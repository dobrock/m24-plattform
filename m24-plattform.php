<?php
/**
 * Plugin Name:       M24 Plattform
 * Plugin URI:        https://www.motorsport24.de
 * Description:       B2B-Sammelanfragen, Händler-Auth, Bestand, Katalog. Pusht Anfragen an M24 Desk.
 * Version:           0.11.227
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            MOTORSPORT24 GmbH
 * Author URI:        https://www.motorsport24.de
 * License:           GPL v2 or later
 * Text Domain:       m24-plattform
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'M24_PLATTFORM_FILE',        __FILE__ );

/**
 * Version = EINZIGE Quelle der Wahrheit ist der „Version:"-Header oben. Die Laufzeit-Konstante
 * (steuert ?ver= der Assets + Updater-Vergleich) wird daraus abgeleitet → Header, Plugins-Seite,
 * Updater und Asset-Cache-Busting können NICHT mehr auseinanderlaufen. get_file_data() ist zu
 * diesem Zeitpunkt (Plugin-Load nach wp-includes) verfügbar; Fallback nur als Sicherheitsnetz.
 */
if ( ! defined( 'M24_PLATTFORM_VERSION' ) ) {
    $m24_hdr = function_exists( 'get_file_data' )
        ? get_file_data( __FILE__, array( 'v' => 'Version' ) )
        : array( 'v' => '' );
    define( 'M24_PLATTFORM_VERSION', ! empty( $m24_hdr['v'] ) ? $m24_hdr['v'] : '0.11.118' );
    unset( $m24_hdr );
}
define( 'M24_PLATTFORM_DIR',         plugin_dir_path( __FILE__ ) );
define( 'M24_PLATTFORM_URL',         plugin_dir_url( __FILE__ ) );
define( 'M24_PLATTFORM_DB_VERSION',  '010' );
// NUR erhöhen, wenn sich Rewrite-Rules ändern (triggert Self-Healing-Flush, nicht bei jedem Bump).
define( 'M24_REWRITE_VERSION',       '5' );

/**
 * Pflicht-Hinweis fuer Rennsport-Neuteile. Wird im Detail-Template als
 * Fallback fuer `_m24_hinweis` ausgegeben (Typ='neu'), und vom Shopware-Importer
 * (Paket D) als Default fuer `_m24_hinweis` gesetzt, wenn das Quell-Customfield leer ist.
 * Ueberschreibbar via Filter `m24_rennsport_hinweis`.
 */
define( 'M24_RENNSPORT_HINWEIS', 'Verkauf nur für den Rennsport – kein Gutachten, keine Eintragung.' );

function m24_rennsport_hinweis() {
    return apply_filters( 'm24_rennsport_hinweis', M24_RENNSPORT_HINWEIS );
}

/**
 * Typ-abhaengiges Detail-Header-Logo (oben rechts).
 *  - 'gebraucht' → BMW-Logo
 *  - 'neu'       → MOTORSPORT24-Logo
 * Files liegen in assets/img/. Ueberschreibbar via Filter `m24_detail_logo` ($url, $typ).
 */
define( 'M24_DETAIL_LOGO_BMW', 'assets/img/bmw-logo.png' );
define( 'M24_DETAIL_LOGO_M24', 'assets/img/m24-logo.png' );

function m24_detail_logo_url( $typ ) {
    $relative = ( 'neu' === $typ ) ? M24_DETAIL_LOGO_M24 : M24_DETAIL_LOGO_BMW;
    $default  = plugins_url( $relative, M24_PLATTFORM_FILE );
    return apply_filters( 'm24_detail_logo', $default, $typ );
}

/**
 * DSGVO-Consent-Text fuer das Anfrage-/Sammelanfrage-Modal.
 * %s = Datenschutzerklaerungs-Link. Ueberschreibbar via Filter `m24_consent_text`.
 */
define( 'M24_INQUIRY_CONSENT_TEXT', 'Ich willige ein, dass meine angegebenen Daten zur Bearbeitung meiner Anfrage gespeichert und verarbeitet werden. Hinweise zur Verarbeitung und zu meinem Widerrufsrecht finde ich in der %s. *' );

