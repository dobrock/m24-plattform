<?php
/**
 * M24 Plattform — Inquiries-Modul: Storage
 *
 * Schritt C.2:
 * - Custom Post Type `m24_inquiry` registrieren
 * - 4 Custom-Status (pending_api_push, synced, synced_via_mail, sync_failed)
 * - insert_inquiry(): nimmt validiertes Daten-Array, legt CPT-Eintrag mit Meta an
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries_Storage {

    const CPT_SLUG = 'm24_inquiry';

    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_action( 'init', [ __CLASS__, 'register_cpt' ] );
        add_action( 'init', [ __CLASS__, 'register_statuses' ] );
        add_filter( 'display_post_states', [ __CLASS__, 'display_post_states' ], 10, 2 );

        // Admin-Liste: eigene Source-Spalte
        add_filter( 'manage_' . self::CPT_SLUG . '_posts_columns',        [ __CLASS__, 'admin_columns' ], 99 ); // 99: NACH wpSEO → dessen Spalten fallen weg
        // wpSEO hängt seine Spalten (Titel/Beschreibung/Robots/Redirect/Views) an manage_edit-{cpt}_columns,
        // das NACH _posts_columns läuft → dort ebenfalls das kuratierte Set erzwingen.
        add_filter( 'manage_edit-' . self::CPT_SLUG . '_columns',          [ __CLASS__, 'admin_columns' ], 99 );
        add_action( 'manage_' . self::CPT_SLUG . '_posts_custom_column',  [ __CLASS__, 'admin_column_content' ], 10, 2 );
        add_filter( 'manage_edit-' . self::CPT_SLUG . '_sortable_columns', [ __CLASS__, 'admin_sortable_columns' ] );

        // Paket H: eigene Inbox-Karten-Seite (M24 → Anfragen); die CPT-Liste bleibt als Fallback erreichbar.
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu_inbox' ], 26 );
    }

    /**
     * Registriert den CPT m24_inquiry.
     */
    public static function register_cpt() {
        $labels = [
            'name'               => __( 'Sammelanfragen', 'm24-plattform' ),
            'singular_name'      => __( 'Sammelanfrage', 'm24-plattform' ),
            'menu_name'          => __( 'Sammelanfragen', 'm24-plattform' ),
            'all_items'          => __( 'Alle Anfragen', 'm24-plattform' ),
            'add_new'            => __( 'Neu anlegen', 'm24-plattform' ),
            'add_new_item'       => __( 'Neue Anfrage anlegen', 'm24-plattform' ),
            'edit_item'          => __( 'Anfrage bearbeiten', 'm24-plattform' ),
            'view_item'          => __( 'Anfrage ansehen', 'm24-plattform' ),
            'search_items'       => __( 'Anfragen durchsuchen', 'm24-plattform' ),
            'not_found'          => __( 'Keine Anfragen gefunden', 'm24-plattform' ),
            'not_found_in_trash' => __( 'Keine Anfragen im Papierkorb', 'm24-plattform' ),
        ];

        register_post_type( self::CPT_SLUG, [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => 'm24-plattform', // §1: unter dem Dach „MOTORSPORT24"
            'show_in_rest'        => false,
            'menu_icon'           => 'dashicons-email-alt',
            'capability_type'     => 'post',
            'hierarchical'        => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => [ 'title', 'editor', 'custom-fields' ],
        ] );
    }

    /**
     * Registriert die 4 Custom-Status.
     */
    public static function register_statuses() {
        register_post_status( M24_Inquiries::STATUS_PENDING, [
            'label'                     => _x( 'API-Push ausstehend', 'inquiry status', 'm24-plattform' ),
            'public'                    => false,
            'internal'                  => false,
            'protected'                 => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: count */
            'label_count'               => _n_noop( 'Pending <span class="count">(%s)</span>', 'Pending <span class="count">(%s)</span>', 'm24-plattform' ),
        ] );

        register_post_status( M24_Inquiries::STATUS_SYNCED, [
            'label'                     => _x( 'Synchronisiert', 'inquiry status', 'm24-plattform' ),
            'public'                    => false,
            'internal'                  => false,
            'protected'                 => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: count */
            'label_count'               => _n_noop( 'Synced <span class="count">(%s)</span>', 'Synced <span class="count">(%s)</span>', 'm24-plattform' ),
        ] );

        register_post_status( M24_Inquiries::STATUS_SYNCED_MAIL, [
            'label'                     => _x( 'Per Mail-Fallback synchronisiert', 'inquiry status', 'm24-plattform' ),
            'public'                    => false,
            'internal'                  => false,
            'protected'                 => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: count */
            'label_count'               => _n_noop( 'Mail-Fallback <span class="count">(%s)</span>', 'Mail-Fallback <span class="count">(%s)</span>', 'm24-plattform' ),
        ] );

        register_post_status( M24_Inquiries::STATUS_FAILED, [
            'label'                     => _x( 'Sync fehlgeschlagen', 'inquiry status', 'm24-plattform' ),
            'public'                    => false,
            'internal'                  => false,
            'protected'                 => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: count */
            'label_count'               => _n_noop( 'Failed <span class="count">(%s)</span>', 'Failed <span class="count">(%s)</span>', 'm24-plattform' ),
        ] );
    }

    /**
     * Zeigt Custom-Status in der Admin-Listenansicht als Label-Badge.
     */
    public static function display_post_states( $post_states, $post ) {
        if ( self::CPT_SLUG !== $post->post_type ) {
            return $post_states;
        }
        $status_labels = [
            M24_Inquiries::STATUS_PENDING     => __( 'Pending', 'm24-plattform' ),
            M24_Inquiries::STATUS_SYNCED      => __( 'Synced', 'm24-plattform' ),
            M24_Inquiries::STATUS_SYNCED_MAIL => __( 'Mail-Fallback', 'm24-plattform' ),
            M24_Inquiries::STATUS_FAILED      => __( 'Failed', 'm24-plattform' ),
        ];
        if ( isset( $status_labels[ $post->post_status ] ) ) {
            $post_states[ 'm24_' . $post->post_status ] = $status_labels[ $post->post_status ];
        }
        return $post_states;
    }

    /**
     * Legt einen Inquiry-CPT-Eintrag an.
     *
     * Erwartet ein bereits validiertes/sanitisiertes Daten-Array mit den Keys:
     *  - vorname, nachname, email (required)
     *  - tel, firma, anrede, strasse, plz, ort, land, uid, biz, notes (optional)
     *  - inquiry_source (string), inquiry_source_meta (array, wird als JSON gespeichert)
     *  - items (Array von Item-Arrays, sanitisiert)
     *
     * @param array $data
     * @return int|WP_Error  Post-ID oder WP_Error
     */
    public static function insert_inquiry( array $data ) {
        // Pflichtfeld hart prüfen (Vorname/Nachname sind optional). E-Mail bleibt Pflicht.
        if ( empty( $data['email'] ) ) {
            return new WP_Error( 'm24_inquiry_missing_field', 'Pflichtfeld "email" fehlt.' );
        }
        if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
            return new WP_Error( 'm24_inquiry_no_items', 'Anfrage ohne Positionen kann nicht gespeichert werden.' );
        }

        // Preis-Gate: nicht-freigeschaltete User dürfen keine Preise kennen,
        // also werden numerische Preise im Storage durch "Auf Anfrage" ersetzt.
        // Server-seitige Entscheidung, unabhängig vom Frontend (Defense in Depth).
        if ( ! M24_Inquiries::user_can_see_prices() ) {
            $price_placeholder = M24_Inquiries::price_login_placeholder();
            foreach ( $data['items'] as $idx => $item ) {
                if ( ! isset( $item['price'] ) ) { continue; }
                $price_str = trim( (string) $item['price'] );
                if ( $price_str === '' ) { continue; }
                // Numerische Preise (mit oder ohne Komma/Punkt) überschreiben.
                // "Auf Anfrage" und andere Strings bleiben bestehen.
                $normalized = str_replace( ',', '.', $price_str );
                if ( is_numeric( $normalized ) ) {
                    $data['items'][ $idx ]['price'] = $price_placeholder;
                }
            }
        }

        $item_count = count( $data['items'] );
        $who = trim( ( $data['vorname'] ?? '' ) . ' ' . ( $data['nachname'] ?? '' ) );
        if ( '' === $who ) { $who = ! empty( $data['firma'] ) ? $data['firma'] : (string) $data['email']; }
        $title = sprintf(
            /* translators: 1: name/email, 2: item count */
            __( 'Anfrage — %1$s (%2$d Positionen)', 'm24-plattform' ),
            $who,
            $item_count
        );

        $post_id = wp_insert_post( [
            'post_type'    => self::CPT_SLUG,
            'post_status'  => M24_Inquiries::STATUS_PENDING,
            'post_title'   => $title,
            'post_content' => isset( $data['notes'] ) ? (string) $data['notes'] : '',
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Kontakt-Meta + inquiry_source (Skalare via Loop)
        $meta_keys = [ 'vorname', 'nachname', 'email', 'tel', 'firma', 'anrede',
                       'strasse', 'plz', 'ort', 'land', 'uid', 'biz',
                       'inquiry_source' ];
        foreach ( $meta_keys as $key ) {
            if ( isset( $data[ $key ] ) ) {
                update_post_meta( $post_id, '_m24_' . $key, $data[ $key ] );
            }
        }

        // inquiry_source_meta separat als JSON-String speichern (Spec v4 §6.2),
        // damit Modul D den Wert 1:1 ans M24-Desk durchreichen kann ohne PHP-Unserialize.
        $meta_for_json = isset( $data['inquiry_source_meta'] ) && is_array( $data['inquiry_source_meta'] )
            ? $data['inquiry_source_meta']
            : [];
        $meta_json = wp_json_encode( $meta_for_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( $meta_json === false ) { $meta_json = '{}'; }
        update_post_meta( $post_id, '_m24_inquiry_source_meta', $meta_json );

        // Items als serialisiertes Array (WP-Standard)
        update_post_meta( $post_id, '_m24_items', $data['items'] );
        update_post_meta( $post_id, '_m24_item_count', $item_count );

        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_storage', 'Inquiry angelegt', [
                'post_id'    => $post_id,
                'source'     => $data['inquiry_source'] ?? '',
                'item_count' => $item_count,
            ] );
        }

        // Feuert NACH allen Meta-Writes (Items/Kontakt vollstaendig). Saubere
        // Stelle fuer Folgeaktionen pro Anfrage — u.a. Benachrichtigungs-Mail
        // (M24_Inquiries_Mail_Fallback::notify). Der Auto-Push laeuft separat.
        do_action( 'm24_inquiry_created', (int) $post_id, $data );

        return $post_id;
    }

    /**
     * Definiert die Spalten in der Admin-Liste der Sammelanfragen.
     * Order: Checkbox, Title, Source, Item-Count, Status, Date.
     */
    public static function admin_columns( $columns ) {
        // Entrümpeln: frisches, kuratiertes Set → wpSEO-/Kommentar-/Autor-Spalten entfallen.
        $new = [];
        if ( isset( $columns['cb'] ) ) { $new['cb'] = $columns['cb']; }
        $new['m24_kunde']  = __( 'Kunde', 'm24-plattform' );
        $new['m24_items']  = __( 'Positionen', 'm24-plattform' );
        $new['m24_betrag'] = __( 'Betrag', 'm24-plattform' );
        $new['m24_garage'] = __( 'Garagen-Nr.', 'm24-plattform' );
        $new['date']       = isset( $columns['date'] ) ? $columns['date'] : __( 'Datum', 'm24-plattform' );
        $new['m24_status'] = __( 'Status', 'm24-plattform' );
        $new['m24_action'] = __( 'Aktion', 'm24-plattform' );
        return $new;
    }

    /** Rendert den Inhalt der eigenen Admin-Spalten. */
    public static function admin_column_content( $column, $post_id ) {
        switch ( $column ) {
            case 'm24_kunde':
                $vor   = (string) get_post_meta( $post_id, '_m24_vorname', true );
                $nach  = (string) get_post_meta( $post_id, '_m24_nachname', true );
                $firma = (string) get_post_meta( $post_id, '_m24_firma', true );
                $email = (string) get_post_meta( $post_id, '_m24_email', true );
                $name  = trim( $vor . ' ' . $nach );
                if ( '' === $name ) { $name = '' !== $firma ? $firma : ( '' !== $email ? $email : '—' ); }
                $edit  = get_edit_post_link( $post_id );
                echo '<strong>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ) ) . '</strong>';
                if ( '' !== $firma && $firma !== $name ) { echo '<br><span style="color:#666;">' . esc_html( $firma ) . '</span>'; }
                if ( '' !== $email ) { echo '<br><a href="mailto:' . esc_attr( $email ) . '" style="color:#2271b1;">' . esc_html( $email ) . '</a>'; }
                break;

            case 'm24_items':
                echo (int) get_post_meta( $post_id, '_m24_item_count', true );
                break;

            case 'm24_betrag':
                echo esc_html( self::inquiry_amount_fmt( $post_id ) );
                break;

            case 'm24_garage':
                echo esc_html( self::inquiry_garage_no( $post_id ) );
                break;

            case 'm24_status':
                $map = [
                    M24_Inquiries::STATUS_PENDING     => [ 'Offen', '#8a6d3b', '#fcf8e3' ],
                    M24_Inquiries::STATUS_SYNCED      => [ 'Synchronisiert', '#1a7f37', '#eafaf0' ],
                    M24_Inquiries::STATUS_SYNCED_MAIL => [ 'Per Mail', '#1f74c4', '#eaf3fb' ],
                    M24_Inquiries::STATUS_FAILED      => [ 'Fehlgeschlagen', '#c8102e', '#fdeaea' ],
                ];
                $st = (string) get_post_status( $post_id );
                $b  = $map[ $st ] ?? [ ucfirst( $st ), '#555', '#eee' ];
                echo '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:600;color:' . esc_attr( $b[1] ) . ';background:' . esc_attr( $b[2] ) . ';">' . esc_html( $b[0] ) . '</span>';
                break;

            case 'm24_action':
                if ( class_exists( 'M24_Offers' ) ) {
                    $url = add_query_arg( [ M24_Offers::QV_NEW => 1, 'from_inquiry' => (int) $post_id ], home_url( '/' ) );
                    echo '<a class="button button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Angebot erstellen &rarr;</a>';
                }
                break;
        }
    }

    /** Betrag: estimated_value_eur (source_meta) → sonst Summe der numerischen Positionspreise × Menge. */
    private static function inquiry_amount_fmt( $post_id ): string {
        $meta = json_decode( (string) get_post_meta( $post_id, '_m24_inquiry_source_meta', true ), true );
        if ( is_array( $meta ) && isset( $meta['estimated_value_eur'] ) && '' !== (string) $meta['estimated_value_eur'] ) {
            return number_format( (float) $meta['estimated_value_eur'], 2, ',', '.' ) . ' €';
        }
        $items = get_post_meta( $post_id, '_m24_items', true );
        $sum   = 0.0; $any = false;
        if ( is_array( $items ) ) {
            foreach ( $items as $it ) {
                $raw = trim( (string) ( $it['price'] ?? '' ) );
                if ( '' === $raw ) { continue; }
                if ( false !== strpos( $raw, ',' ) && false !== strpos( $raw, '.' ) ) { $raw = str_replace( '.', '', $raw ); $raw = str_replace( ',', '.', $raw ); }
                elseif ( false !== strpos( $raw, ',' ) ) { $raw = str_replace( ',', '.', $raw ); }
                $raw = preg_replace( '/[^0-9.\-]/', '', $raw );
                if ( is_numeric( $raw ) ) { $sum += (float) $raw * max( 1, (int) ( $it['qty'] ?? 1 ) ); $any = true; }
            }
        }
        return $any ? number_format( $sum, 2, ',', '.' ) . ' €' : '—';
    }

    /** Garagen-Nr. über die E-Mail → WP-Konto → M24_Garage_Cart::garage_no; sonst „—". */
    private static function inquiry_garage_no( $post_id ): string {
        if ( ! class_exists( 'M24_Garage_Cart' ) ) { return '—'; }
        $email = (string) get_post_meta( $post_id, '_m24_email', true );
        if ( '' === $email || ! is_email( $email ) ) { return '—'; }
        $u = get_user_by( 'email', $email );
        if ( ! $u ) { return '—'; }
        $gno = M24_Garage_Cart::garage_no( (int) $u->ID, false );
        return '' !== $gno ? $gno : '—';
    }

    /** Sortierbare Spalten. */
    public static function admin_sortable_columns( $columns ) {
        $columns['m24_items'] = '_m24_item_count';
        return $columns;
    }

    /* ── Paket H: Inbox-Karten (M24 → Anfragen) + Status-Workflow ─────────── */

    public static function admin_menu_inbox() {
        add_submenu_page( 'm24-plattform', 'Anfragen', 'Anfragen', 'manage_options', 'm24-anfragen', [ __CLASS__, 'render_inbox_page' ] );
    }

    /** Anfrage als beantwortet markieren (aus einem erstellten Angebot). */
    public static function mark_answered( int $inquiry_id, string $offer_no, string $token ): void {
        if ( $inquiry_id <= 0 || self::CPT_SLUG !== get_post_type( $inquiry_id ) ) { return; }
        update_post_meta( $inquiry_id, '_m24_answered_offer_no', sanitize_text_field( $offer_no ) );
        update_post_meta( $inquiry_id, '_m24_answered_token', preg_replace( '/[^a-f0-9]/', '', $token ) );
    }

    public static function render_inbox_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $f = isset( $_GET['f'] ) ? sanitize_key( wp_unslash( $_GET['f'] ) ) : 'offen';          // phpcs:ignore WordPress.Security.NonceVerification
        $s = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        // Die Anfrage-CPT nutzt eigene Post-Status mit exclude_from_search=true (pending_api_push, synced, …).
        // WP_Query 'any' ÜBERSPRINGT genau diese → Inbox bliebe leer. Darum die Status explizit auflisten.
        $stati = array( 'publish', 'pending', 'draft', 'private' );
        if ( class_exists( 'M24_Inquiries' ) ) {
            $stati = array_merge( $stati, array( M24_Inquiries::STATUS_PENDING, M24_Inquiries::STATUS_SYNCED, M24_Inquiries::STATUS_SYNCED_MAIL, M24_Inquiries::STATUS_FAILED ) );
        }
        $q = new WP_Query( [ 'post_type' => self::CPT_SLUG, 'post_status' => $stati, 'posts_per_page' => 300, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ] );
        $cards = array(); $offen_n = 0;
        foreach ( $q->posts as $p ) {
            $answered = (string) get_post_meta( $p->ID, '_m24_answered_offer_no', true );
            if ( '' === $answered ) { $offen_n++; }
            $vor   = (string) get_post_meta( $p->ID, '_m24_vorname', true );
            $nach  = (string) get_post_meta( $p->ID, '_m24_nachname', true );
            $firma = (string) get_post_meta( $p->ID, '_m24_firma', true );
            $email = (string) get_post_meta( $p->ID, '_m24_email', true );
            $name  = trim( $vor . ' ' . $nach ); if ( '' === $name ) { $name = '' !== $firma ? $firma : $email; }
            $gno   = self::inquiry_garage_no( $p->ID );
            $hay   = mb_strtolower( $name . ' ' . $email . ' ' . $gno );
            if ( '' !== $s && false === mb_strpos( $hay, mb_strtolower( $s ) ) ) { continue; }
            if ( 'offen' === $f && '' !== $answered ) { continue; }
            if ( 'beantwortet' === $f && '' === $answered ) { continue; }
            $cards[] = array( 'p' => $p, 'name' => $name, 'firma' => $firma, 'email' => $email, 'gno' => $gno, 'answered' => $answered );
        }

        echo '<div class="wrap m24inbox"><h1>Anfragen</h1>';
        echo '<style>'
            . '.m24inbox .flt{display:flex;gap:10px;margin:14px 0 18px;flex-wrap:wrap;align-items:center}'
            . '.m24inbox .chip{padding:7px 14px;border-radius:999px;border:1.5px solid #e5e7eb;background:#fff;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:#111417}'
            . '.m24inbox .chip.on{background:#0e447e;border-color:#0e447e;color:#fff}'
            . '.m24inbox .srch{margin-left:auto;display:flex;gap:6px}.m24inbox .srch input{height:34px;border:1.5px solid #e5e7eb;border-radius:8px;padding:0 12px;min-width:220px}'
            . '.m24inbox .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:14px;overflow:hidden;max-width:1000px}'
            . '.m24inbox .crow{display:flex;align-items:center;gap:16px;padding:16px 18px;flex-wrap:wrap}'
            . '.m24inbox .av{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;display:grid;place-items:center;font-weight:800;font-size:15px;flex:0 0 auto}'
            . '.m24inbox .who b{font-size:15px}.m24inbox .who div{color:#6b7280;font-size:12.5px}'
            . '.m24inbox .meta{margin-left:auto;display:flex;align-items:center;gap:18px}'
            . '.m24inbox .g{font-family:\'Saira Condensed\',Saira,sans-serif;font-weight:700;color:#9a6b25;font-size:14px}'
            . '.m24inbox .sum{font-weight:800;font-size:16px}'
            . '.m24inbox .badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px}'
            . '.m24inbox .badge.pending{background:#fef3c7;color:#b45309}.m24inbox .badge.done{background:#d1fae5;color:#0e7a3b}.m24inbox .badge.done a{color:#0e7a3b;text-decoration:none}'
            . '.m24inbox .tgl{display:flex;align-items:center;gap:8px;padding:10px 18px;border-top:1px dashed #e5e7eb;color:#0e447e;font-size:13px;font-weight:600;cursor:pointer;user-select:none}'
            . '.m24inbox .car{transition:.2s;display:inline-block}.m24inbox .card.open .car{transform:rotate(90deg)}'
            . '.m24inbox .pos-wrap{display:none;border-top:1px solid #e5e7eb;background:#fbfcfe}.m24inbox .card.open .pos-wrap{display:block}'
            . '.m24inbox .pos{display:flex;gap:12px;align-items:center;padding:10px 18px;border-bottom:1px solid #eef1f5;font-size:13.5px}'
            . '.m24inbox .pos .ph{width:52px;height:38px;border-radius:6px;background:#e5e7eb;flex:0 0 auto}'
            . '.m24inbox .pos .pn{font-weight:600}.m24inbox .pos .pa{color:#6b7280;font-size:12px}.m24inbox .pos .pp{margin-left:auto;font-weight:700;white-space:nowrap}'
            . '.m24inbox .msg{padding:10px 18px;color:#6b7280;font-size:13px;font-style:italic}'
            . '.m24inbox .foot{display:flex;gap:10px;padding:14px 18px;justify-content:flex-end;background:#fff}'
            . '.m24inbox .b{border:0;border-radius:9px;padding:10px 16px;font-weight:700;font-size:13.5px;cursor:pointer;text-decoration:none;display:inline-block}'
            . '.m24inbox .b.blue{background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff}.m24inbox .b.ghost{background:#fff;border:1.5px solid #e5e7eb;color:#111417}'
            . '@media(max-width:700px){.m24inbox .meta{width:100%;margin-left:58px}}'
            . '</style>';

        // Filterleiste.
        $base = admin_url( 'admin.php?page=m24-anfragen' );
        $chip = function ( $key, $label ) use ( $f, $base, $s ) {
            $url = add_query_arg( array( 'f' => $key, 's' => $s ), $base );
            return '<a class="chip' . ( $f === $key ? ' on' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        };
        echo '<div class="flt">'
            . $chip( 'offen', 'Offen (' . (int) $offen_n . ')' )
            . $chip( 'beantwortet', 'Beantwortet' )
            . $chip( 'alle', 'Alle' )
            . '<form class="srch" method="get"><input type="hidden" name="page" value="m24-anfragen"><input type="hidden" name="f" value="' . esc_attr( $f ) . '">'
            . '<input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="Name, E-Mail oder G-Nr"><button class="button">Suchen</button></form>'
            . '</div>';

        if ( empty( $cards ) ) { echo '<p>Keine Anfragen' . ( '' !== $s ? ' zur Suche' : ' in dieser Ansicht' ) . '.</p></div>'; return; }

        foreach ( $cards as $c ) {
            $p = $c['p'];
            $ini = ''; foreach ( array_slice( array_values( array_filter( explode( ' ', $c['name'] ) ) ), 0, 2 ) as $w ) { $ini .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) ); }
            if ( '' === $ini ) { $ini = 'K'; }
            $biz  = (string) get_post_meta( $p->ID, '_m24_biz', true );
            $kt   = ( '1' === $biz ) ? 'B2B' : 'B2C';
            $land = self::inquiry_garage_no( $p->ID ); // placeholder not used
            $land_nm = function_exists( 'm24_inquiry_country_name' ) ? m24_inquiry_country_name( (string) get_post_meta( $p->ID, '_m24_land', true ) ) : (string) get_post_meta( $p->ID, '_m24_land', true );
            $date = get_the_date( 'd.m. H:i', $p );
            $items = get_post_meta( $p->ID, '_m24_items', true ); $items = is_array( $items ) ? $items : array();
            $cnt   = (int) get_post_meta( $p->ID, '_m24_item_count', true ); if ( ! $cnt ) { $cnt = count( $items ); }
            $msg   = trim( (string) $p->post_content );
            $create = add_query_arg( array( 'm24_offer_new' => 1, 'from_inquiry' => $p->ID ), home_url( '/' ) );
            $atok   = (string) get_post_meta( $p->ID, '_m24_answered_token', true );
            $view   = ( '' !== $atok && class_exists( 'M24_Offers' ) ) ? M24_Offers::view_url( $atok ) : '';

            echo '<div class="card">';
            echo '<div class="crow"><div class="av">' . esc_html( $ini ) . '</div>'
                . '<div class="who"><b>' . esc_html( $c['name'] ) . '</b><div>' . esc_html( $c['email'] ) . ' · ' . esc_html( $kt ) . ' · ' . esc_html( '' !== $land_nm ? $land_nm : '—' ) . ' · ' . esc_html( $date ) . '</div></div>'
                . '<div class="meta">' . ( '' !== $c['gno'] && '—' !== $c['gno'] ? '<span class="g">' . esc_html( $c['gno'] ) . '</span>' : '' );
            if ( '' !== $c['answered'] ) {
                echo '<span class="badge done">' . ( '' !== $view ? '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">Beantwortet → ' . esc_html( $c['answered'] ) . '</a>' : 'Beantwortet → ' . esc_html( $c['answered'] ) ) . '</span>';
            } else {
                echo '<span class="badge pending">Offen</span>';
            }
            echo '<span class="sum">' . esc_html( self::inquiry_amount_fmt( $p->ID ) ) . '</span></div></div>';

            echo '<div class="tgl" onclick="this.closest(\'.card\').classList.toggle(\'open\')"><span class="car">▶</span> ' . (int) $cnt . ' Position' . ( 1 === $cnt ? '' : 'en' ) . '</div>';
            echo '<div class="pos-wrap">';
            foreach ( $items as $it ) {
                $it = (array) $it;
                echo '<div class="pos"><span class="ph"></span><div><div class="pn">' . esc_html( (string) ( $it['art'] ?? '' ) ) . '</div>'
                    . '<div class="pa">' . ( ! empty( $it['src_art_nr'] ) ? 'Art.-Nr. ' . esc_html( (string) $it['src_art_nr'] ) . ' · ' : '' ) . '×' . (int) ( $it['qty'] ?? 1 ) . '</div></div>'
                    . '<div class="pp">' . esc_html( '' !== (string) ( $it['price'] ?? '' ) ? (string) $it['price'] . ' €' : 'auf Anfrage' ) . '</div></div>';
            }
            if ( '' !== $msg ) { echo '<div class="msg">Nachricht: „' . esc_html( $msg ) . '"</div>'; }
            echo '</div>';

            echo '<div class="foot">';
            if ( '' !== $c['answered'] && '' !== $view ) {
                echo '<a class="b ghost" href="' . esc_url( $view ) . '" target="_blank" rel="noopener">Angebot ansehen</a>';
            } else {
                if ( '' !== $msg ) { echo '<button type="button" class="b ghost" onclick="this.closest(\'.card\').classList.add(\'open\')">Nachricht lesen</button>'; }
                echo '<a class="b blue" href="' . esc_url( $create ) . '" target="_blank" rel="noopener">Angebot erstellen →</a>';
            }
            echo '</div></div>';
        }
        echo '</div>';
    }
}
