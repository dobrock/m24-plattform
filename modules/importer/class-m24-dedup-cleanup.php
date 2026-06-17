<?php
/**
 * M24 Plattform — Dubletten-Cleanup Phase 2 (Dry-Run default · Hard-Delete gated)
 * Modul: modules/importer/class-m24-dedup-cleanup.php
 *
 * Bereinigt die vom Report 0.9.25 gefundenen Dubletten: Keeper behalten, ALLE Einbindungen
 * der Dubletten auf den Keeper umbiegen, dann NUR nach-Umbiegen-verwaiste Dubletten löschen.
 * Gruppierung + Keeper kommen aus M24_Dedup_Report::analyze() (EINE Quelle, identisch zum Report).
 *
 * DEFAULT = DRY-RUN (ändert nichts, schreibt Plan-CSV). EXECUTE nur mit explizitem Confirm.
 * E36-Gruppen werden im Execute AUSGESCHLOSSEN. „Bild folgt"-Platzhalter (an 0 Teilen) eigener Block.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Dedup_Cleanup {

	const TIME_BUDGET = 20.0;
	const DELETE_CAP  = 40; // wp_delete_attachment ist schwer (Datei + alle Größen)

	/**
	 * @param bool $execute false = Dry-Run (Plan, ändert nichts). true = Hard-Delete (gated).
	 * @param int  $offset  Dry-Run: ab welcher Gruppe fortsetzen. Execute: ignoriert (idempotent).
	 */
	public static function run( $execute = false, $offset = 0 ) {
		$start = microtime( true );
		@set_time_limit( 0 ); // phpcs:ignore
		$analysis = M24_Dedup_Report::analyze();
		$gtotal   = count( $analysis );

		// Referenz-Index EINMAL (3 Queries, bounded) für die Discovery — kein per-Dupe
		// post_content-Scan (das sprengte sonst das 20s-Budget). Recheck im Execute bleibt frisch.
		$members = array();
		foreach ( $analysis as $g ) { foreach ( $g['ids'] as $id ) { $members[ (int) $id ] = true; } }
		$idx = self::build_ref_index( array_keys( $members ) );

		$dir = class_exists( 'M24_Import_Log' ) ? M24_Import_Log::dir() : trailingslashit( wp_upload_dir()['basedir'] ) . 'm24-logs';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
		$csv_name = 'dedup-cleanup-' . ( $execute ? 'exec' : 'plan' ) . '-' . gmdate( 'Ymd-His' ) . '.csv';
		$csv_path = trailingslashit( $dir ) . $csv_name;
		$fh = fopen( $csv_path, 'a' ); // phpcs:ignore
		if ( 0 === (int) $offset && $fh ) {
			fputcsv( $fh, array( 'gruppe_key', 'keeper_id', 'dupe_id', 'aktion', 'feld', 'teil_post_id', 'ergebnis', 'flag' ) );
		}

		$rewire = 0; $delete = 0; $skip_e36 = 0; $skip_ref = 0; $groups_done = 0; $resume = 0; $hit_limit = false;
		$gstart = $execute ? 0 : (int) $offset; // Execute: idempotent von vorn (gelöschte fallen aus analyze())

		for ( $gi = $gstart; $gi < $gtotal; $gi++ ) {
			if ( ( microtime( true ) - $start ) >= self::TIME_BUDGET || ( $execute && $delete >= self::DELETE_CAP ) ) {
				$resume = $execute ? 1 : $gi; $hit_limit = true; break;
			}
			$g = $analysis[ $gi ];
			$groups_done++;
			if ( $g['e36'] ) {
				$skip_e36++;
				if ( $fh ) { fputcsv( $fh, array( $g['key'], $g['keeper'], '', 'skip', '', '', 'übersprungen', 'E36' ) ); }
				continue;
			}
			$keeper = (int) $g['keeper'];
			foreach ( $g['ids'] as $dupe ) {
				$dupe = (int) $dupe;
				if ( $dupe === $keeper ) { continue; }
				$refs = array(
					'thumbnail' => isset( $idx['thumb'][ $dupe ] ) ? $idx['thumb'][ $dupe ] : array(),
					'galerie'   => isset( $idx['gal'][ $dupe ] ) ? $idx['gal'][ $dupe ] : array(),
					'content'   => isset( $idx['content'][ $dupe ] ) ? $idx['content'][ $dupe ] : array(),
				);
				foreach ( array( 'thumbnail', 'galerie', 'content' ) as $feld ) {
					foreach ( $refs[ $feld ] as $pid ) {
						$rewire++;
						if ( $execute ) {
							self::rewire( $feld, (int) $pid, $dupe, $keeper );
							self::log( sprintf( 'rewire #%d→#%d %s · Teil #%d', $dupe, $keeper, $feld, $pid ) );
						}
						if ( $fh ) { fputcsv( $fh, array( $g['key'], $keeper, $dupe, $execute ? 'rewire' : 'would_rewire', $feld, $pid, 'dupe→keeper', '' ) ); }
					}
				}
				if ( $execute ) {
					$still = self::refs( $dupe );
					if ( empty( $still['thumbnail'] ) && empty( $still['galerie'] ) && empty( $still['content'] ) ) {
						wp_delete_attachment( $dupe, true ); $delete++;
						self::log( sprintf( 'DELETE #%d (verwaist nach Umbiegen, Keeper #%d)', $dupe, $keeper ) );
						if ( $fh ) { fputcsv( $fh, array( $g['key'], $keeper, $dupe, 'delete', '', '', 'gelöscht', '' ) ); }
					} else {
						$skip_ref++;
						self::log( sprintf( 'SKIP #%d — noch referenziert, NICHT gelöscht', $dupe ) );
						if ( $fh ) { fputcsv( $fh, array( $g['key'], $keeper, $dupe, 'skip', '', '', 'noch referenziert', 'referenziert' ) ); }
					}
				} else {
					$delete++; // would_delete: nach Umbiegen aller Refs wäre der Dupe verwaist
					if ( $fh ) { fputcsv( $fh, array( $g['key'], $keeper, $dupe, 'would_delete', '', '', 'wäre verwaist', '' ) ); }
				}
			}
		}

		// Platzhalter erst bearbeiten, wenn die Gruppen durch sind (Budget/Cap nicht getroffen).
		$ph = array( 'deleted' => 0, 'skipped' => 0 );
		if ( ! $hit_limit ) {
			$ph = self::placeholders( $execute, $fh, $start );
			if ( $execute && $ph['more'] ) { $resume = 1; }
		} elseif ( $execute ) {
			$resume = 1;
		}
		if ( $fh ) { fclose( $fh ); }

		return array(
			'modus'                 => $execute ? 'EXECUTE' : 'DRY-RUN',
			'gruppen_gesamt'        => $gtotal,
			'gruppen_verarbeitet'   => $groups_done + ( $execute ? 0 : (int) $offset ),
			'rewire'                => $rewire,   // execute: umgebogen · dry: would_rewire
			'delete'                => $delete,   // execute: gelöscht · dry: would_delete
			'skip_e36'              => $skip_e36,
			'skip_referenziert'     => $skip_ref,
			'platzhalter_geloescht' => $ph['deleted'],
			'platzhalter_skip_ref'  => $ph['skipped'],
			'csv_pfad'              => $csv_path,
			'csv_name'              => $csv_name,
			'resume_offset'         => $resume,
			'seconds'               => round( microtime( true ) - $start, 1 ),
		);
	}

	/** Referenz-Index EINMAL aufbauen (3 Queries): att_id => [post_id,…] je Feld. */
	private static function build_ref_index( $att_ids ) {
		global $wpdb; $want = array_flip( array_map( 'intval', (array) $att_ids ) );
		$thumb = array(); $gal = array(); $content = array();
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id'" ) as $r ) { // phpcs:ignore WordPress.DB
			$a = (int) $r->meta_value; if ( isset( $want[ $a ] ) ) { $thumb[ $a ][] = (int) $r->post_id; }
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value<>''", M24_Dedup_Report::GAL_KEY ) ) as $r ) { // phpcs:ignore WordPress.DB
			foreach ( array_map( 'intval', explode( ',', (string) $r->meta_value ) ) as $a ) { if ( isset( $want[ $a ] ) ) { $gal[ $a ][] = (int) $r->post_id; } }
		}
		foreach ( $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private') AND post_content LIKE '%wp-image-%'" ) as $r ) { // phpcs:ignore WordPress.DB
			if ( preg_match_all( '/wp-image-(\d+)/', (string) $r->post_content, $mm ) ) {
				foreach ( $mm[1] as $a ) { $a = (int) $a; if ( isset( $want[ $a ] ) ) { $content[ $a ][] = (int) $r->ID; } }
			}
		}
		return array( 'thumb' => $thumb, 'gal' => $gal, 'content' => $content );
	}

	/** Alle Referenzen eines Attachments (frisch): thumbnail · galerie (FIND_IN_SET) · content. */
	private static function refs( $att_id ) {
		global $wpdb; $id = (int) $att_id;
		$thumb = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%d", $id ) ); // phpcs:ignore WordPress.DB
		$gal   = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND FIND_IN_SET(%d, meta_value)", M24_Dedup_Report::GAL_KEY, $id ) ); // phpcs:ignore WordPress.DB
		$cont  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", '%wp-image-' . $id . '%' ) ); // phpcs:ignore WordPress.DB
		return array(
			'thumbnail' => array_map( 'intval', (array) $thumb ),
			'galerie'   => array_map( 'intval', (array) $gal ),
			'content'   => array_map( 'intval', (array) $cont ),
		);
	}

	/** Eine Einbindung umbiegen dupe→keeper. */
	private static function rewire( $feld, $pid, $dupe, $keeper ) {
		if ( 'thumbnail' === $feld ) {
			update_post_meta( $pid, '_thumbnail_id', $keeper );
		} elseif ( 'galerie' === $feld ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $pid, M24_Dedup_Report::GAL_KEY, true ) ) ) );
			$out = array();
			foreach ( $ids as $x ) { $v = ( $x === $dupe ) ? $keeper : $x; if ( ! in_array( $v, $out, true ) ) { $out[] = $v; } } // Keeper nur EINMAL, Reihenfolge erhalten
			update_post_meta( $pid, M24_Dedup_Report::GAL_KEY, implode( ',', $out ) );
		} elseif ( 'content' === $feld ) {
			$post = get_post( $pid ); if ( ! $post ) { return; }
			$c  = str_replace( 'wp-image-' . $dupe, 'wp-image-' . $keeper, (string) $post->post_content );
			$du = wp_get_attachment_url( $dupe ); $ku = wp_get_attachment_url( $keeper );
			if ( $du && $ku ) { $c = str_replace( $du, $ku, $c ); }
			if ( $c !== $post->post_content ) { wp_update_post( array( 'ID' => $pid, 'post_content' => $c ) ); }
		}
	}

	/** „Bild folgt"-Platzhalter: an 0 Teilen → 0 Refs verifizieren, dann löschen. Kein Umbiegen. */
	private static function placeholders( $execute, $fh, $start ) {
		$ids = M24_Dedup_Report::placeholder_ids();
		$del = 0; $skip = 0; $more = false;
		foreach ( $ids as $pid ) {
			if ( $execute && ( ( microtime( true ) - $start ) >= self::TIME_BUDGET || $del >= self::DELETE_CAP ) ) { $more = true; break; }
			$r = self::refs( (int) $pid );
			$orphan = empty( $r['thumbnail'] ) && empty( $r['galerie'] ) && empty( $r['content'] );
			if ( $orphan ) {
				if ( $execute ) { wp_delete_attachment( (int) $pid, true ); self::log( sprintf( 'DELETE Platzhalter #%d (0 Refs)', $pid ) ); }
				$del++;
				if ( $fh ) { fputcsv( $fh, array( 'platzhalter', '', $pid, $execute ? 'delete' : 'would_delete', '', '', $execute ? 'gelöscht' : 'wäre löschbar', 'platzhalter' ) ); }
			} else {
				$skip++;
				if ( $fh ) { fputcsv( $fh, array( 'platzhalter', '', $pid, 'skip', '', '', 'noch referenziert', 'platzhalter|referenziert' ) ); }
			}
		}
		return array( 'deleted' => $del, 'skipped' => $skip, 'more' => $more );
	}

	private static function log( $msg ) {
		if ( class_exists( 'M24_Import_Log' ) ) { M24_Import_Log::log( 'cleanup: ' . $msg ); }
	}
}

