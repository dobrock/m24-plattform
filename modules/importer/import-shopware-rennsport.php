<?php
/**
 * M24 Plattform — Shopware-Import: RENNSPORT-Teile (kategorie-getrieben)
 * Modul: modules/importer/import-shopware-rennsport.php
 *
 * Importiert NEUE Rennsport-Teile aus den modellspezifischen Shopware-Kategorien
 * (Baum „RENNSPORT TEILE") als m24_teil mit _m24_typ='neu' und dem passenden
 * Hub-Modell-Term. Nutzt die BESTEHENDE Per-Produkt-Logik (M24_Shopware_Import_Core)
 * + Hintergrund-Queue (Action Scheduler) — eigener Hook, damit der Gebraucht-Import
 * unberuehrt bleibt.
 *
 * Trennung zum Gebraucht-Import:
 *   - eigener AS-Hook (HOOK) → keine Batch-Kollision.
 *   - _m24_typ='neu' + fester Modell-Term via Filter (m24_sw_import_typ /
 *     m24_sw_import_modell_terms); Gebrauchtteile (_m24_typ='gebraucht') werden NIE
 *     angefasst (andere Kategorien, anderer Hook).
 *   - Idempotent ueber _m24_sw_id (Re-Run = Update), Medien-Hash-Reuse.
 *
 * Kategorie-Discovery (Stand 2026-06-16, LIVE motorsport24.shop):
 *   Z4 GT3  9a43ce428ef14ef6b2f5ca95da656f96  → Term „Z4 GT3"  (Hub bmw-z4-gt3)
 *   E30     be308dd9d7224d6294aaaa5c94b2bfb5  → „M3 E30"        (bmw-m3-e30)
 *   E36     1e561a3bba884c06b31b23968fce1d35  → „M3 E36"        (bmw-m3-e36)
 *   E46     e34b137d0cb94c14ac43e6411b2c417e  → „M3 E46"        (bmw-m3-e46)
 *   E90     319000c7bbba4d5cbaf6df3340de42d3  → „M3 E9x"        (bmw-m3-e9x)
 *   E92     eb83bd916ba043628da091577058429d  → „M3 E9x"        (bmw-m3-e9x)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Shopware_Rennsport {

	const HOOK   = 'm24_import_rennsport_batch';
	const GROUP  = 'm24-import';
	const OPTION = 'm24_import_rennsport_run';
	const MAX_ATTEMPTS = 3;
	const RETRY_DELAY  = 120;

	/** Quelle (Shopware-Kategorie-UUID) → Ziel-Modell-Term-Name (Hub-Term). Filterbar. */
	public static function category_map() {
		return apply_filters( 'm24_rennsport_category_map', array(
			'z4-gt3' => array( 'cat' => '9a43ce428ef14ef6b2f5ca95da656f96', 'term' => 'Z4 GT3', 'label' => 'Z4 GT3' ),
			'e30'    => array( 'cat' => 'be308dd9d7224d6294aaaa5c94b2bfb5', 'term' => 'M3 E30', 'label' => 'E30 / Gruppe A / DTM' ),
			'e36'    => array( 'cat' => '1e561a3bba884c06b31b23968fce1d35', 'term' => 'M3 E36', 'label' => 'E36 / GT / DTM / STW' ),
			'e46'    => array( 'cat' => 'e34b137d0cb94c14ac43e6411b2c417e', 'term' => 'M3 E46', 'label' => 'E46 / GTR / WTC' ),
			'e90'    => array( 'cat' => '319000c7bbba4d5cbaf6df3340de42d3', 'term' => 'M3 E9x', 'label' => 'E90 / WTC' ),
			'e92'    => array( 'cat' => 'eb83bd916ba043628da091577058429d', 'term' => 'M3 E9x', 'label' => 'E92 / GTR' ),
		) );
	}

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ), 10, 6 );
	}

	/**
	 * Schutz „Gebrauchtteile NICHT anfassen": existiert das Produkt bereits als
	 * _m24_typ='gebraucht', wird es im Rennsport-Import uebersprungen (kein Typ-Flip).
	 * Filterbar (m24_rennsport_skip_existing_gebraucht) — false ⇒ doch reklassifizieren.
	 * @return int post_id wenn zu ueberspringen, sonst 0.
	 */
	private static function skip_existing_gebraucht( $sw_id ) {
		if ( ! apply_filters( 'm24_rennsport_skip_existing_gebraucht', true ) ) { return 0; }
		$q = get_posts( array(
			'post_type' => 'm24_teil', 'post_status' => 'any', 'posts_per_page' => 1,
			'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array( array( 'key' => '_m24_sw_id', 'value' => (string) $sw_id ) ),
		) );
		$pid = $q ? (int) $q[0] : 0;
		if ( $pid && 'gebraucht' === (string) get_post_meta( $pid, '_m24_typ', true ) ) { return $pid; }
		return 0;
	}

	private static function as_available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Reiht die Produkte der gewaehlten Kategorie-Keys als AS-Batches ein (nur IDs).
	 * @param array $keys       Teilmenge von category_map()-Keys; leer = alle.
	 * @param int   $batch_size
	 * @param bool  $force
	 * @return array { run_id, enqueued, batches, per_cat }
	 */
	public static function enqueue( $keys = array(), $batch_size = 5, $force = false ) {
		if ( ! self::as_available() ) {
			throw new Exception( 'Action Scheduler nicht verfuegbar (kein as_enqueue_async_action).' );
		}
		$batch_size = max( 1, (int) $batch_size );
		$map        = self::category_map();
		$keys       = empty( $keys ) ? array_keys( $map ) : array_values( array_intersect( array_keys( $map ), $keys ) );
		$client     = new M24_Shopware_Client();

		as_unschedule_all_actions( self::HOOK ); // sauberer Re-Run (hook-only)

		$run_id   = 'rs_' . gmdate( 'YmdHis' ) . '_' . substr( md5( implode( '', $keys ) . microtime() ), 0, 6 );
		$enqueued = 0; $batches = 0; $per_cat = array();

		foreach ( $keys as $key ) {
			$cfg  = $map[ $key ];
			$ids  = self::collect_ids( $client, $cfg['cat'] );
			$per_cat[ $key ] = count( $ids );
			foreach ( array_chunk( $ids, $batch_size ) as $i => $batch_ids ) {
				as_enqueue_async_action( self::HOOK,
					array( $batch_ids, (string) $cfg['term'], (bool) $force, $run_id, $key . '-' . $i, 1 ),
					self::GROUP );
				$batches++;
			}
			$enqueued += count( $ids );
		}

		update_option( self::OPTION, array(
			'run_id' => $run_id, 'type' => 'rennsport', 'keys' => $keys, 'per_cat' => $per_cat,
			'enqueued' => $enqueued, 'batches' => $batches, 'batch_size' => $batch_size,
			'started_at' => current_time( 'mysql' ), 'done_products' => 0, 'failed_products' => 0, 'done_batch_nos' => array(),
		), false );

		M24_Logger::info( 'shopware-import', sprintf( 'Rennsport-Enqueue: %d Produkte in %d Batches (run %s) — %s',
			$enqueued, $batches, $run_id, wp_json_encode( $per_cat ) ) );

		return array( 'run_id' => $run_id, 'enqueued' => $enqueued, 'batches' => $batches, 'per_cat' => $per_cat );
	}

	/** Alle parentId=null-Produkt-IDs einer Kategorie seitenweise sammeln. */
	private static function collect_ids( $client, $cat_uuid ) {
		$ids = array(); $page = 1; $size = 100;
		while ( true ) {
			$res  = $client->search_category_product_ids( $cat_uuid, $page, $size );
			$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
			if ( empty( $data ) ) { break; }
			foreach ( $data as $p ) { $id = (string) ( $p['id'] ?? '' ); if ( '' !== $id ) { $ids[] = $id; } }
			if ( count( $data ) < $size ) { break; }
			$page++;
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Worker: hydriert den Batch, setzt _m24_typ='neu' + festen Modell-Term via Filter,
	 * importiert ueber die bestehende Per-Produkt-Logik, raeumt die Filter wieder ab.
	 */
	public static function run_batch( $ids = array(), $term = '', $force = false, $run_id = '', $batch_no = 0, $attempt = 1 ) {
		$ids = is_array( $ids ) ? $ids : array();
		if ( empty( $ids ) ) { return; }

		try {
			$client   = new M24_Shopware_Client();
			$products = $client->fetch_products_by_ids( $ids );
		} catch ( Exception $e ) {
			self::handle_failure( $ids, $term, $force, $run_id, $batch_no, $attempt, $e->getMessage() );
			return;
		}

		// Filter aktivieren: dieser Batch → Rennsport + fester Hub-Term.
		$set_typ  = function () { return 'neu'; };
		$set_term = function () use ( $term ) { return array( $term ); };
		add_filter( 'm24_sw_import_typ', $set_typ );
		add_filter( 'm24_sw_import_modell_terms', $set_term );

		$worker = M24_Shopware_Queue_worker_proxy();
		$created = 0; $updated = 0; $locked = 0; $errors = 0; $skipped = 0;
		foreach ( $products as $product ) {
			if ( self::skip_existing_gebraucht( (string) ( $product['id'] ?? '' ) ) ) { $skipped++; continue; }
			$res = $worker->import_product_core( (array) $product, (bool) $force );
			switch ( $res['status'] ) {
				case 'created': $created++; break;
				case 'updated': $updated++; break;
				case 'skipped_lock': $locked++; break;
				default:
					$errors++;
					M24_Logger::warning( 'shopware-import', sprintf( 'Rennsport-Produkt-Fehler (Batch %s): %s — %s', $batch_no, $res['name'], $res['error'] ) );
			}
		}

		remove_filter( 'm24_sw_import_typ', $set_typ );
		remove_filter( 'm24_sw_import_modell_terms', $set_term );

		$missing = count( $ids ) - count( $products );
		if ( $missing > 0 ) { $errors += $missing; }

		self::tally( $run_id, $batch_no, $created + $updated + $locked + $skipped, $errors );
		M24_Logger::info( 'shopware-import', sprintf( 'Rennsport-Batch %s [%s] fertig: +%d neu, ~%d update, %d gesperrt, %d übersprungen(gebraucht), %d Fehler (run %s)',
			$batch_no, $term, $created, $updated, $locked, $skipped, $errors, $run_id ) );
	}

	private static function handle_failure( $ids, $term, $force, $run_id, $batch_no, $attempt, $msg ) {
		if ( $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::RETRY_DELAY, self::HOOK,
				array( $ids, $term, (bool) $force, $run_id, $batch_no, (int) $attempt + 1 ), self::GROUP );
			M24_Logger::warning( 'shopware-import', sprintf( 'Rennsport-Batch %s Hydrierung fehlgeschlagen (Versuch %d), Re-Enqueue: %s', $batch_no, $attempt, $msg ) );
		} else {
			self::tally( $run_id, $batch_no, 0, count( $ids ) );
			M24_Logger::error( 'shopware-import', sprintf( 'Rennsport-Batch %s endgueltig fehlgeschlagen: %s', $batch_no, $msg ) );
		}
	}

	private static function tally( $run_id, $batch_no, $done, $failed ) {
		$run = get_option( self::OPTION, array() );
		if ( empty( $run ) || ( $run['run_id'] ?? '' ) !== $run_id ) { return; }
		$nos = isset( $run['done_batch_nos'] ) && is_array( $run['done_batch_nos'] ) ? $run['done_batch_nos'] : array();
		if ( in_array( (string) $batch_no, $nos, true ) ) { return; }
		$nos[] = (string) $batch_no;
		$run['done_batch_nos']  = $nos;
		$run['done_products']   = (int) ( $run['done_products']   ?? 0 ) + (int) $done;
		$run['failed_products'] = (int) ( $run['failed_products'] ?? 0 ) + (int) $failed;
		update_option( self::OPTION, $run, false );
	}

	/** Synchroner Direkt-Import (Test/kleine Mengen, ohne AS) — gibt Zaehler zurueck. */
	public static function run_sync( $keys = array(), $force = false ) {
		$map    = self::category_map();
		$keys   = empty( $keys ) ? array_keys( $map ) : array_values( array_intersect( array_keys( $map ), $keys ) );
		$client = new M24_Shopware_Client();
		$tot    = array( 'created' => 0, 'updated' => 0, 'locked' => 0, 'skipped' => 0, 'errors' => 0, 'per_cat' => array() );
		foreach ( $keys as $key ) {
			$cfg = $map[ $key ];
			$ids = self::collect_ids( $client, $cfg['cat'] );
			$c = 0; $u = 0; $s = 0;
			foreach ( array_chunk( $ids, 25 ) as $chunk ) {
				$products = $client->fetch_products_by_ids( $chunk );
				$set_typ  = function () { return 'neu'; };
				$set_term = function () use ( $cfg ) { return array( $cfg['term'] ); };
				add_filter( 'm24_sw_import_typ', $set_typ );
				add_filter( 'm24_sw_import_modell_terms', $set_term );
				$worker = M24_Shopware_Queue_worker_proxy();
				foreach ( $products as $product ) {
					if ( self::skip_existing_gebraucht( (string) ( $product['id'] ?? '' ) ) ) { $tot['skipped']++; $s++; continue; }
					$res = $worker->import_product_core( (array) $product, (bool) $force );
					if ( 'created' === $res['status'] ) { $tot['created']++; $c++; }
					elseif ( 'updated' === $res['status'] ) { $tot['updated']++; $u++; }
					elseif ( 'skipped_lock' === $res['status'] ) { $tot['locked']++; }
					else { $tot['errors']++; }
				}
				remove_filter( 'm24_sw_import_typ', $set_typ );
				remove_filter( 'm24_sw_import_modell_terms', $set_term );
			}
			$tot['per_cat'][ $key ] = array( 'ids' => count( $ids ), 'created' => $c, 'updated' => $u, 'skipped' => $s );
		}
		return $tot;
	}

	/** WP-CLI: wp m24 import-rennsport [--cats=z4-gt3,e30] [--batch-size=5] [--sync] [--force] */
	public static function cli( $args, $assoc_args ) {
		$keys = isset( $assoc_args['cats'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['cats'] ) ) ) : array();
		$force = isset( $assoc_args['force'] );
		if ( isset( $assoc_args['sync'] ) ) {
			WP_CLI::log( '── Rennsport-Import (synchron) ──' );
			$t = self::run_sync( $keys, $force );
			WP_CLI::success( sprintf( '%d neu, %d update, %d gesperrt, %d übersprungen(gebraucht), %d Fehler', $t['created'], $t['updated'], $t['locked'], $t['skipped'], $t['errors'] ) );
			foreach ( $t['per_cat'] as $k => $v ) { WP_CLI::log( "  $k: {$v['ids']} IDs → {$v['created']} neu / {$v['updated']} update / {$v['skipped']} übersprungen" ); }
			return;
		}
		$bs = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 5;
		try {
			$r = self::enqueue( $keys, $bs, $force );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( '%d Produkte in %d Batches eingereiht (run %s).', $r['enqueued'], $r['batches'], $r['run_id'] ) );
		foreach ( $r['per_cat'] as $k => $n ) { WP_CLI::log( "  $k: $n Produkte" ); }
		WP_CLI::log( 'Antreiben: wp action-scheduler run · Status: wp m24 import-status' );
	}
}

/** Proxy auf die geteilte Per-Produkt-Logik (Trait via M24_Shopware_Queue). */
function M24_Shopware_Queue_worker_proxy() {
	static $w = null;
	if ( null === $w ) {
		// Anonyme Klasse, die nur den Import-Core-Trait nutzt (kein AS-State noetig).
		$w = new class() { use M24_Shopware_Import_Core; };
	}
	return $w;
}
