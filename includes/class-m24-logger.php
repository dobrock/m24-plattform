<?php
/**
 * M24 Plattform — Logger
 *
 * Schreibt strukturierte Log-Eintraege nach {prefix}m24_sync_log.
 *
 * Verwendung:
 *   M24_Logger::info( 'rest_client', 'Health-Check OK', [ 'status' => 200 ] );
 *   M24_Logger::error( 'inquiries', 'Push fehlgeschlagen', [ 'http' => 500, 'body' => $body ] );
 *
 * Levels: debug, info, warning, error
 * Context: kurzer String (max 50), benennt das Modul/den Bereich
 *          z.B. 'rest_client', 'inquiries_push', 'auth_register', 'migration'
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Logger {

    const LEVEL_DEBUG   = 'debug';
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    public static function debug( $context, $message, $payload = null ) {
        self::write( self::LEVEL_DEBUG, $context, $message, $payload );
    }

    public static function info( $context, $message, $payload = null ) {
        self::write( self::LEVEL_INFO, $context, $message, $payload );
    }

    public static function warning( $context, $message, $payload = null ) {
        self::write( self::LEVEL_WARNING, $context, $message, $payload );
    }

    public static function error( $context, $message, $payload = null ) {
        self::write( self::LEVEL_ERROR, $context, $message, $payload );
        // Ins zentrale Fehlerprotokoll spiegeln (deckt Brevo/Desk/Magic-Link/Updater-::error automatisch ab).
        if ( class_exists( 'M24_Error_Log' ) ) {
            M24_Error_Log::capture( (string) $context, 'error', (string) $message, is_array( $payload ) ? $payload : ( null !== $payload ? array( 'payload' => $payload ) : array() ) );
        }
    }

    /**
     * Schreibt einen Log-Eintrag.
     * Faellt still aus, falls die Tabelle (noch) nicht existiert —
     * z.B. waehrend der allerersten Migration. Kein DB-Error in dem Fall.
     */
    private static function write( $level, $context, $message, $payload ) {
        global $wpdb;

        $table = M24_Database::table( 'sync_log' );

        // Tabelle vorhanden? — falls nicht, in error_log fallback und raus
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SHOW TABLES LIKE %s", $table )
        );
        if ( $exists !== $table ) {
            error_log( "M24 [$level] $context: $message (sync_log nicht vorhanden)" );
            return;
        }

        $payload_json = null;
        if ( $payload !== null ) {
            $payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            // Sicherheit gegen oversized payloads — DB-Spalte ist LONGTEXT, hart cappen wir bei 1 MB
            if ( strlen( $payload_json ) > 1048576 ) {
                $payload_json = substr( $payload_json, 0, 1048576 ) . '...[truncated]';
            }
        }

        $wpdb->insert(
            $table,
            [
                'level'        => substr( $level, 0, 20 ),
                'context'      => substr( $context, 0, 50 ),
                'message'      => $message,
                'payload_json' => $payload_json,
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        // Falls $wpdb->insert fehlschlaegt: in error_log spiegeln
        if ( $wpdb->last_error ) {
            error_log( "M24 Logger DB-Error: " . $wpdb->last_error . " — Original: [$level] $context: $message" );
        }
    }

    /**
     * Liefert die letzten N Log-Eintraege fuer das Admin-Monitor.
     * Filter optional nach Level oder Context.
     */
    public static function recent( $limit = 100, $level = null, $context = null ) {
        global $wpdb;

        $table = M24_Database::table( 'sync_log' );
        $limit = max( 1, min( 1000, (int) $limit ) );

        $where  = [];
        $params = [];

        if ( $level ) {
            $where[]  = 'level = %s';
            $params[] = $level;
        }
        if ( $context ) {
            $where[]  = 'context = %s';
            $params[] = $context;
        }

        $where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
        $sql       = "SELECT * FROM $table $where_sql ORDER BY id DESC LIMIT $limit";

        if ( $params ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }
}
