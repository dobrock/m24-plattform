<?php
/**
 * Migration 027 — Zeilen-Tombstones für Line-Item-LWW (Spec v1.3 §3).
 *
 * Neue Spalte:
 *   deleted_lines_json LONGTEXT NULL — entfernte Positionen als Tombstone-Liste:
 *       [ { line_uid, deleted_at (UTC), rev, origin }, ... ]
 *
 * Warum eine eigene Spalte statt eines deleted_at IM Item?
 * items_json wird an 38 Stellen quer durchs Plugin gelesen (Mail, Kunden-Ansicht, Liste, Operator,
 * W1-Payload, Garage-Bridge …), fast überall mit einem schlichten json_decode + foreach. Lägen
 * Tombstones darin, müsste JEDE dieser Stellen sie herausfiltern — eine übersehene zeigt gelöschte
 * Positionen an oder rechnet sie in die Summe. Getrennt gehalten bleibt items_json exakt das, was es
 * heute ist: die Liste der aktiven Positionen. Nur der Sync liest beide Felder.
 *
 * Rein additiv, idempotent via dbDelta; voller Schema-Restate ist dbDelta-Pflicht.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_027() {
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
        wp_offer_uid VARCHAR(64) NOT NULL DEFAULT '',
        customer_uid VARCHAR(64) NOT NULL DEFAULT '',
        updated_at DATETIME NULL,
        origin VARCHAR(8) NOT NULL DEFAULT 'wp',
        rev INT UNSIGNED NOT NULL DEFAULT 1,
        last_synced_rev INT UNSIGNED NOT NULL DEFAULT 0,
        last_synced_at DATETIME NULL,
        supersedes VARCHAR(64) NOT NULL DEFAULT '',
        superseded_by VARCHAR(64) NOT NULL DEFAULT '',
        needs_resend TINYINT(1) NOT NULL DEFAULT 0,
        deleted_lines_json LONGTEXT NULL,
        PRIMARY KEY (id),
        KEY idx_wp_offer_uid (wp_offer_uid),
        KEY idx_customer_uid (customer_uid),
        KEY idx_updated (updated_at),
        KEY idx_superseded (superseded_by),
        KEY idx_needs_resend (needs_resend),
        UNIQUE KEY uniq_offer_no (offer_no),
        KEY idx_token (token),
        KEY idx_account (account_id),
        KEY idx_status (status),
        KEY idx_desk_sync (desk_sync_status),
        KEY idx_desk_order_id (desk_order_id),
        KEY idx_deleted (deleted_at)
    ) $charset_collate;";

    dbDelta( $sql );

    $have = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'deleted_lines_json' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
    if ( 'deleted_lines_json' !== $have ) {
        error_log( 'M24 Plattform Migration 027: Spalte deleted_lines_json fehlt nach dbDelta an ' . $table );
        return false;
    }
    return true;
}
