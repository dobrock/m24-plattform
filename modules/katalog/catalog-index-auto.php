<?php
/**
 * M24 Katalog — Index-Automatik (0.11.563).
 *
 * Ziel: alles, was auf den Übersichtsseiten steht, darf Google holen — und was neu dazukommt,
 * landet ohne Handgriff in Robots + Sitemap.
 *
 *  1) Automatik-Schalter (Option m24_index_auto, Default AN):
 *       - ALLE veröffentlichten Hubs index,follow + in /sitemap-m24-hubs.xml
 *         (überstimmt die Hand-Allowlist m24_indexable_hubs; Automatik AUS ⇒ Allowlist gilt wieder);
 *       - ALLE veröffentlichten Teile index,follow + in den Produkt-/Gebrauchtteile-Sitemaps.
 *  2) Modell-Übersichten /rennsport-teile/?m24_modell={term} (und /gebrauchtteile/?m24_modell={term},
 *     wenn der Term zu keinem Hub gehört) werden echte Landingpages:
 *       - eigener Title/H1/Description „Rennsport-Teile passend für BMW M2 F87";
 *       - self-canonical (vorher zeigte der Canonical fälschlich auf das ERSTE Teil der Liste);
 *       - index,follow nur „sauber" (kein q/sort/Seite>1) und mit ≥1 aktivem Teil;
 *       - eigene Sitemap /sitemap-m24-modelle.xml (DE + /en/), automatisch je Term mit Teilen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Catalog_Index_Auto {

	const OPTION = 'm24_index_auto';
	const TAX    = 'm24_fahrzeugkat';

	public static function init() {
		add_filter( 'm24_indexable_hub_slugs', array( __CLASS__, 'all_hubs' ), 20 );
		add_filter( 'm24_teil_indexable', array( __CLASS__, 'teil_indexable' ), 10, 2 );

		// Modell-Landingpages auf dem Archiv (nach Hub-archive_canonical @99).
		add_filter( 'wpseo_set_robots',    array( __CLASS__, 'robots' ), 100, 1 );
		add_filter( 'wpseo_set_canonical', array( __CLASS__, 'canonical' ), 100, 1 );
		add_filter( 'wpseo_set_title',     array( __CLASS__, 'title' ), 100, 1 );
		add_filter( 'wpseo_set_desc',      array( __CLASS__, 'desc' ), 100, 1 );
		add_filter( 'document_title_parts', array( __CLASS__, 'doc_title' ), 100 );
		add_filter( 'wp_robots',           array( __CLASS__, 'wp_robots' ), 100 );
		add_filter( 'get_canonical_url',   array( __CLASS__, 'canonical' ), 100 );
	}

	public static function enabled() {
		if ( defined( 'M24_INDEX_AUTO' ) ) { return (bool) M24_INDEX_AUTO; }
		return '0' !== (string) get_option( self::OPTION, '1' );
	}

	/** Automatik AN → jede veröffentlichte Hub-Seite ist freigegeben (neue Hubs automatisch). */
	public static function all_hubs( $allow ) {
		if ( ! self::enabled() || ! class_exists( 'M24_Catalog_Hub' ) ) { return $allow; }
		$keys = array_keys( (array) M24_Catalog_Hub::registry() );
		return $keys ? array_values( array_unique( array_merge( (array) $allow, $keys ) ) ) : $allow;
	}

	/** Automatik AN → jedes veröffentlichte Teil indexierbar. */
	public static function teil_indexable( $yes, $post_id ) {
		if ( $yes || ! self::enabled() ) { return $yes; }
		return 'publish' === get_post_status( (int) $post_id );
	}

	/* ── Modell-Landingpages ─────────────────────────────────────────────── */

	/** Aktueller Modell-Term, wenn wir auf einer Modell-Übersicht des Archivs stehen (sonst null). */
	public static function term() {
		if ( ! class_exists( 'M24_Catalog_Archive' ) || ! M24_Catalog_Archive::is_archive() ) { return null; }
		$slug = M24_Catalog_Archive::current_modell();
		if ( '' === $slug ) { return null; }
		// Gebrauchtteile mit Hub-Term → bleibt beim Hub (Canonical dorthin, s. M24_Catalog_Hub::archive_canonical).
		if ( 'gebraucht' === M24_Catalog_Archive::current_typ() && class_exists( 'M24_Catalog_Hub' ) && '' !== M24_Catalog_Hub::hub_for_term_slug( $slug ) ) {
			return null;
		}
		$t = get_term_by( 'slug', $slug, self::TAX );
		return ( $t && ! is_wp_error( $t ) ) ? $t : null;
	}

	/** „Sauber" = nur ?m24_modell, keine Suche/Sortierung/„alle"/Seite>1. */
	private static function clean() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['q'] ) && '' !== trim( (string) wp_unslash( $_GET['q'] ) ) ) { return false; }
		if ( isset( $_GET['sort'] ) || isset( $_GET['kat'] ) ) { return false; }
		// phpcs:enable
		return max( 1, (int) get_query_var( 'paged' ) ) <= 1;
	}

	private static function label( $t ) {
		$n = trim( (string) $t->name );
		return ( 0 === stripos( $n, 'bmw' ) || 0 === stripos( $n, 'porsche' ) ) ? $n : 'BMW ' . $n;
	}

	private static function typ_label() {
		return ( 'neu' === M24_Catalog_Archive::current_typ() ) ? 'Rennsport-Teile' : 'Gebrauchte Teile';
	}

	public static function h1() {
		$t = self::term();
		return $t ? self::typ_label() . ' passend für ' . self::label( $t ) : '';
	}

	/** Anzahl aktiver, veröffentlichter Teile eines Terms je Typ (neu|gebraucht). */
	public static function count( $term_id, $typ ) {
		$key = 'm24_ia_cnt_' . (int) $term_id . '_' . $typ;
		$hit = wp_cache_get( $key, 'm24' );
		if ( false !== $hit ) { return (int) $hit; }
		$typ_q = ( 'neu' === $typ )
			? array( 'key' => '_m24_typ', 'value' => 'neu' )
			: array( 'relation' => 'OR', array( 'key' => '_m24_typ', 'compare' => 'NOT EXISTS' ), array( 'key' => '_m24_typ', 'value' => 'neu', 'compare' => '!=' ) );
		$q = new WP_Query( array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => array( array( 'taxonomy' => self::TAX, 'terms' => (int) $term_id, 'include_children' => true ) ),
			'meta_query'     => array( 'relation' => 'AND', $typ_q, array( 'key' => '_m24_status', 'value' => 'aktiv' ) ),
		) );
		$n = (int) $q->found_posts;
		wp_cache_set( $key, $n, 'm24', 300 );
		return $n;
	}

	private static function indexable_now() {
		$t = self::term();
		return $t && self::clean() && self::count( $t->term_id, M24_Catalog_Archive::current_typ() ) > 0;
	}

	public static function url_for( $slug, $typ ) {
		$base = ( 'neu' === $typ ) ? 'rennsport-teile' : 'gebrauchtteile';
		return add_query_arg( 'm24_modell', rawurlencode( $slug ), home_url( '/' . $base . '/' ) );
	}

	public static function robots( $robots ) {
		if ( ! self::term() ) { return $robots; }
		return self::indexable_now() ? 'index, follow' : 'noindex, follow';
	}

	public static function wp_robots( $robots ) {
		if ( ! self::term() ) { return $robots; }
		if ( self::indexable_now() ) {
			unset( $robots['noindex'] );
			$robots['index']  = true;
			$robots['follow'] = true;
		}
		return $robots;
	}

	public static function canonical( $url ) {
		$t = self::term();
		return $t ? self::url_for( $t->slug, M24_Catalog_Archive::current_typ() ) : $url;
	}

	public static function title( $title ) {
		$h = self::h1();
		if ( '' === $h ) { return $title; }
		foreach ( array( $h . ' | MOTORSPORT24 seit 2006', $h . ' | MOTORSPORT24', $h ) as $v ) {
			if ( mb_strlen( $v ) <= 65 ) { return $v; }
		}
		return $h;
	}

	public static function doc_title( $parts ) {
		$h = self::h1();
		if ( '' !== $h ) { $parts['title'] = $h; unset( $parts['tagline'] ); }
		return $parts;
	}

	public static function desc( $desc ) {
		$t = self::term();
		if ( ! $t ) { return $desc; }
		$m = self::label( $t );
		return ( 'neu' === M24_Catalog_Archive::current_typ() )
			? 'Rennsport-Teile passend für ' . $m . ' ✓ Rennsportqualität ✓ eindeutig per Teilenummer ✓ weltweiter Versand — MOTORSPORT24 seit 2006'
			: 'Gebrauchte Teile passend für ' . $m . ' ✓ geprüft ✓ sofort lieferbar ✓ weltweiter Versand — MOTORSPORT24 seit 2006';
	}

	/* ── Sitemap /sitemap-m24-modelle.xml ────────────────────────────────── */

	/** [ ['loc'=>…, 'lastmod'=>…], … ] — je Term mit aktiven Teilen (neu immer; gebraucht nur ohne Hub). */
	public static function sitemap_urls() {
		$terms = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => true ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) { return array(); }
		$urls = array();
		foreach ( $terms as $t ) {
			foreach ( array( 'neu', 'gebraucht' ) as $typ ) {
				if ( 'gebraucht' === $typ && class_exists( 'M24_Catalog_Hub' ) && '' !== M24_Catalog_Hub::hub_for_term_slug( $t->slug ) ) { continue; }
				if ( self::count( $t->term_id, $typ ) < 1 ) { continue; }
				$last = get_posts( array(
					'post_type' => 'm24_teil', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids',
					'orderby' => 'modified', 'order' => 'DESC',
					'tax_query' => array( array( 'taxonomy' => self::TAX, 'terms' => (int) $t->term_id ) ),
				) );
				$urls[] = array(
					'loc'     => self::url_for( $t->slug, $typ ),
					'lastmod' => $last ? (string) get_post_modified_time( 'c', true, (int) $last[0] ) : '',
				);
			}
		}
		return $urls;
	}
}

M24_Catalog_Index_Auto::init();
