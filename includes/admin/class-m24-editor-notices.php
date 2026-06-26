<?php
/**
 * M24 Plattform — Admin-Notices auf den M24-Editoren unterdrücken.
 *
 * Nur auf der Fahrzeug-Komfort-Maske (Submenu-Page „m24fz-editor") und dem Teil-Editor
 * (natives post.php für m24_teil; klassischer Fahrzeug-Editor inkl.) werden Fremd-Admin-
 * Notices ausgeblendet: Real Cookie Banner („erneut scannen"), WP Rocket („Cache leeren")
 * sowie die M24-Update-Erfolgsmeldung. Greift VOR do_action('admin_notices') (in_admin_header)
 * und NUR per get_current_screen()-Guard — alle anderen Admin-Seiten bleiben unberührt.
 *
 * Kritische WP-Core-Notices (Core-Update / Wartung) werden nach dem Wipe wieder angehängt.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Editor_Notices {

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'in_admin_header', array( __CLASS__, 'maybe_suppress' ), 1000 );
	}

	/** Genau die beiden M24-Bearbeiten-Screens (+ klassischer Fahrzeug-Editor). */
	private static function is_editor_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$s = get_current_screen();
		if ( ! $s ) {
			return false;
		}
		// 1) Komfort-Maske = Submenu-Page, deren Screen-ID den Page-Slug enthält.
		if ( class_exists( 'M24FZ_Editor_Screen' ) && false !== strpos( (string) $s->id, M24FZ_Editor_Screen::PAGE ) ) {
			return true;
		}
		// 2) Teil-Editor (+ klassischer Fahrzeug-Editor): natives post.php/post-new.php.
		$pt = (string) ( $s->post_type ?? '' );
		if ( in_array( (string) $s->base, array( 'post' ), true ) && in_array( $pt, array( 'm24_teil', 'm24_fahrzeug' ), true ) ) {
			return true;
		}
		return false;
	}

	public static function maybe_suppress() {
		if ( ! self::is_editor_screen() ) {
			return;
		}
		// Per Filter abschaltbar (Default: an).
		if ( ! apply_filters( 'm24_editor_suppress_notices', true ) ) {
			return;
		}

		// Alle registrierten Notice-Callbacks auf diesen Screens entfernen (RCB, WP Rocket,
		// M24-Update-Meldung, sonstige Plugin-Nags). Läuft vor dem do_action() in admin-header.php.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );

		// Kritische WP-Core-Notices wieder anhängen (Core-Update + Wartungs-Hinweis).
		if ( function_exists( 'update_nag' ) ) {
			add_action( 'admin_notices', 'update_nag', 3 );
		}
		if ( function_exists( 'maintenance_nag' ) ) {
			add_action( 'admin_notices', 'maintenance_nag', 10 );
		}
	}
}
