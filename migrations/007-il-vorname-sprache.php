<?php
/**
 * Migration 007 — Interessenten-Spiegel: Vorname/Nachname/Sprache
 *
 * Erweitert {prefix}m24_il_interessenten um persönliche Felder für die personalisierte
 * Anrede + Mailsprache (Off-Market/Parken erheben jetzt Vorname/Nachname + DE/EN).
 * Bestehende Zeilen bleiben gültig (NULL). Idempotent: ADD COLUMN nur, wenn fehlend.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
function m24_migration_007() {
    global $wpdb;

    $table = $wpdb->prefix . 'm24_il_interessenten';

    // Tabelle muss existieren (Migration 005); sonst hier nichts zu tun.
    if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        error_log( 'M24 Plattform Migration 007: Tabelle fehlt: ' . $table );
        return false;
    }

    $cols = array(
        'vorname'  => "ADD COLUMN vorname VARCHAR(120) NULL AFTER name",
        'nachname' => "ADD COLUMN nachname VARCHAR(120) NULL AFTER vorname",
        'sprache'  => "ADD COLUMN sprache VARCHAR(5) NULL AFTER nachname",
    );
    foreach ( $cols as $col => $ddl ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            $col
        ) );
        if ( $exists !== $col ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared — DDL aus fester Whitelist oben.
            $wpdb->query( "ALTER TABLE `{$table}` {$ddl}" );
        }
    }
    return true;
}
