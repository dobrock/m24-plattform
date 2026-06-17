<?php
/**
 * M24 Plattform — Shopware-Import: GEBRAUCHT-Teile (robust, entkoppelt, idempotent)
 * Modul: modules/importer/import-shopware-gebraucht.php
 *
 * Gleiche Robustheit wie der Rennsport-Importer, aber fuer den Gebraucht-Pfad:
 *   - Quelle: Wurzel „GEBRAUCHTE TEILE" (Root), Porsche-Teilbaum ausgeschlossen.
 *   - _m24_typ bleibt Default 'gebraucht' (kein typ-Filter), _m24_status='aktiv'.
 *   - Modell-Term HYBRID: (1) Kategorie-Pfad-Map (deterministisch), (2) parse_from_name
 *     als Fallback, (3) zentrale Normalisierung e90|e92 → „M3 E9x" fuer BEIDE Pfade,
 *     (4) nicht aufloesbar → _m24_modell_unresolved=1 + Log (NIE stiller Drop).
 *   - Produkt-vor-Bild (Bilder in _m24_img_pending, best-effort danach, 15s, kein Throw).
 *   - Fast-Skip via existing_sw_ids() (typ-uebergreifend) → Re-Runs rasen durch Erledigtes.
 *   - Guard: fasst _m24_typ=neu NICHT an (Rennsport bleibt unberuehrt).
 *
 * Hinweis (Discovery 2026-06): Die Gebraucht-Modell-Kategorien sind BAUREIHEN
 * (z.B. „BMW 3er E46"), nicht M3-spezifisch — das Modell kommt real aus dem Namen.
 * Die Kategorie-Map ist daher konservativ + filterbar (m24_gebraucht_category_map).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Shopware_Gebraucht {

	const EXCLUDE = array( '018af11a2e6f7c16a9ed62487f1b3978' ); // Gebrauchte Porsche Teile
	const OPTION  = 'm24_import_gebraucht_run';

	/** Gebraucht-Modell-Kategorie-UUID → Hub-Term (deterministische Schicht). Filterbar. */
	public static function category_map() {
		return apply_filters( 'm24_gebraucht_category_map', array(
			'e1e9d5d6f089480f893f0892fa7d3d4a' => 'M3 E30', // BMW 3er E30
			'66deffa9ef9549c8998a5e3493a8593f' => 'M3 E36', // BMW 3er E36
			'2f612715317e48899edc361cb26f52be' => 'M3 E46', // BMW 3er E46
			'8e845f9f4b714873ac597e7b1a5d3130' => 'M3 E9x', // BMW 3er E90 / E92
		) );
	}

	/** Zentrale Normalisierung: E90/E92-Varianten → „M3 E9x" (beide Pfade). Filterbar. */
	public static function normalize_term( $term ) {
		$term = trim( (string) $term );
		if ( preg_match( '/\bE9[02]\b/i', $term ) || preg_match( '/m3\s*e9x/i', $term ) ) {
			$term = 'M3 E9x';
		}
		return (string) apply_filters( 'm24_gebraucht_normalize_term', $term );
	}

	/**
	 * Modell-Term(e) eines Produkts aufloesen: Kategorie-Map → Parser → normalisieren.
	 * @return array { terms:string[], src:'kategorie'|'parser'|'' }
	 */
	public static function resolve_terms( $product ) {
		// (1) Kategorie-Map (deterministisch).
		$map  = self::category_map();
		$cats = isset( $product['categories'] ) && is_array( $product['categories'] ) ? $product['categories'] : array();
		foreach ( $cats as $c ) {
			$cid = (string) ( $c['id'] ?? '' );
			if ( '' !== $cid && isset( $map[ $cid ] ) ) {
				return array( 'terms' => array( self::normalize_term( $map[ $cid ] ) ), 'src' => 'kategorie' );
			}
		}
		// (2) Parser-Fallback.
		if ( class_exists( 'M24_BMW_Models' ) ) {
			$cat_names = array();
			foreach ( $cats as $c ) { if ( ! empty( $c['name'] ) ) { $cat_names[] = (string) $c['name']; } }
			$p = M24_BMW_Models::parse_from_name( (string) ( $product['name'] ?? '' ), implode( ' ', $cat_names ) );
			$terms = array();
			if ( ! empty( $p['term_names'] ) && is_array( $p['term_names'] ) ) { $terms = $p['term_names']; }
			elseif ( ! empty( $p['term_name'] ) ) { $terms = array( $p['term_name'] ); }
			$terms = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize_term' ), $terms ) ) ) );
			if ( ! empty( $terms ) ) { return array( 'terms' => $terms, 'src' => 'parser' ); }
		}
		// (4) nicht aufloesbar.
		return array( 'terms' => array(), 'src' => '' );
	}

	public static function init() { /* Per-Produkt synchron; kein eigener AS-Hook noetig. */ }

	/** Worklist fuer den Admin-Chunk-Loop: neue (noch nicht importierte) Gebraucht-sw_ids. */
	public static function build_worklist() {
		$client   = new M24_Shopware_Client();
		$existing = self::existing_sw_ids();
		$ids      = self::collect_ids( $client );
		sort( $ids );
		$new = array();
		foreach ( $ids as $id ) { if ( ! isset( $existing[ $id ] ) ) { $new[] = $id; } }
		return $new;
	}

	/** Verarbeitet einen Chunk sw_ids (Admin-AJAX). Gibt Zaehler zurueck. */
	public static function process_chunk( array $sw_ids, $force = false ) {
		$r = array( 'processed' => 0, 'new' => 0, 'skipped' => 0, 'img_pending' => 0, 'unresolved' => 0, 'errors' => 0 );
		if ( empty( $sw_ids ) ) { return $r; }
		$client   = new M24_Shopware_Client();
		$products = $client->fetch_products_by_ids( $sw_ids );
		$by = array();
		foreach ( $products as $p ) { $by[ (string) ( $p['id'] ?? '' ) ] = $p; }
		foreach ( $sw_ids as $swid ) {
			$r['processed']++;
			$p = isset( $by[ $swid ] ) ? $by[ $swid ] : null;
			if ( null === $p ) { $r['errors']++; continue; }
			$res = self::import_one( $p, $force );
			if ( in_array( $res['status'], array( 'created', 'updated' ), true ) ) {
				$r['new']++;
				if ( '' === $res['src'] ) { $r['unresolved']++; }
			} elseif ( 'skipped_neu' === $res['status'] ) { $r['skipped']++; }
			else { $r['errors']++; }
			if ( is_array( $res['img'] ) && (int) $res['img']['remaining'] > 0 ) { $r['img_pending']++; }
		}
		return $r;
	}

	/** EIN Query: Set aller bereits importierten _m24_sw_id (typ-uebergreifend). */
	private static function existing_sw_ids() {
		global $wpdb;
		$vals = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_m24_sw_id' ) ); // phpcs:ignore WordPress.DB
		$set = array();
		foreach ( (array) $vals as $v ) { if ( '' !== (string) $v ) { $set[ (string) $v ] = true; } }
		return $set;
	}

	/** Guard: existiert das Produkt bereits als _m24_typ=neu → nicht anfassen (Rennsport). */
	private static function is_existing_neu( $sw_id ) {
		$q = get_posts( array( 'post_type' => 'm24_teil', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array( array( 'key' => '_m24_sw_id', 'value' => (string) $sw_id ) ) ) );
		return ( $q && 'neu' === (string) get_post_meta( (int) $q[0], '_m24_typ', true ) );
	}

	/** Alle Gebraucht-Produkt-IDs (parentId=null, Porsche raus) seitenweise sammeln. */
	private static function collect_ids( $client ) {
		$ids = array(); $page = 1; $size = 100;
		while ( true ) {
			$res  = $client->search_used_product_ids( $page, $size, self::EXCLUDE );
			$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
			if ( empty( $data ) ) { break; }
			foreach ( $data as $p ) { $id = (string) ( $p['id'] ?? '' ); if ( '' !== $id ) { $ids[] = $id; } }
			if ( count( $data ) < $size ) { break; }
			$page++;
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Entkoppelter Per-Produkt-Import (Produkt vor Bild). typ bleibt Default 'gebraucht'.
	 * @return array { status, post_id, src, img }
	 */
	public static function import_one( $product, $force = false ) {
		$sw_id = (string) ( $product['id'] ?? '' );
		if ( self::is_existing_neu( $sw_id ) ) {
			return array( 'status' => 'skipped_neu', 'post_id' => 0, 'src' => '', 'img' => null );
		}
		$resolved = self::resolve_terms( $product );
		$set_term = function () use ( $resolved ) { return $resolved['terms']; }; // unsere Hybrid-Terme (auch leer)
		$set_skip = function () { return true; };
		add_filter( 'm24_sw_import_modell_terms', $set_term );
		add_filter( 'm24_sw_skip_media', $set_skip );
		try {
			$res = M24_Shopware_Queue_worker_proxy()->import_product_core( (array) $product, (bool) $force );
		} catch ( Exception $e ) {
			$res = array( 'status' => 'skipped_error', 'post_id' => 0, 'name' => (string) ( $product['name'] ?? '' ), 'error' => $e->getMessage() );
		}
		remove_filter( 'm24_sw_import_modell_terms', $set_term );
		remove_filter( 'm24_sw_skip_media', $set_skip );

		$img = null; $pid = (int) ( $res['post_id'] ?? 0 );
		if ( $pid && in_array( $res['status'], array( 'created', 'updated' ), true ) ) {
			// Unresolved markieren (NIE stiller Drop).
			if ( empty( $resolved['terms'] ) ) {
				update_post_meta( $pid, '_m24_modell_unresolved', 1 );
				M24_Logger::warning( 'shopware-import', sprintf( 'Gebraucht: Modell unaufloesbar (#%d): %s', $pid, (string) ( $product['name'] ?? '' ) ) );
			} else {
				delete_post_meta( $pid, '_m24_modell_unresolved' );
			}
			try {
				M24_Shopware_Media::store_pending( $pid, M24_Shopware_Media::extract( $product ) );
				$img = M24_Shopware_Media::attempt( $pid, M24_Shopware_Media::DEFAULT_TIMEOUT );
			} catch ( Exception $e ) { $img = null; }
		}
		return array( 'status' => $res['status'], 'post_id' => $pid, 'src' => $resolved['src'], 'img' => $img );
	}

	/** Synchroner Voll-Import (Fast-Skip, idempotent, Fortschritt via $cb). */
	public static function run_all( $force = false, $cb = null ) {
		$client   = new M24_Shopware_Client();
		$existing = self::existing_sw_ids();
		$ids      = self::collect_ids( $client );
		sort( $ids ); // deterministisch
		$n = count( $ids ); $i = 0;
		$tot = array( 'created' => 0, 'updated' => 0, 'skipped_existing' => 0, 'skipped_neu' => 0, 'errors' => 0,
			'img_done' => 0, 'img_pending' => 0, 'src_kategorie' => 0, 'src_parser' => 0, 'unresolved' => 0 );

		$new = array();
		foreach ( $ids as $id ) {
			if ( isset( $existing[ $id ] ) ) { $tot['skipped_existing']++; $i++; continue; }
			$new[] = $id;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( sprintf( '  Gebraucht: übersprungen (bereits da): %d · neu in diesem Lauf: %d', $tot['skipped_existing'], count( $new ) ) );
		}

		foreach ( array_chunk( $new, 25 ) as $chunk ) {
			try { $products = $client->fetch_products_by_ids( $chunk ); }
			catch ( Exception $e ) { $tot['errors'] += count( $chunk ); $i += count( $chunk ); continue; }
			$by = array();
			foreach ( $products as $p ) { $by[ (string) ( $p['id'] ?? '' ) ] = $p; }
			foreach ( $chunk as $swid ) {
				$i++;
				$p = isset( $by[ $swid ] ) ? $by[ $swid ] : null;
				if ( null === $p ) { $tot['errors']++; continue; }
				$r = self::import_one( $p, $force );
				switch ( $r['status'] ) {
					case 'created': $tot['created']++; $existing[ $swid ] = true; break;
					case 'updated': $tot['updated']++; $existing[ $swid ] = true; break;
					case 'skipped_neu': $tot['skipped_neu']++; break;
					default: $tot['errors']++;
				}
				if ( 'kategorie' === $r['src'] ) { $tot['src_kategorie']++; }
				elseif ( 'parser' === $r['src'] ) { $tot['src_parser']++; }
				elseif ( in_array( $r['status'], array( 'created', 'updated' ), true ) ) { $tot['unresolved']++; }
				if ( is_array( $r['img'] ) ) {
					$tot['img_done'] += (int) $r['img']['done'];
					if ( (int) $r['img']['remaining'] > 0 ) { $tot['img_pending']++; }
				}
				if ( is_callable( $cb ) ) { call_user_func( $cb, $i, $n, $tot['created'] + $tot['updated'], $tot['skipped_existing'] + $tot['skipped_neu'], $tot['img_pending'] ); }
			}
		}
		return $tot;
	}

	/** WP-CLI: wp m24 import-gebraucht [--run-all] [--media] [--status] [--force] [--timeout=15] */
	public static function cli( $args, $assoc_args ) {
		$force = isset( $assoc_args['force'] );
		if ( isset( $assoc_args['status'] ) ) { self::print_status(); return; }
		if ( isset( $assoc_args['media'] ) ) {
			$to = isset( $assoc_args['timeout'] ) ? max( 5, (int) $assoc_args['timeout'] ) : M24_Shopware_Media::DEFAULT_TIMEOUT;
			WP_CLI::log( '── Gebraucht-Media-Repair (Timeout ' . $to . 's) ──' );
			$r = M24_Shopware_Media::repair_all( $to, function ( $i, $n, $pid, $res ) {
				if ( 0 === $i % 10 || $i === $n ) { WP_CLI::log( sprintf( '  %d/%d · +%d Bilder · offen %d', $i, $n, $res['done'], $res['remaining'] ) ); }
			}, 'gebraucht' );
			WP_CLI::success( sprintf( '%d Produkte · %d Bilder geladen · %d noch offen.', $r['products'], $r['done'], $r['still_pending'] ) );
			return;
		}
		if ( isset( $assoc_args['run-all'] ) ) {
			WP_CLI::log( '── Gebraucht-Import (synchron, Produkt-vor-Bild) ──' );
			$t = self::run_all( $force, function ( $i, $n, $ok, $skip, $imgp ) {
				if ( 0 === $i % 25 || $i === $n ) { WP_CLI::log( sprintf( '  %d/%d · ok %d · skip %d · img-pending %d', $i, $n, $ok, $skip, $imgp ) ); }
			} );
			WP_CLI::success( sprintf( '%d neu, %d update, %d bereits da, %d übersprungen(neu), %d Fehler · %d Bilder · %d offen.',
				$t['created'], $t['updated'], $t['skipped_existing'], $t['skipped_neu'], $t['errors'], $t['img_done'], $t['img_pending'] ) );
			WP_CLI::log( sprintf( 'Term-Auflösung: via Kategorie %d · via Parser %d · unresolved %d', $t['src_kategorie'], $t['src_parser'], $t['unresolved'] ) );
			if ( $t['img_pending'] > 0 ) { WP_CLI::log( 'Offene Bilder: wp m24 import-gebraucht --media' ); }
			return;
		}
		WP_CLI::log( 'Nutzung: wp m24 import-gebraucht --run-all | --media | --status  [--force] [--timeout=15]' );
	}

	/** Status (typ=gebraucht): total · Featured · img-pending · unresolved. */
	public static function print_status() {
		$m = M24_Shopware_Media::media_stats( 'gebraucht' );
		$unres = get_posts( array( 'post_type' => 'm24_teil', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array( 'relation' => 'AND', array( 'key' => '_m24_typ', 'value' => 'gebraucht' ), array( 'key' => '_m24_modell_unresolved', 'value' => '1' ) ) ) );
		WP_CLI::log( '── Gebraucht-Import · Status ──' );
		WP_CLI::log( 'Gebraucht-Produkte (_m24_typ=gebraucht):' );
		WP_CLI::log( '  total:           ' . (int) $m['total'] );
		WP_CLI::log( '  mit Featured:    ' . (int) $m['featured'] );
		WP_CLI::log( '  img-pending:     ' . (int) $m['pending'] . ( $m['pending'] > 0 ? '  → wp m24 import-gebraucht --media' : '  ✓ alle Bilder da' ) );
		WP_CLI::log( '  Modell unresolved: ' . count( $unres ) );
	}
}
