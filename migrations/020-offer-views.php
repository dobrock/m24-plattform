<?php
/**
 * Migration 020 — „Angebot angesehen"-Tracking (m24_offers).
 *
 * Drei additive Spalten: viewed_first_at / viewed_last_at (DATETIME, NULL bis zum ersten echten
 * Kunden-/Gast-Aufruf) und view_count (Zähler, Default 0). Operator/Admin-Preview zählt NICHT
 * (Ausschluss im Aufrufer M24_Offers_Render::customer). Rein additiv, idempotent via dbDelta;
 * eigener Migrationsschritt (018/019 laufen auf Bestands-DBs nicht erneut). Voller Schema-Restate
 * ist dbDelta-Pflicht (es diff-t auf die Zielform).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_020() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $table           = $wpdb->prefix . 'm24_offers';

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        offer_no VARCHAR(20) NOT NULL DEFAULT '',
        token VARCHAR(64) NOT NULL DEFAULT '',
        account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'entwurf',
        customer_json TEXT NULL,
        items_json LONGTEXT NULL,
        extras_json TEXT NULL,
        delivery_time VARCHAR(190) NOT NULL DEFAULT '',
        tax_mode VARCHAR(40) NOT NULL DEFAULT '',
        tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        tax_note VARCHAR(255) NOT NULL DEFAULT '',
        subtotal_net DECIMAL(10,2) NOT NULL DEFAULT 0,
        tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_gross DECIMAL(10,2) NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
        valid_until DATE NULL,
        src_json TEXT NULL,
        desk_order_id VARCHAR(64) NOT NULL DEFAULT '',
        bill_anrede VARCHAR(10) NULL,
        bill_vorname VARCHAR(190) NULL,
        bill_nachname VARCHAR(190) NULL,
        bill_firma VARCHAR(190) NULL,
        bill_ustid VARCHAR(32) NULL,
        bill_ustid_vies VARCHAR(12) NULL,
        bill_eori VARCHAR(24) NULL,
        bill_strasse VARCHAR(190) NULL,
        bill_plz VARCHAR(20) NULL,
        bill_ort VARCHAR(190) NULL,
        bill_land VARCHAR(190) NULL,
        bill_telefon VARCHAR(60) NULL,
        ship_diff TINYINT(1) NOT NULL DEFAULT 0,
        ship_anrede VARCHAR(10) NULL,
        ship_vorname VARCHAR(190) NULL,
        ship_nachname VARCHAR(190) NULL,
        ship_firma VARCHAR(190) NULL,
        ship_ustid VARCHAR(32) NULL,
        ship_strasse VARCHAR(190) NULL,
        ship_plz VARCHAR(20) NULL,
        ship_ort VARCHAR(190) NULL,
        ship_land VARCHAR(190) NULL,
        ship_telefon VARCHAR(60) NULL,
        accepted_at DATETIME NULL,
        viewed_first_at DATETIME NULL,
        viewed_last_at DATETIME NULL,
        view_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        paid_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_offer_no (offer_no),
        KEY idx_token (token),
        KEY idx_account (account_id),
        KEY idx_status (status)
    ) $charset_collate;";

    dbDelta( $sql );

    $col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'view_count' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
    if ( 'view_count' !== $col ) {
        error_log( 'M24 Plattform Migration 020: Spalte view_count fehlt nach dbDelta an ' . $table );
        return false;
    }
    return true;
}
