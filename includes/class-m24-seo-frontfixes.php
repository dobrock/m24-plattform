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

		// (2b) <img …> ohne alt-Attribut → alt="" ergänzen (kein Bild bleibt ganz ohne alt; a11y-Baseline).
		$html = preg_replace_callback(
			'#<img\b([^>]*)>#i',
			static function ( $m ) {
				return preg_match( '/\balt\s*=/i', $m[1] ) ? $m[0] : '<img' . $m[1] . ' alt="">';
			},
			$html
		);

		return $html;
	}
}
