<?php
/**
 * M24 — Ein-Klick-Update & Cache-Purge
 * Modul: includes/class-m24-oneclick-update.php
 *
 * Ein Button „Jetzt aktualisieren & Cache leeren" (MOTORSPORT24 → Einstellungen + Admin-Bar):
 * erzwingt Update-Check gegen die bestehende Self-Update-Quelle (PUC), installiert die neueste
 * Version (Filesystem „direct", kein FTP-Prompt), leert OPcache (opcache_reset + per-Datei-
 * Invalidierung) und WP-Rocket, meldet alt→neu + Cache-Status. Manuell auf Knopfdruck (kein Cron).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_OneClick_Update {

	const ACTION = 'm24_oneclick_update';
	const NONCE  = 'm24_oneclick_update';
	const CAP    = 'manage_options';

	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'm24_settings_top', array( __CLASS__, 'render_button' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'admin_footer', array( __CLASS__, 'inline_js' ) );
	}

	/* ── UI ──────────────────────────────────────────────────────────────────── */

	public static function render_button() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$os = class_exists( 'M24_Updater' ) ? M24_Updater::opcache_status() : array();
		?>
		<div id="m24ocu" style="margin:14px 0;padding:16px 18px;border:1px solid #e6e6e3;border-radius:10px;background:#fff;max-width:760px">
			<button type="button" class="button button-primary button-hero" id="m24ocu-btn"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>"
				style="background:linear-gradient(135deg,#1f74c4,#0e447e);border:0">
				🔄 Jetzt aktualisieren &amp; Cache leeren
			</button>
			<span id="m24ocu-spin" class="spinner" style="float:none;margin:0 0 0 8px"></span>
			<p style="margin:10px 0 0;color:#50575e;font-size:13px">
				Aktuelle Version: <strong><?php echo esc_html( M24_PLATTFORM_VERSION ); ?></strong>
				· OPcache: <?php echo esc_html( ( $os['enable'] ?? '' ) ? 'aktiv' : 'aus' ); ?>
				· validate_timestamps: <?php echo esc_html( $os['validate_timestamps'] ?? '?' ); ?>
				· reset verfügbar: <?php echo esc_html( $os['reset_available'] ?? '?' ); ?>
				<?php if ( ! empty( $os['restrict_api'] ) ) { echo ' · restrict_api: ' . esc_html( $os['restrict_api'] ); } ?>
			</p>
			<div id="m24ocu-result" style="margin-top:10px"></div>
		</div>
		<?php
	}

	public static function admin_bar( $bar ) {
		if ( ! current_user_can( self::CAP ) ) { return; } // auch im Frontend ein Klick von jeder Seite
		$bar->add_node( array(
			'id'    => 'm24-oneclick-update',
			'title' => '🔄 M24 aktualisieren',
			'href'  => admin_url( 'admin.php?page=m24-plattform' ),
			'meta'  => array( 'title' => 'M24-Plugin aktualisieren & Cache leeren' ),
		) );
	}

	public static function inline_js() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'm24-plattform' ) ) { return; }
		?>
		<script>
		jQuery(function($){
			var btn=$('#m24ocu-btn'); if(!btn.length){return;}
			btn.on('click',function(){
				var nonce=btn.data('nonce'); btn.prop('disabled',true); $('#m24ocu-spin').addClass('is-active');
				$('#m24ocu-result').html('');
				$.post(ajaxurl,{action:'<?php echo esc_js( self::ACTION ); ?>',_nonce:nonce},function(r){
					btn.prop('disabled',false); $('#m24ocu-spin').removeClass('is-active');
					if(!r||!r.success){ $('#m24ocu-result').html('<div class="notice notice-error inline"><p>'+((r&&r.data&&r.data.message)||'Fehler')+'</p></div>'); return; }
					var d=r.data, html='<div class="notice notice-success inline"><p><strong>'+d.headline+'</strong><br>';
					html+='OPcache: '+d.opcache+' · WP-Rocket: '+d.wprocket;
					if(d.fpm_hint){ html+='<br><em>'+d.fpm_hint+'</em>'; }
					html+='</p></div>';
					$('#m24ocu-result').html(html);
				}).fail(function(){ btn.prop('disabled',false); $('#m24ocu-spin').removeClass('is-active'); $('#m24ocu-result').html('<div class="notice notice-error inline"><p>Netzwerkfehler</p></div>'); });
			});
		});
		</script>
		<?php
	}

	/* ── AJAX-Lauf ───────────────────────────────────────────────────────────── */

	public static function ajax() {
		if ( ! current_user_can( self::CAP ) ) { wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ) ); }
		check_ajax_referer( self::NONCE, '_nonce' );
		@set_time_limit( 180 ); // phpcs:ignore

		$basename = plugin_basename( M24_PLATTFORM_FILE );
		$alt      = M24_PLATTFORM_VERSION;

		// 1) Update-Check erzwingen (PUC + WP-Transient).
		delete_site_transient( 'update_plugins' );
		if ( class_exists( 'M24_Updater' ) && M24_Updater::checker() ) {
			$c = M24_Updater::checker();
			if ( method_exists( $c, 'checkForUpdates' ) ) { $c->checkForUpdates(); }
		}
		if ( function_exists( 'wp_update_plugins' ) ) { wp_update_plugins(); }

		$updates   = get_site_transient( 'update_plugins' );
		$available = isset( $updates->response[ $basename ] );
		$installed = false; $inst_msg = '';

		// 2) Installieren, falls neuer (Filesystem „direct").
		if ( $available ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			$direct = static function () { return 'direct'; };
			add_filter( 'filesystem_method', $direct );
			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$res      = $upgrader->upgrade( $basename );
			remove_filter( 'filesystem_method', $direct );
			if ( is_wp_error( $res ) ) { $inst_msg = $res->get_error_message(); }
			elseif ( false === $res ) { $inst_msg = 'Installation fehlgeschlagen (Filesystem/Berechtigung?).'; }
			else { $installed = true; }
			// Aktiv halten (upgrade() reaktiviert i. d. R. selbst).
			if ( $installed && is_plugin_inactive( $basename ) ) { activate_plugin( $basename ); }
		}

		// 3) OPcache leeren (NACH dem Dateitausch).
		$opc = 'n/a';
		if ( class_exists( 'M24_Updater' ) ) {
			$ok  = M24_Updater::reset_opcache( 'Ein-Klick-Update' );
			$opc = function_exists( 'opcache_reset' ) ? ( $ok ? 'geleert' : 'blockiert' ) : 'n/a';
		} elseif ( function_exists( 'opcache_reset' ) ) {
			$opc = @opcache_reset() ? 'geleert' : 'blockiert'; // phpcs:ignore
		}

		// 4) WP-Rocket leeren.
		$wpr = 'n/a';
		if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); $wpr = 'geleert'; }

		// 5) Neue Version frisch von Platte lesen (Konstante im laufenden Request ist stale).
		$neu = $alt;
		if ( function_exists( 'get_file_data' ) ) {
			$d = get_file_data( M24_PLATTFORM_FILE, array( 'v' => 'Version' ) );
			if ( ! empty( $d['v'] ) ) { $neu = trim( (string) $d['v'] ); }
		}

		$headline = $installed
			? sprintf( 'Aktualisiert: %s → %s', $alt, $neu )
			: ( '' !== $inst_msg ? 'Update fehlgeschlagen: ' . $inst_msg : sprintf( 'Bereits aktuell (Version %s)', $neu ) );

		$fpm = '';
		if ( 'geleert' === $opc ) { $fpm = 'PHP-Cache geleert — kein FPM-Neustart nötig.'; }
		elseif ( 'n/a' !== $opc ) { $fpm = 'PHP-Cache konnte nicht in-process geleert werden → bei reinen PHP-Änderungen einmal FPM 8.2 neu starten. JS/CSS/Assets sind abgedeckt.'; }

		wp_send_json_success( array(
			'headline' => $headline, 'alt' => $alt, 'neu' => $neu,
			'installed' => $installed, 'opcache' => $opc, 'wprocket' => $wpr, 'fpm_hint' => $fpm,
		) );
	}
}
