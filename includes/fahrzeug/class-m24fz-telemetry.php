<?php
/**
 * M24 Fahrzeug — Telemetrie-Sets + Leistungs-Umrechnung (EINE Quelle der Wahrheit)
 * Modul: includes/fahrzeug/class-m24fz-telemetry.php
 *
 * Leistung: Eingabe in PS, Ausgabe IMMER „{kW} kW ({PS} PS)" einzeilig.
 *   kW = round( PS × 0,73549875, 2 ), deutsches Komma. 238 → „175,05 kW (238 PS)".
 * Telemetrie-Streifen ist typabhängig (strasse|renn); jedes Feld optional → leere Zelle weg.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Telemetry {

	const PS_TO_KW = 0.73549875;

	/** „175,05 kW (238 PS)" — leer wenn kein PS-Wert. */
	public static function leistung_label( $ps ) {
		$ps = (int) $ps;
		if ( $ps <= 0 ) { return ''; }
		$kw = number_format( round( $ps * self::PS_TO_KW, 2 ), 2, ',', '.' );
		return sprintf( '%s kW (%d PS)', $kw, $ps );
	}

	/** Getriebe darf NUR Manuell|Automatik sein (Dropdown). */
	public static function getriebe_options() {
		return array( '' => '—', 'Manuell' => 'Manuell', 'Automatik' => 'Automatik' );
	}

	private static function v( $id, $key ) { return trim( (string) get_post_meta( (int) $id, $key, true ) ); }

	/**
	 * Telemetrie-Zellen des Streifens (typabhängig). Nur befüllte Zellen.
	 * @return array Liste von [ 'label' => …, 'value' => … ] (Wert einzeilig).
	 */
	public static function strip_cells( $post_id ) {
		$id  = (int) $post_id;
		$typ = ( 'renn' === self::v( $id, '_m24fz_template_typ' ) ) ? 'renn' : 'strasse';
		$out = array();
		$add = function ( $label, $value ) use ( &$out ) { $value = trim( (string) $value ); if ( '' !== $value ) { $out[] = array( 'label' => $label, 'value' => $value ); } };

		if ( 'strasse' === $typ ) {
			$add( 'Baujahr',       self::v( $id, '_m24fz_baujahr' ) );
			$add( 'Laufleistung',  self::laufleistung( self::v( $id, '_m24fz_laufleistung' ) ) );
			$add( 'Leistung',      self::leistung_label( self::v( $id, '_m24fz_leistung_ps' ) ) );
			$add( 'Getriebe',      self::v( $id, '_m24fz_getriebe' ) );
			$add( 'Farbe',         self::v( $id, '_m24fz_farbe' ) );
			$add( self::v( $id, '_m24fz_tel_opt_label' ), self::v( $id, '_m24fz_tel_opt_value' ) );
		} else {
			$add( 'Baujahr',  self::v( $id, '_m24fz_baujahr' ) );
			$add( 'Leistung', self::leistung_label( self::v( $id, '_m24fz_leistung_ps' ) ) );
			$add( 'Getriebe', self::v( $id, '_m24fz_getriebe' ) );
			if ( self::v( $id, '_m24fz_wagenpass' ) )    { $add( 'Wagenpass', 'vorhanden' ); }
			if ( self::v( $id, '_m24fz_rennhistorie' ) ) { $add( 'Rennhistorie', 'dokumentiert' ); }
			for ( $i = 1; $i <= 3; $i++ ) {
				$add( self::v( $id, "_m24fz_race_opt{$i}_label" ), self::v( $id, "_m24fz_race_opt{$i}_value" ) );
			}
		}
		// Zellen mit leerem Label (z.B. unausgefülltes Optionsfeld) raus.
		return array_values( array_filter( $out, function ( $c ) { return '' !== trim( (string) $c['label'] ); } ) );
	}

	/** Laufleistung hübsch („123.456 km"), tolerant ggü. reiner Zahl. */
	public static function laufleistung( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) { return ''; }
		if ( preg_match( '/^\d[\d.\s]*$/', $raw ) ) {
			return number_format( (int) preg_replace( '/\D/', '', $raw ), 0, ',', '.' ) . ' km';
		}
		return $raw;
	}

	/* ── Länder + Flaggen (Land Erstauslieferung / Standort) ─────────────────── */

	/** Kuratierte Länderliste code => Name (deutsch). Filterbar. */
	public static function countries() {
		return apply_filters( 'm24fz_countries', array(
			'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz', 'IT' => 'Italien',
			'FR' => 'Frankreich', 'ES' => 'Spanien', 'PT' => 'Portugal', 'NL' => 'Niederlande',
			'BE' => 'Belgien', 'LU' => 'Luxemburg', 'GB' => 'Großbritannien', 'IE' => 'Irland',
			'DK' => 'Dänemark', 'SE' => 'Schweden', 'NO' => 'Norwegen', 'FI' => 'Finnland',
			'PL' => 'Polen', 'CZ' => 'Tschechien', 'US' => 'USA', 'CA' => 'Kanada',
			'JP' => 'Japan', 'AE' => 'VAE', 'AU' => 'Australien',
		) );
	}

	/** ISO-2-Code → Flaggen-Emoji (Regional Indicator Symbols). */
	public static function flag( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $code ) ) { return ''; }
		$a = mb_convert_encoding( '&#' . ( 127397 + ord( $code[0] ) ) . ';', 'UTF-8', 'HTML-ENTITIES' );
		$b = mb_convert_encoding( '&#' . ( 127397 + ord( $code[1] ) ) . ';', 'UTF-8', 'HTML-ENTITIES' );
		return $a . $b;
	}

	/** Name eines Codes (für Anzeige). */
	public static function country_name( $code ) {
		$c = self::countries();
		$code = strtoupper( trim( (string) $code ) );
		return isset( $c[ $code ] ) ? $c[ $code ] : $code;
	}
}
