<?php
/**
 * M24 Plattform — Footer-Vorschau (Daniel 18.11.2026)
 *
 * Nur zum Ansehen: mit ?footer=neu in der Adresse wird der bisherige Fußbereich ausgeblendet und der
 * neue gezeigt (Logo „SINCE 2006“ und Links in einer Kopfzeile, darunter drei Spalten: Text,
 * Fahrzeuge + Versand, Kontakt). Ohne den Parameter bleibt alles unverändert. Interne Links behalten
 * den Parameter, damit man sich durch die Seite klicken kann.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Footer_Vorschau {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'stil' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'ausgabe' ), 5 );
	}

	public static function aktiv() {
		return isset( $_GET['footer'] ) && 'neu' === sanitize_key( wp_unslash( $_GET['footer'] ) );
	}

	public static function stil() {
		if ( is_admin() || ! self::aktiv() ) { return; }
		echo '<style id="m24-footer-vorschau">'
			. '.tdc-footer-wrap{display:none!important}'
			. '.m24ft{background:#4f4f4f;color:#eaeaea;font-family:Saira,-apple-system,"Segoe UI",sans-serif;font-size:15px;line-height:1.6;clear:both}'
			. '.m24ft *{box-sizing:border-box}'
			. '.m24ft-in{max-width:1180px;margin:0 auto;padding:44px 24px 26px}'
			. '.m24ft-kopf{display:flex;justify-content:space-between;align-items:center;gap:20px;padding-bottom:18px;margin-bottom:30px;border-bottom:1px solid #6a6a6a}'
			. '.m24ft-logo img{width:230px;max-width:100%;height:auto;display:block}'
			. '.m24ft-links{display:flex;flex-wrap:wrap;gap:8px 24px}'
			. '.m24ft a{color:#fff!important}'
			. '.m24ft-links a{font-weight:500;text-decoration:none}'
			. '.m24ft-links a:hover{text-decoration:underline}'
			. '.m24ft-spalten{display:grid;grid-template-columns:1.25fr 1.1fr .75fr;gap:52px}'
			. '.m24ft p{margin:0 0 16px;color:#eaeaea;font-size:15px;line-height:1.6}'
			. '.m24ft b{font-weight:700;color:#fff}'
			. '.m24ft p a{font-weight:700;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px}'
			. '.m24ft h3{font-family:inherit;font-weight:700;font-size:24px;line-height:1.25;margin:0 0 12px;color:#fff}'
			. '.m24ft h3.m24ft-h2{margin-top:24px}'
			. '.m24ft-adr{line-height:1.55;white-space:nowrap;margin-bottom:14px}'
			. '.m24ft-adr strong{display:block;font-weight:700;color:#fff}'
			. '.m24ft-cp{background:#0d0d0d;color:#cfcfd2;font-size:14px;text-align:center;padding:14px 24px}'
			. '@media(max-width:900px){'
			. '.m24ft-in{padding:32px 20px 18px}'
			. '.m24ft-kopf{flex-direction:column;align-items:flex-start;margin-bottom:24px}'
			. '.m24ft-spalten{grid-template-columns:1fr;gap:8px}'
			. '.m24ft h3{font-size:22px;margin-top:12px}'
			. '}'
			. '</style>' . "\n";
	}

	public static function ausgabe() {
		if ( is_admin() || ! self::aktiv() ) { return; }
		$logo  = esc_url( M24_PLATTFORM_URL . 'assets/img/m24-logo-weiss-since2006.png?v=' . M24_PLATTFORM_VERSION );
		$u     = function ( $pfad ) { return esc_url( home_url( $pfad ) ); };
		$links = array(
			'Kontakt'     => '/kontakt/',
			'AGB'         => '/agb/',
			'Widerruf'    => '/widerruf/',
			'Datenschutz' => '/datenschutz/',
			'Impressum'   => '/impressum/',
		);
		echo '<footer class="m24ft"><div class="m24ft-in">';
		echo '<div class="m24ft-kopf"><a class="m24ft-logo" href="' . $u( '/' ) . '"><img src="' . $logo . '" width="230" height="77" alt="MOTORSPORT24.de – since 2006"></a><nav class="m24ft-links">';
		foreach ( $links as $name => $pfad ) {
			echo '<a href="' . $u( $pfad ) . '">' . esc_html( $name ) . '</a>';
		}
		echo '</nav></div>';
		echo '<div class="m24ft-spalten">';
		echo '<div>'
			. '<p>Seit 2006 vertreibt MOTORSPORT24 hochwertige <b>Rennsport Teile</b> von KK Automobile an anspruchsvolle Kunden weltweit.</p>'
			. '<p>Wir sind Ihr Ansprechpartner für den Bau von Clubsport- und Rennfahrzeugen, sowie im Bereich von Restauration und Neuaufbauten von historischen Serien- und Rennwagen nach Gruppe A, DTM, GTR oder STW Stand.</p>'
			. '<p>Die Internetpräsenz MOTORSPORT24.de steht in keinem Zusammenhang mit den auf dieser Webseite abgebildeten oder benannten Fahrzeugherstellern.</p>'
			. '<p>Bei uns finden Sie eine große Auswahl an <a href="' . $u( '/rennsport-teile/' ) . '">Rennsport Teilen</a> und <a href="' . $u( '/gebrauchtteile/' ) . '">gebrauchten Original BMW Teilen</a>.</p>'
			. '</div>';
		echo '<div>'
			. '<h3>Fahrzeuge</h3>'
			. '<p>Wir verkaufen <a href="' . $u( '/racecars-for-sale/' ) . '">Rennfahrzeuge</a> und <a href="' . $u( '/classic-cars-for-sale/' ) . '">klassische Fahrzeuge</a> in ganz Europa und weltweit.</p>'
			. '<h3 class="m24ft-h2">Weltweiter Versand</h3>'
			. '<p>Wir bieten weltweite Versandlösungen für unsere Fahrzeugteile in allen Sendungsdimensionen. Fahrzeuge liefern wir innerhalb der EU wahlweise im geschlossenen Trailer oder per Container nach Übersee (z.B. USA oder Asien).</p>'
			. '</div>';
		echo '<div>'
			. '<h3>Kontakt</h3>'
			. '<div class="m24ft-adr"><strong>Erreichbar</strong>Montag – Freitag<br>10:00 – 16:00 Uhr</div>'
			. '<div class="m24ft-adr"><strong>Vertriebsbüro</strong>Scharfe Lanke 109-131<br>D-13595 Berlin</div>'
			. '<div class="m24ft-adr"><strong>Werkstatt / Versand</strong>Obere Str. 17a<br>D-37589 Kalefeld</div>'
			. '</div>';
		echo '</div></div>';
		echo '<div class="m24ft-cp">© MOTORSPORT24 2006-' . esc_html( gmdate( 'Y' ) ) . '</div></footer>' . "\n";
		echo '<script>document.addEventListener("DOMContentLoaded",function(){'
			. 'document.querySelectorAll("a[href]").forEach(function(a){try{var u=new URL(a.href);if(u.host===location.host&&!u.searchParams.has("footer")&&!/wp-admin|wp-login/.test(u.pathname)){u.searchParams.set("footer","neu");a.href=u.toString();}}catch(e){}});'
			. '});</script>' . "\n";
	}
}
