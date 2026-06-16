<?php
/**
 * Plugin Name:       M24 Plattform
 * Plugin URI:        https://www.motorsport24.de
 * Description:       B2B-Sammelanfragen, Händler-Auth, Bestand, Katalog. Pusht Anfragen an M24 Desk.
 * Version:           0.7.38
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

define( 'M24_PLATTFORM_VERSION',     '0.7.38' );
define( 'M24_PLATTFORM_FILE',        __FILE__ );
define( 'M24_PLATTFORM_DIR',         plugin_dir_path( __FILE__ ) );
define( 'M24_PLATTFORM_URL',         plugin_dir_url( __FILE__ ) );
define( 'M24_PLATTFORM_DB_VERSION',  '004' );

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

require_once M24_PLATTFORM_DIR . 'includes/class-m24-database.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-logger.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-cache.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-rest-client.php';
require_once M24_PLATTFORM_DIR . 'includes/class-m24-updater.php';
require_once M24_PLATTFORM_DIR . 'includes/inquiry-mail-template.php'; // Anfrage-Mail „Variante A" (reines HTML-Rendering)
require_once M24_PLATTFORM_DIR . 'includes/image-optimization.php';    // WebP-Output + Qualität 90 + 4K-Schwelle (reine Filter)
require_once M24_PLATTFORM_DIR . 'admin/class-m24-settings.php';
require_once M24_PLATTFORM_DIR . 'modules/core/inquiries/inquiries-bootstrap.php';

// Katalog (CPT m24_teil + Taxonomie/Meta, Felder, Admin-Liste; Pricing = reiner Helfer).
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-cpt.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-pricing.php';   // reiner Helfer, kein init()
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
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-seo.php';
require_once M24_PLATTFORM_DIR . 'modules/katalog/catalog-og.php';       // Open-Graph/Twitter (eine Quelle, ersetzt WPCode-Snippets)
require_once M24_PLATTFORM_DIR . 'inc/detail-original-badge.php';        // „Original BMW-Teil"-Badge (Markenrecht), reiner Helfer

// Anfragen-Frontend (Modal + Merkzettel-Mail; nutzt bestehende Inquiry-Pipeline).
require_once M24_PLATTFORM_DIR . 'modules/anfragen/ppwr.php';            // reiner Helfer, kein init()
require_once M24_PLATTFORM_DIR . 'modules/anfragen/inquiry-submit.php';
require_once M24_PLATTFORM_DIR . 'modules/anfragen/inquiry-frontend.php';

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
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once M24_PLATTFORM_DIR . 'modules/importer/import-shopware-cli.php';
    require_once M24_PLATTFORM_DIR . 'modules/importer/resync-media-cli.php';
}

if ( is_admin() ) {
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-log-viewer.php';
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-mock-log-viewer.php';
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-import-status.php';
    require_once M24_PLATTFORM_DIR . 'admin/class-m24-reviews-settings.php';
}

register_activation_hook( __FILE__, [ 'M24_Database', 'activate' ] );
register_deactivation_hook( __FILE__, function() {
    // Nichts loeschen - nur Cron/Action-Scheduler-Jobs deregistrieren
} );

add_action( 'plugins_loaded', function() {
    M24_Database::maybe_upgrade();
    M24_Inquiries::init();
    M24_Settings::init();
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
    if ( is_admin() ) {
        M24_Log_Viewer::init();
        M24_Mock_Log_Viewer::init();
        M24_Import_Status_Page::init();
        M24_Reviews_Settings::init();
    }
}, 5 );
