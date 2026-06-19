<?php
/**
 * M24 Fahrzeug — „Ähnliche Fahrzeuge"
 * Modul: includes/fahrzeug/class-m24fz-similar.php
 *
 * Quelle: neuer CPT m24_fahrzeug + bestehende Alt-Fahrzeug-Beiträge (Root-URL-Posts in den
 * Kategorien race-cars-for-sale / classic-cars-for-sale bzw. racecars-sold / sold-classic-cars).
 * Logik: gleiche MARKE zuerst (aktiv vor verkauft/alt), max. 3. Kein Marken-Treffer →
 * Kategorie-Fallback (race/classic, beliebige Marke). Deaktivierte CPT-Inserate NIE.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Similar {

	/** CPT-Kategorie → Alt-Beitrags-Kategorien (Slugs der WP-Standard-Taxonomie `category`). */
	const LEGACY = array(
		'race-cars'    => array( 'active' => 'race-cars-for-sale',    'sold' => 'racecars-sold' ),
		'classic-cars' => array( 'active' => 'classic-cars-for-sale', 'sold' => 'sold-classic-cars' ),
	);

	/**
	 * Karten-Deskriptoren (max $limit): ['id','url','title','thumb'(att-id|0),'sold'(bool),'baujahr','cc'].
	 * Mischt CPT-Inserate und Alt-Beiträge — das Template rendert quellen-agnostisch.
	 */
	public static function cards( $post_id, $limit = 3 ) {
		$post_id = (int) $post_id;
		$marke   = trim( (string) get_post_meta( $post_id, '_m24fz_marke', true ) );
		$kat     = (string) get_post_meta( $post_id, '_m24fz_kat', true );
		if ( ! isset( self::LEGACY[ $kat ] ) ) { $kat = 'race-cars'; }

		$cards = array();
		$seen  = array( $post_id );
		$add   = function ( $items ) use ( &$cards, &$seen, $limit ) {
			foreach ( $items as $c ) {
				if ( count( $cards ) >= $limit ) { return; }
				if ( in_array( $c['id'], $seen, true ) ) { continue; }
				$seen[] = $c['id']; $cards[] = $c;
			}
		};

		// 1) Gleiche Marke: aktiv (CPT + Alt) → mit verkauft/alt auffüllen.
		if ( '' !== $marke ) {
			$add( self::cpt_cards( array( 'gelistet' ), $marke, $seen, $limit ) );
			$add( self::legacy_cards( $kat, 'active', $marke, $seen, $limit ) );
			if ( count( $cards ) < $limit ) {
				$add( self::cpt_cards( array( 'verkauft', 'reserviert' ), $marke, $seen, $limit ) );
				$add( self::legacy_cards( $kat, 'sold', $marke, $seen, $limit ) );
			}
		}

		// 2) Fallback (nur wenn Marke GAR NICHTS liefert): Kategorie, beliebige Marke.
		if ( empty( $cards ) ) {
			$add( self::cpt_cards( array( 'gelistet' ), '', $seen, $limit ) );
			$add( self::legacy_cards( $kat, 'active', '', $seen, $limit ) );
			$add( self::cpt_cards( array( 'verkauft', 'reserviert' ), '', $seen, $limit ) );
			$add( self::legacy_cards( $kat, 'sold', '', $seen, $limit ) );
		}

		return array_slice( $cards, 0, $limit );
	}

	/** Rückwärtskompatibel: nur die Post-IDs. */
	public static function ids( $post_id, $limit = 3 ) {
		return array_map( static function ( $c ) { return $c['id']; }, self::cards( $post_id, $limit ) );
	}

	/* ── CPT-Quelle ──────────────────────────────────────────────────────────── */

	private static function cpt_cards( $statuses, $marke, $exclude, $limit ) {
		$meta = array( array( 'key' => '_m24fz_status', 'value' => $statuses, 'compare' => 'IN' ) );
		if ( '' !== $marke ) { $meta[] = array( 'key' => '_m24fz_marke', 'value' => $marke ); }
		$ids = get_posts( array(
			'post_type'      => M24FZ_CPT::PT,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, $limit ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => $exclude,
			'orderby'        => 'date', 'order' => 'DESC',
			'meta_query'     => $meta,
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$out[] = array(
				'id'      => $id,
				'url'     => get_permalink( $id ),
				'title'   => get_the_title( $id ),
				'thumb'   => has_post_thumbnail( $id ) ? (int) get_post_thumbnail_id( $id ) : 0,
				'sold'    => M24FZ_CPT::is_sold( $id ),
				'baujahr' => (string) get_post_meta( $id, '_m24fz_baujahr', true ),
				'cc'      => (string) get_post_meta( $id, '_m24fz_standort', true ),
			);
		}
		return $out;
	}

	/* ── Alt-Beitrags-Quelle ─────────────────────────────────────────────────── */

	private static function legacy_cards( $kat, $which, $marke, $exclude, $limit ) {
		$slug = self::LEGACY[ $kat ][ $which ] ?? '';
		if ( '' === $slug ) { return array(); }
		$ids = get_posts( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, $limit ) * 4,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'post__not_in'        => $exclude,
			'orderby'             => 'date', 'order' => 'DESC',
			'category_name'       => $slug,
			'ignore_sticky_posts' => true,
		) );
		$out = array();
		foreach ( $ids as $id ) {
			if ( count( $out ) >= $limit ) { break; }
			$id    = (int) $id;
			$title = get_the_title( $id );
			if ( '' !== $marke && ! self::title_has_brand( $title, $marke ) ) { continue; }
			$clean = preg_replace( '/^\s*(coming up for sale|for sale|sold|verkauft|reserviert)\s*:\s*/i', '', $title );
			$year  = preg_match( '/\b(19|20)\d{2}\b/', $title, $m ) ? $m[0] : '';
			$out[] = array(
				'id'      => $id,
				'url'     => get_permalink( $id ),
				'title'   => trim( (string) $clean ),
				'thumb'   => has_post_thumbnail( $id ) ? (int) get_post_thumbnail_id( $id ) : 0,
				'sold'    => ( 'sold' === $which ),
				'baujahr' => $year,
				'cc'      => '',
			);
		}
		return $out;
	}

	/** Grobe Marken-Erkennung im Alt-Titel (inkl. einfacher Aliase). */
	private static function title_has_brand( $title, $marke ) {
		$aliases = array( $marke );
		$low     = strtolower( $marke );
		if ( 'mercedes' === $low || 'mercedes-benz' === $low ) { $aliases[] = 'daimler'; $aliases[] = 'benz'; }
		if ( 'vw' === $low )         { $aliases[] = 'volkswagen'; }
		if ( 'volkswagen' === $low ) { $aliases[] = 'vw'; }
		foreach ( $aliases as $a ) {
			if ( '' !== $a && false !== stripos( $title, $a ) ) { return true; }
		}
		return false;
	}
}
