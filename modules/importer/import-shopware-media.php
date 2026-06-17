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
		if ( empty( $images ) ) { delete_post_meta( (int) $post_id, self::PENDING_META ); }
		else { update_post_meta( (int) $post_id, self::PENDING_META, wp_json_encode( array_values( $images ) ) ); }
		// Object-Cache (Redis/Memcached) hart invalidieren — sonst liefert der naechste
		// Request stale „pending=0", obwohl der Seed gerade N Bilder schrieb (Prod-Bug 0.9.x).
		wp_cache_delete( (int) $post_id, 'post_meta' );
	}

	/**
	 * Pending-Liste eines Produkts lesen. $fresh=true umgeht den Object-Cache (direkter
	 * DB-Read) — Pflicht im Rebuild-Step, weil ein persistenter Object-Cache auf PROD
	 * sonst stale „pending=0" zurueckgibt und der Step nie von seed → download springt.
	 */
	public static function get_pending( $post_id, $fresh = false ) {
		$post_id = (int) $post_id;
		if ( $fresh ) {
			global $wpdb;
			wp_cache_delete( $post_id, 'post_meta' ); // WP-Meta-Cache fuer diesen Post leeren
			$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", $post_id, self::PENDING_META ) ); // phpcs:ignore WordPress.DB
		} else {
			$raw = (string) get_post_meta( $post_id, self::PENDING_META, true );
		}
		$arr = '' !== $raw ? json_decode( $raw, true ) : array();
		return is_array( $arr ) ? $arr : array();
	}

	/**
	 * Bild-Versuch fuer EIN Produkt: jede Pending-URL laden (Timeout), Erfolg →
	 * Anhang + ggf. Featured/Galerie, URL aus Pending entfernen. Fehler → bleibt.
	 *
	 * @return array { done:int, remaining:int }  (wirft NIE)
	 */
	public static function attempt( $post_id, $timeout = self::DEFAULT_TIMEOUT, $deadline = 0.0 ) {
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
			// Per-Produkt-Deadline: kein neuer Download mehr nach Ablauf → Rest bleibt pending
			// (resumierbar), schuetzt vor Einzel-Produkt das den Call sprengt (viele tote Bilder).
			if ( $deadline > 0 && microtime( true ) >= $deadline ) { $remaining[] = $img; continue; }
			try {
				$att = self::download_attach( $url, $post_id, $hash, $timeout );
			} catch ( Exception $e ) {
				$att = 0;
			}
			if ( $att > 0 ) {
				$done++;
				if ( ! empty( $img['cover'] ) ) {
					// Cover → Featured (wenn leer); NIE zusaetzlich in die Galerie (kein Strip-Duplikat).
					if ( ! $has_featured ) { set_post_thumbnail( $post_id, $att ); $has_featured = $att; }
				} elseif ( $att !== $has_featured && ! in_array( $att, $gallery, true ) ) {
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
	public static function repair_all( $timeout = self::DEFAULT_TIMEOUT, $cb = null, $typ = 'neu' ) {
		$ids = self::pending_post_ids( $typ );
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

	/** Produkt-IDs (_m24_typ=$typ) mit nicht-leerem _m24_img_pending. */
	public static function pending_post_ids( $typ = 'neu' ) {
		$q = get_posts( array(
			'post_type'      => 'm24_teil', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => '_m24_typ', 'value' => (string) $typ ),
				array( 'key' => self::PENDING_META, 'compare' => 'EXISTS' ),
				array( 'key' => self::PENDING_META, 'value' => '', 'compare' => '!=' ),
				array( 'key' => self::PENDING_META, 'value' => '[]', 'compare' => '!=' ),
			),
		) );
		return array_map( 'intval', $q );
	}

	/**
	 * REBUILD-Worklist: Teile mit _m24_sw_id, deren Galerie unvollstaendig wirkt
	 * (≤1 Bild) ODER offene Pending haben. Fuer Backfill der ALT-importierten
	 * Cover-only-Teile (deren Galerie der --media-Pending-Pass nicht erreicht).
	 */
	public static function rebuild_worklist() {
		$ids = get_posts( array(
			'post_type' => 'm24_teil', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array( array( 'key' => '_m24_sw_id', 'compare' => 'EXISTS' ) ),
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$gal  = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $id, '_m24_galerie', true ) ) ) );
			$pend = self::get_pending( $id );
			if ( count( $gal ) <= 1 || ! empty( $pend ) ) { $out[] = (int) $id; }
		}
		return array_values( (array) apply_filters( 'm24_media_rebuild_worklist', $out ) );
	}

	/**
	 * REBUILD-Chunk: holt die Produkte (per _m24_sw_id) neu aus Shopware, setzt
	 * _m24_img_pending = ALLE Medien und laedt sie (idempotent, Hash-Dedupe → nur
	 * fehlende Downloads). Wirft NIE pro Produkt.
	 */
	public static function rebuild_chunk( array $post_ids, $timeout = self::DEFAULT_TIMEOUT, $deadline = 0.0 ) {
		$r = array( 'processed' => 0, 'new' => 0, 'img_pending' => 0, 'skipped' => 0, 'unresolved' => 0, 'errors' => 0 );
		if ( empty( $post_ids ) || ! class_exists( 'M24_Shopware_Client' ) ) { return $r; }
		$sw = array();
		foreach ( $post_ids as $pid ) { $s = (string) get_post_meta( (int) $pid, '_m24_sw_id', true ); if ( '' !== $s ) { $sw[ $s ] = (int) $pid; } }
		if ( empty( $sw ) ) { return $r; }
		try { $products = ( new M24_Shopware_Client() )->fetch_products_by_ids( array_keys( $sw ) ); }
		catch ( Exception $e ) { $r['errors'] = count( $post_ids ); return $r; }
		$by = array();
		foreach ( $products as $p ) { $by[ (string) ( $p['id'] ?? '' ) ] = $p; }
		foreach ( $post_ids as $pid ) {
			$r['processed']++;
			$s = (string) get_post_meta( (int) $pid, '_m24_sw_id', true );
			$p = isset( $by[ $s ] ) ? $by[ $s ] : null;
			if ( null === $p ) { $r['errors']++; continue; }
			try {
				self::store_pending( (int) $pid, self::extract( $p ) ); // Pending = ALLE Medien
				$a = self::attempt( (int) $pid, $timeout, $deadline );
				$r['new'] += (int) $a['done'];
				if ( (int) $a['remaining'] > 0 ) { $r['img_pending']++; }
			} catch ( Exception $e ) { $r['errors']++; }
			if ( $deadline > 0 && microtime( true ) >= $deadline ) { break; } // Call-Budget erreicht
		}
		return $r;
	}

	/**
	 * EIN winziger Rebuild-Schritt fuer EIN Produkt — entweder Seeding (Pending-Liste
	 * aus Shopware befuellen) ODER genau EIN Bild laden. Jeder Request bleibt klein
	 * (1 Shopware-Fetch ODER 1 download_url+Sideload, wenige Sekunden) → weit unter
	 * jedem FPM-/OOM-Limit, das den Shutdown-Handler umgeht. State zwischen Calls:
	 *   _m24_img_pending (verbleibende Bilder) + _m24_media_seed (Run-Token).
	 *
	 * @return array { stage:'seed'|'image', product_done:bool, pending:int, new:int, error:string }
	 */
	public static function rebuild_step( $post_id, $run_token, $timeout = 8 ) {
		$post_id   = (int) $post_id;
		$run_token = (string) $run_token;

		// GROUND-TRUTH: was liest der Storage am Step-START wirklich zurueck? (Prod-Diagnose:
		// Seed schreibt N, naechster Step liest 0 → Object-Cache-Stale sofort sichtbar.)
		$pending_now = self::get_pending( $post_id, true ); // fresh = direkter DB-Read, kein Cache
		self::cache_flush( $post_id );
		$seed = (string) get_post_meta( $post_id, '_m24_media_seed', true );
		M24_Import_Log::log( sprintf(
			'step start #%d: read pending=%d (key=%s · marker=%s · run=%s · ext_cache=%s)',
			$post_id, count( $pending_now ), self::PENDING_META, '' !== $seed ? $seed : '(leer)', $run_token,
			( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) ? 'JA' : 'nein'
		) );

		// VORRANG: Existiert eine Pending-Liste, wird IMMER genau 1 Bild geladen — nie neu
		// geseedet. Das ist immun gegen Token-Wechsel und garantiert Fortschritt (seed→download).
		if ( ! empty( $pending_now ) ) {
			return self::download_one( $post_id, $timeout );
		}

		// Pending leer: entweder in DIESEM Lauf schon geseedet (→ Produkt fertig) ODER noch nicht.
		if ( $seed === $run_token ) {
			return array( 'stage' => 'image', 'product_done' => true, 'pending' => 0, 'new' => 0, 'error' => '' );
		}

		// Phase 1 — Seeding: einmal pro Lauf die Bild-URLs aus Shopware holen (kein Download).
		$sw_id = (string) get_post_meta( $post_id, '_m24_sw_id', true );
		$imgs  = array();
		$err   = '';
		if ( '' === $sw_id ) {
			$err = 'kein _m24_sw_id';
		} elseif ( ! class_exists( 'M24_Shopware_Client' ) ) {
			$err = 'Shopware-Client fehlt';
		} else {
			M24_Import_Log::log( sprintf( 'media #%d: seed — Shopware-Fetch sw_id=%s', $post_id, $sw_id ) );
			try {
				$prods = ( new M24_Shopware_Client() )->fetch_products_by_ids( array( $sw_id ) );
				$p     = ( is_array( $prods ) && isset( $prods[0] ) ) ? $prods[0] : null;
				if ( $p ) { $imgs = self::extract( $p ); } else { $err = 'Shopware: Produkt nicht gefunden'; }
			} catch ( Exception $e ) {
				$err = 'Shopware-Fetch: ' . $e->getMessage();
			}
		}
		// WICHTIG: Seed-Marker IMMER setzen (auch bei leerer/fehlgeschlagener Liste) → ein
		// Produkt ohne Bilder wird beim naechsten Call als „fertig" erkannt, nie endlos re-geseedet.
		self::store_pending( $post_id, $imgs );
		update_post_meta( $post_id, '_m24_media_seed', $run_token );
		self::cache_flush( $post_id );
		$pending = count( $imgs );
		// SMOKING GUN: direkt aus der DB zurueckgelesen (umgeht Cache) — landete der Write?
		global $wpdb;
		$db_raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", $post_id, self::PENDING_META ) ); // phpcs:ignore WordPress.DB
		$db_marker = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", $post_id, '_m24_media_seed' ) ); // phpcs:ignore WordPress.DB
		$db_pending = is_array( $j = json_decode( $db_raw, true ) ) ? count( $j ) : 0;
		M24_Import_Log::log( sprintf( 'media #%d: seed fertig — %d Bilder pending%s · DB-readback pending=%d marker=%s', $post_id, $pending, '' !== $err ? ( ' · ' . $err ) : '', $db_pending, '' !== $db_marker ? $db_marker : '(leer)' ) );
		return array( 'stage' => 'seed', 'product_done' => ( 0 === $pending ), 'pending' => $pending, 'new' => 0, 'error' => $err );
	}

	/** Object-Cache (Redis/Memcached) fuer die Meta dieses Posts hart leeren — Prod-stale-fest. */
	private static function cache_flush( $post_id ) {
		wp_cache_delete( (int) $post_id, 'post_meta' );
	}

	/**
	 * Laedt GENAU EIN Pending-Bild (das erste), haengt es an, entfernt es aus Pending.
	 * Fehlgeschlagene Bilder werden VERWORFEN (nicht zurueckgelegt) — sonst Endlos-
	 * schleife auf einem toten Bild. Reseeding (neuer Run) holt sie idempotent zurueck.
	 * Speicher wird pro Request freigegeben (genau 1 media_handle_sideload).
	 *
	 * @return array { stage:'image', product_done:bool, pending:int, new:int, error:string }
	 */
	public static function download_one( $post_id, $timeout = 8 ) {
		$post_id = (int) $post_id;
		$pending = self::get_pending( $post_id, true ); // fresh: stale „pending=0" darf nicht faelschlich „fertig" melden
		if ( empty( $pending ) ) {
			return array( 'stage' => 'image', 'product_done' => true, 'pending' => 0, 'new' => 0, 'error' => '' );
		}

		$img  = array_shift( $pending ); // erstes Bild
		$url  = (string) ( $img['url'] ?? '' );
		$hash = (string) ( $img['hash'] ?? '' );

		// KRITISCH: verkleinerte Pending-Liste SOFORT persistieren — BEVOR der Download
		// fatalen kann. Killt max_execution_time den Request mitten im Download, ist das
		// Bild bereits raus → kein Endlos-Retry auf einem kaputten Bild. (Vorher wurde
		// store_pending erst am Ende aufgerufen → bei Fatal blieb pending unveraendert.)
		self::store_pending( $post_id, $pending );

		$new = 0; $error = '';

		if ( '' === $url ) {
			$error = 'leere URL';
		} elseif ( self::looks_malformed( $url ) ) {
			// Shopware-Verkettungsfehler (z.B. „…png1705164100516.jpeg") → gar nicht erst laden.
			$error = 'URL verdaechtig (Doppel-Endung) — uebersprungen';
			M24_Import_Log::log( sprintf( 'media #%d: SKIP malformed %s', $post_id, $url ) );
		} else {
			$gallery      = array_values( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $post_id, '_m24_galerie', true ) ) ) ) );
			$has_featured = (int) get_post_thumbnail_id( $post_id );
			M24_Import_Log::log( sprintf( 'media #%d: lade Bild %s (mem %s/%s · pending nach drop: %d · timeout %ds)', $post_id, $url, size_format( memory_get_usage( true ) ), ini_get( 'memory_limit' ), count( $pending ), $timeout ) );
			$t0  = microtime( true );
			try {
				$att = self::download_attach( $url, $post_id, $hash, $timeout );
			} catch ( Exception $e ) {
				$att = 0; $error = $e->getMessage();
			}
			$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( $att > 0 ) {
				$new = 1;
				if ( ! empty( $img['cover'] ) ) {
					if ( ! $has_featured ) { set_post_thumbnail( $post_id, $att ); $has_featured = $att; }
				} elseif ( $att !== $has_featured && ! in_array( $att, $gallery, true ) ) {
					$gallery[] = $att;
				}
				// Featured-Fallback: kein Cover, aber Galerie vorhanden → erstes Bild.
				if ( ! $has_featured && ! empty( $gallery ) ) { set_post_thumbnail( $post_id, (int) $gallery[0] ); }
				update_post_meta( $post_id, '_m24_galerie', implode( ',', $gallery ) );
				self::cache_flush( $post_id );
				M24_Import_Log::log( sprintf( 'media #%d: OK att=%d in %dms (peak %s)', $post_id, $att, $ms, size_format( memory_get_peak_usage( true ) ) ) );
			} else {
				$error = '' !== $error ? $error : 'Download/Sideload fehlgeschlagen';
				M24_Import_Log::log( sprintf( 'media #%d: FEHLER %s nach %dms — verworfen (%s)', $post_id, $url, $ms, $error ) );
			}
		}

		unset( $img );
		return array( 'stage' => 'image', 'product_done' => empty( $pending ), 'pending' => count( $pending ), 'new' => $new, 'error' => $error );
	}

	/**
	 * Heuristik fuer kaputte Shopware-Media-URLs: doppelte Bild-Endung im Pfad
	 * (z.B. „…/foo.png1705164100516.jpeg" = falsch verkettete Dateinamen). Solche
	 * URLs haengen beim Download bis ins max_execution_time-Fatal → vorab skippen.
	 */
	private static function looks_malformed( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return (bool) preg_match( '/\.(png|jpe?g|gif|webp|bmp|tiff?)[^\/]*\.(png|jpe?g|gif|webp|bmp|tiff?)$/i', $path );
	}

	/** Statistik fuer --status: total ($typ) · mit Featured · mit offenem Pending. */
	public static function media_stats( $typ = 'neu' ) {
		$neu = get_posts( array(
			'post_type' => 'm24_teil', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids',
			'no_found_rows' => true,
			'meta_query' => array( array( 'key' => '_m24_typ', 'value' => (string) $typ ) ),
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

		$tmp = self::fetch_to_tmp( $url, (int) $timeout );
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

	/**
	 * Bild in eine Temp-Datei laden mit HART begrenzter Gesamtzeit. Ersetzt download_url(),
	 * dessen Default-redirection (5) den Timeout pro Hop vervielfacht (ein kaputter Redirect-
	 * Chain lief 33s ins 30s-Fatal). Hier: redirection=1 → Worst-Case ≈ 2×$timeout, plus
	 * non-2xx wird sofort als Fehler behandelt. Wirft NIE — WP_Error|Tmp-Pfad.
	 */
	private static function fetch_to_tmp( $url, $timeout ) {
		$timeout = max( 3, (int) $timeout );
		$tmp = wp_tempnam( $url );
		if ( ! $tmp ) { return new WP_Error( 'm24_tmp', 'kein Tempfile' ); }
		$resp = wp_safe_remote_get( $url, array(
			'timeout'             => $timeout,   // cURL CURLOPT_TIMEOUT (Gesamttransfer pro Hop)
			'redirection'         => 1,          // max 1 Redirect → Zeit nicht vervielfachen
			'stream'              => true,
			'filename'            => $tmp,
			'limit_response_size' => 25 * MB_IN_BYTES,
			'headers'             => array( 'Accept' => 'image/*' ),
		) );
		if ( is_wp_error( $resp ) ) { if ( file_exists( $tmp ) ) { @unlink( $tmp ); } return $resp; } // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			if ( file_exists( $tmp ) ) { @unlink( $tmp ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'm24_http', 'HTTP ' . $code );
		}
		return $tmp;
	}
}
