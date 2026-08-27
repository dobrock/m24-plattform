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

        // Sub-Module laden. Bewusst NICHT mit blankem require_once: fehlt eine Datei im Deploy, riss der
        // Fatal die komplette Seite mit — zuletzt seit 17.07. auf jeder Produktseite und bei
        // /wp-json/m24/v1/view-ping, ausgelöst durch eine fehlende inquiries-form.php. Die Datei liegt im
        // Repo; ein einzelner Übertragungsfehler darf den Shop trotzdem nicht lahmlegen.
        //
        // Jetzt: fehlende Datei überspringen, einmal täglich melden, Rest weiterladen. Das Anfrage-Formular
        // fehlt dann — sichtbar, aber die Seite lebt.
        foreach ( array(
            'inquiries-form.php'           => 'M24_Inquiries_Form',
            'inquiries-sidebar.php'        => 'M24_Inquiries_Sidebar',
            'inquiries-validation.php'     => 'M24_Inquiries_Validation',
            'inquiries-storage.php'        => 'M24_Inquiries_Storage',
            'inquiries-mock.php'           => 'M24_Inquiries_Mock',
            'inquiries-m24-push.php'       => 'M24_Inquiries_Push',
            'inquiries-mail-fallback.php'  => 'M24_Inquiries_Mail_Fallback',
        ) as $file => $class ) {
            $path = __DIR__ . '/' . $file;
            if ( ! is_readable( $path ) ) {
                self::report_missing( $file );
                continue;
            }
            require_once $path;
            if ( class_exists( $class ) && method_exists( $class, 'init' ) ) {
                $class::init();
            } else {
                self::report_missing( $file . ' (Klasse ' . $class . ' fehlt)' );
            }
        }
        // require_once __DIR__ . '/inquiries-retry-job.php';
        // if ( is_admin() ) {
        //     require_once __DIR__ . '/inquiries-admin-monitor.php';
        // }
    }

    /**
     * Fehlendes Sub-Modul melden — einmal pro Tag und Datei, damit ein Deploy-Fehler auffaellt, ohne das
     * Log bei jedem Seitenaufruf zuzumuellen.
     */
    private static function report_missing( string $file ): void {
        $key = 'm24_inq_missing_' . md5( $file );
        if ( get_transient( $key ) ) { return; }
        set_transient( $key, 1, DAY_IN_SECONDS );
        $msg = 'Anfrage-Modul fehlt im Deploy: ' . $file . ' — Funktion eingeschraenkt, Seite laeuft weiter.';
        if ( class_exists( 'M24_Error_Log' ) ) {
            M24_Error_Log::capture( 'inquiries', 'error', $msg, array( 'datei' => $file, 'pfad' => __DIR__ ) );
        }
        error_log( 'M24 Plattform: ' . $msg );
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
