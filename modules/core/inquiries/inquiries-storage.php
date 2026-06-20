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
        add_filter( 'manage_' . self::CPT_SLUG . '_posts_columns',        [ __CLASS__, 'admin_columns' ] );
        add_action( 'manage_' . self::CPT_SLUG . '_posts_custom_column',  [ __CLASS__, 'admin_column_content' ], 10, 2 );
        add_filter( 'manage_edit-' . self::CPT_SLUG . '_sortable_columns', [ __CLASS__, 'admin_sortable_columns' ] );

        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_storage', 'Storage-Modul geladen' );
        }
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
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            // Source + Items direkt nach Title einfügen
            if ( 'title' === $key ) {
                $new['m24_source'] = __( 'Quelle', 'm24-plattform' );
                $new['m24_items']  = __( 'Positionen', 'm24-plattform' );
            }
        }
        return $new;
    }

    /**
     * Rendert den Inhalt für eigene Admin-Spalten.
     */
    public static function admin_column_content( $column, $post_id ) {
        if ( 'm24_source' === $column ) {
            $source = get_post_meta( $post_id, '_m24_inquiry_source', true );
            $labels = [
                M24_Inquiries::SOURCE_CART    => __( 'Sammelanfrage', 'm24-plattform' ),
                M24_Inquiries::SOURCE_PRODUCT => __( 'Direktanfrage', 'm24-plattform' ),
                M24_Inquiries::SOURCE_CONTACT => __( 'Kontaktformular', 'm24-plattform' ),
                M24_Inquiries::SOURCE_BLOG    => __( 'Blog-Anfrage', 'm24-plattform' ),
            ];
            $label = isset( $labels[ $source ] ) ? $labels[ $source ] : ( $source ?: '—' );

            // Tooltip mit den ersten Source-Meta-Hinweisen (cart_session_id-Kürzel etc.)
            $meta_raw = get_post_meta( $post_id, '_m24_inquiry_source_meta', true );
            $tooltip  = '';
            if ( ! empty( $meta_raw ) ) {
                $meta = json_decode( (string) $meta_raw, true );
                if ( is_array( $meta ) ) {
                    $bits = [];
                    if ( ! empty( $meta['cart_session_id'] ) ) {
                        $bits[] = 'Session: ' . substr( (string) $meta['cart_session_id'], 0, 8 );
                    }
                    if ( isset( $meta['estimated_value_eur'] ) ) {
                        $bits[] = 'Wert: ' . number_format( (float) $meta['estimated_value_eur'], 2, ',', '.' ) . ' €';
                    }
                    if ( ! empty( $meta['src_url'] ) ) {
                        $bits[] = 'URL: ' . $meta['src_url'];
                    }
                    if ( ! empty( $meta['form_url'] ) ) {
                        $bits[] = 'URL: ' . $meta['form_url'];
                    }
                    if ( ! empty( $meta['blog_post_id'] ) ) {
                        $bits[] = 'Blog-Post: ' . (int) $meta['blog_post_id'];
                    }
                    $tooltip = implode( ' · ', $bits );
                }
            }

            echo esc_html( $label );
            if ( $tooltip !== '' ) {
                echo '<br><small style="color:#666;">' . esc_html( $tooltip ) . '</small>';
            }
            return;
        }

        if ( 'm24_items' === $column ) {
            $count = (int) get_post_meta( $post_id, '_m24_item_count', true );
            echo esc_html( $count );
            return;
        }
    }

    /**
     * Source-Spalte sortierbar machen.
     */
    public static function admin_sortable_columns( $columns ) {
        $columns['m24_source'] = '_m24_inquiry_source';
        $columns['m24_items']  = '_m24_item_count';
        return $columns;
    }
}
