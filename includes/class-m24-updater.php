<?php
/**
 * M24 Plattform — Self-Updater (One-Click-Updates aus privatem GitHub-Repo)
 * Modul: includes/class-m24-updater.php
 *
 * Bindet die plugin-update-checker-Bibliothek (YahnisElsts, v5) gegen das private
 * GitHub-Repo ein. Workflow: Code aendern → `Version:`-Header im Plugin-Hauptfile
 * erhoehen → committen + pushen → WordPress zeigt unter „Plugins" ein Update an →
 * ein Klick „Aktualisieren". Kein Zippen/Hochladen mehr.
 *
 * Branch-Modus (main): die `Version:`-Kopfzeile im Branch ist der Update-Trigger.
 * Updates erscheinen NUR, wenn diese Versionsnummer steigt — andere Commits loesen
 * nichts aus. (Alternative GitHub-Releases: REPO lassen, setBranch() entfernen.)
 *
 * Privates Repo: der GitHub-Token kommt AUSSCHLIESSLICH aus der wp-config-Konstante
 * `M24_GITHUB_TOKEN` (Fine-grained PAT mit Read-Zugriff auf das Repo) — niemals aus
 * der DB, damit er nicht im DB-Dump landet.
 *
 * Repo/Branch sind via Konstanten ueberschreibbar: M24_UPDATER_REPO, M24_UPDATER_BRANCH.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Updater {

	const DEFAULT_REPO   = 'https://github.com/dobrock/m24-plattform/';
	const DEFAULT_BRANCH = 'main';
	const SLUG           = 'm24-plattform';
	const ACTION         = 'm24_force_update_check';

	/** @var \YahnisElsts\PluginUpdateChecker\v5\UpdateChecker|null */
	private static $checker = null;

	public static function init() {
		// Nur dort bauen, wo Update-Checks tatsaechlich laufen: Admin, WP-Cron, WP-CLI.
		// Frontend-Requests bleiben unbelastet.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$lib = M24_PLATTFORM_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $lib ) ) {
			return;
		}
		require_once $lib;

		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
		if ( ! class_exists( $factory ) ) {
			return;
		}

		// Der Updater darf das Backend NIEMALS fataln (kritischer Fehler). buildUpdateChecker()
		// kann werfen (z.B. ungueltige Repo-URL), und setBranch()/setAuthentication() existieren
		// NUR auf dem VCS-(GitHub-)Checker — erkennt PUC die URL nicht als GitHub, liefert es einen
		// generischen Plugin\UpdateChecker OHNE diese Methoden → frueher: Fatal. Daher: try/catch
		// + method_exists-Guard. self::repo() normalisiert ausserdem Bare-Slugs zu Voll-URLs.
		try {
			$checker = call_user_func(
				array( $factory, 'buildUpdateChecker' ),
				self::repo(),
				M24_PLATTFORM_FILE,
				self::SLUG
			);

			if ( ! method_exists( $checker, 'setBranch' ) ) {
				// Kein VCS-Checker → URL nicht als GitHub erkannt. Nicht weiter konfigurieren (Fatal-Schutz).
				if ( class_exists( 'M24_Logger' ) ) {
					M24_Logger::error( 'updater', 'PUC lieferte keinen GitHub-/VCS-Checker fuer "' . self::repo() . '" — Updater inaktiv. M24_UPDATER_REPO pruefen/entfernen.' );
				}
				return;
			}

			// Branch-Modus: Header-Version im Branch = Trigger.
			$checker->setBranch( self::branch() );

			// Privates Repo: Token nur aus wp-config-Konstante.
			if ( self::has_token() && method_exists( $checker, 'setAuthentication' ) ) {
				$checker->setAuthentication( (string) M24_GITHUB_TOKEN );
			}

			self::$checker = $checker;

			// Force-Check-Button (Settings-Seite) → admin-post-Handler.
			add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_force_check' ) );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'M24_Logger' ) ) {
				M24_Logger::error( 'updater', 'Updater-Init fehlgeschlagen (kein Fatal): ' . $e->getMessage() );
			}
			self::$checker = null;
		}
	}

	/**
	 * Repo-URL fuer PUC. Default = hartkodiertes GitHub-Repo. Optionaler Override via
	 * M24_UPDATER_REPO — robust normalisiert: ein Bare-Slug „user/repo" wird zur Voll-URL
	 * „https://github.com/user/repo/" (sonst erkennt PUC kein GitHub → Fatal). Unklare Werte
	 * fallen auf den Default zurueck.
	 */
	public static function repo() {
		$repo = ( defined( 'M24_UPDATER_REPO' ) && '' !== trim( (string) M24_UPDATER_REPO ) )
			? trim( (string) M24_UPDATER_REPO )
			: self::DEFAULT_REPO;

		if ( false !== strpos( $repo, '://' ) ) {
			return $repo; // bereits vollstaendige URL
		}
		$slug = trim( $repo, '/' );
		if ( preg_match( '#^[\w.-]+/[\w.-]+$#', $slug ) ) {
			return 'https://github.com/' . $slug . '/'; // Bare-Slug → Voll-URL
		}
		return self::DEFAULT_REPO; // unbrauchbar → sicher auf Default
	}

	public static function branch() {
		return ( defined( 'M24_UPDATER_BRANCH' ) && M24_UPDATER_BRANCH ) ? (string) M24_UPDATER_BRANCH : self::DEFAULT_BRANCH;
	}

	public static function has_token() {
		return defined( 'M24_GITHUB_TOKEN' ) && '' !== (string) M24_GITHUB_TOKEN;
	}

	public static function checker() {
		return self::$checker;
	}

	/**
	 * Force-Check-Handler: prueft sofort gegen GitHub und leitet mit Ergebnis-Notice
	 * zurueck auf die Einstellungen.
	 */
	public static function handle_force_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		check_admin_referer( self::ACTION );

		$type   = 'ok';
		$notice = '';

		if ( ! self::$checker ) {
			$type   = 'error';
			$notice = __( 'Updater nicht initialisiert (Bibliothek fehlt?).', 'm24-plattform' );
		} else {
			$update = self::$checker->checkForUpdates(); // Update|null
			$errors = method_exists( self::$checker, 'getLastRequestApiErrors' ) ? self::$checker->getLastRequestApiErrors() : array();

			// Im Branch-Modus probiert PUC fuer 'main' per Design ZUERST releases/latest + tags
			// und faellt dann auf den Branch zurueck. Da wir keine Releases/Tags fuehren, sind
			// deren 404 ERWARTBAR und kein Fehler — nur ein fehlgeschlagener Branch-/Auth-Zugriff
			// ist fatal. Diese harmlosen 404 herausfiltern, sonst meldet der Button faelschlich Fehler.
			$fatal = array();
			foreach ( $errors as $e ) {
				$err = isset( $e['error'] ) ? $e['error'] : null;
				$m   = ( $err instanceof WP_Error ) ? $err->get_error_message() : '';
				if ( '' !== $m && ! preg_match( '#/releases/latest|/tags#', $m ) ) {
					$fatal[] = $m;
				}
			}

			if ( $update && isset( $update->version ) ) {
				$notice = sprintf(
					__( 'Update verfuegbar: Version %1$s (installiert: %2$s). Unter „Plugins" aktualisieren.', 'm24-plattform' ),
					$update->version, M24_PLATTFORM_VERSION
				);
			} elseif ( ! empty( $fatal ) ) {
				$type    = 'error';
				$notice  = sprintf( __( 'Update-Pruefung fehlgeschlagen: %s', 'm24-plattform' ), $fatal[0] );
				if ( ! self::has_token() ) {
					$notice .= ' ' . __( '(Kein GitHub-Token gesetzt — bei einem privaten Repo zwingend: Konstante M24_GITHUB_TOKEN in wp-config.php.)', 'm24-plattform' );
				}
			} else {
				$notice = sprintf( __( 'Kein Update verfuegbar. Version %s ist die neueste (Branch main).', 'm24-plattform' ), M24_PLATTFORM_VERSION );
			}
		}

		wp_safe_redirect( add_query_arg( array(
			'page'      => 'm24-plattform',
			'm24upd'    => rawurlencode( $notice ),
			'm24updt'   => $type,
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render-Block fuer die Settings-Seite. Wird aus M24_Settings::render_page() aufgerufen.
	 */
	public static function render_settings_section() {
		$installed = M24_PLATTFORM_VERSION;
		$update    = self::$checker ? self::$checker->getUpdate() : null; // gecachter Stand
		$available = ( $update && isset( $update->version ) ) ? (string) $update->version : '';

		$notice = isset( $_GET['m24upd'] ) ? wp_unslash( $_GET['m24upd'] ) : '';
		$ntype  = ( isset( $_GET['m24updt'] ) && 'error' === $_GET['m24updt'] ) ? 'error' : 'success';
		?>
		<hr>
		<h2><?php echo esc_html__( 'Plugin-Updates', 'm24-plattform' ); ?></h2>
		<p><?php echo esc_html__( 'Updates kommen direkt aus dem GitHub-Repo. Neue Version pushen → hier oder unter „Plugins" erscheint das Update → ein Klick.', 'm24-plattform' ); ?></p>

		<?php if ( '' !== $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $ntype ); ?> is-dismissible" style="margin:10px 0;"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Installierte Version', 'm24-plattform' ); ?></th>
				<td><code><?php echo esc_html( $installed ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Verfuegbare Version', 'm24-plattform' ); ?></th>
				<td>
					<?php if ( '' !== $available && version_compare( $available, $installed, '>' ) ) : ?>
						<code style="color:#1a7f37;font-weight:600;"><?php echo esc_html( $available ); ?></code>
						&nbsp;<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button button-secondary"><?php echo esc_html__( 'Zu „Plugins" → Aktualisieren', 'm24-plattform' ); ?></a>
					<?php elseif ( '' !== $available ) : ?>
						<code><?php echo esc_html( $available ); ?></code> <span style="color:#666;">(<?php echo esc_html__( 'aktuell', 'm24-plattform' ); ?>)</span>
					<?php else : ?>
						<span style="color:#666;"><?php echo esc_html__( 'noch nicht geprueft', 'm24-plattform' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Repository', 'm24-plattform' ); ?></th>
				<td><code><?php echo esc_html( self::repo() ); ?></code> · <?php echo esc_html__( 'Branch', 'm24-plattform' ); ?> <code><?php echo esc_html( self::branch() ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'GitHub-Token', 'm24-plattform' ); ?></th>
				<td>
					<?php if ( self::has_token() ) : ?>
						<span style="color:#1a7f37;font-weight:600;"><span class="dashicons dashicons-yes-alt"></span> <?php echo esc_html__( 'gesetzt (wp-config.php)', 'm24-plattform' ); ?></span>
					<?php else : ?>
						<span style="color:#b87000;font-weight:600;"><span class="dashicons dashicons-warning"></span> <?php echo esc_html__( 'nicht gesetzt', 'm24-plattform' ); ?></span>
						<p class="description"><?php echo esc_html__( 'Bei privatem Repo zwingend: define( "M24_GITHUB_TOKEN", "github_pat_..." ); in wp-config.php.', 'm24-plattform' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<button type="submit" class="button button-primary" style="display:inline-flex;align-items:center;gap:4px;">
				<span class="dashicons dashicons-update" style="line-height:1;"></span>
				<?php echo esc_html__( 'Nach Updates suchen', 'm24-plattform' ); ?>
			</button>
		</form>
		<?php
	}
}
