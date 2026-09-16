<?php
/**
 * M24 — Flaggen-Emoji aus Landwerten und Positionstiteln entfernen.
 * Modul: includes/class-m24-offer-land-flags.php
 *
 * BEFUND (16.09.2026): Im Angebot 2026-1064 heisst die Versandposition
 * "Insured Shipping — DAP · [NO] Norwegen", und customer_json.land lautet
 * "[NO] Norwegen". Die Flagge stammt aus dem Desk, der sie im Landwert fuehrt;
 * die Plattform hat den Wert verbatim uebernommen. Damit wandert sie in jedes
 * Angebots-PDF und an den Kunden — und sie hat am selben Tag einen Absturz
 * ausgeloest (cxLandToIso, 0.11.508), weil sie in Werten schlicht nicht
 * vorgesehen ist.
 *
 * REGEL: Die Flagge ist DARSTELLUNG (M24_Country_Flags::getFlag) und wird dort
 * frisch abgeleitet, wo sie gewollt ist — Angebotsliste, Kundenkarte, Desk-Karte.
 * Im gespeicherten Wert hat sie nichts zu suchen. Die Ursache ist ab 0.11.509
 * an allen Schreibstellen abgestellt (Kundenanlage, Angebots-Snapshot,
 * Adressformular, Desk-Eingang); dieser Lauf raeumt den Bestand auf.
 *
 * ANGEFASST: m24_offers — customer_json.land, extras_json[].label und
 * [].ship_land, Spalten bill_land und ship_land; dazu die User-Meta _m24_land
 * und _m24_addr_shipping['land'].
 *
 * NICHT ANGEFASST: m24_offer_versions. Eine Fassung ist der BELEG dessen, was
 * versendet wurde — Belege werden nicht nachtraeglich umgeschrieben. Aus ihnen
 * wird kein neues Dokument gebaut: M24_Offer_Update::prefill() liest die
 * Angebotszeile, nicht den Fassungs-Snapshot.
 *
 * @package M24_Plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Land_Flags {

	/** Traegt der Wert eine Flagge? Vergleich gegen den bereinigten Wert — eine Quelle, keine zweite Regex. */
	private static function hat_flagge( string $s ): bool {
		return '' !== $s && $s !== M24_Offers::ohne_flagge( $s );
	}

	/**
	 * @param array $ids Optional: Angebotsnummern (2026-1064) oder Zeilen-IDs. Leer = alle Angebote
	 *                   UND alle Kundenkonten.
	 * @param bool  $go  false = Trockenlauf (schreibt nichts), true = bereinigen.
	 * @return array{zeilen:array,summe:array}
	 */
	public static function run( array $ids = array(), bool $go = false ): array {
		global $wpdb;
		$out = array(
			'zeilen' => array(),
			'summe'  => array(
				'Angebote geprueft'        => 0,
				'Angebote mit Flagge'      => 0,
				'Positionstitel bereinigt' => 0,
				'Kundenkonten mit Flagge'  => 0,
			),
		);

		if ( ! class_exists( 'M24_Country_Flags' ) || ! method_exists( 'M24_Offers', 'ohne_flagge' ) ) {
			$out['zeilen'][] = 'Helfer fehlt (M24_Country_Flags / M24_Offers::ohne_flagge) — Lauf abgebrochen, nichts geaendert.';
			return $out;
		}

		$t    = M24_Offers::table();
		$sql  = 'SELECT id, offer_no, customer_json, extras_json, bill_land, ship_land FROM ' . $t;
		$args = array();
		$nos  = array();
		$rid  = array();
		foreach ( $ids as $x ) {
			$x = trim( (string) $x );
			if ( '' === $x ) { continue; }
			if ( ctype_digit( $x ) ) { $rid[] = (int) $x; } else { $nos[] = $x; }
		}
		$cond = array();
		if ( $rid ) { $cond[] = 'id IN (' . implode( ',', array_map( 'intval', $rid ) ) . ')'; }
		if ( $nos ) {
			$cond[] = 'offer_no IN (' . implode( ',', array_fill( 0, count( $nos ), '%s' ) ) . ')';
			$args   = $nos;
		}
		if ( $cond ) { $sql .= ' WHERE ' . implode( ' OR ', $cond ); }

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql );

		foreach ( (array) $rows as $o ) {
			$out['summe']['Angebote geprueft']++;
			$upd  = array();
			$note = array();

			$cj = json_decode( (string) $o->customer_json, true );
			if ( is_array( $cj ) && self::hat_flagge( (string) ( $cj['land'] ?? '' ) ) ) {
				$alt         = (string) $cj['land'];
				$cj['land']  = M24_Offers::ohne_flagge( $alt );
				$upd['customer_json'] = wp_json_encode( $cj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$note[]      = sprintf( 'Kundenland "%s" -> "%s"', $alt, $cj['land'] );
			}

			$ej = json_decode( (string) $o->extras_json, true );
			if ( is_array( $ej ) ) {
				$dirty = false;
				foreach ( $ej as $i => $ex ) {
					foreach ( array( 'label', 'ship_land' ) as $feld ) {
						$v = (string) ( $ex[ $feld ] ?? '' );
						if ( ! self::hat_flagge( $v ) ) { continue; }
						$ej[ $i ][ $feld ] = M24_Offers::ohne_flagge( $v );
						$dirty             = true;
						if ( 'label' === $feld ) { $out['summe']['Positionstitel bereinigt']++; }
						$note[] = sprintf( 'Position "%s" -> "%s"', $v, $ej[ $i ][ $feld ] );
					}
				}
				if ( $dirty ) {
					$upd['extras_json'] = wp_json_encode( $ej, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				}
			}

			foreach ( array( 'bill_land', 'ship_land' ) as $spalte ) {
				$v = (string) ( $o->$spalte ?? '' );
				if ( ! self::hat_flagge( $v ) ) { continue; }
				$upd[ $spalte ] = M24_Offers::ohne_flagge( $v );
				$note[]         = sprintf( '%s "%s" -> "%s"', $spalte, $v, $upd[ $spalte ] );
			}

			if ( ! $upd ) { continue; }
			$out['summe']['Angebote mit Flagge']++;
			$out['zeilen'][] = sprintf( '%s (#%d): %s',
				'' !== (string) $o->offer_no ? (string) $o->offer_no : 'ohne Nummer',
				(int) $o->id,
				implode( ' · ', $note )
			);
			if ( $go ) {
				$wpdb->update( $t, $upd, array( 'id' => (int) $o->id ) );
			}
		}

		// Kundenkonten nur im vollen Lauf — mit IDs ist eine bestimmte Angebotsmenge gemeint.
		if ( empty( $cond ) ) {
			$metas = $wpdb->get_results( $wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''",
				'_m24_land'
			) );
			foreach ( (array) $metas as $m ) {
				$alt = (string) $m->meta_value;
				if ( ! self::hat_flagge( $alt ) ) { continue; }
				$neu = M24_Offers::ohne_flagge( $alt );
				$out['summe']['Kundenkonten mit Flagge']++;
				$out['zeilen'][] = sprintf( 'Konto #%d: _m24_land "%s" -> "%s"', (int) $m->user_id, $alt, $neu );
				if ( $go ) { update_user_meta( (int) $m->user_id, '_m24_land', $neu ); }
			}

			$ships = $wpdb->get_results( $wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				'_m24_addr_shipping'
			) );
			foreach ( (array) $ships as $m ) {
				$adr = maybe_unserialize( $m->meta_value );
				if ( ! is_array( $adr ) ) { continue; }
				$alt = (string) ( $adr['land'] ?? '' );
				if ( ! self::hat_flagge( $alt ) ) { continue; }
				$adr['land'] = M24_Offers::ohne_flagge( $alt );
				$out['summe']['Kundenkonten mit Flagge']++;
				$out['zeilen'][] = sprintf( 'Konto #%d: Lieferanschrift "%s" -> "%s"', (int) $m->user_id, $alt, $adr['land'] );
				if ( $go ) { update_user_meta( (int) $m->user_id, '_m24_addr_shipping', $adr ); }
			}
		} else {
			$out['zeilen'][] = 'Kundenkonten uebersprungen — mit IDs laeuft nur die genannte Angebotsmenge.';
		}

		if ( empty( $out['zeilen'] ) ) {
			$out['zeilen'][] = 'Keine Flagge in Landwerten oder Positionstiteln gefunden.';
		}
		if ( $go && $out['summe']['Angebote mit Flagge'] > 0 && class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'offers', 'info', 'Flaggen aus Landwerten entfernt', array(
				'angebote' => (int) $out['summe']['Angebote mit Flagge'],
				'konten'   => (int) $out['summe']['Kundenkonten mit Flagge'],
			) );
		}
		return $out;
	}
}
