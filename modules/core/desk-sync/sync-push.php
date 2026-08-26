<?php
/**
 * M24 Plattform — Bidirektionale LWW-Sync: Push-Seite WP → Desk (Spec v1.3 §5.1/§6).
 *
 * Schickt lokale Änderungen an POST /api/sync/apply. Der Desk wendet sie nach derselben Konfliktregel an
 * und antwortet je Record mit applied/rev — daraufhin verbucht WP last_synced_rev/last_synced_at.
 *
 * Die Warteschlange ist keine eigene Tabelle, sondern die Buchhaltung selbst: `rev > last_synced_rev`
 * heißt „lokal geändert, drüben noch nicht bekannt". Das kann nicht mit dem echten Zustand
 * auseinanderlaufen, wie es eine separate Queue könnte, und übersteht jeden Absturz.
 *
 * Zwei Auslöser:
 *   - sofort: M24_Sync_LWW::touch() feuert m24_sync_touched → Einzel-Event (entkoppelt, blockiert keinen Klick)
 *   - periodisch: Cron holt nach, was der Einzel-Event nicht geschafft hat (Desk down, Timeout, WP-Cron aus)
 *
 * Echo-Schutz (§6): läuft gerade ein Apply, wird NICHT gepusht — sonst liefe die eben eingespielte
 * Desk-Änderung sofort wieder zurück.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Sync_Push {

	const ENDPOINT    = '/api/sync/apply';
	const CRON        = 'm24_sync_push_pending';
	const EVENT       = 'm24_sync_push_offer';
	const TIMEOUT     = 15;
	const BATCH       = 25;  // Angebote je Cron-Lauf — hält den Request kurz
	const MAX_RECORDS = 200; // Records je HTTP-Call (Desk-Limit ist 500 — bewusst darunter)

	public static function init() {
		// Sofort-Push nach jeder lokalen Änderung (M24_Sync_LWW::touch feuert das).
		add_action( 'm24_sync_touched', array( __CLASS__, 'on_touched' ), 10, 2 );
		add_action( self::EVENT, array( __CLASS__, 'push_offer' ), 10, 1 );

		// Nachzügler: alles, was rev > last_synced_rev hat.
		add_action( self::CRON, array( __CLASS__, 'run_pending' ) );
		if ( ! wp_next_scheduled( self::CRON ) && self::enabled() ) {
			wp_schedule_event( time() + 300, 'm24_10min', self::CRON );
		}
	}

	/** Sync scharf? Nutzt denselben Schalter wie der W-Kanal — ein Sync-Modell, ein Schalter. */
	public static function enabled(): bool {
		return class_exists( 'M24_Desk_Push' ) && M24_Desk_Push::enabled()
			&& class_exists( 'M24_Rest_Client' ) && M24_Rest_Client::is_configured();
	}

	/** Läuft gerade ein Apply? Dann ist die Änderung fremd und darf nicht zurücklaufen (§6). */
	private static function applying(): bool {
		return ( class_exists( 'M24_Sync_Apply' ) && M24_Sync_Apply::$applying )
			|| ( class_exists( 'M24_Desk_Inbound' ) && M24_Desk_Inbound::$applying );
	}

	/* ── Auslöser ─────────────────────────────────────────────────────────── */

	public static function on_touched( $offer_id, $origin ): void {
		if ( 'wp' !== (string) $origin || self::applying() || ! self::enabled() ) { return; }
		$offer_id = (int) $offer_id;
		if ( $offer_id <= 0 ) { return; }
		// Entkoppelt einplanen: ein hängender Desk-Call darf keinen Speichern-Klick blockieren.
		// wp_next_scheduled dedupliziert über die Args — mehrere Edits kurz hintereinander = ein Push.
		if ( ! wp_next_scheduled( self::EVENT, array( $offer_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::EVENT, array( $offer_id ) );
		}
	}

	/** Cron: alles nachholen, was noch nicht drüben ist. */
	public static function run_pending(): void {
		global $wpdb;
		if ( ! self::enabled() ) { return; }
		$t   = M24_Offers::table();
		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id FROM $t WHERE rev > last_synced_rev AND wp_offer_uid <> '' ORDER BY updated_at ASC LIMIT %d",
			self::BATCH
		) );
		foreach ( (array) $ids as $id ) { self::push_offer( (int) $id ); }
		if ( ! empty( $ids ) ) { self::log( 'cron', count( $ids ) . ' Angebot(e) nachgepusht.' ); }
	}

	/* ── Push ─────────────────────────────────────────────────────────────── */

	/**
	 * Ein Angebot pushen: Kopf, Positionen (aktive + Tombstones) und den Kunden.
	 * Erst wenn der Kopf angenommen wurde, wird last_synced_* gesetzt.
	 */
	public static function push_offer( $offer_id ): void {
		$offer_id = (int) $offer_id;
		if ( $offer_id <= 0 || ! self::enabled() || self::applying() ) { return; }

		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! $o || '' === (string) $o->wp_offer_uid ) { return; }
		if ( ! M24_Sync_LWW::needs_push( $o ) ) { return; } // schon drüben — nichts zu tun

		// Abweichung 2 des Desk-Vertrags: /api/sync/apply legt KEINE Aufträge an. Existiert der Auftrag
		// drüben noch nicht (keine desk_order_id), ist W1 zuständig — ein Sync-Push liefe hier nur in
		// 'unbekannter_auftrag'. W1 läuft beim Versand bzw. über die Retry-Queue.
		if ( '' === trim( (string) $o->desk_order_id ) ) {
			self::log( 'push_deferred', (string) $o->offer_no . ' hat keine desk_order_id — Anlage läuft über W1, nicht über den Sync.' );
			return;
		}

		$head = self::send( 'orders', array( self::order_record( $o ) ) );
		if ( empty( $head['ok'] ) ) {
			self::log( 'push_failed', (string) $o->offer_no . ' · orders → ' . (string) ( $head['note'] ?? '' ) );
			return; // last_synced_* NICHT setzen → der Cron versucht es erneut
		}
		// Abweichung 3: der Desk gewinnt bei exaktem Gleichstand, ein unverändert wiederholter Push kommt
		// also als applied:false/lww_aelter zurück. Das ist Idempotenz — als erledigt verbuchen, sonst
		// pusht der Cron dieselbe Revision endlos. Ein echtes Problem ist nur 'unbekannter_auftrag'.
		$verdict = self::verdict( $head['results'] );
		if ( 'unknown' === $verdict ) {
			self::log( 'push_unknown', (string) $o->offer_no . ' — Desk kennt den Auftrag nicht (W1 ausstehend?).' );
			return;
		}

		$lines = self::line_records( $o );
		if ( ! empty( $lines ) ) {
			$res = self::send( 'offer_lines', $lines );
			if ( empty( $res['ok'] ) ) {
				self::log( 'push_failed', (string) $o->offer_no . ' · offer_lines → ' . (string) ( $res['note'] ?? '' ) );
				return;
			}
		}

		$cust = self::customer_record( $o );
		if ( ! empty( $cust ) ) { self::send( 'customers', array( $cust ) ); } // nicht blockierend: Kopf ist durch

		M24_Sync_LWW::mark_synced( $offer_id, (int) $o->rev );
		self::log( 'pushed', (string) $o->offer_no . ' (rev ' . (int) $o->rev . ', ' . count( $lines ) . ' Positionen)' );
	}

	/**
	 * Kopf-Record im Desk-Wire-Format. Feldnamen laut Desk-Vertrag vom 26.08. — bewusst NICHT die
	 * WP-Spaltennamen: `amt` statt total_gross, `delivery_days` statt delivery_time, `order_num` statt
	 * desk_order_num. bill_* steht nicht im Vertrag und geht deshalb nicht raus.
	 *
	 * desk_order_id/desk_customer_id fahren mit, damit der Desk einen Datensatz zuordnen kann, der bei
	 * ihm noch keine uid trägt (uid-Bootstrap, Abweichung 1 des Desk-Vertrags).
	 */
	public static function order_record( $o ): array {
		$cust = json_decode( (string) $o->customer_json, true );
		$cust = is_array( $cust ) ? $cust : array();
		$biz  = ( 'b2b' === (string) ( $cust['kundentyp'] ?? 'b2c' ) );
		$land = trim( (string) ( $cust['land'] ?? '' ) );

		$rec = M24_Sync_LWW::envelope( $o );
		$rec['desk_order_id']    = (string) $o->desk_order_id;
		$rec['desk_customer_id'] = self::desk_customer_id( $o );
		$rec['order_num']        = (string) $o->desk_order_num;
		$rec['ref']              = (string) $o->offer_no;   // WP-Angebotsnummer als Referenz
		$rec['subj']             = 'Angebot ' . (string) $o->offer_no;
		$rec['amt']              = round( (float) $o->total_gross, 2 );
		$rec['status']           = (string) $o->status;
		$rec['country']          = '' !== $land ? $land : 'Deutschland';
		$rec['biz']              = $biz;
		$rec['sender_email']     = (string) ( $cust['email'] ?? '' );
		$rec['inquiry_source']   = class_exists( 'M24_Desk_Push' ) ? M24_Desk_Push::inquiry_source() : 'offer';
		$rec['supersedes']       = (string) $o->supersedes;
		$rec['superseded_by']    = (string) $o->superseded_by;
		$rec['carrier']          = (string) $o->carrier;
		$rec['tracking']         = (string) $o->tracking;
		$rec['payment_date']     = ! empty( $o->payment_date ) ? M24_Sync_LWW::to_iso( (string) $o->payment_date ) : null;

		if ( class_exists( 'M24_Desk_Push' ) ) {
			$rec['sender_lang']   = M24_Desk_Push::payload_lang( $o );
			$rec['delivery_days'] = M24_Desk_Push::payload_lieferzeit( $o ); // kompakter Code, wie in W1
			$rec['vat_mode']      = M24_Desk_Push::vat_mode( (string) $o->tax_mode, $biz, $land );
		}
		$rec['offer_date'] = ! empty( $o->sent_at ) ? substr( (string) $o->sent_at, 0, 10 ) : null;
		$cs = json_decode( (string) $o->completed_steps, true );
		if ( is_array( $cs ) ) { $rec['completed_steps'] = array_values( array_map( 'strval', $cs ) ); }

		// Lieferadresse: ship_name setzt sich wie im D-Kanal aus Anrede + Vor- + Nachname zusammen.
		$rec['ship_name'] = trim( implode( ' ', array_filter( array(
			(string) $o->ship_anrede, (string) $o->ship_vorname, (string) $o->ship_nachname,
		) ) ) );
		foreach ( array( 'ship_firma', 'ship_strasse', 'ship_strasse2', 'ship_plz', 'ship_ort', 'ship_land' ) as $col ) {
			$rec[ $col ] = (string) ( $o->$col ?? '' );
		}
		return $rec;
	}

	/** Desk-Kunden-ID vom verknüpften Konto (User-Meta), für den uid-Bootstrap der Gegenseite. */
	private static function desk_customer_id( $o ): string {
		$acc = (int) $o->account_id;
		if ( $acc <= 0 || ! class_exists( 'M24_Desk_Push' ) ) { return ''; }
		return (string) get_user_meta( $acc, M24_Desk_Push::CUST_META, true );
	}

	/**
	 * Positions-Records: aktive Zeilen plus Tombstones. Die Tombstones MÜSSEN mit — sonst erführe der Desk
	 * nie, dass eine Zeile entfernt wurde, und hielte sie beim nächsten Reconcile für neu.
	 */
	public static function line_records( $o ): array {
		$out   = array();
		$uid   = (string) $o->wp_offer_uid;
		$view  = M24_Offers::view_url( (string) $o->token );
		$src   = json_decode( (string) $o->src_json, true );
		$src   = is_array( $src ) ? $src : array();
		$lang  = class_exists( 'M24_Desk_Push' ) ? M24_Desk_Push::payload_lang( $o ) : 'de';
		$deliv = class_exists( 'M24_Desk_Push' ) ? M24_Desk_Push::payload_lieferzeit( $o ) : (string) $o->delivery_time;

		$items = json_decode( (string) $o->items_json, true );
		foreach ( (array) ( is_array( $items ) ? $items : array() ) as $it ) {
			if ( ! is_array( $it ) || empty( $it['line_uid'] ) ) { continue; }
			$url = trim( (string) ( $it['url'] ?? '' ) );
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) { $url = $view; }
			// Dieselbe Mapping-Methode wie W1 — ein zweites Item-Format wäre genau die Abweichung,
			// die erst auffällt, wenn im Desk die Hälfte der Felder leer bleibt.
			$rec = class_exists( 'M24_Desk_Push' ) ? M24_Desk_Push::map_item( $it, $url, $lang, $src, $deliv ) : array();
			$rec['wp_offer_uid'] = $uid;
			$rec['line_uid']     = (string) $it['line_uid'];
			$rec['updated_at']   = M24_Sync_LWW::to_iso( (string) ( $it['updated_at'] ?? '' ) );
			$rec['origin']       = (string) ( $it['origin'] ?? 'wp' );
			$rec['rev']          = (int) ( $it['rev'] ?? 1 );
			$rec['deleted_at']   = null;
			unset( $rec['einkauf'] ); // geht nie raus (Marge)
			$out[] = $rec;
		}
		// Tombstones MÜSSEN mit: ein Teil-Push löscht Desk-seitig nichts, Entfernen geht nur explizit.
		foreach ( M24_Sync_LWW::tombstones( $o ) as $t ) {
			if ( empty( $t['line_uid'] ) ) { continue; }
			$iso = M24_Sync_LWW::to_iso( (string) ( $t['deleted_at'] ?? '' ) );
			$out[] = array(
				'wp_offer_uid' => $uid,
				'line_uid'     => (string) $t['line_uid'],
				'updated_at'   => $iso,
				'origin'       => (string) ( $t['origin'] ?? 'wp' ),
				'rev'          => (int) ( $t['rev'] ?? 1 ),
				'deleted_at'   => $iso,
			);
		}
		return $out;
	}

	/** Kunden-Record aus dem Angebots-Snapshot (+ Konto, falls vorhanden). */
	public static function customer_record( $o ): array {
		$cuid = (string) $o->customer_uid;
		if ( '' === $cuid ) { return array(); }
		$c = json_decode( (string) $o->customer_json, true );
		$c = is_array( $c ) ? $c : array();

		$rec = array(
			'customer_uid' => $cuid,
			'email'        => (string) ( $c['email'] ?? '' ),
			'firma'        => (string) ( $c['firma'] ?? $c['firmenname'] ?? '' ),
			'name'         => (string) ( $c['name'] ?? '' ),
			'strasse'      => (string) ( $c['strasse'] ?? '' ),
			'plz'          => (string) ( $c['plz'] ?? '' ),
			'ort'          => (string) ( $c['ort'] ?? '' ),
			'land'         => (string) ( $c['land'] ?? '' ),
			'tel'          => (string) ( $c['telefon'] ?? '' ),
			'uid'          => (string) ( $c['ustid'] ?? '' ),
			'updated_at'   => M24_Sync_LWW::to_iso( (string) $o->updated_at ),
			'origin'       => (string) $o->origin,
			'rev'          => (int) $o->rev,
			'deleted_at'   => null,
		);
		$acc = (int) $o->account_id;
		if ( $acc > 0 ) {
			$rev = (int) get_user_meta( $acc, '_m24_sync_rev', true );
			$upd = (string) get_user_meta( $acc, '_m24_sync_updated_at', true );
			if ( $rev > 0 ) { $rec['rev'] = $rev; }
			if ( '' !== $upd ) { $rec['updated_at'] = M24_Sync_LWW::to_iso( $upd ); }
		}
		return $rec;
	}

	/**
	 * Was sagt der Desk zu einer Charge? 'unknown' nur, wenn er den Auftrag gar nicht kennt — dann ist
	 * W1 dran. Ein 'lww_aelter' bedeutet, dass drüben schon der gleiche oder ein neuerer Stand liegt:
	 * für uns erledigt.
	 */
	private static function verdict( array $results ): string {
		foreach ( $results as $r ) {
			if ( ! empty( $r['applied'] ) ) { continue; }
			if ( 'unbekannter_auftrag' === (string) ( $r['reason'] ?? '' ) ) { return 'unknown'; }
		}
		return 'ok';
	}

	/**
	 * Eine Charge an POST /api/sync/apply. Der Desk antwortet je Record {key, applied, rev}.
	 *
	 * @return array{ok:bool,note:string,results:array}
	 */
	public static function send( string $entity, array $records ): array {
		if ( empty( $records ) ) { return array( 'ok' => true, 'note' => 'leer', 'results' => array() ); }
		$results = array();
		foreach ( array_chunk( $records, self::MAX_RECORDS ) as $chunk ) {
			$res    = M24_Rest_Client::request( 'POST', self::ENDPOINT, array( 'entity' => $entity, 'records' => $chunk ), array( 'timeout' => self::TIMEOUT ) );
			$status = (int) ( $res['status'] ?? 0 );
			if ( empty( $res['ok'] ) ) {
				// 404 = Desk hat den Sync-Vertrag noch nicht deployt. Kein Fehler-Spam, nur eine Notiz —
				// die Buchhaltung bleibt offen, der Cron versucht es weiter.
				$note = 404 === $status ? 'endpoint_missing (Desk-Seite noch nicht live)' : 'HTTP ' . $status . ' · ' . (string) ( $res['error'] ?? '' );
				return array( 'ok' => false, 'note' => $note, 'results' => $results );
			}
			$data    = is_array( $res['data'] ?? null ) ? $res['data'] : array();
			$results = array_merge( $results, (array) ( $data['results'] ?? array() ) );
		}
		return array( 'ok' => true, 'note' => 'ok', 'results' => $results );
	}

	private static function log( string $step, string $msg ): void {
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'sync_push', $step, array( 'msg' => $msg ) );
		}
	}
}
