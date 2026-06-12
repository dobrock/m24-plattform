<?php
/**
 * M24 Plattform — Media-Resync WP-CLI Command
 * Modul: modules/importer/resync-media-cli.php
 *
 * Laedt FEHLENDE Hauptbilder/Medien importierter Teile erneut von Shopware nach —
 * idempotent (Hash-Dedupe: vorhandene Medien unangetastet, Featured nur wenn leer),
 * NUR-Medien (Titel/Preis/Meta bleiben). Scope = Teile mit `_m24_sw_id` OHNE Featured
 * Image. Wo das Quellbild bei Shopware fehlt, wird das klar gemeldet (bleibt manuell).
 *
 * Usage:
 *   wp m24 resync-media --dry-run                 # nur zaehlen/listen (Scope)
 *   wp m24 resync-media --dry-run --export=fehlende-bilder.csv
 *   wp m24 resync-media --limit=10                # 10 Teile nachladen (Konsolen-Timeout-safe)
 *   wp m24 resync-media                           # bis Default-Limit nachladen
 *   wp m24 resync-media --queue --cover-only      # Hintergrund (System-Cron), nur Hauptbild
 *   wp m24 resync-media --queue --batch-size=2    # Hintergrund, alle fehlenden Medien
 *
 * Flags:
 *   --queue        Statt direkt: in die Action-Scheduler-Resync-Queue einreihen (unbeaufsichtigt
 *                  ueber WP-Cron, Default batch-size 2). Fortschritt: wp m24 import-status.
 *   --cover-only   Nur das Featured Image laden (Galerie ueberspringen) — schnell.
 *   --batch-size=N Produkte pro Hintergrund-Batch (nur mit --queue, Default 2).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

WP_CLI::add_command( 'm24 resync-media', function ( $args, $assoc ) {
	$dry        = ! empty( $assoc['dry-run'] );
	$use_queue  = ! empty( $assoc['queue'] );
	$cover_only = ! empty( $assoc['cover-only'] );
	$limit      = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 25;
	$batch_size = isset( $assoc['batch-size'] ) ? max( 1, (int) $assoc['batch-size'] ) : 2;
	$export     = isset( $assoc['export'] ) ? (string) $assoc['export'] : '';

	// Scope: importierte Teile (sw_id) ohne Featured Image.
	$ids = get_posts( array(
		'post_type'      => 'm24_teil',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array( array( 'key' => '_m24_sw_id', 'compare' => 'EXISTS' ) ),
	) );
	$scope = array(); // post_id => sw_id
	foreach ( $ids as $id ) {
		if ( ! get_post_thumbnail_id( $id ) ) {
			$scope[ $id ] = (string) get_post_meta( $id, '_m24_sw_id', true );
		}
	}

	WP_CLI::log( sprintf( 'Importierte Teile gesamt: %d', count( $ids ) ) );
	WP_CLI::log( sprintf( 'OHNE Featured Image (Scope): %d', count( $scope ) ) );
	if ( empty( $scope ) ) { WP_CLI::success( 'Alle importierten Teile haben ein Hauptbild — nichts zu tun.' ); return; }

	if ( '' !== $export ) {
		$h = fopen( $export, 'w' );
		if ( $h ) {
			fwrite( $h, "\xEF\xBB\xBF" );
			fputcsv( $h, array( 'Post-ID', 'Titel', 'Art.-Nr.', 'sw_id' ), ';' );
			foreach ( $scope as $pid => $sw ) {
				fputcsv( $h, array( $pid, get_the_title( $pid ), get_post_meta( $pid, '_m24_artikelnummer', true ), $sw ), ';' );
			}
			fclose( $h );
			WP_CLI::log( 'CSV geschrieben: ' . $export );
		}
	}

	if ( $dry ) {
		$n = 0;
		foreach ( $scope as $pid => $sw ) {
			if ( $n++ >= 40 ) { WP_CLI::log( sprintf( '  … und %d weitere', count( $scope ) - 40 ) ); break; }
			WP_CLI::log( sprintf( '  #%d %s | sw_id=%s', $pid, get_the_title( $pid ), $sw ) );
		}
		WP_CLI::warning( 'Dry-run: nichts geladen.' );
		return;
	}

	// Hintergrund-Modus: in die AS-Resync-Queue einreihen → System-Cron drainet unbeaufsichtigt.
	if ( $use_queue ) {
		try {
			$r = M24_Shopware_Queue::enqueue_resync( array_values( $scope ), $cover_only, $batch_size );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf(
			'%d Teile in %d Batches eingereiht (Groesse %d%s, run %s). Abarbeitung laeuft unbeaufsichtigt ueber WP-Cron.',
			$r['enqueued'], $r['batches'], $batch_size, $cover_only ? ', cover-only' : '', $r['run_id']
		) );
		WP_CLI::log( 'Fortschritt: wp m24 import-status' );
		return;
	}

	try {
		$client = new M24_Shopware_Client();
	} catch ( Exception $e ) {
		WP_CLI::error( $e->getMessage() );
	}
	$worker = new M24_Shopware_Queue(); // nutzt Trait M24_Shopware_Import_Core

	$todo = array_slice( $scope, 0, $limit, true );

	// Produkte seitenweise hydrieren (mit Medien-Associations).
	$by_id = array();
	foreach ( array_chunk( array_values( $todo ), 25 ) as $chunk ) {
		try {
			foreach ( $client->fetch_products_by_ids( $chunk ) as $p ) {
				$by_id[ (string) ( $p['id'] ?? '' ) ] = $p;
			}
		} catch ( Exception $e ) {
			WP_CLI::warning( 'Shopware-Fetch-Fehler: ' . $e->getMessage() );
		}
	}

	$loaded = 0; $no_source = 0; $not_found = 0; $still = 0;
	foreach ( $todo as $pid => $sw ) {
		$title = get_the_title( $pid );
		if ( ! isset( $by_id[ $sw ] ) ) {
			$not_found++;
			WP_CLI::log( sprintf( '  #%d %s → NICHT in Shopware gefunden (geloescht?)', $pid, $title ) );
			continue;
		}
		$product = $by_id[ $sw ];
		$media   = isset( $product['media'] ) && is_array( $product['media'] ) ? $product['media'] : array();
		if ( empty( $media ) ) {
			$no_source++;
			WP_CLI::warning( sprintf( '  #%d %s → QUELLBILD FEHLT bei Shopware (bleibt manuell)', $pid, $title ) );
			continue;
		}
		if ( $cover_only ) { $worker->import_cover_only( $product, $pid ); }   // nur Featured Image
		else               { $worker->import_media( $product, $pid, true ); } // alle fehlenden Medien
		if ( get_post_thumbnail_id( $pid ) ) {
			$loaded++;
			WP_CLI::log( sprintf( '  #%d %s → Bild geladen ✓', $pid, $title ) );
		} else {
			$still++;
			WP_CLI::warning( sprintf( '  #%d %s → weiterhin ohne Bild (Download fehlgeschlagen?)', $pid, $title ) );
		}
	}

	WP_CLI::log( '' );
	WP_CLI::log( '── Resync-Media Stats ──' );
	WP_CLI::log( sprintf( '  verarbeitet:        %d', count( $todo ) ) );
	WP_CLI::log( sprintf( '  Bild geladen:       %d', $loaded ) );
	WP_CLI::log( sprintf( '  Quellbild fehlt:    %d  (manuell ergaenzen)', $no_source ) );
	WP_CLI::log( sprintf( '  nicht in Shopware:  %d', $not_found ) );
	WP_CLI::log( sprintf( '  weiter ohne Bild:   %d', $still ) );
	$rest = count( $scope ) - count( $todo );
	if ( $rest > 0 ) {
		WP_CLI::log( sprintf( 'Noch %d offen — erneut ausfuehren (z.B. --limit=%d).', $rest, $limit ) );
	}
	WP_CLI::success( 'Media-Resync fertig.' );
} );
