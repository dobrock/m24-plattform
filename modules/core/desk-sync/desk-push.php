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
    const INQUIRY_SOURCE_DEFAULT = 'offer'; // Ziel-Enum; bis Desk-Block-1 'offer' live hat, per Konstante auf 'cart'

    /**
     * inquiry_source-Wert. Der Desk-Enum lässt HEUTE nur cart|contact_form|product_inquiry|blog_inquiry zu —
     * 'offer' würde 400 werfen (auch im dry_run), bis Desk-Block-1 die Enum-Erweiterung deployt hat. Deshalb
     * per Konstante M24_DESK_INQUIRY_SOURCE (wp-config) übersteuerbar: für den ersten Test 'cart' setzen,
     * danach entfernen → Default 'offer'. Zusätzlich per Filter m24_desk_inquiry_source überschreibbar.
     */
    public static function inquiry_source(): string {
        $v = ( defined( 'M24_DESK_INQUIRY_SOURCE' ) && '' !== (string) M24_DESK_INQUIRY_SOURCE )
            ? (string) M24_DESK_INQUIRY_SOURCE
            : self::INQUIRY_SOURCE_DEFAULT;
        return (string) apply_filters( 'm24_desk_inquiry_source', $v );
    }

    const CUST_META    = '_m24_desk_customer_id';   // Desk-Customer-ID am WP-Konto
    const CUST_SNAP     = '_m24_desk_cust_snapshot'; // zuletzt gepushter Kundendaten-Snapshot (Diff-Basis)
    const CUST_DIRTY    = '_m24_desk_cust_dirty';     // '1' = Kunden-PUT hängt in der Retry-Queue
    const CUST_ATTEMPTS = '_m24_desk_cust_attempts';  // Versuchszähler (Kunde)
    const CUST_PENDING  = '_m24_desk_cust_pending';   // {sig,key} des noch unbestätigten Übergangs
    const CUST_SEQ      = '_m24_desk_cust_seq';       // monoton steigender Zähler je Edit-Ereignis (Key-Quelle)

    public static function init() {
        // Trigger W1: beim Angebotsversand (ersetzt den alten no-op-Stub M24_Offers::push_to_desk).
        add_action( 'm24_offer_sent', array( __CLASS__, 'on_offer_sent' ), 10, 1 );
        // Trigger W2: bei Angebotsannahme → Kunde (Rechnungsadresse) + Auftrag (Lieferadresse + confirmed).
        add_action( 'm24_offer_accepted', array( __CLASS__, 'on_offer_accepted' ), 10, 1 );
        // Trigger W3: bei Konto-/Adressänderung (unabhängig von einer Annahme).
        add_action( 'm24_customer_updated', array( __CLASS__, 'on_customer_updated' ), 10, 1 );

        // Einmaliger Backfill des bestehenden Testfalls: Angebot 2026-1023 → Desk-Customer 108.
        if ( ! get_option( 'm24_desk_backfill_1023' ) ) {
            update_option( 'm24_desk_backfill_1023', 1, false );
            self::backfill_customer_id( '2026-1023', 108 );
        }

        // Retry-Job: WP-Cron alle 4h (Action Scheduler ist im Projekt nicht eingebunden → WP-Cron als Ersatz).
        add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
        add_action( self::CRON, array( __CLASS__, 'run_retry' ) );
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + 900, 'm24_4h', self::CRON );
        }

        // Manueller Retry / Dry-Run aus dem Admin-Monitor (admin-post, PRG, Nonce).
        add_action( 'admin_post_m24_desk_retry',    array( __CLASS__, 'handle_admin_retry' ) );
        add_action( 'admin_post_m24_desk_dry_run',  array( __CLASS__, 'handle_admin_dry_run' ) );
        add_action( 'admin_post_m24_desk_cust_retry', array( __CLASS__, 'handle_admin_cust_retry' ) );
        add_action( 'admin_post_m24_desk_cust_dry',   array( __CLASS__, 'handle_admin_cust_dry' ) );
    }

    /** Testfall-Backfill: Desk-Customer-ID an das Konto des Angebots {offer_no} hängen (idempotent). */
    private static function backfill_customer_id( string $offer_no, int $desk_cid ): void {
        if ( ! class_exists( 'M24_Offers' ) || $desk_cid <= 0 ) { return; }
        global $wpdb;
        $uid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT account_id FROM ' . M24_Offers::table() . ' WHERE offer_no = %s LIMIT 1', $offer_no ) );
        if ( $uid > 0 && '' === (string) get_user_meta( $uid, self::CUST_META, true ) ) {
            update_user_meta( $uid, self::CUST_META, $desk_cid );
            if ( class_exists( 'M24_Logger' ) ) { M24_Logger::info( 'desk_sync', 'backfill_customer_id', array( 'offer_no' => $offer_no, 'uid' => $uid, 'desk_customer_id' => $desk_cid ) ); }
        }
    }

    public static function add_schedule( $s ) {
        if ( ! isset( $s['m24_4h'] ) ) { $s['m24_4h'] = array( 'interval' => 4 * HOUR_IN_SECONDS, 'display' => 'Alle 4 Stunden (M24 Desk-Sync)' ); }
        return $s;
    }

    public static function enabled(): bool {
        return (bool) get_option( self::FLAG, 0 );
    }

    /**
     * Echo-Schutz: läuft gerade ein Desk→WP-Apply (M24_Desk_Inbound), darf KEIN Trigger nach Desk zurückpushen —
     * sonst schickt WP die eben eingespielte Desk-Änderung sofort wieder an Desk. Alle on_*-Trigger fragen das ab.
     */
    private static function applying_inbound(): bool {
        return class_exists( 'M24_Desk_Inbound' ) && M24_Desk_Inbound::$applying;
    }

    /* ── Trigger ──────────────────────────────────────────────────────────── */

    /**
     * Angebot wurde versendet. Scharf (Flag an + Desk konfiguriert) → echter Push; sonst dry_run (keine
     * Nebenwirkung, nur Validierung/Log). Ohne Desk-Konfiguration wird sanft übersprungen.
     */
    public static function on_offer_sent( $offer_id ) {
        $offer_id = (int) $offer_id;
        if ( $offer_id <= 0 || self::applying_inbound() ) { return; }
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

        // Create-only-Guard: ist bereits eine Desk-Order-ID hinterlegt, wurde der Auftrag schon angelegt.
        // Ein erneuter echter POST würde bei geändertem Inhalt eine Dublette erzeugen → stattdessen für W2/PUT
        // vormerken (needs_update) und NICHT nochmal POSTen. Dry-Run bleibt erlaubt (nebenwirkungsfrei).
        if ( ! $dry_run && '' !== trim( (string) $o->desk_order_id ) ) {
            self::mark_needs_update( $offer_id );
            self::log( $offer_id, 'needs_update', 'desk_order_id bereits gesetzt (#' . (string) $o->desk_order_id . ') → kein Re-POST; für W2/PUT vorgemerkt.' );
            return array( 'ok' => true, 'status' => 0, 'note' => 'needs_update' );
        }

        $payload = self::build_payload( $o, $dry_run );
        // X-Idempotency-Key NUR beim echten Push. Der Desk persistiert die Idempotenz für jede Antwort <500 —
        // auch für den Dry-Run (200). Teilte der Dry-Run den Key wp-offer-<id>, käme ein späterer echter Push
        // (Body unterscheidet sich schon durch dry_run:false → anderer Hash) als 409 zurück und würde fälschlich
        // als „synced" gewertet, obwohl NIE etwas angelegt wurde. Dry-Run legt ohnehin nichts an → kein Key nötig.
        $opts = array( 'timeout' => self::TIMEOUT );
        if ( ! $dry_run ) {
            $opts['headers'] = array( 'X-Idempotency-Key' => 'wp-offer-' . $offer_id );
        }
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
            self::persist_customer_id( (int) $o->account_id, $data ); // W1-Response trägt die Desk-Customer-ID
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
                // Numerische Order-Felder (DB NUMERIC(10,2)) — Punkt-Dezimal, KEIN DE-String.
                'amt'        => round( $unit, 2 ), // VK je Einheit (numerisch)
                'einkauf'    => 0.0,               // kein EK in Angeboten bekannt
                // Anzeigefelder für Desk-UI/PDF (DE-Format als String):
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
                'amt' => round( $amt, 2 ), 'einkauf' => 0.0,
                'art' => (string) ( $ex['label'] ?? '' ), 'qty' => '1',
                'price' => number_format( $amt, 2, ',', '.' ), 'gesamt' => '€ ' . number_format( $amt, 2, ',', '.' ),
                'delivery' => '', 'note' => '', 'is25a' => false, 'customs' => false, 'coo' => false, 'hs_code' => '', 'weight_kg' => '',
            );
        }

        $body = array(
            'source'              => 'wordpress_plugin',
            'inquiry_source'      => self::inquiry_source(),
            'inquiry_source_meta' => array(
                'wp_offer_no' => (string) $o->offer_no,
                'wp_offer_id' => (int) $o->id,
                'wp_user_id'  => (int) $o->account_id,
                'accepted_at' => null,
            ),
            'sender_lang' => $lang,
            'vat_mode'    => self::vat_mode( (string) $o->tax_mode, $biz, $land ),
            // Auftragssumme (Brutto) als Zahl (Punkt-Dezimal) — sonst bleibt orders.amt im Desk leer.
            'amt'         => round( (float) $o->total_gross, 2 ),
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
        $data['field_updated_at'] = wp_json_encode( self::merge_stamps( $offer_id, array( 'desk_sync_status' => $now ) ) );
        $wpdb->update( M24_Offers::table(), $data, array( 'id' => $offer_id ) );
    }

    /**
     * field_updated_at fortschreiben statt überschreiben. Die Map ist die LWW-Autorität für BEIDE Richtungen —
     * der Inbound (M24_Desk_Inbound) legt hier die Stempel der übernommenen Desk-Felder ab. Ein blindes
     * Überschreiben mit nur desk_sync_status würde sie bei jedem Status-Write verlieren und LWW entwerten.
     */
    private static function merge_stamps( int $offer_id, array $add ): array {
        global $wpdb;
        $cur = json_decode(
            (string) $wpdb->get_var( $wpdb->prepare( 'SELECT field_updated_at FROM ' . M24_Offers::table() . ' WHERE id = %d', $offer_id ) ),
            true
        );
        return array_merge( is_array( $cur ) ? $cur : array(), $add );
    }

    /**
     * Bereits im Desk angelegtes Angebot, das erneut versendet wurde → für W2/PUT vormerken. Ändert NUR den
     * Status (desk_order_id/desk_synced_at/attempts bleiben unangetastet); die Retry-Queue ignoriert
     * needs_update (nur pending|failed werden erneut gepusht) → kein Re-POST/keine Dublette.
     */
    private static function mark_needs_update( int $offer_id ): void {
        global $wpdb;
        $wpdb->update(
            M24_Offers::table(),
            array( 'desk_sync_status' => 'needs_update', 'field_updated_at' => wp_json_encode( self::merge_stamps( $offer_id, array( 'desk_sync_status' => current_time( 'mysql', true ) ) ) ) ),
            array( 'id' => $offer_id )
        );
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

    /**
     * Retry-Job (alle 4h). Drei Operationen, alle mit deterministischem Idempotency-Key → dedupe-sicher:
     *   W1 create  — pending|failed OHNE desk_order_id → POST /api/orders.
     *   W2 confirm — confirm_failed → PUT /api/orders/<id> (Auftrag-Update).
     *   Kunde      — Konten mit Dirty-Flag → PUT /api/customers/<id> (Kunde-Update, W2a/W3).
     * needs_update wird NICHT wiederholt (W2/PUT-Handoff, kein Re-POST).
     */
    public static function run_retry() {
        if ( ! self::enabled() || ! class_exists( 'M24_Rest_Client' ) || ! M24_Rest_Client::is_configured() ) { return; }
        global $wpdb;
        $t = M24_Offers::table();

        // W1 create (nur ohne bereits vergebene Desk-Order-ID → sonst greift der Create-only-Guard ohnehin).
        foreach ( (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $t WHERE desk_sync_status IN ('pending','failed') AND ( desk_order_id IS NULL OR desk_order_id = '' ) AND desk_sync_attempts < %d ORDER BY id ASC LIMIT 25", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::MAX_TRIES
        ) ) as $id ) { self::push( (int) $id, false ); }

        // W2 confirm (Auftrag-Update).
        foreach ( (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $t WHERE desk_sync_status = 'confirm_failed' AND desk_sync_attempts < %d ORDER BY id ASC LIMIT 25", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            self::MAX_TRIES
        ) ) as $id ) { self::push_order_confirm( (int) $id, false ); }

        // Kunde (W2a/W3) — Konten mit Dirty-Flag.
        $uq = new WP_User_Query( array(
            'meta_query' => array(
                array( 'key' => self::CUST_DIRTY, 'value' => '1' ),
            ),
            'fields' => 'ID', 'number' => 25,
        ) );
        foreach ( (array) $uq->get_results() as $uid ) {
            if ( (int) get_user_meta( (int) $uid, self::CUST_ATTEMPTS, true ) < self::MAX_TRIES ) {
                self::push_customer( (int) $uid, false );
            }
        }
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

    public static function handle_admin_cust_retry() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Keine Berechtigung.' ); }
        $uid = (int) ( $_GET['user'] ?? 0 );
        check_admin_referer( 'm24_desk_cust_retry_' . $uid );
        if ( $uid > 0 ) {
            update_user_meta( $uid, self::CUST_ATTEMPTS, 0 );
            delete_user_meta( $uid, self::CUST_SNAP );    // Snapshot leeren → voller Feldsatz wird erneut gesendet
            delete_user_meta( $uid, self::CUST_PENDING ); // frischer Key: der bewusste Neuversuch ist ein neues Ereignis
            self::push_customer( $uid, false );
        }
        wp_safe_redirect( add_query_arg( 'done', 'retry', admin_url( 'admin.php?page=m24-desk-sync' ) ) );
        exit;
    }

    public static function handle_admin_cust_dry() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Keine Berechtigung.' ); }
        $uid = (int) ( $_GET['user'] ?? 0 );
        check_admin_referer( 'm24_desk_cust_dry_' . $uid );
        $note = '';
        if ( $uid > 0 ) { $r = self::push_customer( $uid, true ); $note = (string) ( $r['note'] ?? '' ); }
        set_transient( 'm24_desk_dry_note_' . get_current_user_id(), $note, 60 );
        wp_safe_redirect( add_query_arg( 'done', 'dry', admin_url( 'admin.php?page=m24-desk-sync' ) ) );
        exit;
    }

    /* ── W2 / W3 (PUT customers/orders) ───────────────────────────────────── */

    /**
     * W2: Angebot angenommen → (a) Kunde (Rechnungsadresse) + (b) Auftrag (Lieferadresse + confirmed).
     * Gating wie W1: Flag aus → dry_run (kein Idempotency-Key). Ohne Desk-Konfiguration sanft übersprungen.
     */
    public static function on_offer_accepted( $offer_id ) {
        $offer_id = (int) $offer_id;
        if ( $offer_id <= 0 || self::applying_inbound() || ! class_exists( 'M24_Rest_Client' ) || ! M24_Rest_Client::is_configured() ) { return; }
        $dry = ! self::enabled();
        $o   = M24_Offers::get_by_id( $offer_id );
        if ( ! $o ) { return; }
        if ( (int) $o->account_id > 0 ) { self::push_customer( (int) $o->account_id, $dry ); } // (a)
        self::push_order_confirm( $offer_id, $dry );                                            // (b)
    }

    /** W3: Kontodaten geändert (unabhängig von Annahme). Ohne Desk-Customer-ID still überspringen. */
    public static function on_customer_updated( $uid ) {
        $uid = (int) $uid;
        if ( $uid <= 0 || self::applying_inbound() || ! class_exists( 'M24_Rest_Client' ) || ! M24_Rest_Client::is_configured() ) { return; }
        if ( '' === (string) get_user_meta( $uid, self::CUST_META, true ) ) { return; } // noch nie gepusht → beim nächsten Angebots-Push
        self::push_customer( $uid, ! self::enabled() );
    }

    /**
     * PUT /api/customers/<desk_customer_id> — geänderte Kundenfelder (Diff gegen Snapshot) + changed_at.
     * Key: wp-cust-<uid>-<changehash>. Nichts geändert → kein Call. Kein Desk-Customer-ID → skip.
     *
     * Diff-Regel (der Desk baut die Felder mit Overwrite, ein leerer String LÖSCHT dort):
     *   unverändert (leer wie befüllt) → Feld weglassen
     *   befüllt → anderer Wert        → neuen Wert senden
     *   befüllt → geleert             → '' senden — das IST die Löschung
     * Der Vergleich (string)$snap[$k] !== (string)$v leistet genau das: ein bewusst geleertes Feld gilt als
     * geändert und wird gesendet, ein nie befülltes bleibt draußen (WP erhebt keinen Anspruch darauf).
     *
     * changed_at ist IMMER self::iso_ms() (= jetzt), nie ein geerbter Stempel: ist er nicht strikt neuer als
     * der Desk-Stempel, verwirft Desk das Feld — antwortet aber trotzdem 200 und der Push sähe fälschlich
     * erfolgreich aus. Mit „jetzt" gewinnt der WP-Edit verlässlich.
     * @return array{ok:bool,status:int,note:string}
     */
    public static function push_customer( int $uid, bool $dry_run = false ): array {
        $cid = (string) get_user_meta( $uid, self::CUST_META, true );
        if ( '' === $cid ) { return array( 'ok' => false, 'status' => 0, 'note' => 'kein desk_customer_id' ); }

        $fields = self::customer_fields( $uid );
        $snap   = json_decode( (string) get_user_meta( $uid, self::CUST_SNAP, true ), true );
        $snap   = is_array( $snap ) ? $snap : array();
        $changed = array();
        foreach ( $fields as $k => $v ) {
            if ( (string) ( $snap[ $k ] ?? '' ) !== (string) ( is_bool( $v ) ? (int) $v : $v ) ) { $changed[ $k ] = $v; }
        }
        if ( empty( $changed ) ) { return array( 'ok' => true, 'status' => 0, 'note' => 'keine Änderung' ); }

        // __source:'sync' → Desk überspringt seinen eigenen Webhook für diesen Write. Ohne das schickt Desk die
        // Änderung, die gerade VON HIER kam, per Inbound zurück und der erste WP-Edit schaukelt sich auf.
        $body = $changed + array( 'changed_at' => self::iso_ms(), '__source' => 'sync' );

        $opts = array( 'timeout' => self::TIMEOUT );
        if ( ! $dry_run ) {
            $opts['headers'] = array( 'X-Idempotency-Key' => self::customer_key( $uid, $snap, $changed ) );
            update_user_meta( $uid, self::CUST_ATTEMPTS, (int) get_user_meta( $uid, self::CUST_ATTEMPTS, true ) + 1 );
        }

        if ( $dry_run ) { $body['dry_run'] = true; }
        $res    = M24_Rest_Client::request( 'PUT', '/api/customers/' . rawurlencode( $cid ), $body, $opts );
        $status = (int) ( $res['status'] ?? 0 );
        $data   = is_array( $res['data'] ?? null ) ? $res['data'] : array();

        if ( $dry_run ) {
            $ok = ( 200 === $status || 201 === $status ) && ( ! empty( $data['dry_run'] ) || 'ok' === ( $data['validation'] ?? '' ) );
            self::log( 0, $ok ? 'cust_dry_ok' : 'cust_dry_fail', 'uid=' . $uid . ' cid=' . $cid . ' → HTTP ' . $status . ' · ' . wp_json_encode( array_keys( $changed ) ) );
            return array( 'ok' => $ok, 'status' => $status, 'note' => 'Kunde Dry-Run → HTTP ' . $status . ( isset( $data['validation'] ) ? ' · validation=' . (string) $data['validation'] : '' ) );
        }

        if ( in_array( $status, array( 200, 201, 409 ), true ) ) {
            self::persist_customer_id( $uid, $data );
            update_user_meta( $uid, self::CUST_SNAP, wp_json_encode( self::normalize_snap( $fields ) ) );
            delete_user_meta( $uid, self::CUST_DIRTY );
            delete_user_meta( $uid, self::CUST_PENDING ); // Übergang bestätigt → nächster Edit bekommt einen frischen Key
            update_user_meta( $uid, self::CUST_ATTEMPTS, 0 );
            self::log( 0, 'cust_synced', 'uid=' . $uid . ' cid=' . $cid . ' HTTP ' . $status );
            return array( 'ok' => true, 'status' => $status, 'note' => 'cust_synced' );
        }
        if ( 400 === $status || 422 === $status ) {
            $detail = self::error_detail( $data, $res );
            delete_user_meta( $uid, self::CUST_DIRTY ); // kein blinder Retry
            // Desk hat den Key auch für diese <500-Antwort persistiert → er ist verbrannt. Pending löschen,
            // damit ein manueller Retry einen frischen Key zieht statt in ein 409 („bereits gesehen") zu laufen.
            delete_user_meta( $uid, self::CUST_PENDING );
            update_user_meta( $uid, self::CUST_ATTEMPTS, self::MAX_TRIES );
            self::log( 0, 'cust_validation_failed', 'uid=' . $uid . ' HTTP ' . $status . ' · ' . $detail );
            if ( class_exists( 'M24_Error_Log' ) ) { M24_Error_Log::capture( 'desk_sync', 'error', 'Desk PUT /customers Validierungsfehler (kein Retry)', array( 'uid' => $uid, 'status' => $status, 'detail' => $detail ) ); }
            return array( 'ok' => false, 'status' => $status, 'note' => 'cust_validation_failed' );
        }
        // ≥500 / Timeout → Retry-Queue + Fallback-Mail.
        $detail = self::error_detail( $data, $res );
        update_user_meta( $uid, self::CUST_DIRTY, '1' );
        self::send_generic_fallback_mail( 'Kunde-Update (PUT /customers/' . $cid . ')', 'Konto #' . $uid, $status, $detail );
        self::log( 0, 'cust_failed', 'uid=' . $uid . ' HTTP ' . $status . ' · ' . $detail . ' → Retry-Queue' );
        return array( 'ok' => false, 'status' => $status, 'note' => 'cust_retry_queued' );
    }

    /**
     * W2b: PUT /api/orders/<desk_order_id> — Lieferadresse (ship_*, sonst = Rechnung) + completed_steps + confirmed.
     * Key: wp-offer-<id>-confirm. Ohne desk_order_id → skip (W1-Create trägt die Daten noch).
     * @return array{ok:bool,status:int,note:string}
     */
    public static function push_order_confirm( int $offer_id, bool $dry_run = false ): array {
        $o = M24_Offers::get_by_id( $offer_id );
        if ( ! $o ) { return array( 'ok' => false, 'status' => 0, 'note' => 'Angebot nicht gefunden.' ); }
        $desk_order = trim( (string) $o->desk_order_id );
        if ( '' === $desk_order ) {
            self::log( $offer_id, 'confirm_skip', 'kein desk_order_id (W1-Create noch offen) → Confirm übersprungen.' );
            return array( 'ok' => false, 'status' => 0, 'note' => 'kein desk_order_id' );
        }

        $body = self::confirm_body( $o );
        $opts = array( 'timeout' => self::TIMEOUT );
        if ( ! $dry_run ) { $opts['headers'] = array( 'X-Idempotency-Key' => 'wp-offer-' . $offer_id . '-confirm' ); }
        if ( ! $dry_run ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . M24_Offers::table() . ' SET desk_sync_attempts = desk_sync_attempts + 1 WHERE id = %d', $offer_id ) );
        }
        if ( $dry_run ) { $body['dry_run'] = true; }

        $res    = M24_Rest_Client::request( 'PUT', '/api/orders/' . rawurlencode( $desk_order ), $body, $opts );
        $status = (int) ( $res['status'] ?? 0 );
        $data   = is_array( $res['data'] ?? null ) ? $res['data'] : array();

        if ( $dry_run ) {
            $ok = ( 200 === $status || 201 === $status ) && ( ! empty( $data['dry_run'] ) || 'ok' === ( $data['validation'] ?? '' ) );
            self::log( $offer_id, $ok ? 'confirm_dry_ok' : 'confirm_dry_fail', 'HTTP ' . $status );
            return array( 'ok' => $ok, 'status' => $status, 'note' => 'Auftrag Dry-Run → HTTP ' . $status . ( isset( $data['validation'] ) ? ' · validation=' . (string) $data['validation'] : '' ) );
        }
        if ( in_array( $status, array( 200, 201, 409 ), true ) ) {
            self::mark( $offer_id, 'synced', 0, '' ); // löst needs_update auf
            self::log( $offer_id, 'confirm_synced', 'HTTP ' . $status );
            return array( 'ok' => true, 'status' => $status, 'note' => 'confirm_synced' );
        }
        if ( 400 === $status || 422 === $status ) {
            $detail = self::error_detail( $data, $res );
            self::mark( $offer_id, 'failed', self::MAX_TRIES, 'Confirm-Validierung (' . $status . '): ' . $detail );
            self::log( $offer_id, 'confirm_validation_failed', 'HTTP ' . $status . ' · ' . $detail );
            if ( class_exists( 'M24_Error_Log' ) ) { M24_Error_Log::capture( 'desk_sync', 'error', 'Desk PUT /orders Confirm-Validierungsfehler (kein Retry)', array( 'offer_no' => (string) $o->offer_no, 'status' => $status, 'detail' => $detail ) ); }
            return array( 'ok' => false, 'status' => $status, 'note' => 'confirm_validation_failed' );
        }
        // ≥500 / Timeout → confirm_failed (eigener Retry-Pfad) + Fallback-Mail.
        $detail = self::error_detail( $data, $res );
        self::mark( $offer_id, 'confirm_failed', null, 'Confirm Server/Timeout (' . $status . '): ' . $detail );
        self::send_fallback_mail( $o, $status, $detail );
        self::log( $offer_id, 'confirm_failed', 'HTTP ' . $status . ' · ' . $detail . ' → Retry-Queue' );
        return array( 'ok' => false, 'status' => $status, 'note' => 'confirm_retry_queued' );
    }

    /**
     * Kundenfelder (v1.1) aus dem WP-Konto. Robust gegen ZWEI Meta-Modelle: das Angebots-/Operator-Modell
     * (flache Metas _m24_firmenname/_m24_strasse/…) und das Konto-Self-Service-Modell (M24_Account:
     * _m24_firma + _m24_addr_billing[]). Flach hat Vorrang, Account-Array ist Fallback. biz aus Kundentyp.
     *
     * Die ship_* (Standard-Lieferanschrift, customers.ship_* im Desk seit e32e745 live) kommen aus dem
     * Array-Meta _m24_addr_shipping und bewusst OHNE Default — anders als 'land', das auf 'Deutschland'
     * fällt. Der Desk-Diff deutet '' als Löschung: ein erfundener Default würde jedem Konto ohne separate
     * Lieferanschrift eine andichten, und ein einmal gesetztes Feld ließe sich nie wieder leeren.
     */
    private static function customer_fields( int $uid ): array {
        $u = get_userdata( $uid );
        $addr = get_user_meta( $uid, '_m24_addr_billing', true );
        $addr = is_array( $addr ) ? $addr : array();
        $ship = get_user_meta( $uid, '_m24_addr_shipping', true );
        $ship = is_array( $ship ) ? $ship : array();
        $sg   = static function ( $k ) use ( $ship ) { return trim( (string) ( $ship[ $k ] ?? '' ) ); };
        $pick = static function ( $flat, $arrkey ) use ( $uid, $addr ) {
            $v = trim( (string) get_user_meta( $uid, $flat, true ) );
            return '' !== $v ? $v : trim( (string) ( $addr[ $arrkey ] ?? '' ) );
        };
        $anrede = trim( (string) get_user_meta( $uid, '_m24_anrede', true ) );
        $vor    = trim( (string) get_user_meta( $uid, 'first_name', true ) );
        $nach   = trim( (string) get_user_meta( $uid, 'last_name', true ) );
        $name   = trim( $anrede . ' ' . trim( $vor . ' ' . $nach ) );
        if ( '' === trim( $vor . $nach ) ) { $name = trim( (string) ( $addr['name'] ?? ( $u ? $u->display_name : '' ) ) ); }
        $firma  = trim( (string) get_user_meta( $uid, '_m24_firmenname', true ) );
        if ( '' === $firma ) { $firma = trim( (string) get_user_meta( $uid, '_m24_firma', true ) ); } // Account-Modell
        $land   = $pick( '_m24_land', 'land' );
        return array(
            'firma'    => $firma,
            'name'     => $name,
            'strasse'  => $pick( '_m24_strasse', 'strasse' ),
            'strasse2' => (string) get_user_meta( $uid, '_m24_adresszusatz', true ),
            'plz'      => $pick( '_m24_plz', 'plz' ),
            'ort'      => $pick( '_m24_ort', 'ort' ),
            'land'     => '' !== $land ? $land : 'Deutschland',
            'uid'      => (string) get_user_meta( $uid, '_m24_ustid', true ),
            'eori'     => mb_substr( (string) get_user_meta( $uid, '_m24_eori', true ), 0, 17 ),
            'tel'      => (string) get_user_meta( $uid, '_m24_telefon', true ),
            'biz'      => ( 'b2b' === get_user_meta( $uid, '_m24_kundentyp', true ) ),
            // Standard-Lieferanschrift → customers.ship_* (PUT-Whitelist im Desk, Teil A3).
            'ship_firma'    => $sg( 'firma' ),
            'ship_name'     => $sg( 'name' ),
            'ship_strasse'  => $sg( 'strasse' ),
            'ship_strasse2' => $sg( 'strasse2' ),
            'ship_plz'      => $sg( 'plz' ),
            'ship_ort'      => $sg( 'ort' ),
            'ship_land'     => $sg( 'land' ),
        );
    }

    /** Auftrag-Confirm-Body: Lieferadresse (ship_*, sonst Rechnung) + completed_steps + confirmed + changed_at. */
    private static function confirm_body( $o ): array {
        $diff = (int) $o->ship_diff === 1;
        $g    = static function ( $bill, $ship ) use ( $o, $diff ) { return (string) ( $diff && '' !== (string) $o->$ship ? $o->$ship : $o->$bill ); };
        $sanr = $g( 'bill_anrede', 'ship_anrede' );
        $svor = $g( 'bill_vorname', 'ship_vorname' );
        $snac = $g( 'bill_nachname', 'ship_nachname' );
        $sname = trim( $sanr . ' ' . trim( $svor . ' ' . $snac ) );
        return array(
            'ship_firma'    => $g( 'bill_firma', 'ship_firma' ),
            'ship_name'     => $sname,
            'ship_strasse'  => $g( 'bill_strasse', 'ship_strasse' ),
            'ship_strasse2' => '', // separate Zusatzzeile für Lieferadresse nicht erfasst → leer
            'ship_plz'      => $g( 'bill_plz', 'ship_plz' ),
            'ship_ort'      => $g( 'bill_ort', 'ship_ort' ),
            'ship_land'     => $g( 'bill_land', 'ship_land' ) ?: 'Deutschland',
            'completed_steps' => array( 'confirmed' ), // WP setzt den confirmed-Schritt; Desk merged serverseitig (dedupe)
            'confirmed'     => true,
            'changed_at'    => self::iso_ms(),
            '__source'      => 'sync', // Desk überspringt dafür seinen Webhook → kein Bounce zurück nach WP
        );
    }

    /**
     * Idempotency-Key für den Kunden-PUT. Er muss das EDIT-EREIGNIS identifizieren, nicht dessen Inhalt.
     *
     * Ein reiner Content-Hash kann die zwei Fälle nicht trennen, die gegensätzliche Keys brauchen:
     *   Retry eines unbestätigten Pushes → MUSS denselben Key tragen (sonst legt Desk doppelt an).
     *   Derselbe Übergang ein zweites Mal → MUSS einen neuen Key tragen. „Bonn → '' → Bonn" ist beim dritten
     *     Push inhaltlich identisch zum ersten; mit gleichem Key antwortet Desk 409 (idempotency_key_reused),
     *     wir werten das als Erfolg und ziehen den Snapshot nach — während in Desk weiterhin '' steht.
     *
     * Deshalb ein Zähler je Ereignis: die Signatur (Von-Zustand + Änderung) beantwortet nur „ist das noch
     * derselbe unbestätigte Übergang?". Ja → gespeicherten Key wiederverwenden. Nein → neue Sequenz. Der
     * Pending-Eintrag wird bei Erfolg UND bei 400/422 gelöscht (Desk persistiert die Idempotenz für jede
     * Antwort <500 — ein Retry mit dem verbrannten Key käme als 409 zurück und sähe fälschlich erfolgreich aus).
     */
    private static function customer_key( int $uid, array $snap, array $changed ): string {
        $sig     = md5( wp_json_encode( array( self::ksorted( array_intersect_key( $snap, $changed ) ), self::ksorted( $changed ) ) ) );
        $pending = json_decode( (string) get_user_meta( $uid, self::CUST_PENDING, true ), true );
        if ( is_array( $pending ) && ( $pending['sig'] ?? '' ) === $sig && ! empty( $pending['key'] ) ) {
            return (string) $pending['key'];
        }
        $seq = (int) get_user_meta( $uid, self::CUST_SEQ, true ) + 1;
        $key = 'wp-cust-' . $uid . '-' . $seq;
        update_user_meta( $uid, self::CUST_SEQ, $seq );
        update_user_meta( $uid, self::CUST_PENDING, wp_json_encode( array( 'sig' => $sig, 'key' => $key ) ) );
        return $key;
    }

    /** Diff-Snapshot auf den aktuellen Feldstand setzen (nach einem Desk→WP-Apply → kein Scheindiff beim nächsten Push). */
    public static function snapshot_customer( int $uid ): void {
        if ( $uid <= 0 ) { return; }
        update_user_meta( $uid, self::CUST_SNAP, wp_json_encode( self::normalize_snap( self::customer_fields( $uid ) ) ) );
    }

    /** Desk-Customer-ID aus einer Response an das Konto hängen (falls geliefert und noch nicht gesetzt). */
    private static function persist_customer_id( int $uid, array $data ): void {
        if ( $uid <= 0 ) { return; }
        $cid = (string) ( $data['customer_id'] ?? $data['customer']['id'] ?? '' );
        if ( '' !== $cid && '' === (string) get_user_meta( $uid, self::CUST_META, true ) ) {
            update_user_meta( $uid, self::CUST_META, $cid );
        }
    }

    private static function normalize_snap( array $fields ): array {
        $out = array();
        foreach ( $fields as $k => $v ) { $out[ $k ] = is_bool( $v ) ? (int) $v : (string) $v; }
        return $out;
    }

    private static function ksorted( array $a ): array { ksort( $a ); return $a; }

    /** Änderungszeitpunkt (UTC, ms) als ISO-8601. */
    private static function iso_ms(): string {
        $t  = microtime( true );
        $ms = (int) round( ( $t - floor( $t ) ) * 1000 );
        if ( $ms > 999 ) { $ms = 999; }
        return gmdate( 'Y-m-d\TH:i:s', (int) $t ) . sprintf( '.%03dZ', $ms );
    }

    /** Fallback-Mail für Kunde-/Auftrag-Updates (generisch, ohne Angebotsobjekt). */
    private static function send_generic_fallback_mail( string $op, string $subject_ctx, int $status, string $detail ): void {
        $settings = get_option( 'm24_plattform_settings', array() );
        $to = ( is_array( $settings ) && ! empty( $settings['fallback_mail_to'] ) ) ? (string) $settings['fallback_mail_to'] : 'service@motorsport24.de';
        $lines = array(
            'Ein Desk-Update ist fehlgeschlagen (Server/Timeout).', '',
            'Vorgang:  ' . $op,
            'Kontext:  ' . $subject_ctx,
            'HTTP:     ' . $status,
            'Detail:   ' . $detail, '',
            'Der Vorgang steht in der Retry-Queue (alle 4 h, bis ' . self::MAX_TRIES . ' Versuche). Monitor: „Desk-Sync".',
        );
        $body = function_exists( 'm24_mail_shell' )
            ? m24_mail_shell( 'Desk-Update fehlgeschlagen — ' . $op, '<pre style="font:13px/1.6 monospace;white-space:pre-wrap;">' . esc_html( implode( "\n", $lines ) ) . '</pre>', array( 'lang' => 'de', 'footer_legal_slim' => true ) )
            : nl2br( esc_html( implode( "\n", $lines ) ) );
        wp_mail( $to, 'Desk-Update fehlgeschlagen — ' . $op, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
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
