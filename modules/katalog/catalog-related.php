<?php
/**
 * M24 Plattform — Katalog: „Weitere Teile"-Auswahl (Pins + Auto)
 * Modul: modules/katalog/catalog-related.php
 *
 * Liefert die Teile-Empfehlungen fuer den „Weitere [Modell]-Teile"-Block der
 * Detailseite. Strategie:
 *   1. Manuelle Pins zuerst (in gespeicherter Reihenfolge).
 *   2. Auto-Auffuellung bis Limit: gleiches Modell (m24_fahrzeugkat) zuerst,
 *      dann gleiche Baugruppe (m24_baugruppe).
 *   3. STABILE, deterministische Sortierung (Artikelnummer, dann ID) → bei jedem
 *      Crawl dieselben Nachbarn (SEO-stabil, kein random).
 *   4. Nur verfuegbare Teile (status=aktiv, publish); self + Duplikate raus.
 *      (Verkaufte laufen ueber den separaten „Aehnliche Teile (Verkauft)"-Block.)
 * Toggle `_m24_related_manual_only` = 1 → KEINE Auto-Auffuellung, nur Pins.
 *
 * Zusaetzlich: Admin-REST `/m24/v1/teile-suche?q=` fuer das Autocomplete der
 * Meta-Box (Titel + Artikelnummer + BMW-Nummer). Nur fuer eingeloggte Redakteure.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Related {

	const META_PINS        = '_m24_related_pins';
	const META_MANUAL_ONLY = '_m24_related_manual_only';
	const NS               = 'm24/v1';
	const POOL             = 100; // Auto-Kandidaten-Pool pro Tier vor Stable-Sort.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	// ── Auswahl-Kern ────────────────────────────────────────────────────────

	/**
	 * Geordnete Liste von Teil-IDs fuer den „Weitere Teile"-Block.
	 * @return int[]
	 */
	public static function get( $post_id, $limit = 5 ) {
		$post_id = (int) $post_id;
		$limit   = max( 0, (int) $limit );

		$result      = array_slice( self::valid_pins( $post_id ), 0, $limit );
		$manual_only = (bool) (int) get_post_meta( $post_id, self::META_MANUAL_ONLY, true );

		if ( ! $manual_only && count( $result ) < $limit ) {
			$exclude = array_merge( array( $post_id ), $result );
			$result  = array_merge( $result, self::auto_fill( $post_id, $limit - count( $result ), $exclude ) );
		}
		return array_slice( $result, 0, $limit );
	}

	/** Validierte, deduplizierte Pin-Liste (nur verfuegbare Teile, Reihenfolge erhalten). */
	public static function valid_pins( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_PINS, true );
		$ids = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $ids ) ) { return array(); }

		$out  = array();
		$seen = array();
		foreach ( $ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 || $pid === (int) $post_id || isset( $seen[ $pid ] ) ) { continue; }
			if ( ! self::is_available( $pid ) ) { continue; }
			$seen[ $pid ] = true;
			$out[]        = $pid;
		}
		return $out;
	}

	/** Auto-Auffuellung: Modell zuerst, dann Baugruppe. Stable-sorted. */
	private static function auto_fill( $post_id, $need, $exclude ) {
		$out = array();
		foreach ( array( M24_Catalog_CPT::TAXONOMY, M24_Catalog_CPT::TAXONOMY_BAUGRUPPE ) as $tax ) {
			if ( count( $out ) >= $need ) { break; }
			$terms = get_the_terms( $post_id, $tax );
			if ( ! $terms || is_wp_error( $terms ) ) { continue; }

			$ids = get_posts( array(
				'post_type'      => M24_Catalog_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::POOL,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post__not_in'   => array_values( array_unique( array_merge( $exclude, $out ) ) ),
				'tax_query'      => array( array( 'taxonomy' => $tax, 'terms' => wp_list_pluck( $terms, 'term_id' ) ) ),
				'meta_query'     => array( array( 'key' => '_m24_status', 'value' => 'aktiv' ) ),
			) );
			foreach ( self::stable_sort( $ids ) as $pid ) {
				$out[] = (int) $pid;
				if ( count( $out ) >= $need ) { break; }
			}
		}
		return $out;
	}

	/** Deterministische, crawl-stabile Reihenfolge: Artikelnummer (natuerlich), dann ID. */
	private static function stable_sort( $ids ) {
		$rows = array();
		foreach ( $ids as $pid ) {
			$rows[] = array( 'id' => (int) $pid, 'art' => (string) get_post_meta( $pid, '_m24_artikelnummer', true ) );
		}
		usort( $rows, function ( $a, $b ) {
			$c = strnatcasecmp( $a['art'], $b['art'] );
			return 0 !== $c ? $c : ( $a['id'] <=> $b['id'] );
		} );
		return array_map( function ( $r ) { return $r['id']; }, $rows );
	}

	/** Verfuegbar = existiert, m24_teil, publish, status=aktiv. */
	public static function is_available( $pid ) {
		$p = get_post( $pid );
		if ( ! $p || M24_Catalog_CPT::POST_TYPE !== $p->post_type || 'publish' !== $p->post_status ) {
			return false;
		}
		return 'aktiv' === get_post_meta( $pid, '_m24_status', true );
	}

	// ── Admin-REST: Autocomplete ─────────────────────────────────────────────

	public static function register_rest() {
		register_rest_route( self::NS, '/teile-suche', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_search' ),
			'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
			'args'                => array(
				'q'       => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'exclude' => array( 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	public static function rest_search( WP_REST_Request $req ) {
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( mb_strlen( $q ) < 2 ) { return rest_ensure_response( array() ); }

		$exclude = array_filter( array_map( 'intval', explode( ',', (string) $req->get_param( 'exclude' ) ) ) );
		$base    = array(
			'post_type'      => M24_Catalog_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => $exclude,
		);
		$by_title = get_posts( array_merge( $base, array( 's' => $q ) ) );
		$by_meta  = get_posts( array_merge( $base, array( 'meta_query' => array(
			'relation' => 'OR',
			array( 'key' => '_m24_artikelnummer',   'value' => $q, 'compare' => 'LIKE' ),
			array( 'key' => '_m24_bmw_teilenummer', 'value' => $q, 'compare' => 'LIKE' ),
		) ) ) );

		$ids = array_slice( array_values( array_unique( array_merge( $by_title, $by_meta ) ) ), 0, 12 );
		$out = array();
		foreach ( $ids as $pid ) {
			$out[] = self::item( (int) $pid );
		}
		return rest_ensure_response( $out );
	}

	/** Kompaktes Anzeige-Item fuer Autocomplete + gespeicherte Pins. */
	public static function item( $pid ) {
		$tid   = get_post_thumbnail_id( $pid );
		$thumb = $tid ? (string) wp_get_attachment_image_url( $tid, 'thumbnail' ) : '';
		return array(
			'id'    => (int) $pid,
			'title' => html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ),
			'artnr' => (string) get_post_meta( $pid, '_m24_artikelnummer', true ),
			'thumb' => $thumb,
		);
	}
}
