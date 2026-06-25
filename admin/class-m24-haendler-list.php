<?php
/**
 * M24 Plattform — Admin-Seite „Händler" (Garage A3).
 *
 * Sichten, Freigeben, (begründet) Ablehnen + VIES-Neuprüfung. Datenquelle: {prefix}m24_haendler
 * ⋈ wp_users ⋈ usermeta. Mutationen via admin-post (PRG), cap manage_options + Nonce je Aktion.
 * Mails (Freigabe/Ablehnung) über M24_B2B_Auth (gleiches CI-Gerüst wie die Magic-Mail).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Haendler_Page {

    const PAGE_SLUG  = 'm24-haendler';
    const CAPABILITY = 'manage_options';
    const PER_PAGE   = 30;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
        add_action( 'admin_post_m24_haendler_approve', array( __CLASS__, 'handle_approve' ) );
        add_action( 'admin_post_m24_haendler_reject', array( __CLASS__, 'handle_reject' ) );
        add_action( 'admin_post_m24_haendler_vies', array( __CLASS__, 'handle_vies' ) );
        add_action( 'admin_post_m24_haendler_bulk_approve', array( __CLASS__, 'handle_bulk_approve' ) );
    }

    public static function register_menu() {
        add_submenu_page( 'm24-plattform', __( 'Händler', 'm24-plattform' ), __( 'Händler', 'm24-plattform' ), self::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
    }

    /** Ablehngründe (Schlüssel → Text). Geteilt von Formular und Handler. */
    public static function reject_reasons(): array {
        return array(
            'gewerbe'    => 'Keine gewerbliche Tätigkeit feststellbar',
            'uid'        => 'USt-IdNr. ungültig / nicht verifizierbar',
            'daten'      => 'Angaben unvollständig oder unplausibel',
            'dublette'   => 'Bereits registriert (Dublette)',
            'sortiment'  => 'Sortiment/Branche passt nicht',
            'missbrauch' => 'Verdacht auf Missbrauch/Spam',
            'sonstiges'  => 'Sonstiges',
        );
    }

    private static function url(): string {
        return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
    }

    /* ── Daten ───────────────────────────────────────────────────────────── */

    public static function rows( string $status = '', string $search = '' ): array {
        global $wpdb;
        $t      = M24_Database::table( 'haendler' );
        $hrows  = $wpdb->get_results( "SELECT * FROM $t ORDER BY created_at DESC", ARRAY_A );
        $out    = array();
        $search = strtolower( trim( $search ) );
        foreach ( (array) $hrows as $h ) {
            if ( '' !== $status && $h['status'] !== $status ) {
                continue;
            }
            $uid = (int) $h['wp_user_id'];
            $u   = $uid ? get_userdata( $uid ) : null;
            $row = array_merge( $h, array(
                'email'    => $u ? $u->user_email : '',
                'vorname'  => $u ? $u->first_name : '',
                'nachname' => $u ? $u->last_name : '',
                'anrede'   => $uid ? (string) get_user_meta( $uid, '_m24_anrede', true ) : '',
                'telefon'  => $uid ? (string) get_user_meta( $uid, '_m24_telefon', true ) : '',
            ) );
            if ( '' !== $search && false === strpos( strtolower( $h['firma'] . ' ' . $row['email'] ), $search ) ) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    public static function counts(): array {
        global $wpdb;
        $t = M24_Database::table( 'haendler' );
        $r = $wpdb->get_results( "SELECT status, COUNT(*) c FROM $t GROUP BY status", OBJECT_K );
        $g = static function ( $s ) use ( $r ) { return isset( $r[ $s ] ) ? (int) $r[ $s ]->c : 0; };
        return array(
            'all'                  => array_sum( array_map( static function ( $x ) { return (int) $x->c; }, $r ) ),
            'pending_verification' => $g( 'pending_verification' ),
            'verified'             => $g( 'verified' ),
            'approved'             => $g( 'approved' ),
            'rejected'             => $g( 'rejected' ),
        );
    }

    /* ── Mutationen (admin-post, PRG) ────────────────────────────────────── */

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

    public static function handle_bulk_approve() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) ); }
        check_admin_referer( 'm24_haendler_bulk' );
        $ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
        foreach ( $ids as $uid ) {
            if ( $uid ) { self::approve( $uid ); }
        }
        wp_safe_redirect( add_query_arg( 'done', 'bulk', self::url() ) );
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
            M24_B2B_Auth::send_rejection_mail( $uid, $text );
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

    /* ── Render ──────────────────────────────────────────────────────────── */

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'MOTORSPORT24 — Händler', 'm24-plattform' ); ?></h1>
            <style>
                .m24h-badge{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
                .m24h-pending_verification{background:#fdf5e6;color:#b87000}
                .m24h-verified{background:#eef3fb;color:#1a5fb4}
                .m24h-approved{background:#edf7f1;color:#1a7a3c}
                .m24h-rejected{background:#f1f2f4;color:#8a93a0}
                .m24h-vies-ok{color:#1a7a3c;font-weight:600}.m24h-vies-bad{color:#c8102e;font-weight:600}.m24h-vies-na{color:#8a93a0}
                .m24h-note{color:#8a93a0;font-size:11px;margin-top:4px;max-width:260px}
                .m24h-reject{background:#fff;border:1px solid #e6e9ee;border-left:4px solid #c8102e;border-radius:8px;padding:18px 20px;margin:14px 0}
                .m24h-reject label{display:block;font-weight:600;margin:10px 0 4px}
                .m24h-reject select,.m24h-reject textarea{width:100%;max-width:520px;padding:8px 10px;border:1px solid #d6dae0;border-radius:6px}
            </style>

            <?php
            if ( isset( $_GET['done'] ) ) {
                $d   = sanitize_text_field( wp_unslash( $_GET['done'] ) );
                $map = array(
                    'approved' => __( 'Händler freigegeben (E-Mail versendet).', 'm24-plattform' ),
                    'rejected' => __( 'Händler abgelehnt.', 'm24-plattform' ),
                    'vies'     => __( 'VIES neu geprüft.', 'm24-plattform' ),
                    'bulk'     => __( 'Auswahl freigegeben.', 'm24-plattform' ),
                );
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $d ] ?? __( 'Aktion ausgeführt.', 'm24-plattform' ) ) . '</p></div>';
            }

            // Ablehn-Panel (wenn ?reject=USERID).
            $reject_uid = isset( $_GET['reject'] ) ? (int) $_GET['reject'] : 0;
            if ( $reject_uid ) {
                self::render_reject_panel( $reject_uid );
            }

            // Status-Views.
            $counts = self::counts();
            $cur    = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
            $views  = array(
                ''                     => array( 'Alle', $counts['all'] ),
                'pending_verification' => array( 'Wartet', $counts['pending_verification'] ),
                'verified'             => array( 'Verifiziert', $counts['verified'] ),
                'approved'             => array( 'Freigegeben', $counts['approved'] ),
                'rejected'             => array( 'Abgelehnt', $counts['rejected'] ),
            );
            echo '<ul class="subsubsub">';
            $i = 0;
            foreach ( $views as $k => $v ) {
                $u   = $k ? add_query_arg( 'status', $k, self::url() ) : self::url();
                $sep = ( ++$i < count( $views ) ) ? ' <span style="color:#c3c4c7">|</span> ' : '';
                printf(
                    '<li><a href="%s"%s>%s <span class="count">(%d)</span></a>%s</li>',
                    esc_url( $u ),
                    ( $cur === $k ? ' class="current"' : '' ),
                    esc_html( $v[0] ),
                    (int) $v[1],
                    $sep // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — statisches Markup
                );
            }
            echo '</ul>';

            $table = new M24_Haendler_List_Table();
            $table->prepare_items();
            ?>
            <form method="get" style="margin:10px 0">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                <?php if ( $cur ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $cur ); ?>"><?php endif; ?>
                <p class="search-box">
                    <input type="search" name="s" value="<?php echo isset( $_GET['s'] ) ? esc_attr( wp_unslash( $_GET['s'] ) ) : ''; ?>" placeholder="<?php esc_attr_e( 'Firma / E-Mail', 'm24-plattform' ); ?>">
                    <button class="button"><?php esc_html_e( 'Suchen', 'm24-plattform' ); ?></button>
                </p>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="m24_haendler_bulk_approve">
                <?php wp_nonce_field( 'm24_haendler_bulk' ); ?>
                <?php $table->display(); ?>
                <p><button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Ausgewählte Händler freigeben?', 'm24-plattform' ) ); ?>');"><?php esc_html_e( 'Ausgewählte freigeben', 'm24-plattform' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    private static function render_reject_panel( int $uid ): void {
        global $wpdb;
        $h = $wpdb->get_row( $wpdb->prepare( "SELECT firma FROM " . M24_Database::table( 'haendler' ) . " WHERE wp_user_id = %d", $uid ) );
        if ( ! $h ) { return; }
        ?>
        <div class="m24h-reject">
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
}

/* ========================================================================= */

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class M24_Haendler_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array( 'singular' => 'haendler', 'plural' => 'haendler', 'ajax' => false ) );
    }

    public function get_columns() {
        return array(
            'cb'        => '<input type="checkbox" />',
            'firma'     => __( 'Firma', 'm24-plattform' ),
            'kontaktp'  => __( 'Ansprechpartner', 'm24-plattform' ),
            'kontakt'   => __( 'E-Mail / Telefon', 'm24-plattform' ),
            'land'      => __( 'Land', 'm24-plattform' ),
            'uid'       => __( 'USt-IdNr. / VIES', 'm24-plattform' ),
            'status'    => __( 'Status', 'm24-plattform' ),
            'registriert' => __( 'Registriert', 'm24-plattform' ),
        );
    }

    public function get_sortable_columns() {
        return array( 'registriert' => array( 'registriert', true ) );
    }

    public function get_bulk_actions() {
        return array(); // Bulk-Freigabe läuft über den eigenen Submit-Button (admin-post).
    }

    public function prepare_items() {
        $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $rows   = M24_Haendler_Page::rows( $status, $search );

        $order = ( isset( $_GET['order'] ) && 'asc' === strtolower( (string) $_GET['order'] ) ) ? 'asc' : 'desc';
        usort( $rows, static function ( $a, $b ) { return strcmp( (string) $a['created_at'], (string) $b['created_at'] ); } );
        if ( 'desc' === $order ) { $rows = array_reverse( $rows ); }

        $per   = M24_Haendler_Page::PER_PAGE;
        $total = count( $rows );
        $paged = max( 1, (int) $this->get_pagenum() );
        $this->items = array_slice( $rows, ( $paged - 1 ) * $per, $per );
        $this->set_pagination_args( array( 'total_items' => $total, 'per_page' => $per, 'total_pages' => (int) ceil( $total / $per ) ) );
        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'firma' );
    }

    public function column_cb( $item ) {
        return '<input type="checkbox" name="ids[]" value="' . (int) $item['wp_user_id'] . '" />';
    }

    public function column_firma( $item ) {
        $uid   = (int) $item['wp_user_id'];
        $edit  = get_edit_user_link( $uid );
        $firma = $item['firma'] ?: ( '#' . $uid );
        $name  = $edit ? '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $firma ) . '</strong></a>' : '<strong>' . esc_html( $firma ) . '</strong>';

        $base = admin_url( 'admin-post.php' );
        $act  = array();
        if ( 'approved' !== $item['status'] ) {
            $approve = wp_nonce_url( add_query_arg( array( 'action' => 'm24_haendler_approve', 'user' => $uid ), $base ), 'm24_haendler_approve_' . $uid );
            $act['approve'] = '<a href="' . esc_url( $approve ) . '" onclick="return confirm(\'' . esc_js( __( 'Diesen Händler freigeben?', 'm24-plattform' ) ) . '\');">' . esc_html__( 'Freigeben', 'm24-plattform' ) . '</a>';
        }
        $act['reject'] = '<a href="' . esc_url( add_query_arg( array( 'page' => 'm24-haendler', 'reject' => $uid ), admin_url( 'admin.php' ) ) ) . '" style="color:#b32d2e;">' . esc_html__( 'Ablehnen', 'm24-plattform' ) . '</a>';
        if ( '' !== (string) $item['uid'] ) {
            $vies = wp_nonce_url( add_query_arg( array( 'action' => 'm24_haendler_vies', 'user' => $uid ), $base ), 'm24_haendler_vies_' . $uid );
            $act['vies'] = '<a href="' . esc_url( $vies ) . '">' . esc_html__( 'VIES neu prüfen', 'm24-plattform' ) . '</a>';
        }
        return $name . $this->row_actions( $act );
    }

    public function column_kontaktp( $item ) {
        $n = trim( $item['vorname'] . ' ' . $item['nachname'] );
        $a = $item['anrede'] ? esc_html( $item['anrede'] ) . ' ' : '';
        return $a . esc_html( $n ?: '—' );
    }

    public function column_kontakt( $item ) {
        $out = $item['email'] ? '<a href="mailto:' . esc_attr( $item['email'] ) . '">' . esc_html( $item['email'] ) . '</a>' : '—';
        if ( '' !== (string) $item['telefon'] ) {
            $out .= '<br><span style="color:#5a6474;font-size:12px;">' . esc_html( $item['telefon'] ) . '</span>';
        }
        return $out;
    }

    public function column_land( $item ) {
        return class_exists( 'M24_B2B_Auth' ) ? esc_html( M24_B2B_Auth::country_name( (string) $item['land'] ) ) : esc_html( (string) $item['land'] );
    }

    public function column_uid( $item ) {
        $uid = (string) $item['uid'];
        if ( '' === $uid ) { return '<span class="m24h-vies-na">—</span>'; }
        $v = $item['uid_valid'];
        if ( null === $v || '' === $v ) {
            $badge = '<span class="m24h-vies-na">— ungeprüft</span>';
        } elseif ( (int) $v === 1 ) {
            $badge = '<span class="m24h-vies-ok">✓ gültig</span>';
        } else {
            $badge = '<span class="m24h-vies-bad">✗ ungültig</span>';
        }
        $when = $item['uid_validated_at'] ? '<br><span style="color:#8a93a0;font-size:11px;">' . esc_html( mysql2date( 'd.m.Y H:i', $item['uid_validated_at'] ) ) . '</span>' : '';
        return '<code>' . esc_html( $uid ) . '</code><br>' . $badge . $when; // phpcs:ignore
    }

    public function column_status( $item ) {
        $labels = array(
            'pending_verification' => 'Wartet (E-Mail)',
            'verified'             => 'Verifiziert',
            'approved'             => 'Freigegeben',
            'rejected'             => 'Abgelehnt',
        );
        $s   = (string) $item['status'];
        $out = '<span class="m24h-badge m24h-' . esc_attr( $s ) . '">' . esc_html( $labels[ $s ] ?? $s ) . '</span>';
        if ( '' !== (string) $item['notes_intern'] ) {
            $note = wp_strip_all_tags( (string) $item['notes_intern'] );
            $out .= '<div class="m24h-note">' . esc_html( mb_strimwidth( $note, 0, 160, '…' ) ) . '</div>';
        }
        return $out;
    }

    public function column_registriert( $item ) {
        return $item['created_at'] ? esc_html( mysql2date( 'd.m.Y H:i', get_date_from_gmt( (string) $item['created_at'] ) ) ) : '—';
    }

    public function no_items() {
        esc_html_e( 'Keine Händler gefunden.', 'm24-plattform' );
    }
}
