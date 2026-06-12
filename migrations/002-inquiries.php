<?php
/**
 * Migration 002 — Inquiries-Tabelle (Vollausbau)
 *
 * Erweitert {prefix}m24_anfragen vom Skelett (id + created_at) auf das volle
 * Schema gemaess Spec v4 Kapitel 7.3.
 *
 * dbDelta ist idempotent: bestehende Spalten bleiben, neue werden ergaenzt.
 *
 * Bewusste Abweichungen von Spec v4 (Kompatibilitaet mit dbDelta):
 *  - ENUM('cart','contact_form',...) -> VARCHAR(40)
 *      dbDelta hat bekannte Probleme mit ENUM-Diffs, validiert wird auf PHP-Ebene.
 *  - JSON -> LONGTEXT
 *      dbDelta unterstuetzt JSON-Typ erst zuverlaessig ab MySQL 8.0.17 / WP 6.6.
 *      Wir validieren JSON beim Schreiben in PHP, koennen spaeter zu JSON migrieren.
 *  - Kein FOREIGN KEY auf {prefix}m24_haendler
 *      WordPress-Standard: keine harten FKs, weil Plugin-Cleanup sonst zickt.
 *      Konsistenz wird auf Code-Ebene erzwungen.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_002() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix . 'm24_';

    $sql = "CREATE TABLE {$prefix}anfragen (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

        haendler_id BIGINT UNSIGNED NULL,
        guest_email VARCHAR(160) NULL,
        guest_firma VARCHAR(160) NULL,

        inquiry_source VARCHAR(40) NOT NULL,
        inquiry_source_meta LONGTEXT NULL,
        sender_lang VARCHAR(5) NOT NULL DEFAULT 'de',

        customer_data LONGTEXT NOT NULL,
        items LONGTEXT NOT NULL,
        notes TEXT NULL,
        dsgvo_consent_meta LONGTEXT NOT NULL,

        m24_order_id BIGINT UNSIGNED NULL,
        m24_order_num VARCHAR(40) NULL,

        sync_status VARCHAR(40) NOT NULL DEFAULT 'pending_api_push',
        sync_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        sync_last_error TEXT NULL,
        sync_last_attempt_at DATETIME NULL,

        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        KEY idx_sync_status (sync_status, sync_last_attempt_at),
        KEY idx_haendler (haendler_id, created_at),
        KEY idx_guest_email (guest_email),
        KEY idx_inquiry_source (inquiry_source),
        KEY idx_created (created_at)
    ) $charset_collate;";

    dbDelta( $sql );

    // Sanity-Check: kritische neue Spalten vorhanden?
    $name = $prefix . 'anfragen';
    $required = [
        'haendler_id', 'inquiry_source', 'sender_lang',
        'customer_data', 'items', 'dsgvo_consent_meta',
        'sync_status', 'sync_attempts', 'updated_at',
    ];
    $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$name}", 0 );
    $missing = array_diff( $required, (array) $columns );
    if ( ! empty( $missing ) ) {
        error_log(
            'M24 Plattform Migration 002: fehlende Spalten in '
            . $name . ': ' . implode( ',', $missing )
        );
        return false;
    }

    return true;
}
