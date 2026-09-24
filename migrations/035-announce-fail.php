<?php
/**
 * Migration 035 — Zaehler fuer fehlgeschlagene uid-Meldungen: Spalte announce_fail in m24_offers.
 *
 * Der uid-Bootstrap (M24_Sync_Push::announce_uid) meldet dem Desk, welche wp_offer_uid zu seinem
 * Auftrag gehoert. Antwortet der Desk 'unbekannter_auftrag', gibt es den Auftrag drueben nicht —
 * und bei einem HART geloeschten Auftrag wird es ihn nie wieder geben. Ohne Zaehler laeuft die
 * Meldung dann bis in alle Ewigkeit, zehn Minuten fuer zehn Minuten (so geschehen bei den
 * Testangeboten aus dem Juli, s. Migration 034).
 *
 * Drei Fehlversuche, dann Ruhe. Der Zaehler steht an der Angebotszeile und nicht in einer Option:
 * es ist ein Zustand DIESES Vorgangs, und das Plugin fuehrt solche Zustaende als Spalte
 * (offer_drift, version_pending, needs_resend — alle nach demselben Muster).
 *
 * Idempotent: ADD COLUMN nur, wenn sie fehlt.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_035() {
    global $wpdb;
    $table = $wpdb->prefix . 'm24_offers';

    if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        error_log( 'M24 Plattform Migration 035: Tabelle fehlt: ' . $table );
        return false;
    }

    $has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'announce_fail' ) );
    if ( 'announce_fail' !== $has ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — DDL, feste Bezeichner.
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN announce_fail TINYINT UNSIGNED NOT NULL DEFAULT 0" );
    }

    $has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'announce_fail' ) );
    if ( 'announce_fail' !== $has ) {
        error_log( 'M24 Plattform Migration 035: Spalte announce_fail fehlt nach ALTER an ' . $table );
        return false;
    }

    if ( class_exists( 'M24_Logger' ) ) {
        M24_Logger::info( 'migration', 'Migration 035: announce_fail angelegt', array() );
    }
    return true;
}
