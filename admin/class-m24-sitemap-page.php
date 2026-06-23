<?php
/**
 * M24 Plattform — Backend-Panel „Sitemap".
 *
 * Quelle der Wahrheit = Option m24_indexable_hubs (Array Hub-Slugs). Der Filter
 * `m24_indexable_hub_slugs` (registriert in M24_Catalog_Hub) liest diese Option als Default
 * → Panel, seo_robots() UND /sitemap-m24-hubs.xml steuern dieselbe Liste.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Sitemap_Page {

	const PAGE_SLUG  = 'm24-sitemap';
	const CAPABILITY = 'manage_options';
	const OPTION     = 'm24_indexable_hubs';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_m24_sitemap_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_m24_sitemap_ping', array( __CLASS__, 'handle_ping' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'm24-plattform',
			__( 'Sitemap', 'm24-plattform' ),
			__( 'Sitemap', 'm24-plattform' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function assets( $hook ) {
		if ( is_string( $hook ) && false !== strpos( $hook, self::PAGE_SLUG ) ) {
			wp_enqueue_style( 'm24fz-saira', plugins_url( 'assets/fonts/saira.css', M24_PLATTFORM_FILE ), array(), null );
		}
	}

	/* ── URLs / Helfer ───────────────────────────────────────────────────── */

	private static function hub_sitemap_url() {
		return home_url( '/sitemap-m24-hubs.xml' );
	}

	private static function master_sitemap_url() {
		return home_url( '/sitemap.xml' );
	}

	/** Aktuelle Allowlist (durch den Filter = Option oder Default). */
	private static function allowlist() {
		return (array) apply_filters( 'm24_indexable_hub_slugs', array( 'e36', 'z4-gt3' ) );
	}

	private static function back_url( $msg = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		return $msg ? add_query_arg( 'm24sm', $msg, $url ) : $url;
	}

	/* ── Render ──────────────────────────────────────────────────────────── */

	public static function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}

		$registry  = class_exists( 'M24_Catalog_Hub' ) ? (array) M24_Catalog_Hub::registry() : array();
		$allow     = self::allowlist();
		$allowset  = array_flip( $allow );
		$hub_url   = self::hub_sitemap_url();
		$master    = self::master_sitemap_url();
		$url_count = count( array_filter( $allow ) );
		$robots    = self::robots_has_sitemap();
		?>
		<div class="wrap m24sm-wrap">
			<h1><?php echo esc_html__( 'MOTORSPORT24 — Sitemap', 'm24-plattform' ); ?></h1>
			<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>

			<?php if ( isset( $_GET['m24sm'] ) ) : $m = sanitize_text_field( wp_unslash( $_GET['m24sm'] ) ); ?>
				<div class="notice notice-success is-dismissible"><p><?php
					echo esc_html( 'saved' === $m ? __( 'Auswahl gespeichert — Robots & Sitemap aktualisiert.', 'm24-plattform' ) : ( 'pinged' === $m ? __( 'Ping ausgelöst (Ergebnis im Sync-Log).', 'm24-plattform' ) : '' ) );
				?></p></div>
			<?php endif; ?>

			<!-- Status-Karten -->
			<div class="m24sm-cards">
				<div class="m24sm-card">
					<div class="h"><?php echo esc_html__( 'Hub-Sitemap', 'm24-plattform' ); ?></div>
					<div class="n"><?php echo (int) $url_count; ?></div>
					<div class="s"><?php echo esc_html__( 'URLs', 'm24-plattform' ); ?></div>
					<a href="<?php echo esc_url( $hub_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '/sitemap-m24-hubs.xml ↗' ); ?></a>
				</div>
				<div class="m24sm-card">
					<div class="h"><?php echo esc_html__( 'Master-Sitemap', 'm24-plattform' ); ?></div>
					<div class="s2"><?php echo esc_html__( 'Jetpack/Core', 'm24-plattform' ); ?></div>
					<a href="<?php echo esc_url( $master ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '/sitemap.xml ↗' ); ?></a>
				</div>
				<div class="m24sm-card">
					<div class="h"><?php echo esc_html__( 'robots.txt', 'm24-plattform' ); ?></div>
					<div class="s2 <?php echo $robots['ok'] ? 'good' : 'warn'; ?>">
						<?php echo esc_html( $robots['ok'] ? __( 'Sitemap-Zeile vorhanden', 'm24-plattform' ) : __( 'keine Sitemap-Zeile gefunden', 'm24-plattform' ) ); ?>
					</div>
					<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '/robots.txt ↗' ); ?></a>
				</div>
			</div>

			<!-- Helfer-Zeile -->
			<div class="m24sm-tools">
				<label><?php echo esc_html__( 'Sitemap-URL:', 'm24-plattform' ); ?>
					<input type="text" readonly value="<?php echo esc_attr( $hub_url ); ?>" onclick="this.select();document.execCommand&&document.execCommand('copy');" class="regular-text code" style="min-width:340px;">
				</label>
				<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( 'https://search.google.com/search-console/sitemaps?resource_id=' . rawurlencode( home_url( '/' ) ) ); ?>"><?php echo esc_html__( 'Google Search Console ↗', 'm24-plattform' ); ?></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="m24_sitemap_ping">
					<?php wp_nonce_field( 'm24_sitemap_ping' ); ?>
					<button type="submit" class="button"><?php echo esc_html__( 'Suchmaschinen anpingen', 'm24-plattform' ); ?></button>
				</form>
			</div>

			<!-- Hub-Auswahl -->
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="m24sm-form">
				<input type="hidden" name="action" value="m24_sitemap_save">
				<?php wp_nonce_field( 'm24_sitemap_save' ); ?>
				<h2><?php echo esc_html__( 'Indexierbare Hubs (in Sitemap + index,follow)', 'm24-plattform' ); ?></h2>
				<table class="wp-list-table widefat striped">
					<thead><tr>
						<th style="width:60px;"><?php echo esc_html__( 'Index', 'm24-plattform' ); ?></th>
						<th><?php echo esc_html__( 'Slug', 'm24-plattform' ); ?></th>
						<th><?php echo esc_html__( 'Titel', 'm24-plattform' ); ?></th>
						<th style="width:90px;"><?php echo esc_html__( 'Teile', 'm24-plattform' ); ?></th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $registry ) ) : ?>
							<tr><td colspan="4"><?php echo esc_html__( 'Keine Hubs vorhanden.', 'm24-plattform' ); ?></td></tr>
						<?php else : foreach ( $registry as $slug => $pid ) :
							$count = class_exists( 'M24_Catalog_Hub' ) ? (int) M24_Catalog_Hub::count( $slug ) : 0;
							?>
							<tr>
								<td><input type="checkbox" name="hubs[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( isset( $allowset[ $slug ] ) ); ?>></td>
								<td><code><?php echo esc_html( $slug ); ?></code></td>
								<td><a href="<?php echo esc_url( get_edit_post_link( (int) $pid ) ); ?>"><?php echo esc_html( get_the_title( (int) $pid ) ); ?></a></td>
								<td><?php echo (int) $count; ?></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
				<p><button type="submit" class="button button-primary"><?php echo esc_html__( 'Auswahl speichern', 'm24-plattform' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	private static function css() {
		return ".m24sm-wrap{--brass:#9a6b25}"
			. ".m24sm-cards{display:flex;gap:16px;margin:16px 0 18px;flex-wrap:wrap}"
			. ".m24sm-card{background:#fafafa;border:1px solid #e6e9ee;border-radius:12px;padding:16px 22px;min-width:200px;font-family:'Saira',sans-serif}"
			. ".m24sm-card .h{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#5a6474;margin-bottom:6px}"
			. ".m24sm-card .n{font-size:38px;font-weight:800;line-height:1;color:var(--brass)}"
			. ".m24sm-card .s{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#5a6474;margin-bottom:8px}"
			. ".m24sm-card .s2{font-size:13px;font-weight:600;color:#3a414c;margin:6px 0 8px}"
			. ".m24sm-card .s2.good{color:#1a7a3c}.m24sm-card .s2.warn{color:#b87000}"
			. ".m24sm-card a{font-size:13px;color:#1f74c4;text-decoration:none}"
			. ".m24sm-tools{background:#fafafa;border:1px solid #e6e9ee;border-radius:12px;padding:14px 16px;margin:0 0 18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}"
			. ".m24sm-form h2{font-family:'Saira',sans-serif}";
	}

	/** robots.txt live holen und auf eine Sitemap:-Zeile prüfen (best-effort, kurzer Timeout). */
	private static function robots_has_sitemap() {
		$res = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 6, 'redirection' => 2 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false );
		}
		$body = (string) wp_remote_retrieve_body( $res );
		return array( 'ok' => ( false !== stripos( $body, 'Sitemap:' ) ) );
	}

	/* ── Aktionen ────────────────────────────────────────────────────────── */

	public static function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		check_admin_referer( 'm24_sitemap_save' );

		$hubs = isset( $_POST['hubs'] ) ? (array) wp_unslash( $_POST['hubs'] ) : array();
		$hubs = array_values( array_unique( array_filter( array_map( 'sanitize_title', $hubs ) ) ) );
		update_option( self::OPTION, $hubs, false );

		wp_safe_redirect( self::back_url( 'saved' ) );
		exit;
	}

	public static function handle_ping() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		check_admin_referer( 'm24_sitemap_ping' );

		$sitemap = self::hub_sitemap_url();
		$targets = apply_filters( 'm24_sitemap_ping_targets', array(
			'google' => 'https://www.google.com/ping?sitemap=' . rawurlencode( $sitemap ),
			'bing'   => 'https://www.bing.com/ping?sitemap=' . rawurlencode( $sitemap ),
		) );

		$results = array();
		foreach ( $targets as $name => $url ) {
			$r = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 2 ) );
			$results[ $name ] = is_wp_error( $r ) ? $r->get_error_message() : (int) wp_remote_retrieve_response_code( $r );
		}

		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'sitemap', 'Sitemap-Ping ausgelöst', array( 'sitemap' => $sitemap, 'results' => $results ) );
		}

		wp_safe_redirect( self::back_url( 'pinged' ) );
		exit;
	}
}