function m24_consent_text() {
    return apply_filters( 'm24_consent_text', M24_INQUIRY_CONSENT_TEXT );
}

/**
 * Datenschutzerklaerungs-URL. Default = WP-Datenschutzseite via get_privacy_policy_url().
 * Ueberschreibbar via Filter `m24_datenschutz_url`.
 */
function m24_datenschutz_url() {
    $url = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
    return apply_filters( 'm24_datenschutz_url', $url );
}

/**
 * Hinweis-Text fuer den Tab „Herstellungshinweise" auf Detail-Seiten
 * von Leichtbauteilen. Ueberschreibbar via Filter `m24_leichtbau_hinweis`.
 */
define( 'M24_LEICHTBAU_HINWEIS', 'Unsere Leichtbauteile werden ausschließlich in Deutschland von deutschen Fachbetrieben hergestellt. Bitte beachten Sie, dass wir rein für den Rennsport produzieren und keine Gutachten oder sonstige Unterlagen zur Verfügung stellen.' );

function m24_leichtbau_hinweis() {
    return apply_filters( 'm24_leichtbau_hinweis', M24_LEICHTBAU_HINWEIS );
}

/**
 * Platzhalter-Bild fuer bildlose Teile — EINE Quelle der Wahrheit fuer Detail-Hauptbild,
 * Grid-Thumbnails (related/Archiv) und og:image-Default. Ueberschreibbar via Filter
 * `m24_noimg_placeholder`. Wird ueberall als CSS-Background eingesetzt (kein <img> →
 * nicht in Image-Sitemap / nicht als Produktbild indexiert).
 */
