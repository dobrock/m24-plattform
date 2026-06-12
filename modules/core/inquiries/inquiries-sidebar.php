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

        if ( class_exists( 'M24_Logger' ) ) {
            M24_Logger::info( 'inquiries_sidebar', 'Sidebar-Modul geladen', [ 'version' => M24_PLATTFORM_VERSION ] );
        }
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

        wp_localize_script( 'm24-inquiries-sidebar', 'M24SidebarConfig', [
            'submitUrl'        => esc_url( home_url( $submit_path ) ),
            'storageKey'       => 'm24_sidebar_items',
            'maxItems'         => 50,
            'userCanSeePrices' => M24_Inquiries::user_can_see_prices(),
            'i18n'       => [
                'title'         => __( 'Sammelanfrage', 'm24-plattform' ),
                'empty'         => __( 'Noch keine Positionen ausgewählt.', 'm24-plattform' ),
                'emptyHint'     => __( 'Füge Pakete oder Artikel über die jeweilige Detail-Seite hinzu.', 'm24-plattform' ),
                'qtyLabel'      => __( 'Menge', 'm24-plattform' ),
                'remove'        => __( 'Entfernen', 'm24-plattform' ),
                'submit'        => __( 'Sammelanfrage absenden', 'm24-plattform' ),
                'open'          => __( 'Sammelanfrage öffnen', 'm24-plattform' ),
                'close'         => __( 'Sammelanfrage schließen', 'm24-plattform' ),
                'badgeAria'     => __( 'Positionen in Sammelanfrage', 'm24-plattform' ),
                'addedToast'    => __( 'Zur Anfrage hinzugefügt', 'm24-plattform' ),
                'maxReached'    => __( 'Maximale Anzahl Positionen erreicht.', 'm24-plattform' ),
            ],
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
