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
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'menu_item' ), 20, 2 );
		add_action( 'wp_head', array( __CLASS__, 'styles' ), 99 );
	}

	public static function enabled(): bool {
		return (bool) get_option( self::OPTION, 0 );
	}

	/** Login/Abmelden als letzter Menüpunkt — nur bei aktivem Flag, nur im Primär-Menü. */
	public static function menu_item( $items, $args ) {
		if ( ! self::enabled() ) {
			return $items;
		}
		// Ziel-Menü: filterbar; Default = erste registrierte Theme-Location (Primär-Menü).
		$loc = (string) apply_filters( 'm24_b2b_login_menu_location', '' );
		if ( '' === $loc ) {
			$regs = array_keys( (array) get_registered_nav_menus() );
			$loc  = $regs[0] ?? '';
		}
		if ( '' !== $loc && isset( $args->theme_location ) && $args->theme_location !== $loc ) {
			return $items;
		}

		if ( m24_is_b2b_authenticated() ) {
			$url   = esc_url( wp_logout_url( home_url( '/' ) ) ); // WP-Core hängt den Nonce an
			$label = ( 'en' === self::lang() ) ? 'Log out' : 'Abmelden';
		} else {
			$url   = esc_url( home_url( '/haendler-login/' ) );
			$label = 'Login';
		}
		return $items . '<li class="menu-item m24-b2b-login-mi"><a href="' . $url . '">' . esc_html( $label ) . '</a></li>';
	}

	private static function lang(): string {
		return class_exists( 'M24_I18n' ) ? M24_I18n::resolve_lang() : 'de';
	}

	/** CI-Button-Optik (Saira, CI-Blau-Verlauf). Nur bei aktivem Flag, self-hosted. */
	public static function styles() {
		if ( ! self::enabled() ) {
			return;
		}
		echo '<style id="m24-b2b-login-mi-css">'
			. ".m24-b2b-login-mi>a{display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);color:#fff!important;padding:8px 16px;border-radius:8px;font-family:'Saira',Arial,sans-serif;font-weight:700;text-decoration:none;line-height:1.1}"
			. '.m24-b2b-login-mi>a:hover{filter:brightness(1.06)}'
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
