<?php
/**
 * M24 Plattform — Fußbereich der Seite + mitlaufende Hauptnavigation (Daniel 18.11.2026)
 *
 * Ersetzt den bisherigen Theme-Fußbereich (wird ausgeblendet) durch den neuen: Logo „SINCE 2006“ und
 * Links in einer Kopfzeile, darunter drei Spalten (Text, Fahrzeuge + Versand, Kontakt). Die englische
 * Fassung übersetzt GTranslate wie den Rest der Seite.
 * Außerdem: Die Hauptnavigation bleibt am Desktop beim Scrollen oben stehen (die schmale Leiste
 * darüber scrollt weg). Mobil bleibt der Kopf unverändert.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Footer {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'stil' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'ausgabe' ), 5 );
		add_action( 'init', array( __CLASS__, 'cache_nach_update_leeren' ), 99 );
	}

	/**
	 * Nach einem Plugin-Update den Seiten-Cache (WP Rocket) einmal leeren – sonst sehen nicht angemeldete
	 * Besucher weiter die alten, zwischengespeicherten Seiten (alter Fußbereich, altes Logo).
	 */
	public static function cache_nach_update_leeren() {
		if ( get_option( 'm24_cache_version' ) === M24_PLATTFORM_VERSION ) { return; }
		update_option( 'm24_cache_version', M24_PLATTFORM_VERSION, false );
		if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
	}

	public static function stil() {
		if ( is_admin() ) { return; }
		echo '<style id="m24-footer">'
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
			. '@media(min-width:1019px){body,#td-outer-wrap{overflow-x:clip!important;overflow-y:visible!important}.td-header-template-wrap{position:sticky!important;top:-27px;z-index:9990!important}.admin-bar .td-header-template-wrap{top:5px}}'
			. '@media(min-width:1019px){.td-header-template-wrap{pointer-events:none}.td-header-template-wrap .tdc-row{pointer-events:auto}.td-header-desktop-wrap .m24-navrow [class*=rand_style],.td-header-desktop-wrap .m24-navrow .vc_column,.td-header-desktop-wrap .m24-navrow .tdb_header_logo,.td-header-desktop-wrap .m24-navrow .tdb-menu>li>a,.td-header-desktop-wrap .m24-navrow .tdb_header_search .tdb-block-inner>*{transition:transform .2s ease,height .2s ease}body.m24-stuck .td-header-desktop-wrap .m24-navrow [class*=rand_style]{height:76px!important;bottom:auto!important}body.m24-stuck .td-header-desktop-wrap .m24-navrow{height:76px}body.m24-stuck .td-header-desktop-wrap .m24-navrow .vc_column{transform:translateY(-2px)}body.m24-stuck .td-header-desktop-wrap .m24-navrow .tdb-menu>li>a{transform:scale(.95);transform-origin:center center}body.m24-stuck .td-header-desktop-wrap .m24-navrow .tdb_header_search .tdb-block-inner>*{transform:scale(.95);transform-origin:center center}body.m24-stuck .td-header-desktop-wrap .m24-navrow .tdb-menu>li>ul,body.m24-stuck .td-header-desktop-wrap .m24-navrow .tdb-menu>li>.sub-menu{margin-top:-2px}}@media(min-width:1019px){.home .tdc-content-wrap .tdc-zone:first-child .tdc-row>.vc_row>.td-element-style{left:50%!important;right:auto!important;width:100vw!important;max-width:none!important;transform:translateX(-50%)}}@media(min-width:1240px){.td-header-desktop-wrap .tdc-row{width:1132px!important;max-width:none!important}.home .tdc-content-wrap .tdc-zone:first-child>.tdc_zone>.tdc-row:not(.stretch_row){width:1132px!important;max-width:none!important;margin-left:16px!important;margin-right:16px!important}}</style>' . "\n";
	}

	public static function ausgabe() {
		if ( is_admin() ) { return; }
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
			. '<p>MOTORSPORT24 vertreibt seit 2006 hochwertige <b>Rennsport-Teile</b> von KK Automobile an anspruchsvolle Kunden weltweit.</p>'
			. '<p>Wir sind Ihr Ansprechpartner für den Bau von Clubsport- und Rennfahrzeugen, sowie im Bereich von Restauration und Neuaufbauten von historischen Serien- und Rennwagen nach Gruppe A, DTM, GTR oder STW Stand.</p>'
			. '<p>Die Internetpräsenz MOTORSPORT24.de steht in keinem Zusammenhang mit den auf dieser Webseite abgebildeten oder benannten Fahrzeugherstellern.</p>'
			. '<p>Bei uns finden Sie eine große Auswahl an <a href="' . $u( '/rennsport-teile/' ) . '">Rennsport-Teilen</a> und <a href="' . $u( '/gebrauchtteile/' ) . '">gebrauchten Original BMW Teilen</a>.</p>'
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
		// Mitlaufende Navigation: Höhe der schmalen Leiste messen, damit genau sie wegscrollt (Desktop).
		echo '<script>(function(){function m(){var w=document.querySelector(".td-header-template-wrap"),r=document.querySelector(".td-header-desktop-wrap .tdc-row");if(!w||!r||window.innerWidth<1019){if(w){w.style.top="";}return;}var h=r.getBoundingClientRect().height,a=document.getElementById("wpadminbar");if(h>0&&h<60){w.style.top=((a?a.offsetHeight:0)-Math.round(h))+"px";}}m();window.addEventListener("load",m);window.addEventListener("resize",m);setTimeout(m,1500);function t(){var rs=document.querySelectorAll(".td-header-desktop-wrap .tdc-row");if(rs.length>1&&!rs[1].classList.contains("m24-navrow")){rs[1].classList.add("m24-navrow");}var y=window.pageYOffset||0,b=document.body,on=b.classList.contains("m24-stuck");if(window.innerWidth<1019){b.classList.remove("m24-stuck");return;}if(!on&&y>140){b.classList.add("m24-stuck");}else if(on&&y<60){b.classList.remove("m24-stuck");}}t();window.addEventListener("scroll",t,{passive:true});window.addEventListener("resize",t);setTimeout(t,1500);})();</script>' . "\n";
	}
}
