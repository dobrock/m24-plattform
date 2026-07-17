<?php
/**
 * Migration 022 — Desk-Sync-Felder (m24_offers), Baustein W1.
 *
 * Additive Spalten für den Outbound-Push „Angebot → M24 Desk" (Schnittstellen-Vertrag v1.1):
 *   desk_order_num       VARCHAR(40)  NULL   — Desk-Auftragsnummer aus der 201-Response.
 *   desk_sync_status     VARCHAR(10)  NULL   — pending|synced|failed (NULL = nie versucht).
 *   desk_synced_at       DATETIME     NULL   — Zeitpunkt des letzten erfolgreichen Sync.
 *   desk_sync_attempts   INT          DEF 0  — Versuchszähler (Retry-Cap = 6).
 *   desk_sync_error      TEXT         NULL   — letzte Fehlerdetails (Monitor/Log).
 *   field_updated_at     LONGTEXT     NULL   — JSON: je Feld der letzte Änderungs-Zeitpunkt (W2/W3-Konfliktlösung).
 *
 * HINWEIS zu desk_order_id: existiert bereits seit Migration 011 als VARCHAR(64). Der Vertrag nennt BIGINT;
 * die Desk-Order-ID (numerisch) wird verlustfrei als String in der bestehenden Spalte gehalten — ein Typwechsel
 * einer bereits ausgelieferten, teils befüllten Spalte via dbDelta wäre fragil und bringt keinen Mehrwert.
 *
 * Rein additiv, idempotent via dbDelta; voller Schema-Restate ist dbDelta-Pflicht (diff-t auf die Zielform).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_022() {
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
        desk_order_num VARCHAR(40) NULL,
        desk_sync_status VARCHAR(10) NULL,
        desk_synced_at DATETIME NULL,
        desk_sync_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        desk_sync_error TEXT NULL,
        field_updated_at LONGTEXT NULL,
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
        reminder_sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        paid_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_offer_no (offer_no),
        KEY idx_token (token),
        KEY idx_account (account_id),
        KEY idx_status (status),
        KEY idx_desk_sync (desk_sync_status)
    ) $charset_collate;";

    dbDelta( $sql );

    $col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'desk_sync_status' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
    if ( 'desk_sync_status' !== $col ) {
        error_log( 'M24 Plattform Migration 022: Spalte desk_sync_status fehlt nach dbDelta an ' . $table );
        return false;
    }
    return true;
}
