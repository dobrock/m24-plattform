<?php
/**
 * Migration 028 — customer_uid für Kontokunden auf die WP-Account-ID umstellen.
 *
 * Migration 026 hatte je Kunde eine UUIDv4 vergeben. Das ist korrekt, aber nicht ableitbar: der Desk
 * kennt eine UUID nur, wenn WP sie ihm einmal geschickt hat — und für Altbestände passierte das nie,
 * weil der Kunden-Push an geänderten Angeboten hängt. Ergebnis: Adressänderungen an Bestandskunden
 * fanden in WP nie ihren Empfänger.
 *
 * Deshalb jetzt: customer_uid = WP-Account-ID (als String). Der Desk kann einen unbekannten Kunden
 * einmalig über die E-Mail matchen und unsere uid übernehmen; danach läuft alles über die uid. Eine
 * ableitbare ID ist außerdem im Log lesbar.
 *
 * Gäste ohne Konto (account_id = 0) behalten ihre UUID — dort gibt es keine stabile Größe abzuleiten.
 *
 * Unschädlich für bereits Gesyncstes: die alten UUIDs hat der Desk nie gesehen (genau das war der
 * Fehler). Wer sie doch schon kennt, hängt eine bestehende Zuordnung laut Vertrag nicht um, sondern
 * bekommt die neue uid beim nächsten Push angeboten.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_028() {
    global $wpdb;
    $table = $wpdb->prefix . 'm24_offers';

    // Angebotszeilen mit Konto: uid = account_id.
    $wpdb->query( "UPDATE {$table} SET customer_uid = CAST(account_id AS CHAR) WHERE account_id > 0" ); // phpcs:ignore WordPress.DB

    // Gäste ohne uid nachziehen (sollte 026 erledigt haben; defensiv, damit keine Zeile leer bleibt).
    $guests = $wpdb->get_col( "SELECT id FROM {$table} WHERE account_id = 0 AND ( customer_uid IS NULL OR customer_uid = '' )" ); // phpcs:ignore WordPress.DB
    foreach ( (array) $guests as $gid ) {
        $wpdb->update( $table, array( 'customer_uid' => wp_generate_uuid4() ), array( 'id' => (int) $gid ) );
    }

    // User-Meta angleichen, damit M24_Sync_LWW::customer_uid() und die DB dasselbe sagen.
    $accounts = $wpdb->get_col( "SELECT DISTINCT account_id FROM {$table} WHERE account_id > 0" ); // phpcs:ignore WordPress.DB
    foreach ( (array) $accounts as $acc ) {
        update_user_meta( (int) $acc, '_m24_customer_uid', (string) (int) $acc );
    }

    if ( class_exists( 'M24_Logger' ) ) {
        M24_Logger::info( 'migration', 'Migration 028: customer_uid = account_id', array(
            'konten' => count( (array) $accounts ),
            'gaeste' => count( (array) $guests ),
        ) );
    }
    return true;
}
