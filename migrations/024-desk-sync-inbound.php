<?php
/**
 * Migration 024 — Desk→WP-Inbound-Felder (m24_offers).
 *
 * Additive Spalten für die Desk-Hoheitsfelder, die der Inbound-Webhook (D1–D3) feldgenau einspielt:
 *   payment_date               DATETIME     NULL — Zahlungseingang laut Desk (D1). Nicht identisch mit paid_at:
 *                                                  paid_at ist der WP-seitige Statuswechsel, payment_date der
 *                                                  vom Desk gemeldete Wert. Beide zu führen hält die Quelle sichtbar.
 *   carrier                    VARCHAR(60)  NULL — Versanddienstleister (D2).
 *   tracking                   VARCHAR(190) NULL — Sendungsnummer (D2).
 *   packages                   TEXT         NULL — Paketangabe (D2). Bewusst TEXT statt INT: der Vertrag legt
 *                                                  weder Skalar noch Struktur fest — Skalare landen als String,
 *                                                  Arrays/Objekte JSON-kodiert. Kein Datenverlust bei Formwechsel.
 *   completed_steps            LONGTEXT     NULL — JSON-Array der erledigten Auftragsschritte (D3).
 *   sevdesk_invoice_number     VARCHAR(60)  NULL — Rechnungsnummer aus sevDesk (D3).
 *   sevdesk_invoice_pdf_r2_key VARCHAR(255) NULL — R2-Objekt-Key des Rechnungs-PDFs (D3). KEIN Public-Link —
 *                                                  nur speichern/anzeigen, kein Direktabruf.
 *   ship_strasse2              VARCHAR(190) NULL — Zusatzzeile der Lieferanschrift. Der Outbound sendet sie
 *                                                  bislang leer (confirm_body), weil es die Spalte nicht gab;
 *                                                  der Inbound kann sie jetzt ablegen.
 *
 * Zusätzlich KEY idx_desk_order_id: der Inbound sucht das Angebot über desk_order_id == data.id — bisher ein
 * Full-Table-Scan je Webhook.
 *
 * Rein additiv, idempotent via dbDelta; voller Schema-Restate ist dbDelta-Pflicht (diff-t auf die Zielform).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_024() {
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
        PRIMARY KEY (id),
        UNIQUE KEY uniq_offer_no (offer_no),
        KEY idx_token (token),
        KEY idx_account (account_id),
        KEY idx_status (status),
        KEY idx_desk_sync (desk_sync_status),
        KEY idx_desk_order_id (desk_order_id)
    ) $charset_collate;";

    dbDelta( $sql );

    foreach ( array( 'payment_date', 'carrier', 'tracking', 'packages', 'completed_steps',
        'sevdesk_invoice_number', 'sevdesk_invoice_pdf_r2_key', 'ship_strasse2' ) as $col ) {
        $have = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $col ) ); // phpcs:ignore WordPress.DB.PreparedSQL
        if ( $col !== $have ) {
            error_log( 'M24 Plattform Migration 024: Spalte ' . $col . ' fehlt nach dbDelta an ' . $table );
            return false;
        }
    }
    return true;
}