define( 'M24_NOIMG_PLACEHOLDER', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2026/06/bild-folgt.png' );

function m24_noimg_placeholder_url() {
    return apply_filters( 'm24_noimg_placeholder', M24_NOIMG_PLACEHOLDER );
}

/**
 * Globaler Index-Schalter fuer Teile-Detailseiten (Vorbereitung Index-Flip).
 * Konstante M24_TEILE_INDEX (wp-config) hat Vorrang, sonst Option `m24_teile_index`.
 * Default 0 = noindex,follow. true → index,follow. Greift via wpSEO-Filter `wpseo_set_robots`.
 */
function m24_teile_index_enabled() {
    if ( defined( 'M24_TEILE_INDEX' ) ) { return (bool) M24_TEILE_INDEX; }
    return (bool) (int) get_option( 'm24_teile_index', 0 );
}

/**
 * Globaler Absendername „MOTORSPORT24" statt WP-Default „WordPress". Greift NUR, wenn eine Mail
 * KEINEN eigenen From-Header setzt (Produkt-/Fahrzeug-Anfragen mit Kundenname bleiben unberührt,
 * da sie ein explizites From: mitgeben). Reply-To bleibt wie gehabt.
 */
add_filter( 'wp_mail_from_name', function ( $name ) {
    return ( '' === (string) $name || 'WordPress' === $name ) ? 'MOTORSPORT24' : $name;
}, 9 );

require_once M24_PLATTFORM_DIR . 'includes/class-m24-database.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-logger.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-cache.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-rest-client.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-alert-taxonomie.php'; // Fahrzeug-Alert: Tag-Landkarte + Rollup (rein)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-brevo-client.php'; // Brevo-API-Wrapper (Phase 2: Liste 3 + Alert-Listen)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-brevo-il.php';     // Interessentenliste plugin-managed DOI + Alert-Spiegel
require_once M24_PLATTFORM_DIR . 'includes/class-m24-garage.php';      // Meine Garage G1: Store + Add-to-Garage + DOI (No-Account)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-garage-cart.php'; // Meine Garage Etappe 1: kontogebundener Warenkorb (Menge, Garage-Seite, Zähler)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-garage-pdf.php';  // Meine Garage Etappe 3: Garage als PDF (Dompdf, vendor/)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-garage-alerts.php'; // Meine Garage Etappe 3: per-Fahrzeug-Änderungs-Alerts (Flag-gated)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-i18n.php';         // i18n-Fundament (DE/EN): String-Registry + Sprachauflösung
require_once M24_PLATTFORM_DIR . 'includes/class-m24-admin-bar.php';    // Admin-Bar: Direktlink zum korrekten M24-Editor je CPT
require_once M24_PLATTFORM_DIR . 'includes/class-m24-login.php';        // Passwordless Magic-Link-Login „D" (flag-gated, Default aus)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-account.php';      // Konto-/Einstellungsseite (Entwurf 1) im Benachrichtigungen-Tab
require_once M24_PLATTFORM_DIR . 'includes/lang/class-m24-lang-endpoint.php'; // /sprache/?to=de|en (Mail-Footer-Sprachumschalter)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-b2b.php';          // B2B/Händler-Auth: Rolle, Preis-Gate, Magic-Link-Token
require_once M24_PLATTFORM_DIR . 'includes/class-m24-b2b-auth.php';     // B2B: Registrierung + Magic-Link-Login + Confirm
require_once M24_PLATTFORM_DIR . 'includes/class-m24-b2b-header-login.php'; // G2a: Header-Login-Button + Auth-Helper (Flag)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-updater.php';
require_once M24_PLATTFORM_DIR . 'includes/inquiry-mail-template.php'; // Anfrage-Mail „Variante A" (reines HTML-Rendering)
require_once M24_PLATTFORM_DIR . 'includes/image-optimization.php';    // WebP-Output + Qualität 90 + 4K-Schwelle (reine Filter)
require_once M24_PLATTFORM_DIR . 'admin/class-m24-settings.php';
require_once M24_PLATTFORM_DIR . 'admin/class-m24-mail-preview.php'; // Admin-Tool: Mail-/PDF-Vorschau + Test-Versand
require_once M24_PLATTFORM_DIR . 'includes/admin/class-m24-editor-notices.php'; // Fremd-Admin-Notices auf den M24-Editoren unterdrücken
require_once M24_PLATTFORM_DIR . 'modules/core/inquiries/inquiries-bootstrap.php';

// Katalog (CPT m24_teil + Taxonomie/Meta, Felder, Admin-Liste; Pricing = reiner Helfer).
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-cpt.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-pricing.php';   // reiner Helfer, kein init()
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-variant-price.php'; // Varianten-„ab"-Preis (reiner Anzeige-Leser)
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-related.php';   // „Weitere Teile"-Auswahl + Admin-REST
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-related-fields.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-fields.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-admin-list.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-artnr.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-gallery.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-template-detail.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-seed-terms.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-rewrites.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-assets.php';      // Zentrales CI-Stylesheet (Tokens + geteilte Karte)
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-template-archive.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-hub-cpt.php';     // Modell-Hubs: CPT m24_modellhub (backend-editierbare Quelle + Seeder)
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-hub.php';        // Modell-Hub-Landingpages (Routing/SEO; liest aus dem CPT)
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-hub-sitemap.php'; // Eigene XML-Sitemap für die Hubs (CPT ist public=false)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-robots.php';          // robots.txt aus dem Plugin (virtuelle robots.txt)
require_once M24_PLATTFORM_DIR . 'includes/admin/class-m24-adminbar.php';   // Admin-Bar aufräumen (Fremd-Ballast raus, M24-Sprungziele)
require_once M24_PLATTFORM_DIR . 'includes/class-m24-comments.php';         // Kommentare site-weit deaktivieren
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-seo.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-og.php';       // Open-Graph/Twitter (eine Quelle, ersetzt WPCode-Snippets)
require_once M24_PLATTFORM_DIR . 'inc/detail-original-badge.php';        // „Original BMW-Teil"-Badge (Markenrecht), reiner Helfer

// Gemeinsames Anfrage-Formular-Partial (eine Quelle für beide Modals — Teile + Fahrzeug).
require_once M24_PLATTFORM_DIR . 'includes/m24-inquiry-fields.php';      // reine Render-Helfer, kein init()

// Anfragen-Frontend (Modal + Merkzettel-Mail; nutzt bestehende Inquiry-Pipeline).
require_once M24_PLATTFORM_DIR . 'modules/anfragen/ppwr.php';            // reiner Helfer, kein init()
require_once M24_PLATTFORM_DIR . 'modules/anfragen/inquiry-submit.php';
require_once M24_PLATTFORM_DIR . 'modules/anfragen/inquiry-frontend.php';

// Fahrzeug-Modul (CPT m24_fahrzeug: Inserate Straßen-/Rennfahrzeuge, Detail-Template, SEO, Verwaltung).
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-cpt.php';
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-telemetry.php';   // reiner Helfer
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-meta.php';
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-meta-render.php';
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-tracking.php';
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-anfrage.php';     // „Jetzt anfragen" (REST + Modal)
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-alert-box.php';   // M24 Fahrzeug-Alert: Editor-Box + Versand (REST)
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-similar.php';     // reiner Helfer
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-template.php';
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-seo.php';
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-admin-list.php';
require_once M24_PLATTFORM_DIR . 'includes/fahrzeug/class-m24fz-editor-screen.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-admin-menu.php';            // §1 Menü-Dach „MOTORSPORT24"
require_once M24_PLATTFORM_DIR . 'includes/class-m24-oneclick-update.php';        // Ein-Klick-Update & Cache-Purge
require_once M24_PLATTFORM_DIR . 'includes/class-m24-fonts.php';                   // Externe Schrift-Requests (googleapis/gstatic) unterbinden

// Gruppierte Suche (REST-Endpoint + Dropdown + gefilterte Vollergebnis-Seite).
require_once M24_PLATTFORM_DIR . 'modules/search/search-query.php';     // reiner Helfer, kein init()
require_once M24_PLATTFORM_DIR . 'modules/search/search-rest.php';
require_once M24_PLATTFORM_DIR . 'modules/search/search-results.php';
require_once M24_PLATTFORM_DIR . 'modules/search/search-frontend.php';

// Verkauft-Ansicht (Alternativen-Block + Desktop-Lightbox auf verkauften Teilen).
require_once M24_PLATTFORM_DIR . 'modules/sold/sold-alternatives.php';  // reiner Helfer, kein init()
require_once M24_PLATTFORM_DIR . 'modules/sold/sold-lightbox.php';

// Bewertungs-Karte (Trust-Element auf Teile-Detailseiten; Anzeige, kein Schema).
require_once M24_PLATTFORM_DIR . 'modules/reviews/reviews-card.php';    // reiner Helfer, kein init()

// Importer (Paket D — Shopware-Gebrauchtteile). Helfer immer geladen; WP-CLI-Command nur unter CLI.
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-shopware-client.php';
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-bmw-models.php';
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-bmw-teilenummer-extractor.php';
// Kontextfreie Per-Produkt-Importlogik (Trait) + Hintergrund-Queue (AS-Action-Handler).
// Beides IMMER laden — der Worker laeuft im WP-Cron-Kontext, nicht nur unter WP-CLI.
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-shopware-import-core.php';
require_once M24_PLATTFORM_DIR . 'modules/importer/import-shopware-queue.php';
require_once M24_PLATTFORM_DIR . 'modules/importer/import-log.php';                 // Konsolenloses Import-Diagnose-Log (Timeout vs. OOM)
require_once M24_PLATTFORM_DIR . 'modules/importer/import-shopware-media.php';     // Bild-Entkopplung + Media-Repair (eine Verantwortung)
require_once M24_PLATTFORM_DIR . 'modules/importer/import-shopware-rennsport.php'; // Rennsport-Import (eigener AS-Hook)
require_once M24_PLATTFORM_DIR . 'modules/importer/import-shopware-gebraucht.php'; // Gebraucht-Import (robust, entkoppelt, hybrid Modell-Term)
require_once M24_PLATTFORM_DIR . 'modules/importer/import-shopware-variants.php'; // Varianten-Pre-Fill in _m24_preisoptionen (0.9.7-Save-Pfad gespiegelt)
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-dedup-report.php';   // Bild-Dubletten-Report (READ-ONLY, Dry-Run)
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-dedup-cleanup.php';  // Dubletten-Cleanup Phase 2 (Dry-Run default · Hard-Delete gated)
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-impact-report.php';  // Cleanup-Impact-Report (READ-ONLY · Backup↔Live-Diff)
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-attachment-restore.php'; // Attachment-Rückholung aus Backup-DB (ADD-ONLY)
require_once M24_PLATTFORM_DIR . 'modules/importer/class-m24-gallery-audit.php';   // Bilder-/Galerie-Audit (READ-ONLY · Admin-Seite + WP-CLI)
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once M24_PLATTFORM_DIR . 'modules/importer/import-shopware-cli.php';
    require_once M24_PLATTFORM_DIR . 'modules/importer/resync-media-cli.php';
}

