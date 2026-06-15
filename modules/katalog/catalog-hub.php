<?php
/**
 * M24 Plattform — Modell-Hubs (indexierbare Landing Pages je BMW-M-Modell)
 * Modul: modules/katalog/catalog-hub.php
 *
 * Routing + Daten/Logik fuer /gebrauchtteile/{hub}/. Das Template (catalog-hub-view.php)
 * baut nur den Inhaltsbereich zwischen get_header()/get_footer(). SEO: self-canonical,
 * index,follow (ueberschreibt die generelle „Modell-Filter = noindex"-Regel — nur diese Hubs).
 *
 * Seed-Defaults pro Hub hier zentral; spaeter via Term-Meta editierbar (Phase 2).
 * Slideshow-Bilder kommen in Phase 2 aus Term-Meta (Mediathek) — v1 zeigt Platzhalter.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Hub {

	const QV  = 'm24_hub';
	const TAX = 'm24_fahrzeugkat';
	const REWRITE_FLAG = 'm24_hub_rewrites_v1';

	/** Hub-Slug → Konfiguration (gemappte Term-Slugs + redaktionelle Seed-Defaults). */
	public static function hubs() {
		$brand = 'MOTORSPORT24 steht in keiner Geschäftsverbindung zur BMW AG und ist kein autorisierter Händler.';
		return array(
			'm3-e30' => array(
				'terms'    => array( 'm3-e30', 'bmw-m3-e30' ),
				'modell'   => 'M3 E30', 'motor' => 'S14 2,3 / 2,5 L', 'baujahre' => '1986–1991',
				'sub'      => 'Aus eigenen Rennsport-Umbauten – geprüft – mit Historie plus unsere Auswahl an eigenen gebrauchten Rennsport-Teilen.',
				'intro_h2' => 'Teile mit Historie — aus eigenen Rennsport-Umbauten',
				'intro'    => array(
					'Unsere Gebrauchtteile passend für den BMW M3 E30 stammen überwiegend aus unseren eigenen Rennsport-Umbauten: Wenn wir einen M3 E30 für den Renneinsatz auf- oder umbauen, werden hochwertige Originalteile fachgerecht ausgebaut, geprüft und hier mit klarer Herkunft angeboten. Dazu kommen ausgewählte Aftermarket-Teile passend für den M3 E30.',
					'So bekommen Sie Teile mit Geschichte statt anonymer Massenware. Seit 2006 beliefern wir Werkstätten, Restauratoren und Rennsport-Teams weltweit. Sie suchen ein bestimmtes Teil, das hier noch nicht gelistet ist? Fragen Sie uns — wir greifen auf einen großen, nicht vollständig online gelisteten Bestand zu.',
				),
			),
			'm3-e36' => array( 'terms' => array( 'm3-e36', 'bmw-m3-e36' ), 'modell' => 'M3 E36', 'motor' => 'S50 / S52', 'baujahre' => '1992–1999' ),
			'm3-e46' => array( 'terms' => array( 'm3-e46', 'bmw-m3-e46' ), 'modell' => 'M3 E46', 'motor' => 'S54 3,2 L', 'baujahre' => '2000–2006' ),
			'm3-e9x' => array( 'terms' => array( 'm3-e9x', 'bmw-m3-e9x' ), 'modell' => 'M3 E9x', 'motor' => 'S65 V8 4,0 L', 'baujahre' => '2007–2013' ),
			'm-sonstige' => array( 'terms' => array( 'sonstige-bmw-m-modelle', 'bmw-m-sonstige' ), 'modell' => 'M-Modelle', 'motor' => 'diverse', 'baujahre' => 'modellabhängig' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 20 );
		// Reihenfolge deterministisch erzwingen: Hub-Regel MUSS vor der generischen
		// Detail-Regel ^gebrauchtteile/([^/]+)/?$ stehen, sonst schluckt diese die
		// Hub-Slugs als Einzelteil ⇒ 404. Array-Union setzt unsere Regel nach vorn.
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'prepend_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_flush' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ) );
		// SEO (wpSEO-Filter): nur auf Hub-Seiten.
		add_filter( 'wpseo_set_title',     array( __CLASS__, 'seo_title' ), 99, 1 );
		add_filter( 'wpseo_set_desc',      array( __CLASS__, 'seo_desc' ), 99, 1 );
		add_filter( 'wpseo_set_robots',    array( __CLASS__, 'seo_robots' ), 99, 1 );
		add_filter( 'wpseo_set_canonical', array( __CLASS__, 'seo_canonical' ), 99, 1 );
		// Rohe Filter-URL ?m24_modell={hub-term} auf dem Gebrauchtteile-Archiv →
		// rel=canonical auf den sauberen Hub (kein Duplicate); Filterseite bleibt noindex.
		add_filter( 'wpseo_set_canonical', array( __CLASS__, 'archive_canonical' ), 99, 1 );
		add_filter( 'document_title_parts', array( __CLASS__, 'doc_title' ) );
	}

	public static function add_rewrite() {
		$slugs = implode( '|', array_map( 'preg_quote', array_keys( self::hubs() ) ) );
		add_rewrite_rule( '^gebrauchtteile/(' . $slugs . ')/?$', 'index.php?' . self::QV . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) { $vars[] = self::QV; return $vars; }

	/** Hub-Regel garantiert an den Anfang des Rule-Arrays (gewinnt vor Detail-Regel). */
	public static function prepend_rule( $rules ) {
		$slugs = implode( '|', array_map( 'preg_quote', array_keys( self::hubs() ) ) );
		$hub = array( '^gebrauchtteile/(' . $slugs . ')/?$' => 'index.php?' . self::QV . '=$matches[1]' );
		return $hub + (array) $rules;
	}

	/** Rewrite-Regeln einmalig flushen (nach Deploy), ohne Activation-Hook. */
	public static function maybe_flush() {
		if ( get_option( self::REWRITE_FLAG ) === self::REWRITE_FLAG ) { return; }
		self::add_rewrite();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, self::REWRITE_FLAG );
	}

	/** Aktueller Hub-Slug (validiert) oder ''. */
	public static function current() {
		$h = get_query_var( self::QV );
		return ( is_string( $h ) && isset( self::hubs()[ $h ] ) ) ? $h : '';
	}

	public static function is_hub() { return '' !== self::current(); }

	/** Term-IDs des aktuellen Hubs (gemappte, existierende Terms). */
	public static function term_ids( $hub = '' ) {
		$hub = $hub ?: self::current();
		if ( '' === $hub ) { return array(); }
		$ids = array();
		foreach ( self::hubs()[ $hub ]['terms'] as $slug ) {
			$t = get_term_by( 'slug', $slug, self::TAX );
			if ( $t && ! is_wp_error( $t ) ) { $ids[] = (int) $t->term_id; }
		}
		return $ids;
	}

	/** Live-Zaehler: aktive, veroeffentlichte Teile im Hub (nicht verkauft/ausgeblendet). */
	public static function count( $hub = '' ) {
		$ids = self::term_ids( $hub );
		if ( empty( $ids ) ) { return 0; }
		$q = new WP_Query( array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => self::TAX, 'terms' => $ids ) ),
			'meta_query'     => array( array( 'key' => '_m24_status', 'value' => 'aktiv' ) ),
		) );
		$n = count( $q->posts );
		wp_reset_postdata();
		return $n;
	}

	/** WP_Query der Hub-Teile (alle aktiven, Neuheit zuerst) — fuers Grid. */
	public static function parts_query( $hub = '' ) {
		$ids = self::term_ids( $hub );
		if ( empty( $ids ) ) { return new WP_Query( array( 'post__in' => array( 0 ) ) ); }
		return new WP_Query( array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => self::TAX, 'terms' => $ids ) ),
			'meta_query'     => array( array( 'key' => '_m24_status', 'value' => 'aktiv' ) ),
		) );
	}

	/** Term-Meta-Schluessel (ohne Praefix) → Admin/Seite teilen sich diese Liste. */
	const META_PREFIX = '_m24_hub_';
	public static function meta_keys() {
		return array( 'h1', 'sub', 'intro_h2', 'intro', 'modell', 'motor', 'baujahre', 'seo_title', 'seo_desc', 'images' );
	}

	/** Primaerer Term eines Hubs (erster existierender gemappter Slug) — traegt die Term-Meta. */
	public static function primary_term_id( $hub = '' ) {
		$hub = $hub ?: self::current();
		if ( '' === $hub || ! isset( self::hubs()[ $hub ] ) ) { return 0; }
		foreach ( self::hubs()[ $hub ]['terms'] as $slug ) {
			$t = get_term_by( 'slug', $slug, self::TAX );
			if ( $t && ! is_wp_error( $t ) ) { return (int) $t->term_id; }
		}
		return 0;
	}

	/** Hub-Key, dessen Primaer-Term diese Term-ID ist (fuer Admin-Sektion) — sonst ''. */
	public static function hub_of_term( $term_id ) {
		$term_id = (int) $term_id;
		foreach ( array_keys( self::hubs() ) as $hub ) {
			if ( self::primary_term_id( $hub ) === $term_id ) { return $hub; }
		}
		return '';
	}

	/** Hub-Key, der diesen Modell-Term-Slug mappt (fuer Archiv-Canonical) — sonst ''. */
	public static function hub_for_term_slug( $slug ) {
		foreach ( self::hubs() as $hub => $cfg ) {
			if ( in_array( $slug, $cfg['terms'], true ) ) { return $hub; }
		}
		return '';
	}

	/** Seed-Defaults + redaktionelle Term-Meta-Overrides (nicht-leere gewinnen). */
	public static function config( $hub = '' ) {
		$hub = $hub ?: self::current();
		if ( '' === $hub || ! isset( self::hubs()[ $hub ] ) ) { return array(); }
		$cfg = self::hubs()[ $hub ];
		$pid = self::primary_term_id( $hub );
		if ( $pid ) {
			$m = function ( $k ) use ( $pid ) { return trim( (string) get_term_meta( $pid, self::META_PREFIX . $k, true ) ); };
			foreach ( array( 'modell', 'motor', 'baujahre', 'sub', 'intro_h2', 'h1', 'seo_title', 'seo_desc' ) as $k ) {
				if ( '' !== $m( $k ) ) { $cfg[ $k ] = $m( $k ); }
			}
			if ( '' !== $m( 'intro' ) ) { $cfg['intro_html'] = $m( 'intro' ); } // WYSIWYG-Override (HTML)
			if ( '' !== $m( 'images' ) ) {
				$cfg['images'] = array_values( array_filter( array_map( 'intval', explode( ',', $m( 'images' ) ) ) ) );
			}
		}
		return $cfg;
	}

	public static function url( $hub ) { return home_url( '/gebrauchtteile/' . $hub . '/' ); }

	/** H1/Title-Muster (Markenrecht: Bestimmungsangabe). Term-Meta-Override gewinnt. */
	public static function h1( $hub = '' ) {
		$c = self::config( $hub );
		if ( ! empty( $c['h1'] ) ) { return $c['h1']; }
		return 'Gebrauchtteile passend für BMW ' . ( $c['modell'] ?? '' );
	}

	/** Slideshow-Bilder (aus Term-Meta) → [id,url,w,h,alt]; leer ⇒ View zeigt Platzhalter. */
	public static function images( $hub = '' ) {
		$c   = self::config( $hub );
		$ids = ! empty( $c['images'] ) ? $c['images'] : array();
		$out = array();
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( ! is_array( $src ) || empty( $src[0] ) ) { continue; }
			$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			if ( '' === $alt ) { $alt = 'Gebrauchtteile passend für BMW ' . ( $c['modell'] ?? '' ); }
			$out[] = array( 'id' => (int) $id, 'url' => $src[0], 'w' => (int) ( $src[1] ?? 0 ), 'h' => (int) ( $src[2] ?? 0 ), 'alt' => $alt );
		}
		return $out;
	}

	/** OG-Bild = erstes Slideshow-Bild (full) → Default-Social-Bild. */
	public static function og_image_url( $hub = '' ) {
		$imgs = self::images( $hub );
		if ( ! empty( $imgs ) ) { return $imgs[0]['url']; }
		$d = function_exists( 'm24_noimg_placeholder_url' ) ? m24_noimg_placeholder_url() : '';
		return (string) apply_filters( 'm24_og_default_image', $d );
	}

	/** ?m24_modell={hub-term} auf dem Gebrauchtteile-Archiv → Hub-Canonical. */
	public static function archive_canonical( $url ) {
		if ( ! class_exists( 'M24_Catalog_Archive' ) || ! M24_Catalog_Archive::is_archive() ) { return $url; }
		if ( 'gebraucht' !== M24_Catalog_Archive::current_typ() ) { return $url; }
		$mod = M24_Catalog_Archive::current_modell();
		if ( '' === $mod ) { return $url; }
		$hub = self::hub_for_term_slug( $mod );
		return $hub ? self::url( $hub ) : $url;
	}

	public static function template_include( $template ) {
		if ( self::is_hub() ) {
			$tpl = M24_PLATTFORM_DIR . 'modules/katalog/catalog-hub-view.php';
			if ( file_exists( $tpl ) ) { return $tpl; }
		}
		return $template;
	}

	// ── SEO ────────────────────────────────────────────────────────────────
	public static function seo_title( $title )  {
		if ( ! self::is_hub() ) { return $title; }
		$c = self::config();
		return ! empty( $c['seo_title'] ) ? $c['seo_title'] : self::build_title();
	}
	public static function doc_title( $parts )  { if ( self::is_hub() ) { $parts['title'] = self::h1(); } return $parts; }
	public static function seo_desc( $desc )     {
		if ( ! self::is_hub() ) { return $desc; }
		$c = self::config();
		if ( ! empty( $c['seo_desc'] ) ) { return $c['seo_desc']; }
		$intro = ! empty( $c['intro_html'] ) ? wp_strip_all_tags( $c['intro_html'] )
			: ( isset( $c['intro'][0] ) ? wp_strip_all_tags( $c['intro'][0] ) : '' );
		if ( '' !== $intro ) { return mb_substr( $intro, 0, 158 ); }
		return self::h1() . ' — geprüft, mit Historie, weltweiter Versand bei MOTORSPORT24 seit 2006.';
	}
	public static function seo_robots( $robots )   { return self::is_hub() ? 'index, follow' : $robots; }
	public static function seo_canonical( $url )    { return self::is_hub() ? self::url( self::current() ) : $url; }

	private static function build_title() {
		$base = self::h1();
		foreach ( array( $base . ' | MOTORSPORT24 seit 2006', $base . ' | MOTORSPORT24', $base ) as $v ) {
			if ( mb_strlen( $v ) <= 65 ) { return $v; }
		}
		return $base;
	}
}
