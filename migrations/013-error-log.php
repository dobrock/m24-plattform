<?php
/**
 * Migration 013 — Zentrales Fehlerprotokoll: Tabelle m24_error_log.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_013() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $table           = $wpdb->prefix . 'm24_error_log';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        severity VARCHAR(12) NOT NULL DEFAULT 'error',
        context VARCHAR(40) NOT NULL DEFAULT '',
        message TEXT NULL,
        meta LONGTEXT NULL,
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        url VARCHAR(255) NOT NULL DEFAULT '',
        resolved TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_created (created_at),
        KEY idx_sev (severity),
        KEY idx_ctx (context),
        KEY idx_resolved (resolved)
    ) $charset_collate;";

    dbDelta( $sql );

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        error_log( 'M24 Plattform Migration 013: Tabelle fehlt: ' . $table );
        return false;
    }
    return true;
}
