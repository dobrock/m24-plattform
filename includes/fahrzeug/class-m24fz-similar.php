<?php
/**
 * M24 Fahrzeug — „Ähnliche Fahrzeuge"
 * Modul: includes/fahrzeug/class-m24fz-similar.php
 *
 * Logik (markenprimär, kategorieübergreifend):
 *  1. Primär nach MARKE matchen (Porsche-Seite → nur Porsche, BMW-Seite → nur BMW).
 *  2. Modell-/Baureihen-Affinität ÜBER Kategorien hinweg (ein M3 E30 zieht ALLE E30 aus Race Cars
 *     UND Classic Cars) — bei Modell-Match wird die Kategorie NICHT eingeschränkt.
 *  3. ~10 % Fremdmarken-Quote zur Auflockerung (Konstante FOREIGN_QUOTE).
 *  4. Quellen: neue m24_fahrzeug + alte Legacy-Posts; reclaimte/301-weitergeleitete ausgeschlossen.
 *  5. Karten-Bild über m24_og/large (Template).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Similar {

	/** Alle Legacy-Fahrzeug-Kategorien (kategorieübergreifend: Race + Classic, aktiv + verkauft). */
	const LEGACY_CATS = array( 'race-cars-for-sale', 'racecars-sold', 'classic-cars-for-sale', 'sold-classic-cars' );
	const LEGACY_SOLD = array( 'racecars-sold', 'sold-classic-cars' );

	/** Anteil Fremdmarken-Kacheln zur Auflockerung (≈1 von 6). */
	const FOREIGN_QUOTE = 0.10;

	/** Karten-Deskriptoren (bis $limit): ['id','url','title','thumb','sold','reserved','baujahr','cc']. */
	public static function cards( $post_id, $limit = 6 ) {
		$post_id  = (int) $post_id;
		$marke    = trim( (string) get_post_meta( $post_id, '_m24fz_marke', true ) );
		$aff      = trim( (string) get_post_meta( $post_id, '_m24fz_baureihe', true ) );
		if ( '' === $aff ) { $aff = trim( (string) get_post_meta( $post_id, '_m24fz_modell', true ) ); }
		$marke_lo = strtolower( $marke );
		$aff_lo   = strtolower( $aff );

		$all = array_merge( self::cpt_pool( $post_id ), self::legacy_pool( $post_id ) );
		if ( empty( $all ) ) { return array(); }

		// Marken-Split + Affinität markieren.
		$brand = array();
		$foreign = array();
		foreach ( $all as $c ) {
			$c['aff'] = ( '' !== $aff_lo && false !== strpos( $c['hay'], $aff_lo ) );
			$is_brand = ( '' !== $marke_lo ) && ( 'cpt' === $c['source'] ? ( $c['marke'] === $marke_lo ) : self::title_has_brand( $c['title'], $marke ) );
			if ( $is_brand ) { $brand[] = $c; } else { $foreign[] = $c; }
		}
		// Ohne gesetzte Marke: keine Fremdmarken-Trennung (alles als „Marke").
		if ( '' === $marke_lo ) { $brand = $all; $foreign = array(); }

		$cmp = static function ( $a, $b ) {
			if ( $a['aff'] !== $b['aff'] ) { return $a['aff'] ? -1 : 1; }
			return $b['ts'] <=> $a['ts'];
		};
		usort( $brand, $cmp );
		usort( $foreign, static function ( $a, $b ) { return $b['ts'] <=> $a['ts']; } );

		// ~10 % Fremdmarke einstreuen, Rest gleiche Marke (Modell-Affinität zuerst).
		$n_foreign     = (int) round( $limit * self::FOREIGN_QUOTE );
		$foreign_pick  = array_slice( $foreign, 0, $n_foreign );
		$brand_pick    = array_slice( $brand, 0, max( 0, $limit - count( $foreign_pick ) ) );
		if ( count( $brand_pick ) + count( $foreign_pick ) < $limit ) {
			$need         = $limit - count( $brand_pick ) - count( $foreign_pick );
			$foreign_pick = array_merge( $foreign_pick, array_slice( $foreign, count( $foreign_pick ), max( 0, $need ) ) );
		}

		$final = $brand_pick;
		$at    = min( count( $final ), 3 );
		foreach ( array_values( $foreign_pick ) as $i => $f ) { array_splice( $final, $at + $i, 0, array( $f ) ); }

		return array_slice( $final, 0, $limit );
	}

	/** Rückwärtskompatibel: nur die Post-IDs. */
	public static function ids( $post_id, $limit = 6 ) {
		return array_map( static function ( $c ) { return $c['id']; }, self::cards( $post_id, $limit ) );
	}

	/* ── CPT-Quelle (kategorieübergreifend) ──────────────────────────────────── */

	private static function cpt_pool( $exclude_id ) {
		$ids = get_posts( array(
			'post_type'      => M24FZ_CPT::PT,
			'post_status'    => 'publish',
			'posts_per_page' => 80,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => array( (int) $exclude_id ),
			'orderby'        => 'date', 'order' => 'DESC',
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( M24FZ_CPT::is_disabled( $id ) ) { continue; }
			$st    = M24FZ_CPT::status( $id );
			$marke = strtolower( trim( (string) get_post_meta( $id, '_m24fz_marke', true ) ) );
			$bau   = strtolower( trim( (string) get_post_meta( $id, '_m24fz_baureihe', true ) ) );
			$mod   = strtolower( trim( (string) get_post_meta( $id, '_m24fz_modell', true ) ) );
			$out[] = array(
				'source'   => 'cpt',
				'id'       => $id,
				'url'      => get_permalink( $id ),
				'title'    => get_the_title( $id ),
				'thumb'    => has_post_thumbnail( $id ) ? (int) get_post_thumbnail_id( $id ) : 0,
				'sold'     => ( 'verkauft' === $st ),
				'reserved' => ( 'reserviert' === $st ),
				'baujahr'  => (string) get_post_meta( $id, '_m24fz_baujahr', true ),
				'cc'       => (string) get_post_meta( $id, '_m24fz_standort', true ),
				'marke'    => $marke,
				'hay'      => $bau . ' ' . $mod . ' ' . strtolower( get_the_title( $id ) ),
				'ts'       => (int) get_post_time( 'U', true, $id ),
			);
		}
		return $out;
	}

	/* ── Alt-Beitrags-Quelle (alle Legacy-Kategorien) ────────────────────────── */

	private static function legacy_pool( $exclude_id ) {
		$blocked = self::redirected_paths();
		$ids = get_posts( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 120,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'post__not_in'        => array( (int) $exclude_id ),
			'orderby'             => 'date', 'order' => 'DESC',
			'ignore_sticky_posts' => true,
			'tax_query'           => array( array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => self::LEGACY_CATS ) ),
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( in_array( self::path_only( get_permalink( $id ) ), $blocked, true ) ) { continue; } // reclaimt/301
			$title = get_the_title( $id );
			$clean = preg_replace( '/^\s*(coming up for sale|for sale|sold|verkauft|reserviert)\s*:\s*/i', '', $title );
			$year  = preg_match( '/\b(19|20)\d{2}\b/', $title, $m ) ? $m[0] : '';
			$out[] = array(
				'source'   => 'legacy',
				'id'       => $id,
				'url'      => get_permalink( $id ),
				'title'    => trim( (string) $clean ),
				'thumb'    => has_post_thumbnail( $id ) ? (int) get_post_thumbnail_id( $id ) : 0,
				'sold'     => (bool) has_term( self::LEGACY_SOLD, 'category', $id ),
				'reserved' => false,
				'baujahr'  => $year,
				'cc'       => '',
				'marke'    => '',
				'hay'      => strtolower( $title ),
				'ts'       => (int) get_post_time( 'U', true, $id ),
			);
		}
		return $out;
	}

	/** Grobe Marken-Erkennung im (Alt-)Titel inkl. einfacher Aliase. */
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

	/** Bereits umgeleitete Alt-Pfade (statische Legacy-Map + per Filter gemergte Reclaim-Map). */
	private static function redirected_paths() {
		if ( ! class_exists( 'M24_Catalog_Hub' ) ) { return array(); }
		$paths = array();
		foreach ( array_keys( (array) M24_Catalog_Hub::legacy_paths() ) as $p ) { $paths[] = self::norm( $p ); }
		return $paths;
	}

	private static function path_only( $url ) { return self::norm( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) ); }
	private static function norm( $p ) { $p = (string) $p; return ( '' === $p || '/' === $p ) ? $p : untrailingslashit( $p ); }
}
