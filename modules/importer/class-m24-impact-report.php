<?php
/**
 * M24 Plattform — Cleanup-Impact-Report (READ-ONLY · Backup↔Live-Diff · alle Post-Typen)
 * Modul: modules/importer/class-m24-impact-report.php
 *
 * Vergleicht eine read-only Scratch-DB (ka_-Backup-Dump, Präfix wp_) gegen Live und listet
 * ALLE durch den Dubletten-Cleanup veränderten Bild-Zuordnungen (m24_teil, Fahrzeug-Inserate,
 * Blog, Seiten) als Reparatur-Landkarte. ÄNDERT NICHTS — einzige Schreiboperation: Report-CSV.
 *
 * Backup-DB-Name: Konstante M24_DEDUP_BACKUP_DB (wp-config) ODER Option m24_dedup_backup_db.
 * Backup-Präfix:  M24_DEDUP_BACKUP_PREFIX / Option m24_dedup_backup_prefix (Default wp_).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Impact_Report {

	const TIME_BUDGET  = 15.0;
	const HEAD_TIMEOUT = 3;

	public static function backup_db() {
		if ( defined( 'M24_DEDUP_BACKUP_DB' ) && M24_DEDUP_BACKUP_DB ) { return (string) M24_DEDUP_BACKUP_DB; }
		return (string) get_option( 'm24_dedup_backup_db', '' );
	}
	public static function backup_prefix() {
		if ( defined( 'M24_DEDUP_BACKUP_PREFIX' ) && M24_DEDUP_BACKUP_PREFIX ) { return (string) M24_DEDUP_BACKUP_PREFIX; }
		return (string) get_option( 'm24_dedup_backup_prefix', 'wp_' );
	}
	private static function ident( $s ) { return (bool) preg_match( '/^[A-Za-z0-9_]+$/', (string) $s ); }

	/**
	 * @param int $offset Resume in die Liste der GELÖSCHTEN Attachments (HEAD-Checks, langsam).
	 * @return array Summary inkl. csv_pfad ODER ['error'=>…].
	 */
	public static function run( $offset = 0 ) {
		global $wpdb;
		$start = microtime( true );
		@set_time_limit( 0 ); // phpcs:ignore

		$bdb = self::backup_db(); $bpx = self::backup_prefix();
		if ( '' === $bdb || ! self::ident( $bdb ) || ! self::ident( $bpx ) ) {
			return array( 'error' => 'Backup-DB nicht konfiguriert. In wp-config: define( "M24_DEDUP_BACKUP_DB", "ka_backup" ); (read-only Scratch-DB, Präfix wp_).' );
		}
		$bposts = "`{$bdb}`.`{$bpx}posts`";
		$bmeta  = "`{$bdb}`.`{$bpx}postmeta`";
		if ( ! $wpdb->get_var( "SHOW TABLES FROM `{$bdb}` LIKE '{$bpx}posts'" ) ) { // phpcs:ignore WordPress.DB
			return array( 'error' => sprintf( 'Backup-Tabelle %s.%sposts nicht gefunden — Dump importiert?', $bdb, $bpx ) );
		}

		$prio = self::high_prio_posts();

		$dir = class_exists( 'M24_Import_Log' ) ? M24_Import_Log::dir() : trailingslashit( wp_upload_dir()['basedir'] ) . 'm24-logs';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		$csv_name = 'impact-report-' . gmdate( 'Ymd-His' ) . '.csv';
		$csv_path = trailingslashit( $dir ) . $csv_name;
		$fh = fopen( $csv_path, 'a' ); // phpcs:ignore
		if ( 0 === (int) $offset && $fh ) {
			fputcsv( $fh, array( 'post_typ', 'post_id', 'titel', 'permalink', 'problem', 'alt_wert', 'neu_wert', 'datei_status', 'prioritaet' ) );
		}

		$by_type = array(); // post_type => betroffene Posts (thumbnail/galerie/content)

		// === Offset 0: schnelle DB-Diffs (Beitragsbild + Galerie + Content) einmal schreiben. ===
		if ( 0 === (int) $offset ) {
			// 2) Beitragsbild (_thumbnail_id) live != backup — alle Post-Typen.
			$rows = $wpdb->get_results(
				"SELECT lp.ID, lp.post_type AS pt, lp.post_title AS t, bm.meta_value AS alt, lm.meta_value AS neu
				 FROM {$wpdb->postmeta} lm
				 JOIN {$bmeta} bm ON bm.post_id=lm.post_id AND bm.meta_key='_thumbnail_id'
				 JOIN {$wpdb->posts} lp ON lp.ID=lm.post_id
				 WHERE lm.meta_key='_thumbnail_id' AND lm.meta_value <> bm.meta_value" ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $r ) {
				$by_type[ $r->pt ] = ( $by_type[ $r->pt ] ?? 0 ) + 1;
				if ( $fh ) { fputcsv( $fh, array( $r->pt, $r->ID, $r->t, get_permalink( $r->ID ), 'beitragsbild', $r->alt, $r->neu, '', isset( $prio[ (int) $r->ID ] ) ? 'manuell überarbeitet — hoch' : '' ) ); }
			}
			// 3a) Galerie (_m24_galerie) live != backup — m24_teil.
			$rows = $wpdb->get_results(
				"SELECT lp.ID, lp.post_type AS pt, lp.post_title AS t, bm.meta_value AS alt, lm.meta_value AS neu
				 FROM {$wpdb->postmeta} lm
				 JOIN {$bmeta} bm ON bm.post_id=lm.post_id AND bm.meta_key='_m24_galerie'
				 JOIN {$wpdb->posts} lp ON lp.ID=lm.post_id
				 WHERE lm.meta_key='_m24_galerie' AND lm.meta_value <> bm.meta_value" ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $r ) {
				$by_type[ $r->pt ] = ( $by_type[ $r->pt ] ?? 0 ) + 1;
				if ( $fh ) { fputcsv( $fh, array( $r->pt, $r->ID, $r->t, get_permalink( $r->ID ), 'galerie', $r->alt, $r->neu, '', isset( $prio[ (int) $r->ID ] ) ? 'manuell überarbeitet — hoch' : '' ) ); }
			}
			// 3b) post_content mit Galerie-/Bild-Referenzen geändert (Fahrzeuge/Blog/Seiten).
			$rows = $wpdb->get_results(
				"SELECT lp.ID, lp.post_type AS pt, lp.post_title AS t
				 FROM {$wpdb->posts} lp
				 JOIN {$bposts} bp ON bp.ID=lp.ID
				 WHERE lp.post_content <> bp.post_content
				 AND ( lp.post_content LIKE '%[gallery%' OR lp.post_content LIKE '%wp-image-%' OR lp.post_content LIKE '%/uploads/%' )" ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $r ) {
				$by_type[ $r->pt ] = ( $by_type[ $r->pt ] ?? 0 ) + 1;
				if ( $fh ) { fputcsv( $fh, array( $r->pt, $r->ID, $r->t, get_permalink( $r->ID ), 'galerie_content', '(content alt)', '(content neu)', '', isset( $prio[ (int) $r->ID ] ) ? 'manuell überarbeitet — hoch' : '' ) ); }
			}
		}

		// === 1) Gelöschte Attachments (backup hat, live fehlt) + Wiederherstellbarkeit (HEAD, chunked). ===
		$deleted = $wpdb->get_results(
			"SELECT b.ID AS id, b.post_title AS t, m.meta_value AS f
			 FROM {$bposts} b
			 LEFT JOIN {$wpdb->posts} l ON l.ID=b.ID
			 LEFT JOIN {$bmeta} m ON m.post_id=b.ID AND m.meta_key='_wp_attached_file'
			 WHERE b.post_type='attachment' AND l.ID IS NULL
			 ORDER BY b.ID" ); // phpcs:ignore WordPress.DB
		$del_total = count( $deleted );
		$up = wp_upload_dir();
		$vorhanden = 0; $nur_photon = 0; $fehlt = 0; $resume = 0; $i = (int) $offset;
		for ( ; $i < $del_total; $i++ ) {
			if ( ( microtime( true ) - $start ) >= self::TIME_BUDGET ) { $resume = $i; break; }
			$d = $deleted[ $i ];
			$file = (string) $d->f;
			$status = self::file_status( $file, $up );
			if ( 'vorhanden' === $status ) { $vorhanden++; } elseif ( 'nur_photon' === $status ) { $nur_photon++; } else { $fehlt++; }
			if ( $fh ) { fputcsv( $fh, array( 'attachment', $d->id, $d->t, '', 'attachment_geloescht', $file, '', $status, '' ) ); }
		}
		if ( $fh ) { fclose( $fh ); }

		return array(
			'backup_db'             => $bdb . '.' . $bpx,
			'betroffene_posts_je_typ' => $by_type, // nur im Offset-0-Aufruf befüllt
			'geloeschte_gesamt'     => $del_total,
			'geprueft'              => $i,
			'vorhanden'             => $vorhanden,
			'nur_photon'            => $nur_photon,
			'fehlt'                 => $fehlt,
			'csv_pfad'              => $csv_path,
			'csv_name'              => $csv_name,
			'resume_offset'         => $resume, // 0 = fertig
			'seconds'               => round( microtime( true ) - $start, 1 ),
		);
	}

	/** Datei am uploads-Pfad? sonst HEAD auf Photon? sonst fehlt. */
	private static function file_status( $file, $up ) {
		if ( '' === $file ) { return 'fehlt'; }
		if ( file_exists( trailingslashit( $up['basedir'] ) . $file ) ) { return 'vorhanden'; }
		$url   = 'https://i0.wp.com/' . preg_replace( '#^https?://#', '', trailingslashit( $up['baseurl'] ) . $file );
		$resp  = wp_remote_head( $url, array( 'timeout' => self::HEAD_TIMEOUT, 'redirection' => 1 ) );
		$code  = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
		return ( 200 === $code ) ? 'nur_photon' : 'fehlt';
	}

	/** Post-IDs mit hoher Priorität: Z4 GT3 + M3 E36 (Term ODER Titel) — heute bewusst gesetzt. */
	private static function high_prio_posts() {
		global $wpdb; $set = array();
		$rows = $wpdb->get_col(
			"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='m24_fahrzeugkat'
			 JOIN {$wpdb->terms} te ON te.term_id=tt.term_id
			 WHERE te.slug LIKE '%z4-gt3%' OR te.slug LIKE '%e36%' OR te.name LIKE '%Z4 GT3%' OR te.name LIKE '%E36%'" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $o ) { $set[ (int) $o ] = true; }
		$rows = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE '%Z4 GT3%' OR post_title LIKE '%E36%'" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $o ) { $set[ (int) $o ] = true; }
		return $set;
	}
}

// ── CLI: wp m24 impact-report [--offset=N] ───────────────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'm24 impact-report', function ( $args, $assoc ) {
		$offset = isset( $assoc['offset'] ) ? max( 0, (int) $assoc['offset'] ) : 0;
		$r = M24_Impact_Report::run( $offset );
		if ( ! empty( $r['error'] ) ) { WP_CLI::error( $r['error'] ); }
		WP_CLI::log( '── Cleanup-Impact-Report (READ-ONLY) · Backup ' . $r['backup_db'] . ' ──' );
		foreach ( (array) $r['betroffene_posts_je_typ'] as $t => $n ) { WP_CLI::log( sprintf( '  betroffen %-14s %d', $t, $n ) ); }
		WP_CLI::log( sprintf( 'Gelöschte Attachments: %d · davon vorhanden %d · nur_photon %d · fehlt %d (geprüft %d)',
			$r['geloeschte_gesamt'], $r['vorhanden'], $r['nur_photon'], $r['fehlt'], $r['geprueft'] ) );
		WP_CLI::log( 'CSV: ' . $r['csv_pfad'] );
		if ( (int) $r['resume_offset'] > 0 ) { WP_CLI::warning( 'Fortsetzen: wp m24 impact-report --offset=' . $r['resume_offset'] ); }
		else { WP_CLI::success( 'Impact-Report vollständig (READ-ONLY, nichts geändert).' ); }
	} );
}
