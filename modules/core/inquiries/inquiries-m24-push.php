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
 *   _m24_push_next_retry      (int)     Unix-Timestamp naechster Retry (5xx/Netz + unvollstaendige Anfrage)
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries_Push {

    const CRON_HOOK_PUSH  = 'm24_inquiry_push';
    const CRON_HOOK_RETRY = 'm24_inquiry_push_retry';
    const CRON_HOOK_WATCH = 'm24_inquiry_push_watchdog';
    /** Ab wann eine Anfrage ohne Desk-Nummer als haengend gilt (Minuten). Grosszuegig: Retries duerfen laufen. */
    const STUCK_AFTER_MIN = 30;

    /** Basis-Delay in Sekunden fuer den exponentiellen Backoff (60, 120, 240, …). */
    const INITIAL_RETRY_DELAY = 60;

    /** Obergrenze der Push-Versuche. Danach mark_failed statt weiterem Retry — kein Dauerfeuer. */
    const MAX_ATTEMPTS = 8;

    /** Deckel fuer den Backoff in Sekunden. 8 Versuche ergeben rund 2 Stunden Nachlauf. */
    const MAX_RETRY_DELAY = 3600;

    /** @var bool Schutz gegen doppelte Init */
    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // Trigger: die FERTIG angelegte Anfrage — nicht transition_post_status. Der feuert aus
        // wp_insert_post() heraus, also bevor insert_inquiry() eine einzige Meta geschrieben hat.
        // Faellt schedule_push() dann auf den synchronen Zweig zurueck (DISABLE_WP_CRON), sah
        // build_payload() eine Anfrage ohne E-Mail und ohne Positionen -> "mapping_failed: email
        // fehlt", ohne dass je ein Request rausging (belegt an #35128). m24_inquiry_created feuert
        // nach allen Meta-Writes (inquiries-storage.php:251). Prioritaet 20: erst die
        // Benachrichtigungs-Mail (notify, Prio 10), dann der Push — ein haengender Desk-Call
        // darf die Mail nicht aufhalten.
        add_action( 'm24_inquiry_created', [ __CLASS__, 'on_inquiry_created' ], 20, 1 );

        // Cron-Callback fuer den eigentlichen Push.
        add_action( self::CRON_HOOK_PUSH,  [ __CLASS__, 'run_push' ], 10, 1 );
        add_action( self::CRON_HOOK_RETRY, [ __CLASS__, 'run_push' ], 10, 1 );

        // Sobald der Desk-Token gesetzt ist: aufgeschobene Pushs nachholen (Set/Unset-sicher, gedrosselt).
        add_action( 'admin_init', [ __CLASS__, 'maybe_resume_deferred' ] );

        // Waechter: haengengebliebene Anfragen sichtbar machen. Der Ausfall ab 14.08. blieb zwei Wochen
        // unbemerkt — nicht weil nichts geloggt wurde, sondern weil das Log ERFOLG meldete. Deshalb
        // prueft der Waechter nicht Log-Eintraege, sondern den tatsaechlichen Zustand: liegt am Eintrag
        // eine Desk-Auftragsnummer? Alles andere ist Behauptung.
        add_action( 'admin_notices', [ __CLASS__, 'stuck_notice' ] );
        add_action( self::CRON_HOOK_WATCH, [ __CLASS__, 'run_watchdog' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK_WATCH ) ) {
            wp_schedule_event( time() + 900, 'hourly', self::CRON_HOOK_WATCH );
        }
    }

    /** Aufgeschobene Anfragen (Desk war ohne Token) neu einplanen, sobald der Desk konfiguriert ist. */
    public static function maybe_resume_deferred() {
        if ( ! class_exists( 'M24_REST_Client' ) || ! M24_REST_Client::is_configured() ) { return; }
        if ( get_transient( 'm24_push_resume_checked' ) ) { return; } // gedrosselt: max. 1×/Stunde
        set_transient( 'm24_push_resume_checked', 1, HOUR_IN_SECONDS );
        $ids = get_posts( [
            'post_type'      => M24_Inquiries_Storage::CPT_SLUG,
            'post_status'    => M24_Inquiries::STATUS_PENDING,
            'meta_key'       => '_m24_push_deferred',
            'meta_value'     => '1',
            'fields'         => 'ids',
            'posts_per_page' => 50,
            'no_found_rows'  => true,
        ] );
        foreach ( (array) $ids as $id ) { self::schedule_push( (int) $id ); }
    }

    // ────────────────────────────────────────────────────────────────────
    // Trigger
    // ────────────────────────────────────────────────────────────────────

    /**
     * Hook auf m24_inquiry_created — feuert in insert_inquiry() NACH allen Meta-Writes.
     * Ab hier sind E-Mail, Positionen und Kontaktdaten garantiert lesbar, auch wenn
     * schedule_push() synchron durchzieht.
     */
    public static function on_inquiry_created( $post_id ) {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== M24_Inquiries_Storage::CPT_SLUG ) {
            return;
        }
        // Nur frisch angelegte, noch nicht uebergebene Anfragen.
        if ( $post->post_status !== M24_Inquiries::STATUS_PENDING ) {
            return;
        }

        self::schedule_push( $post_id );
    }

    /**
     * Mapping-Fehler, die KEIN fachliches Problem sind, sondern ein Zeitproblem: die Anfrage
     * ist (noch) unvollstaendig. Die duerfen nicht in mark_failed enden — nichts im Code setzt
     * einen Eintrag je wieder auf pending, er waere sonst endgueltig tot.
     */
    private static function deferrable_errors(): array {
        return [ 'm24_push_missing_contact', 'm24_push_no_items' ];
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

        // Desk nicht konfiguriert (kein Token/keine URL) → GAR NICHT schedulen. Als „aufgeschoben" markieren;
        // maybe_resume_deferred() holt es nach, sobald der Token gesetzt ist. Kein Request, kein Retry-Spam.
        if ( class_exists( 'M24_REST_Client' ) && ! M24_REST_Client::is_configured() ) {
            update_post_meta( $post_id, '_m24_push_deferred', 1 );
            return;
        }
        delete_post_meta( $post_id, '_m24_push_deferred' );

        // Bevorzugt entkoppelt ueber WP-Cron — ein haengender Desk-Call darf das Absenden des Formulars
        // nicht verzoegern. ABER: mit DISABLE_WP_CRON gibt es keinen Page-Load-Trigger, der Job bliebe
        // fuer immer in wp_options.cron liegen. Genau das ist ab 14.08.2026 passiert: 16 Anfragen
        // hingen zwei Wochen fest, ohne dass irgendwo ein Fehler auftauchte.
        //
        // Tueckisch daran: wp_schedule_single_event() meldet auch dann Erfolg — es PLANT ja korrekt, nur
        // ausgefuehrt wird nie. Der frueher hier geloggte "scheduled: true" war deshalb wertlos.
        // Gleiche Absicherung wie beim Angebotsversand (class-m24-offers.php:1305).
        $cron_off  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        $scheduled = false;
        if ( ! $cron_off ) {
            $scheduled = ( false !== wp_schedule_single_event( time(), self::CRON_HOOK_PUSH, [ $post_id ] ) );
        }
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_push', $scheduled ? 'Push scheduled' : 'Push laeuft synchron', [
                'post_id'   => $post_id,
                'scheduled' => $scheduled,
                'cron_off'  => $cron_off,
            ] );
        }
        if ( ! $scheduled ) {
            // Synchron durchziehen. Gekapselt: ein Fehler im Push darf das Absenden des Formulars nicht
            // mit einem Fatal quittieren — der Nutzer hat seine Anfrage korrekt abgeschickt.
            try {
                self::run_push( $post_id );
            } catch ( \Throwable $e ) {
                self::log_warning( $post_id, 'Synchroner Push fehlgeschlagen: ' . $e->getMessage() );
            }
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

        // DUBLETTEN-RIEGEL: liegt bereits eine Desk-Auftragsnummer am Eintrag, wurde er drueben angelegt —
        // dann NIE erneut pushen. Der Status-Guard darunter reicht dafuer nicht: steht derselbe Eintrag
        // mehrfach in wp_options.cron (jeder Seitenaufbau kann neu geplant haben), laufen zwei Jobs so
        // dicht hintereinander, dass der zweite startet, bevor der erste den Status geschrieben hat.
        // Genau so sind 2026-1047 und 2026-1048 fuer denselben Formidable-Eintrag entstanden.
        $have_num = trim( (string) get_post_meta( $post_id, '_m24_desk_order_num', true ) );
        if ( '' !== $have_num ) {
            self::log_info( $post_id, 'Push uebersprungen — Eintrag ist bereits im Desk angelegt', [
                'desk_order_num' => $have_num,
            ] );
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

        // Desk nicht konfiguriert → AUFSCHIEBEN statt in Dauerschleife retryen. Kein Request, kein error-Log,
        // KEIN Retry-Reschedule (maybe_resume_deferred holt es nach, sobald der Token gesetzt ist).
        if ( class_exists( 'M24_REST_Client' ) && ! M24_REST_Client::is_configured() ) {
            update_post_meta( $post_id, '_m24_push_deferred', 1 );
            self::log_info( $post_id, 'Push aufgeschoben — Desk nicht konfiguriert (kein Token)' );
            return;
        }

        // Test-Mode ZUM AUSFÜHRUNGSZEITPUNKT prüfen (wie der synchrone Pfad). Der Cron-Loopback ist ein
        // eigener Request (is_admin()===false) — die Settings-Klasse hier defensiv sicherstellen, sonst
        // überspränge M24_REST_Client::get_base_url() den Test-Mode-Zweig und pushte an Production.
        if ( ! class_exists( 'M24_Settings' ) && defined( 'M24_PLATTFORM_DIR' ) && is_readable( M24_PLATTFORM_DIR . 'admin/class-m24-settings.php' ) ) {
            require_once M24_PLATTFORM_DIR . 'admin/class-m24-settings.php';
        }
        // Frischer Options-Read (persistenter Objekt-Cache im Loopback kann stale sein).
        wp_cache_delete( M24_REST_Client::SETTINGS_OPTION, 'options' );
        $is_test  = class_exists( 'M24_Settings' ) && M24_Settings::is_test_mode_active();
        $tgt_host = class_exists( 'M24_REST_Client' ) ? (string) wp_parse_url( M24_REST_Client::get_base_url(), PHP_URL_HOST ) : '';
        self::log_info( $post_id, 'Push-Ausführung — Zielkonfiguration aufgelöst', [
            'test_mode'   => $is_test ? 'on' : 'off',
            'target'      => $is_test ? 'mock' : 'production',
            'target_host' => $tgt_host,
        ] );

        $attempts = (int) get_post_meta( $post_id, '_m24_push_attempts', true );
        $attempts++;
        update_post_meta( $post_id, '_m24_push_attempts',     $attempts );
        update_post_meta( $post_id, '_m24_push_last_attempt', gmdate( 'Y-m-d\TH:i:s\Z' ) );

        // Mapping-Daten zusammenbauen.
        $payload = self::build_payload( $post );
        if ( is_wp_error( $payload ) ) {
            // Fehlende E-Mail/Positionen: erneut versuchen statt den Eintrag zu beerdigen.
            if ( in_array( $payload->get_error_code(), self::deferrable_errors(), true ) ) {
                self::handle_incomplete_retry( $post_id, $payload, $attempts );
                return;
            }
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
     * 5xx oder Netzwerk-Fehler: Retry mit exponentiellem Backoff, begrenzt auf MAX_ATTEMPTS.
     * Status bleibt bis dahin pending_api_push. Vorher lief das als 60-Sekunden-Dauerfeuer
     * ohne jeden Abbruch (#34814 stand bei 75 Versuchen, #34818 bei 62).
     */
    private static function handle_server_error_retry( $post_id, $result, $status, $attempts ) {
        $error = (string) ( $result['error'] ?? '' );

        update_post_meta( $post_id, '_m24_push_last_error', $error );

        $next_run = self::schedule_retry( $post_id, $attempts );
        if ( 0 === $next_run ) {
            self::mark_failed( $post_id, $status, sprintf(
                'Aufgegeben nach %d Versuchen (letzter Status %d): %s', $attempts, $status, $error
            ) );
            self::log_error( $post_id, 'Push endgueltig aufgegeben — Versuchs-Obergrenze erreicht', [
                'status'       => $status,
                'error'        => $error,
                'attempts'     => $attempts,
                'max_attempts' => self::MAX_ATTEMPTS,
            ] );
            do_action( 'm24_inquiry_mail_fallback', $post_id, sprintf( 'push_aufgegeben_http_%d', $status ) );
            return;
        }

        self::log_warning( $post_id, 'Push 5xx/network — Retry geplant', [
            'status'    => $status,
            'error'     => $error,
            'attempts'  => $attempts,
            'next_run'  => gmdate( 'Y-m-d\TH:i:s\Z', $next_run ),
            'delay_sec' => $next_run - time(),
        ] );
    }

    /**
     * Anfrage war zum Push-Zeitpunkt noch unvollstaendig (keine E-Mail / keine Positionen).
     * KEIN mark_failed — Status bleibt pending, der naechste Versuch findet die Metas vor.
     * Erst wenn die Obergrenze reisst, ist es ein echter Fehler.
     */
    private static function handle_incomplete_retry( $post_id, $error, $attempts ) {
        update_post_meta( $post_id, '_m24_push_last_error', 'unvollstaendig: ' . $error->get_error_message() );

        $next_run = self::schedule_retry( $post_id, $attempts );
        if ( 0 === $next_run ) {
            self::mark_failed( $post_id, 0, sprintf(
                'mapping_failed nach %d Versuchen: %s', $attempts, $error->get_error_message()
            ) );
            self::log_error( $post_id, 'Anfrage blieb unvollstaendig — Versuchs-Obergrenze erreicht', [
                'error'        => $error->get_error_message(),
                'attempts'     => $attempts,
                'max_attempts' => self::MAX_ATTEMPTS,
            ] );
            do_action( 'm24_inquiry_mail_fallback', $post_id, 'mapping_failed' );
            return;
        }

        self::log_warning( $post_id, 'Push verschoben — Anfrage noch unvollstaendig', [
            'error'     => $error->get_error_message(),
            'attempts'  => $attempts,
            'next_run'  => gmdate( 'Y-m-d\TH:i:s\Z', $next_run ),
            'delay_sec' => $next_run - time(),
        ] );
    }

    /**
     * Plant den naechsten Versuch: 60 s, 120 s, 240 s … gedeckelt auf MAX_RETRY_DELAY.
     *
     * @return int Unix-Timestamp des naechsten Versuchs, 0 wenn die Obergrenze erreicht ist.
     */
    private static function schedule_retry( $post_id, $attempts ): int {
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            delete_post_meta( $post_id, '_m24_push_next_retry' );
            return 0;
        }
        $delay    = (int) min( self::MAX_RETRY_DELAY, self::INITIAL_RETRY_DELAY * ( 2 ** max( 0, $attempts - 1 ) ) );
        $next_run = time() + $delay;
        update_post_meta( $post_id, '_m24_push_next_retry', $next_run );
        wp_schedule_single_event( $next_run, self::CRON_HOOK_RETRY, [ $post_id ] );
        do_action( 'm24_inquiry_push_retry_scheduled', $post_id, $next_run );
        return $next_run;
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
    /**
     * Gezielter Nachlauf fuer haengende Anfragen — WP-CLI:
     *
     *     wp m24 inquiry-repush                 (Trockenlauf ueber alle haengenden)
     *     wp m24 inquiry-repush --ids=1254,1255 (Trockenlauf, nur diese)
     *     wp m24 inquiry-repush --ids=1254 --go (senden)
     *
     * Trockenlauf ist die Vorgabe: bei einem Live-System entscheidet man erst, was passieren WUERDE.
     * Der Dublettenschutz ist doppelt — Eintraege mit _m24_desk_order_num werden uebersprungen, und
     * run_push() prueft es selbst noch einmal.
     *
     * @param array $ids Formidable-/CPT-IDs; leer = alle haengenden.
     * @param bool  $go  false = nur anzeigen.
     * @return array{geprueft:int,gesendet:int,uebersprungen:array,zeilen:array}
     */
    public static function repush( array $ids = [], bool $go = false ): array {
        $posts = [];
        if ( ! empty( $ids ) ) {
            foreach ( $ids as $id ) {
                $p = get_post( (int) $id );
                if ( $p && M24_Inquiries_Storage::CPT_SLUG === $p->post_type ) { $posts[] = $p; }
            }
        } else {
            $posts = self::stuck_inquiries( 200 );
        }

        $out = [ 'geprueft' => count( $posts ), 'gesendet' => 0, 'uebersprungen' => [], 'zeilen' => [] ];
        foreach ( $posts as $p ) {
            $num = trim( (string) get_post_meta( $p->ID, '_m24_desk_order_num', true ) );
            if ( '' !== $num ) {
                $out['uebersprungen'][] = sprintf( '#%d — bereits im Desk (%s)', $p->ID, $num );
                continue;
            }
            $out['zeilen'][] = sprintf(
                '#%d · %s · %s',
                $p->ID,
                mysql2date( 'd.m.Y H:i', (string) $p->post_date ),
                (string) $p->post_title
            );
            if ( ! $go ) { continue; }
            self::run_push( (int) $p->ID );
            if ( '' !== trim( (string) get_post_meta( $p->ID, '_m24_desk_order_num', true ) ) ) { $out['gesendet']++; }
        }
        return $out;
    }

    /**
     * Anfragen, die laenger als STUCK_AFTER_MIN warten und KEINE Desk-Auftragsnummer tragen.
     *
     * Bewusst am Zustand gemessen (_m24_desk_order_num), nicht am Log: "scheduled: true" stand zwei
     * Wochen lang bei jeder Anfrage, waehrend nichts ankam.
     *
     * @return array<int,\WP_Post>
     */
    public static function stuck_inquiries( int $limit = 50 ): array {
        $q = new WP_Query( [
            'post_type'      => M24_Inquiries_Storage::CPT_SLUG,
            'post_status'    => 'any',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'date_query'     => [ [ 'before' => gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER_MIN * MINUTE_IN_SECONDS ), 'inclusive' => true ] ],
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                'relation' => 'OR',
                [ 'key' => '_m24_desk_order_num', 'compare' => 'NOT EXISTS' ],
                [ 'key' => '_m24_desk_order_num', 'value' => '', 'compare' => '=' ],
            ],
            'no_found_rows'  => true,
        ] );
        return $q->posts ?: [];
    }

    /** Stuendlicher Lauf: haengende Anfragen ins Fehler-Log, damit es auch ohne Backend-Besuch auffaellt. */
    public static function run_watchdog(): void {
        $stuck = self::stuck_inquiries( 200 );
        if ( empty( $stuck ) ) { return; }
        $ids = array_map( static function ( $p ) { return (int) $p->ID; }, $stuck );
        if ( class_exists( 'M24_Error_Log' ) ) {
            M24_Error_Log::capture( 'inquiries_push', 'error', sprintf(
                '%d Anfrage(n) ohne Desk-Bestaetigung, aelteste seit %s',
                count( $ids ), (string) $stuck[0]->post_date_gmt
            ), [ 'post_ids' => array_slice( $ids, 0, 40 ), 'schwelle_min' => self::STUCK_AFTER_MIN ] );
        }
        error_log( sprintf( 'M24 Plattform: %d Anfrage(n) haengen ohne Desk-Push (aelteste #%d).', count( $ids ), $ids[0] ) );
    }

    /** Backend-Hinweis auf den M24-Seiten — der Weg, auf dem es Daniel tatsaechlich sieht. */
    public static function stuck_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';
        // Nur auf Dashboard und den M24-Seiten — nicht ueberall im Backend nerven.
        if ( 'dashboard' !== $id && false === strpos( $id, 'm24' ) ) { return; }

        $stuck = self::stuck_inquiries( 50 );
        if ( empty( $stuck ) ) { return; }
        $n     = count( $stuck );
        $since = mysql2date( 'd.m.Y H:i', (string) $stuck[0]->post_date );
        printf(
            '<div class="notice notice-error"><p><strong>%d Anfrage%s ohne Desk-Bestaetigung.</strong> '
            . 'Die aelteste wartet seit %s. Diese Eintraege sind in WordPress angekommen, aber nicht im Desk angelegt — '
            . 'pruefe den Cron und das Fehler-Log (Kontext <code>inquiries_push</code>).</p></div>',
            (int) $n, 1 === $n ? '' : 'n', esc_html( $since )
        );
    }

    public static function build_idempotency_key( $post ) {
        $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $host = preg_replace( '/[^a-zA-Z0-9]+/', '_', $host );
        $host = trim( $host, '_' );
        if ( $host === '' ) { $host = 'unknown'; }

        // Bewusst OHNE post_modified: der Key muss ueber die Lebensdauer des Vorgangs konstant bleiben,
        // sonst ist er als Idempotenz-Schutz wertlos. Wird der Eintrag zwischen zwei Push-Versuchen auch
        // nur angefasst (Anrede nachgetragen, Status beruehrt, Meta geschrieben), aendert sich
        // post_modified — und der Desk sieht einen fremden Key, also einen NEUEN Auftrag. Genau dieser
        // Mechanismus hat beim Abarbeiten des Rueckstaus Dubletten erzeugt: 2026-1047 ohne Anrede,
        // 2026-1048 mit "Herr", derselbe Formidable-Eintrag #1265.
        //
        // Die CPT-ID identifiziert den Vorgang eindeutig und aendert sich nie. Genau das braucht ein
        // Idempotency-Key — er soll wiederholte Zustellung DESSELBEN Vorgangs erkennen, nicht dessen
        // Inhalt versionieren.
        $key = sprintf( 'm24_wp_%s_%d', $host, (int) $post->ID );
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

/**
 * WP-CLI: gezielter Nachlauf haengender Anfragen. Trockenlauf ist die Vorgabe — auf einem Live-System
 * schaut man erst, was passieren wuerde, und sendet dann.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'm24 inquiry-repush', function ( $args, $assoc ) {
        $ids = [];
        if ( ! empty( $assoc['ids'] ) ) {
            $ids = array_filter( array_map( 'intval', preg_split( '/[^0-9]+/', (string) $assoc['ids'] ) ) );
        }
        $go  = isset( $assoc['go'] );
        $r   = M24_Inquiries_Push::repush( $ids, $go );

        WP_CLI::log( sprintf( '%s — %d Eintrag/Eintraege geprueft.', $go ? 'SENDEN' : 'TROCKENLAUF', (int) $r['geprueft'] ) );
        foreach ( $r['uebersprungen'] as $line ) { WP_CLI::log( '  uebersprungen: ' . $line ); }
        if ( empty( $r['zeilen'] ) ) {
            WP_CLI::success( 'Nichts zu senden — alle geprueften Eintraege tragen bereits eine Desk-Nummer.' );
            return;
        }
        WP_CLI::log( $go ? 'Gesendet:' : 'Wuerde senden:' );
        foreach ( $r['zeilen'] as $line ) { WP_CLI::log( '  ' . $line ); }
        if ( $go ) {
            WP_CLI::success( sprintf( '%d von %d erfolgreich im Desk angelegt.', (int) $r['gesendet'], count( $r['zeilen'] ) ) );
        } else {
            WP_CLI::warning( 'Trockenlauf — nichts gesendet. Mit --go ausfuehren.' );
        }
    } );
}
