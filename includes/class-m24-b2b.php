<?php
/**
 * M24 Plattform — B2B/Händler-Auth Daten-Spine (Garage Phase A, Chunk 1).
 *
 * Reiner Backend-Unterbau: Händler-Rolle, Preis-Gate, Magic-Link-Token-Lebenszyklus.
 * KEIN sichtbares Frontend. Tabellen: {prefix}m24_haendler, {prefix}m24_magic_tokens.
 *
 * Sicherheit/DSGVO:
 *   - Magic-Token: nur der SHA-256-Hash landet in der DB; der rohe Token existiert
 *     ausschließlich im Login-Link (Einmal-Nutzung, 15 Min Gültigkeit).
 *   - IP wird nur gehasht (mit auth-Salt) gespeichert, nie roh.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_B2B {

    const ROLE      = 'm24_haendler';
    const TOKEN_TTL = 15 * MINUTE_IN_SECONDS; // 900 s

    /** Request-Cache für den Händler-Datensatz des aktuellen Users. */
    private static ?object $haendler_cache = null;
    private static bool $loaded = false;

    public static function init() {
        // Fallback, falls die Migration (noch) nicht lief.
        if ( ! get_role( self::ROLE ) ) {
            add_role( self::ROLE, 'M24 Händler', [ 'read' => true ] );
        }

        // Token-Cleanup täglich.
        if ( ! wp_next_scheduled( 'm24_b2b_token_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'm24_b2b_token_cleanup' );
        }
        add_action( 'm24_b2b_token_cleanup', [ __CLASS__, 'cleanup_tokens' ] );

        // Händler aus dem wp-admin aussperren (außer Admins, außer AJAX).
        add_action( 'admin_init', [ __CLASS__, 'block_admin' ] );

        // Admin-Bar für Händler ausblenden.
        add_filter( 'show_admin_bar', [ __CLASS__, 'maybe_hide_admin_bar' ] );
    }

    /* ── Zugriffsschutz ──────────────────────────────────────────────────── */

    public static function block_admin() {
        if ( ! is_user_logged_in() || wp_doing_ajax() ) {
            return;
        }
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        $user = wp_get_current_user();
        if ( $user && in_array( self::ROLE, (array) $user->roles, true ) ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }
    }

    public static function maybe_hide_admin_bar( $show ) {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( $user && in_array( self::ROLE, (array) $user->roles, true ) ) {
                return false;
            }
        }
        return $show;
    }

    /* ── Daten-API ───────────────────────────────────────────────────────── */

    /** Händler-Datensatz des eingeloggten Users (request-cached) oder null. */
    public static function current_haendler(): ?object {
        if ( self::$loaded ) {
            return self::$haendler_cache;
        }
        self::$loaded = true;
        if ( ! is_user_logged_in() ) {
            self::$haendler_cache = null;
            return null;
        }
        self::$haendler_cache = self::get_haendler_by_user( get_current_user_id() );
        return self::$haendler_cache;
    }

    public static function get_haendler_by_user( int $uid ): ?object {
        global $wpdb;
        $table = M24_Database::table( 'haendler' );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE wp_user_id = %d LIMIT 1", $uid ) );
        return $row ? $row : null;
    }

    public static function is_logged_in_haendler(): bool {
        return null !== self::current_haendler();
    }

    /** DAS Preis-Gate: nur freigegebene Händler dürfen Preise sehen. */
    public static function can_see_prices(): bool {
        $h = self::current_haendler();
        return $h && 'approved' === $h->status;
    }

    /* ── Magic-Link-Token ────────────────────────────────────────────────── */

    private static function hash_token( string $raw ): string {
        return hash( 'sha256', $raw );
    }

    /** IP nie roh speichern — nur gesalzener Hash (DSGVO). */
    private static function ip_hash(): string {
        return hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt( 'auth' ) );
    }

    /**
     * Token ausstellen. Gibt den ROHEN Token zurück (nur für den Link, nie in der DB).
     * Ältere offene Tokens gleicher email+purpose werden entwertet.
     */
    public static function issue_token( string $email, string $purpose, ?int $user_id = null, ?int $ttl = null ): string {
        global $wpdb;
        $table = M24_Database::table( 'magic_tokens' );
        $email = strtolower( sanitize_email( $email ) );
        $ttl   = ( null !== $ttl && $ttl > 0 ) ? $ttl : self::TOKEN_TTL; // purpose-spezifische TTL (z. B. Garage 30 Min)

        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET used_at = UTC_TIMESTAMP() WHERE email = %s AND purpose = %s AND used_at IS NULL",
            $email,
            $purpose
        ) );

        $raw = bin2hex( random_bytes( 32 ) ); // 64 hex
        $wpdb->insert(
            $table,
            [
                'email'      => $email,
                'wp_user_id' => $user_id,
                'token_hash' => self::hash_token( $raw ),
                'purpose'    => $purpose,
                'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
                'ip_hash'    => self::ip_hash(),
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        return $raw;
    }

    /** Token einlösen (Einmal-Nutzung). Gibt den Datensatz zurück oder null. */
    public static function consume_token( string $raw, string $purpose ): ?object {
        global $wpdb;
        $table = M24_Database::table( 'magic_tokens' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE token_hash = %s AND purpose = %s AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1",
            self::hash_token( $raw ),
            $purpose
        ) );
        if ( ! $row ) {
            return null;
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET used_at = %s WHERE id = %d",
            gmdate( 'Y-m-d H:i:s' ),
            (int) $row->id
        ) );

        return $row;
    }

    /**
     * Token einlösen OHNE purpose-Filter (Einmal-Nutzung). Liefert die Zeile inkl. purpose/
     * wp_user_id/email — der Confirm-Handler entscheidet anhand von purpose, was zu tun ist.
     */
    public static function consume_token_any( string $raw ): ?object {
        global $wpdb;
        $table = M24_Database::table( 'magic_tokens' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE token_hash = %s AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() LIMIT 1",
            self::hash_token( $raw )
        ) );
        if ( ! $row ) {
            return null;
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET used_at = %s WHERE id = %d",
            gmdate( 'Y-m-d H:i:s' ),
            (int) $row->id
        ) );

        return $row;
    }

    /** Abgelaufene/verbrauchte Tokens entfernen (täglicher Cron). */
    public static function cleanup_tokens(): void {
        global $wpdb;
        $table = M24_Database::table( 'magic_tokens' );
        $wpdb->query( "DELETE FROM $table WHERE used_at IS NOT NULL OR expires_at < UTC_TIMESTAMP()" );
    }
}
