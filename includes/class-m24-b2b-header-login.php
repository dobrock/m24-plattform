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

	private static $rendered = false;

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'styles' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// tagDiv-Newspaper ruft do_action('wp_body_open') NICHT auf → primär an wp_footer hängen
		// (feuert zuverlässig). wp_body_open bleibt als harmloser Sekundär-Hook; Guard verhindert Dopplung.
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'render' ) );
	}

	public static function enabled(): bool {
		return (bool) get_option( self::OPTION, 0 );
	}

	/** Neues passwordless „D"-UI aktiv? Dann übernimmt DAS die Header-Anmeldung (kein Doppel). */
	private static function superseded(): bool {
		return class_exists( 'M24_Login' ) && M24_Login::enabled();
	}

	private static function lang(): string {
		return class_exists( 'M24_I18n' ) ? M24_I18n::resolve_lang() : 'de';
	}

	/** Ausgeloggt: Login-Chip (+ JS/CSS) IMMER laden. Eingeloggt: nur bei aktivem Feature ohne D-UI-Ersatz. */
	private static function skip(): bool {
		return is_user_logged_in() && ( ! self::enabled() || self::superseded() );
	}

	public static function assets() {
		if ( self::skip() ) { return; }
		$js = 'assets/js/m24-header-login.js';
		$jv = file_exists( M24_PLATTFORM_DIR . $js ) ? (string) filemtime( M24_PLATTFORM_DIR . $js ) : M24_PLATTFORM_VERSION;
		wp_enqueue_script( 'm24-header-login', M24_PLATTFORM_URL . $js, array(), $jv, true );
	}

	/**
	 * Header-Login-Button menü-engine-unabhängig ausgeben. tagDiv nutzt Block-Menüs
	 * (.tdb-block-menu / #menu-header-menu-2) → wp_nav_menu_items feuert dort NICHT.
	 * Daher: Link an wp_body_open ausgeben + self-hosted Skript, das ihn beim DOMContentLoaded
	 * in den Header-Menü-Container hängt. Fallback (Container nicht gefunden): fixiert oben rechts.
	 */
	public static function render() {
		if ( self::$rendered || self::skip() ) {
			return; // nur EINMAL; ausgeloggt IMMER, eingeloggt nur ohne „D"-UI-Ersatz (kein Doppel-Login).
		}
		self::$rendered = true;
		$logged = is_user_logged_in();
		$en     = ( 'en' === self::lang() );

		echo '<div id="m24-b2b-login" hidden>';
		if ( ! $logged ) {
			// Ausgeloggt: dezenter Outline-Chip „Login" (+ Personen-Punkt), Ziel = sichere /haendler-login/-Strecke.
			echo '<div class="m24hl-acct"><a class="m24hl-chip" href="' . esc_url( home_url( '/haendler-login/' ) ) . '">'
				. '<span class="m24hl-chip-i" aria-hidden="true">●</span><span class="m24hl-chip-t">Login</span></a></div>';
		} else {
			$u       = wp_get_current_user();
			$fn      = trim( (string) get_user_meta( $u->ID, 'first_name', true ) );
			$base    = '' !== $fn ? $fn : ( '' !== trim( (string) $u->display_name ) ? (string) $u->display_name : (string) $u->user_email );
			$initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $base, 0, 1 ) ) : strtoupper( substr( $base, 0, 1 ) );
			$garage  = class_exists( 'M24_Garage_Cart' ) ? M24_Garage_Cart::page_url() : home_url( '/meine-garage/' );
			$settings = add_query_arg( 'tab', 'benachrichtigungen', $garage ); // Deep-Link → Benachrichtigungen-Tab
			$logout  = wp_logout_url( home_url( '/' ) );
			$items   = '<a class="m24hl-item" href="' . esc_url( $garage ) . '">' . esc_html( $en ? 'My garage' : 'Meine Garage' ) . '</a>'
				. '<a class="m24hl-item" href="' . esc_url( $settings ) . '">' . esc_html( $en ? 'E-mail settings' : 'E-Mail-Einstellungen' ) . '</a>';
			if ( current_user_can( 'manage_options' ) ) {
				$items .= '<a class="m24hl-item" href="' . esc_url( admin_url() ) . '">WP-Admin</a>';
			}
			$items  .= '<a class="m24hl-item m24hl-item-logout" href="' . esc_url( $logout ) . '">' . esc_html( $en ? 'Log out' : 'Abmelden' ) . '</a>';
			echo '<div class="m24hl-acct is-in">'
				. '<button type="button" class="m24hl-accbtn" data-m24hl-menu aria-haspopup="true" aria-expanded="false">'
				. '<span class="m24hl-avatar" aria-hidden="true">' . esc_html( $initial ) . '</span>'
				. '<span class="m24hl-acclabel">' . esc_html( $en ? 'My account' : 'Mein Konto' ) . '</span><span class="m24hl-caret" aria-hidden="true">▾</span></button>'
				. '<div class="m24hl-menu" data-m24hl-dropdown hidden>' . $items . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput — Teile bereits escaped
		}
		echo '</div>';
	}

	/** D-Look-Optik (Saira, Outline-Chip ausgeloggt / Messing-Avatar + Dropdown eingeloggt). Self-hosted. */
	public static function styles() {
		if ( self::skip() ) {
			return;
		}
		echo '<style id="m24-b2b-login-css">'
			. '.m24hl-acct{position:relative;display:inline-flex;align-items:center;font-family:\'Saira\',Arial,sans-serif}'
			. '.m24hl-acct--inhdr{margin:0 12px 0 0!important;align-self:center!important}'
			. '.m24hl-acct--float{position:fixed!important;top:10px;right:14px;z-index:99999}'
			// Ausgeloggt: Outline-Chip
			. '.m24hl-chip{display:inline-flex;align-items:center;gap:7px;background:transparent;border:1px solid rgba(255,255,255,.55);color:#fff;border-radius:999px;padding:6px 14px;font:600 13px/1 \'Saira\',Arial,sans-serif;text-decoration:none;white-space:nowrap}'
			. '.m24hl-chip:hover{border-color:#fff;background:rgba(255,255,255,.10);color:#fff}'
			. '.m24hl-chip-i{font-size:9px;line-height:1}'
			. '.m24hl-acct--float .m24hl-chip{border-color:#1f74c4;color:#1f74c4;background:#fff;box-shadow:0 2px 8px rgba(10,12,16,.18)}'
			// Eingeloggt: Messing-Avatar + Label
			. '.m24hl-accbtn{display:inline-flex;align-items:center;gap:8px;background:transparent;border:0;color:#fff;cursor:pointer;font:600 13px/1 \'Saira\',Arial,sans-serif;padding:4px}'
			. '.m24hl-avatar{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#9a6b25;color:#fff;font-weight:700;font-size:14px}'
			. '.m24hl-caret{font-size:11px}'
			. '.m24hl-acct--float .m24hl-accbtn{color:#14161a;background:#fff;border-radius:999px;padding:5px 12px 5px 5px;box-shadow:0 2px 8px rgba(10,12,16,.18)}'
			// Dropdown
			. '.m24hl-menu{position:absolute;top:calc(100% + 8px);right:0;min-width:210px;background:#fff;border:1px solid #e6e9ee;border-radius:10px;box-shadow:0 10px 30px rgba(10,12,16,.18);padding:6px;z-index:100060}'
			. '.m24hl-item{display:block;padding:10px 12px;border-radius:7px;color:#14161a;text-decoration:none;font-size:14px}'
			. '.m24hl-item:hover{background:#f2f4f7;color:#0e447e}'
			. '.m24hl-item-logout{color:#9e2b2b;border-top:1px solid #eef0f2;margin-top:4px}'
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
