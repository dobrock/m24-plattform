<?php
/**
 * M24 Plattform — Admin-Bar aufräumen.
 *
 * Entfernt Fremd-Plugin-Ballast aus der Admin-Bar und ergänzt schnelle M24-Sprungziele.
 * Hohe Priorität (999), damit die Fremd-Nodes zum Zeitpunkt des Entfernens schon da sind.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Adminbar {

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'cleanup' ), 999 );
	}

	/**
	 * @param WP_Admin_Bar $bar
	 */
	public static function cleanup( $bar ) {
		// 1) Fremd-Ballast entfernen (filterbar, falls mal etwas bleiben soll).
		$remove = apply_filters( 'm24_adminbar_remove_nodes', array(
			'rcb-top-node',           // Cookies (Real Cookie Banner)
			'omgf',                   // OMGF
			'td_live_css_css_writer', // Live CSS
			'wp-rocket',              // WP Rocket
		) );
		foreach ( (array) $remove as $node_id ) {
			$bar->remove_node( $node_id );
		}

		// 2) M24-Sprungziele — nur für Redakteure/Admins.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$bar->add_node( array(
			'id'    => 'm24-inserate',
			'title' => 'Inserat-Verwaltung',
			'href'  => admin_url( 'admin.php?page=m24fz-verwaltung' ),
		) );
		$bar->add_node( array(
			'id'    => 'm24-alle-teile',
			'title' => 'Alle Teile',
			'href'  => admin_url( 'edit.php?post_type=m24_teil' ),
		) );
	}
}
