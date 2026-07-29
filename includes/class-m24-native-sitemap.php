<?php
/**
 * M24 Plattform — Native XML-Sitemap (Weg B, löst das Dritt-Plugin „XML Sitemaps" ab).
 *
 * /sitemap.xml wird ein Sitemap-INDEX aus dem M24-Plugin; typisierte Unter-Sitemaps unter
 * /sitemap-m24-<key>.xml. Es kommen AUSSCHLIESSLICH indexierbare, self-canonical 200-URLs hinein.
 *
 * HARTE REGEL 1 (Single Source of Truth): Die Aufnahme-Entscheidung nutzt DIESELBE Logik, die im
 * Frontend das robots-Meta setzt — NICHT neu implementiert:
 *   - m24_teil       → M24_Catalog_SEO::is_indexable_teil()
 *   - m24_fahrzeug   → veröffentlicht && ! M24FZ_CPT::is_disabled()
 *   - Hubs           → Allowlist m24_indexable_hub_slugs (= Quelle von M24_Catalog_Hub::seo_robots)
 *   - Seiten/Blog/Terms → wpSEO-/Yoast-noindex-Meta (dieselben Werte, die das SEO-Plugin rendert)
 * → Was noindex rendert, fehlt automatisch; wird etwas auf index gestellt, erscheint es automatisch.
 *
 * REGEL 2: keine 301/302-Quellen (nur Endziele). REGEL 3: nur self-canonical (keine ?param-/Facetten-URLs).
 *
 * Reversibel: Konstante M24_NATIVE_SITEMAP=false ODER Option m24_native_sitemap='0' → Modul inaktiv,
 * das Dritt-Plugin bedient /sitemap.xml wieder. Cache via Transients, Invalidierung bei save_post/Term-Edit.
 *
 * @package m24-plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Native_Sitemap {

	const QV           = 'm24_sitemap';
	const REWRITE_FLAG = 'm24_native_sitemap_rw_v1';
	const CACHE_PREFIX = 'm24_smap_';
	const CACHE_TTL    = 12 * HOUR_IN_SECONDS;

	/** Typisierte Unter-Sitemaps: key => Builder-Methode. */
	private static function submaps(): array {
		return array(
			'products'      => 'build_products',      // /rennsport-teile/* (index)
			'gebrauchtteile'=> 'build_gebrauchtteile', // Gebrauchtteile (index)
			'vehicles'      => 'build_vehicles',       // Fahrzeuge / For-Sale (index)
			'hubs'          => 'build_hubs',           // Modell-Hubs (Allowlist)
			'categories'    => 'build_categories',     // Kategorie-Landingpages (index)
			'pages'         => 'build_pages',          // statische Seiten (index)
			'blog'          => 'build_blog',           // Blog-Beiträge (index)
		);
	}

	public static function enabled(): bool {
		if ( defined( 'M24_NATIVE_SITEMAP' ) && ! M24_NATIVE_SITEMAP ) { return false; }
		return '0' !== (string) get_option( 'm24_native_sitemap', '1' );
	}

	public static function init() {
		if ( ! self::enabled() ) { return; }
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_flush' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
		// Cache-Invalidierung bei Inhaltsänderungen (nicht bei jedem Abruf neu bauen).
		add_action( 'save_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'edited_term', array( __CLASS__, 'flush_cache' ) );
	}

	public static function add_rewrite() {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?' . self::QV . '=index', 'top' ); // M24 gewinnt /sitemap.xml
		add_rewrite_rule( '^sitemap-m24-([a-z0-9-]+)\.xml$', 'index.php?' . self::QV . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) { $vars[] = self::QV; return $vars; }

	public static function maybe_flush() {
		if ( get_option( self::REWRITE_FLAG ) === self::REWRITE_FLAG ) { return; }
		self::add_rewrite();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, self::REWRITE_FLAG, false );
	}

	public static function flush_cache() {
		foreach ( array_keys( self::submaps() ) as $key ) { delete_transient( self::CACHE_PREFIX . $key ); }
		delete_transient( self::CACHE_PREFIX . 'index' );
	}

	/* ── Serving ──────────────────────────────────────────────────────────── */

	public static function maybe_render() {
		$key = get_query_var( self::QV );
		if ( '' === (string) $key ) { return; }
		$key = sanitize_key( (string) $key );

		if ( 'index' === $key ) {
			$xml = self::cached( 'index', array( __CLASS__, 'build_index' ) );
		} elseif ( array_key_exists( $key, self::submaps() ) ) {
			$method = self::submaps()[ $key ];
			$xml    = self::cached( $key, array( __CLASS__, $method ) );
		} else {
			status_header( 404 );
			return; // unbekannter Sitemap-Key → normal weiter (404)
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true ); // Sitemaps selbst nicht indexieren
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput — Builder escapen jede URL
		exit;
	}

	private static function cached( string $key, $builder ): string {
		$hit = get_transient( self::CACHE_PREFIX . $key );
		if ( is_string( $hit ) && '' !== $hit ) { return $hit; }
		$xml = (string) call_user_func( $builder );
		set_transient( self::CACHE_PREFIX . $key, $xml, self::CACHE_TTL );
		return $xml;
	}

	/* ── Index ────────────────────────────────────────────────────────────── */

	public static function build_index(): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( array_keys( self::submaps() ) as $key ) {
			$entries = self::cached( $key, array( __CLASS__, self::submaps()[ $key ] ) );
			// Leere Unter-Sitemap (nur urlset-Rahmen, kein <url>) nicht in den Index aufnehmen.
			if ( false === strpos( $entries, '<url>' ) ) { continue; }
			$loc     = home_url( '/sitemap-m24-' . $key . '.xml' );
			$lastmod = self::extract_latest_lastmod( $entries );
			$xml    .= "\t<sitemap>\n\t\t<loc>" . esc_url( $loc ) . "</loc>\n";
			if ( '' !== $lastmod ) { $xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n"; }
			$xml    .= "\t</sitemap>\n";
		}
		$xml .= '</sitemapindex>' . "\n";
		return $xml;
	}

	/** Jüngstes <lastmod> aus einer Unter-Sitemap ziehen (für den Index-Eintrag). */
	private static function extract_latest_lastmod( string $xml ): string {
		if ( ! preg_match_all( '#<lastmod>([^<]+)</lastmod>#', $xml, $m ) || empty( $m[1] ) ) { return ''; }
		rsort( $m[1] );
		return (string) $m[1][0];
	}

	/* ── Gemeinsame urlset-Ausgabe ────────────────────────────────────────── */

	/**
	 * @param array $urls Liste ['loc' => string, 'lastmod' => string|'']
	 */
	private static function urlset( array $urls ): string {
		$excl_paths = array_map( array( __CLASS__, 'path_only' ), (array) apply_filters( 'm24_sitemap_exclude_urls', array() ) );
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		$seen = array();
		foreach ( $urls as $u ) {
			$loc = isset( $u['loc'] ) ? (string) $u['loc'] : '';
			if ( '' === $loc || false !== strpos( $loc, '?' ) ) { continue; }   // Regel 3: keine Param-URLs
			if ( isset( $seen[ $loc ] ) ) { continue; }
			if ( in_array( self::path_only( $loc ), $excl_paths, true ) ) { continue; } // Regel 2: Ausschlussliste (z. B. 301-Quellen)
			$seen[ $loc ] = 1;
			$xml .= "\t<url>\n\t\t<loc>" . esc_url( $loc ) . "</loc>\n";
			if ( ! empty( $u['lastmod'] ) ) { $xml .= "\t\t<lastmod>" . esc_html( (string) $u['lastmod'] ) . "</lastmod>\n"; }
			$xml .= "\t</url>\n";
		}
		$xml .= '</urlset>' . "\n";
		return $xml;
	}

	private static function path_only( string $url ): string {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $p ? '/' : untrailingslashit( $p );
	}

	/** Post → URL-Eintrag (self-canonical Permalink + lastmod). */
	private static function post_entry( int $id ): array {
		return array(
			'loc'     => get_permalink( $id ),
			'lastmod' => (string) get_post_modified_time( 'c', true, $id ),
		);
	}

	/** IDs eines Post-Typs (published), gefiltert über einen Indexierbar-Callback. */
	private static function collect_posts( string $post_type, callable $is_indexable ): array {
		if ( ! post_type_exists( $post_type ) ) { return array(); }
		$ids = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );
		$out = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( ! $is_indexable( $id ) ) { continue; }
			if ( ! apply_filters( 'm24_sitemap_include_post', true, $id, $post_type ) ) { continue; }
			$out[] = self::post_entry( $id );
		}
		return $out;
	}

	/* ── Builder: M24-Typen (exakte SSoT) ─────────────────────────────────── */

	public static function build_products(): string {
		return self::urlset( self::collect_posts( 'm24_teil', static function ( $id ) {
			return 'neu' === get_post_meta( $id, '_m24_typ', true )
				&& class_exists( 'M24_Catalog_SEO' ) && M24_Catalog_SEO::is_indexable_teil( $id );
		} ) );
	}

	public static function build_gebrauchtteile(): string {
		return self::urlset( self::collect_posts( 'm24_teil', static function ( $id ) {
			return 'neu' !== get_post_meta( $id, '_m24_typ', true )
				&& class_exists( 'M24_Catalog_SEO' ) && M24_Catalog_SEO::is_indexable_teil( $id );
		} ) );
	}

	public static function build_vehicles(): string {
		return self::urlset( self::collect_posts( 'm24_fahrzeug', static function ( $id ) {
			return ! ( class_exists( 'M24FZ_CPT' ) && M24FZ_CPT::is_disabled( $id ) );
		} ) );
	}

	public static function build_hubs(): string {
		$urls = array();
		if ( class_exists( 'M24_Catalog_Hub' ) ) {
			$allow = (array) apply_filters( 'm24_indexable_hub_slugs', array( 'e36', 'z4-gt3' ) );
			$reg   = (array) M24_Catalog_Hub::registry(); // [slug => post_id]
			foreach ( $allow as $slug ) {
				$slug = (string) $slug;
				if ( '' === $slug ) { continue; }
				$loc     = M24_Catalog_Hub::url( $slug );
				$lastmod = isset( $reg[ $slug ] ) ? (string) get_post_modified_time( 'c', true, (int) $reg[ $slug ] ) : '';
				$urls[]  = array( 'loc' => $loc, 'lastmod' => $lastmod );
			}
		}
		return self::urlset( $urls );
	}

	/* ── Builder: Fremd-SEO-Typen (wpSEO-/Yoast-noindex-Meta) ─────────────── */

	/** true, wenn die Seite/der Beitrag laut SEO-Plugin-Meta noindex ist (dieselbe Quelle wie das Frontend-Meta). */
	public static function seo_noindex_post( int $id ): bool {
		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) ) { return true; } // Yoast
		$code = (string) get_post_meta( $id, '_wpseo_edit_robots', true );                                     // wpSEO (Sergej Müller)
		$noindex_codes = (array) apply_filters( 'm24_wpseo_noindex_codes', array( '4', '5', '6', '7' ) );
		return '' !== $code && in_array( $code, $noindex_codes, true );
	}

	public static function build_pages(): string {
		return self::urlset( self::collect_posts( 'page', static function ( $id ) {
			return ! self::seo_noindex_post( (int) $id );
		} ) );
	}

	public static function build_blog(): string {
		return self::urlset( self::collect_posts( 'post', static function ( $id ) {
			return ! self::seo_noindex_post( (int) $id );
		} ) );
	}

	public static function build_categories(): string {
		$urls      = array();
		$taxes     = (array) apply_filters( 'm24_sitemap_category_taxonomies', array( 'category' ) );
		foreach ( $taxes as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) { continue; }
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true ) );
			if ( is_wp_error( $terms ) ) { continue; }
			foreach ( $terms as $t ) {
				if ( '1' === (string) get_term_meta( $t->term_id, '_yoast_wpseo_meta-robots-noindex', true ) ) { continue; }
				if ( ! apply_filters( 'm24_sitemap_include_term', true, (int) $t->term_id, $tax ) ) { continue; }
				$link = get_term_link( $t );
				if ( is_wp_error( $link ) ) { continue; }
				$urls[] = array( 'loc' => $link, 'lastmod' => '' );
			}
		}
		return self::urlset( $urls );
	}
}
