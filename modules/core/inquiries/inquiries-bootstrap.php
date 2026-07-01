<?php
/**
 * Inquiries-Modul — Bootstrap
 *
 * Modul-Loader und Status-Konstanten fuer das Anfragen-System.
 * Sub-Module (form, sidebar, validation, storage, push, fallback, retry,
 * admin-monitor) werden in spaeteren Sessions hier eingehaengt.
 *
 * Spec-Referenz: M24-Master-Spec-v4.md Kapitel 6 + 19.1
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class M24_Inquiries {

    // ── Sync-Status (DB-Spalte sync_status) ─────────────────────────────
    const STATUS_PENDING       = 'pending_api_push';
    const STATUS_SYNCED        = 'synced';
    const STATUS_SYNCED_MAIL   = 'synced_via_mail';
    const STATUS_FAILED        = 'sync_failed';

    // ── Inquiry-Source (DB-Spalte inquiry_source) ───────────────────────
    const SOURCE_CART          = 'cart';
    const SOURCE_CONTACT       = 'contact_form';
    const SOURCE_PRODUCT       = 'product_inquiry';
    const SOURCE_BLOG          = 'blog_inquiry';

    // ── Pillar (Item-Feld src_pillar) ───────────────────────────────────
    const PILLAR_GEBRAUCHTTEILE = 'gebrauchtteile';
    const PILLAR_KATALOG        = 'katalog';
    const PILLAR_FAHRZEUG       = 'fahrzeug';
    const PILLAR_BLOG           = 'blog';

    // ── Sender-Lang ─────────────────────────────────────────────────────
    const LANG_DE = 'de';
    const LANG_EN = 'en';

    /** @var bool Schutz gegen doppelte Init */
    private static $initialized = false;

    /**
     * Modul initialisieren. Wird aus m24-plattform.php auf 'plugins_loaded'
     * Priority 10 aufgerufen (NACH Database/Logger, weil die im Bootstrap
     * geladen werden).
     */
    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        // Sub-Module laden, sobald sie existieren
        require_once __DIR__ . '/inquiries-form.php';
        M24_Inquiries_Form::init();
        require_once __DIR__ . '/inquiries-sidebar.php';
        M24_Inquiries_Sidebar::init();
        require_once __DIR__ . '/inquiries-validation.php';
        M24_Inquiries_Validation::init();
        require_once __DIR__ . '/inquiries-storage.php';
        M24_Inquiries_Storage::init();
        require_once __DIR__ . '/inquiries-mock.php';
        M24_Inquiries_Mock::init();
        // require_once __DIR__ . '/inquiries-source-tracker.php';
        require_once __DIR__ . '/inquiries-m24-push.php';
        M24_Inquiries_Push::init();
        require_once __DIR__ . '/inquiries-mail-fallback.php';
        M24_Inquiries_Mail_Fallback::init();
        // require_once __DIR__ . '/inquiries-retry-job.php';
        // if ( is_admin() ) {
        //     require_once __DIR__ . '/inquiries-admin-monitor.php';
        // }

        if ( defined( 'M24_LOG_MODULE_LOADS' ) && M24_LOG_MODULE_LOADS && class_exists( 'M24_Logger' ) ) {
            M24_Logger::info(
                'inquiries_bootstrap',
                'Inquiries-Modul geladen',
                [ 'version' => M24_PLATTFORM_VERSION ]
            );
        }
    }

    /**
     * Liste aller gueltigen Status-Werte (fuer Validation).
     */
    public static function valid_statuses() {
        return [
            self::STATUS_PENDING,
            self::STATUS_SYNCED,
            self::STATUS_SYNCED_MAIL,
            self::STATUS_FAILED,
        ];
    }

    /**
     * Liste aller gueltigen Inquiry-Sources.
     */
    public static function valid_sources() {
        return [
            self::SOURCE_CART,
            self::SOURCE_CONTACT,
            self::SOURCE_PRODUCT,
            self::SOURCE_BLOG,
        ];
    }

    /**
     * Liste aller gueltigen Pillars (Item-Level src_pillar).
     */
    public static function valid_pillars() {
        return [
            self::PILLAR_GEBRAUCHTTEILE,
            self::PILLAR_KATALOG,
            self::PILLAR_FAHRZEUG,
            self::PILLAR_BLOG,
        ];
    }

    /**
     * Darf der aktuelle Besucher Preise sehen?
     *
     * Stub für Phase 1: hart false, bis B2B-Login + Freischaltung steht.
     * Override via Filter `m24_user_can_see_prices` (z.B. für lokale Tests
     * oder spätere Login-Logik in Phase 2).
     *
     * @return bool
     */
    public static function user_can_see_prices() {
        return (bool) apply_filters( 'm24_user_can_see_prices', false );
    }

    /**
     * Platzhalter-String für versteckte Preise (Anfrage-Page).
     */
    public static function price_login_placeholder() {
        return __( 'nach Login', 'm24-plattform' );
    }
}
