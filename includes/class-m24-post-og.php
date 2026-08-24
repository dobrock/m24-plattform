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
 *                     (kleiner) → festes Marken-Default (1200x630)
 *  - twitter:card=summary_large_image + twitter:title/description/image (gleiches Bild)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Post_OG {

	/** Mindestbreite, ab der ein Bild als Share-Bild taugt (WhatsApp/FB Large-Preview). */
	const MIN_W = 1200;

	/** @var bool Nur unsere eigene Pufferung wieder schliessen. */
	private static $buffering = false;

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'buffer_start' ), -1000 );
		add_action( 'wp_head', array( __CLASS__, 'buffer_end' ), 1000 );
		// Share-Groesse 1200px breit. M24FZ_SEO registriert dieselbe Groesse — doppeltes
		// add_image_size mit identischen Werten ist unkritisch (gleicher Key, gleiche Werte),
		// aber wir registrieren nur, wenn sie fehlt (z. B. Fahrzeug-Modul deaktiviert).
		add_action( 'after_setup_theme', array( __CLASS__, 'register_size' ), 20 );
	}

	public static function register_size() {
		if ( ! has_image_size( 'm24_og' ) ) {
			add_image_size( 'm24_og', 1200, 0, false );
		}
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
	 * → Marken-Default. Liefert array{url,w,h,alt} — URL und Maße beschreiben IMMER dieselbe Datei.
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

		// 4) Festes Marken-Default (1200x630, liegt im Plugin → immer erreichbar).
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

	/** [url,w,h,alt] einer REALEN Datei zu einem Attachment — Photon (i0.wp.com) umgangen. */
	private static function from_attachment( $att, $post_id ) {
		// Photon fuer die finale Meta-URL abschalten → FB/WhatsApp holt direkt von der Domain
		// (Photon-URLs mit ?fit=…&ssl=1 sind fuer Crawler unnoetig fehleranfaellig).
		add_filter( 'jetpack_photon_skip_for_url', '__return_true', 99 );
		$src = self::share_image( $att );
		remove_filter( 'jetpack_photon_skip_for_url', '__return_true', 99 );
		if ( ! $src ) { return null; }

		$alt = trim( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) { $alt = get_the_title( $post_id ); }
		return array( 'url' => esc_url_raw( $src[0] ), 'w' => (int) $src[1], 'h' => (int) $src[2], 'alt' => self::plain( $alt ) );
	}

	/**
	 * [url,w,h] einer echten Datei: m24_og (bei Bedarf on-the-fly erzeugt) → large → Original.
	 * Gleiches Vorgehen wie M24FZ_SEO::share_image() (dort seit Monaten produktiv erprobt).
	 */
	private static function share_image( $att ) {
		$meta = wp_get_attachment_metadata( $att );

		if ( is_array( $meta ) && ! empty( $meta['sizes']['m24_og']['file'] ) ) {
			$url = self::size_url( $att, $meta['sizes']['m24_og']['file'] );
			if ( $url ) { return array( $url, (int) $meta['sizes']['m24_og']['width'], (int) $meta['sizes']['m24_og']['height'] ); }
		}

		$gen = self::generate_og_size( $att );
		if ( $gen ) { return $gen; }

		if ( is_array( $meta ) && ! empty( $meta['sizes']['large']['file'] ) ) {
			$url = self::size_url( $att, $meta['sizes']['large']['file'] );
			if ( $url ) { return array( $url, (int) $meta['sizes']['large']['width'], (int) $meta['sizes']['large']['height'] ); }
		}

		if ( is_array( $meta ) && ! empty( $meta['width'] ) ) {
			$url = wp_get_attachment_url( $att );
			if ( $url ) { return array( $url, (int) $meta['width'], (int) $meta['height'] ); }
		}
		return null;
	}

	/** m24_og-Zwischengroesse (1200px breit) erzeugen + in den Attachment-Metadaten registrieren. */
	private static function generate_og_size( $att ) {
		$path = get_attached_file( $att );
		if ( ! $path || ! file_exists( $path ) ) { return null; }
		if ( ! function_exists( 'image_make_intermediate_size' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'og_quality' ), 99, 2 );
		$gen = image_make_intermediate_size( $path, 1200, 0, false ); // kein Crop, proportional
		remove_filter( 'wp_editor_set_quality', array( __CLASS__, 'og_quality' ), 99 );
		if ( ! is_array( $gen ) || empty( $gen['file'] ) ) { return null; } // u. a. Original < 1200 (kein Upscale)

		$meta = wp_get_attachment_metadata( $att );
		if ( ! is_array( $meta ) ) { $meta = array(); }
		if ( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) { $meta['sizes'] = array(); }
		$meta['sizes']['m24_og'] = $gen;
		wp_update_attachment_metadata( $att, $meta );

		$url = self::size_url( $att, $gen['file'] );
		return $url ? array( $url, (int) $gen['width'], (int) $gen['height'] ) : null;
	}

	/** Kompressionsqualitaet NUR fuer die m24_og-Groesse (kleine Share-Datei). */
	public static function og_quality( $quality, $mime = '' ) {
		return (int) apply_filters( 'm24_og_quality', 72, $mime );
	}

	/** URL einer Zwischengroesse (gleicher Ordner wie das Original). */
	private static function size_url( $att, $file ) {
		$full = wp_get_attachment_url( $att );
		if ( ! $full ) { return ''; }
		$full = strtok( $full, '?' ); // evtl. Photon-Query abschneiden
		$pos  = strrpos( $full, '/' );
		return ( false === $pos ) ? '' : substr( $full, 0, $pos + 1 ) . $file;
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
