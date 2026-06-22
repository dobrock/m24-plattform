<?php
/**
 * M24 Fahrzeug — „Ähnliche Fahrzeuge"
 * Modul: includes/fahrzeug/class-m24fz-similar.php
 *
 * Logik (markenprimär HART, kategorieübergreifend):
 *  1. Pool strikt auf gleiche MARKE; Fremdmarken nur bis zur 10 %-Quote (≈1 von 6).
 *  2. Modell-/Baureihen-Affinität ÜBER Kategorien (E30 zieht ALLE E30 — CPT + Legacy, Race + Classic).
 *  3. 15 %-Quote verkauft/reserviert gleicher Marke/Modell (separat von der Fremdmarken-Quote).
 *  4. Dedup: reclaimte Alt-Posts (_m24fz_reclaim_post) + 301-weitergeleitete raus; pro Fahrzeug 1 Karte.
 *  5. Karten-Bild über m24_og/large (Template).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Similar {

	/** Alle Legacy-Fahrzeug-Kategorien (kategorieübergreifend: Race + Classic, aktiv + verkauft). */
	const LEGACY_CATS = array( 'race-cars-for-sale', 'racecars-sold', 'classic-cars-for-sale', 'sold-classic-cars' );
	const LEGACY_SOLD = array( 'racecars-sold', 'sold-classic-cars' );

	const FOREIGN_QUOTE = 0.10; // ≈1 von 6 Fremdmarke
	const SOLD_QUOTE    = 0.15; // ≈1 von 6 verkauft/reserviert (gleiche Marke)

	public static function cards( $post_id, $limit = 6 ) {
		$post_id = (int) $post_id;

		// Marke robust: Meta, sonst aus dem Titel raten.
		$marke = trim( (string) get_post_meta( $post_id, '_m24fz_marke', true ) );
		if ( '' === $marke && class_exists( 'M24FZ_Telemetry' ) ) { $marke = (string) M24FZ_Telemetry::guess_brand( get_the_title( $post_id ) ); }
		$marke_lo = strtolower( $marke );

		// Affinität robust: Baureihe → Modell → Modellschlüssel aus dem Titel (E30/E36/991/Z4 …).
		$aff = trim( (string) get_post_meta( $post_id, '_m24fz_baureihe', true ) );
		if ( '' === $aff ) { $aff = trim( (string) get_post_meta( $post_id, '_m24fz_modell', true ) ); }
		if ( '' === $aff ) { $aff = self::model_key( get_the_title( $post_id ) ); }
		$aff_lo = strtolower( $aff );

		$all = array_merge( self::cpt_pool( $post_id ), self::legacy_pool( $post_id ) );
		$all = self::dedupe( $all ); // pro Fahrzeug nur EINE Karte (CPT bevorzugt, da zuerst gemergt)
		if ( empty( $all ) ) { return array(); }

		// Eimer: EXAKTE Baureihe (verfügbar/verkauft) hat strikte Priorität vor sonstiger gleicher Marke.
		$exact_avail = array();
		$exact_sold  = array();
		$other_avail = array();
		$other_sold  = array();
		$foreign     = array();
		foreach ( $all as $c ) {
			$c['aff']  = ( '' !== $aff_lo && false !== strpos( $c['hay'], $aff_lo ) );
			$is_brand  = ( '' !== $marke_lo ) && ( 'cpt' === $c['source'] ? ( $c['marke'] === $marke_lo ) : self::title_has_brand( $c['title'], $marke ) );
			if ( '' === $marke_lo ) { $is_brand = true; } // ohne Marke: nicht filtern
			$sold = ( $c['sold'] || $c['reserved'] );
			if ( ! $is_brand )        { $foreign[] = $c; }
			elseif ( $c['aff'] && ! $sold ) { $exact_avail[] = $c; }
			elseif ( $c['aff'] && $sold )   { $exact_sold[]  = $c; }
			elseif ( ! $sold )        { $other_avail[] = $c; }
			else                      { $other_sold[]  = $c; }
		}
		$by_ts  = static function ( $a, $b ) { return $b['ts'] <=> $a['ts']; };
		$by_aff = static function ( $a, $b ) { if ( $a['aff'] !== $b['aff'] ) { return $a['aff'] ? -1 : 1; } return $b['ts'] <=> $a['ts']; };
		usort( $exact_avail, $by_ts );
		usort( $exact_sold, $by_ts );
		usort( $other_avail, $by_ts );
		usort( $other_sold, $by_ts );
		usort( $foreign, $by_aff );

		// Fremdmarke: aufrunden auf ≥1, sofern Kandidat existiert.
		$n_foreign = $foreign ? max( 1, (int) round( $limit * self::FOREIGN_QUOTE ) ) : 0;
		$n_brand   = max( 0, $limit - $n_foreign );

		// Gleiche Marke strikt: erst ALLE exakten Baureihe-Treffer (verfügbar, dann verkauft), dann Rest.
		$brand_ordered = array_merge( $exact_avail, $exact_sold, $other_avail, $other_sold );
		$brand_pick    = array_slice( $brand_ordered, 0, $n_brand );

		// 15 %-Mindestquote verkauft/reserviert sicherstellen (bevorzugt eine SOLD-E30).
		$n_sold_min = (int) round( $limit * self::SOLD_QUOTE );
		$brand_pick = self::ensure_sold( $brand_pick, $brand_ordered, $n_sold_min );

		// Anordnung: gleiche Marke (Baureihe zuerst), Fremdmarke in der Mitte einstreuen.
		$final = $brand_pick;
		foreach ( array_values( array_slice( $foreign, 0, $n_foreign ) ) as $i => $f ) {
			array_splice( $final, min( count( $final ), 4 + $i ), 0, array( $f ) );
		}

		// Auffüllen bis $limit (Reste), ohne Dubletten.
		$seen = array();
		foreach ( $final as $c ) { $seen[ $c['id'] ] = 1; }
		foreach ( array_merge( $brand_ordered, $foreign ) as $c ) {
			if ( count( $final ) >= $limit ) { break; }
			if ( isset( $seen[ $c['id'] ] ) ) { continue; }
			$seen[ $c['id'] ] = 1; $final[] = $c;
		}

		return array_slice( $final, 0, $limit );
	}

	/** Mindestens $min verkaufte/reservierte Karten in der Auswahl sicherstellen (tauscht letzte verfügbare). */
	private static function ensure_sold( $pick, $ordered, $min ) {
		$is_sold = static function ( $c ) { return ! empty( $c['sold'] ) || ! empty( $c['reserved'] ); };
		$have = 0;
		foreach ( $pick as $c ) { if ( $is_sold( $c ) ) { $have++; } }
		if ( $have >= $min ) { return $pick; }

		$pick_ids = array();
		foreach ( $pick as $c ) { $pick_ids[ $c['id'] ] = 1; }
		$cands = array(); // verkaufte Kandidaten außerhalb der Auswahl (Reihenfolge: exakte Baureihe zuerst)
		foreach ( $ordered as $c ) { if ( $is_sold( $c ) && ! isset( $pick_ids[ $c['id'] ] ) ) { $cands[] = $c; } }

		$need = $min - $have;
		foreach ( $cands as $cand ) {
			if ( $need <= 0 ) { break; }
			// von hinten die letzte NICHT-verkaufte Karte ersetzen
			for ( $i = count( $pick ) - 1; $i >= 0; $i-- ) {
				if ( ! $is_sold( $pick[ $i ] ) ) { $pick[ $i ] = $cand; $need--; break; }
			}
		}
		return $pick;
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
			'posts_per_page' => 100,
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
		$excl    = array_merge( array( (int) $exclude_id ), self::reclaimed_ids() ); // reclaimte Alt-Posts raus
		$ids = get_posts( array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 150,
			'fields'              => 'ids',
			'no_found_rows'       => true,
			'post__not_in'        => $excl,
			'orderby'             => 'date', 'order' => 'DESC',
			'ignore_sticky_posts' => true,
			'tax_query'           => array( array( 'taxonomy' => 'category', 'field' => 'slug', 'terms' => self::LEGACY_CATS ) ),
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( in_array( self::path_only( get_permalink( $id ) ), $blocked, true ) ) { continue; } // 301-weitergeleitet
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

	/* ── Helfer ──────────────────────────────────────────────────────────────── */

	/** Pro Fahrzeug nur EINE Karte: Dedup über normalisierten Titel (erste Sichtung = CPT bevorzugt). */
	private static function dedupe( $items ) {
		$out = array();
		$seen = array();
		foreach ( $items as $c ) {
			$key = preg_replace( '/[^a-z0-9]+/', '', strtolower( (string) $c['title'] ) );
			if ( '' === $key ) { $key = 'id' . $c['id']; }
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = 1; $out[] = $c;
		}
		return $out;
	}

	/** Alle Alt-Post-IDs, die per _m24fz_reclaim_post von einem CPT abgelöst wurden. */
	private static function reclaimed_ids() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_m24fz_reclaim_post' AND meta_value > 0" ); // phpcs:ignore WordPress.DB
		return array_filter( array_map( 'intval', (array) $ids ) );
	}

	/** Modellschlüssel aus dem Titel (BMW-Chassis E30/F82/G80, Z-Reihe, Porsche-Zahlen 911/964/991). */
	private static function model_key( $title ) {
		if ( preg_match( '/\b([EFGKUW]\d{2,3})\b/i', $title, $m ) ) { return $m[1]; }
		if ( preg_match( '/\b(Z\d)\b/i', $title, $m ) ) { return $m[1]; }
		if ( preg_match( '/\b(9\d{2})\b/', $title, $m ) ) { return $m[1]; }
		return '';
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
