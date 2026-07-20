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

        // Teil 3: Replay-Schutz. Kein Key → verarbeiten (LWW ist ohnehin idempotent), aber nichts merken.
        $key = trim( (string) $req->get_header( 'X-Idempotency-Key' ) );
        if ( '' !== $key && get_transient( self::seen_key( $key ) ) ) {
            self::log( 'replay', $entity . ' #' . $id . ' — Key bereits gesehen, kein erneuter Write.' );
            return new WP_REST_Response( array( 'status' => 'replay' ), 200 );
        }

        $prev = self::$applying;
        self::$applying = true; // Teil 5: Echo-Schutz — Outbound-Trigger halten still, solange wir schreiben.
        try {
            $res = ( 'order' === $entity )
                ? self::apply_order( $id, $data, $stamps )
                : self::apply_customer( $id, $data, $stamps );
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

        self::log( 'applied', 'order #' . $id . ' (' . (string) $o->offer_no . ') — übernommen: ' . implode( ',', $applied )
            . ( $discard ? ' · verworfen (LWW): ' . implode( ',', $discard ) : '' ) );
        return array( 'status' => 'applied', 'entity' => 'order', 'id' => $id, 'applied' => $applied, 'discarded' => $discard );
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
        if ( $account_id <= 0 ) {
            $cemail = sanitize_email( (string) ( $data['email'] ?? '' ) );
            if ( '' !== $cemail && is_email( $cemail ) ) {
                $bymail = get_user_by( 'email', $cemail );
                if ( $bymail ) {
                    $account_id = (int) $bymail->ID;
                    if ( $desk_cust > 0 && '' === (string) get_user_meta( $account_id, M24_Desk_Push::CUST_META, true ) ) {
                        update_user_meta( $account_id, M24_Desk_Push::CUST_META, (string) $desk_cust ); // Mapping nachtragen
                    }
                }
            }
        }

        // Angebotsnummer: Desk-Nummer bevorzugt; bei Kollision (UNIQUE offer_no) Fallback D-<id>.
        $offer_no = mb_substr( sanitize_text_field( (string) ( $data['order_num'] ?? $data['ref'] ?? '' ) ), 0, 20 );
        if ( '' === $offer_no ) { $offer_no = 'D-' . $desk_id; }
        $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE offer_no = %s LIMIT 1", $offer_no ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $exists > 0 ) { $offer_no = mb_substr( 'D-' . $desk_id, 0, 20 ); }

        $customer = array(
            'email' => (string) ( $data['email'] ?? '' ), 'name' => (string) ( $data['name'] ?? '' ),
            'firma' => (string) ( $data['firma'] ?? '' ), 'strasse' => (string) ( $data['strasse'] ?? '' ),
            'adresszusatz' => (string) ( $data['strasse2'] ?? '' ), 'plz' => (string) ( $data['plz'] ?? '' ),
            'ort' => (string) ( $data['ort'] ?? '' ), 'land' => (string) ( $data['land'] ?? '' ),
            'kundentyp' => ! empty( $data['biz'] ) ? 'b2b' : 'b2c', 'telefon' => (string) ( $data['tel'] ?? '' ),
            'ustid' => (string) ( $data['uid'] ?? '' ), 'eori' => (string) ( $data['eori'] ?? '' ),
        );

        $items = $data['items'] ?? array();
        if ( is_string( $items ) ) { $items = json_decode( $items, true ); }
        if ( ! is_array( $items ) ) { $items = array(); }

        $gross = (float) ( $data['amt'] ?? 0 );
        $steps = $data['completed_steps'] ?? array();
        if ( is_string( $steps ) ) { $steps = json_decode( $steps, true ); }
        $steps = is_array( $steps ) ? array_values( array_map( 'strval', $steps ) ) : array();
        $paid  = ! empty( $data['payment_date'] );
        $shipn = self::split_name( (string) ( $data['ship_name'] ?? '' ) );

        $row = array(
            'offer_no'      => $offer_no,
            'token'         => bin2hex( random_bytes( 16 ) ),
            'account_id'    => $account_id,
            'status'        => $paid ? 'bezahlt' : 'offen',
            'customer_json' => wp_json_encode( $customer ),
            'items_json'    => wp_json_encode( $items ),
            'extras_json'   => wp_json_encode( array() ),
            'delivery_time' => '',
            'tax_mode'      => sanitize_text_field( (string) ( $data['vat_mode'] ?? '' ) ),
            'tax_rate'      => 0,
            'tax_note'      => '',
            'subtotal_net'  => $gross, // Desk liefert keine Netto/USt-Aufschlüsselung → Brutto als Näherung
            'tax_amount'    => 0,
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
            'sent_at'       => current_time( 'mysql', true ),
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
