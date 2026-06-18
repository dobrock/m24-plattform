<?php
/**
 * M24 Plattform — Attachment-Rückholung aus Backup-DB (ADD-ONLY · ID-erhaltend)
 * Modul: modules/importer/class-m24-attachment-restore.php
 *
 * Holt die vom Dubletten-Cleanup gelöschten Attachment-DB-Einträge aus einer separaten
 * Backup-DB zurück (eigene $wpdb-Verbindung, NUR lesend) — damit [gallery ids=…]-Galerien
 * wieder rendern. FÜGT NUR HINZU: kein UPDATE, kein DELETE, keine Datei-Operation. Nur
 * Attachments, deren Datei tatsächlich auf der Platte liegt, werden zurückgeschrieben.
 *
 * wp-config: M24_RESTORE_DB, M24_RESTORE_USER, M24_RESTORE_PASS, M24_RESTORE_HOST (localhost:3306),
 *            M24_RESTORE_PREFIX (Default wp_).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Attachment_Restore {

	const TIME_BUDGET = 15.0;
	const CHUNK       = 300; // Datei-Checks/Inserts pro Iteration (Guard kappt zusätzlich)
	const LOG_OPTION  = 'm24_restore_inserted_ids'; // tatsächlich von Execute eingefügte IDs (für Undo)

	/** Separate, NUR-LESEND genutzte Backup-Verbindung (gecacht). null = nicht verfügbar. */
	private static function backup() {
		static $bdb = null; static $tried = false;
		if ( $tried ) { return $bdb; }
		$tried = true;
		if ( ! defined( 'M24_RESTORE_DB' ) || ! defined( 'M24_RESTORE_USER' ) || ! defined( 'M24_RESTORE_PASS' ) ) { return null; }
		$host = defined( 'M24_RESTORE_HOST' ) && M24_RESTORE_HOST ? M24_RESTORE_HOST : 'localhost';
		$conn = new wpdb( M24_RESTORE_USER, M24_RESTORE_PASS, M24_RESTORE_DB, $host );
		$conn->hide_errors();
		if ( (int) $conn->get_var( 'SELECT 1' ) !== 1 ) { return null; }
		$bdb = $conn;
		return $bdb;
	}
	private static function bprefix() {
		return ( defined( 'M24_RESTORE_PREFIX' ) && M24_RESTORE_PREFIX ) ? (string) M24_RESTORE_PREFIX : 'wp_';
	}

	/**
	 * @param bool $execute false = Dry-Run (zählt nur). true = ADD-ONLY-Rückschreibung (gated).
	 * @param int  $offset  Resume in die Liste der fehlenden Attachment-IDs.
	 */
	public static function run( $execute = false, $offset = 0 ) {
		global $wpdb;
		$start = microtime( true );
		@set_time_limit( 0 ); // phpcs:ignore

		$bdb = self::backup();
		if ( ! $bdb ) {
			return array( 'error' => 'Backup-DB nicht verbunden. In wp-config: M24_RESTORE_DB/USER/PASS/HOST (+ optional M24_RESTORE_PREFIX) setzen.' );
		}
		$bpx     = self::bprefix();
		$bposts  = $bpx . 'posts';
		$bmeta   = $bpx . 'postmeta';
		if ( (int) $bdb->get_var( "SELECT COUNT(*) FROM `{$bposts}` WHERE post_type='attachment' LIMIT 1" ) === 0 && '' !== (string) $bdb->last_error ) {
			return array( 'error' => 'Backup-Tabelle ' . $bposts . ' nicht lesbar: ' . $bdb->last_error );
		}

		// Fehlende Attachment-IDs = backup(attachment) MINUS live(alle Posts). IDs sind global, nie wiederverwendet.
		$bids = array_map( 'intval', (array) $bdb->get_col( "SELECT ID FROM `{$bposts}` WHERE post_type='attachment'" ) );
		$live = array();
		foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) as $id ) { $live[ (int) $id ] = true; } // phpcs:ignore WordPress.DB
		$missing = array();
		foreach ( $bids as $id ) { if ( ! isset( $live[ $id ] ) ) { $missing[] = $id; } }
		sort( $missing );
		$miss_total = count( $missing );

		$dir = class_exists( 'M24_Import_Log' ) ? M24_Import_Log::dir() : trailingslashit( wp_upload_dir()['basedir'] ) . 'm24-logs';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		$csv_name = 'attach-restore-' . ( $execute ? 'exec' : 'plan' ) . '-' . gmdate( 'Ymd-His' ) . '.csv';
		$csv_path = trailingslashit( $dir ) . $csv_name;
		$fh = fopen( $csv_path, 'a' ); // phpcs:ignore
		if ( 0 === (int) $offset && $fh ) { fputcsv( $fh, array( 'attachment_id', 'datei', 'datei_status', 'aktion', 'titel' ) ); }

		$vorhanden = 0; $fehlt = 0; $restored = 0; $skip_exists = 0; $err = 0; $resume = 0; $i = (int) $offset;
		$sample = array(); $restored_ids = array();
		while ( $i < $miss_total ) {
			if ( ( microtime( true ) - $start ) >= self::TIME_BUDGET ) { $resume = $i; break; }
			$chunk = array_slice( $missing, $i, self::CHUNK );
			if ( empty( $chunk ) ) { break; }
			$in    = implode( ',', array_map( 'intval', $chunk ) );
			// Backup-Daten für den Chunk in 2 Queries holen (nur lesend).
			$prows = array();
			foreach ( (array) $bdb->get_results( "SELECT * FROM `{$bposts}` WHERE ID IN ($in)" ) as $r ) { $prows[ (int) $r->ID ] = $r; }
			$mfile = array(); $mmeta = array();
			foreach ( (array) $bdb->get_results( "SELECT post_id, meta_key, meta_value FROM `{$bmeta}` WHERE post_id IN ($in) AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata')" ) as $m ) {
				if ( '_wp_attached_file' === $m->meta_key ) { $mfile[ (int) $m->post_id ] = (string) $m->meta_value; }
				else { $mmeta[ (int) $m->post_id ] = (string) $m->meta_value; }
			}
			foreach ( $chunk as $id ) {
				$id   = (int) $id;
				$file = isset( $mfile[ $id ] ) ? $mfile[ $id ] : '';
				$on   = self::file_on_disk( $file );
				$status = $on ? 'datei_vorhanden' : 'datei_fehlt';
				if ( $on ) { $vorhanden++; } else { $fehlt++; }
				if ( count( $sample ) < 12 ) { $sample[] = array( 'id' => $id, 'file' => $file, 'status' => $status ); }
				$aktion = $execute ? '—' : ( $on ? 'würde_rückschreiben' : 'übersprungen (Datei fehlt)' );

				if ( $execute && $on ) {
					try {
						$aktion = self::restore_one( $id, $prows[ $id ] ?? null, $file, $mmeta[ $id ] ?? '' );
						if ( 'restored' === $aktion ) { $restored++; $restored_ids[] = $id; } elseif ( 'exists' === $aktion ) { $skip_exists++; }
					} catch ( Throwable $t ) {
						$err++; $aktion = 'fehler';
						self::log( sprintf( 'restore #%d FEHLER: %s', $id, $t->getMessage() ) );
					}
				}
				if ( $fh ) { fputcsv( $fh, array( $id, $file, $status, $aktion, isset( $prows[ $id ] ) ? $prows[ $id ]->post_title : '' ) ); }
			}
			$i += count( $chunk );
			if ( $execute && ( microtime( true ) - $start ) >= self::TIME_BUDGET ) { $resume = $i; break; }
		}
		if ( $fh ) { fclose( $fh ); }
		if ( $execute && ! empty( $restored_ids ) ) { self::log_inserted( $restored_ids ); } // Undo-Protokoll

		return array(
			'modus'             => $execute ? 'EXECUTE' : 'DRY-RUN',
			'backup'            => M24_RESTORE_DB . '.' . $bpx,
			'fehlende_gesamt'   => $miss_total,
			'geprueft'          => $i,
			'datei_vorhanden'   => $vorhanden,
			'datei_fehlt'       => $fehlt,
			'zurueckgeschrieben'=> $restored,
			'skip_existiert'    => $skip_exists,
			'errors'            => $err,
			'beispiele'         => $sample,
			'csv_pfad'          => $csv_path,
			'csv_name'          => $csv_name,
			'resume_offset'     => $resume,
			'seconds'           => round( microtime( true ) - $start, 1 ),
		);
	}

	/** EINE Attachment-Zeile + Pflicht-Postmeta ADD-ONLY zurückschreiben (Original-ID). */
	private static function restore_one( $id, $row, $file, $meta ) {
		global $wpdb;
		if ( ! $row ) { return 'kein_backup'; }
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d", $id ) ) ) { return 'exists'; } // Skip — kein UPDATE
		$wpdb->insert( $wpdb->posts, array( // phpcs:ignore WordPress.DB
			'ID'                    => $id,
			'post_author'           => (int) $row->post_author,
			'post_date'             => $row->post_date,
			'post_date_gmt'         => $row->post_date_gmt,
			'post_content'          => (string) $row->post_content,
			'post_title'            => (string) $row->post_title,
			'post_excerpt'          => (string) $row->post_excerpt,
			'post_status'           => 'inherit',
			'comment_status'        => (string) $row->comment_status,
			'ping_status'           => (string) $row->ping_status,
			'post_name'             => (string) $row->post_name,
			'post_parent'           => (int) $row->post_parent,
			'guid'                  => (string) $row->guid,
			'menu_order'            => (int) $row->menu_order,
			'post_type'             => 'attachment',
			'post_mime_type'        => (string) $row->post_mime_type,
			'post_modified'         => $row->post_modified,
			'post_modified_gmt'     => $row->post_modified_gmt,
		) );
		if ( '' !== $file ) { add_post_meta( $id, '_wp_attached_file', $file, true ); }
		if ( '' !== $meta ) { update_post_meta( $id, '_wp_attachment_metadata', maybe_unserialize( $meta ) ); }
		clean_post_cache( $id );
		self::log( sprintf( 'restore #%d zurückgeschrieben (%s)', $id, $file ) );
		return 'restored';
	}

	/** Datei auf der Platte? Custom-Uploads-Dir (rennsport-teile-bilder) + Default-uploads. */
	private static function file_on_disk( $file ) {
		if ( '' === $file ) { return false; }
		$up   = wp_upload_dir();
		$cand = array(
			trailingslashit( $up['basedir'] ) . $file,
			trailingslashit( WP_CONTENT_DIR ) . 'uploads/' . $file,
			trailingslashit( WP_CONTENT_DIR ) . 'rennsport-teile-bilder/' . $file,
			ABSPATH . ltrim( $file, '/' ),
		);
		foreach ( $cand as $p ) { if ( @file_exists( $p ) ) { return true; } } // phpcs:ignore
		return false;
	}

	private static function log( $msg ) {
		if ( class_exists( 'M24_Import_Log' ) ) { M24_Import_Log::log( 'restore: ' . $msg ); }
	}

	/* ── Undo-Protokoll ──────────────────────────────────────────────────────── */

	/** Eingefügte IDs ins Log mergen (dedupe, Run-Timestamp). */
	private static function log_inserted( array $ids ) {
		$cur  = self::inserted_ids();
		$set  = array_fill_keys( $cur, true );
		foreach ( $ids as $id ) { $set[ (int) $id ] = true; }
		$all  = array_map( 'intval', array_keys( $set ) ); sort( $all );
		update_option( self::LOG_OPTION, array( 'updated' => current_time( 'mysql' ), 'ids' => $all ), false );
	}
	/** Aktuelle Liste der von Execute eingefügten IDs. */
	public static function inserted_ids() {
		$o = get_option( self::LOG_OPTION, array() );
		return ( is_array( $o ) && isset( $o['ids'] ) && is_array( $o['ids'] ) ) ? array_map( 'intval', $o['ids'] ) : array();
	}
	private static function set_inserted( array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) ); sort( $ids );
		update_option( self::LOG_OPTION, array( 'updated' => current_time( 'mysql' ), 'ids' => $ids ), false );
	}

	/**
	 * UNDO der 0.9.30-Rückholung — DB-only, Dateien BLEIBEN. Entfernt ausschließlich die im
	 * Execute-Log protokollierten Attachment-Zeilen (wp_posts + wp_postmeta), und nur wenn sie
	 * noch unveränderte Attachments sind. NIEMALS wp_delete_attachment() (das löscht Dateien).
	 *
	 * @param bool $execute false = Dry-Run (zählt nur).
	 */
	public static function undo( $execute = false ) {
		global $wpdb;
		$start = microtime( true );
		@set_time_limit( 0 ); // phpcs:ignore
		$ids = self::inserted_ids();
		$log_total = count( $ids );

		$would = 0; $removed = 0; $skip = 0; $resume = 0; $sample = array();
		$remaining = $ids; // wird im Execute um gelöschte gekürzt → resümierbar

		foreach ( $ids as $pos => $id ) {
			if ( ( microtime( true ) - $start ) >= self::TIME_BUDGET ) { $resume = 1; break; }
			$id  = (int) $id;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_status FROM {$wpdb->posts} WHERE ID=%d", $id ) ); // phpcs:ignore WordPress.DB
			// Sicherheitscheck: existiert UND unverändertes Attachment.
			$valid = $row && 'attachment' === $row->post_type && 'inherit' === $row->post_status;
			if ( ! $valid ) {
				$skip++;
				// Nicht (mehr) unser Attachment → aus dem Log nehmen, NICHT anfassen.
				if ( $execute ) { unset( $remaining[ $pos ] ); }
				continue;
			}
			$would++;
			if ( count( $sample ) < 12 ) { $sample[] = $id; }
			if ( $execute ) {
				// DB-only: NUR die Zeilen, KEINE Datei. (wp_delete_attachment ist verboten.)
				$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $id ) ); // phpcs:ignore WordPress.DB
				$wpdb->delete( $wpdb->posts, array( 'ID' => $id ) );          // phpcs:ignore WordPress.DB
				clean_post_cache( $id );
				$removed++;
				unset( $remaining[ $pos ] );
				self::log( sprintf( 'UNDO #%d entfernt (nur DB-Zeile, Datei bleibt)', $id ) );
			}
		}
		if ( $execute ) { self::set_inserted( array_values( $remaining ) ); } // gelöschte/ungültige raus

		return array(
			'modus'            => $execute ? 'UNDO-EXECUTE' : 'UNDO-DRY-RUN',
			'log_gesamt'       => $log_total,
			'wuerde_entfernen' => $would,
			'entfernt'         => $removed,
			'uebersprungen'    => $skip,
			'verbleibend_log'  => $execute ? count( self::inserted_ids() ) : $log_total,
			'beispiele'        => $sample,
			'resume_offset'    => $resume,
			'seconds'          => round( microtime( true ) - $start, 1 ),
		);
	}
}