// ── CLI: wp m24 dedup-cleanup [--execute] [--offset=N] ────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'm24 dedup-cleanup', function ( $args, $assoc ) {
		$execute = ! empty( $assoc['execute'] );
		$offset  = isset( $assoc['offset'] ) ? max( 0, (int) $assoc['offset'] ) : 0;
		if ( $execute && empty( $assoc['yes'] ) ) {
			WP_CLI::confirm( 'EXECUTE löscht Attachments ENDGÜLTIG (nur nach-Umbiegen-verwaiste). Backup vorhanden?' );
		}
		$r = M24_Dedup_Cleanup::run( $execute, $offset );
		WP_CLI::log( sprintf( '── Dedup-Cleanup [%s] ──', $r['modus'] ) );
		WP_CLI::log( sprintf( 'Gruppen %d/%d · %s %d · %s %d · skip E36 %d · skip referenziert %d',
			$r['gruppen_verarbeitet'], $r['gruppen_gesamt'],
			$execute ? 'umgebogen' : 'would_rewire', $r['rewire'],
			$execute ? 'gelöscht' : 'would_delete', $r['delete'],
			$r['skip_e36'], $r['skip_referenziert'] ) );
		WP_CLI::log( sprintf( 'Platzhalter %s %d · skip(ref) %d', $execute ? 'gelöscht' : 'löschbar', $r['platzhalter_geloescht'], $r['platzhalter_skip_ref'] ) );
		WP_CLI::log( 'CSV: ' . $r['csv_pfad'] );
		if ( (int) $r['resume_offset'] > 0 ) { WP_CLI::warning( 'Fortsetzen: wp m24 dedup-cleanup' . ( $execute ? ' --execute --yes' : ' --offset=' . $r['resume_offset'] ) ); }
		else { WP_CLI::success( $execute ? 'Cleanup abgeschlossen.' : 'Dry-Run-Plan vollständig (nichts geändert).' ); }
	} );
}
