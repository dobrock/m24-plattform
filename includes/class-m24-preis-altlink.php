<?php
/**
 * M24 [preis]-Altlink-Filter: entfernt tote „Online bestellen"-Buttons auf Alt-Shops (bmwm3shop.de,
 * e30shop.de) aus dem gerenderten Inhalt.
 *
 * Befund (live via REST verifiziert): Der Link steckt in der [preis]-Ausgabe — im Preis-Kasten
 * `.boxed_container` → `.boxed_left`: „… | <b><a href="https?://www.bmwm3shop.de/…" target="_blank">Online
 * bestellen</a></b>". Da die Quelle (Legacy-[preis]-Shortcode) NICHT in diesem Plugin liegt, greifen wir
 * quell-agnostisch am `the_content` NACH der Shortcode-Expansion (Prio 12; do_shortcode läuft auf Prio 11)
 * und strippen nur die Alt-Domain-Anker. „Senden Sie uns eine Nachricht" bleibt erhalten.
 *
 * Flag m24_preis_altlink_filter (Default AN). Domains erweiterbar via Filter m24_preis_altlink_domains.
 * motorsport24.shop bewusst NICHT enthalten (Status als aktueller Shop unklar).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Preis_Altlink {

	const FLAG = 'm24_preis_altlink_filter';

	public static function enabled(): bool {
		return (bool) (int) get_option( self::FLAG, 1 ); // Default AN
	}

	public static function init() {
		if ( ! self::enabled() ) { return; }
		add_filter( 'the_content', array( __CLASS__, 'strip' ), 12 ); // nach do_shortcode (Prio 11)
	}

	private static function domains(): array {
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'm24_preis_altlink_domains', array( 'bmwm3shop.de', 'e30shop.de' ) ) ) ) );
	}

	public static function strip( $content ) {
		if ( ! is_string( $content ) || '' === $content ) { return $content; }
		$domains = self::domains();
		if ( empty( $domains ) ) { return $content; }

		// Schneller Guard: nur weiterarbeiten, wenn eine Alt-Domain überhaupt vorkommt.
		$hit = false;
		foreach ( $domains as $d ) { if ( false !== stripos( $content, $d ) ) { $hit = true; break; } }
		if ( ! $hit ) { return $content; }

		$dre = implode( '|', array_map( static function ( $d ) { return preg_quote( $d, '#' ); }, $domains ) );

		// 1) „ | <b><a href=ALT …>Online bestellen</a></b>" komplett entfernen (inkl. Trenner + Bold).
		$content = (string) preg_replace(
			'#\s*\|\s*<b>\s*<a\b[^>]*href=(["\'])https?://(?:www\.)?(?:' . $dre . ')[^"\']*\1[^>]*>\s*Online bestellen\s*</a>\s*</b>#i',
			'',
			$content
		);
		// 2) Fallback: nackter Alt-Domain-Anker mit „Online bestellen" (falls ohne Trenner/Bold).
		$content = (string) preg_replace(
			'#<a\b[^>]*href=(["\'])https?://(?:www\.)?(?:' . $dre . ')[^"\']*\1[^>]*>\s*Online bestellen\s*</a>#i',
			'',
			$content
		);
		return $content;
	}
}
