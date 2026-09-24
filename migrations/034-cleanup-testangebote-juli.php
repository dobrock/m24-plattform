<?php
/**
 * Migration 034 — Cleanup vom 27.07. nachholen: 2026-1031 bis 2026-1035 in den Papierkorb.
 *
 * Am 27.07. wurde die Aufteilung vereinbart (BRIDGE_Plattform-an-Desk.md): die Desk-Auftraege
 * 1028–1035 loescht Daniel endgueltig, die WP-Testangebote 2026-1031…1035 wandern in den
 * WP-Papierkorb. Die Desk-Haelfte ist erledigt — die Auftraege 360–363 sind HART geloescht, nicht
 * getombstonet. Die WP-Haelfte blieb liegen; STATUS.md fuehrt „Cleanup offen" bis heute.
 *
 * Folge: WP haelt fuenf Angebote, deren Gegenstueck es drueben nicht mehr gibt, und pusht sie seit
 * zwei Monaten alle zehn Minuten. Der Desk antwortet 'unbekannter_auftrag', M24_Sync_Push::push_offer
 * verbucht daraufhin bewusst KEIN last_synced_* (damit ein echter Ausfall nachgeholt wird) — und der
 * Nachzuegler-Cron nimmt sie beim naechsten Lauf wieder auf. Eine Schleife, die nie endet, weil die
 * Bedingung fuer ihr Ende (der Auftrag taucht auf) nie eintreten kann.
 *
 * Deshalb hier ZWEI Schritte, nicht einer:
 *   1. deleted_at setzen — Papierkorb, reversibel, wie vereinbart.
 *   2. last_synced_rev = rev setzen — die Zeile aus der Push-Warteschlange nehmen.
 *
 * Schritt 2 ist der eigentliche Punkt. Ein blosser Papierkorb-Eintrag beendet die Schleife NICHT:
 * M24_Sync_Push::run_pending() filtert `deleted_at` nicht (und darf es nicht — ein Tombstone MUSS
 * hinueber). Hier gibt es aber niemanden mehr, dem etwas zu melden waere: der Desk-Auftrag ist weg.
 * Ein Tombstone an einen geloeschten Auftrag ist keine Nachricht, sondern nur der naechste Versuch.
 *
 * Aus demselben Grund KEIN M24_Sync_LWW::touch(): das wuerde rev hochzaehlen und einen Push planen.
 *
 * Streng idempotent: greift nur auf Zeilen, die noch aktiv sind. Fehlt eine Nummer, ist das kein
 * Fehler — dann wurde sie zwischenzeitlich von Hand erledigt.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_034() {
    global $wpdb;
    $t = $wpdb->prefix . 'm24_offers';

    if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
        return true; // Angebots-Tabelle gibt es (noch) nicht — nichts aufzuraeumen.
    }

    $nummern = array( '2026-1031', '2026-1032', '2026-1033', '2026-1034', '2026-1035' );
    $now     = gmdate( 'Y-m-d H:i:s' );
    $getan   = array();
    $offen   = array();

    foreach ( $nummern as $no ) {
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, deleted_at, rev FROM {$t} WHERE offer_no = %s LIMIT 1", $no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $row ) { $offen[] = $no . ' (nicht vorhanden)'; continue; }
        if ( ! empty( $row->deleted_at ) ) { $offen[] = $no . ' (lag schon im Papierkorb)'; continue; }

        $wpdb->update(
            $t,
            array(
                'deleted_at'      => $now,
                'last_synced_rev' => max( 0, (int) $row->rev ), // aus der Push-Warteschlange nehmen
                'last_synced_at'  => $now,
            ),
            array( 'id' => (int) $row->id )
        );
        $getan[] = $no;
    }

    $msg = 'Migration 034: ' . count( $getan ) . ' Testangebot(e) in den Papierkorb'
        . ( ! empty( $getan ) ? ' (' . implode( ', ', $getan ) . ')' : '' )
        . ( ! empty( $offen ) ? ' · uebersprungen: ' . implode( ', ', $offen ) : '' ) . '.';

    if ( class_exists( 'M24_Logger' ) ) {
        M24_Logger::info( 'migration', $msg, array( 'erledigt' => $getan, 'uebersprungen' => $offen ) );
    }
    if ( ! empty( $getan ) && class_exists( 'M24_Error_Log' ) ) {
        M24_Error_Log::capture( 'migration', 'info', 'Cleanup vom 27.07. nachgeholt — Testangebote im Papierkorb, Push eingestellt', array(
            'angebote' => implode( ', ', $getan ),
        ) );
    }
    return true;
}
