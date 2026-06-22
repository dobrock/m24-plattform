<?php
/**
 * M24 Fahrzeug — „Ähnliche Fahrzeuge"
 * Modul: includes/fahrzeug/class-m24fz-similar.php
 *
 * Pool = gleiche Aktiv-Kategorie (_m24fz_kat) wie das aktuelle Fahrzeug, aus BEIDEN Quellen:
 *  a) neue m24_fahrzeug-Beiträge,
 *  b) alte Fahrzeug-Posts (Legacy) über ihre WP-Kategorie (race* → Race Cars, classic* → Classic Cars).
 * Ausgeschlossen: aktuelles Fahrzeug, Entwürfe, deaktivierte CPT, sowie bereits per Reclaim
 * 301-weitergeleitete Alt-Posts (Dubletten/Redirect vermeiden).
 * Ranking: Modell-/Baureihen-Affinität zuerst, dann Rest der Kategorie nach Aktualität.
 * Anzeige: bis zu 6 Kacheln (2×3), davon ~15 % verkauft/reserviert (≈1 von 6) bewusst eingestreut.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Similar {

	/** CPT-Kategorie → Alt-Beitrags-Kategorien (Slugs der WP-Standard-Taxonomie `category`). */
	const LEGACY = array(
		'race-cars'    => array( 'active' => 'race-cars-for-sale',    'sold' => 'racecars-sold' ),
		'classic-cars' => array( 'active' => 'classic-cars-for-sale', 'sold' => 'sold-classic-cars' ),
	);

	/** Anteil verkaufter/reservierter Kacheln (≈1 von 6). */
	const SOLD_QUOTE = 0.15;

	/**
	 * Karten-Deskriptoren (bis $limit): ['id','url','title','thumb','sold','reserved','baujahr','cc'].
	 * Mischt CPT-Inserate und Alt-Beiträge — das Template rendert quellen-agnostisch.
	 */
	public static function cards( $post_id, $limit = 6 ) {
		$post_id = (int) $post_id;
		$kat     = (string) get_post_meta( $post_id, '_m24fz_kat', true );
		if ( ! isset( self::LEGACY[ $kat ] ) ) { $kat = 'race-cars'; }

		// Affinitäts-Schlüssel: Baureihe bevorzugt, sonst Modell, sonst Marke.
		$aff = trim( (string) get_post_meta( $post_id, '_m24fz_baureihe', true ) );
		if ( '' === $aff ) { $aff = trim( (string) get_post_meta( $post_id, '_m24fz_modell', true ) ); }
		if ( '' === $aff ) { $aff = trim( (string) get_post_meta( $post_id, '_m24fz_marke', true ) ); }

		$pool = array_merge(
			self::cpt_pool( $kat, $post_id, $aff ),
			self::legacy_pool( $kat, $post_id, $aff )
		);
		if ( empty( $pool ) ) { return array(); }

		// Affinität zuerst, dann Aktualität.
		$cmp = static function ( $a, $b ) {
			if ( $a['aff'] !== $b['aff'] ) { return $a['aff'] ? -1 : 1; }
			return $b['ts'] <=> $a['ts'];
		};
		$avail = array();
		$soldr = array();
		foreach ( $pool as $c ) {
			if ( $c['sold'] || $c['reserved'] ) { $soldr[] = $c; } else { $avail[] = $c; }
		}
		usort( $avail, $cmp );
		usort( $soldr, $cmp );

		// Quote: ~15 % verkauft/reserviert (≈1 von 6) — alte SOLD-Posts eignen sich dafür.
		$n_sold    = (int) round( $limit * self::SOLD_QUOTE );
		$sold_pick = array_slice( $soldr, 0, $n_sold );
		$avail_pick = array_slice( $avail, 0, max( 0, $limit - count( $sold_pick ) ) );
		// Zu wenige Verfügbare → mit weiteren verkauften/reservierten auffüllen.
		if ( count( $avail_pick ) + count( $sold_pick ) < $limit ) {
			$need      = $limit - count( $avail_pick ) - count( $sold_pick );
			$sold_pick = array_merge( $sold_pick, array_slice( $soldr, count( $sold_pick ), max( 0, $need ) ) );
		}

		// Verkauft-/Reserviert-Kachel(n) in der Mitte einstreuen statt anhängen.
		$final = $avail_pick;
		$at    = min( count( $final ), 3 );
		foreach ( array_values( $sold_pick ) as $i => $s ) { array_splice( $final, $at + $i, 0, array( $s ) ); }

		return array_slice( $final, 0, $limit );
	}

	/** Rückwärtskompatibel: nur die Post-IDs. */
	public static function ids( $post_id, $limit = 6 ) {
		return array_map( static function ( $c ) { return $c['id']; }, self::cards( $post_id, $limit ) );
	}

	/* ── CPT-Quelle ──────────────────────────────────────────────────────────── */

	private static function cpt_pool( $kat, $exclude_id, $aff ) {
		$ids = get_posts( array(
			'post_type'      => M24FZ_CPT::PT,
			'post_status'    => 'publish', // Entwürfe ausgeschlossen
			'posts_per_page' => 40,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'post__not_in'   => array( (int) $exclude_id ),
			'orderby'        => 'date', 'order' => 'DESC',
			'meta_query'     => array( array( 'key' => '_m24fz_kat', 'value' => $kat ) ),
		) );
		$out     = array();
		$aff_low = strtolower( $aff );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( M24FZ_CPT::is_disabled( $id ) ) { continue; }
			$st  = M24FZ_CPT::status( $id );
			$bau = strtolower( trim( (string) get_post_meta( $id, '_m24fz_baureihe', true ) ) );
			$mod = strtolower( trim( (string) get_post_meta( $id, '_m24fz_modell', true ) ) );
			$hay = $bau . ' ' . $mod . ' ' . strtolower( get_the_title( $id ) );
			$out[] = array(
				'id'       => $id,
				'url'      => get_permalink( $id ),
				'title'    => get_the_title( $id ),
				'thumb'    => has_post_thumbnail( $id ) ? (int) get_post_thumbnail_id( $id ) : 0,
				'sold'     => ( 'verkauft' === $st ),
				'reserved' => ( 'reserviert' === $st ),
				'baujahr'  => (string) get_post_meta( $id, '_m24fz_baujahr', true ),
				'cc'       => (string) get_post_meta( $id, '_m24fz_standort', true ),
				'aff'      => ( '' !== $aff_low && false !== strpos( $hay, $aff_low ) ),
				'ts'       => (int) get_post_time( 'U', true, $id ),
			);
		}
		return $out;
	}

	/* ── Alt-Beitrags-Quelle ─────────────────────────────────────────────────── */

	private static function legacy_pool( $kat, $exclude_id, $aff ) {
		$slugs     = array_values( self::LEGACY[ $kat ] );      // active + sold
		$sold_slug = self::LEGACY[ $kat ]['sold'];
		$blocked   = self::redirected_paths();
		$ids = get_posts( array(
			'post_type'           => 'post',
			'post_status'         => 'publish', // reclaimte (auf Entwurf gesetzte) Alt-Posts fallen automatisch weg
			'posts_per_page'      => 60,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'post__not_in'        => array( (int) $exclude_id ),
			'orderby'             => 'date', 'order' => 'DESC',
			'ignore_sticky_posts' => true,
			'tax_query'           => array( array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => $slugs ) ),
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			// Bereits per Reclaim 301-weitergeleitete Alt-Posts ausschließen.
			if ( in_array( self::path_only( get_permalink( $id ) ), $blocked, true ) ) { continue; }
			$title = get_the_title( $id );
			$clean = preg_replace( '/^\s*(coming up for sale|for sale|sold|verkauft|reserviert)\s*:\s*/i', '', $title );
			$year  = preg_match( '/\b(19|20)\d{2}\b/', $title, $m ) ? $m[0] : '';
			$out[] = array(
				'id'       => $id,
				'url'      => get_permalink( $id ),
				'title'    => trim( (string) $clean ),
				'thumb'    => has_post_thumbnail( $id ) ? (int) get_post_thumbnail_id( $id ) : 0,
				'sold'     => (bool) has_term( $sold_slug, 'category', $id ),
				'reserved' => false,
				'baujahr'  => $year,
				'cc'       => '',
				'aff'      => ( '' !== $aff && false !== stripos( $title, $aff ) ), // Best-Effort über Titel
				'ts'       => (int) get_post_time( 'U', true, $id ),
			);
		}
		return $out;
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
