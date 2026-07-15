<?php
/**
 * M24 Angebots-Annahme — Adresserfassung (Teil 3/4).
 *
 * Verantwortung: Rechnungs-/Lieferadresse validieren (serverseitig), USt-IdNr gegen VIES prüfen (EU, mit Cache +
 * Fallback), an den Auftrag (m24_offers-Spalten) und ans Kundenkonto (User-Meta) persistieren, sowie das
 * Adressformular für die Kundenansicht rendern. Das Annahme-Gate/der Statuswechsel bleibt in M24_Offers::handle_accept.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Address {

	/** EU-Mitgliedstaaten (ISO2) — für die USt-IdNr-Pflicht (B2B) und die VIES-Prüfung. */
	const EU = array( 'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE' );

	/** Pflicht-Rechnungsfelder (B2C-Basis). Telefon optional; firma/ustid nur B2B (+ EU). */
	const BILL_REQUIRED = array( 'anrede', 'vorname', 'nachname', 'strasse', 'plz', 'ort', 'land' );

	/** ── Land → ISO2 (nutzt die Flaggen-Klasse, sonst 2-Zeichen-Heuristik). ── */
	private static function iso2( string $land ): string {
		if ( class_exists( 'M24_Country_Flags' ) ) { return (string) M24_Country_Flags::countryToIso2( $land ); }
		return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $land ), 0, 2 ) );
	}
	public static function is_eu( string $land ): bool {
		$iso = self::iso2( $land );
		return '' !== $iso && in_array( $iso, self::EU, true );
	}

	/** Prefill aus dem Kundenkonto (User-Meta); leere Strings, wenn nichts hinterlegt. */
	public static function prefill( int $uid ): array {
		if ( $uid <= 0 ) { return array(); }
		$vn = (string) get_user_meta( $uid, 'first_name', true );
		$nn = (string) get_user_meta( $uid, 'last_name', true );
		return array(
			'anrede'   => (string) get_user_meta( $uid, '_m24_anrede', true ),
			'vorname'  => $vn,
			'nachname' => $nn,
			'firma'    => (string) get_user_meta( $uid, '_m24_firmenname', true ),
			'ustid'    => (string) get_user_meta( $uid, '_m24_ustid', true ),
			'strasse'  => (string) get_user_meta( $uid, '_m24_strasse', true ),
			'plz'      => (string) get_user_meta( $uid, '_m24_plz', true ),
			'ort'      => (string) get_user_meta( $uid, '_m24_ort', true ),
			'land'     => (string) get_user_meta( $uid, '_m24_land', true ),
			'telefon'  => (string) get_user_meta( $uid, '_m24_telefon', true ),
		);
	}

	/** Ein Adressblock aus dem Payload säubern. */
	private static function clean_block( array $b ): array {
		return array(
			'anrede'   => in_array( ( $b['anrede'] ?? '' ), array( 'Herr', 'Frau' ), true ) ? (string) $b['anrede'] : '',
			'vorname'  => sanitize_text_field( (string) ( $b['vorname'] ?? '' ) ),
			'nachname' => sanitize_text_field( (string) ( $b['nachname'] ?? '' ) ),
			'firma'    => sanitize_text_field( (string) ( $b['firma'] ?? '' ) ),
			'ustid'    => strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( (string) ( $b['ustid'] ?? '' ) ) ) ),
			'strasse'  => sanitize_text_field( (string) ( $b['strasse'] ?? '' ) ),
			'plz'      => sanitize_text_field( (string) ( $b['plz'] ?? '' ) ),
			'ort'      => sanitize_text_field( (string) ( $b['ort'] ?? '' ) ),
			'land'     => sanitize_text_field( trim( (string) ( $b['land'] ?? '' ) ) ),
			'telefon'  => sanitize_text_field( (string) ( $b['telefon'] ?? '' ) ),
		);
	}

	/**
	 * Serverseitige Validierung. $kundentyp: 'b2b'|'b2c'. Gibt zurück:
	 *   [ 'ok' => bool, 'errors' => string[], 'billing' => [], 'shipping' => []|null, 'ship_diff' => bool ]
	 * USt-IdNr (B2B, EU) wird pflicht + per VIES geprüft (Ergebnis in billing['ustid_vies']).
	 */
	public static function validate( array $p, string $kundentyp ): array {
		$errors  = array();
		$billing = self::clean_block( (array) ( $p['billing'] ?? array() ) );
		$is_b2b  = ( 'b2b' === $kundentyp );

		foreach ( self::BILL_REQUIRED as $f ) {
			if ( '' === $billing[ $f ] ) { $errors[] = 'billing.' . $f; }
		}
		$billing['ustid_vies'] = '';
		if ( $is_b2b ) {
			if ( '' === $billing['firma'] ) { $errors[] = 'billing.firma'; }
			if ( self::is_eu( $billing['land'] ) ) {
				if ( '' === $billing['ustid'] ) { $errors[] = 'billing.ustid'; }
				elseif ( ! self::ustid_format_ok( $billing['ustid'] ) ) { $errors[] = 'billing.ustid_format'; }
				else { $billing['ustid_vies'] = self::vies_check( $billing['ustid'] ); } // valid|invalid|unchecked (kein Hard-Block)
			}
		}

		$ship_diff = ! empty( $p['ship_diff'] );
		$shipping  = null;
		if ( $ship_diff ) {
			$shipping = self::clean_block( (array) ( $p['shipping'] ?? array() ) );
			foreach ( self::BILL_REQUIRED as $f ) {
				if ( '' === $shipping[ $f ] ) { $errors[] = 'shipping.' . $f; }
			}
		}

		return array(
			'ok'        => empty( $errors ),
			'errors'    => $errors,
			'billing'   => $billing,
			'shipping'  => $shipping,
			'ship_diff' => $ship_diff,
		);
	}

	/** Grobe USt-IdNr-Formatprüfung (Länder-Muster ohne echte Prüfziffer). */
	public static function ustid_format_ok( string $v ): bool {
		return (bool) preg_match( '/^[A-Z]{2}[0-9A-Z]{2,12}$/', strtoupper( preg_replace( '/\s+/', '', $v ) ) );
	}

	/**
	 * VIES-Prüfung (EU) mit Cache (7 Tage) + Fallback. Rückgabe: 'valid' | 'invalid' | 'unchecked'.
	 * Bei VIES-Ausfall/Timeout → 'unchecked' (Format wurde vorher geprüft) → Annahme NICHT hart blocken, aber
	 * am Auftrag als „nicht gegen VIES geprüft" markiert (manuelle Kontrolle).
	 */
	public static function vies_check( string $ustid ): string {
		$ustid = strtoupper( preg_replace( '/\s+/', '', $ustid ) );
		if ( ! self::ustid_format_ok( $ustid ) ) { return 'unchecked'; }
		$cache_key = 'm24_vies_' . md5( $ustid );
		$cached    = get_transient( $cache_key );
		if ( 'valid' === $cached || 'invalid' === $cached ) { return $cached; } // 'unchecked' wird nicht gecacht → Retry
		$cc  = substr( $ustid, 0, 2 );
		$num = substr( $ustid, 2 );
		$res = wp_remote_post( 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number', array(
			'timeout' => 6,
			'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
			'body'    => wp_json_encode( array( 'countryCode' => $cc, 'vatNumber' => $num ) ),
		) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) { return 'unchecked'; }
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || ! array_key_exists( 'valid', $body ) ) { return 'unchecked'; }
		$out = ! empty( $body['valid'] ) ? 'valid' : 'invalid';
		set_transient( $cache_key, $out, 7 * DAY_IN_SECONDS ); // definitives Ergebnis cachen (kein Doppel-Call)
		return $out;
	}

	/**
	 * Persistenz: Adresse in die m24_offers-Spalten am Auftrag + (nur Rechnungsadresse) ins Kundenkonto (User-Meta)
	 * für künftigen Prefill. accepted_at wird gesetzt.
	 */
	public static function persist( int $offer_id, int $uid, array $billing, $shipping, bool $ship_diff ): void {
		if ( $offer_id <= 0 || ! class_exists( 'M24_Offers' ) ) { return; }
		global $wpdb;
		$t   = $wpdb->prefix . 'm24_offers';
		$row = array(
			'bill_anrede'     => $billing['anrede'],
			'bill_vorname'    => $billing['vorname'],
			'bill_nachname'   => $billing['nachname'],
			'bill_firma'      => $billing['firma'],
			'bill_ustid'      => $billing['ustid'],
			'bill_ustid_vies' => (string) ( $billing['ustid_vies'] ?? '' ),
			'bill_strasse'    => $billing['strasse'],
			'bill_plz'        => $billing['plz'],
			'bill_ort'        => $billing['ort'],
			'bill_land'       => $billing['land'],
			'bill_telefon'    => $billing['telefon'],
			'ship_diff'       => $ship_diff ? 1 : 0,
			'accepted_at'     => current_time( 'mysql', true ),
		);
		if ( $ship_diff && is_array( $shipping ) ) {
			$row += array(
				'ship_anrede'   => $shipping['anrede'],
				'ship_vorname'  => $shipping['vorname'],
				'ship_nachname' => $shipping['nachname'],
				'ship_firma'    => $shipping['firma'],
				'ship_ustid'    => $shipping['ustid'],
				'ship_strasse'  => $shipping['strasse'],
				'ship_plz'      => $shipping['plz'],
				'ship_ort'      => $shipping['ort'],
				'ship_land'     => $shipping['land'],
				'ship_telefon'  => $shipping['telefon'],
			);
		}
		$wpdb->update( $t, $row, array( 'id' => $offer_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Rechnungsadresse ans Kundenkonto (nur setzen, wenn Wert vorhanden — nicht mit Leerem überschreiben).
		if ( $uid > 0 ) {
			$map = array(
				'_m24_anrede' => $billing['anrede'], 'first_name' => $billing['vorname'], 'last_name' => $billing['nachname'],
				'_m24_firmenname' => $billing['firma'], '_m24_ustid' => $billing['ustid'], '_m24_strasse' => $billing['strasse'],
				'_m24_plz' => $billing['plz'], '_m24_ort' => $billing['ort'], '_m24_land' => $billing['land'], '_m24_telefon' => $billing['telefon'],
			);
			foreach ( $map as $k => $v ) { if ( '' !== (string) $v ) { update_user_meta( $uid, $k, $v ); } }
		}
	}

	/** Ein Feld-<label> (Text/Select). $req markiert Pflicht mit *. */
	private static function field( string $scope, string $key, string $label, string $val, bool $req, string $type = 'text' ): string {
		$name = $scope . '_' . $key;
		$star = $req ? ' <span class="m24off-req">*</span>' : '';
		if ( 'anrede' === $key ) {
			$opt = function ( $v, $t ) use ( $val ) { return '<option value="' . esc_attr( $v ) . '"' . selected( $v, $val, false ) . '>' . esc_html( $t ) . '</option>'; };
			return '<label class="m24off-af"><span>' . esc_html( $label ) . $star . '</span><select data-af-field="' . esc_attr( $name ) . '">' . $opt( '', '—' ) . $opt( 'Herr', 'Herr' ) . $opt( 'Frau', 'Frau' ) . '</select></label>';
		}
		return '<label class="m24off-af"><span>' . esc_html( $label ) . $star . '</span><input type="' . esc_attr( $type ) . '" data-af-field="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '" autocomplete="off"></label>';
	}

	/** Adressblock-HTML (Rechnung oder Lieferung). $is_b2b blendet Firma/USt-IdNr ein. */
	private static function block_html( string $scope, array $pf, bool $is_b2b, bool $req_all ): string {
		$h  = self::field( $scope, 'anrede', 'Anrede', (string) ( $pf['anrede'] ?? '' ), $req_all );
		$h .= self::field( $scope, 'vorname', 'Vorname', (string) ( $pf['vorname'] ?? '' ), $req_all );
		$h .= self::field( $scope, 'nachname', 'Nachname', (string) ( $pf['nachname'] ?? '' ), $req_all );
		if ( $is_b2b ) {
			$h .= self::field( $scope, 'firma', 'Firma', (string) ( $pf['firma'] ?? '' ), 'bill' === $scope );
			$h .= self::field( $scope, 'ustid', 'USt-IdNr (EU)', (string) ( $pf['ustid'] ?? '' ), false );
		}
		$h .= self::field( $scope, 'strasse', 'Straße + Nr.', (string) ( $pf['strasse'] ?? '' ), $req_all );
		$h .= self::field( $scope, 'plz', 'PLZ', (string) ( $pf['plz'] ?? '' ), $req_all );
		$h .= self::field( $scope, 'ort', 'Ort', (string) ( $pf['ort'] ?? '' ), $req_all );
		$h .= self::field( $scope, 'land', 'Land', (string) ( $pf['land'] ?? '' ), $req_all );
		$h .= self::field( $scope, 'telefon', 'Telefon (optional)', (string) ( $pf['telefon'] ?? '' ), false );
		return $h;
	}

	/**
	 * Vollständiges Adressformular für die Kundenansicht (nach Login). $cust = Angebots-Kunde (kundentyp),
	 * $prefill aus User-Meta. Nur DE (Rechtstexte-nah); Bedienlabels neutral.
	 */
	public static function render_form( array $cust, array $prefill, string $lang = 'de' ): string {
		$is_b2b = ( 'b2b' === ( $cust['kundentyp'] ?? 'b2c' ) );
		$h  = '<div class="m24off-addr" data-addr>';
		$h .= '<h3 class="m24off-addr-h">Rechnungsadresse</h3>';
		$h .= '<div class="m24off-af-grid" data-addr-billing>' . self::block_html( 'bill', $prefill, $is_b2b, true ) . '</div>';
		$h .= '<label class="m24off-check m24off-addr-diff"><input type="checkbox" data-ship-diff> <span>Lieferadresse abweichend</span></label>';
		$h .= '<div class="m24off-af-grid" data-addr-shipping hidden>' . self::block_html( 'ship', array(), $is_b2b, true ) . '</div>';
		$h .= '</div>';
		return $h;
	}
}
