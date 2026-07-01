<?php
/**
 * Migration 009 — Teile-Merkzettel sortierbar: Spalte sort_order in m24_garage_cart.
 * Bestehende Zeilen je Account nach id initialisieren (stabile Startreihenfolge).
 * Idempotent: ADD COLUMN nur wenn fehlend.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_009() {
    global $wpdb;
    $table = $wpdb->prefix . 'm24_garage_cart';

    if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        error_log( 'M24 Plattform Migration 009: Tabelle fehlt: ' . $table );
        return false;
    }

    $has = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'sort_order' ) );
    if ( 'sort_order' !== $has ) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — DDL, feste Bezeichner.
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN sort_order INT NOT NULL DEFAULT 0" );
        // Bestehende Zeilen je Account nach id durchnummerieren (0-basiert).
        $accs = $wpdb->get_col( "SELECT DISTINCT account_id FROM `{$table}`" );
        foreach ( (array) $accs as $acc ) {
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE account_id = %d ORDER BY id ASC", (int) $acc ) );
            $i = 0;
            foreach ( (array) $ids as $rid ) {
                $wpdb->update( $table, array( 'sort_order' => $i++ ), array( 'id' => (int) $rid ) );
            }
        }
    }
    return true;
}
