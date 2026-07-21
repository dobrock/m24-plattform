<?php
/**
 * Migration 025 — Soft-Delete/Papierkorb (m24_offers).
 *
 * Neue Spalte:
 *   deleted_at DATETIME NULL — Zeitpunkt der (Soft-)Löschung. NULL = aktiv, gesetzt = im Papierkorb.
 *                              Dient zugleich als Tombstone: eine gelöschte gesyncte Zeile bleibt erhalten
 *                              (deleted_at gesetzt), damit der Re-Sync (Inbound-Upsert / 10-Tage-Mirror) sie
 *                              über die vorhandene desk_order_id findet und NICHT wieder als aktiv anlegt.
 *                              Auto-Purge (Cron) löscht endgültig nach 10 Tagen.
 *
 * Rein additiv, idempotent via dbDelta; voller Schema-Restate ist dbDelta-Pflicht (diff-t auf die Zielform).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_025() {
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
        desk_sync_status VARCHAR(20) NULL,
        desk_synced_at DATETIME NULL,
        desk_sync_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        desk_sync_error TEXT NULL,
        field_updated_at LONGTEXT NULL,
        payment_date DATETIME NULL,
        carrier VARCHAR(60) NULL,
        tracking VARCHAR(190) NULL,
        packages TEXT NULL,
        completed_steps LONGTEXT NULL,
        sevdesk_invoice_number VARCHAR(60) NULL,
        sevdesk_invoice_pdf_r2_key VARCHAR(255) NULL,
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
        ship_strasse2 VARCHAR(190) NULL,
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
        deleted_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_offer_no (offer_no),
        KEY idx_token (token),
        KEY idx_account (account_id),
        KEY idx_status (status),
        KEY idx_desk_sync (desk_sync_status),
        KEY idx_desk_order_id (desk_order_id),
        KEY idx_deleted (deleted_at)
    ) $charset_collate;";

    dbDelta( $sql );

    $have = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'deleted_at' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
    if ( 'deleted_at' !== $have ) {
        error_log( 'M24 Plattform Migration 025: Spalte deleted_at fehlt nach dbDelta an ' . $table );
        return false;
    }
    return true;
}
