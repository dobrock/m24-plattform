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
		add_action( 'admin_post_m24_gallery_restore_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_m24_gallery_restore_undo', array( __CLASS__, 'handle_undo' ) );
	}

	public static function handle_apply() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.' ); }
		check_admin_referer( 'm24_gallery_restore_apply' );
		$r = self::apply_restore();
		$msg = ! empty( $r['error'] ) ? 'err:' . rawurlencode( $r['error'] ) : 'applied:' . (int) $r['posts'] . ':' . (int) $r['ids'];
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'post_type' => 'm24_teil', 'view' => 'backup', 'm24msg' => $msg ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function handle_undo() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.' ); }
		check_admin_referer( 'm24_gallery_restore_undo' );
		$r = self::undo_restore();
		$msg = ! empty( $r['empty'] ) ? 'undo_empty' : 'undone:' . (int) $r['posts'] . ':' . (int) $r['entries'];
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'post_type' => 'm24_teil', 'view' => 'backup', 'm24msg' => $msg ), admin_url( 'edit.php' ) ) );
		exit;
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
			<?php self::msg_banner(); ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=m24_gallery_audit_csv' ), 'm24_gallery_audit_csv' ) ); ?>">CSV exportieren</a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'post_type' => 'm24_teil', 'view' => 'backup' ), admin_url( 'edit.php' ) ) ); ?>">Backup-Meta-Quote / Dry-Run</a>
			</p>

			<?php if ( 'backup' === ( $_GET['view'] ?? '' ) ) { self::render_backup_section(); } // phpcs:ignore WordPress.Security.NonceVerification ?>

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

	/** Erfolgs-/Fehler-Banner aus ?m24msg=. */
	private static function msg_banner() {
		$m = isset( $_GET['m24msg'] ) ? sanitize_text_field( wp_unslash( $_GET['m24msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $m ) { return; }
		$parts = explode( ':', $m );
		$txt = ''; $cls = 'notice-success';
		if ( 'applied' === ( $parts[0] ?? '' ) ) { $txt = sprintf( '✓ Wiederherstellung angewendet: %d Posts, %d Bild-IDs geschrieben. „Undo" möglich.', (int) ( $parts[1] ?? 0 ), (int) ( $parts[2] ?? 0 ) ); }
		elseif ( 'undone' === ( $parts[0] ?? '' ) ) { $txt = sprintf( '✓ Undo ausgeführt: %d Posts auf den Vor-Stand zurückgesetzt.', (int) ( $parts[1] ?? 0 ) ); }
		elseif ( 'undo_empty' === $m ) { $txt = 'Kein Schreiblauf zum Zurücksetzen vorhanden.'; $cls = 'notice-warning'; }
		elseif ( 'err' === ( $parts[0] ?? '' ) ) { $txt = 'Fehler: ' . rawurldecode( implode( ':', array_slice( $parts, 1 ) ) ); $cls = 'notice-error'; }
		if ( '' !== $txt ) { printf( '<div class="notice %s"><p>%s</p></div>', esc_attr( $cls ), esc_html( $txt ) ); }
	}

	/** Backup-Meta-Quote + Dry-Run-Plan + gated Anwenden/Undo. */
	private static function render_backup_section() {
		$plan = self::compute_restore_plan();
		echo '<hr><h2>Backup-Meta-Quote &amp; Wiederherstellung</h2>';
		if ( ! empty( $plan['error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $plan['error'] ) );
			return;
		}
		$res = self::backup_resolution();
		$s   = $res['summary'];
		printf(
			'<p style="font-size:14px">Backup-Galerie-IDs gesamt: <strong>%d</strong> · auflösbar HEUTE: <strong>%d (%.1f%%)</strong> · davon Datei vorhanden: <strong>%d (%.1f%%)</strong></p>',
			(int) $s['backup_ids'], (int) $s['aufloesbar'], (float) $s['quote_pct'], (int) $s['mit_datei'], (float) $s['quote_datei_pct']
		);
		printf(
			'<p style="font-size:14px"><strong>Dry-Run:</strong> würde <strong>%d</strong> Posts befüllen (nur leere/kaputte Galerien), insgesamt <strong>%d</strong> Bild-IDs schreiben. <em>OK-Posts werden NIE angefasst.</em></p>',
			(int) $plan['posts_write'], (int) $plan['ids_write']
		);

		$undo_store = get_option( self::UNDO_OPTION, array() );
		$has_undo   = ! empty( $undo_store['entries'] ?? array() );
		?>
		<p>
			<?php if ( $plan['ids_write'] > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Wiederherstellung anwenden? Es werden NUR leere/kaputte Galerien betroffener Posts befüllt. Ein Undo-Snapshot wird vorher gespeichert.');">
				<input type="hidden" name="action" value="m24_gallery_restore_apply">
				<?php wp_nonce_field( 'm24_gallery_restore_apply' ); ?>
				<button type="submit" class="button button-primary">Wiederherstellung anwenden (<?php echo (int) $plan['posts_write']; ?> Posts)</button>
			</form>
			<?php endif; ?>
			<?php if ( $has_undo ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px" onsubmit="return confirm('Letzte Wiederherstellung rückgängig machen? Galerie-Meta wird auf den gespeicherten Vor-Stand zurückgesetzt.');">
				<input type="hidden" name="action" value="m24_gallery_restore_undo">
				<?php wp_nonce_field( 'm24_gallery_restore_undo' ); ?>
				<button type="submit" class="button">Undo letzte Wiederherstellung</button>
			</form>
			<?php endif; ?>
		</p>

		<table class="wp-list-table widefat fixed striped" style="margin-top:10px">
			<thead><tr>
				<th style="width:60px">ID</th><th>Titel</th><th style="width:90px">Typ</th><th style="width:90px">Klasse</th>
				<th style="width:90px">Backup-IDs</th><th style="width:90px">auflösbar</th>
				<th style="width:110px">würde schreiben</th><th>nicht auflösbar (Beispiel)</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $plan['plan'] as $e ) :
				$bk = 0; $rs = 0; foreach ( $e['keys'] as $info ) { $bk += $info['backup']; $rs += $info['resolvable']; } ?>
				<tr>
					<td><?php echo (int) $e['id']; ?></td>
					<td><a href="<?php echo esc_url( get_edit_post_link( $e['id'] ) ); ?>"><?php echo esc_html( $e['title'] ); ?></a></td>
					<td><?php echo esc_html( $e['type'] ); ?></td>
					<td><?php echo esc_html( $e['class'] ); ?></td>
					<td><?php echo (int) $bk; ?></td>
					<td><?php echo (int) $rs; ?></td>
					<td><?php echo $e['write_ids'] > 0 ? '<strong style="color:#1a7f37">+' . (int) $e['write_ids'] . '</strong>' : '<span style="color:#9aa0a6">—</span>'; ?></td>
					<td style="font-size:12px;color:#666"><?php echo esc_html( implode( ' · ', $e['unresolved'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p style="color:#6b7077;font-size:12px;margin-top:8px">
			„würde schreiben" = heute auflösbare Backup-Bilder, die in eine aktuell leere/kaputte Galerie geschrieben würden.
			Vor dem Schreiben wird je Key ein Undo-Snapshot (<code><?php echo esc_html( self::PREUNDO_SFX ); ?></code>) gesichert.
			Pflicht-Reihenfolge: Plesk-Snapshot → Anwenden → bei Bedarf Undo.
		</p>
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

	/* ── Backup-Meta-Auflösungsprüfung (READ-ONLY) ───────────────────────────── */

	/** NUR-LESEND genutzte Backup-Verbindung (gleiche wp-config-Konstanten wie der Restore). */
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

	/** Galerie-Meta-Keys je Post-Typ. */
	private static function meta_keys( $type ) {
		return ( 'm24_fahrzeug' === $type )
			? array( '_m24fz_gal_aussen', '_m24fz_gal_innen', '_m24fz_gal_motor', '_m24fz_gal_unterboden' )
			: array( '_m24_galerie' );
	}

	/**
	 * READ-ONLY: für die betroffenen Posts (Audit-Klasse != OK) die Galerie-Meta aus der BACKUP-DB
	 * lesen und prüfen, wie viele der dort referenzierten Attachment-IDs HEUTE auflösen
	 * (Attachment-Post live vorhanden, optional Datei da). Hohe Quote ⇒ selektiver Meta-Restore
	 * (Galerie-Meta aus Backup zurückschreiben) wäre tragfähig. SCHREIBT NICHTS.
	 *
	 * @return array { error?, posts, summary{ posts_geprueft, backup_ids, aufloesbar, mit_datei, quote_pct, quote_datei_pct }, rows[] }
	 */
	public static function backup_resolution() {
		$bdb = self::backup();
		if ( ! $bdb ) {
			return array( 'error' => 'Backup-DB nicht verbunden. In wp-config: M24_RESTORE_DB/USER/PASS/HOST (+ optional M24_RESTORE_PREFIX) setzen.' );
		}
		$bpx   = self::bprefix();
		$bmeta = $bpx . 'postmeta';
		if ( '' !== (string) $bdb->last_error ) { return array( 'error' => 'Backup-Meta-Tabelle nicht lesbar: ' . $bdb->last_error ); }

		global $wpdb;
		$live_att = array();
		foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'" ) as $a ) { $live_att[ (int) $a ] = true; } // phpcs:ignore WordPress.DB

		$audit    = self::run();
		$affected = array_filter( $audit['rows'], static function ( $r ) { return 'OK' !== $r['class']; } );

		$rows = array();
		$tot_ids = 0; $tot_res = 0; $tot_file = 0; $posts_with_backup = 0;
		foreach ( $affected as $r ) {
			$pid  = (int) $r['id'];
			$bids = array();
			foreach ( self::meta_keys( $r['type'] ) as $k ) {
				$mv = $bdb->get_var( $bdb->prepare( "SELECT meta_value FROM `{$bmeta}` WHERE post_id=%d AND meta_key=%s LIMIT 1", $pid, $k ) ); // phpcs:ignore WordPress.DB
				if ( null === $mv || '' === $mv ) { continue; }
				$arr = maybe_unserialize( $mv );
				if ( is_array( $arr ) ) {
					foreach ( $arr as $v ) { $v = (int) $v; if ( $v > 0 ) { $bids[] = $v; } }
				} else {
					foreach ( explode( ',', (string) $mv ) as $v ) { $v = (int) trim( $v ); if ( $v > 0 ) { $bids[] = $v; } }
				}
			}
			$bids = array_values( array_unique( $bids ) );
			if ( empty( $bids ) ) {
				$rows[] = array( 'id' => $pid, 'title' => $r['title'], 'type' => $r['type'], 'class' => $r['class'], 'backup_ids' => 0, 'resolvable' => 0, 'with_file' => 0, 'sample' => array() );
				continue;
			}
			$posts_with_backup++;
			$res = 0; $file = 0; $sample = array();
			foreach ( $bids as $aid ) {
				if ( isset( $live_att[ $aid ] ) ) {
					$res++;
					$path = get_attached_file( $aid );
					if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) { $file++; }
				} elseif ( count( $sample ) < 2 ) {
					$sample[] = $aid;
				}
			}
			$tot_ids += count( $bids ); $tot_res += $res; $tot_file += $file;
			$rows[] = array( 'id' => $pid, 'title' => $r['title'], 'type' => $r['type'], 'class' => $r['class'], 'backup_ids' => count( $bids ), 'resolvable' => $res, 'with_file' => $file, 'sample' => $sample );
		}

		usort( $rows, static function ( $a, $b ) { return $b['backup_ids'] <=> $a['backup_ids']; } );

		return array(
			'posts'   => count( $affected ),
			'rows'    => $rows,
			'summary' => array(
				'posts_geprueft'    => count( $affected ),
				'posts_mit_backup'  => $posts_with_backup,
				'backup_ids'        => $tot_ids,
				'aufloesbar'        => $tot_res,
				'mit_datei'         => $tot_file,
				'quote_pct'         => $tot_ids ? round( $tot_res / $tot_ids * 100, 1 ) : 0.0,
				'quote_datei_pct'   => $tot_ids ? round( $tot_file / $tot_ids * 100, 1 ) : 0.0,
			),
		);
	}

	/* ── Restore-Plan + gated Wiederherstellung (DB-only Meta) ───────────────── */

	const UNDO_OPTION = 'm24_gallery_restore_undo'; // letzter Schreiblauf (für Undo)
	const PREUNDO_SFX = '_preundo';                  // Snapshot-Meta-Suffix je Galerie-Key

	/** IDs aus Galerie-Meta parsen (CSV-String, serialisiertes Array oder Array). */
	private static function parse_ids( $v ) {
		if ( is_array( $v ) ) { $arr = $v; }
		else { $u = maybe_unserialize( $v ); $arr = is_array( $u ) ? $u : explode( ',', (string) $v ); }
		$out = array();
		foreach ( $arr as $x ) { $x = (int) ( is_string( $x ) ? trim( $x ) : $x ); if ( $x > 0 ) { $out[] = $x; } }
		return array_values( array_unique( $out ) );
	}

	/** Live-Galerie-Meta typgerecht schreiben (teil: CSV-String, fahrzeug: Array). */
	private static function write_meta( $pid, $key, $ids ) {
		if ( '_m24_galerie' === $key ) { update_post_meta( $pid, $key, implode( ',', array_map( 'intval', $ids ) ) ); }
		else { update_post_meta( $pid, $key, array_map( 'intval', $ids ) ); }
	}

	/**
	 * Restore-Plan (READ-ONLY): für jeden betroffenen Post je Galerie-Key ermitteln, ob aus dem
	 * Backup geschrieben würde. Regel: NUR wenn der Key aktuell KEIN auflösbares Bild hat
	 * (leer/komplett dangling) UND das Backup auflösbare IDs liefert. OK-Posts sind nie dabei.
	 * Geschrieben würden die HEUTE auflösbaren Backup-IDs (tote Refs fallen weg).
	 */
	public static function compute_restore_plan() {
		$bdb = self::backup();
		if ( ! $bdb ) { return array( 'error' => 'Backup-DB nicht verbunden. In wp-config: M24_RESTORE_DB/USER/PASS/HOST (+ optional M24_RESTORE_PREFIX) setzen.' ); }
		$bpx = self::bprefix(); $bmeta = $bpx . 'postmeta';

		global $wpdb;
		$live_att = array();
		foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment'" ) as $a ) { $live_att[ (int) $a ] = true; } // phpcs:ignore WordPress.DB

		$audit    = self::run();
		$affected = array_filter( $audit['rows'], static function ( $r ) { return 'OK' !== $r['class']; } );

		$plan = array(); $posts_write = 0; $ids_write = 0;
		foreach ( $affected as $r ) {
			$pid = (int) $r['id'];
			$entry = array( 'id' => $pid, 'title' => $r['title'], 'type' => $r['type'], 'class' => $r['class'], 'keys' => array(), 'write_ids' => 0, 'unresolved' => array() );
			foreach ( self::meta_keys( $r['type'] ) as $k ) {
				$mv   = $bdb->get_var( $bdb->prepare( "SELECT meta_value FROM `{$bmeta}` WHERE post_id=%d AND meta_key=%s LIMIT 1", $pid, $k ) ); // phpcs:ignore WordPress.DB
				$bids = self::parse_ids( $mv );
				if ( empty( $bids ) ) { continue; }
				$res = array(); $unres = array();
				foreach ( $bids as $aid ) { if ( isset( $live_att[ $aid ] ) ) { $res[] = $aid; } else { $unres[] = $aid; } }
				$cur     = self::parse_ids( get_post_meta( $pid, $k, true ) );
				$cur_res = 0; foreach ( $cur as $c ) { if ( isset( $live_att[ $c ] ) ) { $cur_res++; } }
				$write   = ( count( $res ) > 0 && 0 === $cur_res ); // nur kaputte/leere Keys füllen
				$entry['keys'][ $k ] = array( 'backup' => count( $bids ), 'resolvable' => count( $res ), 'write' => $write, 'res_ids' => $res );
				if ( $write ) { $entry['write_ids'] += count( $res ); }
				if ( $unres ) { foreach ( array_slice( $unres, 0, 3 ) as $x ) { $entry['unresolved'][] = $x; } }
			}
			if ( ! empty( $entry['keys'] ) ) {
				if ( $entry['write_ids'] > 0 ) { $posts_write++; $ids_write += $entry['write_ids']; }
				$plan[] = $entry;
			}
		}
		usort( $plan, static function ( $a, $b ) { return $b['write_ids'] <=> $a['write_ids']; } );
		return array( 'plan' => $plan, 'posts_write' => $posts_write, 'ids_write' => $ids_write );
	}

	/** Gated Schreiblauf: Plan ausführen, je Key Undo-Snapshot (_preundo) + Undo-Option speichern. */
	public static function apply_restore() {
		$p = self::compute_restore_plan();
		if ( ! empty( $p['error'] ) ) { return $p; }
		$undo = array(); $posts = 0; $ids = 0;
		foreach ( $p['plan'] as $e ) {
			$touched = false;
			foreach ( $e['keys'] as $k => $info ) {
				if ( empty( $info['write'] ) ) { continue; }
				$old = get_post_meta( $e['id'], $k, true );      // aktueller (leerer/kaputter) Stand
				update_post_meta( $e['id'], $k . self::PREUNDO_SFX, $old ); // Snapshot je Key
				self::write_meta( $e['id'], $k, $info['res_ids'] );
				$undo[] = array( 'id' => $e['id'], 'key' => $k, 'old' => $old );
				$ids   += count( $info['res_ids'] );
				$touched = true;
			}
			if ( $touched ) { $posts++; }
		}
		update_option( self::UNDO_OPTION, array( 'when' => gmdate( 'Y-m-d H:i:s' ), 'entries' => $undo ), false );
		return array( 'posts' => $posts, 'ids' => $ids );
	}

	/** Undo des letzten Schreiblaufs: je Key auf den _preundo-Stand zurück, Snapshots entfernen. */
	public static function undo_restore() {
		$store = get_option( self::UNDO_OPTION, array() );
		$entries = isset( $store['entries'] ) ? (array) $store['entries'] : array();
		if ( empty( $entries ) ) { return array( 'posts' => 0, 'ids' => 0, 'empty' => true ); }
		$ids = array();
		foreach ( $entries as $e ) {
			$pid = (int) $e['id']; $k = (string) $e['key']; $old = $e['old'];
			if ( '' === $old || null === $old || array() === $old ) { delete_post_meta( $pid, $k ); }
			else { update_post_meta( $pid, $k, $old ); }
			delete_post_meta( $pid, $k . self::PREUNDO_SFX );
			$ids[ $pid ] = true;
		}
		delete_option( self::UNDO_OPTION );
		return array( 'posts' => count( $ids ), 'entries' => count( $entries ) );
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

	// ── CLI: wp m24 audit-backup-meta [--format=table|csv] — READ-ONLY Backup-Auflösungsquote ──
	WP_CLI::add_command( 'm24 audit-backup-meta', function ( $args, $assoc ) {
		$r = M24_Gallery_Audit::backup_resolution();
		if ( ! empty( $r['error'] ) ) { WP_CLI::error( $r['error'] ); }
		$format = isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table';

		if ( 'csv' === $format ) {
			$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fputcsv( $fh, array( 'post_id', 'titel', 'typ', 'audit_klasse', 'backup_gal_ids', 'aufloesbar_heute', 'mit_datei', 'beispiel_unaufloesbar' ) );
			foreach ( $r['rows'] as $x ) {
				fputcsv( $fh, array( $x['id'], $x['title'], $x['type'], $x['class'], $x['backup_ids'], $x['resolvable'], $x['with_file'], implode( ' | ', $x['sample'] ) ) );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return;
		}

		$s = $r['summary'];
		WP_CLI::log( '── Backup-Meta-Auflösungsprüfung (READ-ONLY · Backup-DB) ──' );
		WP_CLI::log( sprintf( 'Betroffene Posts: %d · mit Galerie-Meta im Backup: %d', $s['posts_geprueft'], $s['posts_mit_backup'] ) );
		WP_CLI::log( sprintf( 'Backup-Galerie-IDs gesamt: %d · auflösbar HEUTE: %d (%.1f%%) · davon Datei vorhanden: %d (%.1f%%)',
			$s['backup_ids'], $s['aufloesbar'], $s['quote_pct'], $s['mit_datei'], $s['quote_datei_pct'] ) );
		$show = array();
		foreach ( $r['rows'] as $x ) {
			if ( 0 === $x['backup_ids'] ) { continue; }
			$show[] = array(
				'id' => $x['id'], 'typ' => $x['type'], 'klasse' => $x['class'],
				'backup_ids' => $x['backup_ids'], 'auflösbar' => $x['resolvable'], 'mit_datei' => $x['with_file'],
				'titel' => mb_substr( $x['title'], 0, 46 ),
			);
		}
		if ( $show ) {
			WP_CLI\Utils\format_items( 'table', $show, array( 'id', 'typ', 'klasse', 'backup_ids', 'auflösbar', 'mit_datei', 'titel' ) );
		}
		if ( $s['quote_pct'] >= 80 ) {
			WP_CLI::success( sprintf( 'Hohe Auflösungsquote (%.1f%%) → selektiver Meta-Restore tragfähig. READ-ONLY, nichts geändert. Nächster Schritt: Plesk-Snapshot → Dry-Run-Schreiblauf zur Freigabe.', $s['quote_pct'] ) );
		} else {
			WP_CLI::warning( sprintf( 'Auflösungsquote %.1f%% — vor Meta-Restore prüfen, ob zusätzlich Attachment-/Datei-Restore nötig ist. READ-ONLY, nichts geändert.', $s['quote_pct'] ) );
		}
	} );
}
