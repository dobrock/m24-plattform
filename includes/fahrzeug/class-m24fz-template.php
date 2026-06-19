<?php
/**
 * M24 Fahrzeug — Template-Steuerung + Render-Helfer
 * Modul: includes/fahrzeug/class-m24fz-template.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Template {

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function route( $template ) {
		if ( is_singular( M24FZ_CPT::PT ) ) {
			$f = M24_PLATTFORM_DIR . 'templates/single-m24_fahrzeug.php';
			if ( file_exists( $f ) ) { return $f; }
		}
		return $template;
	}

	public static function assets() {
		if ( ! is_singular( M24FZ_CPT::PT ) ) { return; }
		$css = 'assets/css/fahrzeug.css'; $js = 'assets/js/fahrzeug.js';
		wp_enqueue_style( 'm24fz', plugins_url( $css, M24_PLATTFORM_FILE ), array(), filemtime( M24_PLATTFORM_DIR . $css ) );
		wp_enqueue_script( 'm24fz', plugins_url( $js, M24_PLATTFORM_FILE ), array(), filemtime( M24_PLATTFORM_DIR . $js ), true );
		wp_localize_script( 'm24fz', 'M24FZ', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'pid' => get_queried_object_id() ) );
	}

	/* ── Render-Helfer (von der Template-Datei genutzt) ──────────────────────── */

	/** Preisblock: „Preis auf Anfrage" / Messing-Preis / bei verkauft kein Preis. */
	public static function preis_html( $id ) {
		if ( M24FZ_CPT::is_sold( $id ) ) { return ''; }
		if ( (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true ) ) { return '<span class="m24fz-preis">Preis auf Anfrage</span>'; }
		$p = (int) get_post_meta( $id, '_m24fz_preis', true );
		if ( $p <= 0 ) { return '<span class="m24fz-preis">Preis auf Anfrage</span>'; }
		return '<span class="m24fz-preis">' . esc_html( number_format( $p, 0, ',', '.' ) ) . '&nbsp;€</span><span class="m24fz-preis-note">Differenzbesteuert nach §25a UStG</span>';
	}

	/** Alle Galerie-Bilder gruppiert: ['aussen'=>[ids],…] (nur nicht-leere). */
	public static function galleries( $id ) {
		$map = array( 'aussen' => 'Außen', 'innen' => 'Innen', 'motor' => 'Motor', 'unterboden' => 'Unterboden' );
		$out = array();
		foreach ( $map as $k => $label ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_m24fz_gal_' . $k, true ) ) ) );
			if ( $ids ) { $out[ $k ] = array( 'label' => $label, 'ids' => $ids ); }
		}
		return $out;
	}

	/** Flaches Bild-Array (Hero/Big-Bilder): Featured zuerst, dann Außen. */
	public static function hero_images( $id ) {
		$ids = array();
		$f   = get_post_thumbnail_id( $id );
		if ( $f ) { $ids[] = (int) $f; }
		foreach ( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_m24fz_gal_aussen', true ) ) ) as $a ) { if ( $a !== (int) $f ) { $ids[] = $a; } }
		return $ids;
	}

	/** Fahrzeugdaten-Zeilen (nur befüllte). */
	public static function daten_rows( $id ) {
		$fields = array(
			'_m24fz_erstzulassung' => 'Erstzulassung', '_m24fz_modell' => 'Modell', '_m24fz_baureihe' => 'Baureihe',
			'_m24fz_karosserie' => 'Karosserie', '_m24fz_hubraum' => 'Hubraum', '_m24fz_leistung_ps' => 'Leistung',
			'_m24fz_getriebe' => 'Getriebe', '_m24fz_antrieb' => 'Antrieb', '_m24fz_lenkung' => 'Lenkung',
			'_m24fz_kraftstoff' => 'Kraftstoff', '_m24fz_laufleistung' => 'Laufleistung', '_m24fz_aussenfarbe' => 'Außenfarbe',
			'_m24fz_farbbez_hersteller' => 'Farbbez. Hersteller', '_m24fz_innenfarbe' => 'Innenfarbe',
			'_m24fz_innenmaterial' => 'Innenmaterial', '_m24fz_fin' => 'FIN', '_m24fz_neu_gebraucht' => 'Zustand',
		);
		$rows = array();
		foreach ( $fields as $k => $label ) {
			$v = trim( (string) get_post_meta( $id, $k, true ) );
			if ( '' === $v ) { continue; }
			if ( '_m24fz_leistung_ps' === $k )    { $v = M24FZ_Telemetry::leistung_label( $v ); }
			if ( '_m24fz_laufleistung' === $k )   { $v = M24FZ_Telemetry::laufleistung( $v ); }
			$rows[] = array( 'label' => $label, 'value' => $v );
		}
		// Land Erstauslieferung / Standort mit Flagge.
		foreach ( array( '_m24fz_land_erstauslieferung' => 'Erstauslieferung', '_m24fz_standort' => 'Standort' ) as $k => $label ) {
			$cc = trim( (string) get_post_meta( $id, $k, true ) );
			if ( '' === $cc ) { continue; }
			$txt = M24FZ_Telemetry::flag( $cc ) . ' ' . M24FZ_Telemetry::country_name( $cc );
			if ( '_m24fz_standort' === $k && get_post_meta( $id, '_m24fz_standort_ort', true ) ) { $txt .= ', ' . get_post_meta( $id, '_m24fz_standort_ort', true ); }
			$rows[] = array( 'label' => $label, 'value' => $txt );
		}
		return $rows;
	}
}
