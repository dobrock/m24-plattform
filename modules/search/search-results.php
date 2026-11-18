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
		add_filter( 'posts_search',     array( __CLASS__, 'nummern_suche' ), 20, 2 );
	}

	/**
	 * Suche findet Teile auch ueber ihre Nummern (Daniel 18.11.2026): Artikelnummer, Variantennummern
	 * (z. B. 14e30058.1 aus den Preisoptionen) und BMW-Teilenummer. Gilt fuer die Seitensuche und die
	 * gruppierte Suche im Frontend, nur wenn der Begriff wie eine Nummer aussieht (enthaelt eine Ziffer).
	 */
	public static function nummern_suche( $search, $q ) {
		global $wpdb;
		if ( '' === $search || is_admin() || ! $q instanceof WP_Query || ! $q->is_search() ) {
			return $search;
		}
		$term = trim( (string) $q->get( 's' ) );
		if ( strlen( $term ) < 4 || strlen( $term ) > 40 || ! preg_match( '/\d/', $term ) ) {
			return $search;
		}
		$pt = $q->get( 'post_type' );
		$pts = is_array( $pt ) ? $pt : array( (string) $pt );
		if ( ! in_array( 'm24_teil', $pts, true ) && ! in_array( 'any', $pts, true ) && '' !== (string) $pts[0] ) {
			return $search;
		}
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$in = $wpdb->prepare(
			"{$wpdb->posts}.ID IN ( SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} pp ON pp.ID = pm.post_id
				WHERE pp.post_type = 'm24_teil' AND ( ( pm.meta_key IN ('_m24_artikelnummer','_m24_bmw_teilenummer') AND pm.meta_value LIKE %s )
					OR ( pm.meta_key = '_m24_preisoptionen' AND pm.meta_value LIKE %s ) ) )",
			$like, '%"art_nr":"' . $wpdb->esc_like( $term ) . '%'
		); // phpcs:ignore WordPress.DB
		return ' AND ( ( ' . preg_replace( '/^\s*AND\s*/', '', $search ) . ' ) OR ' . $in . ' ) ';
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
