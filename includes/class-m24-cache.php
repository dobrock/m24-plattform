<?php
/**
 * M24 Plattform — Cache
 *
 * Duenner Wrapper um WordPress-Transients mit Plugin-Prefix.
 * Transients liegen je nach Object-Cache-Setup entweder in wp_options
 * oder im persistenten Cache (Redis/Memcached, falls aktiv).
 *
 * Verwendung:
 *   M24_Cache::set( 'health_check', $data, 60 );      // 60 Sek TTL
 *   $data = M24_Cache::get( 'health_check' );         // null wenn abgelaufen
 *   M24_Cache::delete( 'health_check' );
 *
 * Konvention fuer Keys: snake_case mit Modul-Praefix
 *   z.B. 'rest_health', 'vies_DE123456789', 'inquiries_rate_<ip>'
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Cache {

    const PREFIX = 'm24_';

    /**
     * Wert in den Cache schreiben.
     *
     * @param string $key
     * @param mixed  $value     beliebige serialisierbare Daten
     * @param int    $ttl       Lebenszeit in Sekunden (Default 5 Min)
     * @return bool
     */
    public static function set( $key, $value, $ttl = 300 ) {
        return set_transient( self::PREFIX . $key, $value, (int) $ttl );
    }

    /**
     * Wert aus dem Cache holen.
     *
     * @param string $key
     * @return mixed|null   null wenn nicht vorhanden oder abgelaufen
     */
    public static function get( $key ) {
        $val = get_transient( self::PREFIX . $key );
        return ( $val === false ) ? null : $val;
    }

    /**
     * Cache-Eintrag loeschen.
     *
     * @param string $key
     * @return bool
     */
    public static function delete( $key ) {
        return delete_transient( self::PREFIX . $key );
    }

    /**
     * "Remember"-Pattern: Wert holen oder via Callback erzeugen+cachen.
     *
     * Beispiel:
     *   $health = M24_Cache::remember( 'health', 60, function() {
     *       return M24_REST_Client::health();
     *   });
     *
     * @param string   $key
     * @param int      $ttl
     * @param callable $callback   wird nur aufgerufen, wenn Cache leer
     * @return mixed
     */
    public static function remember( $key, $ttl, $callback ) {
        $cached = self::get( $key );
        if ( $cached !== null ) {
            return $cached;
        }

        $value = call_user_func( $callback );
        if ( $value !== null && $value !== false ) {
            self::set( $key, $value, $ttl );
        }
        return $value;
    }
}
