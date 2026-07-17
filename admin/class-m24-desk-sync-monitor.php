<?php
/**
 * M24 Plattform — Admin-Monitor „Desk-Sync" (Baustein W1).
 *
 * Reine Ansicht der Outbound-Sync-Zustände je Angebot: Angebot, Richtung, Status, letzter Versuch,
 * Fehlerdetails + manueller Retry / Dry-Run-Test. Mutationen laufen über M24_Desk_Push (admin-post, PRG, Nonce).
 * KEIN eigener Push-Code hier — eine Verantwortung: Darstellung.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Desk_Sync_Monitor {

    const PAGE_SLUG  = 'm24-desk-sync';
    const CAPABILITY = 'manage_options';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
    }

    public static function register_menu() {
        add_submenu_page( 'm24-plattform', 'Desk-Sync', 'Desk-Sync', self::CAPABILITY, self::PAGE_SLUG, array( __CLASS__, 'render' ) );
    }

    public static function render() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( 'Keine Berechtigung.' ); }
        global $wpdb;
        $t = M24_Offers::table();

        $f   = isset( $_GET['st'] ) ? sanitize_key( wp_unslash( $_GET['st'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $where = "desk_sync_status IS NOT NULL";
        if ( in_array( $f, array( 'pending', 'synced', 'failed', 'needs_update', 'confirm_failed' ), true ) ) { $where .= $wpdb->prepare( ' AND desk_sync_status = %s', $f ); }
        $rows = $wpdb->get_results( "SELECT id, offer_no, status, desk_order_id, desk_order_num, desk_sync_status, desk_synced_at, desk_sync_attempts, desk_sync_error, sent_at FROM $t WHERE $where ORDER BY id DESC LIMIT 300" ); // phpcs:ignore WordPress.DB

        $counts = array();
        foreach ( (array) $wpdb->get_results( "SELECT desk_sync_status s, COUNT(*) c FROM $t WHERE desk_sync_status IS NOT NULL GROUP BY desk_sync_status" ) as $c ) { $counts[ (string) $c->s ] = (int) $c->c; }
        $enabled = class_exists( 'M24_Desk_Push' ) && M24_Desk_Push::enabled();
        $configured = class_exists( 'M24_Rest_Client' ) && M24_Rest_Client::is_configured();

        echo '<div class="wrap"><h1 class="wp-heading-inline">MOTORSPORT24 — Desk-Sync</h1><hr class="wp-header-end">';

        if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $d = sanitize_key( wp_unslash( $_GET['done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
            if ( 'dry' === $d ) {
                $note = get_transient( 'm24_desk_dry_note_' . get_current_user_id() );
                echo '<div class="notice notice-info is-dismissible"><p><strong>Dry-Run:</strong> ' . esc_html( (string) $note ) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>Aktion ausgeführt.</p></div>';
            }
        }

        // Status-Banner.
        if ( ! $configured ) {
            echo '<div class="notice notice-warning"><p>Desk ist nicht konfiguriert (API-URL/Key fehlt) — MOTORSPORT24 → Einstellungen.</p></div>';
        } elseif ( ! $enabled ) {
            echo '<div class="notice notice-warning"><p><strong>Echter Versand ist AUS</strong> (Schalter <code>' . esc_html( M24_Desk_Push::FLAG ) . '</code>). Beim Angebotsversand läuft nur ein <em>Dry-Run</em> (keine Nebenwirkung). Nach grünem Dry-Run in den Einstellungen scharfschalten.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p><strong>Echter Versand ist AN.</strong> Angebote werden beim Senden an <code>/api/orders</code> gepusht.</p></div>';
        }

        echo '<style>.m24ds .chip{display:inline-block;padding:6px 12px;border-radius:999px;border:1.5px solid #e5e7eb;background:#fff;font-size:13px;font-weight:600;text-decoration:none;color:#111417;margin-right:8px}.m24ds .chip.on{background:#0e447e;border-color:#0e447e;color:#fff}'
            . '.m24ds table{margin-top:14px}.m24ds .bdg{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:999px}'
            . '.m24ds .synced{background:#edf7f1;color:#1a7a3c}.m24ds .failed{background:#fdecea;color:#c8102e}.m24ds .pending{background:#fdf5e6;color:#b87000}.m24ds .needs_update{background:#eef3fb;color:#1a5fb4}.m24ds .confirm_failed{background:#fdecea;color:#c8102e}'
            . '.m24ds .err{color:#8a929c;font-size:12px;max-width:420px;display:inline-block}</style>';

        echo '<div class="m24ds">';
        $chip = function ( $k, $lbl, $n ) use ( $f ) {
            $u = add_query_arg( array_filter( array( 'page' => self::PAGE_SLUG, 'st' => $k ) ), admin_url( 'admin.php' ) );
            return '<a class="chip' . ( $f === $k ? ' on' : '' ) . '" href="' . esc_url( $u ) . '">' . esc_html( $lbl ) . ' <span>' . (int) $n . '</span></a>';
        };
        $all = array_sum( $counts );
        echo $chip( '', 'Alle', $all ) . $chip( 'synced', 'Synced', $counts['synced'] ?? 0 ) . $chip( 'failed', 'Failed', $counts['failed'] ?? 0 ) . $chip( 'confirm_failed', 'Confirm-Fail', $counts['confirm_failed'] ?? 0 ) . $chip( 'pending', 'Pending', $counts['pending'] ?? 0 ) . $chip( 'needs_update', 'Needs update', $counts['needs_update'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput

        echo '<h2 style="margin-top:18px;">Aufträge (W1 Anlage / W2 Confirm)</h2>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Angebot</th><th>Richtung</th><th>Status</th><th>Desk-Auftrag</th><th>Versuche</th><th>Letzter Versuch</th><th>Fehlerdetails</th><th>Aktion</th>'
            . '</tr></thead><tbody>';

        if ( empty( $rows ) ) {
            echo '<tr><td colspan="8">Keine Sync-Einträge' . ( '' !== $f ? ' zum Filter' : '' ) . '.</td></tr>';
        } else {
            foreach ( $rows as $r ) {
                $ss   = (string) $r->desk_sync_status;
                $when = $r->desk_synced_at ? mysql2date( 'd.m.Y H:i', (string) $r->desk_synced_at ) : '—';
                $desk = trim( (string) $r->desk_order_num . ( $r->desk_order_id ? ' (#' . (string) $r->desk_order_id . ')' : '' ) );
                // Richtung aus dem Status ableiten: confirm/synced-nach-Anlage = Auftrag-Update, sonst Anlage.
                $dir  = in_array( $ss, array( 'confirm_failed', 'needs_update' ), true ) || ( 'synced' === $ss && '' !== $desk )
                    ? '⤴ Auftrag-Update' : '⤴ Auftrag-Anlage';
                $retry = wp_nonce_url( add_query_arg( array( 'action' => 'm24_desk_retry', 'offer' => (int) $r->id ), admin_url( 'admin-post.php' ) ), 'm24_desk_retry_' . (int) $r->id );
                $dry   = wp_nonce_url( add_query_arg( array( 'action' => 'm24_desk_dry_run', 'offer' => (int) $r->id ), admin_url( 'admin-post.php' ) ), 'm24_desk_dry_' . (int) $r->id );
                echo '<tr>';
                echo '<td><strong>' . esc_html( (string) $r->offer_no ) . '</strong><br><span style="color:#8a929c;font-size:12px;">' . esc_html( (string) $r->status ) . '</span></td>';
                echo '<td>' . esc_html( $dir ) . '</td>';
                echo '<td><span class="bdg ' . esc_attr( $ss ) . '">' . esc_html( $ss ) . '</span></td>';
                echo '<td>' . ( '' !== $desk ? esc_html( $desk ) : '—' ) . '</td>';
                echo '<td>' . (int) $r->desk_sync_attempts . ' / ' . (int) M24_Desk_Push::MAX_TRIES . '</td>';
                echo '<td>' . esc_html( $when ) . '</td>';
                echo '<td><span class="err">' . esc_html( (string) $r->desk_sync_error ) . '</span></td>';
                echo '<td><a class="button button-small" href="' . esc_url( $retry ) . '">Retry</a> <a class="button button-small" href="' . esc_url( $dry ) . '">Dry-Run</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        // Kunden-Updates (W2a/W3): Konten mit Desk-Customer-ID.
        $cust = get_users( array(
            'meta_key' => M24_Desk_Push::CUST_META,
            'meta_compare' => 'EXISTS',
            'number' => 200,
            'fields' => array( 'ID', 'user_email', 'display_name' ),
        ) );
        echo '<h2 style="margin-top:22px;">Kunden-Updates (W2a / W3)</h2>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>Konto</th><th>Richtung</th><th>Desk-Customer</th><th>Zustand</th><th>Versuche</th><th>Aktion</th>'
            . '</tr></thead><tbody>';
        if ( empty( $cust ) ) {
            echo '<tr><td colspan="6">Noch keine Konten mit Desk-Customer-ID.</td></tr>';
        } else {
            foreach ( $cust as $c ) {
                $uid   = (int) $c->ID;
                $cid   = (string) get_user_meta( $uid, M24_Desk_Push::CUST_META, true );
                $dirty = '1' === (string) get_user_meta( $uid, M24_Desk_Push::CUST_DIRTY, true );
                $att   = (int) get_user_meta( $uid, M24_Desk_Push::CUST_ATTEMPTS, true );
                $state = $dirty ? '<span class="bdg failed">retry offen</span>' : '<span class="bdg synced">ok</span>';
                $retry = wp_nonce_url( add_query_arg( array( 'action' => 'm24_desk_cust_retry', 'user' => $uid ), admin_url( 'admin-post.php' ) ), 'm24_desk_cust_retry_' . $uid );
                $dry   = wp_nonce_url( add_query_arg( array( 'action' => 'm24_desk_cust_dry', 'user' => $uid ), admin_url( 'admin-post.php' ) ), 'm24_desk_cust_dry_' . $uid );
                echo '<tr>';
                echo '<td><strong>' . esc_html( (string) $c->display_name ) . '</strong><br><span style="color:#8a929c;font-size:12px;">' . esc_html( (string) $c->user_email ) . '</span></td>';
                echo '<td>⤴ Kunde-Update</td>';
                echo '<td>#' . esc_html( $cid ) . '</td>';
                echo '<td>' . $state . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
                echo '<td>' . (int) $att . ' / ' . (int) M24_Desk_Push::MAX_TRIES . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url( $retry ) . '">Retry</a> <a class="button button-small" href="' . esc_url( $dry ) . '">Dry-Run</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        self::render_inbound();

        echo '</div></div>';
    }

    /**
     * Inbound (Desk → WP, D1–D3): die letzten Webhook-Ereignisse aus dem Logger-Kontext desk_sync_in.
     * Reine Ansicht — der Applier schreibt die Einträge (M24_Desk_Inbound::log).
     */
    private static function render_inbound() {
        $token_set = class_exists( 'M24_Desk_Inbound' ) && '' !== M24_Desk_Inbound::token();

        echo '<h2 style="margin-top:22px;">Inbound (Desk → WP)</h2>';
        if ( ! $token_set ) {
            echo '<div class="notice notice-warning inline"><p><strong>Kein WP-Inbound-Token gesetzt</strong> — eingehende Desk-Webhooks werden mit <code>401</code> abgewiesen. Feld „WP-Inbound-Token" unter MOTORSPORT24 → Einstellungen (oder Konstante <code>M24_WP_INBOUND_TOKEN</code>).</p></div>';
        } else {
            echo '<p style="color:#8a929c;font-size:12px;margin:2px 0 0;">Webhook-URL für Desk (<code>WP_WEBHOOK_URL</code>): <code>' . esc_html( home_url( '/wp-json/m24/v1/desk-sync' ) ) . '</code></p>';
        }

        $rows = class_exists( 'M24_Logger' ) ? (array) M24_Logger::recent( 100, null, M24_Desk_Inbound::LOG_CTX ) : array();
        echo '<table class="widefat striped"><thead><tr><th>Zeitpunkt</th><th>Ereignis</th><th>Details</th></tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="3">Noch keine Inbound-Ereignisse.</td></tr>';
        } else {
            // Statusklassen mappen: applied = grün, verworfen/unbekannt = neutral-blau, auth/400/500 = rot.
            $cls = array(
                'applied' => 'synced', 'replay' => 'needs_update', 'discarded_lww' => 'needs_update',
                'skipped_unmapped' => 'pending', 'unauthorized' => 'failed', 'bad_request' => 'failed', 'error' => 'failed',
            );
            foreach ( $rows as $r ) {
                $step = (string) ( $r['message'] ?? '' );
                $pl   = json_decode( (string) ( $r['payload_json'] ?? '' ), true );
                $msg  = is_array( $pl ) ? (string) ( $pl['msg'] ?? '' ) : '';
                echo '<tr>';
                echo '<td>' . esc_html( mysql2date( 'd.m.Y H:i:s', (string) ( $r['created_at'] ?? '' ) ) ) . '</td>';
                echo '<td><span class="bdg ' . esc_attr( $cls[ $step ] ?? 'pending' ) . '">' . esc_html( $step ) . '</span></td>';
                echo '<td><span class="err" style="max-width:640px;">' . esc_html( $msg ) . '</span></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }
}