if ( is_admin() ) {
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-log-viewer.php';
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-interessenten-page.php'; // Interessenten-Übersicht (Spiegel-Tabellen)
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-sitemap-page.php';       // Sitemap-Panel (Hub-Index-Allowlist + Status/Ping)
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-haendler-list.php';      // Händler-Freigabe-UI (Garage A3)
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-mock-log-viewer.php';
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-import-status.php';
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-reviews-settings.php';
    require_once M24_PLATTFORM_DIR . 'modules/importer/import-admin.php'; // Admin-Import-Steuerung (AJAX-Chunk-Loop)
}

register_activation_hook( __FILE__, [ 'M24_Database', 'activate' ] );
register_activation_hook( __FILE__, [ 'M24_Lang_Endpoint', 'activate' ] ); // /sprache/-Rewrite + Flush
register_deactivation_hook( __FILE__, function() {
    // Nichts loeschen - nur Cron/Action-Scheduler-Jobs deregistrieren
    wp_clear_scheduled_hook( 'm24_il_reminder_tick' ); // DOI-Erinnerungs-Cron
    wp_clear_scheduled_hook( 'm24_b2b_token_cleanup' ); // B2B-Magic-Token-Cleanup-Cron
} );

// Globale, einzige Formular-Stilquelle (CI-Felder für alle Modals/Formulare).
add_action( 'wp_enqueue_scripts', function () {
    $rel  = 'assets/css/m24-forms.css';
    $path = M24_PLATTFORM_DIR . $rel;
    wp_enqueue_style(
        'm24-forms',
        M24_PLATTFORM_URL . $rel,
        array(),
        file_exists( $path ) ? (string) filemtime( $path ) : M24_PLATTFORM_VERSION
    );
}, 5 );

