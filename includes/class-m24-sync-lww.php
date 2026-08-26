<?php
/**
 * M24 Plattform — Bidirektionale LWW-Sync, Kernprimitive (Spec v1.2 §1/§2/§4).
 *
 * Grundregel: die zuletzt eingegebene Änderung gewinnt, egal in welchem System. Damit das entscheidbar ist,
 * trägt JEDER syncbare Datensatz (Angebotskopf, jede Position, Kunde) vier Felder:
 *   updated_at (UTC) · origin (wp|desk) · rev (monoton +1) · deleted_at (Tombstone)
 * plus die Sync-Buchhaltung last_synced_rev / last_synced_at.
 *
 * Diese Klasse hält die Primitive, die BEIDE Richtungen teilen — Push (M24_Desk_Push) wie Apply
 * (M24_Desk_Inbound) — damit die Konfliktregel an genau einer Stelle steht und nicht zweimal leicht
 * unterschiedlich implementiert wird.
 *
 * Identität (Spec §0, Gate vor jedem Sync-Apply):
 *   - Angebot: wp_offer_uid = 'wpoffer_<id>' (immutabel, aus der AUTO_INCREMENT-PK)
 *   - Kunde:   customer_uid = UUIDv4, von WP vergeben — NICHT die E-Mail (die ändert sich ja genau dann,
 *              wenn sie korrigiert wird, und taugt deshalb nicht als Schlüssel)
 *   - Position: line_uid = UUIDv4 je Zeile in items_json
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Sync_LWW {

	/** User-Meta, unter dem die stabile Kunden-ID am Konto hängt. */
	const CUST_UID_META = '_m24_customer_uid';

	/** Erlaubte origin-Werte. */
	const ORIGINS = array( 'wp', 'desk' );

	/* ── Zeitstempel ──────────────────────────────────────────────────────── */

	/** Jetzt als UTC-MySQL-Stempel (die Spalten sind DATETIME in UTC, nicht Site-Zeit). */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** UTC-MySQL-Stempel → Millisekunden seit Epoch; null wenn unlesbar. */
	public static function to_ms( ?string $stamp ): ?int {
		$s = trim( (string) $stamp );
		if ( '' === $s ) { return null; }
		// Sowohl 'Y-m-d H:i:s' (DB) als auch ISO8601 mit Zone (Wire-Format) akzeptieren.
		$ts = strtotime( false !== strpos( $s, 'T' ) ? $s : $s . ' UTC' );
		return false === $ts ? null : (int) ( $ts * 1000 );
	}

	/** UTC-MySQL-Stempel → ISO8601 fürs Wire-Format (Desk erwartet ISO). */
	public static function to_iso( ?string $stamp ): string {
		$ms = self::to_ms( $stamp );
		return null === $ms ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) ( $ms / 1000 ) );
	}

	/** ISO8601 (Wire) → UTC-MySQL-Stempel für die Spalte; '' wenn unlesbar. */
	public static function from_iso( ?string $iso ): string {
		$ms = self::to_ms( $iso );
		return null === $ms ? '' : gmdate( 'Y-m-d H:i:s', (int) ( $ms / 1000 ) );
	}

	/* ── Konfliktregel (Spec §2) ──────────────────────────────────────────── */

	/**
	 * Gewinnt der eingehende Datensatz gegen den lokalen Stand?
	 *
	 *   incoming.updated_at  > local.updated_at            -> anwenden
	 *   incoming.updated_at == local.updated_at:
	 *        incoming.rev > local.rev                      -> anwenden
	 *        gleich und incoming.origin = 'desk'           -> anwenden
	 *        sonst                                         -> verwerfen
	 *   sonst                                              -> verwerfen
	 *
	 * Fehlt lokal ein Stempel, gewinnt der eingehende (der lokale Datensatz kannte LWW noch nicht).
	 * Fehlt dem eingehenden einer, verliert er — sonst überschriebe ein stempelloser Push echte Arbeit.
	 *
	 * @param array $incoming ['updated_at'=>?string,'rev'=>?int,'origin'=>?string]
	 * @param array $local    ['updated_at'=>?string,'rev'=>?int,'origin'=>?string]
	 */
	public static function wins( array $incoming, array $local ): bool {
		$li = self::to_ms( $local['updated_at'] ?? null );
		if ( null === $li ) { return true; }
		$ii = self::to_ms( $incoming['updated_at'] ?? null );
		if ( null === $ii ) { return false; }

		if ( $ii > $li ) { return true; }
		if ( $ii < $li ) { return false; }

		$ir = (int) ( $incoming['rev'] ?? 0 );
		$lr = (int) ( $local['rev'] ?? 0 );
		if ( $ir > $lr ) { return true; }
		if ( $ir < $lr ) { return false; }

		// Gleichstand in Zeit UND rev: der Desk gewinnt (eine Seite muss deterministisch führen,
		// sonst flackert der Datensatz je nach Reihenfolge zwischen beiden Ständen).
		return 'desk' === (string) ( $incoming['origin'] ?? '' );
	}

	/* ── Identität (Spec §0) ──────────────────────────────────────────────── */

	/** Immutabler Angebots-Key aus der PK. Deckt sich mit dem, was der W1-Payload schon sendet. */
	public static function offer_uid( int $offer_id ): string {
		return 'wpoffer_' . $offer_id;
	}

	/** Frische Zeilen-/Kunden-UID. */
	public static function new_uid(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Stabile Kunden-ID. Mit Konto hängt sie am User (alle Angebote desselben Kontos teilen sie),
	 * ohne Konto wird eine frische vergeben — Gäste sollen nicht vom Sync ausgeschlossen sein.
	 */
	public static function customer_uid( int $account_id ): string {
		if ( $account_id <= 0 ) { return self::new_uid(); }
		$uid = (string) get_user_meta( $account_id, self::CUST_UID_META, true );
		if ( '' === $uid ) {
			$uid = self::new_uid();
			update_user_meta( $account_id, self::CUST_UID_META, $uid );
		}
		return $uid;
	}

	/** Jede Position bekommt eine line_uid, falls sie noch keine hat. Gibt das ergänzte Array zurück. */
	public static function ensure_line_uids( array $items ): array {
		foreach ( $items as $i => $it ) {
			if ( ! is_array( $it ) ) { continue; }
			if ( empty( $it['line_uid'] ) ) { $items[ $i ]['line_uid'] = self::new_uid(); }
		}
		return $items;
	}

	/* ── Schreiben (Spec §1) ──────────────────────────────────────────────── */

	/**
	 * Frisch angelegtes Angebot mit Identität + LWW-Startwerten versehen. Muss NACH dem Insert laufen,
	 * weil wp_offer_uid an der AUTO_INCREMENT-PK hängt. Idempotent.
	 *
	 * @param int    $offer_id   Neue Zeile.
	 * @param string $origin     Wer sie angelegt hat ('wp' bei Operator/Kunde, 'desk' beim Spiegel).
	 * @param int    $account_id Konto für die customer_uid (0 = Gast).
	 */
	public static function init_row( int $offer_id, string $origin = 'wp', int $account_id = 0 ): void {
		global $wpdb;
		if ( $offer_id <= 0 ) { return; }
		$t = M24_Offers::table();
		$o = $wpdb->get_row( $wpdb->prepare( "SELECT id, wp_offer_uid, customer_uid, account_id FROM $t WHERE id = %d", $offer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $o ) { return; }

		$data = array(
			'updated_at' => self::now(),
			'origin'     => in_array( $origin, self::ORIGINS, true ) ? $origin : 'wp',
			'rev'        => 1,
		);
		if ( '' === (string) $o->wp_offer_uid ) { $data['wp_offer_uid'] = self::offer_uid( $offer_id ); }
		if ( '' === (string) $o->customer_uid ) {
			$acc = $account_id > 0 ? $account_id : (int) $o->account_id;
			$data['customer_uid'] = self::customer_uid( $acc );
		}
		$wpdb->update( $t, $data, array( 'id' => $offer_id ) );
	}

	/**
	 * Lokale Änderung stempeln: rev+1, updated_at=jetzt, origin setzen. Nach JEDEM lokalen Schreibvorgang
	 * aufrufen, sonst hält die Gegenseite den Datensatz für älter als er ist und überschreibt ihn.
	 *
	 * Bewusst ein einzelnes UPDATE mit `rev = rev + 1` statt Lesen-Rechnen-Schreiben — zwei gleichzeitige
	 * Requests würden sonst dieselbe rev vergeben.
	 *
	 * @param array $extra Weitere Spalten, die im selben Zug geschrieben werden sollen.
	 */
	public static function touch( int $offer_id, string $origin = 'wp', array $extra = array() ): void {
		global $wpdb;
		if ( $offer_id <= 0 ) { return; }
		$org = in_array( $origin, self::ORIGINS, true ) ? $origin : 'wp';
		$t   = M24_Offers::table();

		$set  = array( 'updated_at = %s', 'origin = %s', 'rev = rev + 1' );
		$args = array( self::now(), $org );
		foreach ( $extra as $col => $val ) {
			if ( ! preg_match( '/^[a-z_]+$/', (string) $col ) ) { continue; } // Spaltennamen kommen aus dem Code, nie aus Requests
			$set[]  = '`' . $col . '` = ' . ( null === $val ? 'NULL' : '%s' );
			if ( null !== $val ) { $args[] = is_scalar( $val ) ? (string) $val : wp_json_encode( $val ); }
		}
		$args[] = $offer_id;
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET " . implode( ', ', $set ) . ' WHERE id = %d', $args ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Nach erfolgreichem Push/Apply die Sync-Buchhaltung setzen. Basis des Echo-Schutzes (§4): ist
	 * last_synced_rev == rev, hat die Gegenseite den aktuellen Stand und es gibt nichts zu pushen.
	 */
	public static function mark_synced( int $offer_id, ?int $rev = null ): void {
		global $wpdb;
		if ( $offer_id <= 0 ) { return; }
		$t = M24_Offers::table();
		if ( null === $rev ) {
			$rev = (int) $wpdb->get_var( $wpdb->prepare( "SELECT rev FROM $t WHERE id = %d", $offer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->update( $t, array( 'last_synced_rev' => max( 0, (int) $rev ), 'last_synced_at' => self::now() ), array( 'id' => $offer_id ) );
	}

	/**
	 * Echo-Schutz (§4): Hat die Zeile lokale Änderungen, die die Gegenseite noch nicht kennt?
	 * Eine gerade angewandte Remote-Änderung setzt last_synced_rev = rev und ist damit still.
	 */
	public static function needs_push( $o ): bool {
		return (int) ( $o->rev ?? 0 ) > (int) ( $o->last_synced_rev ?? 0 );
	}

	/** LWW-Felder einer Zeile fürs Wire-Format (Push/Apply-Body). */
	public static function envelope( $o ): array {
		return array(
			'wp_offer_uid' => (string) ( $o->wp_offer_uid ?? '' ),
			'customer_uid' => (string) ( $o->customer_uid ?? '' ),
			'updated_at'   => self::to_iso( (string) ( $o->updated_at ?? '' ) ),
			'origin'       => (string) ( $o->origin ?? 'wp' ),
			'rev'          => (int) ( $o->rev ?? 1 ),
			'deleted_at'   => ! empty( $o->deleted_at ) ? self::to_iso( (string) $o->deleted_at ) : null,
		);
	}
}
