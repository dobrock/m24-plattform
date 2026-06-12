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

	const TAX_MODELL = 'm24_fahrzeugkat';
	const PT_TEIL    = 'm24_teil';

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

	// ── Fahrzeuge = Modell-Terme ───────────────────────────────────────────

	private static function fahrzeuge( $q, $limit ) {
		if ( '' === $q ) { return self::empty_group( self::GROUP_FAHRZEUGE, $q ); }
		$args = array(
			'taxonomy'   => self::TAX_MODELL,
			'hide_empty' => true,   // nur Modelle mit Teilen
			'search'     => $q,     // Name LIKE %q%
		);
		$total = wp_count_terms( $args );
		$total = is_wp_error( $total ) ? 0 : (int) $total;

		$terms = get_terms( array_merge( $args, array( 'number' => max( 0, $limit ), 'orderby' => 'count', 'order' => 'DESC' ) ) );
		$items = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$items[] = array(
					'title' => $t->name,
					'url'   => add_query_arg( 'm24_modell', $t->slug, home_url( '/gebrauchtteile/' ) ),
					'meta'  => sprintf( _n( '%d Teil', '%d Teile', (int) $t->count, 'm24-plattform' ), (int) $t->count ),
				);
			}
		}
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

	// ── Verschiedenes = post + page ────────────────────────────────────────

	private static function verschiedenes( $q, $limit ) {
		if ( '' === $q ) { return self::empty_group( self::GROUP_VERSCHIEDENES, $q ); }
		$query = new WP_Query( array(
			'post_type'           => array( 'post', 'page' ),
			'post_status'         => 'publish',
			's'                   => $q,
			'posts_per_page'      => max( 0, $limit ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		) );
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
