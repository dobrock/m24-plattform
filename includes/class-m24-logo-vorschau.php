<?php
/**
 * M24 Plattform — Logo „SINCE 2006“ im Kopf der Seite (Daniel 18.11.2026)
 *
 * Tauscht das Theme-Logo (Kopf, Desktop und mobil) gegen das überarbeitete weiße Logo mit „SINCE 2006“.
 * Klassenname historisch (war erst eine Vorschau mit ?logo=neu).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Logo_Vorschau {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'ausgabe' ), 99 );
	}

	public static function ausgabe() {
		if ( is_admin() ) { return; }
		$url = esc_url( M24_PLATTFORM_URL . 'assets/img/m24-logo-weiss-since2006.png?v=' . M24_PLATTFORM_VERSION );
		echo '<style id="m24-logo-vorschau">.tdb-logo-img-wrap{background-image:url("' . $url . '")!important}.td-header-wrap a.td-mobile-logo{background-image:url("' . $url . '")!important;transform:scale(1.2);transform-origin:center center}</style>' . "\n";
		echo '<script>document.addEventListener("DOMContentLoaded",function(){'
			. 'document.querySelectorAll("img.tdb-logo-img, img[src*=\"MOTORSPORT24-Logo_negativ\"], img[data-src*=\"MOTORSPORT24-Logo_negativ\"]").forEach(function(i){i.removeAttribute("srcset");i.removeAttribute("data-srcset");i.removeAttribute("data-src");i.removeAttribute("data-retina");i.src=location.origin+"/"+"' . esc_js( ltrim( (string) wp_parse_url( M24_PLATTFORM_URL, PHP_URL_PATH ), '/' ) ) . 'assets/img/m24-logo-weiss-since2006.png?v=' . esc_js( M24_PLATTFORM_VERSION ) . '";});'
			. '});</script>' . "\n";
	}
}
