<?php
/**
 * M24 Plattform — Database & Migration Runner
 *
 * Migrations liegen unter migrations/NNN-name.php und definieren eine Funktion
 * m24_migration_NNN() (3-stellig, z.B. 001), die das Upgrade ausfuehrt.
 *
 * Version wird in wp_options unter 'm24_plattform_db_version' gespeichert.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Database {

    const OPTION_KEY = 'm24_plattform_db_version';

    /**
     * Wird beim Plugin-Activate aufgerufen.
     * Idempotent — kann beliebig oft laufen.
     */
    public static function activate() {
        self::maybe_upgrade();
    }

    /**
     * Prueft, ob neue Migrationen ausstehen, und fuehrt sie aus.
     * Wird beim Plugin-Boot via 'plugins_loaded' aufgerufen.
     */
    public static function maybe_upgrade() {
        $current  = get_option( self::OPTION_KEY, '000' );
        $target   = M24_PLATTFORM_DB_VERSION;

        if ( version_compare( $current, $target, '>=' ) ) {
            return; // schon aktuell
        }

        $available = self::list_migrations();

        foreach ( $available as $version => $file ) {
            if ( version_compare( $version, $current, '<=' ) ) {
                continue; // schon eingespielt
            }

            require_once $file;

            $func = 'm24_migration_' . $version;
            if ( ! function_exists( $func ) ) {
                error_log( "M24 Plattform: Migration $version hat keine Funktion $func" );
                continue;
            }

            $result = call_user_func( $func );
            if ( $result === false ) {
                error_log( "M24 Plattform: Migration $version fehlgeschlagen, Abbruch" );
                if ( class_exists( "M24_Logger" ) ) {
                    M24_Logger::error( "migration", "Migration $version fehlgeschlagen", [ "version" => $version ] );
                }
                return;
            }

            update_option( self::OPTION_KEY, $version );
            if ( class_exists( "M24_Logger" ) ) {
                M24_Logger::info( "migration", "Migration $version ok", [ "version" => $version ] );
            }
            $current = $version;
        }
    }

    /**
     * Listet alle Migration-Dateien unter migrations/ auf.
     * Dateinamen-Schema: NNN-name.php (NNN = 3-stellige Versionsnummer)
     *
     * @return array  [ '001' => '/.../migrations/001-skeleton.php', ... ] sortiert
     */
    private static function list_migrations() {
        $dir   = M24_PLATTFORM_DIR . 'migrations/';
        $files = glob( $dir . '*.php' );

        if ( ! $files ) {
            return [];
        }

        $out = [];
        foreach ( $files as $f ) {
            $base = basename( $f );
            if ( preg_match( '/^(\d{3})-/', $base, $m ) ) {
                $out[ $m[1] ] = $f;
            }
        }

        ksort( $out );
        return $out;
    }

    /**
     * Tabellenname mit Plugin-Prefix (Site-Prefix beruecksichtigt).
     *
     * @param string $name  z.B. 'anfragen' -> 'wp_m24_anfragen'
     * @return string
     */
    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'm24_' . $name;
    }
}
