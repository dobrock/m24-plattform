<?php
/**
 * M24 Plattform — Admin-Seite „Kundenkonten" (vormals „Händler").
 *
 * Zeigt ALLE Kundenkonten (nicht nur B2B/Händler): reguläre Magic-Link-/Alert-Konten UND B2B-Händler,
 * Admin-/Team-Rollen ausgeschlossen. Design im M24-Übersichts-Stil (Karten/Chips/Suche, KEINE WP_List_Table).
 *
 * Indikatoren je Konto (gebündelt geladen, kein N+1): Firma/Ansprechpartner, E-Mail/Telefon, Land,
 * USt-IdNr/VIES, Status, Registriert, Garage, Aktivität (Last-Login), Fahrzeug-Interesse, Newsletter, Herkunft.
 *
 * B2B-Freigabe/Ablehnung/VIES-Neuprüfung bleibt erhalten (admin-post, PRG, Nonce). Datenquelle Händler:
 * {prefix}m24_haendler ⋈ wp_users ⋈ usermeta. Last-Login/Herkunft: M24_User_Activity-Metas (historisch leer).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Haendler_Page {

    const PAGE_SLUG  = 'm24-haendler';
    const CAPABILITY = 'manage_options';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
        add_action( 'admin_post_m24_haendler_approve', array( __CLASS__, 'handle_approve' ) );
        add_action( 'admin_post_m24_haendler_reject', array( __CLASS__, 'handle_reject' ) );
        add_action( 'admin_post_m24_haendler_vies', array( __CLASS__, 'handle_vies' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    public static function register_menu() {
        add_submenu_page( 'm24-plattform', __( 'Kundenkonten', 'm24-plattform' ), __( 'Kundenkonten', 'm24-plattform' ), self::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
    }

    public static function assets( $hook ) {
        if ( is_string( $hook ) && false !== strpos( $hook, self::PAGE_SLUG ) ) {
            wp_enqueue_style( 'm24fz-saira', plugins_url( 'assets/fonts/saira.css', M24_PLATTFORM_FILE ), array(), null );
        }
    }

    /** Ablehngründe (Schlüssel → DE-Label) für Dropdown + notes_intern. Mail nutzt Empfänger-Sprache. */
    public static function reject_reasons(): array {
        return class_exists( 'M24_I18n' ) ? M24_I18n::reject_reasons( 'de' ) : array( 'sonstiges' => 'Sonstiges' );
    }

    private static function url(): string {
        return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
    }

    /* ── Daten: ALLE Kundenkonten inkl. Indikatoren, gebündelt (kein N+1) ─── */

    /**
     * @return array<int,array> Eine Zeile je Kundenkonto (Team/Admin ausgeschlossen), neu→alt, voll angereichert.
     */
    public static function customer_rows(): array {
        global $wpdb;

        // 1) Kundenkonten — Team/Admin-Rollen ausgeschlossen (filterbar).
        $staff_roles = apply_filters( 'm24_kk_staff_roles', array( 'administrator', 'editor', 'author', 'contributor', 'shop_manager' ) );
        $users = get_users( array(
            'role__not_in' => $staff_roles,
            'orderby'      => 'registered',
            'order'        => 'DESC',
            'number'       => 5000,
            'fields'       => 'all',
        ) );
        if ( empty( $users ) ) { return array(); }
        $ids = array_map( static function ( $u ) { return (int) $u->ID; }, $users );
        update_meta_cache( 'user', $ids ); // ein Query → alle User-Metas im Cache (get_user_meta danach gratis)
        $in = implode( ',', array_map( 'intval', $ids ) );

        // 2) Händler-Datensätze (Firma/USt/VIES/Status) gebündelt.
        $ht    = M24_Database::table( 'haendler' );
        $hrows = $wpdb->get_results( "SELECT * FROM $ht WHERE wp_user_id IN ($in)" ); // phpcs:ignore WordPress.DB
        $hmap  = array();
        foreach ( (array) $hrows as $h ) { $hmap[ (int) $h->wp_user_id ] = $h; }

        // 3) Garage-Positionen gebündelt — ein GROUP BY statt count_positions() je Zeile.
        $gt    = M24_Garage_Cart::table();
        $grows = $wpdb->get_results( "SELECT account_id, COUNT(*) c FROM $gt WHERE account_id IN ($in) GROUP BY account_id", ARRAY_A ); // phpcs:ignore WordPress.DB
        $gmap  = array();
        foreach ( (array) $grows as $g ) { $gmap[ (int) $g['account_id'] ] = (int) $g['c']; }

        // 4) Fahrzeug-Interesse: alle beobachteten Fahrzeug-IDs EINMAL vorladen, Titel dann aus dem Post-Cache.
        $notify = array();
        $pids_all = array();
        foreach ( $ids as $uid ) {
            $n    = get_user_meta( $uid, M24_Garage_Cart::NOTIFY_META, true );
            $pids = ( is_array( $n ) && $n ) ? array_map( 'intval', array_keys( $n ) ) : array();
            $notify[ $uid ] = $pids;
            foreach ( $pids as $p ) { $pids_all[ $p ] = true; }
        }
        if ( $pids_all ) { _prime_post_caches( array_keys( $pids_all ), false, false ); } // ein Query für alle Titel

        // 5) Zeilen anreichern.
        $now      = time();
        $thresh   = 3 * MONTH_IN_SECONDS; // Aktivitäts-Schwelle: 3 Monate
        $out      = array();
        foreach ( $users as $u ) {
            $uid   = (int) $u->ID;
            $h     = $hmap[ $uid ] ?? null;
            $firma = $h ? trim( (string) $h->firma ) : '';
            $name  = trim( (string) $u->first_name . ' ' . (string) $u->last_name );
            $is_b2b = ( '' !== $firma ) || in_array( M24_B2B::ROLE, (array) $u->roles, true );

            $ll = (int) get_user_meta( $uid, M24_User_Activity::LOGIN_META, true );
            if ( $ll <= 0 )                     { $act = 'never'; }
            elseif ( ( $now - $ll ) < $thresh ) { $act = 'active'; }
            else                                { $act = 'inactive'; }

            $titles = array();
            foreach ( $notify[ $uid ] as $p ) {
                $tt = trim( (string) get_the_title( $p ) );
                if ( '' !== $tt ) { $titles[] = $tt; }
            }

            $disp = '' !== $firma ? $firma : ( '' !== $name ? $name : (string) $u->user_email );

            $out[] = array(
                'uid'        => $uid,
                'email'      => (string) $u->user_email,
                'name'       => $name,
                'display'    => $disp,
                'firma'      => $firma,
                'anrede'     => (string) get_user_meta( $uid, '_m24_anrede', true ),
                'telefon'    => (string) get_user_meta( $uid, '_m24_telefon', true ),
                'is_b2b'     => $is_b2b,
                'land'       => $h ? (string) $h->land : '',
                'ustid'      => $h ? (string) $h->uid : '',
                'uid_valid'  => $h ? $h->uid_valid : null,
                'uid_val_at' => $h ? (string) $h->uid_validated_at : '',
                'h_status'   => $h ? (string) $h->status : '',
                'notes'      => $h ? (string) $h->notes_intern : '',
                'garage'     => $gmap[ $uid ] ?? 0,
                'act'        => $act,
                'act_ts'     => $ll,
                'interest'   => $titles,
                'interest_n' => count( $notify[ $uid ] ),
                'newsletter' => '' !== (string) get_user_meta( $uid, '_m24_newsletter_optin', true ),
                'source'     => (string) get_user_meta( $uid, M24_User_Activity::SOURCE_META, true ),
                'registered' => (string) $u->user_registered,
            );
        }
        return $out;
    }

    /** Ein Konto gegen einen Filter-Chip prüfen. */
    private static function matches( array $r, string $f ): bool {
        switch ( $f ) {
            case 'b2b':        return (bool) $r['is_b2b'];
            case 'b2c':        return ! $r['is_b2b'];
            case 'garage':     return $r['garage'] > 0;
            case 'inaktiv':    return 'inactive' === $r['act'];
            case 'newsletter': return (bool) $r['newsletter'];
            case 'pending':    return in_array( $r['h_status'], array( 'pending_verification', 'verified' ), true );
            default:           return true; // Alle
        }
    }

    /* ── Mutationen (admin-post, PRG) — B2B-Freigabe unverändert ──────────── */

    private static function approve( int $uid ): void {
        global $wpdb;
        $wpdb->update(
            M24_Database::table( 'haendler' ),
            array( 'status' => 'approved', 'approved_by' => get_current_user_id(), 'approved_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ),
            array( 'wp_user_id' => $uid )
        );
        if ( class_exists( 'M24_B2B_Auth' ) ) {
            M24_B2B_Auth::send_approval_mail( $uid );
        }
    }

    public static function handle_approve() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) ); }
        $uid = (int) ( $_GET['user'] ?? 0 );
        check_admin_referer( 'm24_haendler_approve_' . $uid );
        if ( $uid ) { self::approve( $uid ); }
        wp_safe_redirect( add_query_arg( 'done', 'approved', self::url() ) );
        exit;
    }

    public static function handle_reject() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) ); }
        $uid = (int) ( $_POST['user'] ?? 0 );
        check_admin_referer( 'm24_haendler_reject_' . $uid );
        if ( ! $uid ) { wp_safe_redirect( self::url() ); exit; }

        $reasons = self::reject_reasons();
        $key     = sanitize_key( $_POST['grund'] ?? 'sonstiges' );
        $frei    = sanitize_textarea_field( wp_unslash( $_POST['freitext'] ?? '' ) );
        $notify  = ! empty( $_POST['notify'] );

        $text = isset( $reasons[ $key ] ) ? $reasons[ $key ] : 'Sonstiges';
        if ( 'sonstiges' === $key ) {
            $text = '' !== $frei ? $frei : 'Sonstiges';
        } elseif ( '' !== $frei ) {
            $text .= ' — ' . $frei;
        }

        global $wpdb;
        $note = '[' . current_time( 'Y-m-d H:i' ) . ' abgelehnt] ' . $text;
        $prev = (string) $wpdb->get_var( $wpdb->prepare( "SELECT notes_intern FROM " . M24_Database::table( 'haendler' ) . " WHERE wp_user_id = %d", $uid ) );
        $wpdb->update(
            M24_Database::table( 'haendler' ),
            array( 'status' => 'rejected', 'notes_intern' => trim( $note . ( '' !== $prev ? "\n" . $prev : '' ) ), 'updated_at' => current_time( 'mysql', true ) ),
            array( 'wp_user_id' => $uid )
        );
        if ( $notify && class_exists( 'M24_B2B_Auth' ) ) {
            M24_B2B_Auth::send_rejection_mail( $uid, $key, $frei ); // Mail lokalisiert (Empfänger-Sprache); notes_intern bleibt DE
        }
        wp_safe_redirect( add_query_arg( 'done', 'rejected', self::url() ) );
        exit;
    }

    public static function handle_vies() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) ); }
        $uid = (int) ( $_GET['user'] ?? 0 );
        check_admin_referer( 'm24_haendler_vies_' . $uid );
        if ( $uid && class_exists( 'M24_B2B_Auth' ) ) {
            global $wpdb;
            $t   = M24_Database::table( 'haendler' );
            $cur = $wpdb->get_row( $wpdb->prepare( "SELECT uid, uid_validated_at FROM $t WHERE wp_user_id = %d", $uid ) );
            if ( $cur && '' !== (string) $cur->uid ) {
                $r = M24_B2B_Auth::vies_check( (string) $cur->uid );
                if ( $r['checked'] ) {
                    $wpdb->update( $t,
                        array( 'uid_valid' => ( true === $r['valid'] ? 1 : 0 ), 'uid_validated_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ),
                        array( 'wp_user_id' => $uid )
                    );
                } else {
                    $wpdb->update( $t, array( 'uid_valid' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'wp_user_id' => $uid ) );
                }
            }
        }
        wp_safe_redirect( add_query_arg( 'done', 'vies', self::url() ) );
        exit;
    }

    /* ── Render (M24-Karten-Stil, keine WP_List_Table) ───────────────────── */

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
        }

        $all = self::customer_rows();
        $f   = isset( $_GET['f'] ) ? sanitize_key( wp_unslash( $_GET['f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $s   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $sl  = strtolower( trim( $s ) );

        // Chip-Zähler aus dem geladenen Set (kein Extra-Query).
        $cnt = array( '' => count( $all ), 'b2b' => 0, 'b2c' => 0, 'garage' => 0, 'inaktiv' => 0, 'newsletter' => 0, 'pending' => 0 );
        foreach ( $all as $r ) {
            foreach ( array( 'b2b', 'b2c', 'garage', 'inaktiv', 'newsletter', 'pending' ) as $k ) {
                if ( self::matches( $r, $k ) ) { $cnt[ $k ]++; }
            }
        }

        // Filtern (Chip + Suche über Name/E-Mail/Firma).
        $rows = array();
        foreach ( $all as $r ) {
            if ( ! self::matches( $r, $f ) ) { continue; }
            if ( '' !== $sl ) {
                $hay = strtolower( $r['display'] . ' ' . $r['name'] . ' ' . $r['firma'] . ' ' . $r['email'] );
                if ( false === strpos( $hay, $sl ) ) { continue; }
            }
            $rows[] = $r;
        }

        $reject_uid = isset( $_GET['reject'] ) ? (int) $_GET['reject'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
        ?>
        <div class="wrap m24kk">
            <h1 class="wp-heading-inline"><?php echo esc_html__( 'MOTORSPORT24 — Kundenkonten', 'm24-plattform' ); ?></h1>
            <hr class="wp-header-end">
            <?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — statisches CSS ?>

            <?php
            if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                $d   = sanitize_text_field( wp_unslash( $_GET['done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                $map = array(
                    'approved' => __( 'Händler freigegeben (E-Mail versendet).', 'm24-plattform' ),
                    'rejected' => __( 'Händler abgelehnt.', 'm24-plattform' ),
                    'vies'     => __( 'VIES neu geprüft.', 'm24-plattform' ),
                );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $d ] ?? __( 'Aktion ausgeführt.', 'm24-plattform' ) ) . '</p></div>';
            }
            if ( $reject_uid ) { self::render_reject_panel( $reject_uid ); }

            // Filter-Chips.
            $chip = function ( $key, $label, $n ) use ( $f, $s ) {
                $u = add_query_arg( array_filter( array( 'page' => self::PAGE_SLUG, 'f' => $key, 's' => $s ) ), admin_url( 'admin.php' ) );
                return '<a class="chip' . ( $f === $key ? ' on' : '' ) . '" href="' . esc_url( $u ) . '">' . esc_html( $label )
                    . ' <span class="n">' . (int) $n . '</span></a>';
            };
            echo '<div class="flt">';
            echo $chip( '', 'Alle', $cnt[''] ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo $chip( 'b2b', 'B2B', $cnt['b2b'] ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo $chip( 'b2c', 'B2C', $cnt['b2c'] ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo $chip( 'garage', 'mit Garage', $cnt['garage'] ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo $chip( 'inaktiv', 'inaktiv', $cnt['inaktiv'] ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo $chip( 'newsletter', 'Newsletter', $cnt['newsletter'] ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo $chip( 'pending', 'B2B wartet', $cnt['pending'] ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo '<form class="srch" method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '"><input type="hidden" name="f" value="' . esc_attr( $f ) . '"><input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr__( 'Name, E-Mail oder Firma', 'm24-plattform' ) . '"><button class="button">' . esc_html__( 'Suchen', 'm24-plattform' ) . '</button></form>';
            echo '</div>';

            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'Keine Kundenkonten', 'm24-plattform' ) . ( ( '' !== $f || '' !== $s ) ? ' ' . esc_html__( 'zum Filter', 'm24-plattform' ) : '' ) . '.</p></div>';
                return;
            }

            foreach ( $rows as $r ) { self::render_card( $r ); }
            ?>
            <script>(function(){document.addEventListener('click',function(e){var h=e.target.closest?e.target.closest('[data-kk-toggle]'):null;if(!h)return;if(e.target.closest('a,button'))return;var d=h.parentNode&&h.parentNode.querySelector('.kk-det');if(!d)return;var hid=d.hasAttribute('hidden');if(hid){d.removeAttribute('hidden');}else{d.setAttribute('hidden','');}h.setAttribute('aria-expanded',hid?'true':'false');});})();</script>
        </div>
        <?php
    }

    private static function render_card( array $r ): void {
        $uid  = (int) $r['uid'];
        $edit = get_edit_user_link( $uid );

        // Initialen.
        $ini = '';
        foreach ( array_slice( array_values( array_filter( explode( ' ', $r['display'] ) ) ), 0, 2 ) as $w ) {
            $ini .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) );
        }
        if ( '' === $ini ) { $ini = 'K'; }

        $flag = ( '' !== $r['land'] && class_exists( 'M24_Country_Flags' ) ) ? M24_Country_Flags::getFlagAndCountry( $r['land'] ) : '';
        $land = '' !== $r['land'] ? ( class_exists( 'M24_B2B_Auth' ) ? M24_B2B_Auth::country_name( $r['land'] ) : $r['land'] ) : '';
        $typ  = $r['is_b2b'] ? 'B2B' : 'B2C';

        // Status-Badge: B2B → Händler-Status; B2C → „Kunde".
        $sb = array(
            'pending_verification' => array( 'Wartet (E-Mail)', '#b87000', '#fdf5e6' ),
            'verified'             => array( 'Verifiziert', '#1a5fb4', '#eef3fb' ),
            'approved'             => array( 'Freigegeben', '#1a7a3c', '#edf7f1' ),
            'rejected'             => array( 'Abgelehnt', '#8a93a0', '#f1f2f4' ),
        );
        $st = $sb[ $r['h_status'] ] ?? array( 'Kunde', '#5a6474', '#eef0f3' );

        $reg = '' !== $r['registered'] ? mysql2date( 'd.m.Y', get_date_from_gmt( $r['registered'] ) ) : '—';

        echo '<div class="card">';
        echo '<div class="crow" data-kk-toggle aria-expanded="false" role="button" tabindex="0">';
        echo '<div class="av">' . esc_html( $ini ) . '</div>';
        echo '<div class="who"><b>' . esc_html( $r['display'] ) . '</b>'
            . ' <span class="typ ' . ( $r['is_b2b'] ? 'b2b' : 'b2c' ) . '">' . esc_html( $typ ) . '</span>'
            . ( '' !== $flag ? ' <span class="flagc">' . esc_html( $flag ) . '</span>' : '' )
            . '<div>' . esc_html( $r['email'] ) . ( '' !== $land && '' === $flag ? ' · ' . esc_html( $land ) : '' )
            . ' · ' . esc_html__( 'registriert', 'm24-plattform' ) . ' ' . esc_html( $reg ) . '</div></div>';
        echo '<div class="meta"><span class="badge" style="color:' . esc_attr( $st[1] ) . ';background:' . esc_attr( $st[2] ) . ';">' . esc_html( $st[0] ) . '</span></div>';
        echo '</div>';

        // Indikator-Pillen.
        echo '<div class="kk-ind">';
        // Garage
        if ( $r['garage'] > 0 ) {
            $g = sprintf( _n( 'Ja (%d Position)', 'Ja (%d Positionen)', $r['garage'], 'm24-plattform' ), (int) $r['garage'] );
            echo '<span class="pill on"><i>Garage</i>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $g ) . '</a>' : esc_html( $g ) ) . '</span>';
        } else {
            echo '<span class="pill"><i>Garage</i>–</span>';
        }
        // Aktivität
        if ( 'active' === $r['act'] ) {
            echo '<span class="pill on"><i>Aktivität</i>' . esc_html__( 'aktiv', 'm24-plattform' ) . '</span>';
        } elseif ( 'inactive' === $r['act'] ) {
            echo '<span class="pill warn"><i>Aktivität</i>' . esc_html( sprintf( __( 'inaktiv seit %s', 'm24-plattform' ), mysql2date( 'd.m.Y', gmdate( 'Y-m-d H:i:s', (int) $r['act_ts'] ) ) ) ) . '</span>';
        } else {
            echo '<span class="pill"><i>Aktivität</i>' . esc_html__( 'nie eingeloggt', 'm24-plattform' ) . '</span>';
        }
        // Fahrzeug-Interesse
        if ( $r['interest_n'] > 0 ) {
            $lbl = '' !== implode( '', $r['interest'] )
                ? 'Ja: ' . implode( ', ', array_slice( $r['interest'], 0, 2 ) ) . ( count( $r['interest'] ) > 2 ? ' +' . ( count( $r['interest'] ) - 2 ) : '' )
                : sprintf( _n( 'Ja: %d Fahrzeug', 'Ja: %d Fahrzeuge', $r['interest_n'], 'm24-plattform' ), (int) $r['interest_n'] );
            echo '<span class="pill on"><i>Fahrzeug-Interesse</i>' . esc_html( $lbl ) . '</span>';
        } else {
            echo '<span class="pill"><i>Fahrzeug-Interesse</i>–</span>';
        }
        // Newsletter
        echo '<span class="pill' . ( $r['newsletter'] ? ' on' : '' ) . '"><i>Newsletter</i>' . ( $r['newsletter'] ? esc_html__( 'Ja', 'm24-plattform' ) : esc_html__( 'Nein', 'm24-plattform' ) ) . '</span>';
        // Herkunft
        echo '<span class="pill"><i>Herkunft</i>' . esc_html( M24_User_Activity::source_label( $r['source'] ) ) . '</span>';
        echo '</div>';

        // Detail (aufklappbar): Ansprechpartner/Telefon + USt/VIES + Notiz.
        $det = array();
        if ( '' !== $r['name'] || '' !== $r['anrede'] ) {
            $det[] = '<div class="dl"><span>' . esc_html__( 'Ansprechpartner', 'm24-plattform' ) . '</span>' . esc_html( trim( $r['anrede'] . ' ' . $r['name'] ) ?: '—' ) . '</div>';
        }
        if ( '' !== $r['telefon'] ) {
            $det[] = '<div class="dl"><span>' . esc_html__( 'Telefon', 'm24-plattform' ) . '</span>' . esc_html( $r['telefon'] ) . '</div>';
        }
        if ( '' !== $r['ustid'] ) {
            $v = $r['uid_valid'];
            if ( null === $v || '' === $v ) { $vb = '<span class="vies na">— ungeprüft</span>'; }
            elseif ( (int) $v === 1 )       { $vb = '<span class="vies ok">✓ gültig</span>'; }
            else                            { $vb = '<span class="vies bad">✗ ungültig</span>'; }
            $when = '' !== $r['uid_val_at'] ? ' <em>' . esc_html( mysql2date( 'd.m.Y H:i', $r['uid_val_at'] ) ) . '</em>' : '';
            $det[] = '<div class="dl"><span>' . esc_html__( 'USt-IdNr. / VIES', 'm24-plattform' ) . '</span><code>' . esc_html( $r['ustid'] ) . '</code> ' . $vb . $when . '</div>';
        }
        if ( '' !== $r['notes'] ) {
            $det[] = '<div class="dl note"><span>' . esc_html__( 'Notiz (intern)', 'm24-plattform' ) . '</span>' . esc_html( mb_strimwidth( wp_strip_all_tags( $r['notes'] ), 0, 220, '…' ) ) . '</div>';
        }
        if ( $det ) {
            echo '<div class="kk-det" hidden>' . implode( '', $det ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput — Teile oben escaped
        }

        // Footer-Aktionen.
        echo '<div class="foot">';
        if ( $edit ) { echo '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Konto bearbeiten', 'm24-plattform' ) . '</a>'; }
        echo '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html__( 'E-Mail', 'm24-plattform' ) . '</a>';
        if ( $r['is_b2b'] && '' !== $r['h_status'] ) {
            $base = admin_url( 'admin-post.php' );
            if ( 'approved' !== $r['h_status'] ) {
                $approve = wp_nonce_url( add_query_arg( array( 'action' => 'm24_haendler_approve', 'user' => $uid ), $base ), 'm24_haendler_approve_' . $uid );
                echo '<a href="' . esc_url( $approve ) . '" style="color:#1a7a3c;font-weight:700;" onclick="return confirm(\'' . esc_js( __( 'Diesen Händler freigeben?', 'm24-plattform' ) ) . '\');">' . esc_html__( 'Freigeben', 'm24-plattform' ) . '</a>';
            }
            echo '<a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'reject' => $uid ), admin_url( 'admin.php' ) ) ) . '" style="color:#b32d2e;">' . esc_html__( 'Ablehnen', 'm24-plattform' ) . '</a>';
            if ( '' !== $r['ustid'] ) {
                $vies = wp_nonce_url( add_query_arg( array( 'action' => 'm24_haendler_vies', 'user' => $uid ), $base ), 'm24_haendler_vies_' . $uid );
                echo '<a href="' . esc_url( $vies ) . '">' . esc_html__( 'VIES neu prüfen', 'm24-plattform' ) . '</a>';
            }
        }
        echo '</div></div>';
    }

    private static function render_reject_panel( int $uid ): void {
        global $wpdb;
        $h = $wpdb->get_row( $wpdb->prepare( "SELECT firma FROM " . M24_Database::table( 'haendler' ) . " WHERE wp_user_id = %d", $uid ) );
        if ( ! $h ) { return; }
        ?>
        <div class="m24kk-reject">
            <h2 style="margin-top:0"><?php echo esc_html( sprintf( __( '„%s" ablehnen', 'm24-plattform' ), $h->firma ) ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="m24_haendler_reject">
                <input type="hidden" name="user" value="<?php echo (int) $uid; ?>">
                <?php wp_nonce_field( 'm24_haendler_reject_' . $uid ); ?>
                <label for="m24h-grund"><?php esc_html_e( 'Grund', 'm24-plattform' ); ?></label>
                <select id="m24h-grund" name="grund">
                    <?php foreach ( self::reject_reasons() as $k => $t ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $t ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="m24h-frei"><?php esc_html_e( 'Freitext (optional, wird dem Händler im Grund mitgeteilt)', 'm24-plattform' ); ?></label>
                <textarea id="m24h-frei" name="freitext" rows="2"></textarea>
                <p style="margin-top:12px"><label style="font-weight:400"><input type="checkbox" id="m24h-notify" name="notify" value="1" checked> <?php esc_html_e( 'Händler per Mail informieren', 'm24-plattform' ); ?></label></p>
                <p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Ablehnen', 'm24-plattform' ); ?></button>
                    <a href="<?php echo esc_url( self::url() ); ?>" class="button"><?php esc_html_e( 'Abbrechen', 'm24-plattform' ); ?></a>
                </p>
            </form>
            <script>
            (function(){var g=document.getElementById('m24h-grund'),n=document.getElementById('m24h-notify');
            if(g&&n){g.addEventListener('change',function(){ if(g.value==='missbrauch'){n.checked=false;} });}})();
            </script>
        </div>
        <?php
    }

    private static function css(): string {
        return '<style>'
            . '.m24kk .flt{display:flex;gap:10px;margin:14px 0 18px;flex-wrap:wrap;align-items:center}'
            . '.m24kk .chip{padding:7px 14px;border-radius:999px;border:1.5px solid #e5e7eb;background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:#111417}'
            . '.m24kk .chip.on{background:#0e447e;border-color:#0e447e;color:#fff}'
            . '.m24kk .chip .n{opacity:.65;font-weight:700;margin-left:2px}'
            . '.m24kk .srch{margin-left:auto;display:flex;gap:6px}'
            . '.m24kk .srch input{height:34px;border:1.5px solid #e5e7eb;border-radius:8px;padding:0 12px;min-width:240px}'
            . '.m24kk .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:14px;max-width:1000px;padding:16px 18px}'
            . '.m24kk .crow{display:flex;align-items:center;gap:16px;flex-wrap:wrap;cursor:pointer}'
            . '.m24kk .av{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;display:grid;place-items:center;font-weight:800;font-size:15px;flex:0 0 auto}'
            . '.m24kk .who b{font-size:15px;margin-right:6px}.m24kk .who>div{color:#6b7280;font-size:12.5px;margin-top:2px}'
            . '.m24kk .typ{font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;vertical-align:middle}'
            . '.m24kk .typ.b2b{background:#eef3fb;color:#1a5fb4}.m24kk .typ.b2c{background:#f3eefb;color:#6b3fb4}'
            . '.m24kk .flagc{font-size:13px;color:#374151}'
            . '.m24kk .meta{margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:flex-end}'
            . '.m24kk .badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px}'
            . '.m24kk .kk-ind{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}'
            . '.m24kk .pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#5a6474;background:#f4f6f8;border:1px solid #e6e9ee;border-radius:8px;padding:5px 10px}'
            . '.m24kk .pill i{font-style:normal;font-weight:700;color:#9aa3af;font-size:10.5px;text-transform:uppercase;letter-spacing:.03em}'
            . '.m24kk .pill a{color:inherit;text-decoration:underline}'
            . '.m24kk .pill.on{background:#edf7f1;border-color:#cdeed9;color:#1a7a3c}'
            . '.m24kk .pill.on i{color:#57ab77}'
            . '.m24kk .pill.warn{background:#fdf5e6;border-color:#f0e0bf;color:#b87000}.m24kk .pill.warn i{color:#c9a34e}'
            . '.m24kk .kk-det{margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb;display:flex;flex-direction:column;gap:6px}'
            . '.m24kk .kk-det .dl{font-size:13px;color:#374151}.m24kk .kk-det .dl span{display:inline-block;min-width:150px;color:#8a929c;font-weight:600}'
            . '.m24kk .kk-det .dl.note{color:#8a929c}.m24kk .kk-det code{background:#f4f6f8;padding:1px 6px;border-radius:4px}'
            . '.m24kk .vies.ok{color:#1a7a3c;font-weight:700}.m24kk .vies.bad{color:#c8102e;font-weight:700}.m24kk .vies.na{color:#8a93a0}.m24kk .vies em{color:#8a93a0;font-style:normal}'
            . '.m24kk .foot{display:flex;gap:14px;margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb;font-size:13px;flex-wrap:wrap}.m24kk .foot a{text-decoration:none}'
            . '.m24kk-reject{background:#fff;border:1px solid #e6e9ee;border-left:4px solid #c8102e;border-radius:8px;padding:18px 20px;margin:14px 0}'
            . '.m24kk-reject label{display:block;font-weight:600;margin:10px 0 4px}'
            . '.m24kk-reject select,.m24kk-reject textarea{width:100%;max-width:520px;padding:8px 10px;border:1px solid #d6dae0;border-radius:6px}'
            . '@media(max-width:700px){.m24kk .meta{width:100%;margin-left:58px;justify-content:flex-start}.m24kk .kk-det .dl span{min-width:0;display:block}}'
            . '</style>';
    }
}
