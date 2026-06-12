<?php
/**
 * M24 Plattform — Mock-Log-Viewer (Modul D.0)
 *
 * WP-Admin → M24 Plattform → Mock-Log
 * Zeigt die letzten N Eintraege aus {prefix}m24_mock_log,
 * filterbar nach Route und Response-Code.
 *
 * Spec-Referenz: Uebergabe v10 Kapitel 4.11 + Empfehlung Option 2 (REST + Admin-Page)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Mock_Log_Viewer {

    const PAGE_SLUG  = 'm24-plattform-mock-log';
    const CAPABILITY = 'manage_options';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 25 );
    }

    public static function register_menu() {
        add_submenu_page(
            'm24-plattform',
            __( 'Mock-Log', 'm24-plattform' ),
            __( 'Mock-Log', 'm24-plattform' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( __( 'Keine Berechtigung.', 'm24-plattform' ) );
        }

        $route_filter = isset( $_GET['route'] ) ? sanitize_text_field( wp_unslash( $_GET['route'] ) ) : '';
        $code_filter  = isset( $_GET['code'] )  ? max( 0, min( 599, (int) $_GET['code'] ) )            : 0;
        $limit        = isset( $_GET['limit'] ) ? max( 10, min( 1000, (int) $_GET['limit'] ) )         : 100;

        global $wpdb;
        $table = M24_Database::table( 'mock_log' );

        // Tabelle-Existenz pruefen, damit erste Plugin-Updates ohne Migration sauber failen.
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

        if ( ! $table_exists ) {
            ?>
            <div class="wrap">
                <h1><?php echo esc_html__( 'M24 Plattform — Mock-Log', 'm24-plattform' ); ?></h1>
                <div class="notice notice-warning">
                    <p>
                        <?php echo esc_html__( 'Die Mock-Log-Tabelle existiert noch nicht. Bitte das Plugin einmal deaktivieren und wieder aktivieren, damit Migration 003 laeuft.', 'm24-plattform' ); ?>
                    </p>
                </div>
            </div>
            <?php
            return;
        }

        // Distinct routes fuer Filter-Dropdown.
        $routes = $wpdb->get_col( "SELECT DISTINCT route FROM $table ORDER BY route ASC LIMIT 50" );

        // Eintraege laden mit Filtern.
        $where  = [];
        $params = [];
        if ( $route_filter !== '' ) {
            $where[]  = 'route = %s';
            $params[] = $route_filter;
        }
        if ( $code_filter > 0 ) {
            $where[]  = 'response_code = %d';
            $params[] = $code_filter;
        }
        $where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';

        $params[] = $limit;
        $sql      = "SELECT * FROM $table $where_sql ORDER BY id DESC LIMIT %d";
        $rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        // Total Count fuer Header.
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );

        // Refresh-URL (behaelt Filter bei).
        $refresh_url = add_query_arg( [
            'page'  => self::PAGE_SLUG,
            'route' => $route_filter,
            'code'  => $code_filter ?: null,
            'limit' => $limit,
        ], admin_url( 'admin.php' ) );

        // Clear-URL.
        $clear_url = wp_nonce_url(
            add_query_arg( [
                'page'         => self::PAGE_SLUG,
                'm24_mock_action' => 'clear',
            ], admin_url( 'admin.php' ) ),
            'm24_mock_log_clear'
        );

        // Clear-Aktion behandeln (vor Render).
        if ( isset( $_GET['m24_mock_action'] ) && $_GET['m24_mock_action'] === 'clear' ) {
            check_admin_referer( 'm24_mock_log_clear' );
            $wpdb->query( "TRUNCATE TABLE $table" );
            ?>
            <div class="wrap">
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__( 'Mock-Log geleert.', 'm24-plattform' ); ?></p>
                </div>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="button">
                        <?php echo esc_html__( 'Zurueck zum Mock-Log', 'm24-plattform' ); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html__( 'M24 Plattform — Mock-Log', 'm24-plattform' ); ?>
                <a href="<?php echo esc_url( $refresh_url ); ?>" class="page-title-action">
                    <?php echo esc_html__( 'Aktualisieren', 'm24-plattform' ); ?>
                </a>
                <a href="<?php echo esc_url( $clear_url ); ?>" class="page-title-action"
                   onclick="return confirm('<?php echo esc_js( __( 'Mock-Log wirklich komplett leeren?', 'm24-plattform' ) ); ?>');">
                    <?php echo esc_html__( 'Log leeren', 'm24-plattform' ); ?>
                </a>
            </h1>

            <p style="color:#5a6474;font-size:13px;margin-top:6px;">
                <?php
                /* translators: %d: total count */
                printf(
                    esc_html__( 'Insgesamt %d Mock-Calls protokolliert. REST-Routen: %s', 'm24-plattform' ),
                    $total,
                    '<code>/wp-json/m24-plattform/v1/mock/{health|orders|orders-fail|log}</code>'
                );
                ?>
            </p>

            <form method="get" style="margin:14px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

                <label for="m24-mock-filter-route"><?php echo esc_html__( 'Route:', 'm24-plattform' ); ?></label>
                <select name="route" id="m24-mock-filter-route">
                    <option value=""><?php echo esc_html__( '(alle)', 'm24-plattform' ); ?></option>
                    <?php foreach ( $routes as $r ): ?>
                        <option value="<?php echo esc_attr( $r ); ?>" <?php selected( $route_filter, $r ); ?>>
                            <?php echo esc_html( $r ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                &nbsp;
                <label for="m24-mock-filter-code"><?php echo esc_html__( 'Code:', 'm24-plattform' ); ?></label>
                <select name="code" id="m24-mock-filter-code">
                    <option value="0"><?php echo esc_html__( '(alle)', 'm24-plattform' ); ?></option>
                    <?php foreach ( [ 200, 201, 400, 401, 403, 404, 409, 500 ] as $c ): ?>
                        <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $code_filter, $c ); ?>>
                            <?php echo esc_html( (string) $c ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                &nbsp;
                <label for="m24-mock-filter-limit"><?php echo esc_html__( 'Anzahl:', 'm24-plattform' ); ?></label>
                <select name="limit" id="m24-mock-filter-limit">
                    <?php foreach ( [ 50, 100, 250, 500, 1000 ] as $n ): ?>
                        <option value="<?php echo esc_attr( $n ); ?>" <?php selected( $limit, $n ); ?>>
                            <?php echo esc_html( $n ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="submit" class="button" value="<?php echo esc_attr__( 'Filtern', 'm24-plattform' ); ?>" />
            </form>

            <?php if ( empty( $rows ) ): ?>
                <div class="notice notice-info inline">
                    <p>
                        <?php echo esc_html__( 'Keine Mock-Eintraege gefunden. Test-Aufruf:', 'm24-plattform' ); ?>
                        <code>curl <?php echo esc_html( home_url( '/wp-json/m24-plattform/v1/mock/health' ) ); ?></code>
                    </p>
                </div>
            <?php else: ?>
                <p style="color:#5a6474;font-size:13px;">
                    <?php
                    /* translators: %d = count */
                    printf(
                        esc_html__( '%d Eintraege, neueste zuerst.', 'm24-plattform' ),
                        count( $rows )
                    );
                    ?>
                </p>

                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th style="width:140px;"><?php echo esc_html__( 'Zeit', 'm24-plattform' ); ?></th>
                            <th style="width:60px;"><?php echo esc_html__( 'Method', 'm24-plattform' ); ?></th>
                            <th style="width:160px;"><?php echo esc_html__( 'Route', 'm24-plattform' ); ?></th>
                            <th style="width:80px;"><?php echo esc_html__( 'Code', 'm24-plattform' ); ?></th>
                            <th style="width:140px;"><?php echo esc_html__( 'Source', 'm24-plattform' ); ?></th>
                            <th style="width:120px;"><?php echo esc_html__( 'Idem-Key', 'm24-plattform' ); ?></th>
                            <th><?php echo esc_html__( 'Details', 'm24-plattform' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ):
                            $code  = (int) $row['response_code'];
                            $color = '#5a6474';
                            $bg    = '#f7f8fa';
                            if ( $code >= 200 && $code < 300 ) { $color = '#1a7a3a'; $bg = '#e9f7ee'; }
                            elseif ( $code >= 400 && $code < 500 ) { $color = '#b87000'; $bg = '#fdf5e6'; }
                            elseif ( $code >= 500 ) { $color = '#c8102e'; $bg = '#fdf1f3'; }

                            $headers_pretty = '';
                            if ( ! empty( $row['headers'] ) ) {
                                $decoded = json_decode( $row['headers'], true );
                                $headers_pretty = is_array( $decoded )
                                    ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                                    : $row['headers'];
                            }
                            $body_pretty = '';
                            if ( ! empty( $row['body'] ) ) {
                                $decoded = json_decode( $row['body'], true );
                                $body_pretty = $decoded !== null
                                    ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                                    : $row['body'];
                            }
                            $resp_pretty = '';
                            if ( ! empty( $row['response_body'] ) ) {
                                $decoded = json_decode( $row['response_body'], true );
                                $resp_pretty = $decoded !== null
                                    ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                                    : $row['response_body'];
                            }
                            ?>
                            <tr>
                                <td style="font-family:monospace;font-size:11px;color:#5a6474;">
                                    <?php echo esc_html( $row['created_at'] ); ?>
                                </td>
                                <td style="font-family:monospace;font-size:12px;font-weight:600;">
                                    <?php echo esc_html( $row['method'] ); ?>
                                </td>
                                <td style="font-family:monospace;font-size:12px;">
                                    <?php echo esc_html( $row['route'] ); ?>
                                </td>
                                <td>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $color ); ?>;font-size:11px;font-weight:600;">
                                        <?php echo esc_html( (string) $code ); ?>
                                    </span>
                                </td>
                                <td style="font-family:monospace;font-size:11px;color:#5a6474;">
                                    <?php echo esc_html( $row['source'] ?: '—' ); ?>
                                </td>
                                <td style="font-family:monospace;font-size:11px;color:#5a6474;" title="<?php echo esc_attr( $row['idempotency_key'] ?: '' ); ?>">
                                    <?php echo esc_html( $row['idempotency_key'] ? substr( $row['idempotency_key'], 0, 14 ) . '…' : '—' ); ?>
                                </td>
                                <td>
                                    <details>
                                        <summary style="cursor:pointer;color:#1a5fb4;font-size:12px;">
                                            <?php echo esc_html__( 'Headers/Body/Response', 'm24-plattform' ); ?>
                                        </summary>
                                        <div style="margin-top:6px;">
                                            <strong style="font-size:11px;color:#5a6474;"><?php echo esc_html__( 'Request-Headers', 'm24-plattform' ); ?></strong>
                                            <pre style="margin:3px 0 8px;padding:8px;background:#1a1d23;color:#e8eaf0;font-size:11px;border-radius:4px;overflow-x:auto;max-width:600px;"><?php echo esc_html( $headers_pretty ?: '—' ); ?></pre>
                                            <strong style="font-size:11px;color:#5a6474;"><?php echo esc_html__( 'Request-Body', 'm24-plattform' ); ?></strong>
                                            <pre style="margin:3px 0 8px;padding:8px;background:#1a1d23;color:#e8eaf0;font-size:11px;border-radius:4px;overflow-x:auto;max-width:600px;"><?php echo esc_html( $body_pretty ?: '—' ); ?></pre>
                                            <strong style="font-size:11px;color:#5a6474;"><?php echo esc_html__( 'Response-Body', 'm24-plattform' ); ?></strong>
                                            <pre style="margin:3px 0 0;padding:8px;background:#1a1d23;color:#e8eaf0;font-size:11px;border-radius:4px;overflow-x:auto;max-width:600px;"><?php echo esc_html( $resp_pretty ?: '—' ); ?></pre>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
