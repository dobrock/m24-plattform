<?php
/**
 * M24 Plattform — Inquiries-Modul: Mock-Endpoint (Modul D.0)
 *
 * Stellt vier REST-Routen unter /wp-json/m24-plattform/v1/mock/ bereit:
 *
 *  - GET  /health        : 200 mit Health-OK-JSON (gespiegelt an Spec v4 §4.1)
 *  - POST /orders        : 201 mit fake order_num, loggt Request
 *  - POST /orders-fail   : 500, loggt Request (fuer Retry-Tests in D.3)
 *  - GET  /log           : JSON-Liste der letzten 50 Mock-Calls (Auth: manage_options)
 *
 * Jeder Aufruf wird in {prefix}m24_mock_log persistiert. Die Admin-Page-Variante
 * der Log-Ansicht liegt unter admin/class-m24-mock-log-viewer.php.
 *
 * Auth-Modell:
 *  - /health, /orders, /orders-fail: oeffentlich erreichbar (Mock-Charakter, kein
 *    echtes Schutzbeduerfnis; die zugehoerigen Settings-Page-Tests im D.1a-Modul
 *    schicken einen X-API-Key-Header mit, den der Mock akzeptiert ohne ihn zu
 *    pruefen — er loggt ihn aber, damit Daniel beim Live-Push-Debugging sehen
 *    kann, was das Plugin tatsaechlich rausschickt).
 *  - /log: nur fuer eingeloggte Admins (manage_options). REST-Lesepfad zur
 *    schnellen Inspektion via curl mit Cookie oder im Browser nach Admin-Login.
 *
 * Spec-Referenz: Uebergabe v10 Kapitel 4.11, Master-Spec v4 §6.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries_Mock {

    const REST_NAMESPACE = 'm24-plattform/v1';
    const REST_PREFIX    = 'mock';

    /** @var bool Schutz gegen doppelte Init */
    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Registriert die vier Mock-Routen.
     */
    public static function register_routes() {
        $ns = self::REST_NAMESPACE;
        $px = self::REST_PREFIX;

        register_rest_route( $ns, '/' . $px . '/health', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'route_health' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/' . $px . '/orders', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_orders_success' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/' . $px . '/orders-fail', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'route_orders_fail' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/' . $px . '/log', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'route_log' ],
            'permission_callback' => [ __CLASS__, 'permission_admin' ],
        ] );
    }

    /**
     * Permission-Callback fuer die Log-Route — nur Admins.
     */
    public static function permission_admin() {
        return current_user_can( 'manage_options' );
    }

    // ────────────────────────────────────────────────────────────────────
    // Routen-Callbacks
    // ────────────────────────────────────────────────────────────────────

    /**
     * GET /mock/health
     *
     * Spiegelt das Production-Health-Schema (Spec v4 §4.1):
     *   { status, version, time, db, uptime_seconds }
     */
    public static function route_health( WP_REST_Request $request ) {
        $body = [
            'status'         => 'ok',
            'version'        => '1',
            'time'           => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
            'db'             => 'ok',
            'uptime_seconds' => (int) ( microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ),
        ];

        self::log_request( $request, 200, $body );

        return new WP_REST_Response( $body, 200 );
    }

    /**
     * POST /mock/orders
     *
     * Spiegelt das Production-Erfolgsschema (Spec v4 §4.6):
     *   { id, order_num, status, customer_id, customer_existed, created_at }
     *
     * order_num und id werden aus der eigenen Auto-Increment-ID + Zufalls-Offset
     * gebildet, damit sie ueber Mehrfach-Calls hinweg eindeutig wirken.
     */
    public static function route_orders_success( WP_REST_Request $request ) {
        $payload = self::request_json_body( $request );

        // Validierung der Pflichtfelder gemaess Spec v4 §4.2 — Mock pruegt nicht
        // hart durch, aber bei offensichtlich fehlendem source-Diskriminator
        // antworten wir mit 400, weil das im Production-Backend genauso laeuft.
        if ( ! is_array( $payload ) || ( $payload['source'] ?? '' ) !== 'wordpress_plugin' ) {
            $err = [
                'error'   => 'validation_failed',
                'details' => [ 'source' => 'must equal "wordpress_plugin"' ],
            ];
            self::log_request( $request, 400, $err );
            return new WP_REST_Response( $err, 400 );
        }

        // Idempotency-Header auswerten — wenn der Plugin-Schluessel schon einmal
        // erfolgreich gepusht wurde, antworten wir mit 409 (Spec v4 §4.6).
        $idem_key = (string) $request->get_header( 'X-Idempotency-Key' );
        if ( $idem_key !== '' ) {
            $existing = self::find_existing_order_by_idempotency( $idem_key );
            if ( $existing !== null ) {
                $err = [
                    'error'             => 'duplicate',
                    'existing_order_id' => (int) $existing['order_id'],
                    'order_num'         => (string) $existing['order_num'],
                ];
                self::log_request( $request, 409, $err );
                return new WP_REST_Response( $err, 409 );
            }
        }

        // Erfolgs-Response zusammenbauen.
        $fake_id        = (int) ( time() % 100000 ) + wp_rand( 100, 999 );
        $fake_order_num = 'M-MOCK-' . gmdate( 'Y' ) . '-' . str_pad( (string) ( $fake_id % 9999 ), 4, '0', STR_PAD_LEFT );

        $body = [
            'id'                => $fake_id,
            'order_num'         => $fake_order_num,
            'status'            => 'inquiry_received',
            'customer_id'       => wp_rand( 1, 200 ),
            'customer_existed'  => (bool) wp_rand( 0, 1 ),
            'created_at'        => gmdate( 'Y-m-d\TH:i:s.v\Z' ),
        ];

        self::log_request( $request, 201, $body );

        return new WP_REST_Response( $body, 201 );
    }

    /**
     * POST /mock/orders-fail
     *
     * Antwortet konsistent mit 500 — fuer Retry-Tests in Modul D.3.
     */
    public static function route_orders_fail( WP_REST_Request $request ) {
        $body = [
            'error'   => 'internal_error',
            'message' => 'Mock route — always fails. Use for retry/backoff testing.',
        ];

        self::log_request( $request, 500, $body );

        return new WP_REST_Response( $body, 500 );
    }

    /**
     * GET /mock/log
     *
     * Returnt die letzten N Eintraege (Default 50, max 500).
     * Optional ?route=mock/orders zum Filtern.
     */
    public static function route_log( WP_REST_Request $request ) {
        global $wpdb;

        $limit = (int) $request->get_param( 'limit' );
        if ( $limit < 1 ) {
            $limit = 50;
        }
        $limit = min( $limit, 500 );

        $route = (string) $request->get_param( 'route' );

        $table = M24_Database::table( 'mock_log' );

        if ( $route !== '' ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE route = %s ORDER BY id DESC LIMIT %d",
                    $route,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table ORDER BY id DESC LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
        }

        // JSON-Felder dekodieren fuer angenehme API-Response.
        if ( is_array( $rows ) ) {
            foreach ( $rows as &$row ) {
                if ( ! empty( $row['headers'] ) ) {
                    $decoded = json_decode( $row['headers'], true );
                    if ( is_array( $decoded ) ) {
                        $row['headers'] = $decoded;
                    }
                }
                if ( ! empty( $row['body'] ) ) {
                    $decoded = json_decode( $row['body'], true );
                    if ( $decoded !== null ) {
                        $row['body'] = $decoded;
                    }
                }
                if ( ! empty( $row['response_body'] ) ) {
                    $decoded = json_decode( $row['response_body'], true );
                    if ( $decoded !== null ) {
                        $row['response_body'] = $decoded;
                    }
                }
                $row['id']            = (int) $row['id'];
                $row['response_code'] = (int) $row['response_code'];
            }
            unset( $row );
        }

        return new WP_REST_Response( [
            'count' => is_array( $rows ) ? count( $rows ) : 0,
            'rows'  => $rows ?: [],
        ], 200 );
    }

    // ────────────────────────────────────────────────────────────────────
    // Helper
    // ────────────────────────────────────────────────────────────────────

    /**
     * Loggt einen Mock-Request samt Response in {prefix}m24_mock_log.
     *
     * Sensible Header (Authorization, X-API-Key) werden geredact, damit das
     * Mock-Log nicht versehentlich Tokens persistiert. Die Existenz-Info
     * ("Header war gesetzt") bleibt aber sichtbar.
     */
    private static function log_request( WP_REST_Request $request, $response_code, $response_body ) {
        global $wpdb;

        $table = M24_Database::table( 'mock_log' );

        $headers = [];
        foreach ( $request->get_headers() as $name => $values ) {
            // WP_REST_Request normalisiert Header-Namen zu lowercase mit Underscores.
            $display_name  = strtolower( $name );
            $value_display = is_array( $values ) ? implode( ', ', $values ) : (string) $values;

            // Redaction: Authorization-Header und API-Key-Varianten.
            if ( in_array( $display_name, [ 'authorization', 'x_api_key', 'x-api-key' ], true ) ) {
                $value_display = '[REDACTED — length=' . strlen( $value_display ) . ']';
            }

            $headers[ $display_name ] = $value_display;
        }

        $body_raw = $request->get_body();
        $body_for_log = $body_raw;
        // Wenn JSON-Body, huebsch normalisieren — sonst Original-String.
        if ( $body_raw !== '' && $body_raw !== null ) {
            $decoded = json_decode( $body_raw, true );
            if ( is_array( $decoded ) ) {
                $body_for_log = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }
        }

        // Auswertung fuer Index-Spalten.
        $idem_key = (string) $request->get_header( 'X-Idempotency-Key' );
        $source   = '';
        if ( ! empty( $body_for_log ) ) {
            $decoded = json_decode( (string) $body_for_log, true );
            if ( is_array( $decoded ) && isset( $decoded['source'] ) ) {
                $source = (string) $decoded['source'];
            }
        }

        $route = self::route_label_from_request( $request );

        $wpdb->insert(
            $table,
            [
                'method'          => strtoupper( $request->get_method() ),
                'route'           => $route,
                'headers'         => wp_json_encode( $headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'body'            => $body_for_log,
                'response_code'   => (int) $response_code,
                'response_body'   => is_array( $response_body ) || is_object( $response_body )
                    ? wp_json_encode( $response_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
                    : (string) $response_body,
                'idempotency_key' => $idem_key !== '' ? substr( $idem_key, 0, 120 ) : null,
                'source'          => $source !== '' ? substr( $source, 0, 40 ) : null,
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
        );
    }

    /**
     * Konstruiert das route-Label fuer die Log-Spalte.
     *
     * WP_REST_Request hat get_route() — das liefert "/m24-plattform/v1/mock/orders".
     * Wir kuerzen auf "mock/orders" fuer kompakte Tabellen-Anzeige.
     */
    private static function route_label_from_request( WP_REST_Request $request ) {
        $route = $request->get_route();
        $route = ltrim( (string) $route, '/' );
        $prefix = self::REST_NAMESPACE . '/';
        if ( strpos( $route, $prefix ) === 0 ) {
            $route = substr( $route, strlen( $prefix ) );
        }
        return substr( $route, 0, 120 );
    }

    /**
     * Liest den Request-Body und versucht JSON-Decode.
     *
     * @return array|null  Array bei JSON-Object, sonst null.
     */
    private static function request_json_body( WP_REST_Request $request ) {
        $raw = $request->get_body();
        if ( ! is_string( $raw ) || $raw === '' ) {
            return null;
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Sucht im Mock-Log nach einer frueheren erfolgreichen Order zum gleichen
     * Idempotency-Key. Liefert ['order_id' => int, 'order_num' => string] oder null.
     *
     * "Erfolgreich" = response_code 201 auf der orders-Route.
     */
    private static function find_existing_order_by_idempotency( $idem_key ) {
        global $wpdb;
        $table = M24_Database::table( 'mock_log' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT response_body FROM $table
                 WHERE idempotency_key = %s
                   AND response_code = 201
                   AND route = %s
                 ORDER BY id ASC
                 LIMIT 1",
                $idem_key,
                'mock/orders'
            ),
            ARRAY_A
        );

        if ( ! $row || empty( $row['response_body'] ) ) {
            return null;
        }

        $decoded = json_decode( $row['response_body'], true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['id'], $decoded['order_num'] ) ) {
            return null;
        }

        return [
            'order_id'  => (int) $decoded['id'],
            'order_num' => (string) $decoded['order_num'],
        ];
    }
}
