<?php
/**
 * M24 Plattform — Share-Bild fuer Open Graph (EINE Quelle fuer alle OG-Module)
 * Modul: includes/class-m24-share-image.php
 *
 * Warum es das gibt: WhatsApp rendert KEIN WebP. Auf dieser Installation biegt aber ein
 * WebP-Konverter ueber den WP-Filter `image_editor_output_format` JEDE erzeugte Groesse
 * auf WebP um — und Jetpack Photon (i0.wp.com) liefert je nach Accept-Header ebenfalls
 * WebP aus. Ergebnis: og:image zeigte auf .webp → keine Vorschau beim Teilen.
 *
 * Diese Klasse liefert zu einem Attachment ein GARANTIERTES JPEG:
 *  - Quelle ist das Original-JPEG/PNG (nicht die bereits konvertierte WebP-Fassung),
 *  - erzeugt wird ~1200px lange Kante, Q82, Ziel-MIME explizit image/jpeg,
 *  - waehrend der Erzeugung ist `image_editor_output_format` geleert und
 *    `wp_editor_set_quality` fest gezogen (sonst gewinnt der Konverter),
 *  - Photon ist fuer die Meta-URL abgeschaltet,
 *  - Ablage als `<stem>-m24og.jpg`, gemerkt in eigener Attachment-Meta statt in
 *    $meta['sizes'] → kein Konverter-Hook macht die Datei nachtraeglich zu WebP.
 *
 * Nutzer: M24_Post_OG (Beitraege), M24FZ_SEO (Fahrzeuge), M24_Catalog_OG (Teile/Hubs).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Share_Image {

	/** Ziel-Kantenlaenge des Share-Bildes. */
	const MIN_W = 1200;

	/** Attachment-Meta: Pfad + Masse der erzeugten JPEG-Ableitung (Cache). */
	const META = '_m24_og_share_jpeg';

	/** @var int Qualitaet fuer die laufende Erzeugung (siehe force_quality). */
	private static $quality = 82;

	/**
	 * Share-Bild zu einem Attachment.
	 *
	 * @param int $att Attachment-ID.
	 * @return array|null [ url, width, height ] — URL und Masse beschreiben IMMER dieselbe Datei.
	 */
	public static function for_attachment( $att ) {
		$att = (int) $att;
		if ( ! $att ) { return null; }

		// Photon (i0.wp.com) fuer die finale Meta-URL abschalten → Crawler holen direkt von der
		// Domain; Photon entscheidet sonst per Accept-Header und schiebt WebP unter.
		add_filter( 'jetpack_photon_skip_for_url', '__return_true', 99 );
		$src = self::resolve( $att );
		remove_filter( 'jetpack_photon_skip_for_url', '__return_true', 99 );

		if ( ! $src || ! self::is_safe( $src[0] ) ) { return null; }
		return $src;
	}

	/** Nur Formate, die FB/WhatsApp sicher rendern. WebP/AVIF sind hier bewusst raus. */
	public static function is_safe( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return (bool) preg_match( '/\.(jpe?g|png)$/i', $path );
	}

	/**
	 * Sicherheitsnetz fuer URLs, die NICHT aus for_attachment() stammen (Hub-Bilder, Logos,
	 * Hand-URLs): unsicheres Format → festes Marken-Default (JPEG).
	 */
	public static function safe_url_or_default( $url ) {
		$url = (string) $url;
		if ( '' !== $url && self::is_safe( $url ) ) { return $url; }
		return function_exists( 'm24_og_default_image_url' ) ? (string) m24_og_default_image_url() : '';
	}

	/* ── intern ──────────────────────────────────────────────────────────────── */

	/**
	 * [url,w,h] einer REALEN JPEG-Datei:
	 *   1) gecachte Ableitung  2) frisch erzeugen  3) Original-JPEG/PNG.
	 * Die WP-Zwischengroessen werden bewusst NICHT genutzt — sie sind hier WebP.
	 */
	private static function resolve( $att ) {
		$cached = get_post_meta( $att, self::META, true );
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
	 * Quelldatei: bevorzugt das ORIGINAL-JPEG/PNG, nicht die bereits zu WebP konvertierte
	 * Fassung. Reihenfolge: WP-Original (`original_image`) → angehaengte Datei → gleichnamige
	 * Geschwisterdatei mit Bild-Endung (der Konverter laesst das Original liegen).
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
			$stem = preg_replace( '/\.[^.\/]+$/', '', $p );
			foreach ( array( 'jpg', 'jpeg', 'JPG', 'JPEG', 'png', 'PNG' ) as $ext ) {
				if ( file_exists( $stem . '.' . $ext ) ) { return $stem . '.' . $ext; }
			}
		}
		return $fallback;
	}

	/** Erzeugt `<stem>-m24og.jpg` (~1200px lange Kante, Q82) und merkt sie am Attachment. */
	private static function generate_jpeg( $att ) {
		$src = self::source_path( $att );
		if ( ! $src || ! file_exists( $src ) ) { return null; }

		$dest = preg_replace( '/\.[^.\/]+$/', '', $src ) . '-m24og.jpg';

		// Schon vorhanden (z. B. nach Verlust der Meta) → wiederverwenden.
		if ( file_exists( $dest ) ) {
			$dim = @getimagesize( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$url = self::path_to_url( $dest );
			if ( $url && is_array( $dim ) ) {
				self::remember( $att, $dest, (int) $dim[0], (int) $dim[1] );
				return array( $url, (int) $dim[0], (int) $dim[1] );
			}
		}

		self::$quality = (int) apply_filters( 'm24_og_jpeg_quality', 82 );
		add_filter( 'image_editor_output_format', '__return_empty_array', PHP_INT_MAX );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_quality' ), PHP_INT_MAX );

		$editor = wp_get_image_editor( $src );
		$saved  = null;
		if ( ! is_wp_error( $editor ) ) {
			$editor->set_quality( self::$quality );
			$size = $editor->get_size();
			$w    = is_array( $size ) ? (int) $size['width'] : 0;
			$h    = is_array( $size ) ? (int) $size['height'] : 0;
			// ~1200px lange Kante, proportional, kein Upscale.
			if ( max( $w, $h ) > self::MIN_W ) { $editor->resize( self::MIN_W, self::MIN_W, false ); }
			$saved = $editor->save( $dest, 'image/jpeg' ); // Ziel-MIME explizit → nie WebP
		}

		remove_filter( 'image_editor_output_format', '__return_empty_array', PHP_INT_MAX );
		remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'force_quality' ), PHP_INT_MAX );

		if ( ! is_array( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) { return null; }
		// Doppelter Boden: haette doch ein Filter WebP geschrieben, verwerfen wir das Ergebnis.
		if ( ! preg_match( '/\.(jpe?g)$/i', $saved['path'] ) ) { return null; }

		$url = self::path_to_url( $saved['path'] );
		if ( ! $url ) { return null; }
		self::remember( $att, $saved['path'], (int) $saved['width'], (int) $saved['height'] );
		return array( $url, (int) $saved['width'], (int) $saved['height'] );
	}

	/** Qualitaet gegen fremde Filter durchsetzen — gilt nur waehrend generate_jpeg(). */
	public static function force_quality( $quality ) {
		return self::$quality;
	}

	/** Erzeugte Ableitung am Attachment merken (eigene Meta, NICHT in $meta['sizes']). */
	private static function remember( $att, $path, $w, $h ) {
		update_post_meta( $att, self::META, array( 'path' => $path, 'w' => (int) $w, 'h' => (int) $h ) );
	}

	/** Dateipfad → oeffentliche URL (ueber das Uploads-Verzeichnis, ohne Photon/Rewrite). */
	private static function path_to_url( $path ) {
		$up = wp_get_upload_dir();
		if ( ! empty( $up['basedir'] ) && 0 === strpos( $path, $up['basedir'] ) ) {
			return $up['baseurl'] . str_replace( '\\', '/', substr( $path, strlen( $up['basedir'] ) ) );
		}
		if ( defined( 'ABSPATH' ) && 0 === strpos( $path, ABSPATH ) ) {
			return home_url( '/' . ltrim( str_replace( '\\', '/', substr( $path, strlen( ABSPATH ) ) ), '/' ) );
		}
		return '';
	}
}
