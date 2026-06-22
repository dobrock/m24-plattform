<?php
/**
 * M24 — Externe Schrift-Requests unterbinden
 * Modul: includes/class-m24-fonts.php
 *
 * Ziel: 0 Requests an fonts.googleapis.com / fonts.gstatic.com im Frontend.
 *  - Saira liefert das Plugin self-hosted aus (assets/fonts/saira.css).
 *  - Verbliebene Google-Font-/Icon-Links (z. B. Slider Revolution „Material Icons",
 *    Theme-/OMGF-/WPCode-Reste) werden mehrstufig entfernt:
 *      1) dequeue/deregister bekannter Handles,
 *      2) style_loader_tag-Filter für enqueuete Styles,
 *      3) GARANTIE: Scrub der finalen Seiten-HTML (fängt auch direkt ausgegebene <link>/@import,
 *         egal wie/wann sie emittiert werden) — NUR Saira + Material Icons, andere Google-Fonts bleiben.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Fonts {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_external' ), 100 );
		add_filter( 'style_loader_tag', array( __CLASS__, 'strip_google' ), 10, 3 );
		// Garantie-Scrub der gesamten Frontend-HTML (äußerster Puffer; verschachtelt sauber außerhalb
		// des SEO-Head-Puffers, da template_redirect VOR wp_head feuert).
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 0 );
	}

	/** Bekannte Handles (Slider Revolution „Material Icons" von Google) im Frontend entfernen. */
	public static function dequeue_external() {
		if ( is_admin() ) { return; }
		foreach ( array( 'tp-material-icons', 'rs-material-icons' ) as $h ) {
			wp_dequeue_style( $h );
			wp_deregister_style( $h );
		}
	}

	/** Enqueuete Google-Links NUR für Saira + Material Icons kappen (andere Familien bleiben). */
	public static function strip_google( $tag, $handle, $href ) {
		if ( is_admin() ) { return $tag; }
		return self::is_target_link( (string) $tag, (string) $href ) ? '' : $tag;
	}

	/** Output-Buffer über die gesamte Frontend-Seite starten (Garantie-Scrub). */
	public static function start_buffer() {
		if ( is_admin() || is_feed() || is_robots() ) { return; }
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) { return; }
		ob_start( array( __CLASS__, 'scrub' ) );
	}

	/** Finale HTML säubern: Google-Saira-/Material-Icons-<link> + zugehörige @import-Regeln entfernen. */
	public static function scrub( $html ) {
		if ( false === stripos( $html, 'fonts.googleapis.com' ) && false === stripos( $html, 'fonts.gstatic.com' ) ) {
			return $html;
		}
		// <link …>-Tags (Saira / Material Icons von Google).
		$html = preg_replace_callback(
			'#<link\b[^>]*>#is',
			static function ( $m ) { return self::is_target_link( $m[0], $m[0] ) ? '' : $m[0]; },
			$html
		);
		// @import-Regeln in Inline-<style> (Saira / Material Icons von Google).
		$html = preg_replace(
			'#@import\s+url\(\s*[\'"]?https?://fonts\.(?:googleapis|gstatic)\.com/[^)\'"]*(?:Saira|Material)[^)\'"]*[\'"]?\s*\)\s*;#i',
			'',
			$html
		);
		return $html;
	}

	/** True, wenn die Quelle ein Google-Saira- ODER Material-Icons-Asset ist. */
	private static function is_target_link( $haystack, $href ) {
		$is_google = ( false !== stripos( $haystack, 'fonts.googleapis.com' ) || false !== stripos( $haystack, 'fonts.gstatic.com' )
			|| false !== stripos( (string) $href, 'fonts.googleapis.com' ) || false !== stripos( (string) $href, 'fonts.gstatic.com' ) );
		if ( ! $is_google ) { return false; }
		$probe = $haystack . ' ' . (string) $href;
		return ( false !== stripos( $probe, 'Saira' ) || false !== stripos( $probe, 'Material' ) );
	}
}
