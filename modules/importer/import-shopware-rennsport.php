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
	public static function enqueue( $keys = array(), $batch_size = 3, $force = false ) {
		if ( ! self::as_available() ) {
			throw new Exception( 'Action Scheduler nicht verfuegbar (kein as_enqueue_async_action).' );
		}
		$batch_size = max( 1, (int) $batch_size );
		$map        = self::category_map();
		$keys       = empty( $keys ) ? array_keys( $map ) : array_values( array_intersect( array_keys( $map ), $keys ) );
		$client     = new M24_Shopware_Client();

		self::reset_actions(); // pending canceln + failed/canceled/complete des EIGENEN Hooks loeschen → sauberer Re-Run

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

	/**
	 * Bereinigt AUSSCHLIESSLICH die AS-Aktionen des Rennsport-Hooks: pending canceln +
	 * failed/canceled/complete loeschen. Fremde Hooks (Rocket-Preload, Gebraucht-Import)
	 * bleiben unangetastet. → behebt „74 fehlgeschlagene Batches" + Doppel-Runs.
	 *
	 * @return array { pending, failed, canceled, complete } Anzahl bereinigter Aktionen.
	 */
	public static function reset_actions() {
		$out = array( 'pending' => 0, 'failed' => 0, 'canceled' => 0, 'complete' => 0 );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK ); // canceln aller offenen (hook-only Fast-Path)
		}
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) { return $out; }
		$store = ActionScheduler::store();
		$states = array(
			'failed'   => ActionScheduler_Store::STATUS_FAILED,
			'canceled' => ActionScheduler_Store::STATUS_CANCELED,
			'complete' => ActionScheduler_Store::STATUS_COMPLETE,
			'pending'  => ActionScheduler_Store::STATUS_PENDING,
		);
		foreach ( $states as $key => $status ) {
			$ids = $store->query_actions( array(
				'hook' => self::HOOK, 'group' => self::GROUP, 'status' => $status, 'per_page' => 2000,
			) );
			foreach ( (array) $ids as $id ) {
				try { $store->delete_action( (int) $id ); $out[ $key ]++; } catch ( Exception $e ) {}
			}
		}
		return $out;
	}

	/**
	 * Dedizierter Drain: arbeitet NUR die pending-Aktionen des Rennsport-Hooks ab
	 * (keine fremden AS-Jobs wie Rocket-Preload). Zeit-Budget-begrenzt → kein Plesk-
	 * Konsolen-Timeout. Mehrfach aufrufen bis „fertig".
	 *
	 * @param int $max_seconds Gesamt-Budget dieser Iteration.
	 * @return array { processed, remaining, seconds }
	 */
	public static function drain( $max_seconds = 50 ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			throw new Exception( 'Action Scheduler nicht verfuegbar.' );
		}
		$store  = ActionScheduler::store();
		$runner = ActionScheduler::runner();
		$start  = time();
		$processed = 0;
		while ( ( time() - $start ) < $max_seconds ) {
			$ids = $store->query_actions( array(
				'hook' => self::HOOK, 'group' => self::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 20, 'orderby' => 'date', 'order' => 'ASC',
			) );
			if ( empty( $ids ) ) { break; }
			foreach ( (array) $ids as $id ) {
				try { $runner->process_action( (int) $id, 'cli' ); } catch ( Exception $e ) {
					M24_Logger::warning( 'shopware-import', 'Rennsport-Drain Aktion ' . $id . ' Fehler: ' . $e->getMessage() );
				}
				$processed++;
				if ( ( time() - $start ) >= $max_seconds ) { break; }
			}
		}
		$remaining = (int) $store->query_actions( array(
			'hook' => self::HOOK, 'group' => self::GROUP,
			'status' => ActionScheduler_Store::STATUS_PENDING,
		), 'count' );
		return array( 'processed' => $processed, 'remaining' => $remaining, 'seconds' => time() - $start );
	}

	/** Status des AKTUELLSTEN Rennsport-Runs + AS-Zaehler des eigenen Hooks. */
	public static function status() {
		$run = get_option( self::OPTION, array() );
		$as  = array( 'pending' => null, 'running' => null, 'complete' => null, 'failed' => null );
		if ( class_exists( 'ActionScheduler' ) && class_exists( 'ActionScheduler_Store' ) ) {
			$store = ActionScheduler::store();
			foreach ( array(
				'pending'  => ActionScheduler_Store::STATUS_PENDING,
				'running'  => ActionScheduler_Store::STATUS_RUNNING,
				'complete' => ActionScheduler_Store::STATUS_COMPLETE,
				'failed'   => ActionScheduler_Store::STATUS_FAILED,
			) as $k => $st ) {
				$as[ $k ] = (int) $store->query_actions( array( 'hook' => self::HOOK, 'group' => self::GROUP, 'status' => $st ), 'count' );
			}
		}
		return array( 'run' => $run, 'as' => $as );
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
	 * ENTKOPPELTER Per-Produkt-Import (Prompt C, A+B): Produkt VOR Bild.
	 * 1) Post+Meta+Term committen (_m24_typ=neu, _m24_sw_id, Modell-Term) — Produkt
	 *    existiert ab hier garantiert (Medien im Core via m24_sw_skip_media aus).
	 * 2) Quell-Bild-URLs in _m24_img_pending ablegen.
	 * 3) Bilder best-effort laden (15s-Timeout, kein Throw) → Featured + Pending leeren.
	 * Jedes Produkt in eigenem try/catch — eine Exception failt NIE den Batch.
	 *
	 * @return array { status, post_id, name, error, img:{done,remaining}|null }
	 */
	public static function import_one( $product, $term, $force = false ) {
		$sw_id = (string) ( $product['id'] ?? '' );
		if ( self::skip_existing_gebraucht( $sw_id ) ) {
			return array( 'status' => 'skipped_gebraucht', 'post_id' => 0, 'name' => '', 'error' => '', 'img' => null );
		}
		$set_typ  = function () { return 'neu'; };
		$set_term = function () use ( $term ) { return array( $term ); };
		$set_skip = function () { return true; }; // Medien im Core ueberspringen → entkoppelt
		add_filter( 'm24_sw_import_typ', $set_typ );
		add_filter( 'm24_sw_import_modell_terms', $set_term );
		add_filter( 'm24_sw_skip_media', $set_skip );
		try {
			$res = M24_Shopware_Queue_worker_proxy()->import_product_core( (array) $product, (bool) $force );
		} catch ( Exception $e ) {
			$res = array( 'status' => 'skipped_error', 'post_id' => 0, 'name' => (string) ( $product['name'] ?? '' ), 'error' => $e->getMessage() );
		}
		remove_filter( 'm24_sw_import_typ', $set_typ );
		remove_filter( 'm24_sw_import_modell_terms', $set_term );
		remove_filter( 'm24_sw_skip_media', $set_skip );

		$img = null;
		$pid = (int) ( $res['post_id'] ?? 0 );
		if ( $pid && in_array( $res['status'], array( 'created', 'updated' ), true ) ) {
			try {
				M24_Shopware_Media::store_pending( $pid, M24_Shopware_Media::extract( $product ) );
				$img = M24_Shopware_Media::attempt( $pid, M24_Shopware_Media::DEFAULT_TIMEOUT );
			} catch ( Exception $e ) { $img = null; } // Bilder dürfen die Anlage nie kippen
		}
		return array( 'status' => $res['status'], 'post_id' => $pid, 'name' => $res['name'] ?? '', 'error' => $res['error'] ?? '', 'img' => $img );
	}

	/** Worker (AS-Batch): hydriert + importiert entkoppelt ueber import_one(). */
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

		$created = 0; $updated = 0; $locked = 0; $errors = 0; $skipped = 0;
		foreach ( $products as $product ) {
			$r = self::import_one( $product, $term, $force );
			switch ( $r['status'] ) {
				case 'created': $created++; break;
				case 'updated': $updated++; break;
				case 'skipped_lock': $locked++; break;
				case 'skipped_gebraucht': $skipped++; break;
				default:
					$errors++;
					M24_Logger::warning( 'shopware-import', sprintf( 'Rennsport-Produkt-Fehler (Batch %s): %s — %s', $batch_no, $r['name'], $r['error'] ) );
			}
		}

		$missing = count( $ids ) - count( $products );
		if ( $missing > 0 ) { $errors += $missing; }

		self::tally( $run_id, $batch_no, $created + $updated + $locked + $skipped, $errors );
		M24_Logger::info( 'shopware-import', sprintf( 'Rennsport-Batch %s [%s] fertig: +%d neu, ~%d update, %d gesperrt, %d übersprungen(gebraucht), %d Fehler (run %s)',
			$batch_no, $term, $created, $updated, $locked, $skipped, $errors, $run_id ) );
	}

	/**
	 * SYNCHRONER Voll-Drain (Prompt C, C): EIN CLI-Prozess, schleift ueber ALLE Produkte
	 * der gewaehlten Kategorien bis fertig. Kein Zeit-Budget (CLI hat kein max_execution_time),
	 * idempotent ueber _m24_sw_id (Disconnect/Ctrl-C → neu starten). Fortschritt via $cb.
	 *
	 * @return array { created,updated,skipped,errors,img_done,img_pending,per_cat }
	 */
	public static function run_all( $keys = array(), $force = false, $cb = null ) {
		$map    = self::category_map();
		$keys   = empty( $keys ) ? array_keys( $map ) : array_values( array_intersect( array_keys( $map ), $keys ) );
		$client = new M24_Shopware_Client();
		$tot    = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'img_done' => 0, 'img_pending' => 0, 'per_cat' => array() );

		foreach ( $keys as $key ) {
			$cfg = $map[ $key ];
			$ids = self::collect_ids( $client, $cfg['cat'] );
			$n   = count( $ids ); $i = 0; $c = 0; $u = 0; $s = 0;
			foreach ( array_chunk( $ids, 25 ) as $chunk ) {
				try { $products = $client->fetch_products_by_ids( $chunk ); }
				catch ( Exception $e ) { $tot['errors'] += count( $chunk ); $i += count( $chunk ); continue; }
				$by = array();
				foreach ( $products as $p ) { $by[ (string) ( $p['id'] ?? '' ) ] = $p; }
				foreach ( $chunk as $swid ) {
					$i++;
					$p = isset( $by[ $swid ] ) ? $by[ $swid ] : null;
					if ( null === $p ) { $tot['errors']++; continue; }
					$r = self::import_one( $p, $cfg['term'], $force );
					switch ( $r['status'] ) {
						case 'created': $tot['created']++; $c++; break;
						case 'updated': $tot['updated']++; $u++; break;
						case 'skipped_gebraucht': $tot['skipped']++; $s++; break;
						case 'skipped_lock': $tot['skipped']++; break;
						default: $tot['errors']++;
					}
					if ( is_array( $r['img'] ) ) {
						$tot['img_done'] += (int) $r['img']['done'];
						if ( (int) $r['img']['remaining'] > 0 ) { $tot['img_pending']++; }
					}
					if ( is_callable( $cb ) ) { call_user_func( $cb, $key, $i, $n, $c + $u, $s, $tot['img_pending'] ); }
				}
			}
			$tot['per_cat'][ $key ] = array( 'ids' => $n, 'created' => $c, 'updated' => $u, 'skipped' => $s );
		}
		return $tot;
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

	/**
	 * WP-CLI: wp m24 import-rennsport [--cats=z4-gt3,e30,…]
	 *   --run-all    → SYNCHRONER Voll-Import in EINEM CLI-Prozess (Produkt vor Bild,
	 *                  kein Zeit-Budget, idempotent; empfohlen). Alias: --sync
	 *   --media      → Repair-Pass: offene _m24_img_pending nachladen (resumierbar, cron) [--timeout=15]
	 *   --status     → Status + Bild-Statistik (total neu · mit Featured · img-pending)
	 *   (default)    → Enqueue über eigene AS-Gruppe (bereinigt vorher Alt-Aktionen)
	 *   --run        → dedizierter AS-Drain NUR des Rennsport-Hooks [--max-seconds=50]
	 *   --reset      → Alt-Aktionen des Rennsport-Hooks bereinigen
	 *   --batch-size=3 (Enqueue) · --force
	 */
	public static function cli( $args, $assoc_args ) {
		$keys  = isset( $assoc_args['cats'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['cats'] ) ) ) : array();
		$force = isset( $assoc_args['force'] );

		// --status
		if ( isset( $assoc_args['status'] ) ) {
			self::print_status();
			return;
		}
		// --reset
		if ( isset( $assoc_args['reset'] ) ) {
			$r = self::reset_actions();
			WP_CLI::success( sprintf( 'Rennsport-Hook bereinigt: %d pending, %d failed, %d canceled, %d complete geloescht.',
				$r['pending'], $r['failed'], $r['canceled'], $r['complete'] ) );
			return;
		}
		// --run (dedizierter Drain)
		if ( isset( $assoc_args['run'] ) ) {
			$max = isset( $assoc_args['max-seconds'] ) ? max( 5, (int) $assoc_args['max-seconds'] ) : 50;
			WP_CLI::log( '── Rennsport-Drain (nur eigener Hook, ' . $max . 's Budget) ──' );
			try {
				$d = self::drain( $max );
			} catch ( Exception $e ) {
				WP_CLI::error( $e->getMessage() );
			}
			$run = get_option( self::OPTION, array() );
			WP_CLI::success( sprintf( '%d Batches verarbeitet in %ds · %d offen.', $d['processed'], $d['seconds'], $d['remaining'] ) );
			if ( ! empty( $run ) ) {
				WP_CLI::log( sprintf( 'Run %s: %d ok · %d Fehler', $run['run_id'] ?? '—', (int) ( $run['done_products'] ?? 0 ), (int) ( $run['failed_products'] ?? 0 ) ) );
			}
			if ( $d['remaining'] > 0 ) {
				WP_CLI::log( 'Noch offen → erneut ausführen: wp m24 import-rennsport --run' );
			} else {
				WP_CLI::success( 'Rennsport-Queue leer — Import abgeschlossen.' );
			}
			return;
		}
		// --media (Repair-Pass: offene Bilder nachladen, resumierbar)
		if ( isset( $assoc_args['media'] ) ) {
			$to = isset( $assoc_args['timeout'] ) ? max( 5, (int) $assoc_args['timeout'] ) : M24_Shopware_Media::DEFAULT_TIMEOUT;
			WP_CLI::log( '── Rennsport-Media-Repair (Timeout ' . $to . 's) ──' );
			$r = M24_Shopware_Media::repair_all( $to, function ( $i, $n, $pid, $res ) {
				if ( 0 === $i % 10 || $i === $n ) { WP_CLI::log( sprintf( '  %d/%d · +%d Bilder · offen %d', $i, $n, $res['done'], $res['remaining'] ) ); }
			} );
			WP_CLI::success( sprintf( '%d Produkte bearbeitet · %d Bilder geladen · %d noch mit offenen Bildern.', $r['products'], $r['done'], $r['still_pending'] ) );
			return;
		}
		// --run-all / --sync → SYNCHRONER Voll-Drain (entkoppelt, kein Zeit-Budget)
		if ( isset( $assoc_args['run-all'] ) || isset( $assoc_args['sync'] ) ) {
			WP_CLI::log( '── Rennsport-Import (synchron, Produkt-vor-Bild) ──' );
			$t = self::run_all( $keys, $force, function ( $key, $i, $n, $ok, $skip, $imgp ) {
				if ( 0 === $i % 10 || $i === $n ) { WP_CLI::log( sprintf( '  %s %d/%d · ok %d · skip %d · img-pending %d', $key, $i, $n, $ok, $skip, $imgp ) ); }
			} );
			WP_CLI::success( sprintf( '%d neu, %d update, %d übersprungen(gebraucht), %d Fehler · %d Bilder geladen, %d Produkte mit offenen Bildern.',
				$t['created'], $t['updated'], $t['skipped'], $t['errors'], $t['img_done'], $t['img_pending'] ) );
			foreach ( $t['per_cat'] as $k => $v ) { WP_CLI::log( "  $k: {$v['ids']} IDs → {$v['created']} neu / {$v['updated']} update / {$v['skipped']} übersprungen" ); }
			if ( $t['img_pending'] > 0 ) { WP_CLI::log( 'Offene Bilder nachladen: wp m24 import-rennsport --media' ); }
			return;
		}
		// default → Enqueue
		$bs = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 3;
		try {
			$r = self::enqueue( $keys, $bs, $force );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( '%d Produkte in %d Batches eingereiht (run %s).', $r['enqueued'], $r['batches'], $r['run_id'] ) );
		foreach ( $r['per_cat'] as $k => $n ) { WP_CLI::log( "  $k: $n Produkte" ); }
		WP_CLI::log( 'Empfohlen (synchron, kein AS-Babysitting): wp m24 import-rennsport --cats=' . ( $keys ? implode( ',', $keys ) : 'z4-gt3' ) . ' --run-all' );
		WP_CLI::log( 'AS-Drain (nur eigener Hook):                wp m24 import-rennsport --run' );
		WP_CLI::log( 'Status:                                     wp m24 import-rennsport --status' );
	}

	/** Status-Ausgabe (CLI) des aktuellsten Rennsport-Runs. */
	public static function print_status() {
		$s = self::status(); $run = $s['run']; $as = $s['as'];
		WP_CLI::log( '── Rennsport-Import · Status ──' );
		if ( empty( $run ) ) {
			WP_CLI::log( 'Kein Rennsport-Run registriert. Start: wp m24 import-rennsport --cats=z4-gt3' );
		} else {
			WP_CLI::log( 'Run:         ' . ( $run['run_id'] ?? '—' ) . '  (gestartet ' . ( $run['started_at'] ?? '—' ) . ')' );
			WP_CLI::log( 'Kategorien:  ' . implode( ', ', (array) ( $run['keys'] ?? array() ) ) );
			WP_CLI::log( 'Eingereiht:  ' . (int) ( $run['enqueued'] ?? 0 ) . ' Produkte / ' . (int) ( $run['batches'] ?? 0 ) . ' Batches' );
			WP_CLI::log( 'Verarbeitet: ' . (int) ( $run['done_products'] ?? 0 ) . ' ok · ' . (int) ( $run['failed_products'] ?? 0 ) . ' Fehler' );
		}
		WP_CLI::log( '' );
		WP_CLI::log( 'Action-Scheduler (nur Rennsport-Hook):' );
		WP_CLI::log( '  offen:       ' . ( null === $as['pending'] ? 'n/a' : (int) $as['pending'] ) );
		WP_CLI::log( '  laufend:     ' . ( null === $as['running'] ? 'n/a' : (int) $as['running'] ) );
		WP_CLI::log( '  erledigt:    ' . ( null === $as['complete'] ? 'n/a' : (int) $as['complete'] ) );
		WP_CLI::log( '  fehlgeschl.: ' . ( null === $as['failed'] ? 'n/a' : (int) $as['failed'] ) );
		// Bild-Statistik (Prompt C, E): „alle Bilder da?" ohne SQL.
		$m = M24_Shopware_Media::media_stats();
		WP_CLI::log( '' );
		WP_CLI::log( 'Rennsport-Produkte (_m24_typ=neu):' );
		WP_CLI::log( '  total:           ' . (int) $m['total'] );
		WP_CLI::log( '  mit Featured:    ' . (int) $m['featured'] );
		WP_CLI::log( '  img-pending:     ' . (int) $m['pending'] . ( $m['pending'] > 0 ? '  → wp m24 import-rennsport --media' : '  ✓ alle Bilder da' ) );
		if ( ! empty( $run ) && 0 === (int) $as['pending'] + (int) $as['running'] ) {
			WP_CLI::success( 'Rennsport-Queue leer — Import abgeschlossen.' );
		}
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
