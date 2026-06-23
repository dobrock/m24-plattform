<?php
/**
 * M24 Plattform — robots.txt aus dem Plugin.
 *
 * Hängt am Core-Filter `robots_txt`, der NUR für die virtuelle robots.txt greift —
 * existiert eine physische /robots.txt im Webroot, liefert der Server diese aus und
 * der Filter läuft nie. Domain wird absolut aus home_url() abgeleitet (nicht hardcoden).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Robots {

	public static function init() {
		add_filter( 'robots_txt', array( __CLASS__, 'output' ), 99, 2 );
	}

	/**
	 * @param string $output  Bisheriger robots.txt-Inhalt (Core/andere Plugins).
	 * @param bool   $public  Blog-Sichtbarkeit (Einstellungen → Lesen). false ⇒ unangetastet lassen.
	 */
	public static function output( $output, $public ) {
		// Bei „Suchmaschinen abhalten" Core-Verhalten (Disallow: /) NICHT überschreiben.
		if ( ! $public ) {
			return $output;
		}

		$sitemap_main = esc_url_raw( home_url( '/sitemap.xml' ) );
		$sitemap_hubs = esc_url_raw( home_url( '/sitemap-m24-hubs.xml' ) );

		$lines = array(
			'User-agent: *',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /haendler-konto/',
			'Disallow: /dealers/',
			'Disallow: /*?utm_',
			'Disallow: /*?karosserie=',
			'',
			'Sitemap: ' . $sitemap_main,
			'Sitemap: ' . $sitemap_hubs,
		);

		return implode( "\n", $lines ) . "\n";
	}
}
