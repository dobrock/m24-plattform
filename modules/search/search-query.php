<?php
/**
 * M24 Plattform — Gruppierte Suche: Query-Kern
 * Modul: modules/search/search-query.php
 *
 * Liefert Suchtreffer in drei Gruppen, jede begrenzt + mit Gesamtzahl + „Alle anzeigen"-URL:
 *   - Fahrzeuge      = Terme der Taxonomie m24_fahrzeugkat (Modelle, „Passend fuer …")
 *   - Teile          = CPT m24_teil (gebraucht UND neu/Rennsport zusammen)
 *   - Verschiedenes  = alles uebrige (post, page)
 *
 * Es gibt KEINE Fahrzeug-CPT im Projekt — „Fahrzeuge" sind die Modell-Terme, die bereits
 * die Katalog-Archiv-Filterung (?m24_modell=slug) speisen. Pro Gruppe genau EINE begrenzte
 * Query (kein N+1, kein Voll-Scan). Wiederverwendet von REST-Endpoint + Vollergebnis-Seite.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Search_Query {

	const GROUP_FAHRZEUGE     = 'fahrzeuge';
	const GROUP_TEILE         = 'teile';
	const GROUP_VERSCHIEDENES = 'verschiedenes';

	const PT_TEIL = 'm24_teil';

	/**
	 * „Fahrzeuge" = echte Beitraege (post) aus diesen vier Kategorien (Slugs).
	 * Diese Beitraege erscheinen NICHT in „Verschiedenes". Override via Filter
	 * `m24_search_fahrzeug_categories` (Slugs) / `m24_search_fahrzeug_sold_categories`.
	 */
	const FAHRZEUG_CATEGORIES      = array( 'classic-cars-for-sale', 'race-cars-for-sale', 'sold-classic-cars', 'racecars-sold' );
	const FAHRZEUG_SOLD_CATEGORIES = array( 'sold-classic-cars', 'racecars-sold' );

	/** Slugs → term_ids (gecacht pro Request). */
	private static function cat_ids( $slugs, $filter ) {
		static $cache = array();
		$slugs = (array) apply_filters( $filter, $slugs );
		$key   = md5( implode( ',', $slugs ) );
		if ( isset( $cache[ $key ] ) ) { return $cache[ $key ]; }
		$ids = array();
		foreach ( $slugs as $slug ) {
			$t = get_term_by( 'slug', $slug, 'category' );
			if ( $t && ! is_wp_error( $t ) ) { $ids[] = (int) $t->term_id; }
		}
		$cache[ $key ] = $ids;
		return $ids;
	}

	public static function fahrzeug_cat_ids()      { return self::cat_ids( self::FAHRZEUG_CATEGORIES, 'm24_search_fahrzeug_categories' ); }
	public static function fahrzeug_sold_cat_ids() { return self::cat_ids( self::FAHRZEUG_SOLD_CATEGORIES, 'm24_search_fahrzeug_sold_categories' ); }

	/** Default-Limits (Dropdown). Vollergebnis-Seite ruft mit hohem Limit. */
	public static function default_limits() {
		return array(
			self::GROUP_FAHRZEUGE     => 5,
			self::GROUP_TEILE         => 10,
			self::GROUP_VERSCHIEDENES => 5,
		);
	}

	/**
	 * Alle drei Gruppen.
	 * @return array<string, array{items:array, total:int, all_url:string}>
	 */
	public static function search( $q, $limits = array() ) {
		$q      = trim( (string) $q );
		$limits = wp_parse_args( $limits, self::default_limits() );
		return array(
			self::GROUP_FAHRZEUGE     => self::fahrzeuge( $q, (int) $limits[ self::GROUP_FAHRZEUGE ] ),
			self::GROUP_TEILE         => self::teile( $q, (int) $limits[ self::GROUP_TEILE ] ),
			self::GROUP_VERSCHIEDENES => self::verschiedenes( $q, (int) $limits[ self::GROUP_VERSCHIEDENES ] ),
		);
	}

	/** Eine einzelne Gruppe (fuer die gefilterte Vollergebnis-Seite). */
	public static function group( $key, $q, $limit ) {
		switch ( $key ) {
			case self::GROUP_FAHRZEUGE:     return self::fahrzeuge( $q, $limit );
			case self::GROUP_TEILE:         return self::teile( $q, $limit );
			case self::GROUP_VERSCHIEDENES: return self::verschiedenes( $q, $limit );
		}
		return array( 'items' => array(), 'total' => 0, 'all_url' => '' );
	}

	public static function is_group( $key ) {
		return in_array( $key, array( self::GROUP_FAHRZEUGE, self::GROUP_TEILE, self::GROUP_VERSCHIEDENES ), true );
	}

	/** URL der Vollergebnis-Seite, auf eine Gruppe gefiltert. */
	public static function all_url( $group, $q ) {
		return add_query_arg(
			array( 's' => rawurlencode( $q ), 'm24_group' => $group ),
			home_url( '/' )
		);
	}

	// ── Fahrzeuge = echte Beitraege (post) aus den 4 Fahrzeug-Kategorien ────

	private static function fahrzeuge( $q, $limit ) {
		if ( '' === $q ) { return self::empty_group( self::GROUP_FAHRZEUGE, $q ); }
		$cat_ids = self::fahrzeug_cat_ids();
		if ( empty( $cat_ids ) ) { return self::empty_group( self::GROUP_FAHRZEUGE, $q ); }
		$sold_ids = self::fahrzeug_sold_cat_ids();

		$query = new WP_Query( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			's'                   => $q,
			'category__in'        => $cat_ids,
			'posts_per_page'      => max( 0, $limit ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		) );
		$items = array();
		foreach ( $query->posts as $p ) {
			$cats = wp_get_post_categories( $p->ID );
			$sold = ! empty( array_intersect( $cats, $sold_ids ) );
			$items[] = array(
				'title' => get_the_title( $p->ID ),
				'url'   => get_permalink( $p->ID ),
				'thumb' => self::thumb( $p->ID ),
				'sold'  => $sold,
			);
		}
		$total = (int) $query->found_posts;
		wp_reset_postdata();
		return array( 'items' => $items, 'total' => $total, 'all_url' => self::all_url( self::GROUP_FAHRZEUGE, $q ) );
	}

	// ── Teile = m24_teil (gebraucht + neu) ─────────────────────────────────

	private static function teile( $q, $limit ) {
		if ( '' === $q ) { return self::empty_group( self::GROUP_TEILE, $q ); }
		$query = new WP_Query( array(
			'post_type'           => self::PT_TEIL,
			'post_status'         => 'publish',
			's'                   => $q,
			'posts_per_page'      => max( 0, $limit ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		) );
		$items = array();
		foreach ( $query->posts as $p ) {
			$pid    = $p->ID;
			$sold   = ( 'verkauft' === get_post_meta( $pid, '_m24_status', true ) );
			$price  = '';
			if ( ! $sold && class_exists( 'M24_Catalog_Pricing' ) ) {
				$pr    = M24_Catalog_Pricing::get( $pid );
				$price = isset( $pr['brutto_fmt'] ) ? (string) $pr['brutto_fmt'] : '';
			}
			$items[] = array(
				'title' => get_the_title( $pid ),
				'url'   => get_permalink( $pid ),
				'thumb' => self::thumb( $pid ),
				'price' => $price,
				'sold'  => $sold,
			);
		}
		$total = (int) $query->found_posts;
		wp_reset_postdata();
		return array( 'items' => $items, 'total' => $total, 'all_url' => self::all_url( self::GROUP_TEILE, $q ) );
	}

	// ── Verschiedenes = post + page OHNE die Fahrzeug-Kategorien ────────────

	private static function verschiedenes( $q, $limit ) {
		if ( '' === $q ) { return self::empty_group( self::GROUP_VERSCHIEDENES, $q ); }
		$args = array(
			'post_type'           => array( 'post', 'page' ),
			'post_status'         => 'publish',
			's'                   => $q,
			'posts_per_page'      => max( 0, $limit ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);
		// Fahrzeug-Beitraege ausschliessen (duerfen nicht doppelt in „Verschiedenes" auftauchen).
		$cat_ids = self::fahrzeug_cat_ids();
		if ( ! empty( $cat_ids ) ) { $args['category__not_in'] = $cat_ids; }
		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $p ) {
			$items[] = array(
				'title' => get_the_title( $p->ID ),
				'url'   => get_permalink( $p->ID ),
				'thumb' => self::thumb( $p->ID ),
				'meta'  => ( 'page' === $p->post_type ) ? __( 'Seite', 'm24-plattform' ) : __( 'Beitrag', 'm24-plattform' ),
			);
		}
		$total = (int) $query->found_posts;
		wp_reset_postdata();
		return array( 'items' => $items, 'total' => $total, 'all_url' => self::all_url( self::GROUP_VERSCHIEDENES, $q ) );
	}

	// ── Helfer ─────────────────────────────────────────────────────────────

	private static function thumb( $post_id ) {
		$tid = get_post_thumbnail_id( $post_id );
		if ( ! $tid ) { return ''; }
		$url = wp_get_attachment_image_url( $tid, 'thumbnail' );
		return $url ? (string) $url : '';
	}

	private static function empty_group( $group, $q ) {
		return array( 'items' => array(), 'total' => 0, 'all_url' => self::all_url( $group, $q ) );
	}
}
