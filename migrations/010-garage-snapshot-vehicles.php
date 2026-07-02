<?php
/**
 * Migration 010 — Garage-Share-Snapshot: Fahrzeuge mit einfrieren.
 *
 * Ergänzt die Tabelle m24_garage_snapshot (008) um vehicles_json (eingefrorene Fahrzeug-Kopie zum
 * Zeitpunkt des Teilens). dbDelta fügt die fehlende Spalte idempotent an. KEINE FIN/Art.-Nr. im JSON
 * (Datenschutz) — das erledigt der Writer; hier nur das Schema.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_010() {
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
        vehicles_json LONGTEXT NULL,
        PRIMARY KEY (id),
        KEY idx_token (share_token),
        KEY idx_account (account_id)
    ) $charset_collate;";

    dbDelta( $sql );

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        error_log( 'M24 Plattform Migration 010: Tabelle fehlt: ' . $table );
        return false;
    }
    return true;
}
