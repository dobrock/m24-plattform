<?php
/**
 * Migration 011 — Angebots-Workflow v1: Tabelle m24_offers.
 *
 * Ein Angebot referenziert eine Garage/Anfrage: Positionen + Zusatzpositionen + Lieferzeit + manuelle
 * Steuer (Brutto/Netto/§25a) + Gültig-bis + Status + Nummernkreis (2026-0042). Alles feingranular als
 * JSON, Beträge zusätzlich als DECIMAL für Reports. Rein additiv, idempotent via dbDelta.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_011() {
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

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        error_log( 'M24 Plattform Migration 011: Tabelle fehlt: ' . $table );
        return false;
    }
    return true;
}