add_action( 'plugins_loaded', function() {
    M24_Database::maybe_upgrade();
    M24_Inquiries::init();
    M24_Settings::init();
    M24_Mail_Preview::init();
    M24_Editor_Notices::init();
    M24_Updater::init();
    M24_Catalog_CPT::init();
    M24_Catalog_Related::init();
    M24_Catalog_Related_Fields::init();
    M24_Catalog_Fields::init();
    M24_Catalog_Admin_List::init();
    M24_Catalog_Artnr::init();
    M24_Catalog_Gallery::init();
    M24_Catalog_Template_Detail::init();
    M24_Catalog_Seed_Terms::init();
    M24_Catalog_Rewrites::init();
    M24_Catalog_Assets::init();
    M24_Catalog_Archive::init();
    M24_Catalog_Hub_CPT::init();
    M24_Catalog_Hub::init();
    M24_Catalog_Hub_Sitemap::init();
    M24_Robots::init();
    M24_Adminbar::init();
    M24_Comments::init();
    M24_Catalog_SEO::init();
    M24_Catalog_OG::init();
    M24_Inquiry_Submit::init();
    M24_Inquiry_Frontend::init();
    // Gruppierte Suche: REST-Route, Vollergebnis-Routing, Frontend-Assets.
    M24_Search_REST::init();
    M24_Search_Results::init();
    M24_Search_Frontend::init();
    // Verkauft-Ansicht: Assets (Inline-Block-CSS + Desktop-Lightbox) auf verkauften Teilen.
    M24_Sold_Lightbox::init();
    // Hintergrund-Import: AS-Action-Handler registrieren (Cron + Web-Kontext).
    M24_Shopware_Queue::init();
    M24_Shopware_Rennsport::init();
    // Fahrzeug-Modul.
    M24FZ_CPT::init();
    M24FZ_Meta::init();
    M24FZ_Template::init();
    M24FZ_SEO::init();
    M24FZ_Tracking::init();
    M24FZ_Anfrage::init();
    M24FZ_Alert_Box::init(); // M24 Fahrzeug-Alert: Editor-Box + Versand-REST
    M24_Brevo_IL::init();
    M24_Garage::init(); // Meine Garage G1
    M24_Garage_Cart::init(); // Meine Garage Etappe 1: kontogebundener Warenkorb
    M24_Garage_PDF::init();  // Meine Garage Etappe 3: PDF-Download (admin-post)
    M24_Garage_Alerts::init(); // Meine Garage Etappe 3: per-Fahrzeug-Änderungs-Alerts (Flag-gated)
    add_action( 'init', [ 'M24_I18n', 'init' ], 1 ); // Sprach-Cookie aus ?lang (früh, vor Ausgabe)
    M24_Admin_Bar::init(); // Admin-Bar: Direktlink zum korrekten M24-Editor je CPT
    M24_Login::init(); // Passwordless Magic-Link-Login „D" (flag-gated)
    M24_Account::init(); // Konto-/Einstellungsseite (Entwurf 1); Löschung/Export/Brevo-DOI via m24_account_danger_enabled
    M24_Lang_Endpoint::init(); // /sprache/?to=de|en
    add_action( 'init', [ 'M24_B2B', 'init' ] ); // B2B/Händler-Auth (Rolle, Token-Cron, Admin-Sperre)
    add_action( 'init', [ 'M24_B2B_Auth', 'init' ] ); // B2B: Registrierung/Login/Confirm (Shortcodes, admin-post, Magic-Link)
    M24_B2B_Header_Login::init(); // G2a: Header-Login-Button (Flag) + m24_is_b2b_authenticated() // IL-DOI-Pipeline (Submit→Pending→Mail→Confirm→Brevo Liste 3)
    M24_OneClick_Update::init(); // auch im Frontend (Admin-Bar-Node von jeder Seite; übrige Hooks self-gaten)
    M24_Fonts::init();           // Saira self-hosted; googleapis/gstatic-Links (inkl. Revslider Material Icons) kappen
    if ( is_admin() ) {
        M24_Log_Viewer::init();
        M24_Interessenten_Page::init();
        M24_Sitemap_Page::init();
        M24_Haendler_Page::init();
        M24_Mock_Log_Viewer::init();
        M24_Import_Status_Page::init();
        M24_Reviews_Settings::init();
        M24_Import_Admin::init();
        M24_Gallery_Audit::init();
        M24FZ_Meta_Render::init();
        M24FZ_Admin_List::init();
        M24FZ_Editor_Screen::init();
        M24_Admin_Menu::init();
    }
    m24_purge_cache_on_version_change();
}, 5 );

/**
 * WP-Rocket-Cache bei Plugin-Versionswechsel einmalig leeren.
 *
 * Hub-CSS liegt inline im Template (<style>), steckt also in der gecachten HTML-
 * Seite. Ohne Purge nach einem Deploy wird altes CSS weiter ausgeliefert (genau
 * das hat den 1116px-Fix maskiert). Greift bei jedem Versionssprung automatisch.
 * Kein Cloudflare im Stack — nur WP Rocket.
 */
function m24_purge_cache_on_version_change() {
    if ( get_option( 'm24_purged_version' ) === M24_PLATTFORM_VERSION ) {
        return;
    }
    update_option( 'm24_purged_version', M24_PLATTFORM_VERSION ); // zuerst sperren (kein Re-Entry)
    // OPcache mitnehmen (Belt): falls der Upgrade-Hook nicht lief, beim erkannten
    // Versionswechsel frischen Bytecode erzwingen. (Selbstheilung greift zusaetzlich admin-seitig.)
    if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); } // phpcs:ignore WordPress.PHP.NoSilencedErrors
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
    }
    if ( function_exists( 'rocket_clean_minify' ) ) {
        rocket_clean_minify(); // minifizierte/kombinierte CSS-Caches mit
    }
}
