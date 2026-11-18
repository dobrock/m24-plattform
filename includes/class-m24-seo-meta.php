<?php
/**
 * M24 Plattform — Titel/Beschreibung für Google auf drei Seiten + Standard-Vorschaubild fürs Teilen (Daniel 18.11.2026)
 *
 * 1) Startseite, Rennfahrzeuge (/racecars-for-sale/) und klassische Fahrzeuge (/classic-cars-for-sale/) bekommen
 *    feste Titel und Beschreibungen. Die klassischen Fahrzeuge trugen bisher Titel und Beschreibung der Rennfahrzeuge.
 * 2) Seiten ohne eigenes Vorschaubild (og:image) bekommen die Angaben fürs Teilen (WhatsApp, Facebook …) mit dem
 *    Standardbild (Halle + Logo). Seiten mit eigenem Bild – z. B. Fahrzeuginserate – bleiben unverändert.
 *
 * Umsetzung über den fertigen Seitenquelltext (Ausgabepuffer), damit es unabhängig vom SEO-Plugin greift.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Seo_Meta {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start' ), 1 );
	}

	/** Feste Texte je Seite: [Titel, Beschreibung]. */
	private static function texte() {
		if ( is_front_page() ) {
			return array(
				'MOTORSPORT24 – Rennwagen, Klassiker & Rennsport-Teile seit 2006',
				'Rennwagen und klassische Fahrzeuge, Rennsport-Teile und gebrauchte Originalteile passend für BMW M Modelle. Seit 2006, weltweiter Versand ab Berlin.',
			);
		}
		if ( is_page( 'racecars-for-sale' ) ) {
			return array(
				'Rennwagen & Sportwagen kaufen – MOTORSPORT24',
				'Sorgfältig ausgewählte Rennwagen und Sportwagen aus seriöser Quelle. Lieferung in ganz Europa und weltweit.',
			);
		}
		if ( is_page( 'classic-cars-for-sale' ) ) {
			return array(
				'Klassische Fahrzeuge kaufen – MOTORSPORT24',
				'Ausgewählte Klassiker und Youngtimer mit nachvollziehbarer Historie. Verkauf in ganz Europa und weltweit.',
			);
		}
		return null;
	}

	public static function start() {
		if ( is_admin() || is_feed() || is_robots() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		$texte = self::texte();
		$url   = self::aktuelle_url();
		ob_start( function ( $html ) use ( $texte, $url ) {
			return M24_Seo_Meta::anwenden( (string) $html, $texte, $url );
		} );
	}

	private static function aktuelle_url() {
		$pfad = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return home_url( $pfad ? $pfad : '/' );
	}

	public static function anwenden( $html, $texte, $url ) {
		if ( '' === $html || false === stripos( $html, '</head>' ) ) { return $html; }
		$kopf_ende = stripos( $html, '</head>' );
		$kopf      = substr( $html, 0, $kopf_ende );
		$rest      = substr( $html, $kopf_ende );

		if ( is_array( $texte ) ) {
			$t = esc_html( $texte[0] );
			$d = esc_attr( $texte[1] );
			if ( preg_match( '#<title[^>]*>.*?</title>#is', $kopf ) ) {
				$kopf = preg_replace( '#<title[^>]*>.*?</title>#is', '<title>' . $t . '</title>', $kopf, 1 );
			} else {
				$kopf .= '<title>' . $t . '</title>' . "\n";
			}
			$muster = '#<meta\s+[^>]*name=["\']description["\'][^>]*>#i';
			if ( preg_match( $muster, $kopf ) ) {
				$kopf = preg_replace( $muster, '<meta name="description" content="' . $d . '" />', $kopf, 1 );
			} else {
				$kopf .= '<meta name="description" content="' . $d . '" />' . "\n";
			}
		}

		// Angaben fürs Teilen nur ergänzen, wenn die Seite kein eigenes Vorschaubild mitbringt.
		if ( ! preg_match( '#<meta\s+[^>]*property=["\']og:image["\']#i', $kopf ) ) {
			$titel = '';
			$besch = '';
			if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $kopf, $m ) ) { $titel = trim( wp_strip_all_tags( $m[1] ) ); }
			if ( preg_match( '#<meta\s+[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\']#i', $kopf, $m ) ) { $besch = trim( $m[1] ); }
			$bild = M24_PLATTFORM_URL . 'assets/img/m24-og-standard.jpg';
			$og   = '';
			if ( ! preg_match( '#property=["\']og:title["\']#i', $kopf ) && '' !== $titel ) { $og .= '<meta property="og:title" content="' . esc_attr( html_entity_decode( $titel, ENT_QUOTES, 'UTF-8' ) ) . '" />' . "\n"; }
			if ( ! preg_match( '#property=["\']og:description["\']#i', $kopf ) && '' !== $besch ) { $og .= '<meta property="og:description" content="' . esc_attr( html_entity_decode( $besch, ENT_QUOTES, 'UTF-8' ) ) . '" />' . "\n"; }
			if ( ! preg_match( '#property=["\']og:url["\']#i', $kopf ) ) { $og .= '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n"; }
			if ( ! preg_match( '#property=["\']og:type["\']#i', $kopf ) ) { $og .= '<meta property="og:type" content="website" />' . "\n"; }
			if ( ! preg_match( '#property=["\']og:site_name["\']#i', $kopf ) ) { $og .= '<meta property="og:site_name" content="MOTORSPORT24" />' . "\n"; }
			$og .= '<meta property="og:image" content="' . esc_url( $bild ) . '" />' . "\n"
				. '<meta property="og:image:width" content="1200" />' . "\n"
				. '<meta property="og:image:height" content="630" />' . "\n"
				. '<meta name="twitter:card" content="summary_large_image" />' . "\n";
			$kopf .= $og;
		}
		return $kopf . $rest;
	}
}
