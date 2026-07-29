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
		// Spät (hohe Priorität) → M24 hängt als LETZTES an, damit keine fremde (z. B. Jetpack-)Sitemap-Zeile
		// danach doppelt angefügt wird.
		add_filter( 'robots_txt', array( __CLASS__, 'output' ), 99999, 2 );
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

		// Genau EINE M24-Sitemap-Zeile → der native M24-Sitemap-INDEX unter der garantiert M24-eigenen URL
		// /sitemap-m24-index.xml (kein Route-Konflikt mit Jetpacks /sitemap.xml). Enthält Hubs + alle Typen.
		// $output (Vorlauf anderer Plugins, z. B. Jetpacks Sitemap-Zeile) wird bewusst verworfen → keine Dubletten.
		// Jetpack /news-sitemap.xml (falls aktiv) bleibt optional als zweite Zeile erhalten.
		$lines = array(
			'User-agent: *',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /haendler-konto/',
			'Disallow: /dealers/',
			'Disallow: /*?utm_',
			'Disallow: /*?karosserie=',
			'',
			'Sitemap: ' . esc_url_raw( home_url( '/sitemap-m24-index.xml' ) ),
		);
		$news = apply_filters( 'm24_robots_news_sitemap', esc_url_raw( home_url( '/news-sitemap.xml' ) ) );
		if ( ! empty( $news ) ) { $lines[] = 'Sitemap: ' . $news; }

		return implode( "\n", $lines ) . "\n";
	}
}
