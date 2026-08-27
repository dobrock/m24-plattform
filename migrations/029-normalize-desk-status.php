<?php
/**
 * Migration 029 — vom Desk durchgereichte Fremd-Stati normalisieren.
 *
 * M24_Sync_Apply schrieb den Desk-Status bis 0.11.463 roh in die Spalte. Werte wie "Offered" fielen
 * damit aus der WP-Statusdomäne heraus: keine Badge-Farbe, kein Treffer in den Filter-Chips — und vor
 * allem blendete die Angebote-Liste Aktionen aus, die an den Status gebunden sind. Konkret fehlte
 * „Erneut senden" ausgerechnet bei einem Angebot, das wegen einer falschen Adresse neu raus musste.
 *
 * Diese Migration zieht bestehende Zeilen auf die WP-Domäne. Unbekannte Werte bleiben unangetastet und
 * werden geloggt — lieber ein sichtbar seltsamer Status als ein stillschweigend falscher.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_029() {
    global $wpdb;
    $table = $wpdb->prefix . 'm24_offers';
    $valid = array( 'entwurf', 'offen', 'angenommen', 'bezahlt', 'versandt', 'erledigt', 'abgelehnt', 'storniert', 'abgelaufen' );

    $rows = $wpdb->get_results( "SELECT id, offer_no, status FROM {$table}" ); // phpcs:ignore WordPress.DB
    $fixed = 0; $unknown = array();
    foreach ( (array) $rows as $r ) {
        $cur = (string) $r->status;
        if ( in_array( $cur, $valid, true ) ) { continue; }
        $low = strtolower( trim( $cur ) );
        $new = '';
        if ( class_exists( 'M24_Sync_Apply' ) && isset( M24_Sync_Apply::STATUS_MAP[ $low ] ) ) {
            $new = M24_Sync_Apply::STATUS_MAP[ $low ];
        } elseif ( in_array( $low, $valid, true ) ) {
            $new = $low; // nur Groß-/Kleinschreibung
        }
        if ( '' === $new ) { $unknown[] = $cur . ' (' . (string) $r->offer_no . ')'; continue; }
        $wpdb->update( $table, array( 'status' => $new ), array( 'id' => (int) $r->id ) );
        $fixed++;
    }

    if ( class_exists( 'M24_Logger' ) ) {
        M24_Logger::info( 'migration', 'Migration 029: Desk-Stati normalisiert', array(
            'korrigiert'  => $fixed,
            'unbekannt'   => array_slice( array_unique( $unknown ), 0, 20 ),
        ) );
    }
    return true;
}
