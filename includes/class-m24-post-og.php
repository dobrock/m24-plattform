<?php
/**
 * M24 Plattform — Open Graph / Twitter Cards fuer REGULAERE BEITRAEGE (post_type 'post')
 * Modul: includes/class-m24-post-og.php
 *
 * Ausgangslage: wpSEO liefert auf Blog-Beitraegen KEINE og:-Tags (Head enthaelt nur
 * title/description/robots/canonical) → WhatsApp/Facebook zeigen beim Teilen kein Bild.
 * Die vorhandenen OG-Quellen decken nur CPTs ab (m24_teil → M24_Catalog_OG,
 * m24_fahrzeug → M24FZ_SEO). Dieses Modul schliesst genau die Luecke „post".
 *
 * Technik (wie M24FZ_SEO, bewusst gleiches Muster):
 *  - wp_head puffern (-1000 … 1000) → wir sehen ALLE fremden Tags, auch spaete.
 *  - DEDUP-GUARD: steht bereits ein og:image im Head (wpSEO-OG spaeter aktiviert,
 *    Jetpack Publicize, Theme, WPCode-Snippet) → wir geben NICHTS aus. Keine Doubletten.
 *  - CPTs werden nicht angefasst (nur is_singular('post')).
 *
 * Werte:
 *  - og:title       = wpSEO-Titel (_wpseo_edit_title) → gerenderter <title> → Beitragstitel
 *  - og:description = wpSEO-Meta (_wpseo_edit_description) → gerendertes meta[description]
 *                     → Excerpt → Inhalt (~160 Zeichen)
 *  - og:image       = Beitragsbild ≥1200px → erstes Inhaltsbild ≥1200px → Beitragsbild
 *                     (kleiner) → festes Marken-Default (1200x630) — IMMER als JPEG,
 *                     nie WebP (WhatsApp rendert WebP nicht → sonst keine Vorschau)
 *  - twitter:card=summary_large_image + twitter:title/description/image (gleiches Bild)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Post_OG {

	/** Mindestbreite, ab der ein Bild als Share-Bild taugt (WhatsApp/FB Large-Preview). */
	const MIN_W = 1200;

	/** Attachment-Meta: Pfad + Masse der erzeugten JPEG-Share-Ableitung (Cache). */
	const META_SHARE = '_m24_og_share_jpeg';

	/** @var bool Nur unsere eigene Pufferung wieder schliessen. */
	private static $buffering = false;

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'buffer_start' ), -1000 );
		add_action( 'wp_head', array( __CLASS__, 'buffer_end' ), 1000 );
		// Bewusst KEINE add_image_size: die WP-Zwischengroessen (auch m24_og) laufen auf dieser
		// Installation durch die WebP-Konvertierung. Das Share-Bild erzeugen wir selbst als JPEG.
	}

	/** Nur Einzelansicht regulaerer Beitraege — CPTs haben eigene OG-Quellen. */
	private static function active() {
		return is_singular( 'post' );
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

		// Dedup-Guard: fremdes og:image vorhanden → Head unveraendert durchreichen.
		if ( false === stripos( $head, 'og:image' ) ) {
			$head .= self::render_tags( get_queried_object_id(), $head );
		}
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput -- Head-Pass-through; eigene Tags sind escaped.
	}

	/* ── Tag-Satz ────────────────────────────────────────────────────────────── */

	/** @param string $head Bereits gerenderter Head (Quelle fuer wpSEO-Title/Description). */
	private static function render_tags( $id, $head ) {
		if ( ! $id ) { return ''; }

		$title = self::og_title( $id, $head );
		$desc  = self::og_desc( $id, $head );
		$img   = self::og_image( $id );

		$out  = "\n<!-- M24 Open Graph (Beitrag; wpSEO liefert fuer 'post' kein OG) -->\n";
		$out .= self::tag( 'og:type', 'article' );
		$out .= self::tag( 'og:site_name', get_bloginfo( 'name' ) );
		$out .= self::tag( 'og:title', $title );
		$out .= self::tag( 'og:description', $desc );
		$out .= self::tag( 'og:url', get_permalink( $id ) );
		if ( '' !== $img['url'] ) {
			$out .= self::tag( 'og:image', $img['url'] );
			if ( 0 === stripos( $img['url'], 'https://' ) ) { $out .= self::tag( 'og:image:secure_url', $img['url'] ); }
			if ( $img['w'] > 0 ) { $out .= self::tag( 'og:image:width', (string) $img['w'] ); }
			if ( $img['h'] > 0 ) { $out .= self::tag( 'og:image:height', (string) $img['h'] ); }
			$out .= self::tag( 'og:image:alt', '' !== $img['alt'] ? $img['alt'] : $title );
		}
		$out .= self::tag( 'twitter:card', 'summary_large_image', 'name' );
		$out .= self::tag( 'twitter:title', $title, 'name' );
		$out .= self::tag( 'twitter:description', $desc, 'name' );
		if ( '' !== $img['url'] ) { $out .= self::tag( 'twitter:image', $img['url'], 'name' ); }
		return $out;
	}

	/** Ein Meta-Tag (leerer Content → ''). */
	private static function tag( $key, $content, $attr = 'property' ) {
		$content = trim( (string) $content );
		if ( '' === $content ) { return ''; }
		return '<meta ' . $attr . '="' . esc_attr( $key ) . '" content="' . esc_attr( $content ) . '">' . "\n";
	}

	/* ── Titel / Beschreibung ────────────────────────────────────────────────── */

	/** wpSEO-Titel (Feld) → gerenderter <title> (= wpSEO-Ausgabe) → Beitragstitel. */
	private static function og_title( $id, $head ) {
		$t = trim( (string) get_post_meta( $id, '_wpseo_edit_title', true ) );
		if ( '' === $t && preg_match( '#<title[^>]*>(.*?)</title>#is', $head, $m ) ) {
			$t = self::plain( $m[1] );
		}
		if ( '' === $t ) { $t = get_the_title( $id ); }
		return self::plain( $t );
	}

	/** wpSEO-Meta (Feld) → gerendertes meta[description] → Excerpt → Inhalt (~160 Z.). */
	private static function og_desc( $id, $head ) {
		$d = trim( (string) get_post_meta( $id, '_wpseo_edit_description', true ) );
		if ( '' === $d && preg_match( '#<meta[^>]+name=["\']description["\'][^>]*content=["\'](.*?)["\']#is', $head, $m ) ) {
			$d = self::plain( $m[1] );
		}
		if ( '' === $d ) {
			$post = get_post( $id );
			$raw  = $post ? (string) $post->post_excerpt : '';
			if ( '' === trim( $raw ) && $post ) { $raw = (string) $post->post_content; }
			$d = self::shorten( self::plain( wp_strip_all_tags( strip_shortcodes( $raw ), true ) ), 160 );
		}
		return $d;
	}

	/** Entities aufloesen, Whitespace/NBSP normalisieren. */
	private static function plain( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
		$s = str_replace( array( "\xc2\xa0", "\r", "\n", "\t" ), ' ', $s );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Auf ~$max Zeichen kuerzen, an der Wortgrenze, mit Auslassungszeichen. */
	private static function shorten( $s, $max ) {
		if ( '' === $s || mb_strlen( $s ) <= $max ) { return $s; }
		$cut = mb_substr( $s, 0, $max );
		$sp  = mb_strrpos( $cut, ' ' );
		if ( $sp && $sp > (int) ( $max * 0.6 ) ) { $cut = mb_substr( $cut, 0, $sp ); }
		return rtrim( $cut, " ,;:.-" ) . ' …';
	}

	/* ── Bild ────────────────────────────────────────────────────────────────── */

	/**
	 * Bild-Kaskade: Beitragsbild ≥1200 → erstes Inhaltsbild ≥1200 → Beitragsbild (kleiner)
	 * → Marken-Default. Liefert array{url,w,h,alt}.
	 *
	 * HARTE REGEL: die finale og:image-URL ist IMMER ein JPEG (bzw. PNG). WhatsApp rendert
	 * KEIN WebP → ein .webp-og:image bedeutet „keine Vorschau". Deshalb erzeugt
	 * generate_jpeg() eine echte JPEG-Ableitung; taugt am Ende nichts, greift das
	 * Marken-Default (JPEG). Kein Pfad darf .webp/.avif ausliefern.
	 */
	private static function og_image( $id ) {
		$thumb = (int) get_post_thumbnail_id( $id );

		// 1) Beitragsbild, wenn das Original gross genug fuer eine Large-Preview ist.
		if ( $thumb && self::orig_width( $thumb ) >= self::MIN_W ) {
			$img = self::from_attachment( $thumb, $id );
			if ( $img ) { return $img; }
		}

		// 2) Erstes Inhaltsbild ≥1200px.
		foreach ( self::content_attachments( $id ) as $att ) {
			if ( self::orig_width( $att ) < self::MIN_W ) { continue; }
			$img = self::from_attachment( $att, $id );
			if ( $img ) { return $img; }
		}

		// 3) Beitragsbild auch unterhalb 1200px — ein echtes Motiv schlaegt das generische Default.
		if ( $thumb ) {
			$img = self::from_attachment( $thumb, $id );
			if ( $img ) { return $img; }
		}

		// 4) Festes Marken-Default (1200x630 JPEG, liegt im Plugin → immer erreichbar).
		return self::default_image();
	}

	private static function default_image() {
		return array(
			'url' => esc_url_raw( m24_og_default_image_url() ),
			'w'   => 1200,
			'h'   => 630,
			'alt' => get_bloginfo( 'name' ),
		);
	}

	/** Originalbreite eines Attachments (0, wenn unbekannt). */
	private static function orig_width( $att ) {
		$meta = wp_get_attachment_metadata( $att );
		return ( is_array( $meta ) && ! empty( $meta['width'] ) ) ? (int) $meta['width'] : 0;
	}

	/** Nur Formate, die FB/WhatsApp sicher rendern. WebP/AVIF sind hier bewusst raus. */
	private static function is_share_safe( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return (bool) preg_match( '/\.(jpe?g|png)$/i', $path );
	}

	/** [url,w,h,alt] zu einem Attachment — garantiert JPEG/PNG, ohne Photon. */
	private static function from_attachment( $att, $post_id ) {
		// Photon (i0.wp.com) fuer die finale Meta-URL abschalten → Crawler holen direkt von der
		// Domain; Photon liefert je nach Accept-Header sonst WebP aus.
		add_filter( 'jetpack_photon_skip_for_url', '__return_true', 99 );
		$src = self::share_image( $att );
		remove_filter( 'jetpack_photon_skip_for_url', '__return_true', 99 );
		if ( ! $src || ! self::is_share_safe( $src[0] ) ) { return null; }

		$alt = trim( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) { $alt = get_the_title( $post_id ); }
		return array( 'url' => esc_url_raw( $src[0] ), 'w' => (int) $src[1], 'h' => (int) $src[2], 'alt' => self::plain( $alt ) );
	}

	/**
	 * [url,w,h] einer REALEN JPEG-Datei:
	 *   1) gecachte M24-Share-JPEG-Ableitung  2) frisch erzeugen  3) Original-JPEG/PNG.
	 * Die WP-Zwischengroessen werden bewusst NICHT genutzt — auf dieser Installation sind sie
	 * WebP (Konverter-Plugin via `image_editor_output_format`), und genau das bricht WhatsApp.
	 */
	private static function share_image( $att ) {
		$cached = get_post_meta( $att, self::META_SHARE, true );
		if ( is_array( $cached ) && ! empty( $cached['path'] ) && file_exists( $cached['path'] ) ) {
			$url = self::path_to_url( $cached['path'] );
			if ( $url ) { return array( $url, (int) $cached['w'], (int) $cached['h'] ); }
		}

		$gen = self::generate_jpeg( $att );
		if ( $gen ) { return $gen; }

		// Notnagel: Original-JPEG/PNG direkt (echte Masse aus der Datei).
		$src = self::source_path( $att );
		if ( $src && preg_match( '/\.(jpe?g|png)$/i', $src ) ) {
			$url = self::path_to_url( $src );
			$dim = @getimagesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- defekte Datei => false
			if ( $url ) { return array( $url, is_array( $dim ) ? (int) $dim[0] : 0, is_array( $dim ) ? (int) $dim[1] : 0 ); }
		}
		return null;
	}

	/**
	 * Quelldatei fuer die Ableitung: bevorzugt das ORIGINAL-JPEG/PNG, nicht die bereits zu WebP
	 * konvertierte Fassung. Reihenfolge: WP-Original (`original_image`) → angehaengte Datei →
	 * gleichnamige Geschwisterdatei mit Bild-Endung (der Konverter laesst das Original liegen).
	 */
	private static function source_path( $att ) {
		$cands = array();
		if ( function_exists( 'wp_get_original_image_path' ) ) {
			$orig = wp_get_original_image_path( $att, true );
			if ( $orig ) { $cands[] = $orig; }
		}
		$file = get_attached_file( $att, true );
		if ( $file ) { $cands[] = $file; }

		$fallback = '';
		foreach ( $cands as $p ) {
			if ( ! file_exists( $p ) ) { continue; }
			if ( preg_match( '/\.(jpe?g|png)$/i', $p ) ) { return $p; }   // Original in gutem Format
			if ( '' === $fallback ) { $fallback = $p; }                    // z. B. .webp — nur als letzte Wahl
			// Geschwisterdatei: gleiches Basisnamen-Stem, aber JPEG/PNG (Konverter-Original).
			$stem = preg_replace( '/\.[^.\/]+$/', '', $p );
			foreach ( array( 'jpg', 'jpeg', 'JPG', 'JPEG', 'png', 'PNG' ) as $ext ) {
				if ( file_exists( $stem . '.' . $ext ) ) { return $stem . '.' . $ext; }
			}
		}
		return $fallback;
	}

	/**
	 * Erzeugt die Share-Ableitung als ECHTES JPEG (~1200px lange Kante, Q82) und merkt sie am
	 * Attachment. Zwei Filter sind dabei entscheidend:
	 *  - `image_editor_output_format` wird fuer die Dauer der Erzeugung geleert → das
	 *    WebP-Konverter-Plugin kann JPEG nicht mehr auf WebP umbiegen (das war die Ursache).
	 *  - `wp_editor_set_quality` wird fest auf unseren Wert gezogen (sonst gewinnt der Konverter).
	 * Die Datei wird NICHT in die Attachment-Metadaten eingetragen → kein Konverter-Hook, der
	 * sie nachtraeglich zu WebP macht.
	 */
	private static function generate_jpeg( $att ) {
		$src = self::source_path( $att );
		if ( ! $src || ! file_exists( $src ) ) { return null; }

		$dest = preg_replace( '/\.[^.\/]+$/', '', $src ) . '-m24og.jpg';

		// Schon vorhanden (z. B. nach Cache-Loeschung der Meta) → wiederverwenden.
		if ( file_exists( $dest ) ) {
			$dim = @getimagesize( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$url = self::path_to_url( $dest );
			if ( $url && is_array( $dim ) ) {
				self::remember( $att, $dest, (int) $dim[0], (int) $dim[1] );
				return array( $url, (int) $dim[0], (int) $dim[1] );
			}
		}

		$q = (int) apply_filters( 'm24_og_jpeg_quality', 82 );
		add_filter( 'image_editor_output_format', '__return_empty_array', PHP_INT_MAX );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_quality' ), PHP_INT_MAX );
		self::$quality = $q;

		$editor = wp_get_image_editor( $src );
		$saved  = null;
		if ( ! is_wp_error( $editor ) ) {
			$editor->set_quality( $q );
			$size = $editor->get_size();
			$w    = is_array( $size ) ? (int) $size['width'] : 0;
			$h    = is_array( $size ) ? (int) $size['height'] : 0;
			// ~1200px lange Kante, proportional, kein Upscale (kleinere Bilder bleiben wie sie sind).
			if ( max( $w, $h ) > self::MIN_W ) { $editor->resize( self::MIN_W, self::MIN_W, false ); }
			$saved = $editor->save( $dest, 'image/jpeg' ); // Ziel-MIME explizit → nie WebP
		}

		remove_filter( 'image_editor_output_format', '__return_empty_array', PHP_INT_MAX );
		remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_quality' ), PHP_INT_MAX );

		if ( ! is_array( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) { return null; }
		// Doppelter Boden: haette ein Filter doch ein WebP geschrieben, verwerfen wir das Ergebnis.
		if ( ! preg_match( '/\.(jpe?g)$/i', $saved['path'] ) ) { return null; }

		$url = self::path_to_url( $saved['path'] );
		if ( ! $url ) { return null; }
		self::remember( $att, $saved['path'], (int) $saved['width'], (int) $saved['height'] );
		return array( $url, (int) $saved['width'], (int) $saved['height'] );
	}

	/** @var int Qualitaet fuer die laufende Erzeugung (siehe force_quality). */
	private static $quality = 82;

	/** Qualitaet gegen fremde Filter durchsetzen — gilt nur waehrend generate_jpeg(). */
	public static function force_quality( $quality ) {
		return self::$quality;
	}

	/** Erzeugte Ableitung am Attachment merken (eigene Meta, NICHT in $meta['sizes']). */
	private static function remember( $att, $path, $w, $h ) {
		update_post_meta( $att, self::META_SHARE, array( 'path' => $path, 'w' => (int) $w, 'h' => (int) $h ) );
	}

	/** Dateipfad → oeffentliche URL (ueber das Uploads-Verzeichnis, ohne Photon/Rewrite). */
	private static function path_to_url( $path ) {
		$up = wp_get_upload_dir();
		if ( ! empty( $up['basedir'] ) && 0 === strpos( $path, $up['basedir'] ) ) {
			return $up['baseurl'] . str_replace( '\\', '/', substr( $path, strlen( $up['basedir'] ) ) );
		}
		// Fallback: relativ zu ABSPATH.
		if ( defined( 'ABSPATH' ) && 0 === strpos( $path, ABSPATH ) ) {
			return home_url( '/' . ltrim( str_replace( '\\', '/', substr( $path, strlen( ABSPATH ) ) ), '/' ) );
		}
		return '';
	}

	/**
	 * Attachment-IDs der Inhaltsbilder in Reihenfolge des Auftretens.
	 * Primaer ueber die WP-Klasse `wp-image-<ID>` (robust, auch bei Photon-URLs),
	 * ergaenzend ueber die src-URL (attachment_url_to_postid).
	 */
	private static function content_attachments( $id ) {
		$post = get_post( $id );
		if ( ! $post ) { return array(); }
		$content = (string) $post->post_content;
		$out     = array();

		if ( preg_match_all( '/wp-image-(\d+)/i', $content, $m ) ) {
			foreach ( $m[1] as $att ) { $out[] = (int) $att; }
		}
		if ( empty( $out ) && preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $url ) {
				$url = strtok( $url, '?' );
				// Photon-Praefix (i0.wp.com/www.motorsport24.de/…) auf die Originaldomain zuruecksetzen.
				if ( preg_match( '#^https?://i\d\.wp\.com/(.+)$#i', $url, $p ) ) { $url = 'https://' . $p[1]; }
				$att = (int) attachment_url_to_postid( $url );
				if ( $att ) { $out[] = $att; }
			}
		}
		return array_slice( array_values( array_unique( array_filter( $out ) ) ), 0, 10 );
	}
}
