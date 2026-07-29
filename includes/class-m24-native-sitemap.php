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

		// Auto-Flush bei jedem Deploy: neue Plugin-Version → Sitemap-Cache verwerfen (sonst liefert der
		// 12h-Transient nach einem Code-Fix weiter die alte Sitemap, ohne dass ein Post gespeichert wurde).
		if ( (string) get_option( 'm24_smap_ver', '' ) !== (string) M24_PLATTFORM_VERSION ) {
			self::flush_cache();
			update_option( 'm24_smap_ver', (string) M24_PLATTFORM_VERSION, false );
		}

		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_flush' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 ); // prio 0 → gewinnt /sitemap.xml vor Jetpack

		// Cache-Invalidierung bei Inhaltsänderungen.
		add_action( 'save_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_cache' ) );
		add_action( 'edited_term', array( __CLASS__, 'flush_cache' ) );
		// … und an „Cache leeren" (WP Rocket) + einen generischen Hook + manuellen Rebuild.
		foreach ( array( 'rocket_purge_cache', 'after_rocket_clean_domain', 'rocket_after_clean_files', 'm24_flush_sitemap' ) as $h ) {
			add_action( $h, array( __CLASS__, 'flush_cache' ) );
		}
		add_action( 'admin_post_m24_sitemap_rebuild', array( __CLASS__, 'handle_rebuild' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_node' ), 90 );
	}

	/** Manueller „Sitemap neu bauen"-Eintrag in der Admin-Bar (nur Admins). */
	public static function admin_bar_node( $bar ) {
		if ( ! current_user_can( 'manage_options' ) || ! is_object( $bar ) ) { return; }
		$bar->add_node( array(
			'id'    => 'm24-sitemap-rebuild',
			'title' => 'M24 Sitemap neu bauen',
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=m24_sitemap_rebuild' ), 'm24_sitemap_rebuild' ),
		) );
	}

	public static function handle_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) ); }
		check_admin_referer( 'm24_sitemap_rebuild' );
		self::flush_cache();
		// Vorwärmen: Index (baut alle Unter-Sitemaps) sofort neu erzeugen, damit der nächste Crawl frisch ist.
		self::cached( 'index', array( __CLASS__, 'build_index' ) );
		wp_safe_redirect( add_query_arg( 'm24_smap', 'rebuilt', wp_get_referer() ?: home_url( '/sitemap.xml' ) ) );
		exit;
	}

	/** Cache-Salt: in ALLE Transient-Keys eingemischt → Bump invalidiert Unter-Sitemaps UND Redirect-Checks global. */
	private static function salt(): string { return (string) get_option( 'm24_smap_salt', '1' ); }

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

	/** Globale Invalidierung: Salt hochzählen → alle salt-tragenden Transients (Unter-Sitemaps + rc-Checks) verwaisen. */
	public static function flush_cache() {
		update_option( 'm24_smap_salt', (string) ( (int) get_option( 'm24_smap_salt', '1' ) + 1 ), false );
	}

	/* ── Serving ──────────────────────────────────────────────────────────── */

	public static function maybe_render() {
		$key = (string) get_query_var( self::QV );
		if ( '' === $key ) {
			// Falls unsere Rewrite-Rule die Route NICHT gewonnen hat (z. B. Jetpack bedient /sitemap.xml):
			// den Pfad direkt prüfen und die M24-Sitemap ausliefern (template_redirect prio 0 → vor Jetpack).
			$path = strtolower( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ) ); // phpcs:ignore
			if ( '/sitemap.xml' === $path || '/sitemap-m24-index.xml' === $path ) { $key = 'index'; }
			elseif ( preg_match( '#^/sitemap-m24-([a-z0-9-]+)\.xml$#', $path, $m ) ) { $key = $m[1]; }
			else { return; }
		}
		$key = sanitize_key( $key );

		if ( 'index' === $key ) {
			$xml = self::cached( 'index', array( __CLASS__, 'build_index' ) );
		} elseif ( array_key_exists( $key, self::submaps() ) ) {
			$xml = self::cached( $key, array( __CLASS__, self::submaps()[ $key ] ) );
		} else {
			return; // unbekannter Key → normal weiter
		}

		// XML-Whitespace-Fix (QA #3): globaler Whitespace VOR unserer Ausgabe (Leerzeile außerhalb der PHP-Tags
		// in irgendeinem Plugin/Theme-File) landet sonst als führendes \n vor <?xml → Viewer bricht ab.
		// Alle Output-Buffer leeren → <?xml als erstes Byte, unabhängig vom globalen Whitespace.
		while ( ob_get_level() ) { ob_end_clean(); }
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow', true ); // Sitemaps selbst nicht indexieren
		}
		echo ltrim( $xml ); // phpcs:ignore WordPress.Security.EscapeOutput — Builder escapen jede URL; ltrim = Sicherheitsnetz
		exit;
	}

	private static function cached( string $key, $builder ): string {
		$tk  = self::CACHE_PREFIX . self::salt() . '_' . $key; // salt im Key → flush_cache() invalidiert global
		$hit = get_transient( $tk );
		if ( is_string( $hit ) && '' !== $hit ) { return $hit; }
		$xml = (string) call_user_func( $builder );
		set_transient( $tk, $xml, self::CACHE_TTL );
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
	 * @param array $urls        Liste ['loc' => string, 'lastmod' => string|'']
	 * @param bool  $http_verify true → jede URL zusätzlich per HTTP prüfen und 3xx-Redirects verwerfen
	 *                           (für redirect-anfällige Typen: categories/pages/blog). CPT-Singles/Hubs sind
	 *                           self-canonical per Konstruktion → kein HTTP nötig.
	 */
	private static function urlset( array $urls, bool $http_verify = false ): string {
		$excl_paths = array_map( array( __CLASS__, 'norm_path' ), (array) apply_filters( 'm24_sitemap_exclude_urls', array() ) );
		$redir_src  = self::redirect_source_paths(); // Regel 2 (SSoT): dieselben Quell-Pfade, die das Plugin 301-umleitet
		$verify     = $http_verify && (bool) apply_filters( 'm24_sitemap_http_verify', true );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		$seen = array();
		foreach ( $urls as $u ) {
			$loc = isset( $u['loc'] ) ? (string) $u['loc'] : '';
			if ( '' === $loc || false !== strpos( $loc, '?' ) ) { continue; }   // Regel 3: keine Param-URLs
			if ( isset( $seen[ $loc ] ) ) { continue; }
			$np = self::norm_path( $loc );
			if ( in_array( $np, $excl_paths, true ) ) { continue; }             // manuelle Ausschlussliste
			if ( self::is_redirect_source( $np, $redir_src ) ) { continue; }    // Regel 2: plugin-verwaltete 301-Quelle (deterministisch)
			if ( $verify && self::http_redirects( $loc ) ) { continue; }        // generisch: liefert 3xx → raus
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

	/** Pfad normalisiert: lowercase, ohne Rand-Slashes (wie M24_Catalog_Hub::legacy_redirect). */
	private static function norm_path( string $url ): string {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $p ) { $p = $url; }
		return strtolower( trim( rawurldecode( $p ), '/' ) );
	}

	/**
	 * Regel 2 (Single Source of Truth): alle 301-Quell-Pfade, die das Plugin selbst umleitet — die exakt
	 * gleiche Quelle wie M24_Catalog_Hub::legacy_redirect (inkl. der per Filter eingespeisten Fahrzeug-Reclaims).
	 * So kann die Sitemap NIE eine plugin-umgeleitete Alt-URL listen, und neue 301-Einträge wirken automatisch.
	 */
	private static function redirect_source_paths(): array {
		$paths = array();
		if ( class_exists( 'M24_Catalog_Hub' ) ) {
			foreach ( array_keys( (array) M24_Catalog_Hub::legacy_paths() ) as $p )    { $paths[] = self::norm_path( (string) $p ); }
			foreach ( array_keys( (array) M24_Catalog_Hub::legacy_reclaims() ) as $p ) { $paths[] = self::norm_path( (string) $p ); }
		}
		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/** true, wenn $norm_path exakt ODER als Präfix eine 301-Quelle ist (legacy_paths matcht prefix-basiert). */
	private static function is_redirect_source( string $norm_path, array $sources ): bool {
		foreach ( $sources as $src ) {
			if ( '' === $src ) { continue; }
			if ( $norm_path === $src || 0 === strpos( $norm_path, $src . '/' ) ) { return true; }
		}
		return false;
	}

	/**
	 * Generischer Redirect-Check: liefert die URL beim HEAD einen 3xx (ohne zu folgen), ist sie eine
	 * Weiterleitungs-Quelle → nicht in die Sitemap. Ergebnis 12h gecacht (pro URL), damit der Kalt-Build der
	 * redirect-anfälligen Sitemaps (categories/pages/blog) nicht bei jedem Abruf erneut per HTTP prüft.
	 * Transiente Fehler/Timeouts → KEIN Redirect angenommen (echte Inhalte nicht wegen Netzflackern verlieren).
	 */
	private static function http_redirects( string $url ): bool {
		$ck  = 'm24_smap_rc_' . self::salt() . '_' . md5( $url );
		$hit = get_transient( $ck );
		if ( '3' === $hit ) { return true; }
		if ( '2' === $hit ) { return false; }
		$res  = wp_remote_head( $url, array( 'timeout' => 5, 'redirection' => 0, 'sslverify' => true ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$is_redir = ( $code >= 300 && $code < 400 );
		if ( 0 !== $code ) { set_transient( $ck, $is_redir ? '3' : '2', self::CACHE_TTL ); } // Fehler (0) nicht cachen
		return $is_redir;
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
		} ), true );
	}

	public static function build_blog(): string {
		return self::urlset( self::collect_posts( 'post', static function ( $id ) {
			return ! self::seo_noindex_post( (int) $id );
		} ), true );
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
		return self::urlset( $urls, true );
	}
}
