<?php
/**
 * M24 Plattform — Katalog: Open-Graph / Twitter-Cards (eine Quelle = das Plugin)
 * Modul: modules/katalog/catalog-og.php
 *
 * Gibt auf Teile-Detailseiten GENAU EINE OG-/Twitter-Garnitur aus und entfernt
 * fremde og:/twitter:/fb:app_id-Metas (wpSEO-Eigen-OG, Theme, Alt-Snippets) —
 * ersetzt die drei WPCode-Snippets (#23984 Ausgabe, #23986 wpSEO-OG aus,
 * #23987 Doubletten-Entfernung). Technik: wp_head puffern, fremde OG strippen,
 * eigene Tags anhaengen.
 *
 * Werte (Hand-Werte gewinnen, via M24_Catalog_SEO-Helfer):
 *  - og:title       = Titel-Kaskade (force_detail_title)
 *  - og:description = echter Beschreibungstext → Checkmark-Fallback (filter_og_desc)
 *  - og:image       = Featured Image (absolut, full) → Default-Social-Bild
 *  - og:url/type/site_name + twitter:card/title/description/image
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_OG {

	const PT = 'm24_teil';

	/** @var bool Nur unsere eigene Pufferung wieder schliessen. */
	private static $buffering = false;

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'buffer_start' ), -1000 );
		add_action( 'wp_head', array( __CLASS__, 'buffer_end' ), 1000 );
	}

	private static function active() {
		return is_singular( self::PT );
	}

	public static function buffer_start() {
		if ( ! self::active() || self::$buffering ) { return; }
		self::$buffering = true;
		ob_start();
	}

	public static function buffer_end() {
		if ( ! self::$buffering ) { return; }
		self::$buffering = false;
		$head = (string) ob_get_clean();
		// Fremde OG/Twitter/fb-Metas entfernen → keine Doubletten, eine Quelle (dieses Modul).
		$head = preg_replace(
			'#[ \t]*<meta\b[^>]*\b(?:property|name)=["\'](?:og:[^"\']*|twitter:[^"\']*|fb:app_id)["\'][^>]*>\s*#i',
			"\n",
			$head
		);
		echo $head; // unveraenderter Rest des Heads (vertrauenswuerdig, von WP/Plugins erzeugt)
		echo self::render_tags(); // phpcs:ignore WordPress.Security.EscapeOutput — intern via tag() escaped
	}

	/** Ein Meta-Tag (leerer Content → ''). */
	private static function tag( $key, $content, $attr = 'property' ) {
		$content = trim( (string) $content );
		if ( '' === $content ) { return ''; }
		return '<meta ' . $attr . '="' . esc_attr( $key ) . '" content="' . esc_attr( $content ) . '">' . "\n";
	}

	private static function render_tags() {
		$id = get_queried_object_id();
		if ( ! $id ) { return ''; }

		if ( class_exists( 'M24_Catalog_SEO' ) ) {
			$title = (string) M24_Catalog_SEO::force_detail_title( '' ); // Kaskade bzw. Hand-Titel
			$desc  = (string) M24_Catalog_SEO::filter_og_desc( '' );     // echter Text → Checkmark-Fallback
		} else {
			$title = get_the_title( $id );
			$desc  = '';
		}
		$img = self::og_image( $id );

		$out  = "<!-- M24 Open Graph -->\n";
		$out .= self::tag( 'og:type', 'article' );
		$out .= self::tag( 'og:site_name', get_bloginfo( 'name' ) );
		$out .= self::tag( 'og:title', $title );
		$out .= self::tag( 'og:description', $desc );
		$out .= self::tag( 'og:url', get_permalink( $id ) );
		if ( '' !== $img['url'] ) {
			$out .= self::tag( 'og:image', $img['url'] );
			$out .= self::tag( 'og:image:secure_url', $img['url'] );
			if ( $img['w'] > 0 ) { $out .= self::tag( 'og:image:width', (string) $img['w'] ); }
			if ( $img['h'] > 0 ) { $out .= self::tag( 'og:image:height', (string) $img['h'] ); }
			$out .= self::tag( 'og:image:alt', $title );
		}
		// Twitter-Paritaet (sonst faellt X auf og: zurueck — explizit ist sauberer).
		$out .= self::tag( 'twitter:card', 'summary_large_image', 'name' );
		$out .= self::tag( 'twitter:title', $title, 'name' );
		$out .= self::tag( 'twitter:description', $desc, 'name' );
		if ( '' !== $img['url'] ) { $out .= self::tag( 'twitter:image', $img['url'], 'name' ); }
		return $out;
	}

	/** Featured Image (full, absolut) inkl. Maße; sonst filterbares Default-Social-Bild. */
	private static function og_image( $id ) {
		$url = ''; $w = 0; $h = 0;
		if ( has_post_thumbnail( $id ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $id ), 'full' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$url = (string) $src[0];
				$w   = isset( $src[1] ) ? (int) $src[1] : 0;
				$h   = isset( $src[2] ) ? (int) $src[2] : 0;
			}
		}
		if ( '' === $url ) {
			// Default-Social-Bild (Teil ohne Featured Image). Filterbar via m24_og_default_image.
			$url = (string) apply_filters( 'm24_og_default_image', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2026/06/bild-folgt.png' );
			$w = 0; $h = 0;
		}
		return array( 'url' => '' !== $url ? esc_url_raw( $url ) : '', 'w' => $w, 'h' => $h );
	}
}
