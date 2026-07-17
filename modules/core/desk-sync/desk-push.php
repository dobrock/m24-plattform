<?php
/**
 * M24 Plattform — Modul core/desk-sync: Baustein W1 (Angebot erstellt & gesendet → Push als Auftrag nach M24 Desk).
 *
 * Verantwortung dieser Datei: den Outbound-Push eines versendeten Angebots an POST {api_url}/api/orders nach
 * Schnittstellen-Vertrag v1.1 §3 bauen und ausführen — inkl. Idempotenz (X-Idempotency-Key: wp-offer-<id>),
 * Response-Handling (201/409/≥500/Timeout/400-422), Fallback-Mail (Pfad A) und Retry-Job (WP-Cron, 4h, max 6).
 *
 * NUR W1. Der Inbound-Rückkanal (bezahlt/Statuswechsel) und W2/W3 folgen separat.
 *
 * Konfiguration wird aus der BESTEHENDEN Desk-Verbindung gelesen (M24_Rest_Client / M24_Settings:
 * api_url, api_key = X-API-Key, fallback_mail_to) — eine Quelle der Wahrheit, kein zweiter Credential-Satz.
 * Echter Versand ist per Schalter m24_desk_sync_enabled gated (Default AUS): solange aus, läuft beim Senden
 * NUR ein dry_run (keine Nebenwirkung) → erst nach grünem Dry-Run scharfschalten.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Desk_Push {

    const CRON      = 'm24_desk_sync_retry';
    const FLAG      = 'm24_desk_sync_enabled'; // Default AUS → nur dry_run beim Senden
    const MAX_TRIES = 6;
    const TIMEOUT   = 10;

    public static function init() {
        // Trigger W1: beim Angebotsversand (ersetzt den alten no-op-Stub M24_Offers::push_to_desk).
        add_action( 'm24_offer_sent', array( __CLASS__, 'on_offer_sent' ), 10, 1 );

        // Retry-Job: WP-Cron alle 4h (Action Scheduler ist im Projekt nicht eingebunden → WP-Cron als Ersatz).
        add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
        add_action( self::CRON, array( __CLASS__, 'run_retry' ) );
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + 900, 'm24_4h', self::CRON );
        }

        // Manueller Retry / Dry-Run aus dem Admin-Monitor (admin-post, PRG, Nonce).
        add_action( 'admin_post_m24_desk_retry',   array( __CLASS__, 'handle_admin_retry' ) );
        add_action( 'admin_post_m24_desk_dry_run', array( __CLASS__, 'handle_admin_dry_run' ) );
    }

    public static function add_schedule( $s ) {
        if ( ! isset( $s['m24_4h'] ) ) { $s['m24_4h'] = array( 'interval' => 4 * HOUR_IN_SECONDS, 'display' => 'Alle 4 Stunden (M24 Desk-Sync)' ); }
        return $s;
    }

    public static function enabled(): bool {
        return (bool) get_option( self::FLAG, 0 );
    }

    /* ── Trigger ──────────────────────────────────────────────────────────── */

    /**
     * Angebot wurde versendet. Scharf (Flag an + Desk konfiguriert) → echter Push; sonst dry_run (keine
     * Nebenwirkung, nur Validierung/Log). Ohne Desk-Konfiguration wird sanft übersprungen.
     */
    public static function on_offer_sent( $offer_id ) {
        $offer_id = (int) $offer_id;
        if ( $offer_id <= 0 ) { return; }
        if ( ! class_exists( 'M24_Rest_Client' ) || ! M24_Rest_Client::is_configured() ) {
            self::log( $offer_id, 'skipped', 'Desk nicht konfiguriert (URL/Key fehlt).' );
            return;
        }
        if ( self::enabled() ) {
            self::push( $offer_id, false );
        } else {
            // Sicherheits-Default: kein echter Insert, bis der Dry-Run grün ist.
            $res = self::push( $offer_id, true );
            self::mark( $offer_id, 'pending', 0, 'Dry-Run beim Senden (Scharfschalten via ' . self::FLAG . '). ' . (string) ( $res['note'] ?? '' ) );
        }
    }

    /* ── Push-Client ──────────────────────────────────────────────────────── */

    /**
     * Baut den Body und führt den Request aus. $dry_run=true hängt "dry_run":true an (identischer Body sonst).
     * @return array{ok:bool,status:int,note:string}
     */
    public static function push( int $offer_id, bool $dry_run = false ): array {
        $o = M24_Offers::get_by_id( $offer_id );
        if ( ! $o ) { return array( 'ok' => false, 'status' => 0, 'note' => 'Angebot nicht gefunden.' ); }

        $payload = self::build_payload( $o, $dry_run );
        $opts    = array(
            'timeout' => self::TIMEOUT,
            'headers' => array( 'X-Idempotency-Key' => 'wp-offer-' . $offer_id ),
        );
        // attempts nur bei echtem Versand hochzählen (Dry-Run ist nebenwirkungsfrei).
        if ( ! $dry_run ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . M24_Offers::table() . ' SET desk_sync_attempts = desk_sync_attempts + 1 WHERE id = %d', $offer_id ) );
        }

        $res    = M24_Rest_Client::request( 'POST', '/api/orders', $payload, $opts );
        $status = (int) ( $res['status'] ?? 0 );
        $data   = is_array( $res['data'] ?? null ) ? $res['data'] : array();

        // Dry-Run: keine Persistenz, nur Diagnose zurückgeben.
        if ( $dry_run ) {
            $ok   = ( 200 === $status || 201 === $status ) && ( ! empty( $data['dry_run'] ) || 'ok' === ( $data['validation'] ?? '' ) );
            $note = 'Dry-Run → HTTP ' . $status
                . ( isset( $data['validation'] ) ? ' · validation=' . (string) $data['validation'] : '' )
                . ( isset( $data['would_create_customer'] ) ? ' · would_create_customer=' . ( $data['would_create_customer'] ? 'true' : 'false' ) : '' )
                . ( isset( $data['customer_id'] ) ? ' · customer_id=' . (string) $data['customer_id'] : '' );
            self::log( $offer_id, $ok ? 'dry_ok' : 'dry_fail', $note );
            return array( 'ok' => $ok, 'status' => $status, 'note' => $note );
        }

        // 201 → Erfolg: Desk-IDs speichern.
        if ( 201 === $status || 200 === $status ) {
            $desk_id  = (string) ( $data['order_id'] ?? $data['id'] ?? $data['desk_order_id'] ?? '' );
            $desk_num = (string) ( $data['order_num'] ?? $data['order_number'] ?? $data['desk_order_num'] ?? '' );
            self::mark( $offer_id, 'synced', 0, '', $desk_id, $desk_num );
            self::log( $offer_id, 'synced', 'HTTP ' . $status . ' · order_id=' . $desk_id . ' · order_num=' . $desk_num );
            return array( 'ok' => true, 'status' => $status, 'note' => 'synced' );
        }

        // 409 idempotency_key_reused → bereits gepusht = Erfolg.
        if ( 409 === $status ) {
            $code = (string) ( $data['error'] ?? $data['code'] ?? '' );
            $desk_id  = (string) ( $data['order_id'] ?? $data['desk_order_id'] ?? '' );
            $desk_num = (string) ( $data['order_num'] ?? $data['desk_order_num'] ?? '' );
            self::mark( $offer_id, 'synced', 0, '', $desk_id, $desk_num );
            self::log( $offer_id, 'synced', 'HTTP 409 (' . ( '' !== $code ? $code : 'idempotency_key_reused' ) . ') → bereits gepusht.' );
            return array( 'ok' => true, 'status' => 409, 'note' => 'already_pushed' );
        }

        // 400/422 → Validierungsfehler: KEIN blinder Retry, Monitor-Flag, Details loggen.
        if ( 400 === $status || 422 === $status ) {
            $detail = self::error_detail( $data, $res );
            self::mark( $offer_id, 'failed', self::MAX_TRIES, 'Validierung (' . $status . '): ' . $detail ); // attempts=MAX → aus Retry-Queue
            self::log( $offer_id, 'validation_failed', 'HTTP ' . $status . ' · ' . $detail );
            if ( class_exists( 'M24_Error_Log' ) ) {
                M24_Error_Log::capture( 'desk_sync', 'error', 'Desk /api/orders Validierungsfehler (kein Retry)', array( 'offer_no' => (string) $o->offer_no, 'status' => $status, 'detail' => $detail ) );
            }
            return array( 'ok' => false, 'status' => $status, 'note' => 'validation_failed' );
        }

        // ≥500 / Timeout / Netzwerk (status 0) → Pfad A: Fallback-Mail + failed → Retry-Queue.
        $detail = self::error_detail( $data, $res );
        self::mark( $offer_id, 'failed', null, 'Server/Timeout (' . $status . '): ' . $detail );
        self::send_fallback_mail( $o, $status, $detail );
        self::log( $offer_id, 'failed', 'HTTP ' . $status . ' · ' . $detail . ' → Fallback-Mail + Retry-Queue.' );
        return array( 'ok' => false, 'status' => $status, 'note' => 'retry_queued' );
    }

    /**
     * v1.1 §3 Body. Preise: number_format(x,2,',','.'); gesamt mit "€ "-Präfix. Kunde wird Desk-seitig via
     * findOrCreateCustomer angelegt (kein separater Kunden-Call).
     */
    public static function build_payload( $o, bool $dry_run = false ): array {
        $cust   = json_decode( (string) $o->customer_json, true ) ?: array();
        $items  = json_decode( (string) $o->items_json, true ) ?: array();
        $extras = json_decode( (string) $o->extras_json, true ) ?: array();
        $src    = json_decode( (string) $o->src_json, true ) ?: array();

        $lang    = ( 'en' === strtolower( (string) ( $src['src_lang'] ?? $o->tax_mode ) ) || 'en' === self::offer_lang( $o ) ) ? 'en' : 'de';
        $biz     = ( 'b2b' === ( $cust['kundentyp'] ?? 'b2c' ) );
        $land    = trim( (string) ( $cust['land'] ?? '' ) );
        $view    = M24_Offers::view_url( (string) $o->token );

        // customer.name = Anrede + Vor-/Nachname (sonst Fallback name/E-Mail-Localpart).
        $anrede = trim( (string) ( $cust['anrede'] ?? '' ) );
        $vor    = trim( (string) ( $cust['vorname'] ?? '' ) );
        $nach   = trim( (string) ( $cust['nachname'] ?? '' ) );
        $name   = trim( $anrede . ' ' . trim( $vor . ' ' . $nach ) );
        if ( '' === trim( $vor . $nach ) ) { $name = trim( (string) ( $cust['name'] ?? '' ) ); }

        $mapped = array();
        foreach ( $items as $it ) {
            $qty   = max( 1, (int) ( $it['qty'] ?? 1 ) );
            $unit  = (float) ( $it['unit_price'] ?? 0 );
            $line  = $unit * $qty;
            $url   = trim( (string) ( $it['url'] ?? '' ) );
            if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) { $url = $view; } // Fallback: valide Angebots-URL
            $mapped[] = array(
                // Pflicht für den Desk-Validator:
                'src_url'    => $url,
                'src_pillar' => self::item_pillar( $it ),
                'src_lang'   => $lang,
                'src_modell' => (string) ( $src['src_modell'] ?? '' ),
                'src_pid'    => (string) ( (int) ( $it['teil_id'] ?? 0 ) ?: (string) ( $src['src_pid'] ?? '' ) ),
                // Anzeigefelder für Desk-UI/PDF:
                'art'        => (string) ( $it['title'] ?? '' ),
                'qty'        => (string) $qty,
                'price'      => number_format( $unit, 2, ',', '.' ),
                'gesamt'     => '€ ' . number_format( $line, 2, ',', '.' ),
                'delivery'   => (string) $o->delivery_time,
                'note'       => (string) ( $it['art_nr'] ?? '' ),
                'is25a'      => ( ! empty( $it['tax25a'] ) || ! empty( $it['st25a'] ) ),
                'customs'    => false,
                'coo'        => false,
                'hs_code'    => (string) ( $it['hs_code'] ?? '' ),
                'weight_kg'  => (string) ( $it['weight_kg'] ?? '' ),
            );
        }
        foreach ( $extras as $ex ) {
            if ( empty( $ex['on'] ) ) { continue; }
            $amt = (float) ( $ex['amount'] ?? 0 );
            $mapped[] = array(
                'src_url' => $view, 'src_pillar' => 'katalog', 'src_lang' => $lang, 'src_modell' => '', 'src_pid' => '',
                'art' => (string) ( $ex['label'] ?? '' ), 'qty' => '1',
                'price' => number_format( $amt, 2, ',', '.' ), 'gesamt' => '€ ' . number_format( $amt, 2, ',', '.' ),
                'delivery' => '', 'note' => '', 'is25a' => false, 'customs' => false, 'coo' => false, 'hs_code' => '', 'weight_kg' => '',
            );
        }

        $body = array(
            'source'              => 'wordpress_plugin',
            'inquiry_source'      => 'offer',
            'inquiry_source_meta' => array(
                'wp_offer_no' => (string) $o->offer_no,
                'wp_offer_id' => (int) $o->id,
                'wp_user_id'  => (int) $o->account_id,
                'accepted_at' => null,
            ),
            'sender_lang' => $lang,
            'vat_mode'    => self::vat_mode( (string) $o->tax_mode, $biz, $land ),
            'subj'        => 'Angebot ' . (string) $o->offer_no,
            'notes'       => mb_substr( (string) ( $src['note'] ?? '' ), 0, 2000 ),
            'phone'       => mb_substr( (string) ( $cust['telefon'] ?? '' ), 0, 40 ),
            'customer'    => array(
                'email'    => (string) ( $cust['email'] ?? '' ),
                'firma'    => (string) ( $cust['firma'] ?? $cust['firmenname'] ?? '' ),
                'name'     => $name,
                'strasse'  => (string) ( $cust['strasse'] ?? '' ),
                'strasse2' => (string) ( $cust['adresszusatz'] ?? '' ),
                'plz'      => (string) ( $cust['plz'] ?? '' ),
                'ort'      => (string) ( $cust['ort'] ?? '' ),
                'land'     => '' !== $land ? $land : 'Deutschland', // DE-Klartext
                'uid'      => (string) ( $cust['ustid'] ?? '' ),
                'eori'     => mb_substr( (string) ( $cust['eori'] ?? '' ), 0, 17 ),
                'biz'      => $biz,
            ),
            'items' => $mapped,
        );
        if ( $dry_run ) { $body['dry_run'] = true; }
        return $body;
    }

    /**
     * vat_mode explizit aus dem Angebots-Steuermodus (+ Kundentyp/Land) → b2b_de|b2c_de|b2c_eu|b2b_eu|b2c_export.
     */
    public static function vat_mode( string $tax_mode, bool $biz, string $land ): string {
        switch ( $tax_mode ) {
            case 'b2b_eu_net':    return 'b2b_eu';                       // Reverse Charge, EU-Unternehmen
            case 'b2c_eu_oss':    return 'b2c_eu';                       // OSS, EU-Privat
            case 'drittland_net': return 'b2c_export';                   // Ausfuhr Drittland
            case 'b2b_de_19':     return $biz ? 'b2b_de' : 'b2c_de';     // 19 % DE, je Kundentyp
        }
        return $biz ? 'b2b_de' : 'b2c_de'; // Fallback
    }

    /** src_pillar aus der Positionsquelle: Gebrauchtteil→gebrauchtteile, Fahrzeug→fahrzeug, sonst katalog. */
    public static function item_pillar( array $it ): string {
        if ( ! empty( $it['used'] ) ) { return 'gebrauchtteile'; }
        $pid = (int) ( $it['teil_id'] ?? 0 );
        if ( $pid > 0 && 'm24_fahrzeug' === get_post_type( $pid ) ) { return 'fahrzeug'; }
        return 'katalog';
    }

    private static function offer_lang( $o ): string {
        $sj = json_decode( (string) $o->src_json, true );
        return ( is_array( $sj ) && 'en' === ( $sj['lang'] ?? $sj['src_lang'] ?? '' ) ) ? 'en' : 'de';
    }

    /* ── Persistenz / Fallback / Retry ────────────────────────────────────── */

    /**
     * Sync-Status persistieren. $attempts=null lässt den Zähler unangetastet; sonst wird er gesetzt.
     * field_updated_at wird als JSON-Snapshot fortgeschrieben (W2/W3-Konfliktbasis).
     */
    private static function mark( int $offer_id, string $status, ?int $attempts = null, string $error = '', string $desk_id = '', string $desk_num = '' ): void {
        global $wpdb;
        $now  = current_time( 'mysql', true );
        $data = array( 'desk_sync_status' => $status, 'desk_sync_error' => ( '' !== $error ? $error : null ) );
        if ( 'synced' === $status ) {
            $data['desk_synced_at'] = $now;
            if ( '' !== $desk_id )  { $data['desk_order_id']  = $desk_id; }
            if ( '' !== $desk_num ) { $data['desk_order_num'] = $desk_num; }
        }
        if ( null !== $attempts ) { $data['desk_sync_attempts'] = $attempts; }
        $data['field_updated_at'] = wp_json_encode( array( 'desk_sync_status' => $now ) );
        $wpdb->update( M24_Offers::table(), $data, array( 'id' => $offer_id ) );
    }

    /** Pfad A: formatierte Fallback-Mail an m24_desk_fallback_mail (Reuse der bestehenden Settings-Adresse). */
    private static function send_fallback_mail( $o, int $status, string $detail ): void {
        $settings = get_option( 'm24_plattform_settings', array() );
        $to       = '';
        if ( is_array( $settings ) && ! empty( $settings['fallback_mail_to'] ) ) { $to = (string) $settings['fallback_mail_to']; }
        if ( '' === $to ) { $to = 'service@motorsport24.de'; }

        $cust = json_decode( (string) $o->customer_json, true ) ?: array();
        $lines = array(
            'Der automatische Push des Angebots an M24 Desk ist fehlgeschlagen (Server/Timeout).',
            '',
            'Angebot:   ' . (string) $o->offer_no . ' (ID ' . (int) $o->id . ')',
            'Kunde:     ' . (string) ( $cust['name'] ?? '' ) . ' <' . (string) ( $cust['email'] ?? '' ) . '>',
            'Betrag:    ' . number_format( (float) $o->total_gross, 2, ',', '.' ) . ' €',
            'HTTP:      ' . $status,
            'Detail:    ' . $detail,
            '',
            'Der Auftrag steht in der Retry-Queue (alle 4 h, bis ' . self::MAX_TRIES . ' Versuche) und wird',
            'idempotent (X-Idempotency-Key: wp-offer-' . (int) $o->id . ') erneut versucht.',
            'Manueller Retry: Menü „Desk-Sync".',
        );
        $body = function_exists( 'm24_mail_shell' )
            ? m24_mail_shell( 'Desk-Push fehlgeschlagen — Angebot ' . (string) $o->offer_no, '<pre style="font:13px/1.6 monospace;white-space:pre-wrap;">' . esc_html( implode( "\n", $lines ) ) . '</pre>', array( 'lang' => 'de', 'footer_legal_slim' => true ) )
            : nl2br( esc_html( implode( "\n", $lines ) ) );
        wp_mail( $to, 'Desk-Push fehlgeschlagen — Angebot ' . (string) $o->offer_no, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    /** Retry-Job: pending|failed mit attempts < MAX erneut pushen (gleicher Idempotency-Key → dedupe-sicher). */
    public static function run_retry() {
        if ( ! self::enabled() || ! class_exists( 'M24_Rest_Client' ) || ! M24_Rest_Client::is_configured() ) { return; }
        global $wpdb;
        $t    = M24_Offers::table();
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $t WHERE desk_sync_status IN ('pending','failed') AND desk_sync_attempts < %d ORDER BY id ASC LIMIT 25", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::MAX_TRIES
        ) );
        foreach ( (array) $rows as $id ) { self::push( (int) $id, false ); }
    }

    /* ── Admin-Aktionen (Monitor) ─────────────────────────────────────────── */

    public static function handle_admin_retry() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Keine Berechtigung.' ); }
        $id = (int) ( $_GET['offer'] ?? 0 );
        check_admin_referer( 'm24_desk_retry_' . $id );
        if ( $id > 0 ) {
            // Manueller Retry setzt den Zähler zurück, damit ein „failed (validation)" bewusst erneut laufen kann.
            global $wpdb;
            $wpdb->update( M24_Offers::table(), array( 'desk_sync_attempts' => 0 ), array( 'id' => $id ) );
            self::push( $id, false );
        }
        wp_safe_redirect( add_query_arg( 'done', 'retry', admin_url( 'admin.php?page=m24-desk-sync' ) ) );
        exit;
    }

    public static function handle_admin_dry_run() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Keine Berechtigung.' ); }
        $id = (int) ( $_GET['offer'] ?? 0 );
        check_admin_referer( 'm24_desk_dry_' . $id );
        $note = '';
        if ( $id > 0 ) { $r = self::push( $id, true ); $note = (string) ( $r['note'] ?? '' ); }
        set_transient( 'm24_desk_dry_note_' . get_current_user_id(), $note, 60 );
        wp_safe_redirect( add_query_arg( 'done', 'dry', admin_url( 'admin.php?page=m24-desk-sync' ) ) );
        exit;
    }

    /* ── Helfer ───────────────────────────────────────────────────────────── */

    private static function error_detail( array $data, array $res ): string {
        if ( ! empty( $data['message'] ) ) { return (string) $data['message']; }
        if ( ! empty( $data['error'] ) )   { return is_scalar( $data['error'] ) ? (string) $data['error'] : wp_json_encode( $data['error'] ); }
        if ( ! empty( $data['errors'] ) )  { return wp_json_encode( $data['errors'] ); }
        if ( ! empty( $res['error'] ) )    { return (string) $res['error']; }
        if ( ! empty( $res['raw'] ) )      { return mb_substr( (string) $res['raw'], 0, 300 ); }
        return 'keine Details';
    }

    private static function log( int $offer_id, string $step, string $msg ): void {
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'desk_sync', $step, array( 'offer_id' => $offer_id, 'msg' => $msg ) );
        }
    }
}
