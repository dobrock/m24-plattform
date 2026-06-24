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
		// Eigene Nodes früh genug ergänzen.
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_nodes' ), 999 );
		// Fremd-Ballast NACH allen Registrierungen entfernen (omgf/Live-CSS/WP-Rocket
		// hängen sich spät ein → admin_bar_menu/999 läuft davor und greift ins Leere).
		add_action( 'wp_before_admin_bar_render', array( __CLASS__, 'remove_nodes' ), 99999 );
	}

	/** Fremd-Plugin-Nodes entfernen (filterbar). */
	public static function remove_nodes() {
		global $wp_admin_bar;
		if ( ! $wp_admin_bar ) {
			return;
		}
		$remove = apply_filters( 'm24_adminbar_remove_nodes', array(
			'rcb-top-node',           // Cookies (Real Cookie Banner)
			'omgf',                   // OMGF
			'td_live_css_css_writer', // Live CSS
			'wp-rocket',              // WP Rocket
		) );
		foreach ( (array) $remove as $node_id ) {
			$wp_admin_bar->remove_node( $node_id );
		}
	}

	/**
	 * M24-Sprungziele ergänzen — nur für Redakteure/Admins.
	 *
	 * @param WP_Admin_Bar $bar
	 */
	public static function add_nodes( $bar ) {
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
