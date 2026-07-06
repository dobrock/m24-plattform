<?php
/**
 * Migration 014 — Produkt-Varianten durch die Garage-Kette.
 * Erweitert m24_garage_cart um Varianten-Spalten (Label/Art-Nr./Preis) und weitet den Unique-Key
 * auf die Variante aus, damit dieselbe Teile-ID in mehreren Varianten getrennt in der Garage liegen kann.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
function m24_migration_014() {
	global $wpdb;
	$t = $wpdb->prefix . 'm24_garage_cart';

	// Tabelle noch nicht vorhanden? ensure_table() legt sie mit neuem Schema an → nichts zu tun.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
		return;
	}

	$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$t}`", 0 );
	if ( ! in_array( 'variant_label', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN variant_label VARCHAR(190) NOT NULL DEFAULT '' AFTER post_id" );
	}
	if ( ! in_array( 'variant_artnr', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN variant_artnr VARCHAR(100) NOT NULL DEFAULT '' AFTER variant_label" );
	}
	if ( ! in_array( 'variant_price', $cols, true ) ) {
		$wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN variant_price DECIMAL(10,2) NULL AFTER variant_artnr" );
	}

	// Unique-Key von (account, type, id) auf (…, variant_label) ausweiten.
	$idx   = (array) $wpdb->get_results( "SHOW INDEX FROM `{$t}`", ARRAY_A );
	$names = array_map( function ( $r ) { return $r['Key_name']; }, $idx );
	if ( in_array( 'uniq_pos', $names, true ) ) {
		$wpdb->query( "ALTER TABLE `{$t}` DROP INDEX uniq_pos" );
	}
	if ( ! in_array( 'uniq_pos_v', $names, true ) ) {
		$wpdb->query( "ALTER TABLE `{$t}` ADD UNIQUE KEY uniq_pos_v (account_id, post_type, post_id, variant_label)" );
	}
}
