<?php
/**
 * M24 — Sprachweiche: Besucher, deren Browser nicht auf Deutsch steht, landen auf der englischen Seite (/en/…)
 * (Daniel, 30.10.2026).
 *
 * Bewusst im Browser (kleines Inline-Script im <head>), nicht auf dem Server: WP Rocket liefert Seiten aus dem
 * Cache, eine PHP-Weiche würde dort nie laufen. Regeln (SEO-sicher):
 *  - nur einmal: Nach der ersten Weiterleitung bzw. sobald jemand von einer eigenen Seite kommt (z. B. per
 *    Sprachumschalter auf Deutsch zurück), wird Cookie m24_sprache gesetzt – danach nie wieder automatisch.
 *  - nie für Suchmaschinen/Bots (Googlebot, Bingbot, Vorschau-Robots …) → Google indexiert beide Fassungen normal.
 *  - nur auf deutschen Seiten (nicht /en/…), nicht im Backend, nicht für angemeldete Nutzer, nicht mit ?nolang.
 *  - Deutsch, Österreich, Schweiz (de, de-AT, de-CH …) bleiben auf Deutsch.
 * Abschaltbar über die Konstante M24_SPRACH_WEICHE=false.
 *
 * @package m24-plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_head', function () {
	if ( ( defined( 'M24_SPRACH_WEICHE' ) && ! M24_SPRACH_WEICHE ) || is_admin() || is_user_logged_in() ) { return; }
	?>
<script>(function(){try{
var d=document,l=location,p=l.pathname;if(!/(^|\.)motorsport24\.de$/.test(l.hostname))return;if(!/(^|\.)motorsport24\.de$/.test(l.hostname))return;
if(/^\/en(\/|$)/.test(p)||/^\/(wp-|feed|kontakt-formular)/.test(p)||/[?&]nolang/.test(l.search))return;
if(/(^|;\s*)m24_sprache=/.test(d.cookie))return;
var setz=function(v){d.cookie='m24_sprache='+v+';path=/;max-age=31536000;SameSite=Lax'+(l.protocol==='https:'?';Secure':'');};
if(d.referrer&&d.referrer.indexOf(l.host)>-1){setz('de');return;}
var ua=navigator.userAgent||'';if(/bot|crawl|spider|slurp|preview|facebookexternalhit|lighthouse|headless|google-|bing|yandex|baidu|duckduck|semrush|ahrefs/i.test(ua))return;
var langs=(navigator.languages&&navigator.languages.length?navigator.languages:[navigator.language||'']);
if(String(langs[0]||'').toLowerCase().indexOf('de')===0){setz('de');return;}
setz('en-auto');l.replace('/en'+p+l.search+l.hash);
}catch(e){}})();</script>
	<?php
}, 1 );
