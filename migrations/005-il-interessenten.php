<?php
/**
 * Migration 005 — Interessentenlisten-Spiegel (Fahrzeug-Alert-Fundament)
 *
 * Spiegel-Tabelle zur DOI-bestätigten Interessentenliste (Brevo bleibt Master für den
 * Versand) plus Relationstabelle für sauberes, dedupliziertes Zählen je Alert-Tag.
 *
 * dbDelta-Konventionen wie Migration 002: VARCHAR statt ENUM, keine harten FKs.
 *   - {prefix}m24_il_interessenten        (1 Zeile je E-Mail, UNIQUE)
 *   - {prefix}m24_il_interessenten_tags   (email ↔ tag, UNIQUE-Paar)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_005() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $prefix          = $wpdb->prefix . 'm24_';

    $sql_main = "CREATE TABLE {$prefix}il_interessenten (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        kundentyp VARCHAR(40) NULL,
        name VARCHAR(190) NULL,
        consent_at DATETIME NULL,
        source_inserat_id BIGINT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'aktiv',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_email (email),
        KEY idx_status (status),
        KEY idx_source (source_inserat_id)
    ) $charset_collate;";

    $sql_tags = "CREATE TABLE {$prefix}il_interessenten_tags (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        tag VARCHAR(60) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_email_tag (email, tag),
        KEY idx_email (email),
        KEY idx_tag (tag)
    ) $charset_collate;";

    dbDelta( $sql_main );
    dbDelta( $sql_tags );

    // Sanity-Check: beide Tabellen vorhanden?
    foreach ( array( 'il_interessenten', 'il_interessenten_tags' ) as $name ) {
        $table  = $prefix . $name;
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $exists !== $table ) {
            error_log( 'M24 Plattform Migration 005: Tabelle fehlt: ' . $table );
            return false;
        }
    }

    return true;
}
