<?php
/**
 * M24 Plattform — Admin-Seite „Shopware-Import" (Hintergrund-Queue-Monitor)
 * Modul: admin/class-m24-import-status.php
 *
 * Zeigt den Fortschritt des Hintergrund-Imports OHNE Konsole: eingereiht / erledigt /
 * fehlgeschlagen / offen, plus DB-Produktzahl. Enqueue-Button startet einen Lauf
 * (nur ID-Abruf, < 10 s) — die Abarbeitung uebernimmt der WP-Cron via Action Scheduler.
 *
 * Datenquelle: M24_Shopware_Queue::status().
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Import_Status_Page {

	const PAGE_SLUG  = 'm24-plattform-import';
	const CAPABILITY = 'manage_options';
	const ACTION     = 'm24_import_enqueue';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_enqueue' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'm24-plattform',
			__( 'Shopware-Import', 'm24-plattform' ),
			__( 'Shopware-Import', 'm24-plattform' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/** Enqueue-Button-Handler: startet einen Hintergrund-Lauf. */
	public static function handle_enqueue() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		check_admin_referer( self::ACTION );

		$batch_size = isset( $_POST['batch_size'] ) ? max( 1, (int) $_POST['batch_size'] ) : 10;
		$force      = ! empty( $_POST['force'] );

		$notice = '';
		$type   = 'ok';
		try {
			$r = M24_Shopware_Queue::enqueue( 'gebraucht', $batch_size, $force );
			$notice = sprintf(
				/* translators: 1: Produktzahl, 2: Batch-Zahl */
				__( '%1$d Produkte in %2$d Batches eingereiht. Abarbeitung laeuft jetzt im Hintergrund (WP-Cron).', 'm24-plattform' ),
				$r['enqueued'], $r['batches']
			);
		} catch ( Exception $e ) {
			$type   = 'error';
			$notice = $e->getMessage();
		}

		wp_safe_redirect( add_query_arg( array(
			'page'      => self::PAGE_SLUG,
			'm24notice' => rawurlencode( $notice ),
			'm24type'   => $type,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}

		$s    = M24_Shopware_Queue::status();
		$run  = $s['run'];
		$as   = $s['as'];
		$open = (int) $as['pending'] + (int) $as['running'];

		$enqueued = (int) ( $run['enqueued'] ?? 0 );
		$done     = (int) ( $run['done_products'] ?? 0 );
		$failed   = (int) ( $run['failed_products'] ?? 0 );
		$pct      = $enqueued > 0 ? min( 100, (int) round( ( $done + $failed ) / $enqueued * 100 ) ) : 0;

		$notice = isset( $_GET['m24notice'] ) ? wp_unslash( $_GET['m24notice'] ) : '';
		$ntype  = ( isset( $_GET['m24type'] ) && 'error' === $_GET['m24type'] ) ? 'error' : 'success';

		// Auto-Refresh solange noch Batches offen sind.
		$refresh = $open > 0 ? 15 : 0;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Shopware-Import — Hintergrund-Queue', 'm24-plattform' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $ntype ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! M24_Shopware_Queue::as_available() ) : ?>
				<div class="notice notice-error"><p>
					<?php echo esc_html__( 'Action Scheduler ist nicht verfuegbar. Bitte WP Rocket oder Imagify aktiv lassen — sie liefern die Bibliothek mit.', 'm24-plattform' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $refresh > 0 ) : ?>
				<meta http-equiv="refresh" content="<?php echo (int) $refresh; ?>">
				<p style="color:#666;"><?php echo esc_html( sprintf( __( 'Aktualisiert automatisch alle %d s …', 'm24-plattform' ), $refresh ) ); ?></p>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Aktueller Lauf', 'm24-plattform' ); ?></h2>
			<?php if ( empty( $run ) ) : ?>
				<p><?php echo esc_html__( 'Noch kein Import-Lauf gestartet.', 'm24-plattform' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:640px;">
					<tbody>
						<tr><th><?php echo esc_html__( 'Run-ID', 'm24-plattform' ); ?></th><td><code><?php echo esc_html( $run['run_id'] ?? '—' ); ?></code></td></tr>
						<tr><th><?php echo esc_html__( 'Gestartet', 'm24-plattform' ); ?></th><td><?php echo esc_html( $run['started_at'] ?? '—' ); ?></td></tr>
						<tr><th><?php echo esc_html__( 'Eingereiht', 'm24-plattform' ); ?></th><td><?php echo (int) $enqueued; ?> <?php echo esc_html__( 'Produkte', 'm24-plattform' ); ?> / <?php echo (int) ( $run['batches'] ?? 0 ); ?> <?php echo esc_html__( 'Batches', 'm24-plattform' ); ?> (<?php echo esc_html__( 'Groesse', 'm24-plattform' ); ?> <?php echo (int) ( $run['batch_size'] ?? 0 ); ?>)</td></tr>
						<tr><th><?php echo esc_html__( 'Erledigt', 'm24-plattform' ); ?></th><td><strong><?php echo (int) $done; ?></strong></td></tr>
						<tr><th><?php echo esc_html__( 'Fehlgeschlagen', 'm24-plattform' ); ?></th><td><?php echo (int) $failed; ?></td></tr>
						<tr><th><?php echo esc_html__( 'Fortschritt', 'm24-plattform' ); ?></th><td>
							<div style="background:#e2e4e7;border-radius:4px;width:100%;max-width:320px;height:18px;overflow:hidden;display:inline-block;vertical-align:middle;">
								<div style="background:#2271b1;height:100%;width:<?php echo (int) $pct; ?>%;"></div>
							</div>
							<span style="margin-left:8px;"><?php echo (int) $pct; ?>%</span>
						</td></tr>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Batch-Queue (Action Scheduler)', 'm24-plattform' ); ?></h2>
			<table class="widefat striped" style="max-width:420px;">
				<tbody>
					<tr><th><?php echo esc_html__( 'offen', 'm24-plattform' ); ?></th><td><?php echo esc_html( self::fmt( $as['pending'] ) ); ?></td></tr>
					<tr><th><?php echo esc_html__( 'laufend', 'm24-plattform' ); ?></th><td><?php echo esc_html( self::fmt( $as['running'] ) ); ?></td></tr>
					<tr><th><?php echo esc_html__( 'erledigt', 'm24-plattform' ); ?></th><td><?php echo esc_html( self::fmt( $as['complete'] ) ); ?></td></tr>
					<tr><th><?php echo esc_html__( 'fehlgeschlagen', 'm24-plattform' ); ?></th><td><?php echo esc_html( self::fmt( $as['failed'] ) ); ?></td></tr>
				</tbody>
			</table>
			<p><?php echo esc_html( sprintf( __( 'Gebrauchtteile mit Shopware-ID in der Datenbank: %d', 'm24-plattform' ), (int) $s['imported_in_db'] ) ); ?></p>
			<?php if ( ! empty( $run ) && 0 === $open ) : ?>
				<p style="color:#1a7f37;font-weight:600;"><?php echo esc_html__( '✓ Queue leer — Import abgeschlossen.', 'm24-plattform' ); ?></p>
			<?php endif; ?>

			<hr>
			<h2><?php echo esc_html__( 'Neuen Lauf starten', 'm24-plattform' ); ?></h2>
			<p><?php echo esc_html__( 'Reiht alle Gebrauchtteile neu ein (idempotent — bestehende Teile werden aktualisiert, keine Duplikate). Der ID-Abruf dauert nur wenige Sekunden.', 'm24-plattform' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<label><?php echo esc_html__( 'Batch-Groesse:', 'm24-plattform' ); ?>
					<input type="number" name="batch_size" value="10" min="1" max="50" style="width:70px;">
				</label>
				&nbsp;
				<label><input type="checkbox" name="force" value="1"> <?php echo esc_html__( 'Force (Beschreibungs-Resync)', 'm24-plattform' ); ?></label>
				&nbsp;
				<button type="submit" class="button button-primary"<?php disabled( ! M24_Shopware_Queue::as_available() ); ?>>
					<?php echo esc_html__( 'Import einreihen', 'm24-plattform' ); ?>
				</button>
			</form>
			<p style="color:#666;margin-top:14px;">
				<?php echo esc_html__( 'Details siehe', 'm24-plattform' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=m24-plattform-log&context=shopware-import' ) ); ?>"><?php echo esc_html__( 'Sync-Log', 'm24-plattform' ); ?></a>.
			</p>
		</div>
		<?php
	}

	private static function fmt( $v ) {
		return null === $v ? 'n/a' : (string) (int) $v;
	}
}
