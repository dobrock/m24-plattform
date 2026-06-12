<?php
/**
 * M24 Plattform — Sync-Log-Viewer
 *
 * WP-Admin → M24 Plattform → Sync-Log
 * Zeigt die letzten N Eintraege aus {prefix}m24_sync_log,
 * filterbar nach Level und Context.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Log_Viewer {

    const PAGE_SLUG  = 'm24-plattform-log';
    const CAPABILITY = 'manage_options';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ], 20 );
    }

    public static function register_menu() {
        add_submenu_page(
            'm24-plattform',
            __( 'Sync-Log', 'm24-plattform' ),
            __( 'Sync-Log', 'm24-plattform' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( __( 'Keine Berechtigung.', 'm24-plattform' ) );
        }

        $level   = isset( $_GET['level'] )   ? sanitize_text_field( wp_unslash( $_GET['level'] ) )   : '';
        $context = isset( $_GET['context'] ) ? sanitize_text_field( wp_unslash( $_GET['context'] ) ) : '';
        $limit   = isset( $_GET['limit'] )   ? max( 10, min( 1000, (int) $_GET['limit'] ) )         : 100;

        $rows = M24_Logger::recent( $limit, $level ?: null, $context ?: null );

        // Distinct contexts fuer Filter-Dropdown
        global $wpdb;
        $table    = M24_Database::table( 'sync_log' );
        $contexts = $wpdb->get_col( "SELECT DISTINCT context FROM $table ORDER BY context ASC LIMIT 50" );

        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html__( 'M24 Plattform — Sync-Log', 'm24-plattform' ); ?>
                <a href="<?php echo esc_url( add_query_arg( [
                    'page'    => self::PAGE_SLUG,
                    'level'   => $level,
                    'context' => $context,
                    'limit'   => $limit,
                ], admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
                    <?php echo esc_html__( 'Aktualisieren', 'm24-plattform' ); ?>
                </a>
            </h1>

            <form method="get" style="margin:14px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />

                <label for="m24-filter-level"><?php echo esc_html__( 'Level:', 'm24-plattform' ); ?></label>
                <select name="level" id="m24-filter-level">
                    <option value=""><?php echo esc_html__( '(alle)', 'm24-plattform' ); ?></option>
                    <?php foreach ( [ 'debug', 'info', 'warning', 'error' ] as $lvl ): ?>
                        <option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $level, $lvl ); ?>>
                            <?php echo esc_html( $lvl ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                &nbsp;
                <label for="m24-filter-context"><?php echo esc_html__( 'Context:', 'm24-plattform' ); ?></label>
                <select name="context" id="m24-filter-context">
                    <option value=""><?php echo esc_html__( '(alle)', 'm24-plattform' ); ?></option>
                    <?php foreach ( $contexts as $ctx ): ?>
                        <option value="<?php echo esc_attr( $ctx ); ?>" <?php selected( $context, $ctx ); ?>>
                            <?php echo esc_html( $ctx ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                &nbsp;
                <label for="m24-filter-limit"><?php echo esc_html__( 'Anzahl:', 'm24-plattform' ); ?></label>
                <select name="limit" id="m24-filter-limit">
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
                    <p><?php echo esc_html__( 'Keine Log-Eintraege gefunden.', 'm24-plattform' ); ?></p>
                </div>
            <?php else: ?>
                <p style="color:#5a6474;font-size:13px;">
                    <?php
                    printf(
                        /* translators: %d = count */
                        esc_html__( '%d Eintraege, neueste zuerst.', 'm24-plattform' ),
                        count( $rows )
                    );
                    ?>
                </p>

                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th style="width:140px;"><?php echo esc_html__( 'Zeit', 'm24-plattform' ); ?></th>
                            <th style="width:80px;"><?php echo esc_html__( 'Level', 'm24-plattform' ); ?></th>
                            <th style="width:140px;"><?php echo esc_html__( 'Context', 'm24-plattform' ); ?></th>
                            <th><?php echo esc_html__( 'Message', 'm24-plattform' ); ?></th>
                            <th style="width:100px;"><?php echo esc_html__( 'Payload', 'm24-plattform' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ):
                            $lvl = $row['level'];
                            $color = '#5a6474';
                            $bg    = '#f7f8fa';
                            switch ( $lvl ) {
                                case 'error':   $color = '#c8102e'; $bg = '#fdf1f3'; break;
                                case 'warning': $color = '#b87000'; $bg = '#fdf5e6'; break;
                                case 'info':    $color = '#1a5fb4'; $bg = '#eef3fb'; break;
                                case 'debug':   $color = '#5a6474'; $bg = '#f2f4f7'; break;
                            }
                            $payload_pretty = '';
                            if ( ! empty( $row['payload_json'] ) ) {
                                $decoded = json_decode( $row['payload_json'], true );
                                $payload_pretty = $decoded !== null
                                    ? wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                                    : $row['payload_json'];
                            }
                            ?>
                            <tr>
                                <td style="font-family:monospace;font-size:11px;color:#5a6474;">
                                    <?php echo esc_html( $row['created_at'] ); ?>
                                </td>
                                <td>
                                    <span style="display:inline-block;padding:2px 8px;border-radius:4px;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $color ); ?>;font-size:11px;font-weight:600;">
                                        <?php echo esc_html( strtoupper( $lvl ) ); ?>
                                    </span>
                                </td>
                                <td style="font-family:monospace;font-size:12px;">
                                    <?php echo esc_html( $row['context'] ); ?>
                                </td>
                                <td><?php echo esc_html( $row['message'] ); ?></td>
                                <td>
                                    <?php if ( $payload_pretty ): ?>
                                        <details>
                                            <summary style="cursor:pointer;color:#1a5fb4;font-size:12px;"><?php echo esc_html__( 'anzeigen', 'm24-plattform' ); ?></summary>
                                            <pre style="margin:6px 0 0;padding:8px;background:#1a1d23;color:#e8eaf0;font-size:11px;border-radius:4px;overflow-x:auto;max-width:600px;"><?php echo esc_html( $payload_pretty ); ?></pre>
                                        </details>
                                    <?php else: ?>
                                        <span style="color:#9aa3b0;">—</span>
                                    <?php endif; ?>
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
