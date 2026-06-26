<?php
/**
 * M24 Plattform — Sprach-Umschalt-Endpoint /sprache/?to=de|en
 *
 * Für den Mail-Footer-Link „Sprache ändern". Setzt die Site-Sprache des Besuchers
 * (gleicher Cookie-Mechanismus wie der Stil-C-Frontend-Switcher, via M24_I18n::set_cookie)
 * und leitet 302 auf eine serverseitig abgeleitete, interne Landing (Startseite in der
 * gewählten Sprache) weiter. Kein Account, kein Token, kein Param-Redirect (Open-Redirect-Schutz).
 *
 * Pretty-URL via Rewrite-Rule + Query-Var; Flush nur bei Plugin-Aktivierung.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Lang_Endpoint {

	const QV = 'm24_lang';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rule' ) );
		// Self-Healing-Flush NACH der Rule-Registrierung (Prio 20 > 10): register_activation_hook
		// feuert beim One-Click-UPDATE nicht → versions-getriggert nachflushen, sonst /sprache/ = 404.
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	public static function add_rule() {
		add_rewrite_rule( '^sprache/?$', 'index.php?' . self::QV . '=1', 'top' );
	}

	/** Einmaliger Soft-Flush bei Rewrite-Versions-Mismatch (kein Flush bei jedem Request). */
	public static function maybe_flush() {
		$target = defined( 'M24_REWRITE_VERSION' ) ? M24_REWRITE_VERSION : '1';
		if ( get_option( 'm24_rewrite_version' ) !== $target ) {
			flush_rewrite_rules( false ); // soft: kein .htaccess-Write nötig (Rules stehen bereits via init)
			update_option( 'm24_rewrite_version', $target );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/** Bei Plugin-Aktivierung (Erstinstallation): Rule registrieren + hart flushen + Version stempeln. */
	public static function activate() {
		self::add_rule();
		flush_rewrite_rules();
		update_option( 'm24_rewrite_version', defined( 'M24_REWRITE_VERSION' ) ? M24_REWRITE_VERSION : '1' );
	}

	public static function maybe_handle() {
		if ( ! get_query_var( self::QV ) ) {
			return;
		}
		// 1) to lesen, lowercase, trim. 2) Whitelist de|en, sonst Fallback de.
		$to = isset( $_GET['to'] ) ? strtolower( trim( (string) wp_unslash( $_GET['to'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $to, array( 'de', 'en' ), true ) ) {
			$to = 'de';
		}
		// 3) Sprache setzen — exakt der Switcher-Mechanismus (Cookie m24_lang, SameSite=Lax).
		if ( class_exists( 'M24_I18n' ) ) {
			M24_I18n::set_cookie( $to );
		}
		// 4)+5) Ziel serverseitig ableiten (interne DE/EN-Startseite). KEIN Param-Redirect → Open-Redirect-sicher.
		//        302, da Sprachwahl nicht permanent cachebar.
		wp_safe_redirect( home_url( '/?lang=' . $to ), 302 );
		exit;
	}
}
