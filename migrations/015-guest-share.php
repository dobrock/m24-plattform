<?php
/**
 * Migration 015 — Anonymer 1-Wochen-Gast-Share.
 * Eigene Ablage m24_guest_share: 7-Tage-TTL + Auto-Prune. Inhalt nur Item-IDs/Varianten/Mengen (keine PII).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
function m24_migration_015() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$cc    = $wpdb->get_charset_collate();
	$table = $wpdb->prefix . 'm24_guest_share';

	dbDelta( "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		token CHAR(32) NOT NULL,
		items_json LONGTEXT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		expires_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY token (token),
		KEY expires_at (expires_at)
	) {$cc};" );
}
