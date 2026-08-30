<?php
/**
 * Migration 032 — Fassungen statt Nachfolger-Nummern.
 *
 * Bisher entstand bei einer Änderung an einem versendeten Angebot eine NEUE Nummer. Ab jetzt gilt:
 * eine Nummer, mehrere Fassungen, ein Listeneintrag. Die laufende Fassung steht an der Angebotszeile,
 * die Vorfassungen liegen als Beleg in einer eigenen Tabelle — nicht als JSON an der Zeile und nicht
 * als eigene Angebotszeilen (uniq_offer_no lässt das ohnehin nicht zu, und die Liste soll je Vorgang
 * genau einen Eintrag zeigen).
 *
 * An m24_offers:
 *   offer_version          INT     — laufende Fassung, startet bei 1
 *   version_pending        TINYINT — 1 = Fassung geschrieben, aber NICHT versendet
 *   version_pending_reason VARCHAR — warum nicht (i.d.R. "Desk nicht erreichbar")
 *   version_pending_at     DATETIME— UTC, seit wann
 *
 * version_pending ist der EINZIGE erlaubte Zwischenzustand: Das Angebots-PDF kommt vom Desk
 * (M24_Desk_Push::offer_pdf_attachment holt es über offer-artifacts). Ohne Artefakt darf nichts
 * rausgehen — eine Angebotsmail ohne Anhang ist keine. Der Zustand muss an der Karte begründet
 * sichtbar sein, damit er nicht zum stillen Liegenbleiber wird wie 2026-1056.
 *
 * Neue Tabelle m24_offer_versions: je Fassung ein Beleg mit vollständigem Zeilen-Snapshot.
 * purge_trashed() fasst sie nie an — es löscht ausschließlich in m24_offers.
 *
 * Rein additiv, idempotent via dbDelta; Spaltenliste unverändert aus Migration 031 übernommen.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_032() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $table           = $wpdb->prefix . 'm24_offers';
    $versions        = $wpdb->prefix . 'm24_offer_versions';

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
        supersedes_no VARCHAR(20) NOT NULL DEFAULT '',
        supersedes_desk VARCHAR(40) NOT NULL DEFAULT '',
        offer_drift TINYINT(1) NOT NULL DEFAULT 0,
        offer_drift_at DATETIME NULL,
        offer_drift_fields TEXT NULL,
        offer_version INT UNSIGNED NOT NULL DEFAULT 1,
        version_pending TINYINT(1) NOT NULL DEFAULT 0,
        version_pending_reason VARCHAR(190) NULL,
        version_pending_at DATETIME NULL,
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
        KEY idx_deleted (deleted_at),
        KEY idx_version_pending (version_pending)
    ) $charset_collate;";

    dbDelta( $sql );

    // Beleg-Tabelle. snapshot_json hält die vollständige Angebotszeile zum Zeitpunkt des Versands —
    // ein Beleg, der sich später nicht mehr aus dem laufenden Stand rekonstruieren ließe.
    $sql_v = "CREATE TABLE {$versions} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        offer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        offer_no VARCHAR(20) NOT NULL DEFAULT '',
        version INT UNSIGNED NOT NULL DEFAULT 1,
        snapshot_json LONGTEXT NULL,
        item_count INT UNSIGNED NOT NULL DEFAULT 0,
        total_gross DECIMAL(10,2) NOT NULL DEFAULT 0,
        valid_until DATE NULL,
        desk_artifact VARCHAR(190) NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_offer_version (offer_id, version),
        KEY idx_offer_no (offer_no)
    ) $charset_collate;";

    dbDelta( $sql_v );

    foreach ( array( 'offer_version', 'version_pending', 'version_pending_reason', 'version_pending_at' ) as $col ) {
        $have = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $col ) ); // phpcs:ignore WordPress.DB.PreparedSQL
        if ( $col !== $have ) {
            error_log( 'M24 Plattform Migration 032: Spalte ' . $col . ' fehlt nach dbDelta an ' . $table );
            return false;
        }
    }
    if ( $versions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $versions ) ) ) {
        error_log( 'M24 Plattform Migration 032: Tabelle fehlt: ' . $versions );
        return false;
    }

    // Bestand: alles Versendete ist Fassung 1. Entwürfe bleiben ebenfalls bei 1 — die Fassung zählt
    // erst mit dem Versand hoch, ein Entwurf ist noch keine Fassung.
    $wpdb->query( "UPDATE {$table} SET offer_version = 1 WHERE offer_version = 0" ); // phpcs:ignore WordPress.DB

    if ( class_exists( 'M24_Logger' ) ) {
        M24_Logger::info( 'migration', 'Migration 032: Fassungen angelegt', array() );
    }
    return true;
}
