<?php
/**
 * M24 Admin-Bar: Direktlink zum KORREKTEN Editor je CPT (Frontend, eingeloggt, edit-Rechte).
 *
 * - singular m24_fahrzeug → M24-Fahrzeug-Editor (edit.php?post_type=m24_fahrzeug&page=m24fz-editor&post=ID)
 * - singular m24_teil     → klassischer WP-Editor (get_edit_post_link; m24_teil nutzt die Classic-Maske)
 * - sonst (page/post)     → Standard-Editor (get_edit_post_link)
 * Der WP-Default-„Bearbeiten"-Knoten für diese Ansichten wird entfernt (kein Doppel-Link).
 * Bonus: auf /en/-Seiten (GTranslate) zusätzlich „🌐 Übersetzung bearbeiten" → aktuelle URL + ?language_edit=1.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Admin_Bar {

	public static function init() {
		// Priorität 90: nach dem Core-„edit"-Knoten (wp_admin_bar_edit_menu, prio 80) → wir können ihn ersetzen.
		add_action( 'admin_bar_menu', array( __CLASS__, 'nodes' ), 90 );
		add_action( 'wp_head', array( __CLASS__, 'style' ) );
	}

	public static function nodes( $bar ) {
		if ( is_admin() || ! is_user_logged_in() || ! is_singular() ) { return; }
		$id = (int) get_queried_object_id();
		if ( $id <= 0 ) { return; }
		$pt = (string) get_post_type( $id );
		if ( ! in_array( $pt, array( 'm24_fahrzeug', 'm24_teil', 'page', 'post' ), true ) ) { return; }
		if ( ! current_user_can( 'edit_post', $id ) ) { return; }

		// Korrekte Editor-URL je CPT.
		if ( 'm24_fahrzeug' === $pt ) {
			$url = admin_url( 'edit.php?post_type=m24_fahrzeug&page=m24fz-editor&post=' . $id );
		} else {
			$url = (string) get_edit_post_link( $id, 'raw' );
		}
		if ( '' === $url ) { return; }

		// Core-„Bearbeiten"-Knoten entfernen → kein doppelter Bearbeiten-Link.
		$bar->remove_node( 'edit' );

		$bar->add_node( array(
			'id'    => 'm24-edit',
			'title' => '✎ M24 bearbeiten',
			'href'  => $url,
			'meta'  => array( 'class' => 'm24-adminbar-edit' ),
		) );

		// Bonus: GTranslate-/en/-Seite → „Übersetzung bearbeiten" (aktuelle URL + ?language_edit=1).
		if ( self::is_en_url() ) {
			$cur = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' );
			$bar->add_node( array(
				'id'    => 'm24-translate-edit',
				'title' => '🌐 Übersetzung bearbeiten',
				'href'  => esc_url_raw( add_query_arg( 'language_edit', '1', $cur ) ),
				'meta'  => array( 'class' => 'm24-adminbar-edit' ),
			) );
		}
	}

	/** Aktuelle URL ist eine /en/-GTranslate-Seite? */
	private static function is_en_url(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return (bool) preg_match( '#^/en/#', $uri );
	}

	/** Dezentes Highlight, damit der M24-Knoten klar erkennbar ist. */
	public static function style() {
		if ( is_admin() || ! is_user_logged_in() || ! is_admin_bar_showing() ) { return; }
		echo '<style id="m24-adminbar-css">#wpadminbar li#wp-admin-bar-m24-edit>.ab-item,#wpadminbar li#wp-admin-bar-m24-translate-edit>.ab-item{background:#9a6b25!important;color:#fff!important;font-weight:600}</style>' . "\n";
	}
}
