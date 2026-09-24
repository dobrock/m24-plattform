<?php
/**
 * Migration 033 — Verlauf am Angebot (Sync-Entitaet `thread`, Desk → WP).
 *
 * Der Desk ist seit dem Rueckbau vom 24.09. kein Postfach mehr: Gmail `m24desk@` ist das Postfach,
 * Daniel liest und antwortet in Apple Mail, und der Desk fuehrt am Auftrag nur noch den VERLAUF.
 * Damit derselbe Verlauf auch an der WP-Angebotskarte steht, spiegelt der Desk ihn hierher.
 *
 * Eigene Tabelle, nicht JSON an m24_offers: der Verlauf waechst unabhaengig vom Angebot, wird nie
 * geaendert und soll sortierbar sein. Ein JSON-Feld muesste je Eintrag die ganze Angebotszeile neu
 * schreiben — und genau daran haengt die Sync-Buchhaltung (rev/updated_at), die dann bei jeder
 * eingehenden Mail hochzaehlen wuerde.
 *
 * SCHLUESSEL ist desk_id (comm_thread.id im Desk), UNIQUE. NICHT msg_id: die hat nur, was aus einem
 * Postfach GELESEN wurde — von 349 Zeilen im Desk tragen 12 eine. Ereignisse (payment, shipped,
 * followup, doc_sent, test) entstehen beim Versenden und waren nie eine Nachricht (Befund des
 * App-Fensters, 24.09. 16:20). Ein Unique-Index auf msg_id haette 337 Zeilen ausgesperrt.
 *
 * Append-only: kein updated_at, kein rev, kein deleted_at, keine Tombstones. Eine bereits
 * empfangene desk_id erneut zu schicken ist ein No-Op.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_033() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $thread          = $wpdb->prefix . 'm24_offer_thread';

    // mail_date UND created_at, beide nullable-tolerant gefuehrt: mail_date traegt nur, was aus einer
    // Mail gelesen wurde (Date-Header), created_at sagt, wann der Desk es eingelesen hat. Die Luecke
    // dazwischen ist drueben der Nachzuegler-Alarm — wer nur ein Datum fuehrt, sieht sie nie.
    // Sortiert wird an der Karte deshalb ueber COALESCE(mail_date, created_at), wie im Desk.
    $sql = "CREATE TABLE {$thread} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        desk_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        wp_offer_uid VARCHAR(64) NOT NULL DEFAULT '',
        offer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        dir VARCHAR(3) NOT NULL DEFAULT 'in',
        type VARCHAR(20) NOT NULL DEFAULT 'email',
        from_addr VARCHAR(190) NOT NULL DEFAULT '',
        body LONGTEXT NULL,
        msg_id VARCHAR(255) NULL,
        mail_date DATETIME NULL,
        created_at DATETIME NULL,
        synced_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_desk_id (desk_id),
        KEY idx_offer (offer_id),
        KEY idx_uid (wp_offer_uid),
        KEY idx_sort (offer_id, mail_date, created_at)
    ) $charset_collate;";

    dbDelta( $sql );

    if ( $thread !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $thread ) ) ) {
        error_log( 'M24 Plattform Migration 033: Tabelle fehlt: ' . $thread );
        return false;
    }
    // Der Unique-Index IST die Idempotenz dieser Entitaet — ohne ihn erzeugt jeder Reconcile-Lauf
    // den kompletten Verlauf ein weiteres Mal. Deshalb nicht nur die Tabelle pruefen, sondern ihn.
    $idx = $wpdb->get_results( "SHOW INDEX FROM {$thread} WHERE Key_name = 'uniq_desk_id'" ); // phpcs:ignore WordPress.DB
    if ( empty( $idx ) ) {
        error_log( 'M24 Plattform Migration 033: uniq_desk_id fehlt an ' . $thread );
        return false;
    }

    if ( class_exists( 'M24_Logger' ) ) {
        M24_Logger::info( 'migration', 'Migration 033: Verlaufs-Tabelle angelegt', array( 'tabelle' => $thread ) );
    }
    return true;
}
