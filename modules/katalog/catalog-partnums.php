<?php
/**
 * M24 Katalog — Teilenummern-Index (v3/C1).
 *
 * Baut pro m24_teil ein Meta `_m24_partnums` (space-separierte, normalisierte Ziffernfolgen) aus den ROHEN
 * Artikelfeldern: post_content (Beschreibung) + `_m24_hinweis` + `_m24_bmw_teilenummer`. NIEMALS aus der
 * gerenderten Seite (Related-Nummern wären false positives). 11-stellige Vollnummern werden zusätzlich mit
 * den letzten 7 Ziffern als Alias indexiert. Pflege via save_post; Erst-Backfill in Häppchen per Cron.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Catalog_Partnums {

	const META      = '_m24_partnums';
	const CRON      = 'm24_partnums_backfill';
	const CURSOR    = 'm24_partnums_cursor';
	const COUNT     = 'm24_partnums_indexed_count';
	const DONE      = 'm24_partnums_backfill_done';
	const BATCH     = 40;

	public static function init() {
		add_action( 'save_post_m24_teil', array( __CLASS__, 'on_save' ), 20, 1 );
		add_action( self::CRON, array( __CLASS__, 'run_backfill' ) );
	}

	/** Ziffernfolgen (≥7, ≤13) aus Rohtexten; 11-stellig zusätzlich als letzte-7-Alias. Dedupe. */
	public static function extract_from( string ...$texts ): array {
		$out = array();
		foreach ( $texts as $t ) {
			if ( '' === $t ) { continue; }
			// Zifferngruppen, die per einzelnem Trenner (Leerzeichen/Punkt/Bindestrich) verbunden sind
			// (deckt „11 12 1 316 993" ab); Komma/Zeilenumbruch trennt echte Nummern.
			if ( preg_match_all( '/\d+(?:[ .\-]\d+)*/', $t, $mm ) ) {
				foreach ( $mm[0] as $cand ) {
					$n = preg_replace( '/\D/', '', $cand );
					$len = strlen( $n );
					if ( $len < 7 || $len > 13 ) { continue; }
					$out[ $n ] = true;
					if ( 11 === $len ) { $out[ substr( $n, -7 ) ] = true; }
				}
			}
		}
		return array_keys( $out );
	}

	/** Ein Teil (neu) indexieren. @return int Anzahl indexierter Nummern. */
	public static function reindex( int $post_id ): int {
		if ( 'm24_teil' !== get_post_type( $post_id ) ) { return 0; }
		$p       = get_post( $post_id );
		$content = $p ? (string) $p->post_content : '';
		$hinweis = (string) get_post_meta( $post_id, '_m24_hinweis', true );
		$bmw     = (string) get_post_meta( $post_id, '_m24_bmw_teilenummer', true );
		$nums    = self::extract_from( $content, $hinweis, $bmw );
		if ( empty( $nums ) ) {
			delete_post_meta( $post_id, self::META );
			return 0;
		}
		update_post_meta( $post_id, self::META, implode( ' ', $nums ) );
		return count( $nums );
	}

	public static function on_save( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
		self::reindex( (int) $post_id );
	}

	/** Backfill (neu) starten — von der Migration aufgerufen. */
	public static function start_backfill() {
		update_option( self::CURSOR, 0, false );
		update_option( self::COUNT, 0, false );
		delete_option( self::DONE );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 10, self::CRON );
		}
	}

	/** Ein Häppchen abarbeiten (Cursor über ID); reschedult sich selbst bis fertig. OPcache-/Timeout-sicher. */
	public static function run_backfill() {
		global $wpdb;
		$cursor = (int) get_option( self::CURSOR, 0 );
		$ids    = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'm24_teil' AND ID > %d ORDER BY ID ASC LIMIT %d",
			$cursor,
			self::BATCH
		) ); // phpcs:ignore WordPress.DB

		if ( empty( $ids ) ) {
			update_option( self::DONE, gmdate( 'c' ), false );
			if ( class_exists( 'M24_Error_Log' ) ) {
				M24_Error_Log::capture( 'partnums_backfill', 'info', 'Teilenummern-Backfill fertig', array( 'artikel_mit_index' => (int) get_option( self::COUNT, 0 ) ) );
			}
			return;
		}

		$indexed = (int) get_option( self::COUNT, 0 );
		foreach ( $ids as $id ) {
			if ( self::reindex( (int) $id ) > 0 ) { $indexed++; }
			$cursor = (int) $id;
		}
		update_option( self::CURSOR, $cursor, false );
		update_option( self::COUNT, $indexed, false );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 20, self::CRON );
		}
	}
}
