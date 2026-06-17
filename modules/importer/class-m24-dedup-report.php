<?php
/**
 * M24 Plattform — Bild-Dubletten-Report (READ-ONLY, Dry-Run)
 * Modul: modules/importer/class-m24-dedup-report.php
 *
 * Gruppiert Attachment-Dubletten, bestimmt je Gruppe einen Keeper (eingebunden/ältestes)
 * und listet alle Dubletten + Einbindung. ÄNDERT/LÖSCHT NICHTS — einzige Schreiboperation
 * ist die Report-CSV in uploads/m24-logs/. Hard-Delete = separate Phase 2 (von Daniel).
 *
 * Dedup-Schlüssel (Priorität):
 *   1. _m24_sw_media_hash  (exakte Shopware-Media-Quelle, vom Importer gesetzt)
 *   2. Fallback: normalisierter Dateinamen-Stamm aus _wp_attached_file (nur Attachments OHNE Hash)
 *   3. SHA1-Filehash NUR per --deep=<gruppe> auf EINE Gruppe (Disk-IO, nicht im Vollreport)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Dedup_Report {

	const SRC_KEY     = '_m24_sw_media_hash'; // Schritt 0: verifizierter Importer-Quell-Key
	const GAL_KEY     = '_m24_galerie';
	const TIME_BUDGET = 20.0;

	/**
	 * Vollreport (read-only). $offset = ab welcher Gruppe fortsetzen (Resume bei 20s-Netz).
	 * @return array Summary inkl. csv_pfad.
	 */
	public static function run( $offset = 0, $deep = '' ) {
		global $wpdb;
		$start = microtime( true );
		@set_time_limit( 0 ); // phpcs:ignore — read-only, aber wir kappen selbst per Budget

		if ( '' !== (string) $deep ) { return self::deep_group( (string) $deep ); }

		$total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='attachment'" ); // phpcs:ignore WordPress.DB

		// group_concat-Limit hochsetzen (große Gruppen) — Session-only, read-only.
		$wpdb->query( 'SET SESSION group_concat_max_len = 1000000' ); // phpcs:ignore WordPress.DB

		// 1) Hash-Gruppen (exakte Quelle) — rein SQL, kein WP_Post-Laden.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_value AS k, COUNT(*) AS n, GROUP_CONCAT(post_id ORDER BY post_id) AS ids
			 FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value<>''
			 GROUP BY meta_value HAVING n>1 ORDER BY n DESC", self::SRC_KEY
		) ); // phpcs:ignore WordPress.DB
		$groups = array(); $hash_member_ids = array();
		foreach ( (array) $rows as $r ) {
			$ids = array_map( 'intval', explode( ',', (string) $r->ids ) );
			$groups[] = array( 'key' => 'hash:' . $r->k, 'ids' => $ids );
			foreach ( $ids as $id ) { $hash_member_ids[ $id ] = true; }
		}

		// 2) Dateinamen-Fallback: Attachments OHNE Hash, gruppiert nach normalisiertem Stamm.
		$files = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id AS id, pm.meta_value AS f
			 FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->postmeta} h ON h.post_id=pm.post_id AND h.meta_key=%s
			 WHERE pm.meta_key='_wp_attached_file' AND ( h.meta_value IS NULL OR h.meta_value='' )", self::SRC_KEY
		) ); // phpcs:ignore WordPress.DB
		$by_stem = array();
		foreach ( (array) $files as $f ) {
			$id = (int) $f->id;
			if ( isset( $hash_member_ids[ $id ] ) ) { continue; }
			$stem = self::normalize_stem( (string) $f->f );
			if ( '' === $stem ) { continue; }
			$by_stem[ $stem ][] = $id;
		}
		foreach ( $by_stem as $stem => $ids ) {
			if ( count( $ids ) > 1 ) { sort( $ids ); $groups[] = array( 'key' => 'file:' . $stem, 'ids' => $ids ); }
		}

		// Einbindungs-Maps (einmalig, bounded) über ALLE Gruppen-Mitglieder.
		$all_ids = array();
		foreach ( $groups as $g ) { foreach ( $g['ids'] as $id ) { $all_ids[ $id ] = true; } }
		$thumb_map   = self::thumbnail_map( array_keys( $all_ids ) ); // att_id => [teil_id,...]
		$gal_map     = self::gallery_map( array_keys( $all_ids ) );   // att_id => [teil_id,...]
		$content_map = self::content_map( $all_ids );                 // att_id => teil_id (1 Query statt N LIKE)
		$e36_teile   = self::e36_teil_ids();                          // teil_id => true

		// CSV vorbereiten.
		$dir = ( class_exists( 'M24_Import_Log' ) ? M24_Import_Log::dir() : trailingslashit( wp_upload_dir()['basedir'] ) . 'm24-logs' );
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		$csv_name = 'dedup-report-' . gmdate( 'Ymd-His' ) . '.csv';
		$csv_path = trailingslashit( $dir ) . $csv_name;
		$fh = fopen( $csv_path, 'a' ); // phpcs:ignore — Report-Datei, einzige Schreiboperation
		if ( 0 === (int) $offset && $fh ) {
			fputcsv( $fh, array( 'gruppe_key', 'keeper_id', 'dupe_id', 'dupe_eingebunden', 'eingebunden_als', 'teil_post_id', 'teil_titel', 'flag' ) );
		}

		$ueberschuss = 0; $eingebunden = 0; $verwaist = 0; $processed = 0; $resume = 0;
		$gtotal = count( $groups );
		for ( $gi = (int) $offset; $gi < $gtotal; $gi++ ) {
			if ( ( microtime( true ) - $start ) >= self::TIME_BUDGET ) { $resume = $gi; break; }
			$g   = $groups[ $gi ];
			$ids = $g['ids'];
			$ueberschuss += count( $ids ) - 1;

			// Einbindung je Mitglied bestimmen.
			$emb = array(); // id => ['als'=>thumbnail|galerie|content, 'teile'=>[id,...]]
			foreach ( $ids as $id ) {
				$teile = array(); $als = '';
				if ( ! empty( $thumb_map[ $id ] ) ) { $als = 'thumbnail'; $teile = $thumb_map[ $id ]; }
				elseif ( ! empty( $gal_map[ $id ] ) ) { $als = 'galerie'; $teile = $gal_map[ $id ]; }
				elseif ( ! empty( $content_map[ $id ] ) ) { $als = 'content'; $teile = array( $content_map[ $id ] ); }
				if ( '' !== $als ) { $emb[ $id ] = array( 'als' => $als, 'teile' => $teile ); }
			}

			// Keeper: bevorzugt eingebundenes, sonst niedrigste ID. Mehrere eingebunden → niedrigste.
			$embedded_ids = array_keys( $emb );
			sort( $embedded_ids );
			$is_orphan = empty( $embedded_ids );
			$keeper = $is_orphan ? min( $ids ) : $embedded_ids[0];
			if ( $is_orphan ) { $verwaist++; }

			foreach ( $ids as $id ) {
				if ( $id === $keeper ) { continue; }
				$emb_here = isset( $emb[ $id ] );
				if ( $emb_here ) { $eingebunden++; }
				$teile = $emb_here ? $emb[ $id ]['teile'] : array( 0 );
				$als   = $emb_here ? $emb[ $id ]['als'] : '';
				foreach ( $teile as $tid ) {
					$flag = array();
					if ( $is_orphan ) { $flag[] = 'verwaist'; }
					if ( $tid && isset( $e36_teile[ $tid ] ) ) { $flag[] = 'E36'; }
					if ( $fh ) {
						fputcsv( $fh, array(
							$g['key'], $keeper, $id, $emb_here ? 'ja' : 'nein', $als,
							$tid ?: '', $tid ? get_the_title( $tid ) : '', implode( '|', $flag ),
						) );
					}
				}
			}
			$processed++;
		}
		if ( $fh ) { fclose( $fh ); }

		// "Bild folgt"-Platzhalter — separater Block (nur listen).
		$ph = self::placeholder_block();

		return array(
			'total_attachments'    => $total,
			'dedup_gruppen'        => $gtotal,
			'gruppen_verarbeitet'  => $processed + (int) $offset,
			'dubletten_ueberschuss'=> $ueberschuss,
			'davon_eingebunden'    => $eingebunden,
			'davon_verwaist'       => $verwaist,
			'platzhalter_attachments' => $ph['count'],
			'platzhalter_an_teile' => $ph['teile'],
			'csv_pfad'             => $csv_path,
			'csv_name'             => $csv_name,
			'resume_offset'        => $resume, // 0 = fertig, sonst hier fortsetzen
			'seconds'              => round( microtime( true ) - $start, 1 ),
		);
	}

	/** Dateinamen-Stamm normalisieren: Pfad/Endung weg, -scaled, Größen -WxH, WP-Suffix -N. */
	private static function normalize_stem( $file ) {
		$b = strtolower( basename( (string) $file ) );
		$b = preg_replace( '/\.[a-z0-9]+$/', '', $b );          // Endung
		$b = preg_replace( '/-scaled$/', '', $b );               // -scaled
		$b = preg_replace( '/-\d+x\d+$/', '', $b );              // Größen-Suffix -WxH
		$b = preg_replace( '/-\d+$/', '', $b );                  // WP-Dedupe -1/-2…
		return trim( (string) $b );
	}

	/** att_id => [teil_id,…] über _thumbnail_id. */
	private static function thumbnail_map( array $att_ids ) {
		global $wpdb; $map = array();
		if ( empty( $att_ids ) ) { return $map; }
		$in = implode( ',', array_map( 'intval', $att_ids ) );
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value IN ($in)" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) { $map[ (int) $r->meta_value ][] = (int) $r->post_id; }
		return $map;
	}

	/** att_id => [teil_id,…] über _m24_galerie (CSV). Bounded über alle Galerie-Posts. */
	private static function gallery_map( array $att_ids ) {
		global $wpdb; $map = array();
		if ( empty( $att_ids ) ) { return $map; }
		$want = array_flip( array_map( 'intval', $att_ids ) );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value<>''", self::GAL_KEY ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			foreach ( array_map( 'intval', explode( ',', (string) $r->meta_value ) ) as $aid ) {
				if ( isset( $want[ $aid ] ) ) { $map[ $aid ][] = (int) $r->post_id; }
			}
		}
		return $map;
	}

	/**
	 * att_id => erste Post-ID, die es per wp-image-{id} im post_content einbindet.
	 * EINE Query (Posts mit 'wp-image-') + Regex statt N LIKE-Scans → 30s-sicher.
	 */
	private static function content_map( array $want ) {
		global $wpdb; $map = array();
		if ( empty( $want ) ) { return $map; }
		$rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_content LIKE '%wp-image-%'" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			if ( preg_match_all( '/wp-image-(\d+)/', (string) $r->post_content, $mm ) ) {
				foreach ( $mm[1] as $aid ) {
					$aid = (int) $aid;
					if ( isset( $want[ $aid ] ) && ! isset( $map[ $aid ] ) ) { $map[ $aid ] = (int) $r->ID; }
				}
			}
		}
		return $map;
	}

	/** Teil-IDs mit E36-Modell-Term (Phase-2-Sonderbehandlung). */
	private static function e36_teil_ids() {
		global $wpdb; $set = array();
		$rows = $wpdb->get_col(
			"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='m24_fahrzeugkat'
			 JOIN {$wpdb->terms} te ON te.term_id=tt.term_id
			 WHERE te.slug LIKE '%e36%' OR te.name LIKE '%E36%'" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $o ) { $set[ (int) $o ] = true; }
		return $set;
	}

	/** „Bild folgt"-Platzhalter zählen + an wie viele Teile sie als Featured hängen. */
	private static function placeholder_block() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} f ON f.post_id=p.ID AND f.meta_key='_wp_attached_file'
			 WHERE p.post_type='attachment'
			 AND ( p.post_title LIKE 'bild%folgt%' OR f.meta_value LIKE '%bild-folgt%' )" ); // phpcs:ignore WordPress.DB
		$ids = array_map( 'intval', (array) $ids );
		if ( empty( $ids ) ) { return array( 'count' => 0, 'teile' => 0 ); }
		$in = implode( ',', $ids );
		$teile = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value IN ($in)" ); // phpcs:ignore WordPress.DB
		return array( 'count' => count( $ids ), 'teile' => $teile );
	}

	/** --deep: SHA1-Filehash der Mitglieder EINER Gruppe (Disk-IO, nur auf Anforderung). */
	private static function deep_group( $group_key ) {
		global $wpdb; $out = array( 'gruppe' => $group_key, 'dateien' => array() );
		$ids = array();
		if ( 0 === strpos( $group_key, 'hash:' ) ) {
			$h = substr( $group_key, 5 );
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s", self::SRC_KEY, $h ) ) ); // phpcs:ignore WordPress.DB
		}
		$base = wp_upload_dir()['basedir'];
		foreach ( $ids as $id ) {
			$rel = (string) get_post_meta( $id, '_wp_attached_file', true );
			$path = trailingslashit( $base ) . $rel;
			$out['dateien'][] = array( 'id' => $id, 'sha1' => is_readable( $path ) ? sha1_file( $path ) : '(unlesbar)', 'file' => $rel );
		}
		return $out;
	}
}

