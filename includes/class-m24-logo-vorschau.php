<?php
/**
 * M24 Plattform — Logo-Vorschau „SINCE 2006“ (Daniel 18.11.2026)
 *
 * Nur zum Ansehen: mit ?logo=neu in der Adresse zeigt der Kopf der Seite das überarbeitete Logo
 * (weißes Logo mit „SINCE 2006“). Ohne den Parameter bleibt alles unverändert. Interne Links
 * behalten den Parameter, damit man sich durch die Seite klicken kann.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Logo_Vorschau {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'ausgabe' ), 99 );
	}

	public static function aktiv() {
		return isset( $_GET['logo'] ) && 'neu' === sanitize_key( wp_unslash( $_GET['logo'] ) );
	}

	public static function ausgabe() {
		if ( is_admin() || ! self::aktiv() ) { return; }
		$url = esc_url( M24_PLATTFORM_URL . 'assets/img/m24-logo-weiss-since2006.png?v=' . M24_PLATTFORM_VERSION );
		echo '<style id="m24-logo-vorschau">.tdb-logo-img-wrap{background-image:url("' . $url . '")!important}</style>' . "\n";
		echo '<script>document.addEventListener("DOMContentLoaded",function(){'
			. 'document.querySelectorAll("img.tdb-logo-img").forEach(function(i){i.removeAttribute("srcset");i.removeAttribute("data-retina");i.src="' . $url . '";});'
			. 'document.querySelectorAll("a[href]").forEach(function(a){try{var u=new URL(a.href);if(u.host===location.host&&!u.searchParams.has("logo")&&!/wp-admin|wp-login/.test(u.pathname)){u.searchParams.set("logo","neu");a.href=u.toString();}}catch(e){}});'
			. '});</script>' . "\n";
	}
}
