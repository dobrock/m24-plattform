<?php
/**
 * M24 Bilder-/Galerie-Audit — READ-ONLY Diagnose
 * Modul: modules/importer/class-m24-gallery-audit.php
 *
 * Beantwortet: welche Produkte (m24_teil) und Fahrzeuge (m24_fahrzeug) haben nur noch 1 Bild
 * bzw. kaputte Galerien — und in welcher Form. NUR LESEN, keinerlei Schreiboperation.
 *
 * Klassifikation je Post:
 *   OK            ≥2 auflösbare Bilder (Attachment-Post existiert UND Datei auf Platte da)
 *   ONLY_TITLE    nur Titelbild auflösbar; Galerie leer oder komplett dangling  (die „1 Bild"-Fälle)
 *   DANGLING_IDS  Galerie-IDs hinterlegt, aber Attachment-Post fehlt
 *   FILE_MISSING  Attachment-Post da, Datei fehlt (DB restored, Uploads nicht)
 *   EMPTY_META    gar keine Galerie-IDs hinterlegt
 *
 * Quelle der Galerie-IDs:
 *   m24_teil      → _m24_galerie (CSV)
 *   m24_fahrzeug  → _m24fz_gal_aussen|innen|motor|unterboden (je Array)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Gallery_Audit {

	const PAGE  = 'm24-gallery-audit';
	const CAP   = 'manage_options';
	const ORDER = array( 'ONLY_TITLE', 'DANGLING_IDS', 'FILE_MISSING', 'EMPTY_META', 'OK' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_m24_gallery_audit_csv', array( __CLASS__, 'export_csv' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=m24_teil',
			'Bilder-Audit (READ-ONLY)', 'Bilder-Audit',
			self::CAP, self::PAGE, array( __CLASS__, 'render' )
		);
	}

	/* ── Kern: einen Post auswerten ─────────────────────────────────────────── */

	/** Galerie-Roh-IDs eines Posts (typabhängig), in Reihenfolge, dedupliziert. */
	private static function gallery_ids( $post_id, $type ) {
		$ids = array();
		if ( 'm24_fahrzeug' === $type ) {
			foreach ( array( '_m24fz_gal_aussen', '_m24fz_gal_innen', '_m24fz_gal_motor', '_m24fz_gal_unterboden' ) as $k ) {
				foreach ( (array) get_post_meta( $post_id, $k, true ) as $v ) {
					$v = (int) $v; if ( $v > 0 ) { $ids[] = $v; }
				}
			}
		} else { // m24_teil
			$csv = (string) get_post_meta( $post_id, '_m24_galerie', true );
			foreach ( explode( ',', $csv ) as $v ) { $v = (int) trim( $v ); if ( $v > 0 ) { $ids[] = $v; } }
		}
		return array_values( array_unique( $ids ) );
	}

	/** Ist die Attachment-ID auflösbar? ['post'=>bool, 'file'=>bool]. */
	private static function resolve( $aid ) {
		$aid = (int) $aid;
		$is_att = ( $aid > 0 && 'attachment' === get_post_type( $aid ) );
		if ( ! $is_att ) { return array( 'post' => false, 'file' => false ); }
		$path = get_attached_file( $aid ); // Originaldatei laut DB
		$file = ( is_string( $path ) && '' !== $path && file_exists( $path ) );
		return array( 'post' => true, 'file' => $file );
	}

	/**
	 * Einen Post auswerten → Zeile fürs Audit.
	 * @return array { id,title,type,status,has_thumb, gal_ids, resolvable, dangling, file_missing, class, sample[] }
	 */
	public static function audit_post( $post ) {
		$id     = (int) $post->ID;
		$type   = (string) $post->post_type;
		$tid    = (int) get_post_thumbnail_id( $id );
		$thumb  = self::resolve( $tid );
		$has_thumb = ( $tid > 0 && $thumb['post'] && $thumb['file'] );

		$gids   = self::gallery_ids( $id, $type );
		$resolvable = 0; $dangling = 0; $file_missing = 0; $sample = array();
		foreach ( $gids as $aid ) {
			$r = self::resolve( $aid );
			if ( ! $r['post'] ) {
				$dangling++;
				if ( count( $sample ) < 2 ) { $sample[] = $aid . ' (kein Attachment)'; }
			} elseif ( ! $r['file'] ) {
				$file_missing++;
				if ( count( $sample ) < 2 ) { $sample[] = $aid . ' (Datei fehlt)'; }
			} else {
				$resolvable++;
			}
		}

		// Auflösbare Bilder gesamt = Titelbild (wenn auflösbar) + Galerie-auflösbar (Titel-ID nicht doppelt zählen).
		$thumb_extra   = ( $has_thumb && ! in_array( $tid, $gids, true ) ) ? 1 : 0;
		$total_usable  = $resolvable + $thumb_extra;

		// Klassifikation.
		if ( empty( $gids ) ) {
			$class = $has_thumb ? 'ONLY_TITLE' : 'EMPTY_META';
		} elseif ( 0 === $resolvable && $dangling > 0 && 0 === $file_missing ) {
			$class = ( $has_thumb && $total_usable <= 1 ) ? 'ONLY_TITLE' : 'DANGLING_IDS';
		} elseif ( 0 === $resolvable && $file_missing > 0 ) {
			$class = ( $has_thumb && $total_usable <= 1 ) ? 'ONLY_TITLE' : 'FILE_MISSING';
		} elseif ( $total_usable >= 2 ) {
			$class = 'OK';
		} else {
			$class = 'ONLY_TITLE';
		}
		// „Reine" Schadensbilder ohne Titelbild trotzdem korrekt benennen.
		if ( 'OK' !== $class && ! $has_thumb && 0 === $resolvable ) {
			if ( $dangling > 0 && 0 === $file_missing ) { $class = empty( $gids ) ? 'EMPTY_META' : 'DANGLING_IDS'; }
			elseif ( $file_missing > 0 )                { $class = 'FILE_MISSING'; }
		}

		return array(
			'id'           => $id,
			'title'        => get_the_title( $id ),
			'type'         => $type,
			'status'       => (string) $post->post_status,
			'has_thumb'    => $has_thumb ? 1 : 0,
			'gal_count'    => count( $gids ),
			'resolvable'   => $resolvable,
			'dangling'     => $dangling,
			'file_missing' => $file_missing,
			'usable_total' => $total_usable,
			'class'        => $class,
			'sample'       => $sample,
		);
	}

	/* ── Lauf über alle Posts ────────────────────────────────────────────────── */

	/** Komplett-Audit (alle m24_teil + m24_fahrzeug, alle Status inkl. draft). */
	public static function run() {
		$rows    = array();
		$summary = array_fill_keys( self::ORDER, 0 );
		$by_type = array();

		$q = new WP_Query( array(
			'post_type'      => array( 'm24_teil', 'm24_fahrzeug' ),
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'all',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		foreach ( $q->posts as $p ) {
			$row = self::audit_post( $p );
			$rows[] = $row;
			$summary[ $row['class'] ]++;
			$by_type[ $row['type'] ] = ( $by_type[ $row['type'] ] ?? 0 ) + 1;
		}
		wp_reset_postdata();

		// Sortierung nach Schwere (ORDER), innerhalb nach Typ + ID.
		$rank = array_flip( self::ORDER );
		usort( $rows, static function ( $a, $b ) use ( $rank ) {
			$ra = $rank[ $a['class'] ] ?? 99; $rb = $rank[ $b['class'] ] ?? 99;
			if ( $ra !== $rb ) { return $ra <=> $rb; }
			if ( $a['type'] !== $b['type'] ) { return strcmp( $a['type'], $b['type'] ); }
			return $a['id'] <=> $b['id'];
		} );

		$total    = count( $rows );
		$affected = $total - ( $summary['OK'] ?? 0 );
		return array(
			'rows'      => $rows,
			'summary'   => $summary,
			'by_type'   => $by_type,
			'total'     => $total,
			'affected'  => $affected,
		);
	}

	/* ── Admin-Seite ─────────────────────────────────────────────────────────── */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.' ); }
		$res = self::run();
		$badge = array(
			'OK' => '#1a7f37', 'ONLY_TITLE' => '#c0392b', 'DANGLING_IDS' => '#9a6b25',
			'FILE_MISSING' => '#b34700', 'EMPTY_META' => '#6b7077',
		);
		?>
		<div class="wrap">
			<h1>Bilder-/Galerie-Audit <span style="font-size:13px;color:#6b7077">(READ-ONLY · nichts wird geändert)</span></h1>
			<p style="font-size:14px">
				<strong><?php echo (int) $res['affected']; ?></strong> von <strong><?php echo (int) $res['total']; ?></strong>
				Produkten/Fahrzeugen betroffen.
			</p>
			<p>
				<?php foreach ( self::ORDER as $c ) : ?>
					<span style="display:inline-block;margin:0 12px 6px 0">
						<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $badge[ $c ] ); ?>"></span>
						<strong><?php echo esc_html( $c ); ?></strong>: <?php echo (int) $res['summary'][ $c ]; ?>
					</span>
				<?php endforeach; ?>
			</p>
			<p style="color:#6b7077;font-size:13px">
				Geprüft je Typ:
				<?php foreach ( $res['by_type'] as $t => $n ) { echo esc_html( $t . ' = ' . $n . '  ' ); } ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=m24_gallery_audit_csv' ), 'm24_gallery_audit_csv' ) ); ?>">CSV exportieren</a>
			</p>

			<table class="wp-list-table widefat fixed striped" style="margin-top:14px">
				<thead><tr>
					<th style="width:90px">Klasse</th><th style="width:60px">ID</th><th>Titel</th>
					<th style="width:90px">Typ</th><th style="width:70px">Status</th>
					<th style="width:60px">Titelbild</th><th style="width:60px">Gal-IDs</th>
					<th style="width:60px">auflösbar</th><th style="width:60px">dangling</th>
					<th style="width:70px">Datei&nbsp;fehlt</th><th>Beispiel nicht auflösbar</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $res['rows'] as $r ) : if ( 'OK' === $r['class'] ) { continue; } // Tabelle zeigt nur Betroffene ?>
					<tr>
						<td><span style="color:#fff;background:<?php echo esc_attr( $badge[ $r['class'] ] ); ?>;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700"><?php echo esc_html( $r['class'] ); ?></span></td>
						<td><?php echo (int) $r['id']; ?></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['title'] ); ?></a></td>
						<td><?php echo esc_html( $r['type'] ); ?></td>
						<td><?php echo esc_html( $r['status'] ); ?></td>
						<td><?php echo $r['has_thumb'] ? '✓' : '—'; ?></td>
						<td><?php echo (int) $r['gal_count']; ?></td>
						<td><?php echo (int) $r['resolvable']; ?></td>
						<td><?php echo (int) $r['dangling']; ?></td>
						<td><?php echo (int) $r['file_missing']; ?></td>
						<td style="font-size:12px;color:#666"><?php echo esc_html( implode( ' · ', $r['sample'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="color:#6b7077;font-size:12px;margin-top:10px">
				Tabelle zeigt nur betroffene Einträge. „OK" (≥2 auflösbare Bilder) sind im CSV vollständig enthalten.
				WP-CLI: <code>wp m24 audit-galleries --format=csv</code>.
			</p>
		</div>
		<?php
	}

	/* ── CSV-Export ──────────────────────────────────────────────────────────── */

	public static function export_csv() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.' ); }
		check_admin_referer( 'm24_gallery_audit_csv' );
		$res = self::run();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=m24-bilder-audit-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM (Excel)
		fputcsv( $out, array( 'klasse', 'post_id', 'titel', 'typ', 'status', 'titelbild', 'gal_ids', 'aufloesbar', 'dangling', 'datei_fehlt', 'nutzbar_gesamt', 'beispiel_nicht_aufloesbar' ) );
		foreach ( $res['rows'] as $r ) {
			fputcsv( $out, array(
				$r['class'], $r['id'], $r['title'], $r['type'], $r['status'],
				$r['has_thumb'] ? 'ja' : 'nein', $r['gal_count'], $r['resolvable'],
				$r['dangling'], $r['file_missing'], $r['usable_total'], implode( ' | ', $r['sample'] ),
			) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}

// ── CLI: wp m24 audit-galleries [--format=table|csv] ─────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'm24 audit-galleries', function ( $args, $assoc ) {
		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';
		$res    = M24_Gallery_Audit::run();

		if ( 'csv' === $format ) {
			$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fputcsv( $fh, array( 'klasse', 'post_id', 'titel', 'typ', 'status', 'titelbild', 'gal_ids', 'aufloesbar', 'dangling', 'datei_fehlt', 'nutzbar_gesamt', 'beispiel' ) );
			foreach ( $res['rows'] as $r ) {
				fputcsv( $fh, array( $r['class'], $r['id'], $r['title'], $r['type'], $r['status'], $r['has_thumb'] ? 'ja' : 'nein', $r['gal_count'], $r['resolvable'], $r['dangling'], $r['file_missing'], $r['usable_total'], implode( ' | ', $r['sample'] ) ) );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return;
		}

		WP_CLI::log( '── Bilder-/Galerie-Audit (READ-ONLY) ──' );
		WP_CLI::log( sprintf( '%d von %d betroffen.', $res['affected'], $res['total'] ) );
		foreach ( M24_Gallery_Audit::ORDER as $c ) {
			WP_CLI::log( sprintf( '  %-13s %d', $c, (int) $res['summary'][ $c ] ) );
		}
		$show = array();
		foreach ( $res['rows'] as $r ) {
			if ( 'OK' === $r['class'] ) { continue; }
			$show[] = array(
				'klasse' => $r['class'], 'id' => $r['id'], 'typ' => $r['type'], 'status' => $r['status'],
				'titelbild' => $r['has_thumb'] ? 'ja' : 'nein', 'gal' => $r['gal_count'],
				'auflösbar' => $r['resolvable'], 'dangling' => $r['dangling'], 'datei_fehlt' => $r['file_missing'],
				'titel' => mb_substr( $r['title'], 0, 50 ),
			);
		}
		if ( $show ) {
			WP_CLI\Utils\format_items( 'table', $show, array( 'klasse', 'id', 'typ', 'status', 'titelbild', 'gal', 'auflösbar', 'dangling', 'datei_fehlt', 'titel' ) );
		}
		WP_CLI::success( 'Audit vollständig (READ-ONLY, nichts geändert). CSV: --format=csv' );
	} );
}
