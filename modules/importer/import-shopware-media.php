<?php
/**
 * M24 Plattform — Shopware-Import: Bild-Entkopplung & Media-Repair
 * Modul: modules/importer/import-shopware-media.php
 *
 * EINE Verantwortung: Bilder NACH der Produktanlage best-effort laden, ohne den
 * Import zu blockieren. Quell-URLs liegen pro Produkt in _m24_img_pending (JSON).
 *
 * Garantien:
 *   - download_url() mit kurzem Timeout (Default 15s) statt 300s → kein Haenger.
 *   - Fehler/Timeout → URL bleibt in _m24_img_pending, KEIN Throw, naechstes Bild.
 *   - Idempotent: Hash-Dedupe ueber _m24_sw_media_hash (kein Re-Download).
 *   - Resumierbar: --media-Pass kann beliebig oft laufen, arbeitet Pending ab.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Shopware_Media {

	const PENDING_META = '_m24_img_pending';
	const DEFAULT_TIMEOUT = 15;

	/** Aus dem Shopware-Produkt die Bild-URLs (sortiert, Cover-Flag, Hash) extrahieren. */
	public static function extract( $product ) {
		$media = isset( $product['media'] ) && is_array( $product['media'] ) ? $product['media'] : array();
		usort( $media, function ( $a, $b ) { return ( (int) ( $a['position'] ?? 0 ) ) <=> ( (int) ( $b['position'] ?? 0 ) ); } );
		$cover = (string) ( $product['coverId'] ?? '' );
		$out = array();
		foreach ( $media as $m ) {
			$mob = isset( $m['media'] ) && is_array( $m['media'] ) ? $m['media'] : array();
			$url = (string) ( $mob['url'] ?? '' );
			if ( '' === $url ) { continue; }
			$out[] = array(
				'url'   => $url,
				'hash'  => (string) ( $mob['metaData']['hash'] ?? '' ),
				'cover' => ( '' !== $cover && (string) ( $m['id'] ?? '' ) === $cover ),
			);
		}
		return $out;
	}

	/** Pending-URLs am Produkt ablegen (überschreibt; Produkt existiert bereits). */
	public static function store_pending( $post_id, array $images ) {
		if ( empty( $images ) ) { delete_post_meta( (int) $post_id, self::PENDING_META ); return; }
		update_post_meta( (int) $post_id, self::PENDING_META, wp_json_encode( array_values( $images ) ) );
	}

	/** Pending-Liste eines Produkts lesen. */
	public static function get_pending( $post_id ) {
		$raw = (string) get_post_meta( (int) $post_id, self::PENDING_META, true );
		$arr = '' !== $raw ? json_decode( $raw, true ) : array();
		return is_array( $arr ) ? $arr : array();
	}

	/**
	 * Bild-Versuch fuer EIN Produkt: jede Pending-URL laden (Timeout), Erfolg →
	 * Anhang + ggf. Featured/Galerie, URL aus Pending entfernen. Fehler → bleibt.
	 *
	 * @return array { done:int, remaining:int }  (wirft NIE)
	 */
	public static function attempt( $post_id, $timeout = self::DEFAULT_TIMEOUT ) {
		$post_id = (int) $post_id;
		$pending = self::get_pending( $post_id );
		if ( empty( $pending ) ) { return array( 'done' => 0, 'remaining' => 0 ); }

		$gallery     = array_values( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $post_id, '_m24_galerie', true ) ) ) ) );
		$has_featured = (int) get_post_thumbnail_id( $post_id );
		$remaining   = array();
		$done        = 0;

		foreach ( $pending as $img ) {
			$url  = (string) ( $img['url'] ?? '' );
			$hash = (string) ( $img['hash'] ?? '' );
			if ( '' === $url ) { continue; }
			try {
				$att = self::download_attach( $url, $post_id, $hash, $timeout );
			} catch ( Exception $e ) {
				$att = 0;
			}
			if ( $att > 0 ) {
				$done++;
				if ( ! empty( $img['cover'] ) && ! $has_featured ) {
					set_post_thumbnail( $post_id, $att );
					$has_featured = $att;
				} elseif ( ! in_array( $att, $gallery, true ) ) {
					$gallery[] = $att;
				}
			} else {
				$remaining[] = $img; // bleibt für nächsten Pass
			}
		}

		// Featured-Fallback: kein Cover, aber Galerie vorhanden → erstes Bild.
		if ( ! $has_featured && ! empty( $gallery ) ) {
			set_post_thumbnail( $post_id, (int) $gallery[0] );
		}
		update_post_meta( $post_id, '_m24_galerie', implode( ',', $gallery ) );
		self::store_pending( $post_id, $remaining );

		return array( 'done' => $done, 'remaining' => count( $remaining ) );
	}

	/**
	 * Repair-Pass: alle Rennsport-Produkte (_m24_typ=neu) mit offenem Pending erneut
	 * versuchen. Resumierbar/cron-tauglich. $cb($i,$total,$sw_post_id,$res) optional.
	 *
	 * @return array { products:int, done:int, still_pending:int }
	 */
	public static function repair_all( $timeout = self::DEFAULT_TIMEOUT, $cb = null ) {
		$ids = self::pending_post_ids();
		$total = count( $ids );
		$done = 0; $still = 0;
		foreach ( $ids as $i => $pid ) {
			$res = self::attempt( $pid, $timeout );
			$done += $res['done'];
			if ( $res['remaining'] > 0 ) { $still++; }
			if ( is_callable( $cb ) ) { call_user_func( $cb, $i + 1, $total, $pid, $res ); }
		}
		return array( 'products' => $total, 'done' => $done, 'still_pending' => $still );
	}

	/** Produkt-IDs (_m24_typ=neu) mit nicht-leerem _m24_img_pending. */
	public static function pending_post_ids() {
		$q = get_posts( array(
			'post_type'      => 'm24_teil', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => '_m24_typ', 'value' => 'neu' ),
				array( 'key' => self::PENDING_META, 'compare' => 'EXISTS' ),
				array( 'key' => self::PENDING_META, 'value' => '', 'compare' => '!=' ),
				array( 'key' => self::PENDING_META, 'value' => '[]', 'compare' => '!=' ),
			),
		) );
		return array_map( 'intval', $q );
	}

	/** Statistik fuer --status: total neu · mit Featured · mit offenem Pending. */
	public static function media_stats() {
		$neu = get_posts( array(
			'post_type' => 'm24_teil', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
			'no_found_rows' => true,
			'meta_query' => array( array( 'key' => '_m24_typ', 'value' => 'neu' ) ),
		) );
		$featured = 0; $pending = 0;
		foreach ( $neu as $id ) {
			if ( get_post_thumbnail_id( $id ) ) { $featured++; }
			if ( ! empty( self::get_pending( $id ) ) ) { $pending++; }
		}
		return array( 'total' => count( $neu ), 'featured' => $featured, 'pending' => $pending );
	}

	/**
	 * Bild laden (kurzer Timeout) + an Produkt haengen, Hash-Dedupe. Gibt Attachment-ID
	 * oder 0 zurueck. Wirft NIE — WP_Error/Timeout → 0, Tempfile aufgeraeumt.
	 */
	private static function download_attach( $url, $post_id, $hash, $timeout ) {
		// Hash-Dedupe: existierenden Anhang wiederverwenden.
		if ( '' !== $hash ) {
			$ex = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true,
				'meta_query' => array( array( 'key' => '_m24_sw_media_hash', 'value' => $hash ) ) ) );
			if ( ! empty( $ex ) ) { return (int) $ex[0]; }
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, (int) $timeout ); // kurzer Timeout statt 300s
		if ( is_wp_error( $tmp ) ) { return 0; }

		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name ) { $name = 'bild-' . substr( md5( $url ), 0, 8 ) . '.jpg'; }
		$file = array( 'name' => $name, 'tmp_name' => $tmp );
		$att  = media_handle_sideload( $file, (int) $post_id );
		if ( is_wp_error( $att ) ) {
			if ( file_exists( $tmp ) ) { @unlink( $tmp ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 0;
		}
		if ( '' !== $hash ) { update_post_meta( (int) $att, '_m24_sw_media_hash', $hash ); }
		return (int) $att;
	}
}
