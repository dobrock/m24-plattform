<?php
/**
 * Migration 003 — Mock-Log-Tabelle (Modul D.0)
 *
 * Legt {prefix}m24_mock_log an. Speichert jeden Aufruf an die Mock-REST-Routen
 * /wp-json/m24-plattform/v1/mock/* fuer Verifikation des Push-Pfads aus Modul D.
 *
 * Die Tabelle bleibt bei Plugin-Deaktivierung bestehen (Test-Daten ueberleben),
 * wird aber bei Plugin-Uninstall geloescht (in zukuenftiger uninstall.php).
 *
 * Spec-Referenz: M24-Master-Spec-v4 Kapitel 6, Uebergabe v10 Kapitel 4.11
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_003() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $table           = M24_Database::table( 'mock_log' );

    // Schema-Hinweise:
    //  - method VARCHAR(8): GET/POST/PUT/DELETE/PATCH/HEAD/OPTIONS reichen aus.
    //  - route VARCHAR(120): /wp-json/m24-plattform/v1/mock/orders-fail ist der Maximalfall.
    //  - headers/body/response_body LONGTEXT: REST-Bodies und Headers koennen viele KB werden.
    //  - response_code SMALLINT UNSIGNED: 0..65535 reicht (HTTP-Codes sind 100..599).
    //  - idempotency_key VARCHAR(120) als ausgewerteter Header — separat fuer schnelles
    //    "wurde dieser Key schon gepusht?"-Lookup ohne JSON-Parse.
    //  - source VARCHAR(40) als ausgewerteter inquiry_source-Body-Feldwert — gleicher Grund.
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

        method VARCHAR(8) NOT NULL,
        route VARCHAR(120) NOT NULL,
        headers LONGTEXT NULL,
        body LONGTEXT NULL,

        response_code SMALLINT UNSIGNED NOT NULL,
        response_body LONGTEXT NULL,

        idempotency_key VARCHAR(120) NULL,
        source VARCHAR(40) NULL,

        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        KEY idx_route (route, created_at),
        KEY idx_idempotency (idempotency_key),
        KEY idx_created (created_at)
    ) $charset_collate;";

    dbDelta( $sql );

    // Sanity-Check: kritische Spalten muessen vorhanden sein.
    $columns = $wpdb->get_col( "SHOW COLUMNS FROM $table" );
    $required = [ 'id', 'method', 'route', 'response_code', 'created_at' ];
    $missing  = array_diff( $required, $columns );

    if ( ! empty( $missing ) ) {
        error_log(
            'M24 Plattform Migration 003: fehlende Spalten in '
            . $table . ': ' . implode( ',', $missing )
        );
        return false;
    }

    return true;
}
