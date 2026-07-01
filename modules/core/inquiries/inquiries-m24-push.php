<?php
/**
 * M24 Plattform — Inquiries-Modul: M24-Desk-Push (Modul D.1b)
 *
 * Mappt einen Inquiry-CPT auf das Backend-Schema (Spec v4 §4.2-4.5) und
 * pusht ihn via M24_REST_Client::push_order() ans M24-Desk-Backend.
 *
 * Pfad-Routing nach Response (Spec v4 §4.6):
 *   201 Erfolg          → Status synced, Order-Meta speichern
 *   409 Idempotency-Hit → Status synced (wie Erfolg), Order-Meta aus Response
 *   400/422 Validation  → Status sync_failed, Mail-Fallback-Trigger (Hook), KEIN Retry
 *   401/403 Auth        → Status sync_failed, Mail-Fallback-Trigger, KEIN Retry
 *   404                 → Status sync_failed, Mail-Fallback-Trigger, KEIN Retry
 *   5xx / Netzwerk      → Status bleibt pending_api_push, Cron-Retry-Slot in 60s
 *                         (D.3 ersetzt das durch echte Backoff-Logik)
 *
 * Trigger-Schnittstelle:
 *   - transition_post_status: jeder Wechsel auf pending_api_push → schedule_push()
 *   - schedule_push() registriert wp_schedule_single_event(time(), 'm24_inquiry_push', [post_id])
 *   - run_push() ist der Cron-Callback und enthaelt die eigentliche Logik
 *
 * Idempotency-Key (Spec v4 §4.7-Konvention):
 *   m24_wp_<hostname>_<post_id>_<post_modified_unix>
 *   - Site-Identitaet: damit mehrere WP-Instanzen koexistieren koennen
 *   - Post-ID: stabil pro Inquiry
 *   - Modified-Unix: aendert sich, wenn die Inquiry tatsaechlich neu serialisiert
 *     wurde — verhindert dass ein retried Push faelschlich als neue Order erkannt
 *     wird, aber laesst Re-Submissions nach Datenupdate als neue Order durchgehen
 *
 * Hook-Schnittstellen (von D.2/D.3 konsumierbar):
 *   - do_action( 'm24_inquiry_pushed_ok', $post_id, $order_id, $order_num )
 *   - do_action( 'm24_inquiry_mail_fallback', $post_id, $reason ) — D.2
 *   - do_action( 'm24_inquiry_push_retry_scheduled', $post_id, $next_run_ts ) — D.3
 *
 * Postmeta-Schreibe:
 *   _m24_desk_order_id        (int)     M24-Desk Order-ID
 *   _m24_desk_order_num       (string)  M-2026-XXXX
 *   _m24_idempotency_key      (string)  generierter Key
 *   _m24_push_attempts        (int)     Anzahl Versuche (1, 2, ...)
 *   _m24_push_last_attempt    (string)  ISO-Timestamp letzter Versuch
 *   _m24_push_last_status     (int)     letzter HTTP-Status (0 = Netzwerk-Fehler)
 *   _m24_push_last_error      (string)  letzte Fehlermeldung (bei nicht-201/409)
 *   _m24_push_next_retry      (int)     Unix-Timestamp naechster Retry (nur bei 5xx)
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries_Push {

    const CRON_HOOK_PUSH  = 'm24_inquiry_push';
    const CRON_HOOK_RETRY = 'm24_inquiry_push_retry';

    /** Initialer Retry-Delay in Sekunden bei 5xx — D.3 macht draus echten Backoff. */
    const INITIAL_RETRY_DELAY = 60;

    /** @var bool Schutz gegen doppelte Init */
    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // Trigger: jeder Wechsel auf pending_api_push schedult einen Push.
        add_action( 'transition_post_status', [ __CLASS__, 'on_status_transition' ], 10, 3 );

        // Cron-Callback fuer den eigentlichen Push.
        add_action( self::CRON_HOOK_PUSH,  [ __CLASS__, 'run_push' ], 10, 1 );
        add_action( self::CRON_HOOK_RETRY, [ __CLASS__, 'run_push' ], 10, 1 );

        if ( defined( 'M24_LOG_MODULE_LOADS' ) && M24_LOG_MODULE_LOADS && class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_push', 'Push-Modul geladen' );
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Trigger
    // ────────────────────────────────────────────────────────────────────

    /**
     * Hook auf transition_post_status.
     * Schedult einen Push, wenn ein Inquiry-CPT auf pending_api_push geht.
     *
     * Wir lassen den Hook auch greifen, wenn $old === pending_api_push (Re-Trigger
     * via "Erneut pushen"-Admin-Aktion in D.4). Die einzige Bedingung ist:
     * neuer Status ist pending_api_push und Posttyp passt.
     */
    public static function on_status_transition( $new_status, $old_status, $post ) {
        if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
            return;
        }
        if ( $post->post_type !== M24_Inquiries_Storage::CPT_SLUG ) {
            return;
        }
        if ( $new_status !== M24_Inquiries::STATUS_PENDING ) {
            return;
        }

        self::schedule_push( (int) $post->ID );
    }

    /**
     * Schedult einen einmaligen Push fuer den naechsten WP-Cron-Tick.
     * Idempotent: doppelte Schedules auf gleichen Zeitpunkt mit gleichen Args
     * werden von WP-Cron deduplicated.
     */
    public static function schedule_push( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }

        // Sofort, nicht zukuenftig — WP-Cron picked es beim naechsten Page-Load.
        $scheduled = wp_schedule_single_event( time(), self::CRON_HOOK_PUSH, [ $post_id ] );

        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_push', 'Push scheduled', [
                'post_id'   => $post_id,
                'scheduled' => $scheduled !== false,
            ] );
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Push-Ausfuehrung
    // ────────────────────────────────────────────────────────────────────

    /**
     * Cron-Callback. Fuehrt den eigentlichen Push aus.
     *
     * @param int $post_id  Inquiry-CPT-ID
     */
    public static function run_push( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== M24_Inquiries_Storage::CPT_SLUG ) {
            self::log_warning( $post_id, 'Push-Job auf nicht-existierendem oder falschem Posttyp' );
            return;
        }

        // Nur pushen, wenn Status auch wirklich pending ist. Schuetzt vor
        // Race-Condition: User klickt "manuell synced" zwischen Schedule und Run.
        if ( $post->post_status !== M24_Inquiries::STATUS_PENDING ) {
            self::log_info( $post_id, 'Push uebersprungen — Status ist nicht mehr pending', [
                'current_status' => $post->post_status,
            ] );
            return;
        }

        $attempts = (int) get_post_meta( $post_id, '_m24_push_attempts', true );
        $attempts++;
        update_post_meta( $post_id, '_m24_push_attempts',     $attempts );
        update_post_meta( $post_id, '_m24_push_last_attempt', gmdate( 'Y-m-d\TH:i:s\Z' ) );

        // Mapping-Daten zusammenbauen.
        $payload = self::build_payload( $post );
        if ( is_wp_error( $payload ) ) {
            self::log_error( $post_id, 'Mapping fehlgeschlagen', [
                'error' => $payload->get_error_message(),
            ] );
            self::mark_failed( $post_id, 0, 'mapping_failed: ' . $payload->get_error_message() );
            do_action( 'm24_inquiry_mail_fallback', $post_id, 'mapping_failed' );
            return;
        }

        $idem_key = self::build_idempotency_key( $post );
        update_post_meta( $post_id, '_m24_idempotency_key', $idem_key );

        $result = M24_REST_Client::request( 'POST', '/api/orders', $payload, [
            'headers' => [ 'X-Idempotency-Key' => $idem_key ],
        ] );

        update_post_meta( $post_id, '_m24_push_last_status', (int) $result['status'] );

        // Routing nach Status-Code.
        $status = (int) $result['status'];

        if ( $status === 201 || $status === 200 ) {
            self::handle_success( $post_id, $result, $idem_key, $attempts );
            return;
        }
        if ( $status === 409 ) {
            self::handle_conflict( $post_id, $result, $idem_key, $attempts );
            return;
        }
        if ( $status >= 400 && $status < 500 ) {
            self::handle_client_error( $post_id, $result, $status );
            return;
        }
        // 5xx oder Netzwerk-Fehler (status === 0) → Retry-Slot.
        self::handle_server_error_retry( $post_id, $result, $status, $attempts );
    }

    // ────────────────────────────────────────────────────────────────────
    // Result-Handler
    // ────────────────────────────────────────────────────────────────────

    private static function handle_success( $post_id, $result, $idem_key, $attempts ) {
        $data      = is_array( $result['data'] ) ? $result['data'] : [];
        $order_id  = isset( $data['id'] )        ? (int)    $data['id']        : 0;
        $order_num = isset( $data['order_num'] ) ? (string) $data['order_num'] : '';

        update_post_meta( $post_id, '_m24_desk_order_id',  $order_id );
        update_post_meta( $post_id, '_m24_desk_order_num', $order_num );
        delete_post_meta( $post_id, '_m24_push_last_error' );
        delete_post_meta( $post_id, '_m24_push_next_retry' );

        wp_update_post( [
            'ID'          => $post_id,
            'post_status' => M24_Inquiries::STATUS_SYNCED,
        ] );

        self::log_info( $post_id, 'Push erfolgreich', [
            'attempts'  => $attempts,
            'order_id'  => $order_id,
            'order_num' => $order_num,
        ] );

        do_action( 'm24_inquiry_pushed_ok', $post_id, $order_id, $order_num );
    }

    /**
     * 409 Idempotency-Hit. Backend sagt: "Diese Order existiert schon, hier
     * ist die existing_order_id." Wir behandeln das wie 201, aber mit dem
     * existing_order_id aus der Response.
     */
    private static function handle_conflict( $post_id, $result, $idem_key, $attempts ) {
        $data      = is_array( $result['data'] ) ? $result['data'] : [];
        $order_id  = isset( $data['existing_order_id'] ) ? (int)    $data['existing_order_id'] : 0;
        $order_num = isset( $data['order_num'] )         ? (string) $data['order_num']         : '';

        update_post_meta( $post_id, '_m24_desk_order_id',  $order_id );
        update_post_meta( $post_id, '_m24_desk_order_num', $order_num );
        delete_post_meta( $post_id, '_m24_push_last_error' );
        delete_post_meta( $post_id, '_m24_push_next_retry' );

        wp_update_post( [
            'ID'          => $post_id,
            'post_status' => M24_Inquiries::STATUS_SYNCED,
        ] );

        self::log_info( $post_id, 'Push 409 idempotency-hit, als synced behandelt', [
            'attempts'          => $attempts,
            'existing_order_id' => $order_id,
            'order_num'         => $order_num,
        ] );

        do_action( 'm24_inquiry_pushed_ok', $post_id, $order_id, $order_num );
    }

    /**
     * 4xx (ausser 409): Validation/Auth/NotFound. Kein Retry, direkt
     * Mail-Fallback triggern.
     */
    private static function handle_client_error( $post_id, $result, $status ) {
        $error = (string) ( $result['error'] ?? '' );
        $reason = sprintf( 'http_%d: %s', $status, $error );

        self::mark_failed( $post_id, $status, $error );

        self::log_error( $post_id, 'Push 4xx — kein Retry', [
            'status' => $status,
            'error'  => $error,
            'data'   => is_array( $result['data'] ) ? $result['data'] : null,
        ] );

        do_action( 'm24_inquiry_mail_fallback', $post_id, $reason );
    }

    /**
     * 5xx oder Netzwerk-Fehler: Retry. Status bleibt pending_api_push.
     * D.1b setzt nur einen einfachen Retry in 60 Sekunden;
     * D.3 ersetzt diese Methode durch Exponential-Backoff mit Max-Versuchen.
     */
    private static function handle_server_error_retry( $post_id, $result, $status, $attempts ) {
        $error = (string) ( $result['error'] ?? '' );

        update_post_meta( $post_id, '_m24_push_last_error', $error );

        $next_run = time() + self::INITIAL_RETRY_DELAY;
        update_post_meta( $post_id, '_m24_push_next_retry', $next_run );

        wp_schedule_single_event( $next_run, self::CRON_HOOK_RETRY, [ $post_id ] );

        self::log_warning( $post_id, 'Push 5xx/network — Retry geplant', [
            'status'    => $status,
            'error'     => $error,
            'attempts'  => $attempts,
            'next_run'  => gmdate( 'Y-m-d\TH:i:s\Z', $next_run ),
            'delay_sec' => self::INITIAL_RETRY_DELAY,
        ] );

        do_action( 'm24_inquiry_push_retry_scheduled', $post_id, $next_run );
    }

    /**
     * Setzt Status auf sync_failed und schreibt Fehlermeldung.
     */
    private static function mark_failed( $post_id, $status, $error ) {
        update_post_meta( $post_id, '_m24_push_last_error', $error );
        delete_post_meta( $post_id, '_m24_push_next_retry' );

        wp_update_post( [
            'ID'          => $post_id,
            'post_status' => M24_Inquiries::STATUS_FAILED,
        ] );
    }

    // ────────────────────────────────────────────────────────────────────
    // Mapping-Layer (Plugin-CPT → Backend-Schema)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Baut das vollstaendige Order-Body-Array aus dem CPT-Eintrag.
     *
     * @param WP_Post $post
     * @return array|WP_Error
     */
    public static function build_payload( $post ) {
        $post_id = (int) $post->ID;

        // Kontaktdaten lesen.
        $get = function( $key ) use ( $post_id ) {
            return (string) get_post_meta( $post_id, '_m24_' . $key, true );
        };

        $vorname  = $get( 'vorname' );
        $nachname = $get( 'nachname' );
        $email    = $get( 'email' );

        // Nur E-Mail ist Pflicht (Vorname/Nachname sind optional).
        if ( $email === '' ) {
            return new WP_Error( 'm24_push_missing_contact', 'email fehlt' );
        }

        $items_raw = get_post_meta( $post_id, '_m24_items', true );
        if ( ! is_array( $items_raw ) || empty( $items_raw ) ) {
            return new WP_Error( 'm24_push_no_items', 'Keine Items im Postmeta' );
        }

        $internal_source = $get( 'inquiry_source' );
        $api_source      = self::map_source_to_api( $internal_source );

        $source_meta_json = (string) get_post_meta( $post_id, '_m24_inquiry_source_meta', true );
        $source_meta      = [];
        if ( $source_meta_json !== '' ) {
            $decoded = json_decode( $source_meta_json, true );
            if ( is_array( $decoded ) ) {
                $source_meta = $decoded;
            }
        }

        $items_mapped = self::map_items( $items_raw );

        $sender_lang = self::derive_sender_lang( $get( 'land' ) );

        $cust_full = trim( $vorname . ' ' . $nachname );
        if ( '' === $cust_full ) { $cust_full = $get( 'firma' ) !== '' ? $get( 'firma' ) : $email; }

        $customer = [
            'firma'    => $get( 'firma' ),
            'name'     => $cust_full,
            'email'    => $email,
            'tel'      => $get( 'tel' ),
            'strasse'  => $get( 'strasse' ),
            'plz'      => $get( 'plz' ),
            'ort'      => $get( 'ort' ),
            'land'     => $get( 'land' ),
            'uid'      => $get( 'uid' ),
            'biz'      => ( $get( 'biz' ) === '1' ) ? 'b2b' : 'b2c',
        ];
        // Leere Strings rauswerfen, damit Backend nicht "" als gesetzt interpretiert.
        $customer = array_filter( $customer, function( $v ) {
            return $v !== '' && $v !== null;
        } );

        $body = [
            'source'              => 'wordpress_plugin',
            'subj'                => self::build_subj( $post, $items_mapped ),
            'cust'                => $cust_full,
            'sender_email'        => $email,
            'sender_lang'         => $sender_lang,
            'country'             => $get( 'land' ),
            'inquiry_source'      => $api_source,
            'inquiry_source_meta' => (object) $source_meta, // (object) damit json_encode {} statt [] schreibt
            'items'               => $items_mapped,
            'customer'            => (object) $customer,
        ];

        $notes = (string) $post->post_content;
        if ( $notes !== '' ) {
            $body['notes'] = mb_substr( $notes, 0, 2000 );
        }

        return $body;
    }

    /**
     * Plugin-internes Source-Token → Backend-Wert (Spec v4 §4.3).
     */
    public static function map_source_to_api( $internal ) {
        $map = [
            M24_Inquiries::SOURCE_CART    => 'wordpress_plugin_cart',
            M24_Inquiries::SOURCE_PRODUCT => 'wordpress_plugin_product',
            M24_Inquiries::SOURCE_CONTACT => 'wordpress_plugin_contact',
            M24_Inquiries::SOURCE_BLOG    => 'wordpress_plugin_blog',
        ];
        return $map[ $internal ] ?? 'wordpress_plugin_cart';
    }

    /**
     * Mappt das Plugin-Item-Array auf das Backend-Item-Schema (Spec v4 §4.4).
     *
     * Plugin-Item: { art, qty, price, src_url, src_pillar, src_modell, src_pid,
     *                src_art_nr, src_variant }
     * Backend-Item: { name, qty, ek=0, vk, price_on_request?, src_url, src_pillar,
     *                 src_modell, src_pid, src_art_nr, src_variant, src_lang="de" }
     */
    public static function map_items( $items_raw ) {
        $out = [];
        foreach ( $items_raw as $item ) {
            if ( ! is_array( $item ) || empty( $item['art'] ) ) {
                continue;
            }

            // Preis-Logik: numerisch (mit Komma oder Punkt) → vk = float, sonst poR
            $price_str        = isset( $item['price'] ) ? trim( (string) $item['price'] ) : '';
            $price_normalized = str_replace( ',', '.', $price_str );

            $is_numeric = ( $price_str !== '' && is_numeric( $price_normalized ) );
            $vk         = $is_numeric ? (float) $price_normalized : 0.0;

            $mapped = [
                'name'        => (string) $item['art'],
                'qty'         => isset( $item['qty'] ) ? max( 1, (int) $item['qty'] ) : 1,
                'ek'          => 0,
                'vk'          => $vk,
                'src_url'     => isset( $item['src_url'] )     ? (string) $item['src_url']     : '',
                'src_pillar'  => isset( $item['src_pillar'] )  ? (string) $item['src_pillar']  : 'gebrauchtteile',
                'src_modell'  => isset( $item['src_modell'] )  ? (string) $item['src_modell']  : '',
                'src_pid'     => isset( $item['src_pid'] )     ? (string) $item['src_pid']     : '',
                'src_art_nr'  => isset( $item['src_art_nr'] )  ? (string) $item['src_art_nr']  : '',
                'src_variant' => isset( $item['src_variant'] ) ? (string) $item['src_variant'] : '',
                'src_lang'    => 'de', // hartcoded Phase 1
            ];

            // price_on_request nur setzen, wenn true (Spec v4 §4.4: false weglassen).
            if ( ! $is_numeric ) {
                $mapped['price_on_request'] = true;
            }

            $out[] = $mapped;
        }
        return $out;
    }

    /**
     * Konstruiert den subj-String fuer den Backend-Body.
     * Format: "Sammelanfrage: N Artikel" (DE) bzw. "Inquiry: N items" (EN).
     */
    private static function build_subj( $post, $items_mapped ) {
        $count = count( $items_mapped );

        // Benutze post_title falls bereits aussagekraeftig (storage.php setzt
        // "Anfrage — Vorname Nachname (N Positionen)"), sonst Fallback.
        $title = (string) $post->post_title;
        if ( $title !== '' ) {
            $subj = $title;
        } else {
            $subj = sprintf(
                /* translators: %d: count */
                _n( 'Sammelanfrage: %d Artikel', 'Sammelanfrage: %d Artikel', $count, 'm24-plattform' ),
                $count
            );
        }
        return mb_substr( $subj, 0, 255 );
    }

    /**
     * Sender-Sprache aus dem Land ableiten (Spec v4 §6.3).
     * Phase 1: DE/AT/CH → de, sonst en.
     */
    private static function derive_sender_lang( $land ) {
        $de_speaking = [ 'Deutschland', 'Österreich', 'Schweiz', 'DE', 'AT', 'CH', 'Germany', 'Austria', 'Switzerland' ];
        if ( in_array( trim( (string) $land ), $de_speaking, true ) ) {
            return 'de';
        }
        return 'en';
    }

    /**
     * Idempotency-Key-Konstruktion.
     *
     * Format: m24_wp_<host>_<post_id>_<post_modified_unix>
     *
     * - host: parse_url(home_url, PHP_URL_HOST), Underscores statt Punkte
     * - post_id: stabile Inquiry-ID
     * - post_modified_unix: aendert sich, wenn die Inquiry serverseitig neu
     *   gespeichert wurde (z.B. via Admin-Edit). Ein simpler Retry hat
     *   denselben Wert und triggert damit Backend-Idempotency-Logik (409).
     *
     * Maximal 120 Zeichen (DB-Spalte mock_log.idempotency_key).
     */
    public static function build_idempotency_key( $post ) {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $host = preg_replace( '/[^a-zA-Z0-9]+/', '_', $host );
        $host = trim( $host, '_' );
        if ( $host === '' ) { $host = 'unknown'; }

        $modified = strtotime( (string) $post->post_modified_gmt );
        if ( ! $modified ) {
            $modified = time();
        }

        $key = sprintf( 'm24_wp_%s_%d_%d', $host, (int) $post->ID, (int) $modified );
        return mb_substr( $key, 0, 120 );
    }

    // ────────────────────────────────────────────────────────────────────
    // Logging-Helpers (alle gehen via M24_Logger; class_exists-Check
    // unnoetig, weil das Modul nach Logger-Bootstrap geladen wird)
    // ────────────────────────────────────────────────────────────────────

    private static function log_info( $post_id, $message, $extra = [] ) {
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiry_push', $message, array_merge( [ 'post_id' => $post_id ], $extra ) );
        }
    }
    private static function log_warning( $post_id, $message, $extra = [] ) {
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::warning( 'inquiry_push', $message, array_merge( [ 'post_id' => $post_id ], $extra ) );
        }
    }
    private static function log_error( $post_id, $message, $extra = [] ) {
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::error( 'inquiry_push', $message, array_merge( [ 'post_id' => $post_id ], $extra ) );
        }
    }
}
