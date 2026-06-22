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

	/** Zustand-Mehrfachauswahl (slug => Label). Filterbar. */
	public static function zustand_options() {
		return apply_filters( 'm24fz_zustand_options', array(
			'beschaedigt'           => 'Beschädigt',
			'unfallfrei'            => 'Unfallfrei',
			'restaurationsobjekt'   => 'Restaurationsobjekt',
			'teilrestauriert'       => 'Teilrestauriert',
			'vollrestauriert'       => 'Vollrestauriert',
			'unrestauriert-original' => 'Unrestaurierter Originalzustand',
		) );
	}

	/** Ausstattung-Mehrfachauswahl (slug => Label). Filterbar/erweiterbar. */
	public static function ausstattung_options() {
		return apply_filters( 'm24fz_ausstattung_options', array(
			'abs'          => 'ABS',
			'airbag'       => 'Airbag',
			'klimaanlage'  => 'Klimaanlage',
			'schiebedach'  => 'Schiebedach',
			'servolenkung' => 'Servolenkung',
		) );
	}

	/* ── Enum-Sets für Auswahlfelder (value == gespeicherter Wert) ───────────── */
	public static function neu_gebraucht_options() { return array( 'Gebraucht', 'Neu' ); }
	public static function antrieb_options()       { return array( 'Heck', 'Front', 'Allrad' ); }
	public static function kraftstoff_options()    { return array( 'Benzin', 'Diesel' ); }
	public static function lenkung_options()       { return array( 'Links', 'Rechts' ); }
	public static function innenmaterial_options() { return array( 'Velours', 'Stoff', 'Kunstleder', 'Leder', 'Vollleder', 'Teilleder / Stoff' ); }
	public static function innenfarbe_options()    { return array( 'Grau', 'Schwarz', 'Bordeauxrot', 'Rot', 'Weiß', 'Grün', 'Blau' ); }
	public static function karosserie_options()    { return array( 'Coupé', 'Limousine', 'Cabriolet', 'Touring', '2-türige Limousine' ); }

	/** Fahrzeugtyp-Label (A3 „Bezeichnungen übernehmen"). */
	public static function typ_label( $typ ) { return ( 'renn' === $typ ) ? 'Rennwagen' : 'Straßenfahrzeug'; }

	/**
	 * Kanonischen Enum-Wert finden (case-insensitive + trim, optional Alias-Tabelle).
	 * Gibt den exakten Options-Wert zurück oder '' bei echtem Nicht-Treffer (Schutz bleibt).
	 */
	public static function match_enum( $value, $options, $aliases = array() ) {
		$v = trim( (string) $value );
		if ( '' === $v ) { return ''; }
		foreach ( $aliases as $from => $to ) { if ( 0 === strcasecmp( $v, $from ) ) { $v = $to; break; } }
		foreach ( $options as $opt ) { if ( 0 === strcasecmp( $v, (string) $opt ) ) { return (string) $opt; } }
		return '';
	}

	/** Alias-Tabellen je Feld (gängige Schreibvarianten → Enum). */
	public static function enum_aliases( $key ) {
		$a = array(
			'_m24fz_neu_gebraucht' => array( 'Gebrauchte' => 'Gebraucht', 'Gebrauchtwagen' => 'Gebraucht', 'Neuwagen' => 'Neu' ),
			'_m24fz_antrieb'       => array( 'Hinterrad' => 'Heck', 'Hinterradantrieb' => 'Heck', 'RWD' => 'Heck', 'Frontantrieb' => 'Front', 'Vorderrad' => 'Front', 'FWD' => 'Front', 'Allradantrieb' => 'Allrad', '4x4' => 'Allrad', 'AWD' => 'Allrad' ),
			'_m24fz_lenkung'       => array( 'LHD' => 'Links', 'Linkslenker' => 'Links', 'RHD' => 'Rechts', 'Rechtslenker' => 'Rechts' ),
			'_m24fz_karosserie'    => array( 'Coupe' => 'Coupé', 'Kombi' => 'Touring' ),
		);
		return $a[ $key ] ?? array();
	}

	/** Bekannte Marken (für brand-Ableitung aus dem Titel). Filterbar. */
	public static function known_brands() {
		return apply_filters( 'm24fz_known_brands', array(
			'Mercedes-Benz', 'Daimler-Benz', 'Aston Martin', 'BMW', 'Porsche', 'Mercedes', 'Audi',
			'Volkswagen', 'VW', 'Ferrari', 'Lamborghini', 'Ford', 'Opel', 'Jaguar', 'Alpina',
		) );
	}

	/** Marke grob aus einem Titel ableiten (erste bekannte Marke). '' wenn keine. */
	public static function guess_brand( $title ) {
		$t = (string) $title;
		foreach ( self::known_brands() as $b ) {
			if ( false !== stripos( $t, $b ) ) { return ( 'VW' === $b ) ? 'Volkswagen' : $b; }
		}
		return '';
	}

	/** Währungssymbol/-kürzel. EUR → „€", CHF → „CHF". */
	public static function currency_symbol( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		return 'CHF' === $code ? 'CHF' : '€';
	}

	private static function v( $id, $key ) { return trim( (string) get_post_meta( (int) $id, $key, true ) ); }

	/** Farbe-Keypoint: Hersteller-Farbbezeichnung bevorzugt, sonst einfache Außen-/Farbangabe. */
	private static function farbe_value( $id ) {
		$b = self::v( $id, '_m24fz_farbbez_hersteller' );
		if ( '' !== $b ) { return $b; }
		$a = self::v( $id, '_m24fz_aussenfarbe' );
		if ( '' !== $a ) { return $a; }
		return self::v( $id, '_m24fz_farbe' );
	}

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
			$add( 'Laufleistung',  self::laufleistung( self::v( $id, '_m24fz_laufleistung' ), self::v( $id, '_m24fz_laufleistung_einheit' ) ) );
			$add( 'Leistung',      self::leistung_label( self::v( $id, '_m24fz_leistung_ps' ) ) );
			$add( 'Getriebe',      self::v( $id, '_m24fz_getriebe' ) );
			// 5. Zelle „Farbe": Hersteller-Farbbezeichnung, sonst Fallback auf die einfache Außenfarbe.
			$add( 'Farbe',         self::farbe_value( $id ) );
			$add( self::v( $id, '_m24fz_tel_opt_label' ), self::v( $id, '_m24fz_tel_opt_value' ) );
		} else {
			// Renn-spezifische Option-Substitution (kumulativ): Option 1 belegt → „Farbe" weg;
			// Option 2 belegt → zusätzlich „Rennhistorie" weg; Option 3 belegt → zusätzlich „Wagenpass" weg.
			$opt1 = '' !== self::v( $id, '_m24fz_race_opt1_value' );
			$opt2 = '' !== self::v( $id, '_m24fz_race_opt2_value' );
			$opt3 = '' !== self::v( $id, '_m24fz_race_opt3_value' );
			$add( 'Baujahr',  self::v( $id, '_m24fz_baujahr' ) );
			$add( 'Leistung', self::leistung_label( self::v( $id, '_m24fz_leistung_ps' ) ) );
			$add( 'Getriebe', self::v( $id, '_m24fz_getriebe' ) );
			if ( ! $opt1 ) { $add( 'Farbe', self::farbe_value( $id ) ); }
			if ( ! $opt3 && self::v( $id, '_m24fz_wagenpass' ) )    { $add( 'Wagenpass', 'vorhanden' ); }
			if ( ! $opt2 && self::v( $id, '_m24fz_rennhistorie' ) ) { $add( 'Rennhistorie', 'dokumentiert' ); }
			for ( $i = 1; $i <= 3; $i++ ) {
				$add( self::v( $id, "_m24fz_race_opt{$i}_label" ), self::v( $id, "_m24fz_race_opt{$i}_value" ) );
			}
		}
		// Zellen mit leerem Label (z.B. unausgefülltes Optionsfeld) raus.
		return array_values( array_filter( $out, function ( $c ) { return '' !== trim( (string) $c['label'] ); } ) );
	}

	/** Laufleistung hübsch („123.456 km" / „… mi"), tolerant ggü. reiner Zahl. */
	public static function laufleistung( $raw, $unit = 'km' ) {
		$raw  = trim( (string) $raw );
		if ( '' === $raw ) { return ''; }
		$unit = ( 'mi' === strtolower( trim( (string) $unit ) ) ) ? 'mi' : 'km';
		if ( preg_match( '/^\d[\d.\s]*$/', $raw ) ) {
			return number_format( (int) preg_replace( '/\D/', '', $raw ), 0, ',', '.' ) . ' ' . $unit;
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
