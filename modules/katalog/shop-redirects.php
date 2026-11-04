<?php
/**
 * M24 Plattform — Katalog: 301-Weiche motorsport24.shop → motorsport24.de
 * Modul: shop-redirects.php (04.11.2026)
 *
 * Der Shopware-Shop (motorsport24.shop) wird abgeschaltet. Die Domain zeigt danach
 * per DNS auf diesen WordPress-Server (Domain-Alias im Hosting). Jede Anfrage mit
 * Host motorsport24.shop / www.motorsport24.shop wird hier – noch vor WordPress'
 * eigener Canonical-Weiche – per 301 auf die passende Adresse unter
 * https://www.motorsport24.de umgeleitet:
 *
 *   1. Exakter Treffer in data/shop-redirects.json (Shop-Pfad → .de-Pfad):
 *      890 Produkt-URLs (inkl. /detail/{uuid}-Varianten) + 57 Kategorien.
 *      Ziel ist das jeweilige Teil (/rennsport-teile/… bzw. /gebrauchtteile/…),
 *      sonst der Modell-Hub (/teile/e46/, /bmw-m2-f87/ …) oder das Archiv.
 *   2. Kein Treffer: Baureihe aus dem Pfadtext raten → Modell-Hub;
 *      Startseite/Konto/Warenkorb/Suche → Startseite; Rest → /gebrauchtteile/.
 *
 * Läuft auf plugins_loaded (vor WP-Query und redirect_canonical). WP Rocket cached
 * je Host, 301-Antworten werden nicht gecacht → kein Cache-Konflikt mit .de.
 * Die Map ist statisch; neue Teile brauchen keinen Eintrag (alte Shop-URLs ändern
 * sich nicht mehr). Abschaltbar über die Konstante M24_SHOP_REDIRECTS=false.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Shop_Redirects {

	const TARGET_HOST = 'https://www.motorsport24.de';
	const MAP_FILE    = 'data/shop-redirects.json';

	public static function init() {
		if ( defined( 'M24_SHOP_REDIRECTS' ) && ! M24_SHOP_REDIRECTS ) { return; }
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/** Host gehört zur alten Shop-Domain? */
	public static function is_shop_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = preg_replace( '/:\d+$/', '', $host );
		return ( 'motorsport24.shop' === $host || 'www.motorsport24.shop' === $host );
	}

	public static function maybe_redirect() {
		if ( empty( $_SERVER['HTTP_HOST'] ) || ! self::is_shop_host( $_SERVER['HTTP_HOST'] ) ) { return; }
		if ( php_sapi_name() === 'cli' ) { return; }
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$target = self::target_for( $uri );
		if ( ! headers_sent() ) {
			header( 'X-M24-Shop-Redirect: 1' );
			header( 'Cache-Control: max-age=86400' );
		}
		wp_redirect( $target, 301, 'M24 Shop-Umzug' );
		exit;
	}

	/** Shop-Request-URI → vollständige .de-URL. Reine Funktion (testbar). */
	public static function target_for( $uri ) {
		$path = parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? rawurldecode( $path ) : '/';
		$key  = self::normalize( $path );

		// Startseite, Konto, Warenkorb, Suche, Bilder etc. → Startseite.
		if ( '' === $key || preg_match( '#^/(account|checkout|search|widgets|navigation|media|thumbnail|bundles|cart|newsletter|register|wishlist)(/|$)#', $key ) ) {
			return self::TARGET_HOST . '/';
		}

		$map = self::map();
		if ( isset( $map[ $key ] ) ) {
			return self::TARGET_HOST . $map[ $key ];
		}
		// Sprachpräfix / Shopware-Suffixe abstreifen und erneut versuchen.
		$alt = preg_replace( '#^/(de|en)(/|$)#', '/', $key );
		$alt = preg_replace( '#\.html?$#', '', $alt );
		$alt = self::normalize( $alt );
		if ( '' !== $alt && isset( $map[ $alt ] ) ) {
			return self::TARGET_HOST . $map[ $alt ];
		}

		return self::TARGET_HOST . self::guess_hub( $key );
	}

	/** Kleinschreibung, ohne Query, ohne Slash am Ende. */
	public static function normalize( $path ) {
		$p = strtolower( (string) $path );
		$p = preg_replace( '#/+#', '/', $p );
		$p = rtrim( $p, '/' );
		if ( '' !== $p && '/' !== $p[0] ) { $p = '/' . $p; }
		return $p;
	}

	/** Baureihe aus dem Pfadtext raten → Modell-Hub (alle Ziele existieren auf .de). */
	public static function guess_hub( $key ) {
		$rules = array(
			'#e30#'                         => '/teile/e30/',
			'#e36#'                         => '/teile/e36/',
			'#e46#'                         => '/teile/e46/',
			'#e9[02x]#'                     => '/teile/e9x/',
			'#z4-?gt3#'                     => '/teile/z4-gt3/',
			'#f87|m2-|m235|m240#'           => '/bmw-m2-f87/',
			'#f82|m4-|g82#'                 => '/gebrauchtteile/?m24_modell=m4-f82',
			'#1er|e8[1278]|f2[01]|135i#'    => '/bmw-1er/',
			'#z4|e89|e85|e86#'              => '/bmw-z4/',
			'#rennsport|carbon|gtr|dtm|gt4#' => '/rennsport-teile/',
		);
		foreach ( $rules as $rx => $t ) {
			if ( preg_match( $rx, $key ) ) { return $t; }
		}
		return '/gebrauchtteile/';
	}

	/** Map aus data/shop-redirects.json (einmal pro Request gelesen). */
	private static function map() {
		static $map = null;
		if ( null !== $map ) { return $map; }
		$map  = array();
		$file = M24_PLATTFORM_DIR . self::MAP_FILE;
		if ( is_readable( $file ) ) {
			$json = json_decode( (string) file_get_contents( $file ), true );
			if ( is_array( $json ) ) { $map = $json; }
		}
		return $map;
	}
}
M24_Shop_Redirects::init();
