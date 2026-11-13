<?php
/**
 * Bot-Sperre (Daniel, 13.11.2026): automatisierte Browser ohne Bildschirm („HeadlessChrome“) bekommen 403.
 *
 * Anlass: ein Abrufer aus Vilnius (HeadlessChrome/94, 800x600) kam rund um die Uhr und verfälschte die Statistik.
 * Gesperrt wird am Browser-Kennzeichen, nicht an der IP – die Adressbereiche teilt sich der Bot mit echten Kunden.
 * Suchmaschinen (Googlebot, Bingbot), Lighthouse/PageSpeed und Link-Vorschauen tragen dieses Kennzeichen nicht.
 *
 * WP Rocket liefert Cache-Seiten aus, ohne dass PHP läuft – deshalb wird das Kennzeichen zusätzlich in WP Rockets
 * Liste „nie aus dem Cache bedienen“ eingetragen (rocket_cache_reject_ua), damit die Sperre auch dort greift.
 * Abschalten: define( 'M24_BOT_SPERRE_AUS', true ); in wp-config.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class M24_Bot_Sperre {

	const MUSTER  = 'HeadlessChrome';
	const OPTION  = 'm24_bot_sperre_stand';
	const STAND   = '1';

	public static function init() {
		if ( defined( 'M24_BOT_SPERRE_AUS' ) && M24_BOT_SPERRE_AUS ) {
			return;
		}
		add_filter( 'rocket_cache_reject_ua', array( __CLASS__, 'rocket_ua' ) );
		add_action( 'admin_init', array( __CLASS__, 'rocket_neu_schreiben' ) );
		self::sperren();
	}

	/** Trifft das Kennzeichen zu? */
	public static function ist_bot( $ua ) {
		return '' !== (string) $ua && false !== stripos( (string) $ua, self::MUSTER );
	}

	private static function sperren() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore
		if ( ! self::ist_bot( $ua ) ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
		echo 'Automated access is not permitted.';
		exit;
	}

	public static function rocket_ua( $liste ) {
		$liste   = is_array( $liste ) ? $liste : array();
		$liste[] = self::MUSTER;
		return array_values( array_unique( $liste ) );
	}

	/** Einmalig: WP Rocket seine Konfiguration und .htaccess-Regeln mit dem neuen Kennzeichen neu schreiben lassen. */
	public static function rocket_neu_schreiben() {
		if ( self::STAND === get_option( self::OPTION ) ) {
			return;
		}
		update_option( self::OPTION, self::STAND, false );
		if ( function_exists( 'rocket_generate_config_file' ) ) {
			rocket_generate_config_file();
		}
		if ( function_exists( 'flush_rocket_htaccess' ) ) {
			flush_rocket_htaccess();
		}
	}
}

M24_Bot_Sperre::init();
