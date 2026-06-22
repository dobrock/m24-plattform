<?php
/**
 * M24 Fahrzeug — SEO: JSON-LD (Vehicle/Offer/Breadcrumb), Robots, Title/Meta, 404 für deaktiviert
 * Modul: includes/fahrzeug/class-m24fz-seo.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_SEO {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'gate_disabled' ), 1 );
		add_filter( 'wpseo_set_robots', array( __CLASS__, 'robots' ), 99 );
		add_filter( 'wpseo_set_title', array( __CLASS__, 'title' ), 99 );
		add_filter( 'wpseo_set_desc', array( __CLASS__, 'desc' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'json_ld' ), 20 );
		// Open Graph / Twitter: Head puffern (vor Yoast prio 1 starten), nur ergänzen, wenn kein og:image da ist.
		add_action( 'wp_head', array( __CLASS__, 'og_buffer_start' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'og_buffer_end' ), 9 );
		// WhatsApp-taugliche Share-Bildgröße (1200px breit, proportional, kein Crop).
		add_action( 'after_setup_theme', array( __CLASS__, 'register_sizes' ) );
	}

	/** Eigene OG-/Share-Bildgröße — 1200px breit, < 300 KB, ohne das Hero-Bild zu verändern. */
	public static function register_sizes() {
		add_image_size( 'm24_og', 1200, 0, false );
	}

	/* ── Open Graph / Twitter Cards (Beitragsbild statt Favicon beim Teilen) ───────
	 * HINWEIS: Fallback, weil Yoast für den CPT m24_fahrzeug kein OG erzeugt. Sobald Yoast
	 * OG für diesen CPT liefert (og:image im Head), unterdrückt der Dedup-Schutz unseren Block.
	 * Bei dauerhafter Yoast-CPT-OG-Aktivierung diesen Block deaktivieren bzw. auf die
	 * wpseo_opengraph_*-Filter umstellen. */

	private static $og_buf = false;

	/** Head ab Priorität 0 puffern (vor Yoast prio 1), damit wir ein bereits gesetztes og:image sehen. */
	public static function og_buffer_start() {
		if ( is_singular( M24FZ_CPT::PT ) && ! M24FZ_CPT::is_disabled( get_queried_object_id() ) ) {
			self::$og_buf = true;
			ob_start();
		}
	}

	/** Puffer wieder ausgeben; nur wenn KEIN og:image im Head steht, unseren OG-/Twitter-Satz anhängen. */
	public static function og_buffer_end() {
		if ( ! self::$og_buf ) { return; }
		self::$og_buf = false;
		$head = ob_get_clean();
		if ( false === stripos( (string) $head, 'og:image' ) ) {
			$head .= self::og_tags( get_queried_object_id() );
		}
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput -- Head-Pass-through; eigene Tags sind escaped.
	}

	/** Vollständiger OG-/Twitter-Tag-Satz für ein Fahrzeug. */
	private static function og_tags( $id ) {
		$title = get_the_title( $id );
		$url   = get_permalink( $id );
		$desc  = self::og_desc( $id );
		$img   = self::og_image( $id );

		$out = "\n<!-- M24 Fahrzeug Open Graph (Fallback; Yoast liefert für diesen CPT kein OG) -->\n";
		$meta = function ( $attr, $key, $val ) use ( &$out ) {
			if ( '' === (string) $val ) { return; }
			$out .= '<meta ' . $attr . '="' . esc_attr( $key ) . '" content="' . esc_attr( $val ) . '">' . "\n";
		};
		$meta( 'property', 'og:type', 'article' );
		$meta( 'property', 'og:site_name', 'MOTORSPORT24' );
		$meta( 'property', 'og:title', $title );
		$meta( 'property', 'og:description', $desc );
		$meta( 'property', 'og:url', $url );
		if ( $img ) {
			$meta( 'property', 'og:image', $img['url'] );
			if ( 0 === stripos( $img['url'], 'https://' ) ) { $meta( 'property', 'og:image:secure_url', $img['url'] ); }
			if ( $img['w'] > 0 ) { $meta( 'property', 'og:image:width', (string) $img['w'] ); }
			if ( $img['h'] > 0 ) { $meta( 'property', 'og:image:height', (string) $img['h'] ); }
			$meta( 'property', 'og:image:alt', $img['alt'] );
		}
		$meta( 'name', 'twitter:card', 'summary_large_image' );
		$meta( 'name', 'twitter:title', $title );
		$meta( 'name', 'twitter:description', $desc );
		if ( $img ) { $meta( 'name', 'twitter:image', $img['url'] ); }
		return $out;
	}

	/** Bild-Quelle: optionales Vorschaubild → Beitragsbild → erstes Hero-/Galeriebild → Logo/Site-Icon. */
	private static function og_image( $id ) {
		$att = (int) get_post_meta( $id, '_m24fz_og_image', true ); // optionales Social-/WhatsApp-Bild
		if ( ! $att ) { $att = (int) get_post_thumbnail_id( $id ); }
		if ( ! $att && class_exists( 'M24FZ_Template' ) ) {
			$hero = (array) M24FZ_Template::hero_images( $id );
			if ( ! empty( $hero ) ) { $att = (int) $hero[0]; }
		}
		if ( $att ) {
			// Photon (i0.wp.com) für die finale Meta-URL umgehen → FB/WhatsApp holt direkt von der Domain.
			add_filter( 'jetpack_photon_skip_for_url', '__return_true', 99 );
			$src = self::share_image( $att ); // [ url, w, h ] — URL und Maße beschreiben IMMER dieselbe Datei
			remove_filter( 'jetpack_photon_skip_for_url', '__return_true', 99 );
			if ( $src ) {
				$alt = trim( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) );
				if ( '' === $alt ) { $alt = get_the_title( $id ); }
				return array( 'url' => $src[0], 'w' => (int) $src[1], 'h' => (int) $src[2], 'alt' => $alt );
			}
		}
		$logo = '';
		$cl   = (int) get_theme_mod( 'custom_logo' );
		if ( $cl ) { $s = wp_get_attachment_image_src( $cl, 'full' ); if ( $s ) { $logo = $s[0]; } }
		if ( '' === $logo ) { $logo = (string) get_site_icon_url( 512 ); }
		$logo = (string) apply_filters( 'm24fz_og_fallback_image', $logo, $id );
		return ( '' !== $logo ) ? array( 'url' => $logo, 'w' => 0, 'h' => 0, 'alt' => get_bloginfo( 'name' ) ) : null;
	}

	/**
	 * Liefert [ url, width, height ] einer REALEN Datei — URL und Maße beschreiben immer dieselbe Datei.
	 * Reihenfolge: m24_og (bei Bedarf on-the-fly erzeugt) → large (echte Datei) → full (Original mit echten Maßen).
	 */
	private static function share_image( $att ) {
		$meta = wp_get_attachment_metadata( $att );

		// 1) m24_og bereits vorhanden?
		if ( is_array( $meta ) && ! empty( $meta['sizes']['m24_og']['file'] ) ) {
			$url = self::size_url( $att, $meta['sizes']['m24_og']['file'] );
			if ( $url ) { return array( $url, (int) $meta['sizes']['m24_og']['width'], (int) $meta['sizes']['m24_og']['height'] ); }
		}

		// 2) m24_og on-the-fly erzeugen (nur wenn Original ≥ 1200, sonst kein Upscale → Fallback).
		$gen = self::generate_og_size( $att );
		if ( $gen ) { return $gen; }

		// 3) „large" als echte Datei.
		if ( is_array( $meta ) && ! empty( $meta['sizes']['large']['file'] ) ) {
			$url = self::size_url( $att, $meta['sizes']['large']['file'] );
			if ( $url ) { return array( $url, (int) $meta['sizes']['large']['width'], (int) $meta['sizes']['large']['height'] ); }
		}

		// 4) Original (Full) mit den ECHTEN Originalmaßen.
		if ( is_array( $meta ) && ! empty( $meta['width'] ) ) {
			$url = wp_get_attachment_url( $att );
			if ( $url ) { return array( $url, (int) $meta['width'], (int) $meta['height'] ); }
		}
		return null;
	}

	/** m24_og-Zwischengröße (1200px breit) erzeugen, in den Attachment-Metadaten registrieren, [url,w,h] zurück. */
	private static function generate_og_size( $att ) {
		$path = get_attached_file( $att );
		if ( ! $path || ! file_exists( $path ) ) { return null; }
		if ( ! function_exists( 'image_make_intermediate_size' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
		// Qualität NUR für die m24_og-Größe definieren (WebP q72 → ~130 KB); Originale + andere Größen unberührt.
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'og_quality' ), 99, 2 );
		$gen = image_make_intermediate_size( $path, 1200, 0, false ); // false = kein Crop; 0 Höhe = proportional
		remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'og_quality' ), 99 );
		if ( ! is_array( $gen ) || empty( $gen['file'] ) ) { return null; } // u. a. wenn Original < 1200 (kein Upscale)

		$meta = wp_get_attachment_metadata( $att );
		if ( ! is_array( $meta ) ) { $meta = array(); }
		if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) { $meta['sizes'] = array(); }
		$meta['sizes']['m24_og'] = $gen;
		wp_update_attachment_metadata( $att, $meta );

		$url = self::size_url( $att, $gen['file'] );
		return $url ? array( $url, (int) $gen['width'], (int) $gen['height'] ) : null;
	}

	/** Kompressionsqualität NUR für die m24_og-Größe (filterbar, Default 72) — kleine Share-Datei. */
	public static function og_quality( $quality, $mime = '' ) {
		return (int) apply_filters( 'm24fz_og_quality', 72, $mime );
	}

	/** URL einer Zwischengröße (gleicher Ordner wie das Original; Photon ist im Aufrufer deaktiviert). */
	private static function size_url( $att, $file ) {
		$full = wp_get_attachment_url( $att );
		if ( ! $full ) { return ''; }
		$pos = strrpos( $full, '/' );
		return ( false === $pos ) ? '' : substr( $full, 0, $pos + 1 ) . $file;
	}

	/** Kurzbeschreibung (~160 Zeichen) aus Zusammenfassung → Beschreibung → Excerpt. */
	private static function og_desc( $id ) {
		$d = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_m24fz_zusammenfassung', true ) ) );
		if ( '' === $d ) { $d = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_m24fz_beschreibung', true ) ) ); }
		if ( '' === $d ) { $d = trim( wp_strip_all_tags( get_the_excerpt( $id ) ) ); }
		$d = preg_replace( '/\s+/', ' ', $d );
		return ( mb_strlen( $d ) > 160 ) ? rtrim( mb_substr( $d, 0, 159 ) ) . '…' : $d;
	}

	/** „deaktiviert" → Frontend weg: 404 + noindex. */
	public static function gate_disabled() {
		if ( ! is_singular( M24FZ_CPT::PT ) ) { return; }
		$id = get_queried_object_id();
		if ( M24FZ_CPT::is_disabled( $id ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public static function robots( $robots ) {
		if ( ! is_singular( M24FZ_CPT::PT ) ) { return $robots; }
		return M24FZ_CPT::is_disabled( get_queried_object_id() ) ? 'noindex, follow' : 'index, follow';
	}

	/** Title/Desc über die bestehende M24-Pipeline (post_title, 75-Limit, Cascade). */
	public static function title( $title ) {
		if ( ! is_singular( M24FZ_CPT::PT ) || ! class_exists( 'M24_Catalog_SEO' ) ) { return $title; }
		return M24_Catalog_SEO::build_title( get_the_title( get_queried_object_id() ), 'neu' );
	}
	public static function desc( $desc ) {
		if ( ! is_singular( M24FZ_CPT::PT ) || ! class_exists( 'M24_Catalog_SEO' ) ) { return $desc; }
		$id  = get_queried_object_id();
		$sum = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_m24fz_zusammenfassung', true ) ) );
		if ( '' !== $sum ) { $sum = preg_replace( '/\s+/', ' ', $sum ); return mb_strlen( $sum ) > 155 ? rtrim( mb_substr( $sum, 0, 154 ) ) . '…' : $sum; }
		return M24_Catalog_SEO::build_desc( get_the_title( $id ), 'neu' );
	}

	/** JSON-LD Vehicle/Car + Offer + BreadcrumbList. */
	public static function json_ld() {
		if ( ! is_singular( M24FZ_CPT::PT ) ) { return; }
		$id = get_queried_object_id();
		if ( M24FZ_CPT::is_disabled( $id ) ) { return; }
		$g  = function ( $k ) use ( $id ) { return (string) get_post_meta( $id, $k, true ); };

		$car = array(
			'@context' => 'https://schema.org', '@type' => 'Car',
			'name'     => get_the_title( $id ),
			'url'      => get_permalink( $id ),
		);
		// brand IMMER ausgeben (FIX 3): Marke-Meta, sonst aus dem Titel ableiten.
		$marke = trim( (string) $g( '_m24fz_marke' ) );
		if ( '' === $marke ) { $marke = M24FZ_Telemetry::guess_brand( get_the_title( $id ) ); }
		if ( '' !== $marke )             { $car['brand'] = array( '@type' => 'Brand', 'name' => $marke ); }
		if ( $g( '_m24fz_modell' ) )     { $car['model'] = $g( '_m24fz_modell' ); }
		if ( $g( '_m24fz_baujahr' ) )    { $car['productionDate'] = $g( '_m24fz_baujahr' ); $car['vehicleModelDate'] = $g( '_m24fz_baujahr' ); }
		if ( $g( '_m24fz_karosserie' ) ) { $car['bodyType'] = $g( '_m24fz_karosserie' ); }
		if ( $g( '_m24fz_aussenfarbe' ) ){ $car['color'] = $g( '_m24fz_aussenfarbe' ); }
		if ( $g( '_m24fz_getriebe' ) )   { $car['vehicleTransmission'] = $g( '_m24fz_getriebe' ); }
		if ( $g( '_m24fz_neu_gebraucht' ) ) { $car['itemCondition'] = ( false !== stripos( $g( '_m24fz_neu_gebraucht' ), 'neu' ) ) ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition'; }
		// Neue Enums → schema.org (F).
		// Rennwagen: straßenspezifische Felder (Kraftstoff/Lenkung) nicht emittieren.
		$is_renn = ( 'renn' === $g( '_m24fz_template_typ' ) );
		if ( ! $is_renn && $g( '_m24fz_kraftstoff' ) ) { $car['fuelType'] = $g( '_m24fz_kraftstoff' ); }
		// Antrieb case-insensitiv + Alias → schema.org (greift auch bei Altwerten wie „heck").
		$antrieb = M24FZ_Telemetry::match_enum( $g( '_m24fz_antrieb' ), M24FZ_Telemetry::antrieb_options(), M24FZ_Telemetry::enum_aliases( '_m24fz_antrieb' ) );
		$drive   = array( 'Heck' => 'RearWheelDriveConfiguration', 'Front' => 'FrontWheelDriveConfiguration', 'Allrad' => 'AllWheelDriveConfiguration' );
		if ( isset( $drive[ $antrieb ] ) ) { $car['driveWheelConfiguration'] = 'https://schema.org/' . $drive[ $antrieb ]; }
		if ( ! $is_renn ) {
			$lenkung = M24FZ_Telemetry::match_enum( $g( '_m24fz_lenkung' ), M24FZ_Telemetry::lenkung_options(), M24FZ_Telemetry::enum_aliases( '_m24fz_lenkung' ) );
			$steer   = array( 'Links' => 'LeftHandDriving', 'Rechts' => 'RightHandDriving' );
			if ( isset( $steer[ $lenkung ] ) ) { $car['steeringPosition'] = 'https://schema.org/' . $steer[ $lenkung ]; }
		}
		if ( $g( '_m24fz_innenmaterial' ) ) { $car['vehicleInteriorType'] = $g( '_m24fz_innenmaterial' ); }
		if ( $g( '_m24fz_innenfarbe' ) )    { $car['vehicleInteriorColor'] = $g( '_m24fz_innenfarbe' ); }
		$lauf = (int) preg_replace( '/\D/', '', $g( '_m24fz_laufleistung' ) );
		if ( $lauf > 0 ) {
			$munit = ( 'mi' === strtolower( $g( '_m24fz_laufleistung_einheit' ) ) ) ? 'SMI' : 'KMT';
			$car['mileageFromOdometer'] = array( '@type' => 'QuantitativeValue', 'value' => $lauf, 'unitCode' => $munit );
		}
		$ps = (int) $g( '_m24fz_leistung_ps' );
		if ( $ps > 0 ) { $car['vehicleEngine'] = array( '@type' => 'EngineSpecification', 'enginePower' => array(
			array( '@type' => 'QuantitativeValue', 'value' => round( $ps * M24FZ_Telemetry::PS_TO_KW, 2 ), 'unitCode' => 'KWT' ),
			array( '@type' => 'QuantitativeValue', 'value' => $ps, 'unitCode' => 'BHP' ),
		) ); }
		if ( has_post_thumbnail( $id ) ) { $car['image'] = get_the_post_thumbnail_url( $id, 'large' ); }

		// description: Fahrzeugbeschreibung (Plaintext, gekürzt) → sonst Zusammenfassung → Excerpt.
		$desc = trim( wp_strip_all_tags( (string) $g( '_m24fz_beschreibung' ) ) );
		if ( '' === $desc ) { $desc = trim( wp_strip_all_tags( (string) $g( '_m24fz_zusammenfassung' ) ) ); }
		if ( '' === $desc ) { $desc = trim( wp_strip_all_tags( get_the_excerpt( $id ) ) ); }
		$desc = preg_replace( '/\s+/', ' ', $desc );
		if ( '' !== $desc ) { $car['description'] = ( mb_strlen( $desc ) > 320 ) ? rtrim( mb_substr( $desc, 0, 319 ) ) . '…' : $desc; }

		// Offer.
		$paf   = (int) $g( '_m24fz_preis_auf_anfrage' );
		$preis = (int) $g( '_m24fz_preis' );
		$red   = (int) $g( '_m24fz_preis_reduziert' );
		$eff   = ( $red > 0 && $red < $preis ) ? $red : $preis;
		$cur   = ( 'CHF' === strtoupper( $g( '_m24fz_waehrung' ) ) ) ? 'CHF' : 'EUR';
		$avail = M24FZ_CPT::is_sold( $id ) ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock';
		$offer = array( '@type' => 'Offer', 'priceCurrency' => $cur, 'availability' => $avail, 'url' => get_permalink( $id ),
			'seller' => array( '@type' => 'Organization', 'name' => 'MOTORSPORT24 GmbH' ) );
		// Eindeutiger Identifier des Einzelstücks: FIN, sonst interne Fahrzeug-ID.
		$fin = trim( (string) $g( '_m24fz_fin' ) );
		$offer['sku'] = ( '' !== $fin ) ? $fin : ( 'M24-' . $id );
		if ( ! $paf && $eff > 0 ) {
			$offer['price'] = $eff;
			// Rollend gültig: heute + 12 Monate (ISO-8601).
			$offer['priceValidUntil'] = gmdate( 'Y-m-d', strtotime( '+12 months' ) );
			// JSON-LD-Steuersignal (Übergabe v29, von Daniel freigegeben): in BEIDEN Modi true —
			// der angezeigte Preis ist der All-in-Endpreis (auch bei §25a ist die Margensteuer
			// eingepreist). Rein maschinenlesbar; Frontend-Hinweis „§25a" bleibt davon unberührt.
			$offer['priceSpecification'] = array(
				'@type' => 'PriceSpecification', 'price' => $eff, 'priceCurrency' => $cur,
				'valueAddedTaxIncluded' => true,
			);
		}
		$car['offers'] = $offer;

		// BreadcrumbList kommt von wpSEO (Yoast/Newspaper) — KEIN zweites BreadcrumbList hier
		// ausgeben (Duplicate Structured Data vermeiden). Nur das Car-Schema ist M24-eigen.
		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $car ) . '</script>' . "\n";
	}
}
