<?php
/**
 * M24 Plattform — Modul core/desk-sync: Inbound (Desk → WP, D1–D3).
 *
 * Gegenstück zu M24_Desk_Push (WP → Desk, W1/W2/W3). Verantwortung dieser Datei: den von Desk gefeuerten
 * Webhook annehmen, authentifizieren, gegen Replays absichern und die Nutzlast FELDGENAU per Last-Write-Wins
 * auf den lokalen Angebots-/Kundendatensatz anwenden.
 *
 * Route (eine, Dispatch über `entity` im Body — so sendet Desk an genau eine WP_WEBHOOK_URL, ohne Suffix):
 *   POST m24/v1/desk-sync            → Basis-URL: https://www.motorsport24.de/wp-json/m24/v1/desk-sync
 *   POST m24/v1/desk-sync/order|customer — toleranter Alias, falls Desk doch mit Suffix konfiguriert wird.
 *
 * Antwort-Codes folgen dem Desk-Retry-Verhalten (>=500 retrybar, <500 endgültig):
 *   200 — angewandt / LWW-verworfen / Replay / unbekannter Datensatz (alles idempotent → kein Retry)
 *   401 — Token fehlt/falsch     400 — Payload kaputt   (endgültig → kein sinnloser Retry)
 *   500 — nur transiente WP-Fehler (DB-Write schlägt fehl) → Desk stellt später erneut zu
 *
 * NICHT synchronisiert (bewusst): items/amt/vat_mode/notes/subj (D5). Die Desk-Item-Form trägt Anzeige-Strings
 * (art/qty/price in DE-Format) und keine teil_id — ein Rückschreiben nach items_json würde die Katalog-
 * Verknüpfung zerstören; amt allein würde subtotal_net/tax_amount inkonsistent lassen. D5 braucht einen
 * beidseitig geklärten Item-Vertrag und folgt separat.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Desk_Inbound {

    const NS          = 'm24/v1';
    const ROUTE       = '/desk-sync';
    const SEEN_PREFIX = 'm24_dsk_seen_';          // Transient-Präfix für X-Idempotency-Key
    const SEEN_TTL    = 7 * DAY_IN_SECONDS;       // Ablauf analog Desk
    const CUST_STAMPS = '_m24_desk_field_updated_at'; // User-Meta: LWW-Map je Kundenfeld
    const LOG_CTX     = 'desk_sync_in';           // eigener Logger-Kontext → Monitor filtert sauber

    /**
     * Echo-Schutz: true, solange der Inbound schreibt. Die Outbound-Trigger (M24_Desk_Push::on_*) prüfen das
     * Flag und returnen früh — sonst schickt WP die gerade eingespielte Desk-Änderung sofort wieder an Desk.
     */
    public static $applying = false;

    /**
     * Desk-Feld → WP-Spalte (m24_offers). Schlüssel = Feldname im Vertrag (auch der Schlüssel in
     * field_updated_at), Wert = lokale Spalte. 'ship_name' ist der einzige Sonderfall (siehe apply_order).
     */
    const ORDER_MAP = array(
        'payment_date'               => 'payment_date',
        'carrier'                    => 'carrier',
        'tracking'                   => 'tracking',
        'packages'                   => 'packages',
        'completed_steps'            => 'completed_steps',
        'sevdesk_invoice_number'     => 'sevdesk_invoice_number',
        'sevdesk_invoice_pdf_r2_key' => 'sevdesk_invoice_pdf_r2_key',
        'ship_firma'                 => 'ship_firma',
        'ship_name'                  => '',            // → ship_anrede/ship_vorname/ship_nachname
        'ship_strasse'               => 'ship_strasse',
        'ship_strasse2'              => 'ship_strasse2',
        'ship_plz'                   => 'ship_plz',
        'ship_ort'                   => 'ship_ort',
        'ship_land'                  => 'ship_land',
    );

    /** Desk-Feld → User-Meta (flaches Operator-Modell; dasselbe, das M24_Desk_Push::customer_fields() liest). */
    const CUSTOMER_MAP = array(
        'anrede'   => '_m24_anrede',
        'firma'    => '_m24_firmenname',
        'strasse'  => '_m24_strasse',
        'strasse2' => '_m24_adresszusatz',
        'plz'      => '_m24_plz',
        'ort'      => '_m24_ort',
        'land'     => '_m24_land',
        'uid'      => '_m24_ustid',
        'tel'      => '_m24_telefon',
        'eori'     => '_m24_eori',
    );

    /** Kunden-Lieferanschrift: Desk-Feld → Schlüssel im Array-Meta _m24_addr_shipping (M24_Account::M_ADDR_SHIP). */
    const CUSTOMER_SHIP_MAP = array(
        'ship_firma'    => 'firma',
        'ship_name'     => 'name',
        'ship_strasse'  => 'strasse',
        'ship_strasse2' => 'strasse2',
        'ship_plz'      => 'plz',
        'ship_ort'      => 'ort',
        'ship_land'     => 'land',
    );

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        // Backfill: bereits gespiegelte Desk-Angebote nachträglich korrekt materialisieren (Summen/Datum/Positionen).
        add_action( 'admin_post_m24_backfill_synced_offers', array( __CLASS__, 'handle_backfill_synced' ) );
        // 10-Tage-Mirror: Desk-native Aufträge (noch nicht in WP) als Spiegel anlegen (Upsert, keine Dubletten).
        add_action( 'admin_post_m24_mirror_backfill', array( __CLASS__, 'handle_mirror_backfill' ) );
    }

    public static function register_routes() {
        $args = array(
            'methods'             => 'POST',
            'permission_callback' => array( __CLASS__, 'check_token' ),
            'callback'            => array( __CLASS__, 'handle' ),
        );
        register_rest_route( self::NS, self::ROUTE, $args );
        // Alias mit Suffix: Desk hängt heute keins an, aber eine falsch gesetzte WP_WEBHOOK_URL soll nicht
        // in einen 404 laufen. Die entity aus dem Pfad wird ignoriert — der Body bleibt die Wahrheit.
        register_rest_route( self::NS, self::ROUTE . '/(?P<entity>order|customer)', $args );
    }

    /* ── Teil 2: Auth ─────────────────────────────────────────────────────── */

    /**
     * Der WP-Inbound-Token ist ein EIGENES Geheimnis (Desk führt es als WP_WEBHOOK_TOKEN) — bewusst nicht der
     * Outbound-Key (X-API-Key): die beiden Richtungen sollen unabhängig rotierbar sein. Konstante hat Vorrang
     * vor DB-Setting, analog M24_REST_Client::get_api_key().
     */
    public static function token(): string {
        if ( defined( 'M24_WP_INBOUND_TOKEN' ) && '' !== (string) M24_WP_INBOUND_TOKEN ) {
            return (string) M24_WP_INBOUND_TOKEN;
        }
        $s = get_option( 'm24_plattform_settings', array() );
        return is_array( $s ) ? trim( (string) ( $s['wp_inbound_token'] ?? '' ) ) : '';
    }

    /** Konstantzeit-Vergleich gegen X-M24-Token. Kein Token-Wert in Logs. */
    public static function check_token( WP_REST_Request $req ) {
        $tok = self::token();
        $hdr = (string) $req->get_header( 'X-M24-Token' );
        if ( '' === $tok ) {
            self::log( 'unauthorized', 'Kein WP-Inbound-Token konfiguriert (Einstellungen / M24_WP_INBOUND_TOKEN).' );
            return new WP_Error( 'm24dsk_no_token', 'Inbound nicht konfiguriert.', array( 'status' => 401 ) );
        }
        if ( '' === $hdr || ! hash_equals( $tok, $hdr ) ) {
            self::log( 'unauthorized', 'X-M24-Token fehlt oder stimmt nicht.' );
            return new WP_Error( 'm24dsk_auth', 'Nicht autorisiert.', array( 'status' => 401 ) );
        }
        return true;
    }

    /* ── Dispatch ─────────────────────────────────────────────────────────── */

    public static function handle( WP_REST_Request $req ) {
        $p = $req->get_json_params();
        if ( ! is_array( $p ) ) {
            return self::bad( 'Body ist kein JSON-Objekt.' );
        }

        $entity = strtolower( trim( (string) ( $p['entity'] ?? '' ) ) );
        if ( ! in_array( $entity, array( 'order', 'customer' ), true ) ) {
            return self::bad( 'entity fehlt oder ist unbekannt (erwartet: order|customer).' );
        }
        $data = is_array( $p['data'] ?? null ) ? $p['data'] : null;
        if ( null === $data ) {
            return self::bad( 'data fehlt oder ist kein Objekt.' );
        }
        $id = (int) ( $data['id'] ?? $p['id'] ?? 0 );
        if ( $id <= 0 ) {
            return self::bad( 'id fehlt oder ist nicht numerisch.' );
        }
        $stamps = is_array( $p['field_updated_at'] ?? null ) ? $p['field_updated_at'] : array();

        // Löschweitergabe: Desk signalisiert eine Löschung (event/action oder deleted/deleted_at im data-Objekt).
        // Für Aufträge → Soft-Delete des WP-Spiegels (Papierkorb, Tombstone). Kunden-Delete wird (noch) nicht
        // durchgereicht (Kundenkonto ≠ Auftrag; separater Vorgang).
        $event     = strtolower( trim( (string) ( $p['event'] ?? $p['action'] ?? $data['event'] ?? '' ) ) );
        $is_delete = in_array( $event, array( 'deleted', 'delete', 'removed', 'order.deleted', 'trashed' ), true )
            || ! empty( $data['deleted'] ) || ! empty( $data['deleted_at'] );

        // Teil 3: Replay-Schutz. Kein Key → verarbeiten (LWW ist ohnehin idempotent), aber nichts merken.
        $key = trim( (string) $req->get_header( 'X-Idempotency-Key' ) );
        if ( '' !== $key && get_transient( self::seen_key( $key ) ) ) {
            self::log( 'replay', $entity . ' #' . $id . ' — Key bereits gesehen, kein erneuter Write.' );
            return new WP_REST_Response( array( 'status' => 'replay' ), 200 );
        }

        $prev = self::$applying;
        self::$applying = true; // Teil 5: Echo-Schutz — Outbound-Trigger halten still, solange wir schreiben.
        try {
            if ( 'order' === $entity && $is_delete ) {
                $res = self::soft_delete_order( $id );
            } else {
                $res = ( 'order' === $entity )
                    ? self::apply_order( $id, $data, $stamps )
                    : self::apply_customer( $id, $data, $stamps );
            }
        } catch ( Exception $e ) {
            self::$applying = $prev;
            // Transient (DB weg o. ä.) → 5xx, damit Desk erneut zustellt.
            self::log( 'error', $entity . ' #' . $id . ' — ' . $e->getMessage() );
            return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Interner Fehler.' ), 500 );
        }
        self::$applying = $prev;

        if ( '' !== $key ) { set_transient( self::seen_key( $key ), 1, self::SEEN_TTL ); }
        return new WP_REST_Response( $res, 200 );
    }

    /* ── Teil 6: entity=order ─────────────────────────────────────────────── */

    /**
     * Angebot über desk_order_id == data.id finden und die Desk-Hoheitsfelder feldgenau anwenden.
     * @return array Status-Body für die 200er-Antwort.
     */
    private static function apply_order( int $id, array $data, array $stamps ): array {
        global $wpdb;
        $t = M24_Offers::table();
        $o = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE desk_order_id = %s LIMIT 1", (string) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $o ) {
            // Upsert: nicht gefunden → Desk-originären Auftrag als plugin-natives Angebot anlegen, mit Kunde verknüpfen.
            $new_id = self::create_order( $id, $data, $stamps );
            if ( $new_id > 0 ) {
                self::log( 'created', 'order #' . $id . ' → neues Angebot id ' . $new_id );
                return array( 'status' => 'created', 'entity' => 'order', 'id' => $id, 'offer_id' => $new_id );
            }
            self::log( 'skipped_unmapped', 'order #' . $id . ' — nicht gefunden, Anlage nicht möglich.' );
            return array( 'status' => 'skipped_unmapped', 'entity' => 'order', 'id' => $id );
        }

        $local    = self::decode_map( (string) $o->field_updated_at );
        $cols     = array();   // Spalte => Wert
        $applied  = array();   // Vertragsfeldnamen, die gewonnen haben
        $discard  = array();

        foreach ( self::ORDER_MAP as $field => $col ) {
            if ( ! array_key_exists( $field, $data ) ) { continue; }
            if ( ! self::wins( $stamps[ $field ] ?? null, $local[ $field ] ?? null ) ) {
                $discard[] = $field;
                continue;
            }
            $v = $data[ $field ];
            if ( 'ship_name' === $field ) {
                // Der Outbound baut ship_name = Anrede + Vorname + Nachname; hier der Rückweg.
                $n = self::split_name( (string) $v );
                $cols['ship_anrede']   = $n['anrede'];
                $cols['ship_vorname']  = $n['vorname'];
                $cols['ship_nachname'] = $n['nachname'];
            } elseif ( 'payment_date' === $field ) {
                $cols[ $col ] = self::to_mysql_utc( (string) $v );
            } elseif ( 'completed_steps' === $field ) {
                $cols[ $col ] = is_array( $v ) ? wp_json_encode( array_values( array_map( 'strval', $v ) ) ) : null;
            } elseif ( 'packages' === $field ) {
                $cols[ $col ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
            } else {
                $cols[ $col ] = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : null;
            }
            $applied[]       = $field;
            $local[ $field ] = self::stamp_of( $stamps[ $field ] ?? null );
        }

        if ( empty( $applied ) ) {
            self::log( 'discarded_lww', 'order #' . $id . ' (' . (string) $o->offer_no . ') — nichts übernommen · verworfen: ' . implode( ',', $discard ) );
            return array( 'status' => 'discarded_lww', 'entity' => 'order', 'id' => $id, 'discarded' => $discard );
        }

        // Eine abweichende Lieferadresse vom Desk ist nur wirksam, wenn das Angebot sie auch als abweichend führt.
        foreach ( array( 'ship_firma', 'ship_name', 'ship_strasse', 'ship_strasse2', 'ship_plz', 'ship_ort', 'ship_land' ) as $sf ) {
            if ( in_array( $sf, $applied, true ) ) { $cols['ship_diff'] = 1; break; }
        }

        $cols['field_updated_at'] = wp_json_encode( $local );
        if ( false === $wpdb->update( $t, $cols, array( 'id' => (int) $o->id ) ) ) {
            throw new Exception( 'DB-Update m24_offers fehlgeschlagen: ' . $wpdb->last_error );
        }

        // D1: gemeldeter Zahlungseingang → Angebot als bezahlt führen (mark_paid ist idempotent und feuert
        // m24_offer_paid; ohne das bliebe payment_date eine Spalte, die niemand liest).
        if ( in_array( 'payment_date', $applied, true ) && ! empty( $cols['payment_date'] ) ) {
            M24_Offers::mark_paid( (int) $o->id, 'desk' );
        }

        // Desk-Lebenszyklus → WP-Status/Pill (erledigt/abgelehnt/versandt/…). completed_steps ist die feine
        // Quelle. ZULETZT gesetzt, damit ein späterer Schritt (versandt/erledigt) ein vorheriges
        // mark_paid('bezahlt') gewinnt. Nur wenn completed_steps per LWW übernommen wurde (Desk ist dafür
        // autoritativ); Entwürfe bleiben unangetastet.
        if ( in_array( 'completed_steps', $applied, true ) ) {
            $steps_new = json_decode( (string) ( $cols['completed_steps'] ?? '[]' ), true );
            $ns        = self::status_from_steps( is_array( $steps_new ) ? $steps_new : array(), (string) ( $data['status'] ?? '' ) );
            if ( self::status_transition_ok( (string) $o->status, $ns ) ) {
                $wpdb->update( $t, array( 'status' => $ns ), array( 'id' => (int) $o->id ) );
                self::log( 'status', 'order #' . $id . ' (' . (string) $o->offer_no . ') → Status ' . $ns );
            }
        }

        self::log( 'applied', 'order #' . $id . ' (' . (string) $o->offer_no . ') — übernommen: ' . implode( ',', $applied )
            . ( $discard ? ' · verworfen (LWW): ' . implode( ',', $discard ) : '' ) );
        return array( 'status' => 'applied', 'entity' => 'order', 'id' => $id, 'applied' => $applied, 'discarded' => $discard );
    }

    /**
     * Löschweitergabe Desk→WP: den WP-Spiegel des Auftrags in den Papierkorb (Soft-Delete). Die Zeile bleibt
     * als Tombstone erhalten (deleted_at gesetzt) → Re-Sync (apply_order-Upsert / 10-Tage-Mirror) findet sie über
     * desk_order_id und legt sie NICHT wieder als aktiv an. Kein WP-Spiegel → No-op (nichts anzulegen/zu löschen).
     */
    private static function soft_delete_order( int $desk_id ): array {
        global $wpdb; $t = M24_Offers::table();
        $o = $wpdb->get_row( $wpdb->prepare( "SELECT id, offer_no, deleted_at FROM $t WHERE desk_order_id = %s LIMIT 1", (string) $desk_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $o ) {
            self::log( 'delete_noop', 'order #' . $desk_id . ' — kein WP-Spiegel vorhanden.' );
            return array( 'status' => 'delete_noop', 'entity' => 'order', 'id' => $desk_id );
        }
        if ( empty( $o->deleted_at ) ) {
            $wpdb->update( $t, array( 'deleted_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $o->id ) );
            self::log( 'deleted', 'order #' . $desk_id . ' (' . (string) $o->offer_no . ') → Papierkorb.' );
        }
        return array( 'status' => 'deleted', 'entity' => 'order', 'id' => $desk_id, 'offer_id' => (int) $o->id );
    }

    /* ── Teil 6: entity=customer ──────────────────────────────────────────── */

    /**
     * Konto über die Mapping-Meta _m24_desk_customer_id == data.id finden (NICHT über E-Mail — die ist bewusst
     * nicht eindeutig) und die Kundenfelder feldgenau anwenden. `flag` wird nie übernommen (Desk leitet es aus
     * `land` ab, WP rendert selbst).
     */
    private static function apply_customer( int $id, array $data, array $stamps ): array {
        $users = get_users( array(
            'meta_key'   => M24_Desk_Push::CUST_META, // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => (string) $id,             // phpcs:ignore WordPress.DB.SlowDBQuery
            'number'     => 1,
            'fields'     => 'ID',
        ) );
        $uid = (int) ( $users[0] ?? 0 );
        $created = false;
        if ( $uid <= 0 ) {
            // Upsert: nicht gefunden → Kundenkonto aus data anlegen (WP-User ohne Login/Mail-Effekt), Mapping setzen.
            $uid = self::create_customer( $id, $data );
            if ( $uid <= 0 ) {
                self::log( 'skipped_unmapped', 'customer #' . $id . ' — nicht gefunden, Anlage ohne valide E-Mail nicht möglich.' );
                return array( 'status' => 'skipped_unmapped', 'entity' => 'customer', 'id' => $id );
            }
            $created = true;
        }

        $local   = self::decode_map( (string) get_user_meta( $uid, self::CUST_STAMPS, true ) );
        $applied = array();
        $discard = array();
        $ship    = get_user_meta( $uid, '_m24_addr_shipping', true );
        $ship    = is_array( $ship ) ? $ship : array();
        $ship_touched = false;

        foreach ( self::CUSTOMER_MAP as $field => $meta ) {
            if ( ! array_key_exists( $field, $data ) ) { continue; }
            if ( ! self::wins( $stamps[ $field ] ?? null, $local[ $field ] ?? null ) ) { $discard[] = $field; continue; }
            $v = sanitize_text_field( (string) ( is_scalar( $data[ $field ] ) ? $data[ $field ] : '' ) );
            if ( 'eori' === $field ) { $v = mb_substr( $v, 0, 17 ); }
            if ( 'anrede' === $field ) { $lc = strtolower( $v ); $v = ( 'herr' === $lc ) ? 'Herr' : ( ( 'frau' === $lc ) ? 'Frau' : '' ); } // Wire lowercase → intern 'Herr'/'Frau'
            update_user_meta( $uid, $meta, $v );
            $applied[]       = $field;
            $local[ $field ] = self::stamp_of( $stamps[ $field ] ?? null );
        }

        // name → Anrede + Vor-/Nachname (Rückweg zu customer_fields(), das genau so zusammensetzt).
        if ( array_key_exists( 'name', $data ) ) {
            if ( self::wins( $stamps['name'] ?? null, $local['name'] ?? null ) ) {
                $n = self::split_name( (string) $data['name'] );
                // Anrede nur aus dem name-Prefix ableiten, wenn KEIN separates anrede-Feld kam (das ist autoritativ,
                // sonst würde ein prefixloser name das eben gesyncte anrede wieder auf '' überschreiben).
                if ( ! array_key_exists( 'anrede', $data ) ) { update_user_meta( $uid, '_m24_anrede', $n['anrede'] ); }
                wp_update_user( array( 'ID' => $uid, 'first_name' => $n['vorname'], 'last_name' => $n['nachname'] ) );
                $applied[]      = 'name';
                $local['name']  = self::stamp_of( $stamps['name'] ?? null );
            } else {
                $discard[] = 'name';
            }
        }

        // biz → Kundentyp.
        if ( array_key_exists( 'biz', $data ) ) {
            if ( self::wins( $stamps['biz'] ?? null, $local['biz'] ?? null ) ) {
                update_user_meta( $uid, '_m24_kundentyp', ! empty( $data['biz'] ) ? 'b2b' : 'b2c' );
                $applied[]    = 'biz';
                $local['biz'] = self::stamp_of( $stamps['biz'] ?? null );
            } else {
                $discard[] = 'biz';
            }
        }

        // Standard-Lieferanschrift → Array-Meta (M24_Account::M_ADDR_SHIP).
        foreach ( self::CUSTOMER_SHIP_MAP as $field => $k ) {
            if ( ! array_key_exists( $field, $data ) ) { continue; }
            if ( ! self::wins( $stamps[ $field ] ?? null, $local[ $field ] ?? null ) ) { $discard[] = $field; continue; }
            $ship[ $k ]      = sanitize_text_field( (string) ( is_scalar( $data[ $field ] ) ? $data[ $field ] : '' ) );
            $ship_touched    = true;
            $applied[]       = $field;
            $local[ $field ] = self::stamp_of( $stamps[ $field ] ?? null );
        }
        if ( $ship_touched ) { update_user_meta( $uid, '_m24_addr_shipping', $ship ); }

        if ( empty( $applied ) && ! $created ) {
            self::log( 'discarded_lww', 'customer #' . $id . ' (uid ' . $uid . ') — nichts übernommen · verworfen: ' . implode( ',', $discard ) );
            return array( 'status' => 'discarded_lww', 'entity' => 'customer', 'id' => $id, 'discarded' => $discard );
        }

        update_user_meta( $uid, self::CUST_STAMPS, wp_json_encode( $local ) );
        // Den Diff-Snapshot des Outbounds nachziehen: sonst hält push_customer() die eben eingespielten
        // Desk-Werte für eine lokale Änderung und schickt sie beim nächsten Trigger zurück.
        self::resync_customer_snapshot( $uid );

        $st = $created ? 'created' : 'applied';
        self::log( $st, 'customer #' . $id . ' (uid ' . $uid . ') — ' . ( $created ? 'angelegt · ' : '' ) . 'übernommen: ' . implode( ',', $applied )
            . ( $discard ? ' · verworfen (LWW): ' . implode( ',', $discard ) : '' ) );
        return array( 'status' => $st, 'entity' => 'customer', 'id' => $id, 'applied' => $applied, 'discarded' => $discard, 'uid' => $uid );
    }

    /* ── Upsert-Anlage (Desk → WP) ─────────────────────────────────────────── */

    /**
     * Kundenkonto aus einer Desk-Zeile anlegen. Bewusst als WP-User (das Kundenmodell des Plugins IST
     * wp_users — Mapping/Meta/Outbound hängen daran), aber OHNE Login-Nebenwirkung: Zufallspasswort, Rolle
     * subscriber, KEINE Willkommens-/Passwort-Mail (wp_insert_user verschickt von sich aus nichts). Bestehende
     * E-Mail → verknüpfen statt doppeln. Ohne valide E-Mail nicht anlegbar → 0.
     */
    private static function create_customer( int $desk_id, array $data ): int {
        $email = sanitize_email( (string) ( $data['email'] ?? '' ) );
        if ( ! is_email( $email ) ) { return 0; }
        $existing = get_user_by( 'email', $email );
        $uid = $existing ? (int) $existing->ID : 0;
        if ( $uid <= 0 ) {
            $name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
            $res  = wp_insert_user( array(
                'user_login'   => $email,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password( 24 ),
                'role'         => 'subscriber',
                'display_name' => '' !== $name ? $name : $email,
            ) );
            if ( is_wp_error( $res ) || (int) $res <= 0 ) { return 0; }
            $uid = (int) $res;
        }
        update_user_meta( $uid, M24_Desk_Push::CUST_META, (string) $desk_id ); // Mapping Desk customers.id ↔ WP-User
        return $uid;
    }

    /**
     * Desk-originären Auftrag als plugin-natives Angebot (m24_offers) anlegen. Best-effort-Mapping der Desk-
     * Zeile auf das WP-Schema; items werden in Desk-Form abgelegt (Anzeige ggf. abweichend — der beidseitige
     * Item-Vertrag ist offen). Verknüpfung zum Kunden über desk customers.id → WP-User (0 = noch unverknüpft).
     * KEIN mark_paid/Hook-Feuern (Echo- + Mail-Vermeidung) — Status wird direkt gesetzt.
     * @return int neue offer-id, 0 bei Fehler.
     */
    private static function create_order( int $desk_id, array $data, array $stamps ): int {
        global $wpdb;
        $t = M24_Offers::table();

        // Kunden-Identität aus der ORDER-Zeile: die trägt cust (Anzeigename), sender_email und country —
        // NICHT name/email/firma (die stehen auf der customers-Zeile). Diese Felder speisen BEIDES:
        // die Konto-Verknüpfung (E-Mail-Fallback) UND den Angebots-Snapshot (Liste zeigt sonst „K").
        $cemail = sanitize_email( (string) ( $data['sender_email'] ?? $data['email'] ?? '' ) );
        $cname  = trim( (string) ( $data['cust'] ?? $data['name'] ?? '' ) );
        $cland  = (string) ( $data['country'] ?? $data['land'] ?? '' );

        $account_id = 0;
        $desk_cust  = (int) ( $data['customer_id'] ?? 0 );
        if ( $desk_cust > 0 ) {
            $u = get_users( array( 'meta_key' => M24_Desk_Push::CUST_META, 'meta_value' => (string) $desk_cust, 'number' => 1, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
            $account_id = (int) ( $u[0] ?? 0 );
        }
        // Fallback: Mapping (noch) nicht gesetzt — z. B. wenn der Kunde NICHT über den /desk-sync-Inbound
        // (der _m24_desk_customer_id setzt), sondern via /offers/customer-create („Schnellanlage") oder einen
        // anderen Pfad angelegt wurde. Dann über die E-Mail des Desk-Kunden verknüpfen UND das Mapping
        // nachtragen, damit der Auftrag am Konto hängt und Folge-Syncs (D5) den Kunden wiederfinden.
        if ( $account_id <= 0 && '' !== $cemail && is_email( $cemail ) ) {
            $bymail = get_user_by( 'email', $cemail );
            if ( $bymail ) {
                $account_id = (int) $bymail->ID;
                if ( $desk_cust > 0 && '' === (string) get_user_meta( $account_id, M24_Desk_Push::CUST_META, true ) ) {
                    update_user_meta( $account_id, M24_Desk_Push::CUST_META, (string) $desk_cust ); // Mapping nachtragen
                }
            }
        }

        // Angebotsnummer: Desk-Nummer bevorzugt; bei Kollision (UNIQUE offer_no) Fallback D-<id>.
        $offer_no = mb_substr( sanitize_text_field( (string) ( $data['order_num'] ?? $data['ref'] ?? '' ) ), 0, 20 );
        if ( '' === $offer_no ) { $offer_no = 'D-' . $desk_id; }
        $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE offer_no = %s LIMIT 1", $offer_no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $exists > 0 ) { $offer_no = mb_substr( 'D-' . $desk_id, 0, 20 ); }

        // Kunden-Snapshot fürs Angebot: Name/E-Mail/Land aus der Order-Zeile (Liste gruppiert danach). Die
        // restlichen Stammdaten (Firma/Adresse/USt/EORI/Typ) trägt die Order NICHT — vom verknüpften Konto
        // nachziehen, falls vorhanden.
        $customer = array(
            'email' => $cemail, 'name' => $cname, 'land' => $cland,
            'kundentyp' => ! empty( $data['biz'] ) ? 'b2b' : 'b2c',
            'firma' => '', 'strasse' => '', 'adresszusatz' => '', 'plz' => '', 'ort' => '', 'telefon' => '', 'ustid' => '', 'eori' => '',
        );
        if ( $account_id > 0 ) {
            $customer['firma']        = (string) get_user_meta( $account_id, '_m24_firmenname', true );
            $customer['strasse']      = (string) get_user_meta( $account_id, '_m24_strasse', true );
            $customer['adresszusatz'] = (string) get_user_meta( $account_id, '_m24_adresszusatz', true );
            $customer['plz']          = (string) get_user_meta( $account_id, '_m24_plz', true );
            $customer['ort']          = (string) get_user_meta( $account_id, '_m24_ort', true );
            $customer['telefon']      = (string) get_user_meta( $account_id, '_m24_telefon', true );
            $customer['ustid']        = (string) get_user_meta( $account_id, '_m24_ustid', true );
            $customer['eori']         = (string) get_user_meta( $account_id, '_m24_eori', true );
            if ( '' === $customer['name'] ) {
                $customer['name'] = trim( (string) get_user_meta( $account_id, 'first_name', true ) . ' ' . (string) get_user_meta( $account_id, 'last_name', true ) );
            }
            $kt = (string) get_user_meta( $account_id, '_m24_kundentyp', true );
            if ( '' !== $kt ) { $customer['kundentyp'] = ( 'b2b' === $kt ) ? 'b2b' : 'b2c'; }
        }

        // Positionen Desk→WP normalisieren (WP-Renderer lesen title/unit_price/qty, nicht art/price) und Summen
        // cent-genau ableiten (Brutto = Desk amt; Netto/USt konsistent zum vat_mode). Beides über die Helfer,
        // die auch der Backfill für Alt-Zeilen nutzt.
        $items_raw = $data['items'] ?? array();
        if ( is_string( $items_raw ) ) { $items_raw = json_decode( $items_raw, true ); }
        if ( ! is_array( $items_raw ) ) { $items_raw = array(); }
        $items    = self::normalize_items( $items_raw );
        // Desk-vat_mode → WP-tax_mode-Key speichern, damit der ganze WP-Stack (Labels, compute_totals,
        // Kunden-Ansicht) den Steuerfall korrekt kennt (roher Desk-Wert = Rate 0).
        $vat_mode = self::vat_to_wp_taxmode( sanitize_text_field( (string) ( $data['vat_mode'] ?? '' ) ) );
        $tot      = self::derive_totals( $items, $vat_mode, $cland, (float) ( $data['amt'] ?? 0 ) );
        $net   = $tot['net'];
        $tax   = $tot['tax'];
        $gross = $tot['gross'];
        $rate  = $tot['rate'];

        // Angebotsdatum aus dem Desk (echtes Sende-/Angebotsdatum), NICHT „jetzt" → Übersicht zeigt das reale
        // Datum statt „heute". Reihenfolge nach Verlässlichkeit; nur bei komplett fehlendem Datum auf jetzt.
        $offer_date_raw = (string) ( $data['offer_date'] ?? $data['order_date'] ?? $data['created'] ?? $data['created_at'] ?? '' );
        $sent_at        = '' !== trim( $offer_date_raw ) ? self::to_mysql_utc( $offer_date_raw ) : null;
        if ( empty( $sent_at ) ) { $sent_at = current_time( 'mysql', true ); }
        $steps = $data['completed_steps'] ?? array();
        if ( is_string( $steps ) ) { $steps = json_decode( $steps, true ); }
        $steps = is_array( $steps ) ? array_values( array_map( 'strval', $steps ) ) : array();
        $paid  = ! empty( $data['payment_date'] );
        $shipn = self::split_name( (string) ( $data['ship_name'] ?? '' ) );
        // Auftrags-Status aus dem Desk-Lebenszyklus (completed_steps → erledigt/abgelehnt/versandt/…);
        // Fallback auf das gröbere status-Feld, sonst bezahlt/offen nach payment_date.
        $wp_status = self::status_from_steps( $steps, (string) ( $data['status'] ?? '' ) );
        if ( '' === $wp_status ) { $wp_status = $paid ? 'bezahlt' : 'offen'; }

        $row = array(
            'offer_no'      => $offer_no,
            'token'         => bin2hex( random_bytes( 16 ) ),
            'account_id'    => $account_id,
            'status'        => $wp_status,
            'customer_json' => wp_json_encode( $customer ),
            'items_json'    => wp_json_encode( $items ),
            'extras_json'   => wp_json_encode( array() ),
            'delivery_time' => '',
            'tax_mode'      => $vat_mode,
            'tax_rate'      => $rate,
            'tax_note'      => '',
            'subtotal_net'  => $net,
            'tax_amount'    => $tax,
            'total_gross'   => $gross,
            'currency'      => 'EUR',
            'src_json'      => wp_json_encode( array(
                'desk_origin' => true, 'desk_customer_id' => $desk_cust,
                'subj' => (string) ( $data['subj'] ?? '' ), 'note' => (string) ( $data['notes'] ?? '' ),
            ) ),
            'desk_order_id'    => (string) $desk_id,
            'desk_order_num'   => mb_substr( sanitize_text_field( (string) ( $data['order_num'] ?? '' ) ), 0, 40 ),
            'desk_sync_status' => 'synced',
            'desk_synced_at'   => current_time( 'mysql', true ),
            'completed_steps'  => wp_json_encode( $steps ),
            'payment_date'     => $paid ? self::to_mysql_utc( (string) $data['payment_date'] ) : null,
            'carrier'          => isset( $data['carrier'] ) ? sanitize_text_field( (string) $data['carrier'] ) : null,
            'tracking'         => isset( $data['tracking'] ) ? sanitize_text_field( (string) $data['tracking'] ) : null,
            'packages'         => isset( $data['packages'] ) ? ( is_scalar( $data['packages'] ) ? (string) $data['packages'] : wp_json_encode( $data['packages'] ) ) : null,
            'sevdesk_invoice_number'     => isset( $data['sevdesk_invoice_number'] ) ? sanitize_text_field( (string) $data['sevdesk_invoice_number'] ) : null,
            'sevdesk_invoice_pdf_r2_key' => isset( $data['sevdesk_invoice_pdf_r2_key'] ) ? sanitize_text_field( (string) $data['sevdesk_invoice_pdf_r2_key'] ) : null,
            'ship_firma'    => (string) ( $data['ship_firma'] ?? '' ),
            'ship_anrede'   => $shipn['anrede'],
            'ship_vorname'  => $shipn['vorname'],
            'ship_nachname' => $shipn['nachname'],
            'ship_strasse'  => (string) ( $data['ship_strasse'] ?? '' ),
            'ship_strasse2' => (string) ( $data['ship_strasse2'] ?? '' ),
            'ship_plz'      => (string) ( $data['ship_plz'] ?? '' ),
            'ship_ort'      => (string) ( $data['ship_ort'] ?? '' ),
            'ship_land'     => (string) ( $data['ship_land'] ?? '' ),
            'ship_diff'     => ( '' !== (string) ( $data['ship_name'] ?? '' ) || '' !== (string) ( $data['ship_strasse'] ?? '' ) ) ? 1 : 0,
            'field_updated_at' => wp_json_encode( is_array( $stamps ) ? $stamps : array() ),
            'created_at'    => current_time( 'mysql', true ),
            'sent_at'       => $sent_at, // echtes Angebotsdatum aus dem Desk (offer_date), nicht „jetzt"
        );
        if ( $paid ) { $row['paid_at'] = self::to_mysql_utc( (string) $data['payment_date'] ); }

        $ok = $wpdb->insert( $t, $row );
        if ( false === $ok ) { return 0; }
        return (int) $wpdb->insert_id;
    }

    /** Snapshot = aktueller Feldstand, damit der nächste push_customer() keinen Scheindiff sieht. */
    private static function resync_customer_snapshot( int $uid ): void {
        if ( ! method_exists( 'M24_Desk_Push', 'snapshot_customer' ) ) { return; }
        M24_Desk_Push::snapshot_customer( $uid );
    }

    /* ── Teil 4: LWW-Helfer ───────────────────────────────────────────────── */

    /**
     * Gewinnt der eingehende Wert? Ja, wenn lokal kein Stempel existiert ODER der eingehende jünger ist.
     * Gleichstand/älter → Verwurf (idempotent: ein Replay trägt denselben Stempel und wird verworfen).
     * Fehlt der eingehende Stempel, lässt sich nichts vergleichen → Desk ist für diese Felder die Hoheit,
     * also übernehmen (Vertrag Teil 4.3: „incomingStamp fehlt-lokal oder incomingStamp > localStamp").
     */
    private static function wins( $incoming, $local ): bool {
        $l = self::to_ms( (string) ( $local ?? '' ) );
        if ( null === $l ) { return true; }
        $i = self::to_ms( (string) ( $incoming ?? '' ) );
        if ( null === $i ) { return false; } // lokal gestempelt, eingehend nicht → lokal behalten
        return $i > $l;
    }

    /** Der Stempel, der lokal abgelegt wird: der eingehende; fehlt er, der Empfangszeitpunkt. */
    private static function stamp_of( $incoming ): string {
        $s = trim( (string) ( $incoming ?? '' ) );
        return ( '' !== $s && null !== self::to_ms( $s ) ) ? $s : gmdate( 'Y-m-d\TH:i:s' ) . '.000Z';
    }

    /**
     * ISO-8601 (mit ms/Zone) ODER MySQL-UTC ('Y-m-d H:i:s', so schreibt current_time('mysql', true)) → Epoch-ms.
     * Ein lexikalischer Vergleich der beiden Formen wäre falsch (' ' < 'T'), deshalb echt parsen.
     */
    public static function to_ms( string $s ): ?int {
        $s = trim( $s );
        if ( '' === $s ) { return null; }
        $ms = 0;
        if ( preg_match( '/\.(\d{1,3})/', $s, $m ) ) { $ms = (int) str_pad( $m[1], 3, '0' ); }
        $base = preg_replace( '/\.\d+/', '', $s );
        // Ohne Zonen-Suffix ist der Wert per Konvention UTC (MySQL-Spalten des Plugins sind UTC).
        if ( ! preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $base ) ) { $base .= ' UTC'; }
        $t = strtotime( $base );
        return ( false === $t ) ? null : ( $t * 1000 + $ms );
    }

    /** ISO/beliebiges Datum → 'Y-m-d H:i:s' UTC für DATETIME-Spalten. Leer/unparsbar → null. */
    private static function to_mysql_utc( string $s ): ?string {
        $ms = self::to_ms( $s );
        return ( null === $ms ) ? null : gmdate( 'Y-m-d H:i:s', (int) floor( $ms / 1000 ) );
    }

    private static function decode_map( string $json ): array {
        $m = json_decode( $json, true );
        return is_array( $m ) ? $m : array();
    }

    /* ── Helfer ───────────────────────────────────────────────────────────── */

    /**
     * "Herr Max Mustermann" → anrede/vorname/nachname. Kehrt den Outbound-Aufbau um. Ohne führende Anrede
     * wandert das erste Token in den Vornamen, der Rest in den Nachnamen (Doppelnachnamen bleiben zusammen).
     */
    /** DE-Preis-String ("2.380,00", "€ 12.521,01", "2380,00") → float. Nicht parsbar → 0.0. */
    private static function parse_de_price( string $raw ): float {
        $raw = trim( $raw );
        if ( '' === $raw ) { return 0.0; }
        if ( false !== strpos( $raw, ',' ) && false !== strpos( $raw, '.' ) ) { $raw = str_replace( '.', '', $raw ); $raw = str_replace( ',', '.', $raw ); }
        elseif ( false !== strpos( $raw, ',' ) ) { $raw = str_replace( ',', '.', $raw ); }
        $raw = preg_replace( '/[^0-9.\-]/', '', $raw );
        return is_numeric( $raw ) ? (float) $raw : 0.0;
    }

    /**
     * Desk-Positionen (art/qty/price-DE-String/note/is25a) → WP-Form (title/unit_price/qty/art_nr/tax25a).
     * Idempotent: bereits WP-geformte Items (unit_price gesetzt) bleiben unverändert. Preis ist netto
     * (Vertrag v1.1: item.price = number_format(unit_price_netto)); §25a trägt Brutto direkt.
     */
    public static function normalize_items( array $raw ): array {
        $out = array();
        foreach ( $raw as $it ) {
            $it    = (array) $it;
            $title = (string) ( $it['title'] ?? $it['art'] ?? '' );
            if ( '' === trim( $title ) ) { continue; }
            $unit = isset( $it['unit_price'] ) ? (float) $it['unit_price'] : self::parse_de_price( (string) ( $it['price'] ?? '' ) );
            if ( $unit <= 0.0 && isset( $it['amt'] ) ) { $unit = (float) $it['amt']; }
            $out[] = array(
                'teil_id'    => (int) ( $it['teil_id'] ?? 0 ),
                'title'      => $title,
                'art_nr'     => (string) ( $it['art_nr'] ?? $it['note'] ?? '' ),
                'qty'        => max( 1, (int) ( $it['qty'] ?? 1 ) ),
                'unit_price' => round( $unit, 2 ),
                'tax25a'     => ( ! empty( $it['tax25a'] ) || ! empty( $it['is25a'] ) || ! empty( $it['st25a'] ) ),
                'custom'     => false,
            );
        }
        return $out;
    }

    /** Deutscher Kunde? (Land verbatim: 'DE'/'Deutschland'/'Germany'/…). */
    private static function is_de_land( string $land ): bool {
        return in_array( strtoupper( trim( $land ) ), array( 'DE', 'D', 'DEU', 'DEUTSCHLAND', 'GERMANY' ), true );
    }

    /**
     * Desk-vat_mode (b2b_de/b2c_de/b2b_eu/b2c_eu/b2c_export) → WP-tax_mode-Key
     * (b2b_de_19/b2b_eu_net/b2c_eu_oss/drittland_net). Der WP-Stack (Labels, compute_totals, Kunden-Ansicht)
     * kennt NUR die WP-Keys — der rohe Desk-Wert fiele dort auf Rate 0. Unbekannt → unverändert.
     */
    public static function vat_to_wp_taxmode( string $m ): string {
        switch ( $m ) {
            case 'b2b_de': case 'b2c_de': case 'b2b_de_19': return 'b2b_de_19';
            case 'b2b_eu': case 'b2b_eu_net':               return 'b2b_eu_net';
            case 'b2c_eu': case 'b2c_eu_oss':               return 'b2c_eu_oss';
            case 'b2c_export': case 'drittland_net':        return 'drittland_net';
        }
        return $m;
    }

    /**
     * Steuersatz + Bestimmbarkeit für einen (WP- oder Desk-)Steuerfall. 'known'=false → der korrekte
     * Satz ist ohne Brutto-Anker nicht ermittelbar (OSS-Zielland-Satz / unbekannter Fall bei Nicht-DE).
     * @return array{rate:float,known:bool}
     */
    private static function tax_rate_for_mode( string $mode, string $land ): array {
        if ( in_array( $mode, array( 'b2b_de_19', 'b2b_de', 'b2c_de' ), true ) ) { return array( 'rate' => 19.0, 'known' => true ); }
        if ( in_array( $mode, array( 'b2b_eu_net', 'b2b_eu', 'drittland_net', 'b2c_export' ), true ) ) { return array( 'rate' => 0.0, 'known' => true ); }
        if ( in_array( $mode, array( 'b2c_eu_oss', 'b2c_eu' ), true ) ) { return array( 'rate' => 0.0, 'known' => false ); } // OSS: Satz ohne Brutto unbekannt
        if ( self::is_de_land( $land ) ) { return array( 'rate' => 19.0, 'known' => true ); } // unbekannter Modus, aber DE → 19 %
        return array( 'rate' => 0.0, 'known' => false );
    }

    /**
     * Summen aus Positionen + (falls vorhanden) Desk-Brutto ableiten — cent-genau = Desk.
     *   Autorität Brutto = amt (falls >0); sonst aus Netto × (1+Satz) rekonstruiert.
     *   DE-19 (ohne §25a) → Netto/USt via Satz; net-Modi → USt 0, Brutto = Netto; §25a aus der Steuerbasis raus.
     *   'determinable' = false NUR wenn KEIN Brutto-Anker (amt<=0) UND der Satz nicht bestimmbar ist (OSS/unbek.
     *   Nicht-DE) — dann darf der Backfill NICHT mit Brutto=Netto „heilen" (sähe echt aus, wäre falsch).
     * @return array{net:float,tax:float,gross:float,rate:float,determinable:bool}
     */
    public static function derive_totals( array $items, string $vat_mode, string $land, float $amt ): array {
        $net_reg = 0.0; $st25a = 0.0;
        foreach ( $items as $it ) {
            $line = (float) ( $it['unit_price'] ?? 0 ) * max( 1, (int) ( $it['qty'] ?? 1 ) );
            if ( ! empty( $it['tax25a'] ) || ! empty( $it['st25a'] ) ) { $st25a += $line; } else { $net_reg += $line; }
        }
        $r      = self::tax_rate_for_mode( $vat_mode, $land );
        $rate   = (float) $r['rate'];
        $has25a = $st25a > 0.0;

        if ( $amt > 0.0 ) {
            // Brutto-Anker vorhanden → Desk-Brutto ist die Wahrheit; Netto/USt konsistent dazu.
            $gross = round( $amt, 2 );
            if ( $rate > 0.0 && ! $has25a ) {
                $net = round( $gross / ( 1 + $rate / 100 ), 2 );
                $tax = round( $gross - $net, 2 );
            } else {
                $net = round( $net_reg + $st25a, 2 );
                $tax = round( max( 0.0, $gross - $net ), 2 );
            }
            return array( 'net' => $net, 'tax' => $tax, 'gross' => $gross, 'rate' => $rate, 'determinable' => true );
        }

        // Kein Brutto-Anker → aus dem Steuersatz rekonstruieren; unbestimmbar → Signal an den Aufrufer.
        if ( ! $r['known'] ) {
            return array( 'net' => round( $net_reg + $st25a, 2 ), 'tax' => 0.0, 'gross' => 0.0, 'rate' => $rate, 'determinable' => false );
        }
        $net = round( $net_reg + $st25a, 2 );
        $tax = round( $net_reg * $rate / 100, 2 ); // §25a nicht besteuert
        return array( 'net' => $net, 'tax' => $tax, 'gross' => round( $net + $tax, 2 ), 'rate' => $rate, 'determinable' => true );
    }

    /** Monatsanfang (UTC) aus einer YYYYMM…-Auftragsnummer ("202606127" → "2026-06-01 00:00:00"). Sonst null. */
    public static function month_start_from_num( string $num ): ?string {
        return preg_match( '/^(20\d{2})(0[1-9]|1[0-2])/', trim( $num ), $m ) ? ( $m[1] . '-' . $m[2] . '-01 00:00:00' ) : null;
    }

    /**
     * Desk-Auftrags-Lebenszyklus → WP-Angebots-Status (Pill). Feine Unterscheidung über completed_steps
     * (Desk-Vertrag), das gröbere status-Feld nur als Fallback. Reihenfolge = Priorität:
     *   done + (payment|shipped) → erledigt · done ohne beides → abgelehnt (Anfrage geschlossen, nicht angenommen)
     *   shipped → versandt · payment → bezahlt · confirmed → angenommen · offer → offen
     * Leer → '' (Aufrufer behält den bestehenden Status; kein Downgrade auf „offen").
     */
    public static function status_from_steps( array $steps, string $desk_status = '' ): string {
        $s   = array_map( 'strval', array_values( $steps ) );
        $has = static function ( $k ) use ( $s ) { return in_array( $k, $s, true ); };
        if ( $has( 'done' ) )      { return ( $has( 'payment' ) || $has( 'shipped' ) ) ? 'erledigt' : 'abgelehnt'; }
        if ( $has( 'shipped' ) )   { return 'versandt'; }
        if ( $has( 'payment' ) )   { return 'bezahlt'; }
        if ( $has( 'confirmed' ) ) { return 'angenommen'; }
        if ( $has( 'offer' ) )     { return 'offen'; }
        if ( 'done' === $desk_status ) { return 'erledigt'; } // Fallback, falls completed_steps fehlt
        return '';
    }

    /** Neuer WP-Status aus Steps zulässig? Entwürfe nie automatisch umstatusen; leerer Neu-Status = kein Wechsel. */
    private static function status_transition_ok( string $current, string $next ): bool {
        return '' !== $next && $next !== $current && 'entwurf' !== $current;
    }

    /* ── Backfill: bereits gespiegelte Desk-Angebote nachträglich korrekt materialisieren ─────────────── */

    /**
     * Echtes Angebots-/Sendedatum aus einer Desk-Order-Zeile ziehen. Tolerant gegenüber Feldnamen (der GET-
     * Payload ist nicht 1:1 dokumentiert): direkte Datumsfelder zuerst (Angebots-/Sendedatum vor „created"),
     * dann ein etwaiger Statusverlauf (Zeitpunkt des „Angebot gesendet"-Schritts). '' wenn nichts Parsbares.
     */
    private static function extract_order_date( array $row ): string {
        foreach ( array( 'offer_date', 'sent_at', 'offer_sent_at', 'angebot_gesendet', 'order_date', 'created_at', 'created' ) as $k ) {
            if ( isset( $row[ $k ] ) && is_scalar( $row[ $k ] ) && '' !== (string) $row[ $k ] ) {
                $ms = self::to_mysql_utc( (string) $row[ $k ] );
                if ( $ms ) { return $ms; }
            }
        }
        foreach ( array( 'status_history', 'history', 'timeline', 'steps_at' ) as $hk ) {
            if ( empty( $row[ $hk ] ) || ! is_array( $row[ $hk ] ) ) { continue; }
            foreach ( $row[ $hk ] as $ev ) {
                if ( ! is_array( $ev ) ) { continue; }
                $step = strtolower( (string) ( $ev['step'] ?? $ev['status'] ?? $ev['name'] ?? '' ) );
                if ( in_array( $step, array( 'offer', 'angebot', 'offer_sent', 'sent', 'angebot_gesendet' ), true ) ) {
                    $t = (string) ( $ev['at'] ?? $ev['date'] ?? $ev['timestamp'] ?? $ev['ts'] ?? '' );
                    if ( '' !== $t ) { $ms = self::to_mysql_utc( $t ); if ( $ms ) { return $ms; } }
                }
            }
        }
        return '';
    }

    /** Order-Liste aus einer GET-Antwort (Wurzel-Array oder unter orders/data/items). */
    private static function extract_order_list( $data ): array {
        if ( is_array( $data ) ) {
            if ( isset( $data[0] ) ) { return $data; }
            foreach ( array( 'orders', 'data', 'items' ) as $k ) {
                if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) { return $data[ $k ]; }
            }
        }
        return array();
    }

    /** Desk-Order-ID einer rohen GET-Zeile (tolerant gegenüber Feldnamen). */
    private static function pulled_order_id( array $r ): string {
        return (string) ( $r['id'] ?? $r['order_id'] ?? $r['desk_order_id'] ?? '' );
    }

    /**
     * Rohe Desk-Order-Liste holen: GET /api/orders (einmal, 120s transient-gecacht). Bestes-Bemühen:
     * nicht konfiguriert / Fehler / unerwartete Form → leer. KEIN harter Fehler.
     */
    public static function fetch_desk_orders(): array {
        $cached = get_transient( 'm24_desk_orders_raw' );
        if ( is_array( $cached ) ) { return $cached; }
        $list = array();
        if ( class_exists( 'M24_Rest_Client' ) && M24_Rest_Client::is_configured() ) {
            $res    = M24_Rest_Client::request( 'GET', '/api/orders', null, array( 'timeout' => 20 ) );
            $status = (int) ( $res['status'] ?? 0 );
            if ( $status >= 200 && $status < 300 ) {
                $list = self::extract_order_list( $res['data'] ?? null );
                self::log( 'desk_pull', 'GET /api/orders → ' . count( $list ) . ' Aufträge.' );
            } else {
                self::log( 'desk_pull', 'GET /api/orders HTTP ' . $status . '.' );
            }
        }
        set_transient( 'm24_desk_orders_raw', $list, 120 );
        return $list;
    }

    /** Map desk_order_id ⇒ echtes Datum ('Y-m-d H:i:s' UTC) aus dem Desk-Pull (für den Datums-Backfill). */
    public static function fetch_desk_order_dates(): array {
        $map = array();
        foreach ( self::fetch_desk_orders() as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $oid = self::pulled_order_id( $row );
            if ( '' === $oid ) { continue; }
            $d = self::extract_order_date( $row );
            if ( '' !== $d ) { $map[ $oid ] = $d; }
        }
        return $map;
    }

    /**
     * Rohe Desk-GET-Zeile → Feld-Shape, die create_order/der Webhook erwartet. Tolerant gegenüber Feldnamen
     * (die GET-Form ist nicht 1:1 dokumentiert). Preis/Steuer/Status/Items werden downstream normalisiert.
     */
    public static function normalize_pulled_order( array $r ): array {
        $pick = static function ( array $r, array $keys ) {
            foreach ( $keys as $k ) {
                if ( isset( $r[ $k ] ) && is_scalar( $r[ $k ] ) && '' !== (string) $r[ $k ] ) { return (string) $r[ $k ]; }
            }
            return '';
        };
        $steps = $r['completed_steps'] ?? $r['steps'] ?? array();
        if ( is_string( $steps ) ) { $d = json_decode( $steps, true ); $steps = is_array( $d ) ? $d : array(); }
        $items = $r['items'] ?? $r['positions'] ?? array();
        if ( is_string( $items ) ) { $d = json_decode( $items, true ); $items = is_array( $d ) ? $d : array(); }
        return array(
            'sender_email'    => $pick( $r, array( 'sender_email', 'email', 'customer_email' ) ),
            'cust'            => $pick( $r, array( 'cust', 'customer_name', 'name', 'kunde' ) ),
            'country'         => $pick( $r, array( 'country', 'land' ) ),
            'customer_id'     => (int) ( $r['customer_id'] ?? $r['cust_id'] ?? 0 ),
            'biz'             => ( ! empty( $r['biz'] ) || 'b2b' === strtolower( (string) ( $r['kundentyp'] ?? '' ) ) ),
            'amt'             => (float) ( $r['amt'] ?? $r['total_gross'] ?? $r['gross'] ?? $r['total'] ?? 0 ),
            'vat_mode'        => $pick( $r, array( 'vat_mode', 'tax_mode' ) ),
            'completed_steps' => is_array( $steps ) ? array_values( array_map( 'strval', $steps ) ) : array(),
            'status'          => $pick( $r, array( 'status' ) ),
            'payment_date'    => $pick( $r, array( 'payment_date', 'paid_at' ) ),
            'order_num'       => $pick( $r, array( 'order_num', 'order_number', 'ref', 'no' ) ),
            'ref'             => $pick( $r, array( 'ref', 'order_num', 'order_number' ) ),
            'offer_date'      => self::extract_order_date( $r ),
            'items'           => is_array( $items ) ? $items : array(),
            'subj'            => $pick( $r, array( 'subj', 'subject', 'betreff' ) ),
            'notes'           => $pick( $r, array( 'notes', 'note' ) ),
            'ship_firma'      => $pick( $r, array( 'ship_firma' ) ),
            'ship_name'       => $pick( $r, array( 'ship_name' ) ),
            'ship_strasse'    => $pick( $r, array( 'ship_strasse' ) ),
            'ship_strasse2'   => $pick( $r, array( 'ship_strasse2' ) ),
            'ship_plz'        => $pick( $r, array( 'ship_plz' ) ),
            'ship_ort'        => $pick( $r, array( 'ship_ort' ) ),
            'ship_land'       => $pick( $r, array( 'ship_land' ) ),
            'carrier'         => $pick( $r, array( 'carrier' ) ),
            'tracking'        => $pick( $r, array( 'tracking' ) ),
        );
    }

    /**
     * 10-Tage-Mirror: Desk-Aufträge, die (a) in WP noch NICHT als Spiegel existieren (Upsert-Guard über
     * desk_order_id) und (b) höchstens $days alt sind. Ohne Datum → nicht sicher einordbar, übersprungen.
     * @return array{new:array,existing:int,undated:int,pulled:int,keys:array}
     */
    public static function find_mirror_candidates( int $days = 10 ): array {
        global $wpdb; $t = M24_Offers::table();
        $rows   = self::fetch_desk_orders();
        $cutoff = time() - $days * DAY_IN_SECONDS;
        $new = array(); $existing = 0; $undated = 0; $keys = array();
        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) ) { continue; }
            if ( empty( $keys ) ) { $keys = array_keys( $r ); } // Diagnose: Feldnamen der ersten Zeile (Mapping prüfen)
            $oid = self::pulled_order_id( $r );
            if ( '' === $oid ) { continue; }
            // Zuerst die günstigen Filter (Datum), dann erst die DB-Existenzabfrage.
            $date = self::extract_order_date( $r );
            $ts   = '' !== $date ? strtotime( $date . ' UTC' ) : 0;
            if ( ! $ts ) { $undated++; continue; }                  // ohne Datum nicht sicher einordbar
            if ( $ts < $cutoff ) { continue; }                      // älter als N Tage
            $ex = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE desk_order_id = %s LIMIT 1", $oid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $ex > 0 ) { $existing++; continue; }               // schon gespiegelt → kein Duplikat
            $norm  = self::normalize_pulled_order( $r );
            $steps = is_array( $norm['completed_steps'] ) ? $norm['completed_steps'] : array();
            $new[] = array(
                'desk_id' => $oid,
                'data'    => $norm,
                'preview' => array(
                    'order_num' => (string) ( '' !== $norm['order_num'] ? $norm['order_num'] : 'D-' . $oid ),
                    'name'      => (string) $norm['cust'],
                    'date'      => substr( $date, 0, 10 ),
                    'status'    => self::status_from_steps( $steps, (string) $norm['status'] ) ?: 'offen',
                    'gross'     => (float) $norm['amt'],
                ),
            );
        }
        return array( 'new' => $new, 'existing' => $existing, 'undated' => $undated, 'pulled' => count( $rows ), 'keys' => $keys );
    }

    /** POST-Handler: legt für die 10-Tage-Kandidaten WP-Spiegel an (idempotent über desk_order_id). */
    public static function handle_mirror_backfill() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) ); }
        check_admin_referer( 'm24_mirror_backfill' );
        global $wpdb; $t = M24_Offers::table();
        $res = self::find_mirror_candidates( 10 );
        $n = 0;
        foreach ( $res['new'] as $c ) {
            $oid = (string) $c['desk_id'];
            // Re-Check unmittelbar vor dem Insert (Race-/Doppelklick-sicher) → nie ein Duplikat.
            if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE desk_order_id = %s LIMIT 1", $oid ) ) > 0 ) { continue; } // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $new_id = self::create_order( (int) $oid, (array) $c['data'], array() );
            if ( $new_id > 0 ) { $n++; self::log( 'mirror', 'Desk-Auftrag #' . $oid . ' → Angebot id ' . $new_id ); }
        }
        wp_safe_redirect( add_query_arg( array( 'page' => 'm24-offers', 'done' => 'mirror', 'n' => $n ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Ermittelt für eine Desk-gespiegelte Angebotszeile die zu korrigierenden Felder (Summen/Positionen/Status/Datum).
     * @param object $o          Angebots-Zeile
     * @param array  $desk_dates Map desk_order_id ⇒ echtes Datum (aus fetch_desk_order_dates)
     */
    private static function compute_backfill( $o, array $desk_dates = array() ): ?array {
        $items_raw = json_decode( (string) $o->items_json, true );
        $items_raw = is_array( $items_raw ) ? $items_raw : array();
        $items     = self::normalize_items( $items_raw );

        $cust = json_decode( (string) $o->customer_json, true );
        $land = is_array( $cust ) ? (string) ( $cust['land'] ?? '' ) : '';
        $changes = array();

        // Steuerfall auf den WP-Key normalisieren (Alt-Zeilen tragen den rohen Desk-vat_mode → sonst Rate 0).
        $wp_mode = self::vat_to_wp_taxmode( (string) $o->tax_mode );
        if ( $wp_mode !== (string) $o->tax_mode ) { $changes['tax_mode'] = $wp_mode; }

        // Summen: Steuerfall bestimmbar → heilen; unbestimmbar (OSS/unbekannt ohne Brutto-Anker) → NICHT mit
        // Brutto=Netto „heilen", Zeile bleibt auf dem Platzhalter (0,00). Status/Datum/Positionen laufen trotzdem.
        $tot = self::derive_totals( $items, $wp_mode, $land, (float) $o->total_gross );
        $totals_skipped = empty( $tot['determinable'] );
        if ( ! $totals_skipped ) {
            if ( round( (float) $o->subtotal_net, 2 ) !== $tot['net'] )   { $changes['subtotal_net'] = $tot['net']; }
            if ( round( (float) $o->tax_amount, 2 )   !== $tot['tax'] )   { $changes['tax_amount']   = $tot['tax']; }
            if ( round( (float) $o->total_gross, 2 )  !== $tot['gross'] ) { $changes['total_gross']  = $tot['gross']; }
            if ( round( (float) $o->tax_rate, 2 )     !== round( $tot['rate'], 2 ) ) { $changes['tax_rate'] = $tot['rate']; }
        }

        // Positionen nur umschreiben, wenn sie noch in Desk-Form vorliegen (erstes Item ohne unit_price).
        if ( ! empty( $items_raw ) ) {
            $first = (array) reset( $items_raw );
            if ( ! isset( $first['unit_price'] ) ) { $changes['items_json'] = wp_json_encode( $items ); }
        }

        // Status aus dem gespeicherten Desk-Lebenszyklus (completed_steps) nachziehen — unabhängig von den Summen.
        $steps_raw = json_decode( (string) $o->completed_steps, true );
        $ns        = self::status_from_steps( is_array( $steps_raw ) ? $steps_raw : array(), '' );
        if ( self::status_transition_ok( (string) $o->status, $ns ) ) { $changes['status'] = $ns; }

        // Datum: das ECHTE Desk-Angebotsdatum (per GET /api/orders geholt) hat immer Vorrang. Nur wenn der Desk
        // keins liefert, Fallback Monatsanfang aus der Nummer — und der nur, wenn sent_at praktisch = created_at
        // ist (= Sync-Zeit-Default der Alt-Zeilen; echte Datumswerte neuer Syncs bleiben unangetastet).
        $new_sent  = null;
        $desk_date = (string) ( $desk_dates[ (string) $o->desk_order_id ] ?? '' );
        if ( '' !== $desk_date ) {
            if ( substr( $desk_date, 0, 10 ) !== substr( (string) $o->sent_at, 0, 10 ) ) { $new_sent = $desk_date; } // tagesgenau abweichend → setzen
        } else {
            $st = strtotime( (string) $o->sent_at . ' UTC' );
            $ct = strtotime( (string) $o->created_at . ' UTC' );
            if ( $st && $ct && abs( $st - $ct ) < DAY_IN_SECONDS ) {
                $ms = self::month_start_from_num( (string) ( $o->desk_order_num ?: $o->offer_no ) );
                if ( $ms && substr( $ms, 0, 7 ) !== substr( (string) $o->sent_at, 0, 7 ) ) { $new_sent = $ms; }
            }
        }
        if ( null !== $new_sent ) { $changes['sent_at'] = $new_sent; }

        if ( empty( $changes ) ) { return array( 'status' => 'nochange', 'totals_skipped' => $totals_skipped ); }
        return array(
            'status'         => 'change',
            'totals_skipped' => $totals_skipped,
            'id'             => (int) $o->id,
            'offer_no'       => (string) $o->offer_no,
            'changes'        => $changes,
            'preview'        => array(
                'name'      => is_array( $cust ) ? (string) ( $cust['name'] ?? '' ) : '',
                'status'    => $changes['status'] ?? (string) $o->status,
                'date'      => $new_sent ? substr( $new_sent, 0, 10 ) : substr( (string) $o->sent_at, 0, 10 ),
                'positions' => count( $items ),
                'net'       => $totals_skipped ? (float) $o->subtotal_net : $tot['net'],
                'gross'     => $totals_skipped ? (float) $o->total_gross : $tot['gross'],
            ),
        );
    }

    /**
     * Desk-gespiegelte Angebote (src_json.desk_origin) auswerten.
     * @return array{candidates:array,skipped:int}  candidates = Zeilen mit Korrektur; skipped = unbestimmbare (übersprungen)
     */
    public static function find_backfill_candidates(): array {
        global $wpdb;
        $t    = M24_Offers::table();
        $rows = $wpdb->get_results( "SELECT * FROM $t WHERE src_json LIKE '%\"desk_origin\":true%' ORDER BY id DESC LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL
        $desk_dates = self::fetch_desk_order_dates(); // echtes Angebotsdatum vom Desk (best effort)
        $cands = array(); $skipped = 0;
        foreach ( (array) $rows as $o ) {
            $c = self::compute_backfill( $o, $desk_dates );
            if ( 'change' === ( $c['status'] ?? '' ) ) { $cands[] = $c; }
            if ( ! empty( $c['totals_skipped'] ) )     { $skipped++; } // Summen bleiben 0,00 (Steuerfall unklar), unabhängig von Status/Datum
        }
        return array( 'candidates' => $cands, 'skipped' => $skipped, 'dates_pulled' => count( $desk_dates ) );
    }

    /** POST-Handler: wendet die Korrekturen an (Nonce + Capability). Idempotent → mehrfach ausführbar. */
    public static function handle_backfill_synced() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) ); }
        check_admin_referer( 'm24_backfill_synced_offers' );
        global $wpdb;
        $t   = M24_Offers::table();
        $res = self::find_backfill_candidates();
        $n   = 0;
        foreach ( $res['candidates'] as $c ) {
            if ( false !== $wpdb->update( $t, $c['changes'], array( 'id' => $c['id'] ) ) ) {
                $n++;
                self::log( 'backfill', 'Angebot ' . $c['offer_no'] . ' (#' . $c['id'] . ') neu materialisiert: ' . wp_json_encode( array_keys( $c['changes'] ) ) );
            }
        }
        wp_safe_redirect( add_query_arg( array( 'page' => 'm24-offers', 'done' => 'backfill', 'n' => $n, 'skip' => (int) $res['skipped'] ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function split_name( string $name ): array {
        $out   = array( 'anrede' => '', 'vorname' => '', 'nachname' => '' );
        $parts = preg_split( '/\s+/', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $parts ) { return $out; }
        if ( in_array( mb_strtolower( $parts[0] ), array( 'herr', 'herrn', 'frau', 'mr', 'mr.', 'mrs', 'mrs.', 'ms', 'ms.', 'divers' ), true ) ) {
            $out['anrede'] = array_shift( $parts );
        }
        if ( ! $parts ) { return $out; }
        $out['vorname']  = count( $parts ) > 1 ? array_shift( $parts ) : '';
        $out['nachname'] = implode( ' ', $parts );
        return $out;
    }

    private static function seen_key( string $key ): string {
        return self::SEEN_PREFIX . md5( $key ); // Transient-Namen sind längenbegrenzt → hashen
    }

    private static function bad( string $why ) {
        self::log( 'bad_request', $why );
        return new WP_REST_Response( array( 'status' => 'bad_request', 'message' => $why ), 400 );
    }

    private static function log( string $step, string $msg ): void {
        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( self::LOG_CTX, $step, array( 'msg' => $msg ) );
        }
    }
}
