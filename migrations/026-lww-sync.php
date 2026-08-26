<?php
/**
 * Migration 026 — Bidirektionale LWW-Sync, Fundament (Spec v1.2 §0/§1).
 *
 * Neue Spalten auf m24_offers:
 *   wp_offer_uid   VARCHAR(64) — immutabler Angebots-Key ('wpoffer_<id>'), bisher nur im W1-Payload
 *                                gebildet (M24_Desk_Push::build_payload). Jetzt persistiert, damit Sync-
 *                                Records darüber keyen können. Nur KEY, nicht UNIQUE — s. Kommentar
 *                                am Index (Backfill läuft nach dbDelta).
 *   customer_uid   VARCHAR(64) — stabile Kunden-ID (UUID, von WP vergeben). NICHT die E-Mail: die ist
 *                                genau der Wert, der sich beim Korrigieren ändert. Auch Gäste ohne Konto
 *                                bekommen eine.
 *   updated_at     DATETIME    — UTC, bei JEDER lokalen Änderung neu.
 *   origin         VARCHAR(8)  — wp | desk (wer die letzte Änderung gemacht hat).
 *   rev            INT         — monoton +1 je lokaler Änderung; Tiebreak bei gleichem updated_at.
 *   last_synced_rev / last_synced_at — Sync-Buchhaltung, Basis des Echo-Schutzes (§4).
 *   supersedes / superseded_by — Supersede-Kette (Verweise auf wp_offer_uid), §3.
 *   needs_resend   TINYINT(1)  — nach Supersede gesetzt; Versand bleibt manuell („Erneut senden").
 *
 * Positionen (items_json) bekommen je Zeile ein line_uid — das erledigt der Backfill unten, weil die
 * Positionen als JSON im LONGTEXT liegen und keine eigene Tabelle haben.
 *
 * Backfill für Bestandszeilen: rev=1, updated_at = bester bekannter Stand, origin nach Herkunft
 * (desk_order_id gesetzt und desk-originär → 'desk', sonst 'wp'), wp_offer_uid aus der PK.
 *
 * Rein additiv, idempotent via dbDelta; voller Schema-Restate ist dbDelta-Pflicht (diff-t auf die Zielform).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_026() {
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

    // Pflichtspalten verifizieren — ohne sie darf kein Sync-Apply laufen (Spec §0).
    foreach ( array( 'wp_offer_uid', 'customer_uid', 'updated_at', 'origin', 'rev', 'supersedes', 'needs_resend' ) as $col ) {
        $have = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $col ) ); // phpcs:ignore WordPress.DB.PreparedSQL
        if ( $col !== $have ) {
            error_log( 'M24 Plattform Migration 026: Spalte ' . $col . ' fehlt nach dbDelta an ' . $table );
            return false;
        }
    }

    // ── Backfill 1: wp_offer_uid aus der PK (immutabel, deckt sich mit dem bisherigen W1-Payload).
    $wpdb->query( "UPDATE {$table} SET wp_offer_uid = CONCAT('wpoffer_', id) WHERE wp_offer_uid = ''" ); // phpcs:ignore WordPress.DB

    // ── Backfill 2: updated_at/origin/rev. Bester bekannter Zeitpunkt = jüngster vorhandener Stempel.
    // origin: von Desk gespiegelte Zeilen (desk_sync_status='synced' + desk_order_id) gelten als 'desk',
    // alles andere als 'wp'. rev startet bei 1 — der erste lokale Edit macht daraus 2.
    $wpdb->query( // phpcs:ignore WordPress.DB
        "UPDATE {$table}
            SET updated_at = COALESCE(desk_synced_at, sent_at, created_at, UTC_TIMESTAMP()),
                origin     = CASE WHEN desk_order_id <> '' AND desk_sync_status = 'synced' THEN 'desk' ELSE 'wp' END,
                rev        = 1
          WHERE updated_at IS NULL"
    );

    // ── Backfill 3: customer_uid je Angebot. Kunden mit Konto teilen sich die UID des Kontos
    // (User-Meta _m24_customer_uid), Gäste bekommen eine eigene. Beides UUIDv4, von WP vergeben.
    $rows = $wpdb->get_results( "SELECT id, account_id FROM {$table} WHERE customer_uid = ''" ); // phpcs:ignore WordPress.DB
    foreach ( (array) $rows as $r ) {
        $uid = '';
        $acc = (int) $r->account_id;
        if ( $acc > 0 ) {
            $uid = (string) get_user_meta( $acc, '_m24_customer_uid', true );
            if ( '' === $uid ) {
                $uid = wp_generate_uuid4();
                update_user_meta( $acc, '_m24_customer_uid', $uid );
            }
        } else {
            $uid = wp_generate_uuid4(); // Gast ohne Konto — UID hängt am Angebot
        }
        $wpdb->update( $table, array( 'customer_uid' => $uid ), array( 'id' => (int) $r->id ) );
    }

    // ── Backfill 4: line_uid je Position in items_json. Ohne stabile Zeilen-ID kann Line-Item-LWW
    // (§3) zwei gleichzeitige Edits an verschiedenen Zeilen nicht auseinanderhalten.
    $offers = $wpdb->get_results( "SELECT id, items_json FROM {$table} WHERE items_json IS NOT NULL AND items_json <> ''" ); // phpcs:ignore WordPress.DB
    $touched = 0;
    foreach ( (array) $offers as $o ) {
        $items = json_decode( (string) $o->items_json, true );
        if ( ! is_array( $items ) || empty( $items ) ) { continue; }
        $dirty = false;
        foreach ( $items as $i => $it ) {
            if ( ! is_array( $it ) ) { continue; }
            if ( empty( $it['line_uid'] ) ) {
                $items[ $i ]['line_uid'] = wp_generate_uuid4();
                $dirty = true;
            }
        }
        if ( $dirty ) {
            $wpdb->update( $table, array( 'items_json' => wp_json_encode( $items ) ), array( 'id' => (int) $o->id ) );
            $touched++;
        }
    }
    if ( class_exists( 'M24_Logger' ) ) {
        M24_Logger::info( 'migration', 'Migration 026: LWW-Fundament gesetzt', array( 'offers_lines_backfilled' => $touched ) );
    }

    return true;
}
