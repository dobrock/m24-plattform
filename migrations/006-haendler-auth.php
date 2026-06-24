<?php
/**
 * Migration 006 — Händler-Auth Daten-Spine (Garage Phase A)
 *
 * Erweitert das bestehende {prefix}m24_haendler-Skelett (aus 001) additiv um die
 * Auth-/Status-Felder und legt {prefix}m24_magic_tokens (Magic-Link-Login) an.
 *
 * dbDelta-Konventionen wie 001/002: VARCHAR statt ENUM (dbDelta-sicher), KEINE harten
 * FOREIGN KEYs (Referenz-Integrität in PHP). Plus: Rolle m24_haendler idempotent anlegen.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function m24_migration_006() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix . 'm24_';

    $sql_haendler = "CREATE TABLE {$prefix}haendler (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        wp_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        firma VARCHAR(160) NOT NULL DEFAULT '',
        uid VARCHAR(40) NULL,
        uid_validated_at DATETIME NULL,
        uid_valid TINYINT(1) NULL,
        land CHAR(2) NOT NULL DEFAULT '',
        sprach_praeferenz VARCHAR(2) NOT NULL DEFAULT 'de',
        status VARCHAR(24) NOT NULL DEFAULT 'pending_verification',
        approved_by BIGINT UNSIGNED NULL,
        approved_at DATETIME NULL,
        notes_intern TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_wp_user (wp_user_id),
        KEY idx_status (status),
        KEY idx_uid (uid)
    ) $charset_collate;";

    $sql_tokens = "CREATE TABLE {$prefix}magic_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        wp_user_id BIGINT UNSIGNED NULL,
        token_hash CHAR(64) NOT NULL,
        purpose VARCHAR(20) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        ip_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_token (token_hash),
        KEY idx_email (email),
        KEY idx_expires (expires_at)
    ) $charset_collate;";

    dbDelta( $sql_haendler );
    dbDelta( $sql_tokens );

    // Sanity-Check: beide Tabellen vorhanden?
    foreach ( [ 'haendler', 'magic_tokens' ] as $t ) {
        $name   = $prefix . $t;
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $name ) );
        if ( $exists !== $name ) {
            error_log( "M24 Plattform Migration 006: Tabelle $name wurde nicht erstellt" );
            return false;
        }
    }

    // Händler-Rolle idempotent anlegen (read-only; Capabilities kommen mit dem Auth-Modul).
    if ( ! get_role( 'm24_haendler' ) ) {
        add_role( 'm24_haendler', 'M24 Händler', [ 'read' => true ] );
    }

    return true;
}
