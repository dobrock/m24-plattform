<?php
/**
 * M24 Plattform — Gruppierte Suche: Vollergebnis-Seite (Gruppen-Filter)
 * Modul: modules/search/search-results.php
 *
 * Die „Alle Ergebnisse anzeigen"-Links zeigen auf /?s=<q>&m24_group=<gruppe>.
 * Bei gesetztem, gueltigem m24_group rendert eine eigene Vorlage NUR diese Gruppe
 * (templates/search-group.php) — inkl. korrekter Preis-Logik fuer Teile und
 * Modell-Links fuer Fahrzeuge. Ohne Filter bleibt die Standard-Theme-Suche aktiv.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Search_Results {

	public static function init() {
		add_filter( 'query_vars',       array( __CLASS__, 'query_var' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
	}

	public static function query_var( $vars ) {
		$vars[] = 'm24_group';
		return $vars;
	}

	/** Aktuell angefragte Gruppe (validiert) oder '' . */
	public static function current_group() {
		$g = isset( $_GET['m24_group'] ) ? sanitize_key( wp_unslash( $_GET['m24_group'] ) ) : '';
		return M24_Search_Query::is_group( $g ) ? $g : '';
	}

	public static function current_query() {
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	}

	public static function template( $template ) {
		if ( is_search() && '' !== self::current_group() ) {
			$custom = M24_PLATTFORM_DIR . 'templates/search-group.php';
			if ( file_exists( $custom ) ) {
				return $custom;
			}
		}
		return $template;
	}
}
