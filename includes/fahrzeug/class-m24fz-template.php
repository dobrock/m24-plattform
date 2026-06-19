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

	/** Preisblock: „Preis auf Anfrage" / Messing-Preis (+ ggf. reduziert) / bei verkauft kein Preis. */
	public static function preis_html( $id ) {
		if ( M24FZ_CPT::is_sold( $id ) ) { return ''; }
		if ( (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true ) ) { return '<span class="m24fz-preis">Preis auf Anfrage</span>'; }
		$p = (int) get_post_meta( $id, '_m24fz_preis', true );
		if ( $p <= 0 ) { return '<span class="m24fz-preis">Preis auf Anfrage</span>'; }
		$cur = M24FZ_Telemetry::currency_symbol( get_post_meta( $id, '_m24fz_waehrung', true ) );
		$red = (int) get_post_meta( $id, '_m24fz_preis_reduziert', true );
		$fmt = function ( $v ) use ( $cur ) { return esc_html( number_format( $v, 0, ',', '.' ) ) . '&nbsp;' . esc_html( $cur ); };
		// E) Steuerhinweis abhängig von „MwSt. ausweisbar".
		$note = (int) get_post_meta( $id, '_m24fz_mwst_ausweisbar', true ) ? 'Preis inkl. 19&nbsp;% MwSt.' : 'Differenzbesteuert nach §25a UStG';
		$alt  = ( $red > 0 && $red < $p ) ? '<span class="m24fz-preis-alt">' . $fmt( $p ) . '</span>' : '';
		$main = ( $red > 0 && $red < $p ) ? $red : $p;
		return $alt . '<span class="m24fz-preis">' . $fmt( $main ) . '</span><span class="m24fz-preis-note">' . $note . '</span>';
	}

	/**
	 * Jetpack Tiled Gallery (rectangular) je Kategorie rendern. Hebt die Tiled-Content-Breite
	 * auf die echte boxed Containerbreite (sonst rendert Classic-Tiled fix auf theme content_width
	 * = 696px → ~2/3 gestaucht). Reihenfolge = Backend-Sortierung (ids ⇒ orderby post__in).
	 */
	public static function tiled_gallery( $csv ) {
		$cw = (int) apply_filters( 'm24fz_gallery_content_width', 1036 );
		$f  = static function () use ( $cw ) { return $cw; };
		add_filter( 'tiled_gallery_content_width', $f, 999 );
		$html = do_shortcode( '[gallery ids="' . esc_attr( $csv ) . '" type="rectangular" columns="3" link="file"]' );
		remove_filter( 'tiled_gallery_content_width', $f, 999 );
		return $html;
	}

	/** YouTube-Video-ID aus diversen URL-Formen (youtu.be / watch?v= / embed/ / shorts/). */
	public static function yt_id( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) { return ''; }
		if ( preg_match( '~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|v/))([A-Za-z0-9_-]{11})~', $url, $m ) ) { return $m[1]; }
		if ( preg_match( '~^[A-Za-z0-9_-]{11}$~', $url ) ) { return $url; }
		return '';
	}

	/** Ausgewählte Labels einer Mehrfach-Meta (Zustand/Ausstattung) — nur gültige Slugs. */
	public static function chips( $id, $key, $options ) {
		$out = array();
		foreach ( (array) get_post_meta( $id, $key, true ) as $s ) { if ( isset( $options[ $s ] ) ) { $out[] = $options[ $s ]; } }
		return $out;
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

	/** 3er-Block: erste Außen-Bilder OHNE das Beitragsbild (Hero zeigt es bereits — keine Dublette). */
	public static function block_images( $id, $limit = 3 ) {
		$f      = (int) get_post_thumbnail_id( $id );
		$aussen = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_m24fz_gal_aussen', true ) ) ) );
		$out    = array();
		foreach ( $aussen as $a ) {
			if ( $a === $f ) { continue; }
			$out[] = $a;
			if ( count( $out ) >= $limit ) { break; }
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
		// Rennwagen: straßenspezifische Felder nicht ausgeben (Werte bleiben gespeichert).
		$is_renn = ( 'renn' === get_post_meta( $id, '_m24fz_template_typ', true ) );
		if ( $is_renn ) { unset( $fields['_m24fz_erstzulassung'], $fields['_m24fz_kraftstoff'], $fields['_m24fz_lenkung'] ); }
		$rows = array();
		foreach ( $fields as $k => $label ) {
			$v = trim( (string) get_post_meta( $id, $k, true ) );
			if ( '' === $v ) { continue; }
			if ( '_m24fz_leistung_ps' === $k )    { $v = M24FZ_Telemetry::leistung_label( $v ); }
			if ( '_m24fz_laufleistung' === $k )   { $v = M24FZ_Telemetry::laufleistung( $v, get_post_meta( $id, '_m24fz_laufleistung_einheit', true ) ); }
			$rows[] = array( 'label' => $label, 'value' => $v );
		}
		// Optionale Zusatzfelder (leer ⇒ ausgeblendet).
		$halter = (int) get_post_meta( $id, '_m24fz_anzahl_halter', true );
		if ( $halter > 0 ) { $rows[] = array( 'label' => 'Fahrzeughalter', 'value' => (string) $halter ); }
		$toggles = array( '_m24fz_matching_numbers' => 'Matching Numbers', '_m24fz_fahrbereit' => 'Fahrbereit', '_m24fz_zugelassen' => 'Zugelassen' );
		if ( $is_renn ) { unset( $toggles['_m24fz_zugelassen'] ); }
		foreach ( $toggles as $k => $label ) {
			if ( (int) get_post_meta( $id, $k, true ) ) { $rows[] = array( 'label' => $label, 'value' => 'Ja' ); }
		}
		// Land Erstauslieferung / Standort mit Flagge.
		foreach ( array( '_m24fz_land_erstauslieferung' => 'Erstauslieferung', '_m24fz_standort' => 'Standort' ) as $k => $label ) {
			$cc = trim( (string) get_post_meta( $id, $k, true ) );
			if ( '' === $cc ) { continue; }
			$txt = M24FZ_Telemetry::flag( $cc ) . ' ' . M24FZ_Telemetry::country_name( $cc );
			$rows[] = array( 'label' => $label, 'value' => $txt );
		}
		return $rows;
	}
}
