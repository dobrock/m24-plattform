<?php
/**
 * M24 Plattform — Katalog: URL-Routing (Rewrites + kanonische Links)
 * Modul: catalog-rewrites.php
 *
 * Typbasierte URLs für CPT `m24_teil`:
 *   Gebrauchtteile (_m24_typ=gebraucht): /gebrauchtteile/{slug}/
 *   Rennsport-Teile (_m24_typ=neu):      /rennsport-teile/{slug}/
 * Archive:  /gebrauchtteile/  und  /rennsport-teile/  (+ /page/N/)
 *
 * `post_type_link` erzeugt die kanonische URL je nach Typ; ein 301 auf
 * template_redirect leitet falsche Präfixe und Alt-URLs (/teile/...) auf
 * die kanonische Adresse um -> keine Dubletten (SEO).
 *
 * Hinweis: built-in CPT-Rewrite ist in catalog-cpt.php abgeschaltet
 * (rewrite => false, has_archive => false). Nach Deploy: Permalinks flushen.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Rewrites {

	const POST_TYPE = 'm24_teil';

	/** Liefert das URL-Präfix zum Teil-Typ. */
	public static function prefix_for_typ( $typ ) {
		return ( 'neu' === $typ ) ? 'rennsport-teile' : 'gebrauchtteile';
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'post_type_link', array( __CLASS__, 'permalink' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'canonical_redirect' ) );
	}

	public static function add_rules() {
		$single = 'index.php?' . self::POST_TYPE . '=$matches[1]';

		// Archiv mit Pagination (spezifischer -> zuerst)
		add_rewrite_rule( '^rennsport-teile/page/([0-9]+)/?$', 'index.php?m24_teil_archiv=neu&paged=$matches[1]', 'top' );
		add_rewrite_rule( '^gebrauchtteile/page/([0-9]+)/?$', 'index.php?m24_teil_archiv=gebraucht&paged=$matches[1]', 'top' );

		// Archiv-Basis
		add_rewrite_rule( '^rennsport-teile/?$', 'index.php?m24_teil_archiv=neu', 'top' );
		add_rewrite_rule( '^gebrauchtteile/?$', 'index.php?m24_teil_archiv=gebraucht', 'top' );

		// Einzelteile (beide Präfixe lösen denselben Post per Slug auf)
		add_rewrite_rule( '^rennsport-teile/([^/]+)/?$', $single, 'top' );
		add_rewrite_rule( '^gebrauchtteile/([^/]+)/?$', $single, 'top' );

		// Alt-URL aus der Migration: /teile/{slug}/ -> wird per 301 kanonisiert
		add_rewrite_rule( '^teile/([^/]+)/?$', $single, 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'm24_teil_archiv';
		$vars[] = 'm24_modell';
		return $vars;
	}

	/** Kanonische Einzelteil-URL nach Typ. */
	public static function permalink( $url, $post ) {
		if ( empty( $post ) || self::POST_TYPE !== $post->post_type ) {
			return $url;
		}
		$typ    = get_post_meta( $post->ID, '_m24_typ', true );
		$prefix = self::prefix_for_typ( $typ );
		return home_url( user_trailingslashit( $prefix . '/' . $post->post_name ) );
	}

	/** Falsches Präfix / Alt-URL -> 301 auf kanonische Adresse. */
	public static function canonical_redirect() {
		if ( is_admin() || ! is_singular( self::POST_TYPE ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$canonical = get_permalink( $post );
		if ( ! $canonical ) {
			return;
		}
		$current_path   = '/' . trim( $GLOBALS['wp']->request, '/' );
		$canonical_path = (string) wp_parse_url( $canonical, PHP_URL_PATH );
		if ( untrailingslashit( $current_path ) !== untrailingslashit( $canonical_path ) ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}
	}
}
