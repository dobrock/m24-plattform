<?php
/**
 * M24 Plattform — Bidirektionale LWW-Sync: Apply-Seite (Spec v1.3 §2/§3/§4).
 *
 * Nimmt Datensätze vom Desk entgegen und wendet sie nach der Konfliktregel an. EIN Einstiegspunkt für
 * beide Wege, über die Desk-Änderungen hereinkommen:
 *   - Event-Push (Desk→WP-Webhook, sofort)
 *   - Reconcile-Pull (WP holt GET /api/sync/changes, Etappe 4)
 * Dadurch gilt die Regel garantiert für beide gleich — ein zweiter, leicht abweichender Applier wäre
 * genau die Sorte Fehler, die man erst bei divergierenden Daten bemerkt.
 *
 * Entitäten und ihre Schlüssel:
 *   orders      → wp_offer_uid
 *   offer_lines → wp_offer_uid + line_uid   (Line-Item-LWW, §3)
 *   customers   → customer_uid
 *
 * Abgrenzung zu M24_Desk_Inbound: der bleibt für die bestehenden D1–D5-Webhooks zuständig (feldweise
 * Stempel über field_updated_at). Dieser Applier bedient den neuen Sync-Vertrag mit
 * record-level updated_at/origin/rev. Beide Pfade koexistieren (Spec §8: bestehende Pfade nicht brechen).
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Sync_Apply {

	/** Läuft gerade ein Apply? Der Push-Trigger fragt das ab und pusht dann NICHT zurück (§4). */
	public static $applying = false;

	/**
	 * Angebote mit materieller Änderung (Positionen/Preise/Adresse) in der laufenden Charge.
	 * Der Supersede-Test läuft EINMAL pro Angebot am Ende — sonst entstünden bei einem Edit an drei
	 * Positionen drei Ersatz-Angebote.
	 */
	private static $material = array();

	/** Kopf-Felder, deren Änderung einen Supersede auslösen kann. Status/Zahlung/Tracking gehören NICHT
	 * dazu: das sind Abwicklungsdaten, die nichts am Angebot selbst ändern. */
	const MATERIAL_FIELDS = array( 'ship_firma', 'ship_name', 'ship_strasse', 'ship_strasse2', 'ship_plz', 'ship_ort', 'ship_land', 'country', 'sender_email' );

	/**
	 * Kopf-Felder, die der Desk setzen darf: Desk-Wire-Feld → WP-Spalte. Feldnamen laut Desk-Vertrag
	 * vom 26.08. — `delivery_days`, nicht delivery_time. bill_* steht NICHT im Vertrag: die
	 * Rechnungsadresse pflegt WP bei der Angebotsannahme, der Desk führt nur die Lieferanschrift.
	 * `ship_name` ist der Sonderfall — er kommt als eine Zeile und wird gesplittet (wie im D-Kanal).
	 */
	const ORDER_FIELDS = array(
		'status'        => 'status',
		'delivery_days' => 'delivery_time',
		'payment_date'  => 'payment_date',
		'carrier'       => 'carrier',
		'tracking'      => 'tracking',
		'ship_firma'    => 'ship_firma',
		'ship_name'     => '',            // → ship_anrede/ship_vorname/ship_nachname
		'ship_strasse'  => 'ship_strasse',
		'ship_strasse2' => 'ship_strasse2',
		'ship_plz'      => 'ship_plz',
		'ship_ort'      => 'ship_ort',
		'ship_land'     => 'ship_land',
		'supersedes'    => 'supersedes',
		'superseded_by' => 'superseded_by',
	);

	/**
	 * Kundendaten, die am ORDERS-Record hängen → Schlüssel im customer_json des Angebots.
	 *
	 * Der Desk führt Empfänger und Land auf der Auftragszeile mit (sender_email, cust, country), nicht
	 * nur auf der Kundenzeile. Ohne diese Übernahme bleibt die Angebots-Momentaufnahme auf dem alten
	 * Stand: die Liste zeigt weiter die alte Adresse, und — schlimmer — die Angebots-Mail liest ihren
	 * Empfänger aus genau diesem Feld und ginge erneut an die falsche Adresse.
	 */
	const ORDER_CUSTOMER_FIELDS = array(
		'sender_email' => 'email',
		'cust'         => 'name',
		'country'      => 'land',
	);

	/** Kunden-Felder: Wire-Feld → User-Meta. Deckt sich mit M24_Desk_Inbound::CUSTOMER_MAP. */
	const CUSTOMER_FIELDS = array(
		'anrede'   => '_m24_anrede',
		'firma'    => '_m24_firmenname',
		'strasse'  => '_m24_strasse',
		'strasse2' => '_m24_adresszusatz',
		'plz'      => '_m24_plz',
		'ort'      => '_m24_ort',
		'land'     => '_m24_land',
		'uid'      => '_m24_ustid',
		'tel'      => '_m24_telefon',
		'eori'     => '_m24_eori',
	);

	/**
	 * Einstiegspunkt: eine Liste Datensätze einer Entität anwenden.
	 *
	 * @param string $entity  orders | offer_lines | customers
	 * @param array  $records Wire-Records inkl. LWW-Feldern.
	 * @return array{entity:string,results:array} je Record {key, applied, rev, reason}
	 */
	public static function records( string $entity, array $records ): array {
		$results = array();
		$prev    = self::$applying;
		self::$applying = true; // Echo-Schutz für die gesamte Charge (§4)
		try {
			foreach ( $records as $rec ) {
				if ( ! is_array( $rec ) ) { continue; }
				try {
					switch ( $entity ) {
						case 'orders':      $results[] = self::apply_order( $rec ); break;
						case 'offer_lines': $results[] = self::apply_line( $rec ); break;
						case 'customers':   $results[] = self::apply_customer( $rec ); break;
						default:
							$results[] = array( 'key' => '', 'applied' => false, 'rev' => 0, 'reason' => 'unknown_entity' );
					}
				} catch ( \Throwable $e ) {
					// Ein kaputter Record darf die Charge nicht kippen — der Rest muss durchlaufen.
					$results[] = array( 'key' => (string) ( $rec['wp_offer_uid'] ?? $rec['customer_uid'] ?? '' ), 'applied' => false, 'rev' => 0, 'reason' => 'error: ' . $e->getMessage() );
					self::log( 'apply_error', $entity . ': ' . $e->getMessage() );
				}
			}
		} finally {
			self::$applying = $prev;
		}

		// Supersede (Spec §3) NACH der Charge — außerhalb von $applying, damit der W1-Push des
		// Ersatz-Angebots nicht am Echo-Schutz hängenbleibt.
		if ( ! $prev && ! empty( self::$material ) && class_exists( 'M24_Sync_Supersede' ) ) {
			$todo = self::$material;
			self::$material = array();
			foreach ( $todo as $offer_id => $reason ) {
				// Gekapselt: die Datensätze der Charge sind zu diesem Zeitpunkt bereits geschrieben. Ein
				// Fehler beim Ersetzen darf den Lauf nicht mitreißen und schon gar nicht die Antwort an den
				// Desk verschlucken — sonst gilt eine angewandte Änderung drüben als nicht angekommen.
				try {
					M24_Sync_Supersede::maybe_supersede( (int) $offer_id, (string) $reason );
				} catch ( \Throwable $e ) {
					self::log( 'supersede_error', 'Angebot ' . (int) $offer_id . ': ' . $e->getMessage() );
					if ( class_exists( 'M24_Error_Log' ) ) {
						M24_Error_Log::capture( 'sync_apply', 'error', 'Supersede fehlgeschlagen — Angebot unverändert', array(
							'offer_id' => (int) $offer_id, 'grund' => (string) $reason, 'fehler' => $e->getMessage(),
						) );
					}
				}
			}
		}
		return array( 'entity' => $entity, 'results' => $results );
	}

	/* ── orders (Kopf) ────────────────────────────────────────────────────── */

	private static function apply_order( array $rec ): array {
		global $wpdb;
		$uid     = trim( (string) ( $rec['wp_offer_uid'] ?? '' ) );
		$desk_id = trim( (string) ( $rec['desk_order_id'] ?? '' ) );

		$o = '' !== $uid ? self::offer_by_uid( $uid ) : null;
		if ( ! $o && '' !== $desk_id ) {
			// Desk-Auftrag ohne uid → über die Desk-ID zuordnen und die uid nachtragen. Der nächste Push
			// meldet sie zusammen mit desk_order_id zurück, damit der Desk sie übernehmen kann.
			$o = self::offer_by_desk_id( $desk_id );
			if ( $o ) {
				if ( '' === (string) $o->wp_offer_uid ) { M24_Sync_LWW::init_row( (int) $o->id, 'desk', (int) $o->account_id ); }
				$o  = self::offer_by_uid( M24_Sync_LWW::offer_uid( (int) $o->id ) ) ?: $o;
				$uid = (string) $o->wp_offer_uid;
				self::log( 'uid_bootstrap', 'desk_order_id ' . $desk_id . ' → ' . $uid );
			}
		}
		if ( '' === $uid && ! $o ) { return self::res( '', false, 0, 'missing_wp_offer_uid' ); }
		if ( ! $o ) { return self::res( $uid, false, 0, 'not_found' ); }

		if ( ! self::wins_over( $rec, $o ) ) {
			return self::res( $uid, false, (int) $o->rev, 'discarded_lww' );
		}

		$cols = array();
		foreach ( self::ORDER_FIELDS as $field => $col ) {
			if ( ! array_key_exists( $field, $rec ) ) { continue; }
			$v = $rec[ $field ];
			if ( 'ship_name' === $field ) {
				// Der Desk führt einen einzeiligen Empfängernamen; WP hat drei Spalten dafür.
				$parts = preg_split( '/\s+/', trim( sanitize_text_field( (string) $v ) ) ) ?: array();
				$anr   = ( ! empty( $parts ) && in_array( $parts[0], array( 'Herr', 'Frau', 'Herrn' ), true ) ) ? array_shift( $parts ) : '';
				$cols['ship_anrede']   = $anr;
				$cols['ship_nachname'] = count( $parts ) > 0 ? (string) array_pop( $parts ) : '';
				$cols['ship_vorname']  = implode( ' ', $parts );
			} elseif ( 'payment_date' === $field ) {
				$cols[ $col ] = '' !== (string) $v ? M24_Sync_LWW::from_iso( (string) $v ) : null;
			} else {
				$cols[ $col ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : null;
			}
		}

		// Tombstone: gelöscht im Desk → Papierkorb in WP. Nie hart löschen (§4), das Archiv-PDF bleibt.
		if ( array_key_exists( 'deleted_at', $rec ) ) {
			$del = trim( (string) ( $rec['deleted_at'] ?? '' ) );
			$cols['deleted_at'] = '' !== $del ? M24_Sync_LWW::from_iso( $del ) : null;
		}

		// Empfänger/Name/Land aus dem Auftrags-Record in die Angebots-Momentaufnahme übernehmen.
		$snap = self::merge_customer_snapshot( (string) $o->customer_json, $rec );
		if ( null !== $snap ) {
			$cols['customer_json'] = $snap;
		}

		if ( ! empty( $cols ) ) {
			$wpdb->update( M24_Offers::table(), $cols, array( 'id' => (int) $o->id ) );
		}
		self::adopt( (int) $o->id, $rec );

		// Eine vom Desk gemeldete Zahlung soll denselben Weg nehmen wie über D1 (Status + Hook).
		if ( ! empty( $cols['payment_date'] ) ) { M24_Offers::mark_paid( (int) $o->id, 'desk' ); }

		// Adressänderung an einem versendeten Angebot ist materiell → Supersede-Kandidat.
		$addr = array_intersect( array_keys( $rec ), self::MATERIAL_FIELDS );
		if ( ! empty( $addr ) && ! empty( $cols ) ) {
			self::$material[ (int) $o->id ] = 'Adresse geändert (' . implode( ',', $addr ) . ')';
		}
		self::log( 'applied_order', $uid . ' (' . (string) $o->offer_no . ') · Felder: ' . implode( ',', array_keys( $cols ) ) );
		return self::res( $uid, true, self::rev_of( (int) $o->id ) );
	}

	/**
	 * customer_json des Angebots mit den Kundendaten aus einem orders-Record zusammenführen.
	 *
	 * Gibt das neue JSON zurück, oder null wenn sich nichts ändert — dann bleibt die Spalte unangetastet
	 * und ein reiner Status-Push schreibt sie nicht sinnlos neu.
	 *
	 * @return string|null
	 */
	private static function merge_customer_snapshot( string $customer_json, array $rec ): ?string {
		$cust = json_decode( $customer_json, true );
		$cust = is_array( $cust ) ? $cust : array();
		$dirty = false;
		foreach ( self::ORDER_CUSTOMER_FIELDS as $field => $key ) {
			if ( ! array_key_exists( $field, $rec ) ) { continue; }
			$v = trim( (string) $rec[ $field ] );
			if ( '' === $v ) { continue; } // ein leeres Feld ist keine Korrektur, sondern ein fehlender Wert
			if ( 'email' === $key ) {
				$v = sanitize_email( $v );
				if ( ! is_email( $v ) ) { continue; } // nie eine kaputte Adresse in den Versand-Snapshot
			} else {
				$v = sanitize_text_field( $v );
			}
			if ( (string) ( $cust[ $key ] ?? '' ) === $v ) { continue; }
			$cust[ $key ] = $v;
			$dirty = true;
		}
		return $dirty ? (string) wp_json_encode( $cust ) : null;
	}

	/* ── offer_lines (Positionen, §3) ─────────────────────────────────────── */

	/**
	 * Eine Position anwenden. LWW gilt PRO Zeile: ein Desk-Edit an Zeile A darf einen gleichzeitigen
	 * WP-Edit an Zeile B nicht verwerfen. Danach werden die Kopf-Summen neu berechnet — sie werden
	 * bewusst nicht separat übertragen (§3), sonst driften Summe und Positionen auseinander.
	 */
	private static function apply_line( array $rec ): array {
		global $wpdb;
		$uid  = trim( (string) ( $rec['wp_offer_uid'] ?? '' ) );
		$luid = trim( (string) ( $rec['line_uid'] ?? '' ) );
		$key  = $uid . '/' . $luid;
		if ( '' === $uid || '' === $luid ) { return self::res( $key, false, 0, 'missing_key' ); }

		$o = self::offer_by_uid( $uid );
		if ( ! $o ) { return self::res( $key, false, 0, 'not_found' ); }

		$items = json_decode( (string) $o->items_json, true );
		$items = is_array( $items ) ? $items : array();
		$idx   = M24_Sync_LWW::find_line( $items, $luid );
		$tombs = M24_Sync_LWW::tombstones( $o );

		// Vorläufige Desk-UID (Bestandszeile, die noch nie eine WP-uid gesehen hat): NICHT als neue Zeile
		// anlegen. Der Desk adoptiert unsere line_uid erst beim Push WP→Desk — pullen wir vorher, hätten wir
		// dieselbe Position zweimal: einmal unter unserer uid, einmal unter seiner geliehenen. Kennen wir die
		// uid dagegen schon, ist die Adoption gelaufen und die Zeile wird normal verarbeitet.
		if ( null === $idx && ! empty( $rec['line_uid_vorlaeufig'] ) ) {
			self::log( 'line_deferred', $key . ' — vorläufige Desk-uid, Adoption steht aus (erst pushen, dann pullen).' );
			return self::res( $key, false, 0, 'line_uid_vorlaeufig' );
		}

		// Lokaler Stand der Zeile: die aktive Position, sonst ihr Tombstone, sonst gar nichts (= neu).
		$local = array();
		if ( null !== $idx ) {
			$local = $items[ $idx ];
		} else {
			foreach ( $tombs as $t ) {
				if ( (string) ( $t['line_uid'] ?? '' ) === $luid ) { $local = $t; break; }
			}
		}
		if ( ! empty( $local ) && ! M24_Sync_LWW::wins( $rec, $local ) ) {
			return self::res( $key, false, (int) ( $local['rev'] ?? 0 ), 'discarded_lww' );
		}

		$deleted = '' !== trim( (string) ( $rec['deleted_at'] ?? '' ) );
		if ( $deleted ) {
			if ( null !== $idx ) { array_splice( $items, $idx, 1 ); }
			$tombs = M24_Sync_LWW::merge_tombstones( $tombs, array( array(
				'line_uid'   => $luid,
				'deleted_at' => M24_Sync_LWW::from_iso( (string) $rec['deleted_at'] ),
				'rev'        => (int) ( $rec['rev'] ?? 1 ),
				'origin'     => 'desk',
			) ) );
		} else {
			$line = self::line_from_record( $rec, is_array( $local ) ? $local : array() );
			if ( null !== $idx ) {
				$items[ $idx ] = $line;
			} else {
				$items[] = $line; // im Desk hinzugefügte Zeile
			}
		}

		$extras = json_decode( (string) $o->extras_json, true );
		$extras = is_array( $extras ) ? $extras : array();
		$cust   = json_decode( (string) $o->customer_json, true );
		$cust   = is_array( $cust ) ? $cust : array();
		$bd     = M24_Offers::compute_totals( $items, $extras, (string) $o->tax_mode, (float) $o->tax_rate, (string) ( $cust['land'] ?? '' ) );

		$wpdb->update( M24_Offers::table(), array(
			'items_json'         => wp_json_encode( array_values( $items ) ),
			'deleted_lines_json' => wp_json_encode( $tombs ),
			'subtotal_net'       => $bd['net'] + $bd['st25a'],
			'tax_amount'         => $bd['tax'],
			'total_gross'        => $bd['total'],
		), array( 'id' => (int) $o->id ) );

		// Der Kopf hat sich geändert (Summen) — stempeln, aber als Desk-Änderung und sofort verbucht,
		// damit der Echo-Schutz sie nicht als lokale Änderung zurückpusht.
		M24_Sync_LWW::touch( (int) $o->id, 'desk' );
		M24_Sync_LWW::mark_synced( (int) $o->id );

		self::$material[ (int) $o->id ] = 'Positionen geändert';
		self::log( 'applied_line', $key . ( $deleted ? ' (gelöscht)' : '' ) . ' → Summen neu: ' . number_format( (float) $bd['total'], 2, ',', '.' ) );
		return self::res( $key, true, (int) ( $rec['rev'] ?? 1 ) );
	}

	/**
	 * Wire-Record → Positions-Array in der Form, die clean_items() erzeugt. Nicht übertragene Felder
	 * bleiben auf dem lokalen Stand: der Desk kennt WP-Interna wie thumb/url/race nicht und würde sie
	 * sonst mit Leerwerten überschreiben.
	 */
	private static function line_from_record( array $rec, array $local ): array {
		$line = $local;
		$line['line_uid'] = (string) $rec['line_uid'];
		// Desk-Feldnamen laut Vertrag: `art` ist der Titel, `amt` der numerische Einzelpreis (`price` ist
		// nur die DE-formatierte Anzeige), `note` die Artikelnummer, `is25a` die Differenzbesteuerung.
		if ( array_key_exists( 'art', $rec ) )    { $line['title']   = sanitize_text_field( (string) $rec['art'] ); }
		if ( array_key_exists( 'note', $rec ) )   { $line['art_nr']  = sanitize_text_field( (string) $rec['note'] ); }
		if ( array_key_exists( 'qty', $rec ) )    { $line['qty']     = max( 1, (int) $rec['qty'] ); }
		if ( array_key_exists( 'amt', $rec ) )    { $line['unit_price'] = round( (float) $rec['amt'], 2 ); }
		if ( array_key_exists( 'is25a', $rec ) )  { $line['tax25a']  = (bool) $rec['is25a']; }
		if ( array_key_exists( 'src_pid', $rec ) && ctype_digit( (string) $rec['src_pid'] ) ) { $line['teil_id'] = (int) $rec['src_pid']; }
		foreach ( array( 'hs_code', 'weight_kg' ) as $k ) {
			if ( array_key_exists( $k, $rec ) ) { $line[ $k ] = sanitize_text_field( (string) $rec[ $k ] ); }
		}

		// Pflichtfelder für eine im Desk NEU angelegte Zeile, die WP sonst nirgends herbekommt.
		if ( ! isset( $line['title'] ) )      { $line['title'] = ''; }
		if ( ! isset( $line['qty'] ) )        { $line['qty'] = 1; }
		if ( ! isset( $line['unit_price'] ) ) { $line['unit_price'] = 0.0; }
		if ( ! isset( $line['teil_id'] ) )    { $line['teil_id'] = 0; }

		$line['updated_at'] = M24_Sync_LWW::from_iso( (string) ( $rec['updated_at'] ?? '' ) ) ?: M24_Sync_LWW::now();
		$line['rev']        = (int) ( $rec['rev'] ?? 1 );
		$line['origin']     = 'desk';
		return $line;
	}

	/* ── customers ────────────────────────────────────────────────────────── */

	/**
	 * Kundendaten anwenden. Gematcht wird über customer_uid, NIE über die E-Mail — die E-Mail ist ja
	 * genau der Wert, der sich im Korrektur-Fall ändert (Federico .com→.it).
	 *
	 * Eine geänderte E-Mail wird zusätzlich in den customer_json-Snapshot der noch offenen Angebote
	 * gespiegelt: die Angebots-Mail liest ihren Empfänger von dort, nicht aus dem Konto.
	 */
	private static function apply_customer( array $rec ): array {
		$cuid = trim( (string) ( $rec['customer_uid'] ?? '' ) );
		$dcid = trim( (string) ( $rec['desk_customer_id'] ?? '' ) );
		// Bestandskunden im Desk tragen NOCH KEINE customer_uid — WP hat sie vergeben, der Desk übernimmt
		// sie erst mit unserem nächsten Push. Solange kommt der Record nur mit desk_customer_id herein.
		// Der darf deshalb NICHT abgewiesen werden: sonst erreicht keine einzige Adressänderung an einem
		// Altbestand jemals WP (genau dieser Fall — Adresse im Desk geändert, WP zeigt weiter die alte).
		if ( '' === $cuid && '' === $dcid ) { return self::res( '', false, 0, 'missing_customer_uid' ); }

		$uid = '' !== $cuid ? self::user_by_customer_uid( $cuid ) : 0;
		if ( $uid <= 0 && '' !== $dcid ) {
			// uid-Bootstrap über die Desk-Kunden-ID (liegt als User-Meta aus der W1-Response vor).
			$uid = self::user_by_desk_customer_id( $dcid );
			if ( $uid > 0 ) {
				$cuid = M24_Sync_LWW::customer_uid( $uid ); // legt sie an, falls noch keine da ist
				self::log( 'uid_bootstrap', 'desk_customer_id ' . $dcid . ' → user ' . $uid . ' = ' . $cuid );
			}
		}
		if ( $uid <= 0 && '' === $cuid ) {
			// Weder uid noch Konto — für den Snapshot-Weg fehlt der Schlüssel. Nicht raten.
			return self::res( $dcid, false, 0, 'kein_customer_uid_match' );
		}
		if ( $uid <= 0 ) {
			// Kein Konto (Gast-Kunde) — die Adresse lebt dann nur im Angebots-Snapshot.
			$n = self::sync_offer_snapshots( $cuid, $rec );
			return $n > 0
				? self::res( $cuid, true, (int) ( $rec['rev'] ?? 1 ), 'guest_snapshot_only' )
				: self::res( $cuid, false, 0, 'not_found' );
		}

		$local = array(
			'updated_at' => (string) get_user_meta( $uid, '_m24_sync_updated_at', true ),
			'rev'        => (int) get_user_meta( $uid, '_m24_sync_rev', true ),
			'origin'     => (string) get_user_meta( $uid, '_m24_sync_origin', true ),
		);
		if ( ! M24_Sync_LWW::wins( $rec, $local ) ) {
			return self::res( $cuid, false, (int) $local['rev'], 'discarded_lww' );
		}

		foreach ( self::CUSTOMER_FIELDS as $field => $meta ) {
			if ( ! array_key_exists( $field, $rec ) ) { continue; }
			update_user_meta( $uid, $meta, sanitize_text_field( (string) $rec[ $field ] ) );
		}
		if ( ! empty( $rec['email'] ) && is_email( (string) $rec['email'] ) ) {
			wp_update_user( array( 'ID' => $uid, 'user_email' => sanitize_email( (string) $rec['email'] ) ) );
		}

		update_user_meta( $uid, '_m24_sync_updated_at', M24_Sync_LWW::from_iso( (string) ( $rec['updated_at'] ?? '' ) ) ?: M24_Sync_LWW::now() );
		update_user_meta( $uid, '_m24_sync_rev', (int) ( $rec['rev'] ?? 1 ) );
		update_user_meta( $uid, '_m24_sync_origin', 'desk' );

		$touched = self::sync_offer_snapshots( $cuid, $rec );
		self::log( 'applied_customer', $cuid . ' → user ' . $uid . ', Angebots-Snapshots: ' . $touched );
		return self::res( $cuid, true, (int) ( $rec['rev'] ?? 1 ) );
	}

	/**
	 * Geänderte Kundendaten in den customer_json-Snapshot der nicht abgeschlossenen Angebote spiegeln.
	 * Nur dort, wo der Versand noch bevorsteht — abgeschlossene Angebote sind Belege und bleiben, wie
	 * sie versendet wurden.
	 */
	private static function sync_offer_snapshots( string $customer_uid, array $rec ): int {
		global $wpdb;
		$t    = M24_Offers::table();
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id, customer_json FROM $t WHERE customer_uid = %s AND deleted_at IS NULL AND status IN ('entwurf','offen','versandt','angenommen')",
			$customer_uid
		) );
		$map = array( 'email' => 'email', 'firma' => 'firma', 'strasse' => 'strasse', 'plz' => 'plz', 'ort' => 'ort', 'land' => 'land', 'tel' => 'telefon', 'uid' => 'ustid' );
		$n   = 0;
		foreach ( (array) $rows as $r ) {
			$cust  = json_decode( (string) $r->customer_json, true );
			$cust  = is_array( $cust ) ? $cust : array();
			$dirty = false;
			foreach ( $map as $field => $key ) {
				if ( ! array_key_exists( $field, $rec ) ) { continue; }
				$v = ( 'email' === $field ) ? sanitize_email( (string) $rec[ $field ] ) : sanitize_text_field( (string) $rec[ $field ] );
				if ( 'email' === $field && ! is_email( $v ) ) { continue; }
				if ( (string) ( $cust[ $key ] ?? '' ) !== $v ) { $cust[ $key ] = $v; $dirty = true; }
			}
			if ( ! $dirty ) { continue; }
			$wpdb->update( $t, array( 'customer_json' => wp_json_encode( $cust ) ), array( 'id' => (int) $r->id ) );
			M24_Sync_LWW::touch( (int) $r->id, 'desk' );
			M24_Sync_LWW::mark_synced( (int) $r->id );
			$n++;
		}
		return $n;
	}

	/* ── Helfer ───────────────────────────────────────────────────────────── */

	private static function offer_by_uid( string $uid ) {
		global $wpdb;
		$t = M24_Offers::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE wp_offer_uid = %s LIMIT 1", $uid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * uid-Bootstrap (Abweichung 1 des Desk-Vertrags): Desk-eigene Aufträge tragen noch keine
	 * wp_offer_uid, dafür eine desk_order_id. Über die findet WP seinen Spiegel und meldet die uid beim
	 * nächsten Push zurück — erst dadurch kommen Desk-Neuanlagen überhaupt je in den Sync.
	 */
	private static function offer_by_desk_id( string $desk_id ) {
		global $wpdb;
		if ( '' === $desk_id ) { return null; }
		$t = M24_Offers::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE desk_order_id = %s LIMIT 1", $desk_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Gegenstück für Kunden: Konto über die Desk-Kunden-ID finden (User-Meta aus dem D-Kanal). */
	private static function user_by_desk_customer_id( string $desk_cid ): int {
		if ( '' === $desk_cid || ! class_exists( 'M24_Desk_Push' ) ) { return 0; }
		$u = get_users( array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_key' => M24_Desk_Push::CUST_META, 'meta_value' => $desk_cid, 'number' => 1, 'fields' => 'ID',
		) );
		return ! empty( $u ) ? (int) $u[0] : 0;
	}

	private static function user_by_customer_uid( string $cuid ): int {
		$u = get_users( array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_key'   => M24_Sync_LWW::CUST_UID_META,
			'meta_value' => $cuid,
			'number'     => 1,
			'fields'     => 'ID',
		) );
		return ! empty( $u ) ? (int) $u[0] : 0;
	}

	/** Gewinnt der eingehende Kopf-Record gegen den lokalen Stand der Zeile? */
	private static function wins_over( array $rec, $o ): bool {
		return M24_Sync_LWW::wins( $rec, array(
			'updated_at' => (string) ( $o->updated_at ?? '' ),
			'rev'        => (int) ( $o->rev ?? 0 ),
			'origin'     => (string) ( $o->origin ?? '' ),
		) );
	}

	/** Nach dem Anwenden: Stempel des Absenders übernehmen und als gesynct verbuchen (§2/§4). */
	private static function adopt( int $offer_id, array $rec ): void {
		global $wpdb;
		$stamp = M24_Sync_LWW::from_iso( (string) ( $rec['updated_at'] ?? '' ) );
		$wpdb->update( M24_Offers::table(), array(
			'updated_at' => '' !== $stamp ? $stamp : M24_Sync_LWW::now(),
			'origin'     => 'desk',
			'rev'        => max( 1, (int) ( $rec['rev'] ?? 1 ) ),
		), array( 'id' => $offer_id ) );
		M24_Sync_LWW::mark_synced( $offer_id );
	}

	private static function rev_of( int $offer_id ): int {
		global $wpdb;
		$t = M24_Offers::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT rev FROM $t WHERE id = %d", $offer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function res( string $key, bool $applied, int $rev, string $reason = '' ): array {
		$r = array( 'key' => $key, 'applied' => $applied, 'rev' => $rev );
		if ( '' !== $reason ) { $r['reason'] = $reason; }
		return $r;
	}

	private static function log( string $step, string $msg ): void {
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'sync_apply', $step, array( 'msg' => $msg ) );
		}
	}
}
