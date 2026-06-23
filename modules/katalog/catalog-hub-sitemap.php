<?php
/**
 * M24 Katalog — XML-Sitemap für die Modell-Hubs.
 *
 * Der CPT m24_modellhub ist public=false → Jetzt­pack/Core-Sitemaps nehmen ihn nie auf.
 * Dieses Mini-Modul liefert eine eigene Sitemap unter /sitemap-m24-hubs.xml mit GENAU
 * den freigegebenen Hub-Slugs aus der Allowlist `m24_indexable_hub_slugs` — dieselbe
 * Quelle der Wahrheit wie M24_Catalog_Hub::seo_robots(). Keine Param-/Pagination-/Filter-URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Catalog_Hub_Sitemap {

	const QV           = 'm24_hub_sitemap';
	const PATH         = 'sitemap-m24-hubs.xml';
	const REWRITE_FLAG = 'm24_hub_sitemap_rewrite_v1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_flush' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	public static function add_rewrite() {
		add_rewrite_rule( '^' . self::PATH . '$', 'index.php?' . self::QV . '=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	/** Rewrite-Regel einmalig flushen (nach Deploy), ohne Activation-Hook. */
	public static function maybe_flush() {
		if ( get_option( self::REWRITE_FLAG ) === self::REWRITE_FLAG ) { return; }
		self::add_rewrite();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, self::REWRITE_FLAG );
	}

	/** Greift nur auf /sitemap-m24-hubs.xml — gibt das XML aus und beendet. */
	public static function maybe_render() {
		if ( ! get_query_var( self::QV ) ) { return; }

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex', true );
		}

		echo self::build(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — bereits escaped
		exit;
	}

	/** Valider <urlset> nach sitemaps.org; leere Allowlist ⇒ valide leere Sitemap. */
	public static function build() {
		$allow = (array) apply_filters( 'm24_indexable_hub_slugs', array( 'e36', 'z4-gt3' ) );
		$reg   = class_exists( 'M24_Catalog_Hub' ) ? (array) M24_Catalog_Hub::registry() : array();

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $allow as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug || ! class_exists( 'M24_Catalog_Hub' ) ) { continue; }

			$loc = M24_Catalog_Hub::url( $slug ); // saubere kanonische URL, kein Query
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $loc ) . "</loc>\n";

			if ( isset( $reg[ $slug ] ) ) {
				$lastmod = get_post_modified_time( 'c', true, (int) $reg[ $slug ] );
				if ( $lastmod ) {
					$xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
				}
			}

			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>' . "\n";
		return $xml;
	}
}
