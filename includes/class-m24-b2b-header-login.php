<?php
/**
 * M24 Plattform — G2a: Header-Login-Button + Auth-State-Helper (Feature-Flag).
 *
 * Baut NICHT neu, sondern auf der bestehenden B2B-Magic-Link-Auth auf
 * (M24_B2B_Auth: /haendler-login/-Formular → handle_login → ?m24_confirm=TOKEN →
 * confirm_intercept → wp_set_auth_cookie). Token sind bereits SHA-256-gehasht,
 * 15-min-TTL, single-use, enumeration-safe — kein zweites Login-System.
 *
 * NEU hier:
 *  - Feature-Flag m24_magiclink_enabled (default AUS) → steuert NUR den Header-Button-Rollout
 *    (die bestehende /haendler-login/-Strecke bleibt unangetastet/live).
 *  - Header-Login-Button als Menüpunkt (wp_nav_menu_items), CI-konform.
 *  - Globaler Helper m24_is_b2b_authenticated() (für späteres Preis-Gate / Garage = G2b).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_B2B_Header_Login {

	const OPTION = 'm24_magiclink_enabled';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'styles' ), 99 );
		add_action( 'wp_body_open', array( __CLASS__, 'render' ) );
	}

	public static function enabled(): bool {
		return (bool) get_option( self::OPTION, 0 );
	}

	private static function lang(): string {
		return class_exists( 'M24_I18n' ) ? M24_I18n::resolve_lang() : 'de';
	}

	/**
	 * Header-Login-Button menü-engine-unabhängig ausgeben. tagDiv nutzt Block-Menüs
	 * (.tdb-block-menu / #menu-header-menu-2) → wp_nav_menu_items feuert dort NICHT.
	 * Daher: Link an wp_body_open ausgeben + self-hosted Skript, das ihn beim DOMContentLoaded
	 * in den Header-Menü-Container hängt. Fallback (Container nicht gefunden): fixiert oben rechts.
	 */
	public static function render() {
		if ( ! self::enabled() ) {
			return;
		}
		if ( m24_is_b2b_authenticated() ) {
			$url   = wp_logout_url( home_url( '/' ) ); // WP-Core hängt den Nonce an
			$label = ( 'en' === self::lang() ) ? 'Log out' : 'Abmelden';
		} else {
			$url   = home_url( '/haendler-login/' );
			$label = 'Login';
		}
		?>
		<div id="m24-b2b-login" hidden><a class="m24-b2b-login-a" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></div>
		<script>
		(function(){
			function place(){
				var w=document.getElementById('m24-b2b-login'); if(!w) return;
				var a=w.querySelector('.m24-b2b-login-a'); if(!a) return;
				var menu=document.querySelector('#menu-header-menu-2,.tdb-block-menu ul.sf-menu,.td-header-menu-wrap ul.sf-menu,ul.sf-menu');
				if(menu){
					var li=document.createElement('li');
					li.className='menu-item m24-b2b-login-mi';
					li.appendChild(a);
					menu.appendChild(li);
					if(w.parentNode){ w.parentNode.removeChild(w); }
				} else {
					w.className='m24-b2b-login-fallback';
					w.hidden=false;
				}
			}
			if(document.readyState!=='loading'){ place(); }
			else { document.addEventListener('DOMContentLoaded', place); }
		})();
		</script>
		<?php
	}

	/** CI-Button-Optik (Saira, CI-Blau-Verlauf 135°). Nur bei aktivem Flag, self-hosted, kein externer Call. */
	public static function styles() {
		if ( ! self::enabled() ) {
			return;
		}
		echo '<style id="m24-b2b-login-mi-css">'
			. '.m24-b2b-login-a{display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);color:#fff!important;padding:8px 16px;border-radius:8px;font-family:\'Saira\',Arial,sans-serif;font-weight:700;font-size:14px;text-decoration:none;line-height:1.1;white-space:nowrap}'
			. '.m24-b2b-login-a:hover{filter:brightness(1.06)}'
			. '.m24-b2b-login-mi{display:flex;align-items:center}'
			. '.m24-b2b-login-fallback{position:fixed;top:10px;right:14px;z-index:99999}'
			. '</style>' . "\n";
	}
}

/**
 * Eingeloggter B2B-Händler? Saubere Single-Source für späteres Preis-Gate / „Meine Garage" (G2b).
 * Reine Plumbing-Funktion — KEINE Preis-/Garage-Logik hier.
 */
function m24_is_b2b_authenticated(): bool {
	if ( class_exists( 'M24_B2B' ) && method_exists( 'M24_B2B', 'is_logged_in_haendler' ) ) {
		return (bool) M24_B2B::is_logged_in_haendler();
	}
	return false;
}