// ── CLI: wp m24 restore-attachments [--execute] [--offset=N] ──────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'm24 restore-attachments', function ( $args, $assoc ) {
		$execute = ! empty( $assoc['execute'] );
		$offset  = isset( $assoc['offset'] ) ? max( 0, (int) $assoc['offset'] ) : 0;
		if ( $execute && empty( $assoc['yes'] ) ) { WP_CLI::confirm( 'ADD-ONLY: gelöschte Attachments mit vorhandener Datei zurückschreiben?' ); }
		$r = M24_Attachment_Restore::run( $execute, $offset );
		if ( ! empty( $r['error'] ) ) { WP_CLI::error( $r['error'] ); }
		WP_CLI::log( sprintf( '── Attachment-Rückholung [%s] · Backup %s ──', $r['modus'], $r['backup'] ) );
		WP_CLI::log( sprintf( 'Fehlend: %d · Datei vorhanden %d · Datei fehlt %d (geprüft %d)', $r['fehlende_gesamt'], $r['datei_vorhanden'], $r['datei_fehlt'], $r['geprueft'] ) );
		if ( $execute ) { WP_CLI::log( sprintf( 'Zurückgeschrieben: %d · skip(existiert) %d · Fehler %d', $r['zurueckgeschrieben'], $r['skip_existiert'], $r['errors'] ) ); }
		WP_CLI::log( 'CSV: ' . $r['csv_pfad'] );
		if ( (int) $r['resume_offset'] > 0 ) { WP_CLI::warning( 'Fortsetzen: wp m24 restore-attachments' . ( $execute ? ' --execute --yes' : '' ) . ' --offset=' . $r['resume_offset'] ); }
		else { WP_CLI::success( $execute ? 'Rückholung abgeschlossen (ADD-ONLY).' : 'Dry-Run vollständig (nichts geändert).' ); }
	} );

	WP_CLI::add_command( 'm24 restore-attachments-undo', function ( $args, $assoc ) {
		$execute = ! empty( $assoc['execute'] );
		if ( $execute && empty( $assoc['yes'] ) ) { WP_CLI::confirm( 'UNDO entfernt die von der Rückholung eingefügten DB-Zeilen (Dateien bleiben). Fortfahren?' ); }
		$r = M24_Attachment_Restore::undo( $execute );
		WP_CLI::log( sprintf( '── %s ──', $r['modus'] ) );
		WP_CLI::log( sprintf( 'Log: %d · würde entfernen %d · entfernt %d · übersprungen %d · verbleibend %d', $r['log_gesamt'], $r['wuerde_entfernen'], $r['entfernt'], $r['uebersprungen'], $r['verbleibend_log'] ) );
		if ( (int) $r['resume_offset'] > 0 ) { WP_CLI::warning( 'Fortsetzen: wp m24 restore-attachments-undo --execute --yes' ); }
		else { WP_CLI::success( $execute ? 'Undo abgeschlossen (nur DB-Zeilen entfernt, Dateien unangetastet).' : 'Undo-Dry-Run (nichts geändert).' ); }
	} );
}
