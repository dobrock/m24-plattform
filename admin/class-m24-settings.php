<?php
/**
 * M24 Plattform — Settings-Page
 *
 * WP-Admin → M24 Plattform → "Einstellungen"
 *
 * Felder (Production):
 *   - API-URL                (z.B. https://motorsport24-api.onrender.com)
 *   - API-Key                (X-API-Key Token aus M24 Desk)
 *   - Fallback-Mail-Empfaenger (fuer Pfad A bei API-Ausfall, Inquiries-Modul)
 *
 * Felder (Test/Dev):
 *   - Test-Mode (an/aus)     - bei "an" werden Pushes auf den Mock-Endpoint umgelenkt
 *   - Mock-URL               - Default leer; leer-Wert bedeutet home_url('/wp-json/m24-plattform/v1/mock')
 *
 * Plus: "Verbindung testen"-Button → ruft GET /api/health → zeigt Ergebnis inline.
 *       Letzter erfolgreicher/fehlgeschlagener Health-Check wird persistiert.
 *
 * wp-config.php-Override (Spec v4 §4.10):
 *   - M24_DESK_API_URL    → API-URL-Feld wird read-only
 *   - M24_DESK_API_TOKEN  → API-Key-Feld wird read-only
 *   Konstanten haben Vorrang vor DB-Werten in M24_REST_Client::get_base_url()/get_api_key().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Settings {

    const OPTION_KEY      = 'm24_plattform_settings';
    const PAGE_SLUG       = 'm24-plattform';
    const CAPABILITY      = 'manage_options';
    const NONCE_ACTION    = 'm24_health_check';
    const BREVO_NONCE     = 'm24_brevo_test';
    const PROVISION_NONCE = 'm24_brevo_provision';

    public static function init() {
        add_action( 'admin_menu',     [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_init',     [ __CLASS__, 'register_settings' ] );
        add_action( 'wp_ajax_m24_health_check',    [ __CLASS__, 'ajax_health_check' ] );
        add_action( 'wp_ajax_m24_brevo_test',      [ __CLASS__, 'ajax_brevo_test' ] );
        add_action( 'wp_ajax_m24_brevo_provision', [ __CLASS__, 'ajax_brevo_provision' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_notices',  [ __CLASS__, 'maybe_render_test_mode_banner' ] );
    }

    public static function register_menu() {
        add_menu_page(
            __( 'MOTORSPORT24', 'm24-plattform' ),
            __( 'MOTORSPORT24', 'm24-plattform' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ],
            'dashicons-car',
            25.5 // §1: Dach sauber zwischen Inhalts- und Plugin-Bereich
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __( 'Einstellungen', 'm24-plattform' ),
            __( 'Einstellungen', 'm24-plattform' ),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings() {
        register_setting(
            'm24_plattform_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize' ],
                'default'           => self::defaults(),
            ]
        );
        // SEO: globaler Index-Schalter fuer Teile-Detailseiten (Default 0 = noindex).
        // Per Konstante M24_TEILE_INDEX uebersteuerbar (s. m24_teile_index_enabled()).
        register_setting(
            'm24_plattform_group',
            'm24_teile_index',
            [
                'type'              => 'boolean',
                'sanitize_callback' => static function ( $v ) { return ! empty( $v ) ? 1 : 0; },
                'default'           => 0,
            ]
        );
        // G2a: Magic-Link-Header-Login (Beta). Default AUS — erst nach Live-Verifikation einschalten.
        register_setting(
            'm24_plattform_group',
            'm24_magiclink_enabled',
            [
                'type'              => 'boolean',
                'sanitize_callback' => static function ( $v ) { return ! empty( $v ) ? 1 : 0; },
                'default'           => 0,
            ]
        );
        // Garage-Alerts (Etappe 3): per-Fahrzeug Preis-/Status-Mails. Default AUS — erst nach §7-Opt-out an.
        register_setting(
            'm24_plattform_group',
            'm24_garage_alerts_enabled',
            [
                'type'              => 'boolean',
                'sanitize_callback' => static function ( $v ) { return ! empty( $v ) ? 1 : 0; },
                'default'           => 0,
            ]
        );
        // Brevo-API-Key (Interessentenliste, Phase 2). Maskiert; nie im Klartext gerendert.
        register_setting(
            'm24_plattform_group',
            'm24_brevo_api_key',
            [
                'type'              => 'string',
                'sanitize_callback' => [ __CLASS__, 'sanitize_brevo_key' ],
                'default'           => '',
            ]
        );
        // Off-Market-Brevo-Liste (DOI). Solange 0/leer: Off-Market-Formular „In Vorbereitung" + disabled.
        register_setting(
            'm24_plattform_group',
            'm24_offmarket_list_id',
            [
                'type'              => 'integer',
                'sanitize_callback' => static function ( $v ) { return max( 0, (int) $v ); },
                'default'           => 0,
            ]
        );
    }

    /**
     * Masked-Field-Sanitize: Leeres Feld ODER zurückgeschickte Maskierung (•) = unverändert lassen.
     * Per Konstante M24_BREVO_API_KEY gesetzt → DB-Wert nie überschreiben.
     */
    public static function sanitize_brevo_key( $input ) {
        $existing = (string) get_option( 'm24_brevo_api_key', '' );
        if ( class_exists( 'M24_Brevo_Client' ) && M24_Brevo_Client::key_locked_by_config() ) {
            return $existing;
        }
        $val = is_string( $input ) ? trim( $input ) : '';
        if ( '' === $val || false !== strpos( $val, '•' ) ) {
            return $existing;
        }
        return sanitize_text_field( $val );
    }

    public static function defaults() {
        return [
            'api_url'          => 'https://motorsport24-api.onrender.com',
            'api_key'          => '',
            'fallback_mail_to' => 'service@motorsport24.de',
            'test_mode'        => false,
            'mock_url'         => '',
            // Read-only intern, nicht im Form-Submit setzbar:
            'last_health_ok'   => null,  // ISO-Timestamp letzter Erfolg
            'last_health_err'  => null,  // [ time, status, error ] letzter Fehler
        ];
    }

    public static function sanitize( $input ) {
        $defaults = self::defaults();
        // Bestehende Werte mergen, damit last_health_* nicht durch Form-Submit verloren geht.
        $existing = wp_parse_args( get_option( self::OPTION_KEY, [] ), $defaults );
        $out      = $existing;

        $out['api_url']          = isset( $input['api_url'] ) ? esc_url_raw( trim( $input['api_url'] ) ) : $defaults['api_url'];
        $out['api_key']          = isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : '';
        $out['fallback_mail_to'] = isset( $input['fallback_mail_to'] ) ? sanitize_email( trim( $input['fallback_mail_to'] ) ) : $defaults['fallback_mail_to'];
        $out['test_mode']        = ! empty( $input['test_mode'] );
        $out['mock_url']         = isset( $input['mock_url'] ) ? esc_url_raw( trim( $input['mock_url'] ) ) : '';

        // last_health_ok / last_health_err: Settings-API ruft sanitize() auch auf
        // direkten update_option()-Calls aus dem AJAX-Handler auf. Wenn $input
        // diese Keys traegt (vom AJAX-Pfad), uebernehmen wir sie 1:1 — sonst
        // bleibt der bestehende Wert aus $existing erhalten.
        if ( array_key_exists( 'last_health_ok', $input ) ) {
            $out['last_health_ok'] = is_string( $input['last_health_ok'] ) || is_null( $input['last_health_ok'] )
                ? $input['last_health_ok']
                : null;
        }
        if ( array_key_exists( 'last_health_err', $input ) ) {
            // Erwartet: array|null. Defensive Type-Check.
            if ( is_array( $input['last_health_err'] ) ) {
                $err = $input['last_health_err'];
                $out['last_health_err'] = [
                    'time'   => isset( $err['time'] )   ? sanitize_text_field( (string) $err['time'] )   : '',
                    'status' => isset( $err['status'] ) ? (int) $err['status']                            : 0,
                    'error'  => isset( $err['error'] )  ? sanitize_text_field( (string) $err['error'] )  : '',
                ];
            } elseif ( is_null( $input['last_health_err'] ) ) {
                $out['last_health_err'] = null;
            }
        }

        return $out;
    }

    /**
     * Hat wp-config.php die API-URL via Konstante gesetzt?
     */
    public static function api_url_overridden_by_config() {
        return defined( 'M24_DESK_API_URL' ) && ! empty( M24_DESK_API_URL );
    }

    /**
     * Hat wp-config.php den API-Key via Konstante gesetzt?
     */
    public static function api_key_overridden_by_config() {
        return defined( 'M24_DESK_API_TOKEN' ) && ! empty( M24_DESK_API_TOKEN );
    }

    /**
     * Test-Mode aktiv? (rein DB-Setting; Konstanten-Override ist nicht vorgesehen,
     * weil Test-Mode eine bewusste Dev-Entscheidung ist und kein Production-Setting.)
     */
    public static function is_test_mode_active() {
        $settings = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );
        return ! empty( $settings['test_mode'] );
    }

    /**
     * Effektive Mock-URL fuer den Test-Mode.
     * Leer-Wert in DB → Default home_url('/wp-json/m24-plattform/v1/mock').
     */
    public static function effective_mock_url() {
        $settings = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );
        $url = trim( (string) ( $settings['mock_url'] ?? '' ) );
        if ( $url === '' ) {
            $url = home_url( '/wp-json/m24-plattform/v1/mock' );
        }
        return rtrim( $url, '/' );
    }

    /**
     * Roter Banner im WP-Admin, wenn Test-Mode aktiv.
     * Sichtbar auf allen Admin-Seiten (auch ausserhalb der M24-Pages),
     * damit man im Tagesgeschaeft nicht vergisst, dass keine Production-Pushes laufen.
     */
    public static function maybe_render_test_mode_banner() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        if ( ! self::is_test_mode_active() ) {
            return;
        }

        $settings_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        $mock_url     = self::effective_mock_url();
        ?>
        <div class="notice notice-error" style="border-left-color:#c8102e;background:#fdf1f3;">
            <p style="font-weight:600;color:#c8102e;font-size:14px;margin:8px 0;">
                <span class="dashicons dashicons-warning" style="color:#c8102e;"></span>
                <?php echo esc_html__( 'M24 Plattform — TEST-MODUS aktiv', 'm24-plattform' ); ?>
            </p>
            <p style="color:#5a6474;font-size:13px;margin:4px 0 8px;">
                <?php
                printf(
                    /* translators: 1: mock URL, 2: settings URL */
                    esc_html__( 'API-Pushes gehen an den lokalen Mock-Endpoint (%1$s). Keine Daten an Production. %2$s', 'm24-plattform' ),
                    '<code>' . esc_html( $mock_url ) . '</code>',
                    '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Test-Modus deaktivieren', 'm24-plattform' ) . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    public static function enqueue_assets( $hook ) {
        // nur auf unserer Settings-Page laden
        if ( strpos( (string) $hook, self::PAGE_SLUG ) === false ) {
            return;
        }

        wp_enqueue_script(
            'm24-admin',
            M24_PLATTFORM_URL . 'assets/admin.js',
            [ 'jquery' ],
            M24_PLATTFORM_VERSION,
            true
        );

        wp_localize_script( 'm24-admin', 'M24Admin', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
            'brevoNonce'     => wp_create_nonce( self::BREVO_NONCE ),
            'provisionNonce' => wp_create_nonce( self::PROVISION_NONCE ),
            'i18n'    => [
                'testing' => __( 'Teste Verbindung...', 'm24-plattform' ),
                'success' => __( 'Verbindung OK', 'm24-plattform' ),
                'error'   => __( 'Verbindung fehlgeschlagen', 'm24-plattform' ),
            ],
        ] );

        wp_add_inline_style( 'wp-admin', '
            .m24-test-result { display:inline-block; margin-left:12px; padding:6px 12px; border-radius:4px; font-size:13px; vertical-align:middle; }
            .m24-test-result.ok { background:#edf7f1; color:#1a7a3c; border:1px solid #1a7a3c; }
            .m24-test-result.fail { background:#fdf1f3; color:#c8102e; border:1px solid #c8102e; }
            .m24-test-result.testing { background:#eef3fb; color:#1a5fb4; border:1px solid #1a5fb4; }
            .m24-test-detail { margin-top:8px; font-family:monospace; font-size:12px; color:#5a6474; }
            .m24-config-locked { background:#f0f0f1; color:#5a6474; }
            .m24-config-locked-hint { color:#b87000; font-size:12px; margin-top:4px; }
            .m24-health-history { background:#f7f8fa; border:1px solid #e0e3e8; padding:10px 14px; border-radius:4px; margin-top:10px; font-size:13px; }
            .m24-health-history .m24-hh-ok { color:#1a7a3c; font-weight:600; }
            .m24-health-history .m24-hh-err { color:#c8102e; font-weight:600; }
        ' );
    }

    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( __( 'Keine Berechtigung.', 'm24-plattform' ) );
        }

        $settings        = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );
        $url_locked      = self::api_url_overridden_by_config();
        $key_locked      = self::api_key_overridden_by_config();
        $effective_url   = $url_locked ? (string) M24_DESK_API_URL : $settings['api_url'];
        $effective_key   = $key_locked ? '(via wp-config.php gesetzt)' : $settings['api_key'];
        $is_test_mode    = ! empty( $settings['test_mode'] );
        $mock_default    = home_url( '/wp-json/m24-plattform/v1/mock' );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'MOTORSPORT24 — Einstellungen', 'm24-plattform' ); ?></h1>
            <?php do_action( 'm24_settings_top' ); // Ein-Klick-Update-Button (M24_OneClick_Update) ?>
            <p><?php echo esc_html__( 'Verbindung zwischen WordPress-Plugin und M24 Desk Backend.', 'm24-plattform' ); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'm24_plattform_group' );
                ?>
                <h2><?php echo esc_html__( 'Production-Verbindung', 'm24-plattform' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="m24_api_url"><?php echo esc_html__( 'API-URL', 'm24-plattform' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="url"
                                id="m24_api_url"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_url]"
                                value="<?php echo esc_attr( $url_locked ? (string) M24_DESK_API_URL : $settings['api_url'] ); ?>"
                                class="regular-text<?php echo $url_locked ? ' m24-config-locked' : ''; ?>"
                                placeholder="https://motorsport24-api.onrender.com"
                                <?php echo $url_locked ? 'readonly' : ''; ?>
                            />
                            <p class="description">
                                <?php echo esc_html__( 'Basis-URL des M24 Desk Backends, ohne abschliessenden Slash.', 'm24-plattform' ); ?>
                            </p>
                            <?php if ( $url_locked ): ?>
                                <p class="m24-config-locked-hint">
                                    <span class="dashicons dashicons-lock"></span>
                                    <?php echo esc_html__( 'Wert via wp-config.php (Konstante M24_DESK_API_URL) gesetzt — DB-Wert wird ignoriert.', 'm24-plattform' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="m24_api_key"><?php echo esc_html__( 'API-Key (X-API-Key)', 'm24-plattform' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="m24_api_key"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
                                value="<?php echo esc_attr( $key_locked ? '' : $settings['api_key'] ); ?>"
                                class="regular-text code<?php echo $key_locked ? ' m24-config-locked' : ''; ?>"
                                placeholder="<?php echo esc_attr( $key_locked ? __( '(via wp-config.php gesetzt)', 'm24-plattform' ) : 'm24_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' ); ?>"
                                autocomplete="off"
                                spellcheck="false"
                                <?php echo $key_locked ? 'readonly' : ''; ?>
                            />
                            <p class="description">
                                <?php echo esc_html__( 'Service-Token aus M24 Desk. Wird als X-API-Key Header gesendet.', 'm24-plattform' ); ?>
                            </p>
                            <?php if ( $key_locked ): ?>
                                <p class="m24-config-locked-hint">
                                    <span class="dashicons dashicons-lock"></span>
                                    <?php echo esc_html__( 'Wert via wp-config.php (Konstante M24_DESK_API_TOKEN) gesetzt — DB-Wert wird ignoriert. Empfohlen fuer Production.', 'm24-plattform' ); ?>
                                </p>
                            <?php else: ?>
                                <p class="description" style="color:#b87000;font-size:12px;">
                                    <?php echo esc_html__( 'Tipp: Fuer Production die Konstante M24_DESK_API_TOKEN in wp-config.php setzen, damit der Token nicht im DB-Dump landet.', 'm24-plattform' ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="m24_fallback_mail"><?php echo esc_html__( 'Fallback-Mail-Empfaenger', 'm24-plattform' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="email"
                                id="m24_fallback_mail"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fallback_mail_to]"
                                value="<?php echo esc_attr( $settings['fallback_mail_to'] ); ?>"
                                class="regular-text"
                            />
                            <p class="description"><?php echo esc_html__( 'Empfaenger-Adresse, falls API-Push fehlschlaegt (Pfad A).', 'm24-plattform' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:24px;"><?php echo esc_html__( 'Brevo — Interessentenliste (Liste 3)', 'm24-plattform' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="m24_brevo_api_key"><?php echo esc_html__( 'Brevo API-Key', 'm24-plattform' ); ?></label>
                        </th>
                        <td>
                            <?php
                            $brevo_locked = class_exists( 'M24_Brevo_Client' ) && M24_Brevo_Client::key_locked_by_config();
                            $brevo_masked = class_exists( 'M24_Brevo_Client' ) ? M24_Brevo_Client::masked_key() : '';
                            ?>
                            <input
                                type="text"
                                id="m24_brevo_api_key"
                                name="m24_brevo_api_key"
                                value=""
                                class="regular-text code<?php echo $brevo_locked ? ' m24-config-locked' : ''; ?>"
                                placeholder="<?php echo esc_attr( $brevo_locked ? __( '(via wp-config.php gesetzt)', 'm24-plattform' ) : ( $brevo_masked !== '' ? $brevo_masked : 'xkeysib-…' ) ); ?>"
                                autocomplete="off"
                                spellcheck="false"
                                <?php echo $brevo_locked ? 'readonly' : ''; ?>
                            />
                            <p class="description">
                                <?php echo esc_html__( 'Wird als Header „api-key" gesendet. Maskiert gespeichert — leer lassen behält den vorhandenen Key.', 'm24-plattform' ); ?>
                            </p>
                            <?php if ( $brevo_locked ) : ?>
                                <p class="m24-config-locked-hint">
                                    <span class="dashicons dashicons-lock"></span>
                                    <?php echo esc_html__( 'Wert via wp-config.php (Konstante M24_BREVO_API_KEY) gesetzt — DB-Wert wird ignoriert.', 'm24-plattform' ); ?>
                                </p>
                            <?php elseif ( $brevo_masked !== '' ) : ?>
                                <p class="description" style="color:#1a7a3c;font-size:12px;">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <?php echo esc_html__( 'Key gespeichert.', 'm24-plattform' ); ?> <code><?php echo esc_html( $brevo_masked ); ?></code>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="m24_offmarket_list_id"><?php echo esc_html__( 'Off-Market Listen-ID', 'm24-plattform' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="number" min="0" step="1"
                                id="m24_offmarket_list_id"
                                name="m24_offmarket_list_id"
                                value="<?php echo esc_attr( (string) (int) get_option( 'm24_offmarket_list_id', 0 ) ); ?>"
                                class="small-text"
                            />
                            <p class="description">
                                <?php echo esc_html__( 'Brevo-Listen-ID für die Off-Market-Anmeldung (DOI). Solange leer/0: Off-Market-Formular bleibt „In Vorbereitung" und deaktiviert. ID setzen → Formular live.', 'm24-plattform' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" id="m24-brevo-test-button" class="button">
                        <?php echo esc_html__( 'Verbindung testen', 'm24-plattform' ); ?>
                    </button>
                    <span id="m24-brevo-test-result" class="m24-test-result" style="display:none;"></span>
                    <br>
                    <span class="description"><?php echo esc_html__( 'Prüft via GET /v3/account. Neu eingetippter Key wird direkt getestet, sonst der gespeicherte.', 'm24-plattform' ); ?></span>
                </p>

                <?php
                $alert_map   = get_option( 'm24_alert_list_ids', array() );
                $alert_count = is_array( $alert_map ) ? count( $alert_map ) : 0;
                $alert_total = class_exists( 'M24_Alert_Taxonomie' ) ? count( M24_Alert_Taxonomie::tags() ) : 0;
                ?>
                <p style="margin-top:18px;">
                    <button type="button" id="m24-alert-provision-button" class="button">
                        <?php echo esc_html__( 'Alert-Listen anlegen / prüfen', 'm24-plattform' ); ?>
                    </button>
                    <span id="m24-alert-provision-result" class="m24-test-result" style="display:none;"></span>
                    <br>
                    <span class="description">
                        <?php
                        printf(
                            /* translators: 1: provisioned count, 2: total taxonomy count */
                            esc_html__( 'Legt fehlende Fahrzeug-Alert-Listen im Brevo-Ordner „M24 Alert" an (idempotent). Aktuell zugeordnet: %1$d von %2$d.', 'm24-plattform' ),
                            (int) $alert_count,
                            (int) $alert_total
                        );
                        ?>
                    </span>
                </p>

                <h2 style="margin-top:24px;"><?php echo esc_html__( 'Test-Modus (Dev)', 'm24-plattform' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="m24_test_mode"><?php echo esc_html__( 'Test-Modus aktiv', 'm24-plattform' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    id="m24_test_mode"
                                    name="<?php echo esc_attr( self::OPTION_KEY ); ?>[test_mode]"
                                    value="1"
                                    <?php checked( $is_test_mode ); ?>
                                />
                                <?php echo esc_html__( 'Pushes auf den Mock-Endpoint umlenken', 'm24-plattform' ); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__( 'Im Test-Modus werden Health-Check und Order-Push nicht ans Production-Backend, sondern an die Mock-URL unten geschickt. Erkennbar an einem roten Banner im gesamten WP-Admin.', 'm24-plattform' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="m24_mock_url"><?php echo esc_html__( 'Mock-URL', 'm24-plattform' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="url"
                                id="m24_mock_url"
                                name="<?php echo esc_attr( self::OPTION_KEY ); ?>[mock_url]"
                                value="<?php echo esc_attr( $settings['mock_url'] ); ?>"
                                class="regular-text"
                                placeholder="<?php echo esc_attr( $mock_default ); ?>"
                            />
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: default mock URL */
                                    esc_html__( 'Leer lassen fuer Default: %s', 'm24-plattform' ),
                                    '<code>' . esc_html( $mock_default ) . '</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__( 'SEO — Indexierung', 'm24-plattform' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Teile-Detailseiten indexieren', 'm24-plattform' ); ?></th>
                        <td>
                            <?php $teile_index_const = defined( 'M24_TEILE_INDEX' ); ?>
                            <label>
                                <input type="checkbox" name="m24_teile_index" value="1"
                                    <?php checked( m24_teile_index_enabled(), true ); ?>
                                    <?php disabled( $teile_index_const, true ); ?> />
                                <?php echo esc_html__( 'Teile auf „index, follow" schalten (aus = noindex, follow)', 'm24-plattform' ); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__( 'Übersichten (Gebrauchtteile/Rennsport) bleiben immer indexierbar; Modell-Filter-URLs bleiben noindex. Erst nach QA aller Teile aktivieren.', 'm24-plattform' ); ?>
                                <?php if ( $teile_index_const ) : ?>
                                    <br><span class="m24-config-locked-hint"><span class="dashicons dashicons-lock"></span>
                                    <?php echo esc_html__( 'Per Konstante M24_TEILE_INDEX gesetzt — Schalter inaktiv.', 'm24-plattform' ); ?></span>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:24px;"><?php echo esc_html__( 'Magic-Link-Login (Beta, G2a)', 'm24-plattform' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Header-Login-Button', 'm24-plattform' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="m24_magiclink_enabled" value="1" <?php checked( (bool) get_option( 'm24_magiclink_enabled', 0 ), true ); ?> />
                                <?php echo esc_html__( 'Login-/Abmelden-Button im Header anzeigen (nutzt die bestehende /haendler-login/-Magic-Link-Strecke)', 'm24-plattform' ); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__( 'Default aus. Erst nach Live-Verifikation aktivieren. Steuert nur den Header-Button — die bestehende Händler-Login-Seite bleibt unabhängig davon aktiv.', 'm24-plattform' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 style="margin-top:24px;"><?php echo esc_html__( 'Garage-Alerts (Beta, Etappe 3)', 'm24-plattform' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Preis-/Status-Mails senden', 'm24-plattform' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="m24_garage_alerts_enabled" value="1" <?php checked( (bool) get_option( 'm24_garage_alerts_enabled', 0 ), true ); ?> />
                                <?php echo esc_html__( 'Per-Fahrzeug-Alerts (Preisänderung / verkauft-reserviert) tatsächlich per E-Mail versenden', 'm24-plattform' ); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__( 'Default aus. Solange aus: Pipeline läuft + loggt („would-send", Kontext „alerts"), sendet aber nichts. Erst nach §7-UWG-Opt-out aktivieren.', 'm24-plattform' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php echo esc_html__( 'Verbindungstest', 'm24-plattform' ); ?></h2>
            <p><?php echo esc_html__( 'Prueft via GET /api/health, ob das Backend erreichbar ist und der API-Key gueltig ist.', 'm24-plattform' ); ?></p>
            <?php if ( $is_test_mode ): ?>
                <p style="color:#b87000;font-size:13px;">
                    <span class="dashicons dashicons-info"></span>
                    <?php echo esc_html__( 'Test-Modus aktiv: Health-Check geht gegen den Mock-Endpoint, nicht gegen Production.', 'm24-plattform' ); ?>
                </p>
            <?php endif; ?>
            <p>
                <button type="button" id="m24-test-button" class="button button-primary">
                    <?php echo esc_html__( 'Verbindung testen', 'm24-plattform' ); ?>
                </button>
                <span id="m24-test-result" class="m24-test-result" style="display:none;"></span>
            </p>
            <pre id="m24-test-detail" class="m24-test-detail" style="display:none;"></pre>

            <?php
            $last_ok  = $settings['last_health_ok']  ?? null;
            $last_err = $settings['last_health_err'] ?? null;
            if ( $last_ok || $last_err ):
                ?>
                <div class="m24-health-history">
                    <strong><?php echo esc_html__( 'Letzter Health-Check', 'm24-plattform' ); ?></strong>
                    <?php if ( $last_ok ): ?>
                        <div class="m24-hh-ok">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php echo esc_html__( 'Erfolg:', 'm24-plattform' ); ?>
                            <code><?php echo esc_html( $last_ok ); ?></code>
                        </div>
                    <?php endif; ?>
                    <?php if ( $last_err && is_array( $last_err ) ): ?>
                        <div class="m24-hh-err">
                            <span class="dashicons dashicons-warning"></span>
                            <?php
                            printf(
                                /* translators: 1: timestamp, 2: status, 3: error */
                                esc_html__( 'Fehler: %1$s — HTTP %2$s — %3$s', 'm24-plattform' ),
                                '<code>' . esc_html( $last_err['time'] ?? '' ) . '</code>',
                                esc_html( (string) ( $last_err['status'] ?? '?' ) ),
                                esc_html( (string) ( $last_err['error'] ?? '' ) )
                            );
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <hr>

            <h2><?php echo esc_html__( 'System-Info', 'm24-plattform' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Plugin-Version', 'm24-plattform' ); ?></th>
                    <td><code><?php echo esc_html( M24_PLATTFORM_VERSION ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'DB-Migration-Stand', 'm24-plattform' ); ?></th>
                    <td><code><?php echo esc_html( get_option( 'm24_plattform_db_version', '000' ) ); ?></code> (Ziel: <code><?php echo esc_html( M24_PLATTFORM_DB_VERSION ); ?></code>)</td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Effektive API-URL', 'm24-plattform' ); ?></th>
                    <td>
                        <code><?php echo esc_html( M24_REST_Client::get_base_url() ); ?></code>
                        <?php if ( $is_test_mode ): ?>
                            <span style="color:#b87000;">(Test-Modus: Mock)</span>
                        <?php elseif ( $url_locked ): ?>
                            <span style="color:#1a5fb4;">(via wp-config.php)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'PHP-Version', 'm24-plattform' ); ?></th>
                    <td><code><?php echo esc_html( PHP_VERSION ); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'WordPress-Version', 'm24-plattform' ); ?></th>
                    <td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
                </tr>
            </table>

            <?php
            // Plugin-Updates (Self-Updater) — One-Click-Updates aus dem GitHub-Repo.
            if ( class_exists( 'M24_Updater' ) ) {
                M24_Updater::render_settings_section();
            }
            ?>
        </div>
        <?php
    }

    /**
     * AJAX-Handler fuer den Test-Button.
     * Erwartet: action=m24_health_check, _ajax_nonce
     * Antwortet: JSON mit { ok, status, data, error, elapsed_ms }
     *
     * Persistiert das Ergebnis in last_health_ok / last_health_err der Settings.
     */
    public static function ajax_health_check() {
        check_ajax_referer( self::NONCE_ACTION );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'error' => 'forbidden' ], 403 );
        }

        $started = microtime( true );
        $result  = M24_REST_Client::health();
        $elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

        // Ergebnis in Settings persistieren.
        $now      = gmdate( 'Y-m-d\TH:i:s\Z' );
        $settings = wp_parse_args( get_option( self::OPTION_KEY, [] ), self::defaults() );

        if ( $result['ok'] ) {
            $version = is_array( $result['data'] ) && isset( $result['data']['version'] )
                ? (string) $result['data']['version']
                : '?';
            $settings['last_health_ok']  = $now . ' (v' . $version . ', ' . $elapsed . ' ms)';
            $settings['last_health_err'] = null;
        } else {
            $settings['last_health_err'] = [
                'time'   => $now,
                'status' => (int) $result['status'],
                'error'  => (string) $result['error'],
            ];
            // last_health_ok bleibt unveraendert — wir loeschen den letzten Erfolg nicht.
        }

        update_option( self::OPTION_KEY, $settings );

        wp_send_json( [
            'ok'         => $result['ok'],
            'status'     => $result['status'],
            'data'       => $result['data'],
            'error'      => $result['error'],
            'elapsed_ms' => $elapsed,
        ] );
    }

    /**
     * AJAX-Handler fuer den Brevo-Verbindungstest.
     * Erwartet: action=m24_brevo_test, _ajax_nonce, key (optional, frisch eingetippt).
     * Antwortet: { ok, code, msg, email }
     */
    public static function ajax_brevo_test() {
        check_ajax_referer( self::BREVO_NONCE );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'msg' => 'forbidden' ], 403 );
        }
        if ( ! class_exists( 'M24_Brevo_Client' ) ) {
            wp_send_json( [ 'ok' => false, 'code' => 0, 'msg' => 'Brevo-Client nicht geladen' ] );
        }

        // Maskierten Wert (•) oder leeres Feld als „nicht eingetippt" behandeln → gespeicherter Key.
        $typed = isset( $_POST['key'] ) ? trim( (string) wp_unslash( $_POST['key'] ) ) : '';
        if ( '' === $typed || false !== strpos( $typed, '•' ) ) {
            $typed = null;
        } else {
            $typed = sanitize_text_field( $typed );
        }

        $res   = M24_Brevo_Client::account( $typed );
        $email = is_array( $res['data'] ) && isset( $res['data']['email'] ) ? (string) $res['data']['email'] : '';

        wp_send_json( [
            'ok'    => $res['ok'],
            'code'  => $res['code'],
            'msg'   => $res['msg'],
            'email' => $email,
        ] );
    }

    /**
     * AJAX-Handler fuer das Alert-Listen-Provisioning.
     * Erwartet: action=m24_brevo_provision, _ajax_nonce. Antwortet: { ok, msg }
     */
    public static function ajax_brevo_provision() {
        check_ajax_referer( self::PROVISION_NONCE );

        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_send_json_error( [ 'msg' => 'forbidden' ], 403 );
        }
        if ( ! class_exists( 'M24_Brevo_Client' ) ) {
            wp_send_json( [ 'ok' => false, 'msg' => 'Brevo-Client nicht geladen' ] );
        }

        $res = M24_Brevo_Client::provision_alert_lists();
        wp_send_json( [ 'ok' => $res['ok'], 'msg' => $res['msg'] ] );
    }
}
