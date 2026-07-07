<?php
/**
 * M24 Plattform — Inquiries-Modul: Sammelanfrage-Sidebar
 *
 * Schritt B2: Floating Sidebar rechts unten, LocalStorage-driven.
 * - Globale JS-API: M24Sidebar.addItem({ art, qty, price, src_url, src_pillar, src_modell, src_pid })
 * - Persistenz: LocalStorage-Key 'm24_sidebar_items'
 * - Submit: Sessionless POST an Anfrage-Page mit items_json als Hidden-Field
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries_Sidebar {

    private static $initialized = false;

    /**
     * Default-Slug der Anfrage-Page (Submit-Ziel).
     * Kann via Filter 'm24_inquiries_sidebar_submit_url' überschrieben werden.
     */
    const DEFAULT_SUBMIT_PATH = '/m24-anfrage-test/';

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_footer',          [ __CLASS__, 'render_skeleton' ] );
    }

    /**
     * CSS + JS einbinden, plus Übergabe-Konfiguration nach JS.
     */
    public static function enqueue_assets() {
        // Im Admin nicht laden
        if ( is_admin() ) {
            return;
        }

        $base_url = plugin_dir_url( M24_PLATTFORM_FILE );
        $version  = defined( 'M24_PLATTFORM_VERSION' ) ? M24_PLATTFORM_VERSION : '0.1.0';

        wp_enqueue_style(
            'm24-inquiries-sidebar',
            $base_url . 'assets/css/inquiries-sidebar.css',
            [],
            $version
        );

        wp_enqueue_script(
            'm24-inquiries-sidebar',
            $base_url . 'assets/js/inquiries-sidebar.js',
            [],
            $version,
            true // im Footer laden
        );

        $submit_path = apply_filters( 'm24_inquiries_sidebar_submit_url', self::DEFAULT_SUBMIT_PATH );

        // Drawer wird per JS injiziert → GTranslate erreicht ihn nicht. Serverseitige /en/-Erkennung ist in
        // GTranslates URL-/Proxy-Modus unzuverlässig (Origin-Render sieht oft kein /en/, kein googtrans-Cookie).
        // Robust: BEIDE Sprachen einbetten; das JS wählt clientseitig nach der echten Browser-URL (location).
        $keymap = array(
            'title' => 'cart_title', 'empty' => 'cart_empty', 'emptyHint' => 'cart_empty_hint',
            'qtyLabel' => 'cart_qty', 'remove' => 'cart_remove', 'submit' => 'cart_submit',
            'open' => 'cart_open', 'close' => 'cart_close', 'badgeAria' => 'cart_badge_aria',
            'addedToast' => 'cart_added', 'maxReached' => 'cart_max',
        );
        $have_i18n = class_exists( 'M24_I18n' );
        $lang_de   = $have_i18n ? M24_I18n::js_strings( $keymap, 'de' ) : array();
        $lang_en   = $have_i18n ? M24_I18n::js_strings( $keymap, 'en' ) : array();
        $srv_lang  = $have_i18n ? M24_I18n::display_lang() : 'de';

        // Diagnose (opt-in via M24_I18N_DEBUG): was liefert die serverseitige Sprach-Erkennung im Drawer-Render?
        if ( defined( 'M24_I18N_DEBUG' ) && M24_I18N_DEBUG ) {
            error_log( sprintf(
                'M24 i18n drawer: display_lang=%s REQUEST_URI=%s googtrans=%s',
                $srv_lang,
                isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '(none)',
                isset( $_COOKIE['googtrans'] ) ? (string) $_COOKIE['googtrans'] : '(none)'
            ) );
        }

        wp_localize_script( 'm24-inquiries-sidebar', 'M24SidebarConfig', [
            'submitUrl'        => esc_url( home_url( $submit_path ) ),
            'storageKey'       => 'm24_sidebar_items',
            'maxItems'         => 50,
            'userCanSeePrices' => M24_Inquiries::user_can_see_prices(),
            'srvLang'          => $srv_lang,       // serverseitige Best-Guess-Sprache (Fallback)
            'i18n'             => ( 'en' === $srv_lang ) ? $lang_en : $lang_de, // Basis (Nicht-Proxy-Setups)
            'i18nDe'           => $lang_de,        // vollständige DE-/EN-Sets für die clientseitige Auswahl
            'i18nEn'           => $lang_en,
        ] );
    }

    /**
     * Statisches HTML-Skelett. Inhalt wird vom JS via DOM-Manipulation gefüllt.
     * Alles im wp_footer-Hook, damit es ans Body-Ende kommt.
     */
    public static function render_skeleton() {
        if ( is_admin() ) {
            return;
        }
        ?>
        <div id="m24-sidebar-root" class="m24-sidebar" data-state="closed" aria-hidden="true">

            <button
                type="button"
                class="m24-sidebar__toggle"
                data-m24-action="toggle"
                aria-label="<?php esc_attr_e( 'Sammelanfrage öffnen', 'm24-plattform' ); ?>"
                aria-expanded="false"
                aria-controls="m24-sidebar-panel"
            >
                <span class="m24-sidebar__toggle-icon" aria-hidden="true">📋</span>
                <span class="m24-sidebar__badge" data-m24-count aria-hidden="true">0</span>
            </button>

            <aside
                id="m24-sidebar-panel"
                class="m24-sidebar__panel"
                role="dialog"
                aria-label="<?php esc_attr_e( 'Sammelanfrage', 'm24-plattform' ); ?>"
            >
                <header class="m24-sidebar__header">
                    <h2 class="m24-sidebar__title"><?php esc_html_e( 'Sammelanfrage', 'm24-plattform' ); ?></h2>
                    <button
                        type="button"
                        class="m24-sidebar__close"
                        data-m24-action="close"
                        aria-label="<?php esc_attr_e( 'Sammelanfrage schließen', 'm24-plattform' ); ?>"
                    >&times;</button>
                </header>

                <div class="m24-sidebar__body" data-m24-list>
                    <!-- Items via JS gerendered -->
                </div>

                <footer class="m24-sidebar__footer">
                    <button
                        type="button"
                        class="m24-sidebar__submit"
                        data-m24-action="submit"
                        disabled
                    >
                        <?php esc_html_e( 'Sammelanfrage absenden', 'm24-plattform' ); ?>
                    </button>
                </footer>
            </aside>

        </div>
        <?php
    }
}
