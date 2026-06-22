<?php
/**
 * M24 — Externe Schrift-Requests unterbinden
 * Modul: includes/class-m24-fonts.php
 *
 * Ziel: 0 Requests an fonts.googleapis.com / fonts.gstatic.com im Frontend.
 *  - Saira liefert das Plugin selbst self-hosted aus (assets/fonts/saira.css).
 *  - Slider Revolution (ThemePunch) registriert Google „Material Icons" unter dem Handle
 *    `tp-material-icons` — im Frontend ungenutzt → dequeue/deregister.
 *  - Sicherheitsnetz: jeden verbleibenden Style-Link auf googleapis/gstatic im Frontend kappen.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Fonts {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_external' ), 100 );
		add_filter( 'style_loader_tag', array( __CLASS__, 'strip_google' ), 10, 3 );
	}

	/** Slider-Revolution-„Material Icons" (Google) im Frontend entfernen. */
	public static function dequeue_external() {
		if ( is_admin() ) { return; }
		foreach ( array( 'tp-material-icons', 'revslider-material-icons' ) as $h ) {
			wp_dequeue_style( $h );
			wp_deregister_style( $h );
		}
	}

	/**
	 * Sicherheitsnetz: verbliebene Google-Links NUR für Saira + Material Icons kappen
	 * (Theme-/Fremd-Google-Fonts anderer Familien bleiben unberührt).
	 */
	public static function strip_google( $tag, $handle, $href ) {
		if ( is_admin() ) { return $tag; }
		$h = (string) $href;
		$is_google = ( false !== strpos( $h, 'fonts.googleapis.com' ) || false !== strpos( $h, 'fonts.gstatic.com' ) );
		if ( ! $is_google ) { return $tag; }
		if ( false !== stripos( $h, 'family=Saira' ) || false !== stripos( $h, 'Material+Icons' ) || false !== stripos( $h, 'material-icons' ) ) {
			return '';
		}
		return $tag;
	}
}
