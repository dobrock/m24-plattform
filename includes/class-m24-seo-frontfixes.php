<?php
/**
 * M24 Plattform — SEO-/Barrierefreiheit-Frontfixes (rein über Hooks/Filter, kein Theme-Patch).
 *
 * Behebt Live-Audit-Befunde der Startseite (29.07.2026), ohne das Newspaper-Parent-Theme zu patchen:
 *
 *   1) H1-Dopplung: das Newspaper-Theme rendert Logo/Wrapper-<h1> ohne Textinhalt → mehrere H1 pro Seite.
 *      Ein gezielter, gekapselter Output-Filter demotet NUR textleere <h1> zu <div> (Klassen bleiben, CSS
 *      unberührt). Die echte, textführende H1 (Seitentitel) bleibt die einzige H1.
 *   2) Alt-Texte: (a) für über WP-Funktionen gerenderte Attachment-Bilder (Thumbnails/Featured) wird ein
 *      leeres alt aus dem Bild-/Beitragstitel gefüllt (wp_get_attachment_image_attributes). (b) Als Sicherheits-
 *      netz ergänzt der Output-Filter bei <img> OHNE alt-Attribut ein alt="" (kein <img> bleibt ganz ohne alt).
 *   3) hreflang: DE-Seite ↔ GTranslate-/en/-Seite gegenseitig verlinken + x-default (self-Canonical unberührt).
 *   7) WebSite + SearchAction (Sitelinks-Suchfeld) als JSON-LD auf der Startseite. wpSEO liefert keinen
 *      Schema-Graph → keine Dopplung. Organization/BreadcrumbList werden bewusst NICHT ergänzt (kein Dup).
 *
 * Kill-Switch für den Output-Filter (Punkte 1/2b): Konstante M24_SEO_OUTPUT_FIX = false ODER
 * Filter 'm24_seo_output_fix_enabled' → false. Die reinen wp_head-/attribute-Filter (2a/3/7) sind risikolos
 * additiv und laufen immer.
 *
 * @package m24-plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_SEO_Frontfixes {

	public static function init() {
		// 2a) Leeres alt bei WP-gerenderten Attachment-Bildern aus dem Titel füllen (server-seitig, sauber).
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'fill_alt' ), 20, 3 );

		// 3) hreflang-Paare (DE ↔ /en/ ↔ x-default).
		add_action( 'wp_head', array( __CLASS__, 'hreflang' ), 3 );

		// 7) WebSite + SearchAction nur auf der Startseite.
		add_action( 'wp_head', array( __CLASS__, 'website_schema' ), 20 );

		// B) Kategorie-Title-Suffix vereinheitlichen: /category/*-Archive nutzen den langen Marken-Suffix
		// (Titel 76–86 Zeichen) → auf den kurzen „| MOTORSPORT24 seit 2006" umstellen. Nur category-Archive;
		// Produkt-/Fahrzeug-/Teileseiten bleiben unberührt (bewusst lang: Teilenummern/Modellnamen).
		add_filter( 'wpseo_set_title', array( __CLASS__, 'category_title_suffix' ), 99 );

		// 1 + 2b) Gekapselter Output-Filter (nur Frontend-HTML), abschaltbar.
		if ( self::output_fix_enabled() ) {
			add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
		}
	}

	/* ── 2a) Alt aus Titel ────────────────────────────────────────────────── */

	public static function fill_alt( $attr, $attachment, $size = '' ) {
		if ( ! is_array( $attr ) ) { return $attr; }
		if ( isset( $attr['alt'] ) && '' !== trim( (string) $attr['alt'] ) ) { return $attr; } // gesetztes alt (auch bewusst) respektieren
		$title = '';
		if ( is_object( $attachment ) && isset( $attachment->ID ) ) {
			$title = trim( wp_strip_all_tags( (string) get_the_title( (int) $attachment->ID ) ) );
			if ( '' === $title ) {
				$parent = (int) wp_get_post_parent_id( (int) $attachment->ID );
				if ( $parent > 0 ) { $title = trim( wp_strip_all_tags( (string) get_the_title( $parent ) ) ); }
			}
		}
		// Nur echten, beschreibenden Titel setzen (kein „Attachment-…"/Dateiname-Stuffing).
		if ( '' !== $title && false === stripos( $title, 'attachment' ) ) {
			$attr['alt'] = mb_substr( $title, 0, 125 );
		}
		return $attr;
	}

	/* ── B) Kategorie-Title-Suffix ────────────────────────────────────────── */

	/**
	 * Auf /category/*-Archiven den langen Marken-Suffix („… | MOTORSPORT24 - Hochwertige Rennsport Teile
	 * seit 2006") durch den kurzen („… | MOTORSPORT24 seit 2006") ersetzen. Keyword/Rest bleibt unverändert.
	 * Robust gegen Varianten des langen Suffixes: ab „MOTORSPORT24" abschneiden und den kurzen Suffix anhängen.
	 * Greift NUR für category-Archive; alle anderen Seitentypen unberührt.
	 */
	public static function category_title_suffix( $title ) {
		if ( ! is_category() ) { return $title; }
		$title  = (string) $title;
		$anchor = 'MOTORSPORT24';
		$pos    = strpos( $title, $anchor );
		if ( false === $pos ) { return $title; } // kein Marken-Suffix → nichts tun
		return substr( $title, 0, $pos + strlen( $anchor ) ) . ' seit 2006';
	}

	/* ── 3) hreflang ──────────────────────────────────────────────────────── */

	public static function hreflang() {
		if ( is_admin() || is_feed() || is_404() || is_search() ) { return; }
		// Aktuellen Pfad (ohne Query/Fragment) bestimmen.
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/', PHP_URL_PATH ); // phpcs:ignore
		if ( '' === $path ) { $path = '/'; }
		// /en-Präfix erkennen → DE-Pfad ist der ohne Präfix.
		$de_path = preg_replace( '#^/en(?=/|$)#', '', $path );
		if ( '' === $de_path ) { $de_path = '/'; }
		$de_path = user_trailingslashit( $de_path );
		$de_url  = home_url( $de_path );
		$en_url  = home_url( user_trailingslashit( '/en' . ( '/' === $de_path ? '/' : $de_path ) ) );

		echo "\n" . '<link rel="alternate" hreflang="de-de" href="' . esc_url( $de_url ) . '" />' . "\n";
		echo '<link rel="alternate" hreflang="en" href="' . esc_url( $en_url ) . '" />' . "\n";
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $de_url ) . '" />' . "\n";
	}

	/* ── 7) WebSite + SearchAction ────────────────────────────────────────── */

	public static function website_schema() {
		if ( ! is_front_page() ) { return; }
		$home = home_url( '/' );
		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'WebSite',
			'name'            => get_bloginfo( 'name' ),
			'url'             => $home,
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $home . '?s={search_term_string}',
				),
				'query-input' => 'required name=search_term_string',
			),
		);
		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/* ── 1 + 2b) Output-Filter (nur textleere H1 demoten + alt-los <img> absichern) ── */

	private static function output_fix_enabled(): bool {
		$on = ! ( defined( 'M24_SEO_OUTPUT_FIX' ) && ! M24_SEO_OUTPUT_FIX );
		return (bool) apply_filters( 'm24_seo_output_fix_enabled', $on );
	}

	public static function start_buffer() {
		if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) { return; } // phpcs:ignore
		ob_start( array( __CLASS__, 'process_html' ) );
	}

	/**
	 * Transformiert NUR: (1) textleere <h1>…</h1> → <div> (Attribute bleiben), (2b) <img> ohne alt → alt="".
	 * Defensiv: kein vollständiges HTML-Dokument → unverändert zurück (nie fremde/JSON-/AJAX-Ausgabe anfassen).
	 */
	public static function process_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) { return $html; }
		if ( false === stripos( $html, '</html>' ) || false === stripos( $html, '<body' ) ) { return $html; }

		// (1) Genau EINE H1 pro Seite: die erste textführende (semantische) H1 bleibt; JEDE weitere H1
		// (leer ODER gefüllt — z. B. Logo-/Wrapper-H1 oder ein doppelt gerenderter Titel) wird zu <div>
		// demotet. Die Attribute (class/style) werden 1:1 aufs <div> übernommen → Optik unverändert.
		$kept = false;
		$html = preg_replace_callback(
			'#<h1(\b[^>]*)>(.*?)</h1>#is',
			static function ( $m ) use ( &$kept ) {
				$is_empty = ( '' === trim( wp_strip_all_tags( (string) $m[2] ) ) );
				if ( ! $is_empty && ! $kept ) { $kept = true; return $m[0]; } // erste echte H1 behalten
				return '<div' . $m[1] . '>' . $m[2] . '</div>';                // alle weiteren + alle leeren → <div>
			},
			$html
		);

		// (2b) Alt-Texte nachziehen und Bilder unterhalb des Seitenkopfs verzögert laden (Daniel, 22.11.2026:
		// „überall Alt-Texte nachziehen – wichtig"). Stichprobe davor: S63-Motor 43 von 51 Bildern ohne
		// Beschreibung, Rennwagen-Übersicht 42 von 55, dort kein einziges Bild mit loading="lazy".
		//  - Fehlendes ODER leeres alt wird gefüllt: erst aus dem title-Attribut des Bildes, dann aus dem
		//    Dateinamen („BMW-M3-E30-Track-Tool-12.jpg" → „BMW M3 E30 Track Tool"), auf Einzelseiten zuletzt
		//    aus dem Seitentitel mit laufender Nummer. Ein gesetztes alt bleibt immer, wie es ist.
		//  - Schmuckbilder (Logo, Icon, Flagge, Zählpixel, role=presentation, aria-hidden, sehr klein) behalten
		//    alt="" — ein erfundener Text wäre dort für Vorleseprogramme nur Lärm.
		//  - loading="lazy" + decoding="async" für alles ab dem fünften Bild, das noch kein loading trägt; die
		//    ersten vier (Logo, Kopf, Aufmacher) laden sofort, damit das größte sichtbare Bild nicht wartet.
		$seitentitel = is_singular() ? trim( wp_strip_all_tags( (string) get_the_title( (int) get_queried_object_id() ) ) ) : '';
		$nr          = 0;
		$bildnr      = 0;
		// Skriptblöcke bleiben unangetastet: steht dort ein <img …> in einer Zeichenkette, würde ein
		// eingefügtes Attribut mit Anführungszeichen das Skript zerbrechen.
		$teile = preg_split( '#(<script\b.*?</script>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $teile ) ) { return $html; }
		foreach ( $teile as $i => $teil ) {
			if ( $i % 2 ) { continue; } // ungerade = <script>…</script>
			$teile[ $i ] = preg_replace_callback(
			'#<img\b([^>]*?)\s*/?>#i',
			static function ( $m ) use ( $seitentitel, &$nr, &$bildnr ) {
				$a = $m[1];
				$nr++;
				$hat_alt = preg_match( '/\balt\s*=\s*(["\'])(.*?)\1/is', $a, $am );
				if ( ! $hat_alt || '' === trim( (string) $am[2] ) ) {
					$alt = self::alt_vorschlag( $a, $seitentitel, $bildnr );
					$neu = ' alt="' . esc_attr( $alt ) . '"';
					$a   = $hat_alt ? preg_replace( '/\s*\balt\s*=\s*(["\']).*?\1/is', $neu, $a, 1 ) : $a . $neu;
				}
				if ( $nr > 4 && ! preg_match( '/\bloading\s*=/i', $a ) ) { $a .= ' loading="lazy"'; }
				if ( $nr > 4 && ! preg_match( '/\bdecoding\s*=/i', $a ) ) { $a .= ' decoding="async"'; }
				return '<img' . $a . '>';
			},
			(string) $teil
			);
		}

		return implode( '', $teile );
	}

	/** Schmuckbild? Dann bleibt alt leer. */
	private static function ist_schmuck( string $attribute ): bool {
		if ( preg_match( '/\brole\s*=\s*["\']presentation|\baria-hidden\s*=\s*["\']true/i', $attribute ) ) { return true; }
		if ( preg_match( '/\b(?:src|class)\s*=\s*["\'][^"\']*(?:logo|icon|flag|flagge|emoji|avatar|spinner|pixel|blank\.)/i', $attribute ) ) { return true; }
		if ( preg_match( '/\bwidth\s*=\s*["\']?(\d+)/i', $attribute, $w ) && (int) $w[1] > 0 && (int) $w[1] <= 48 ) { return true; }
		return false;
	}

	/** Alt-Vorschlag für ein Bild ohne Beschreibung; '' für Schmuckbilder und wenn nichts Brauchbares da ist. */
	private static function alt_vorschlag( string $attribute, string $seitentitel, int &$bildnr ): string {
		if ( self::ist_schmuck( $attribute ) ) { return ''; }
		if ( preg_match( '/\btitle\s*=\s*(["\'])(.*?)\1/is', $attribute, $t ) && '' !== trim( $t[2] ) ) {
			return mb_substr( trim( wp_strip_all_tags( html_entity_decode( $t[2], ENT_QUOTES, 'UTF-8' ) ) ), 0, 125 );
		}
		$name = '';
		// Echte Quelle zuerst: Nachlade-Skripte legen sie in data-src ab, in src steht dann nur ein Platzhalter.
		if ( preg_match( '/\b(?:data-src|data-lazy-src)\s*=\s*(["\'])(.*?)\1/is', $attribute, $s ) || preg_match( '/\bsrc\s*=\s*(["\'])(?!data:)(.*?)\1/is', $attribute, $s ) ) {
			$name = (string) pathinfo( (string) wp_parse_url( html_entity_decode( $s[2] ), PHP_URL_PATH ), PATHINFO_FILENAME );
			$name = rawurldecode( $name );
			$name = preg_replace( '/-\d+x\d+$|-scaled$|-e\d{6,}$/i', '', $name );          // WP-Größen, „-scaled", Bearbeitungsstempel
			$name = trim( preg_replace( '/[\s_\-.]+/', ' ', $name ) );
			$name = trim( preg_replace( '/\s+\d{1,4}$/', '', $name ) );                     // laufende Nummer am Ende
		}
		// Kamera-/Zufallsnamen sind keine Beschreibung: IMG 1234, DSC0042, reine Ziffern, Hash-Namen.
		$brauchbar = '' !== $name
			&& ! preg_match( '/^(?:img|dsc|dscn|dscf|pxl|photo|bild|image|foto|screenshot|whatsapp|unnamed)(?![a-zäöü])/iu', $name )
			&& ! preg_match( '/^[0-9a-f\s]{12,}$/i', $name )
			&& preg_match( '/\p{L}{3,}/u', $name );
		if ( $brauchbar ) { return mb_substr( $name, 0, 125 ); }
		if ( '' !== $seitentitel ) { $bildnr++; return mb_substr( $seitentitel, 0, 110 ) . ' – Bild ' . $bildnr; }
		return '';
	}
}
