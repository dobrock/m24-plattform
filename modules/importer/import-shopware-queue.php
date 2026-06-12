<?php
/**
 * M24 Plattform — Shopware-Import als Hintergrund-Job (Action Scheduler / WP-Cron)
 * Modul: modules/importer/import-shopware-queue.php
 *
 * Loest das Plesk-Konsolen-Timeout (exit 124): statt 523 Produkte synchron mit
 * Bild-Downloads zu importieren, werden sie als Action-Scheduler-Batches eingereiht
 * und ueber den bestehenden WP-Cron server-seitig in Haeppchen abgearbeitet.
 *
 * Ablauf:
 *   1. `wp m24 import-shopware --queue` (Enqueue, < 10 s): holt NUR die Produkt-IDs
 *      (keine Bilder) seitenweise und reiht pro Batch eine async AS-Action ein.
 *   2. WP-Cron triggert Action Scheduler → `run_batch()` importiert jeden Batch ueber
 *      die BESTEHENDE Per-Produkt-Logik (trait M24_Shopware_Import_Core) — idempotent,
 *      Medien-Reuse. Fehler pro Produkt werden geloggt + uebersprungen.
 *   3. Fortschritt: `wp m24 import-status` + Admin-Seite „Shopware-Import".
 *
 * Robustheit: schlaegt die API-Hydrierung eines Batches fehl, wird der Batch (bis
 * MAX_ATTEMPTS) mit Verzoegerung neu eingereiht. Idempotenz verhindert Duplikate.
 *
 * Abhaengigkeit: Action Scheduler (auf Live via WP Rocket + Imagify gebundlet, lokal
 * via Imagify). Fehlt es, bricht der Enqueue mit klarer Meldung ab.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Shopware_Queue {

	// Per-Produkt-Importlogik — geteilt mit dem synchronen CLI-Command.
	use M24_Shopware_Import_Core;

	const HOOK         = 'm24_import_shopware_batch';
	const HOOK_RESYNC  = 'm24_resync_media_batch';
	const GROUP        = 'm24-import';
	const OPTION       = 'm24_import_run';
	const MAX_ATTEMPTS = 3;
	const RETRY_DELAY  = 120; // Sekunden bis Re-Enqueue eines fehlgeschlagenen Batches.

	// Porsche-Wurzel (identisch zum synchronen Importer).
	const EXCLUDE = array( '018af11a2e6f7c16a9ed62487f1b3978' );

	/** Registriert die AS-Action-Handler. Muss in JEDEM Kontext laufen (Cron/Web), nicht nur CLI. */
	public static function init() {
		add_action( self::HOOK,        array( __CLASS__, 'run_batch' ),        10, 5 );
		add_action( self::HOOK_RESYNC, array( __CLASS__, 'run_resync_batch' ), 10, 5 );
	}

	/** Singleton-Instanz fuer die Trait-Methoden (kein State noetig). */
	private static function worker() {
		static $instance = null;
		if ( null === $instance ) { $instance = new self(); }
		return $instance;
	}

	public static function as_available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	// ----------------------------------------------------------------------
	// Enqueue
	// ----------------------------------------------------------------------

	/**
	 * Reiht alle Produkte als AS-Batches ein. Schnell — holt nur IDs, keine Bilder.
	 *
	 * @param string $type
	 * @param int    $batch_size
	 * @param bool   $force
	 * @return array { run_id, enqueued, batches, batch_size }  (wirft Exception bei Fehler)
	 */
	public static function enqueue( $type = 'gebraucht', $batch_size = 10, $force = false ) {
		if ( ! self::as_available() ) {
			throw new Exception( 'Action Scheduler nicht verfuegbar (kein as_enqueue_async_action). Ist WP Rocket / Imagify aktiv?' );
		}
		$batch_size = max( 1, (int) $batch_size );

		$client = new M24_Shopware_Client();

		// Bestehende offene Batches dieses Hooks verwerfen → sauberer Re-Run ohne Doppel-Batches.
		// WICHTIG: hook-only (ohne Group/Args) aufrufen, damit AS den cancel_actions_by_hook-
		// Fast-Path nimmt. Mit Group + leeren Args wuerde AS nur Actions OHNE Args matchen und
		// unsere (args-tragenden) Batches stehen lassen → Duplikate beim Re-Run.
		as_unschedule_all_actions( self::HOOK );

		// IDs seitenweise einsammeln (nur id/productNumber, keine Associations).
		$ids       = array();
		$page      = 1;
		$page_size = 100;
		$total     = 0;
		while ( true ) {
			$result   = $client->search_used_product_ids( $page, $page_size, self::EXCLUDE );
			$products = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
			if ( 1 === $page ) { $total = (int) ( $result['total'] ?? 0 ); }
			if ( empty( $products ) ) { break; }
			foreach ( $products as $p ) {
				$id = (string) ( $p['id'] ?? '' );
				if ( '' !== $id ) { $ids[] = $id; }
			}
			if ( count( $products ) < $page_size ) { break; }
			$page++;
		}
		$ids = array_values( array_unique( $ids ) );

		$run_id  = 'run_' . gmdate( 'YmdHis' ) . '_' . substr( md5( implode( '', $ids ) . microtime() ), 0, 6 );
		$batches = array_chunk( $ids, $batch_size );

		foreach ( $batches as $i => $batch_ids ) {
			as_enqueue_async_action(
				self::HOOK,
				array( $batch_ids, (bool) $force, $run_id, (int) $i, 1 ),
				self::GROUP
			);
		}

		$run = array(
			'run_id'         => $run_id,
			'type'           => (string) $type,
			'force'          => (bool) $force,
			'enqueued'       => count( $ids ),
			'total_api'      => $total,
			'batches'        => count( $batches ),
			'batch_size'     => $batch_size,
			'started_at'     => current_time( 'mysql' ),
			'done_products'  => 0,
			'failed_products'=> 0,
			'done_batch_nos' => array(),
		);
		update_option( self::OPTION, $run, false );

		M24_Logger::info( 'shopware-import', sprintf(
			'Enqueue: %d Produkte in %d Batches (Groesse %d, run %s)%s',
			count( $ids ), count( $batches ), $batch_size, $run_id, $force ? ', force' : ''
		) );

		return array(
			'run_id'     => $run_id,
			'enqueued'   => count( $ids ),
			'batches'    => count( $batches ),
			'batch_size' => $batch_size,
		);
	}

	/**
	 * Reiht eine konkrete sw_id-Liste fuer einen Media-Resync ueber Action Scheduler ein
	 * (eigener Hook → run_resync_batch). Default batch-size 2 (Bild-Downloads), drainet
	 * unbeaufsichtigt ueber den System-Cron. $cover_only = nur Featured Image laden.
	 *
	 * @return array { run_id, enqueued, batches }  (wirft Exception ohne Action Scheduler)
	 */
	public static function enqueue_resync( array $sw_ids, $cover_only = false, $batch_size = 2 ) {
		if ( ! self::as_available() ) {
			throw new Exception( 'Action Scheduler nicht verfuegbar (kein as_enqueue_async_action).' );
		}
		$sw_ids = array_values( array_unique( array_filter( array_map( 'strval', $sw_ids ) ) ) );
		if ( empty( $sw_ids ) ) {
			return array( 'run_id' => '', 'enqueued' => 0, 'batches' => 0 );
		}
		$batch_size = max( 1, (int) $batch_size );
		as_unschedule_all_actions( self::HOOK_RESYNC ); // hook-only → sauberer Re-Run

		$run_id  = 'resync_' . gmdate( 'YmdHis' ) . '_' . substr( md5( implode( '', $sw_ids ) . microtime() ), 0, 6 );
		$batches = array_chunk( $sw_ids, $batch_size );
		foreach ( $batches as $i => $batch_ids ) {
			as_enqueue_async_action(
				self::HOOK_RESYNC,
				array( $batch_ids, (bool) $cover_only, $run_id, (int) $i, 1 ),
				self::GROUP
			);
		}
		update_option( self::OPTION, array(
			'run_id'          => $run_id,
			'type'            => $cover_only ? 'resync-media (cover-only)' : 'resync-media',
			'force'           => false,
			'enqueued'        => count( $sw_ids ),
			'total_api'       => count( $sw_ids ),
			'batches'         => count( $batches ),
			'batch_size'      => $batch_size,
			'started_at'      => current_time( 'mysql' ),
			'done_products'   => 0,
			'failed_products' => 0,
			'done_batch_nos'  => array(),
		), false );

		M24_Logger::info( 'shopware-import', sprintf(
			'Media-Resync enqueue: %d Teile in %d Batches (Groesse %d%s, run %s)',
			count( $sw_ids ), count( $batches ), $batch_size, $cover_only ? ', cover-only' : '', $run_id
		) );
		return array( 'run_id' => $run_id, 'enqueued' => count( $sw_ids ), 'batches' => count( $batches ) );
	}

	// ----------------------------------------------------------------------
	// Worker (laeuft im WP-Cron-Kontext, NICHT WP-CLI)
	// ----------------------------------------------------------------------

	/**
	 * Importiert einen Batch ueber die bestehende Per-Produkt-Logik.
	 * Args werden von Action Scheduler positionsweise uebergeben.
	 */
	public static function run_batch( $ids = array(), $force = false, $run_id = '', $batch_no = 0, $attempt = 1 ) {
		$ids = is_array( $ids ) ? $ids : array();
		if ( empty( $ids ) ) { return; }

		// Batch hydrieren (eine API-Anfrage). Faellt das fehl → Batch verzoegert neu einreihen.
		try {
			$client   = new M24_Shopware_Client();
			$products = $client->fetch_products_by_ids( $ids );
		} catch ( Exception $e ) {
			self::handle_batch_failure( $ids, $force, $run_id, $batch_no, $attempt, $e->getMessage() );
			return;
		}

		$worker = self::worker();
		$created = 0; $updated = 0; $locked = 0; $errors = 0;
		foreach ( $products as $product ) {
			$res = $worker->import_product_core( (array) $product, (bool) $force );
			switch ( $res['status'] ) {
				case 'created':      $created++; break;
				case 'updated':      $updated++; break;
				case 'skipped_lock': $locked++;  break;
				default:
					$errors++;
					M24_Logger::warning( 'shopware-import', sprintf(
						'Produkt-Fehler (Batch %d): %s — %s', $batch_no, $res['name'], $res['error']
					) );
			}
		}

		// IDs ohne gefundenes Produkt (in Shopware geloescht o.ae.) als Fehler werten.
		$missing = count( $ids ) - count( $products );
		if ( $missing > 0 ) { $errors += $missing; }

		self::tally_batch( $run_id, $batch_no, $created + $updated + $locked, $errors );

		M24_Logger::info( 'shopware-import', sprintf(
			'Batch %d fertig: +%d neu, ~%d update, %d gesperrt, %d Fehler (run %s)',
			$batch_no, $created, $updated, $locked, $errors, $run_id
		) );
	}

	/**
	 * Media-Resync-Worker: laedt fehlende Medien (oder nur Cover) der Batch-sw_ids nach.
	 * Titel/Preis/Meta bleiben unberuehrt; idempotent ueber Media-Hash. Quellbild fehlt →
	 * geloggt, kein endloser Retry.
	 */
	public static function run_resync_batch( $ids = array(), $cover_only = false, $run_id = '', $batch_no = 0, $attempt = 1 ) {
		$ids = is_array( $ids ) ? $ids : array();
		if ( empty( $ids ) ) { return; }

		try {
			$client   = new M24_Shopware_Client();
			$products = $client->fetch_products_by_ids( $ids );
		} catch ( Exception $e ) {
			if ( $attempt < self::MAX_ATTEMPTS && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + self::RETRY_DELAY, self::HOOK_RESYNC,
					array( $ids, (bool) $cover_only, $run_id, (int) $batch_no, (int) $attempt + 1 ), self::GROUP );
				M24_Logger::warning( 'shopware-import', sprintf( 'Resync-Batch %d Hydrierung fehlgeschlagen (Versuch %d/%d), Re-Enqueue: %s', $batch_no, $attempt, self::MAX_ATTEMPTS, $e->getMessage() ) );
			} else {
				self::tally_batch( $run_id, $batch_no, 0, count( $ids ) );
				M24_Logger::error( 'shopware-import', sprintf( 'Resync-Batch %d endgueltig fehlgeschlagen: %s', $batch_no, $e->getMessage() ) );
			}
			return;
		}

		$worker = self::worker();
		$by_id  = array();
		foreach ( $products as $p ) { $by_id[ (string) ( $p['id'] ?? '' ) ] = $p; }

		$done = 0; $no_source = 0; $errors = 0;
		foreach ( $ids as $sw ) {
			$p = isset( $by_id[ $sw ] ) ? $by_id[ $sw ] : null;
			if ( null === $p ) { $errors++; continue; }
			$post_id = $worker->find_by_sw_id( $sw );
			if ( ! $post_id ) { $errors++; continue; }
			$media = isset( $p['media'] ) && is_array( $p['media'] ) ? $p['media'] : array();
			if ( empty( $media ) ) {
				$no_source++;
				M24_Logger::warning( 'shopware-import', sprintf( 'Resync #%d: Quellbild fehlt bei Shopware (bleibt manuell)', $post_id ) );
				continue;
			}
			if ( $cover_only ) { $worker->import_cover_only( (array) $p, $post_id ); }
			else               { $worker->import_media( (array) $p, $post_id, true ); }
			if ( get_post_thumbnail_id( $post_id ) ) { $done++; } else { $errors++; }
		}

		self::tally_batch( $run_id, $batch_no, $done, $no_source + $errors );
		M24_Logger::info( 'shopware-import', sprintf(
			'Resync-Batch %d fertig: %d Bild(er) gesetzt, %d Quelle fehlt, %d Fehler (run %s)',
			$batch_no, $done, $no_source, $errors, $run_id
		) );
	}

	/** Batch-Hydrierung fehlgeschlagen → bis MAX_ATTEMPTS verzoegert neu einreihen. */
	private static function handle_batch_failure( $ids, $force, $run_id, $batch_no, $attempt, $msg ) {
		if ( $attempt < self::MAX_ATTEMPTS && self::as_available() && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + self::RETRY_DELAY,
				self::HOOK,
				array( $ids, (bool) $force, $run_id, (int) $batch_no, (int) $attempt + 1 ),
				self::GROUP
			);
			M24_Logger::warning( 'shopware-import', sprintf(
				'Batch %d Hydrierung fehlgeschlagen (Versuch %d/%d), Re-Enqueue in %ds: %s',
				$batch_no, $attempt, self::MAX_ATTEMPTS, self::RETRY_DELAY, $msg
			) );
		} else {
			self::tally_batch( $run_id, $batch_no, 0, count( $ids ) );
			M24_Logger::error( 'shopware-import', sprintf(
				'Batch %d endgueltig fehlgeschlagen nach %d Versuchen: %s', $batch_no, $attempt, $msg
			) );
		}
	}

	/** Fortschritts-Zaehler im Run-Option aktualisieren (idempotent pro batch_no). */
	private static function tally_batch( $run_id, $batch_no, $done, $failed ) {
		$run = get_option( self::OPTION, array() );
		if ( empty( $run ) || ( $run['run_id'] ?? '' ) !== $run_id ) {
			return; // veralteter / fremder Run — Zaehler nicht verfaelschen.
		}
		$done_nos = isset( $run['done_batch_nos'] ) && is_array( $run['done_batch_nos'] ) ? $run['done_batch_nos'] : array();
		if ( in_array( (int) $batch_no, $done_nos, true ) ) {
			return; // Batch schon gezaehlt (Retry) → nicht doppelt zaehlen.
		}
		$done_nos[]              = (int) $batch_no;
		$run['done_batch_nos']   = $done_nos;
		$run['done_products']    = (int) ( $run['done_products']   ?? 0 ) + (int) $done;
		$run['failed_products']  = (int) ( $run['failed_products'] ?? 0 ) + (int) $failed;
		update_option( self::OPTION, $run, false );
	}

	// ----------------------------------------------------------------------
	// Status
	// ----------------------------------------------------------------------

	/** Strukturierter Status: AS-Batch-Zaehler + Run-Option + DB-Produktzahl. */
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
			) as $key => $status ) {
				$as[ $key ] = (int) $store->query_actions( array(
					'hook'   => self::HOOK,
					'group'  => self::GROUP,
					'status' => $status,
				), 'count' );
			}
		}

		return array(
			'run'             => $run,
			'as'              => $as,
			'imported_in_db'  => self::count_imported(),
		);
	}

	/** Anzahl Gebrauchtteile mit Shopware-Herkunft in der DB. */
	private static function count_imported() {
		$q = new WP_Query( array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_m24_sw_id', 'compare' => 'EXISTS' ) ),
		) );
		return (int) $q->found_posts;
	}

	// ----------------------------------------------------------------------
	// WP-CLI-Bruecken (nur unter WP-CLI aufgerufen)
	// ----------------------------------------------------------------------

	/** Wird aus `wp m24 import-shopware --queue` UND `wp m24 import-queue` aufgerufen. */
	public static function cli_enqueue( $args, $assoc_args ) {
		$type       = (string) ( $assoc_args['type'] ?? 'gebraucht' );
		$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, (int) $assoc_args['batch-size'] ) : 10;
		$force      = isset( $assoc_args['force'] );

		if ( ! self::as_available() ) {
			WP_CLI::error( 'Action Scheduler nicht verfuegbar. Bitte WP Rocket oder Imagify aktiv lassen (sie bundlen die Bibliothek).' );
		}

		WP_CLI::log( '── M24 Importer · Hintergrund-Queue ──' );
		WP_CLI::log( 'Typ:        ' . $type );
		WP_CLI::log( 'Batch-Size: ' . $batch_size );
		WP_CLI::log( 'Force:      ' . ( $force ? 'ja' : 'nein' ) );
		WP_CLI::log( 'Hole Produkt-IDs aus Shopware (nur IDs, keine Bilder)…' );

		try {
			$r = self::enqueue( $type, $batch_size, $force );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		WP_CLI::success( sprintf(
			'%d Produkte in %d Batches eingereiht (run %s). Abarbeitung laeuft jetzt ueber WP-Cron.',
			$r['enqueued'], $r['batches'], $r['run_id']
		) );
		WP_CLI::log( 'Fortschritt: wp m24 import-status   (oder Admin → M24 Plattform → Shopware-Import)' );
		WP_CLI::log( 'Antreiben:   wp action-scheduler run   (optional, beschleunigt den Drain)' );
	}

	/** `wp m24 import-status` */
	public static function cli_status( $args, $assoc_args ) {
		$s   = self::status();
		$run = $s['run'];

		WP_CLI::log( '── Shopware-Import · Status ──' );
		if ( empty( $run ) ) {
			WP_CLI::log( 'Kein Import-Run registriert. Starte mit: wp m24 import-shopware --queue' );
		} else {
			WP_CLI::log( 'Run:         ' . ( $run['run_id'] ?? '—' ) . '  (gestartet ' . ( $run['started_at'] ?? '—' ) . ')' );
			WP_CLI::log( 'Eingereiht:  ' . ( (int) ( $run['enqueued'] ?? 0 ) ) . ' Produkte / ' . ( (int) ( $run['batches'] ?? 0 ) ) . ' Batches (Groesse ' . ( (int) ( $run['batch_size'] ?? 0 ) ) . ')' );
			WP_CLI::log( 'Verarbeitet: ' . ( (int) ( $run['done_products'] ?? 0 ) ) . ' ok · ' . ( (int) ( $run['failed_products'] ?? 0 ) ) . ' Fehler' );
		}

		$as = $s['as'];
		WP_CLI::log( '' );
		WP_CLI::log( 'Action-Scheduler-Batches:' );
		WP_CLI::log( '  offen:       ' . self::fmt( $as['pending'] ) );
		WP_CLI::log( '  laufend:     ' . self::fmt( $as['running'] ) );
		WP_CLI::log( '  erledigt:    ' . self::fmt( $as['complete'] ) );
		WP_CLI::log( '  fehlgeschl.: ' . self::fmt( $as['failed'] ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Gebrauchtteile mit Shopware-ID in DB: ' . $s['imported_in_db'] );

		$open = (int) $as['pending'] + (int) $as['running'];
		if ( ! empty( $run ) && 0 === $open ) {
			WP_CLI::success( 'Queue leer — Import abgeschlossen.' );
		}
	}

	private static function fmt( $v ) {
		return null === $v ? 'n/a' : (string) (int) $v;
	}
}