// ── CLI: wp m24 dedup-report [--deep=<gruppe>] [--offset=N] ───────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'm24 dedup-report', function ( $args, $assoc ) {
		$deep   = isset( $assoc['deep'] ) ? (string) $assoc['deep'] : '';
		$offset = isset( $assoc['offset'] ) ? max( 0, (int) $assoc['offset'] ) : 0;
		$r = M24_Dedup_Report::run( $offset, $deep );
		if ( '' !== $deep ) { WP_CLI::log( wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); return; }
		WP_CLI::log( '── Bild-Dubletten-Report (READ-ONLY) ──' );
		WP_CLI::log( sprintf( 'Attachments gesamt:    %d', $r['total_attachments'] ) );
		WP_CLI::log( sprintf( 'Dedup-Gruppen:         %d', $r['dedup_gruppen'] ) );
		WP_CLI::log( sprintf( 'Dubletten-Überschuss:  %d  (eingebunden %d · verwaiste Gruppen %d)', $r['dubletten_ueberschuss'], $r['davon_eingebunden'], $r['davon_verwaist'] ) );
		WP_CLI::log( sprintf( '„Bild folgt":          %d Attachments an %d Teilen', $r['platzhalter_attachments'], $r['platzhalter_an_teile'] ) );
		WP_CLI::log( sprintf( 'CSV:                   %s', $r['csv_pfad'] ) );
		if ( (int) $r['resume_offset'] > 0 ) {
			WP_CLI::warning( sprintf( '20s-Budget erreicht — fortsetzen: wp m24 dedup-report --offset=%d', $r['resume_offset'] ) );
		} else {
			WP_CLI::success( 'Report vollständig. READ-ONLY — nichts geändert.' );
		}
	} );
}
