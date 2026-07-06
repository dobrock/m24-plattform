<?php
/**
 * M24 Plattform — REST Client
 *
 * Wrapper um WordPress' wp_remote_* mit:
 *   - X-API-Key Auth-Header (aus Settings oder wp-config.php-Konstante)
 *   - JSON-Body Handling
 *   - Timeout-Defaults (20 Sek)
 *   - Strukturiertes Logging via M24_Logger
 *   - Einheitliches Result-Format
 *   - Test-Mode-Umlenkung auf den Plugin-eigenen Mock-Endpoint
 *
 * Konfigurations-Vorrang (Spec v4 §4.10):
 *   1. Test-Mode aktiv (M24_Settings::is_test_mode_active() === true):
 *        - Base-URL = M24_Settings::effective_mock_url()
 *        - API-Key  = "mock-no-auth" (Mock akzeptiert alles, Pre-Flight-Check stolpert nicht)
 *   2. wp-config.php-Konstanten (M24_DESK_API_URL / M24_DESK_API_TOKEN):
 *        - haben Vorrang vor DB-Settings
 *   3. DB-Settings aus wp_options['m24_plattform_settings']
 *
 * Result-Format:
 *   [
 *     'ok'      => bool,            // true bei HTTP 2xx, false sonst
 *     'status'  => int,             // HTTP-Status (0 bei Netzwerk-Fehler)
 *     'data'    => array|null,      // dekodierter Response-Body (JSON), null bei Fehler/leer
 *     'error'   => string|null,     // Fehlermeldung, null bei Erfolg
 *     'raw'     => string|null,     // raw Body (fuer Debug, falls JSON-Decode fehlschlaegt)
 *   ]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_REST_Client {

    const DEFAULT_TIMEOUT = 20;
    const SETTINGS_OPTION = 'm24_plattform_settings';

    /**
     * Liefert die effektive API-Base-URL.
     *
     * Vorrang-Reihenfolge:
     *   1. Test-Mode → Mock-URL aus M24_Settings::effective_mock_url()
     *   2. Konstante M24_DESK_API_URL aus wp-config.php
     *   3. DB-Setting api_url
     */
    public static function get_base_url() {
        // 1. Test-Mode hat hoechsten Vorrang.
        if ( class_exists( 'M24_Settings' ) && M24_Settings::is_test_mode_active() ) {
            return M24_Settings::effective_mock_url();
        }

        // 2. wp-config.php-Konstante.
        if ( defined( 'M24_DESK_API_URL' ) && ! empty( M24_DESK_API_URL ) ) {
            return rtrim( (string) M24_DESK_API_URL, '/' );
        }

        // 3. DB-Setting.
        $settings = get_option( self::SETTINGS_OPTION, [] );
        $url      = isset( $settings['api_url'] ) ? trim( (string) $settings['api_url'] ) : '';
        return rtrim( $url, '/' );
    }

    /**
     * Liefert den effektiven API-Key.
     *
     * Vorrang-Reihenfolge:
     *   1. Test-Mode → Dummy-String "mock-no-auth", damit Pre-Flight-Check
     *      in request() nicht stolpert. Mock-Endpoint ignoriert den Header.
     *   2. Konstante M24_DESK_API_TOKEN aus wp-config.php
     *   3. DB-Setting api_key
     */
    /** Ist der Desk vollständig konfiguriert (URL + Token/Key)? Zentraler Gate für Push/Sync + Cron-Scheduling. */
    public static function is_configured(): bool {
        return '' !== (string) self::get_base_url() && '' !== (string) self::get_api_key();
    }

    /** Einmal pro Tag eine info loggen, dass der Desk-Push mangels Token übersprungen wurde (kein Spam). */
    private static function log_skip_once(): void {
        if ( get_transient( 'm24_desk_skip_logged' ) ) { return; }
        set_transient( 'm24_desk_skip_logged', 1, DAY_IN_SECONDS );
        if ( class_exists( 'M24_Error_Log' ) ) {
            M24_Error_Log::capture( 'rest_client', 'info', 'Desk-Push übersprungen — kein M24_DESK_API_TOKEN', [] );
        }
    }

    public static function get_api_key() {
        // 1. Test-Mode: Dummy-Key, damit der Pre-Flight nicht abbricht.
        if ( class_exists( 'M24_Settings' ) && M24_Settings::is_test_mode_active() ) {
            return 'mock-no-auth';
        }

        // 2. wp-config.php-Konstante.
        if ( defined( 'M24_DESK_API_TOKEN' ) && ! empty( M24_DESK_API_TOKEN ) ) {
            return (string) M24_DESK_API_TOKEN;
        }

        // 3. DB-Setting.
        $settings = get_option( self::SETTINGS_OPTION, [] );
        return isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : '';
    }

    /**
     * Liefert eine Diagnose-Beschreibung der aktiven Konfiguration.
     * Nuetzlich fuer Logger-Eintraege und die Settings-Page.
     *
     * @return array  [ source: 'test_mode'|'wp_config'|'db'|'unset', detail: string ]
     */
    public static function describe_active_config() {
        if ( class_exists( 'M24_Settings' ) && M24_Settings::is_test_mode_active() ) {
            return [
                'source' => 'test_mode',
                'detail' => 'Test-Mode aktiv → Mock: ' . M24_Settings::effective_mock_url(),
            ];
        }
        if ( defined( 'M24_DESK_API_URL' ) && ! empty( M24_DESK_API_URL ) ) {
            return [
                'source' => 'wp_config',
                'detail' => 'wp-config.php-Konstante: ' . (string) M24_DESK_API_URL,
            ];
        }
        $settings = get_option( self::SETTINGS_OPTION, [] );
        $url = isset( $settings['api_url'] ) ? trim( (string) $settings['api_url'] ) : '';
        if ( $url === '' ) {
            return [ 'source' => 'unset', 'detail' => 'Keine API-URL konfiguriert' ];
        }
        return [ 'source' => 'db', 'detail' => 'DB-Setting: ' . $url ];
    }

    /**
     * Health-Check: GET /api/health
     *
     * @return array Result-Format
     */
    public static function health() {
        return self::request( 'GET', '/api/health' );
    }

    /**
     * Auftrag pushen: POST /api/orders
     *
     * @param array $payload  vollstaendiger Order-Body inkl. customer/items
     * @return array Result-Format
     */
    public static function push_order( $payload ) {
        return self::request( 'POST', '/api/orders', $payload );
    }

    /**
     * Generischer Request.
     *
     * @param string     $method   GET, POST, PUT, DELETE
     * @param string     $path     z.B. /api/health
     * @param array|null $body     wird als JSON gesendet (POST/PUT)
     * @param array      $opts     extra Optionen (timeout, headers)
     * @return array Result-Format
     */
    public static function request( $method, $path, $body = null, $opts = [] ) {
        $base    = self::get_base_url();
        $api_key = self::get_api_key();

        // Pre-Flight: Desk konfiguriert? Sonst SANFT überspringen — KEIN Request, KEIN error-Log, kein Retry-
        // Spam. Nur einmalig eine info („Desk-Push übersprungen — kein Token"); der Aufrufer behandelt
        // 'skipped' als „aufgeschoben", nicht als Fehler.
        if ( ! $base || ! $api_key ) {
            self::log_skip_once();
            return [ 'ok' => false, 'skipped' => true, 'status' => 0, 'error' => 'desk_not_configured', 'message' => 'Desk-Push übersprungen — kein Token/keine URL.' ];
        }

        // Test-Mode-Pfad-Mapping: das Production-Backend liegt unter /api/health,
        // unser Mock unter /health (Praefix /wp-json/m24-plattform/v1/mock/ ist
        // schon Teil der Base-URL). Wir mappen /api/* → /* nur im Test-Mode.
        $effective_path = $path;
        if ( class_exists( 'M24_Settings' ) && M24_Settings::is_test_mode_active() ) {
            if ( strpos( $effective_path, '/api/' ) === 0 ) {
                $effective_path = substr( $effective_path, strlen( '/api' ) );
            }
        }

        $url     = $base . $effective_path;
        $timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : self::DEFAULT_TIMEOUT;

        $headers = [
            'X-API-Key'    => $api_key,
            'Accept'       => 'application/json',
            'User-Agent'   => 'M24-Plattform-Plugin/' . M24_PLATTFORM_VERSION . ' (WordPress)',
        ];
        if ( isset( $opts['headers'] ) && is_array( $opts['headers'] ) ) {
            $headers = array_merge( $headers, $opts['headers'] );
        }

        $args = [
            'method'      => strtoupper( $method ),
            'timeout'     => $timeout,
            'redirection' => 3,
            'headers'     => $headers,
            'sslverify'   => true,
        ];

        if ( $body !== null ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        }

        $started = microtime( true );
        $response = wp_remote_request( $url, $args );
        $elapsed_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

        // Netzwerk-/WP-Fehler (kein HTTP-Response)
        if ( is_wp_error( $response ) ) {
            return self::fail( 'rest_client', 'Netzwerk-Fehler: ' . $response->get_error_message(), 0, [
                'method'     => $method,
                'path'       => $path,
                'elapsed_ms' => $elapsed_ms,
            ] );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $raw    = (string) wp_remote_retrieve_body( $response );
        $data   = null;

        if ( $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $data = $decoded;
            }
        }

        $ok = ( $status >= 200 && $status < 300 );

        $log_payload = [
            'method'     => $method,
            'path'       => $path,
            'status'     => $status,
            'elapsed_ms' => $elapsed_ms,
        ];

        if ( $ok ) {
            M24_Logger::info( 'rest_client', "$method $path -> $status", $log_payload );
        } else {
            $log_payload['response'] = $data ? $data : substr( $raw, 0, 500 );
            M24_Logger::error( 'rest_client', "$method $path -> $status", $log_payload );
        }

        return [
            'ok'     => $ok,
            'status' => $status,
            'data'   => $data,
            'error'  => $ok ? null : self::extract_error( $status, $data, $raw ),
            'raw'    => $data === null ? $raw : null,
        ];
    }

    /**
     * Baut eine Fehler-Antwort, loggt direkt.
     */
    private static function fail( $context, $message, $status, $payload = [] ) {
        M24_Logger::error( $context, $message, $payload );
        return [
            'ok'     => false,
            'status' => $status,
            'data'   => null,
            'error'  => $message,
            'raw'    => null,
        ];
    }

    /**
     * Extrahiert eine sinnvolle Fehlermeldung aus dem Backend-Response.
     * Backend liefert z.B. { "error": "validation_failed", "details": [...] }.
     */
    private static function extract_error( $status, $data, $raw ) {
        if ( is_array( $data ) ) {
            if ( isset( $data['error'] ) ) {
                return is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] );
            }
            if ( isset( $data['message'] ) ) {
                return (string) $data['message'];
            }
        }
        if ( $raw !== '' && $raw !== null ) {
            return 'HTTP ' . $status . ': ' . substr( $raw, 0, 200 );
        }
        return 'HTTP ' . $status;
    }
}
