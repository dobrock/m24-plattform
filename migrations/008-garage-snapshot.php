<?php
/**
 * Migration 008 — Garage-Share-Snapshot.
 *
 * Eingefrorene Kopie der Teile-Merkzettel-Positionen je Share-Token. Die öffentliche
 * Read-only-Share-Ansicht rendert aus diesem Snapshot (nicht mehr aus dem Live-Cart):
 * später hinzugefügte Teile erscheinen NICHT, gelöschte BLEIBEN sichtbar. Rotate = neuer
 * Token + neuer Snapshot. dbDelta-Konventionen: VARCHAR statt ENUM, keine harten FKs.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_008() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $table           = $wpdb->prefix . 'm24_garage_snapshot';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        share_token VARCHAR(190) NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        items_json LONGTEXT NULL,
        totals_json TEXT NULL,
        PRIMARY KEY (id),
        KEY idx_token (share_token),
        KEY idx_account (account_id)
    ) $charset_collate;";

    dbDelta( $sql );

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        error_log( 'M24 Plattform Migration 008: Tabelle fehlt: ' . $table );
        return false;
    }
    return true;
}
