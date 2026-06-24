<?php
/**
 * M24 Plattform — Kommentare site-weit deaktivieren.
 *
 * B2B-Plattform mit eigenen CPTs — Kommentare/Trackbacks sind unnötig. Wir entfernen den
 * Editor-Support, halten comments_open/pings_open hart auf false, blenden das Comments-
 * Admin-Menü aus und setzen die Discussion-Defaults einmalig auf „geschlossen".
 * (Der Admin-Bar-Node „comments" fliegt über M24_Adminbar::remove_nodes raus.)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Comments {

	const DEFAULTS_FLAG = 'm24_comments_off_v1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'remove_support' ), 100 ); // nach CPT-Registrierung (init:5)
		add_filter( 'comments_open', '__return_false', 100 );
		add_filter( 'pings_open', '__return_false', 100 );
		add_action( 'admin_menu', array( __CLASS__, 'hide_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_set_defaults' ) );
	}

	/** Editor-Support für comments + trackbacks auf post/page + M24-CPTs entfernen. */
	public static function remove_support() {
		$types = apply_filters( 'm24_comments_disable_types', array(
			'post', 'page', 'm24_teil', 'm24_fahrzeug', 'm24_modellhub',
		) );
		foreach ( (array) $types as $t ) {
			remove_post_type_support( $t, 'comments' );
			remove_post_type_support( $t, 'trackbacks' );
		}
	}

	/** Comments-Admin-Menü ausblenden. */
	public static function hide_menu() {
		remove_menu_page( 'edit-comments.php' );
	}

	/** Discussion-Defaults einmalig: neue Beiträge ohne Kommentare/Pings. */
	public static function maybe_set_defaults() {
		if ( get_option( self::DEFAULTS_FLAG ) ) {
			return;
		}
		update_option( self::DEFAULTS_FLAG, gmdate( 'c' ) );
		update_option( 'default_comment_status', 'closed' );
		update_option( 'default_ping_status', 'closed' );
	}
}
