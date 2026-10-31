<?php
/**
 * M24 Katalog — Verpackungsregister-Nummer (LUCID, VerpackG) der MOTORSPORT24 GmbH (0.11.567).
 *
 *  - Option `m24_lucid_nr` (Default DE4212735792932), editierbar unter MOTORSPORT24 → Einstellungen.
 *  - Teile-Detailseite: Zeile „Verpackungsregister (LUCID): …" im Block TEILE-DATEN.
 *  - Shortcode [m24_lucid] → nur die Nummer (z. B. fürs Impressum);
 *    [m24_lucid label="1"] → „Verpackungsregister (LUCID): DE…".
 *
 * Die Nummer läuft immer notranslate (GTranslate soll sie nicht anfassen). Das Label ist auf
 * /en/-URLs fest englisch (notranslate), sonst deutsch (GTranslate-übersetzbar). Bewusst nur
 * pfadbasiert — Cookie/Accept-Language würden gecachte DE-Seiten verfälschen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Catalog_Lucid {

	const OPTION  = 'm24_lucid_nr';
	const DEFAULT_NR = 'DE4212735792932';

	public static function init() {
		add_shortcode( 'm24_lucid', array( __CLASS__, 'shortcode' ) );
	}

	/** Bereinigte LUCID-Nummer ('' = ausgeblendet, wenn im Backend bewusst geleert). */
	public static function nummer(): string {
		$v = get_option( self::OPTION, self::DEFAULT_NR );
		return self::sanitize( $v );
	}

	/** Sanitize: Großbuchstaben/Ziffern, max. 32 Zeichen. */
	public static function sanitize( $v ): string {
		$v = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $v ) );
		return substr( $v, 0, 32 );
	}

	/** Aktuelle Seite ist eine /en/-URL (GTranslate-Pfadmodus)? */
	private static function is_en_path(): bool {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return (bool) preg_match( '#^/en(/|$)#', $path );
	}

	/** Label-HTML (DE übersetzbar, EN fest). */
	public static function label_html(): string {
		if ( self::is_en_path() ) {
			return '<span class="notranslate" translate="no">Packaging register (LUCID)</span>';
		}
		return 'Verpackungsregister (LUCID)';
	}

	/** Nummer-HTML (immer notranslate). */
	public static function nummer_html(): string {
		$nr = self::nummer();
		if ( '' === $nr ) {
			return '';
		}
		return '<span class="m24-lucid-nr notranslate" translate="no">' . esc_html( $nr ) . '</span>';
	}

	/** Komplette Zeile „Label: Nummer" ('' wenn keine Nummer). */
	public static function zeile_html(): string {
		$nr = self::nummer_html();
		return '' === $nr ? '' : self::label_html() . ': ' . $nr;
	}

	/** [m24_lucid] / [m24_lucid label="1"] */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'label' => '0' ), (array) $atts, 'm24_lucid' );
		$mit  = in_array( strtolower( (string) $atts['label'] ), array( '1', 'true', 'yes', 'ja' ), true );
		return $mit ? self::zeile_html() : self::nummer_html();
	}
}
