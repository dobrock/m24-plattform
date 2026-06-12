<?php
/**
 * Migration 001 — Skeleton-Tabellen
 *
 * Inhalt:
 *  - {prefix}m24_anfragen   (Skelett, Felder kommen mit Inquiries-Modul)
 *  - {prefix}m24_haendler   (Skelett, Felder kommen mit Auth-Modul)
 *  - {prefix}m24_sync_log   (vollstaendig, weil Logger sie sofort braucht)
 *
 * Weitere Tabellen (m24_redirects, m24_bestand, m24_katalog_*) kommen
 * als eigene Migrationen, sobald die jeweiligen Module gebaut werden.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function m24_migration_001() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix . 'm24_';

    // ── m24_anfragen (Skelett) ──────────────────────────────────────────
    $sql_anfragen = "CREATE TABLE {$prefix}anfragen (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // ── m24_haendler (Skelett) ──────────────────────────────────────────
    $sql_haendler = "CREATE TABLE {$prefix}haendler (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // ── m24_sync_log (voll) ─────────────────────────────────────────────
    $sql_log = "CREATE TABLE {$prefix}sync_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level VARCHAR(20) NOT NULL,
        context VARCHAR(50) NOT NULL,
        message TEXT,
        payload_json LONGTEXT,
        PRIMARY KEY (id),
        KEY idx_level (level),
        KEY idx_context (context),
        KEY idx_created (created_at)
    ) $charset_collate;";

    // dbDelta ist idempotent — laesst bestehende Tabellen/Spalten in Ruhe
    dbDelta( $sql_anfragen );
    dbDelta( $sql_haendler );
    dbDelta( $sql_log );

    // Sanity-Check: alle drei Tabellen vorhanden?
    foreach ( [ 'anfragen', 'haendler', 'sync_log' ] as $t ) {
        $name = $prefix . $t;
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $name )
        );
        if ( $exists !== $name ) {
            error_log( "M24 Plattform Migration 001: Tabelle $name wurde nicht erstellt" );
            return false;
        }
    }

    return true;
}
