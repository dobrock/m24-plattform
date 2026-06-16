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
	const REWRITE_FLAG = 'm24_hub_rewrites_v2'; // v2: + /seite/N/-Pagination
	const PER_PAGE = 24;                        // Teile pro Seite (Auftrag: 24–36)

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
		add_action( 'wp', array( __CLASS__, 'fix_status' ) ); // 404/Canonical-Schutz fuer /seite/N/
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) ); // AJAX-Trefferliste
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
		add_rewrite_rule( '^gebrauchtteile/(' . $slugs . ')/seite/([0-9]+)/?$', 'index.php?' . self::QV . '=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( '^gebrauchtteile/(' . $slugs . ')/?$', 'index.php?' . self::QV . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) { $vars[] = self::QV; return $vars; }

	/** Hub-Regeln garantiert an den Anfang des Rule-Arrays (gewinnen vor Detail-Regel). */
	public static function prepend_rule( $rules ) {
		$slugs = implode( '|', array_map( 'preg_quote', array_keys( self::hubs() ) ) );
		$hub = array(
			'^gebrauchtteile/(' . $slugs . ')/seite/([0-9]+)/?$' => 'index.php?' . self::QV . '=$matches[1]&paged=$matches[2]',
			'^gebrauchtteile/(' . $slugs . ')/?$'                => 'index.php?' . self::QV . '=$matches[1]',
		);
		return $hub + (array) $rules;
	}

	/** Hub-Seiten nie 404 (auch /seite/N/) + Theme-Canonical-Redirect aus. */
	public static function fix_status() {
		if ( ! self::is_hub() ) { return; }
		global $wp_query;
		$wp_query->is_404 = false;
		status_header( 200 );
		remove_action( 'template_redirect', 'redirect_canonical' );
	}

	/** Request-Parameter (serverseitig, wirken ueber die ganze Liste inkl. Pagination). */
	public static function current_q()     { return isset( $_GET['q'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ) : ''; } // phpcs:ignore WordPress.Security.NonceVerification
	public static function current_sort()  {
		$s = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $s, array( 'preis-auf', 'preis-ab' ), true ) ? $s : 'neu';
	}
	public static function current_paged() { return max( 1, (int) get_query_var( 'paged' ) ); }

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

	/**
	 * Voll geordnete Teile-ID-Liste des Hubs: Suche (q) + Sortierung (neu/preis-auf/-ab),
	 * Verkauft-Teile auf max. 15 % des Sets gedeckelt. Bei Preissortierung Verkauft ans Ende,
	 * bei „neueste" nach Datum eingemischt.
	 */
	public static function ordered_ids( $hub, $sort, $q ) {
		$ids = self::term_ids( $hub );
		if ( empty( $ids ) ) { return array(); }
		$base = array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => self::TAX, 'terms' => $ids ) ),
		);
		if ( '' !== $q ) { $base['s'] = $q; }

		// Aktive Teile (sortiert).
		$a = $base;
		$a['meta_query'] = array( array( 'key' => '_m24_status', 'value' => 'aktiv' ) );
		if ( 'preis-auf' === $sort || 'preis-ab' === $sort ) {
			$a['meta_key'] = '_m24_preis_netto';
			$a['orderby']  = 'meta_value_num';
			$a['order']    = ( 'preis-auf' === $sort ) ? 'ASC' : 'DESC';
		} else {
			$a['orderby'] = 'date';
			$a['order']   = 'DESC';
		}
		$active = ( new WP_Query( $a ) )->posts;

		// Verkaufte Teile (Datum DESC), auf 15 % des Sets gedeckelt: sold <= aktiv * 3/17.
		$s = $base;
		$s['meta_query'] = array( array( 'key' => '_m24_status', 'value' => 'verkauft' ) );
		$s['orderby']    = 'date';
		$s['order']      = 'DESC';
		$sold = ( new WP_Query( $s ) )->posts;
		$cap  = (int) floor( count( $active ) * 3 / 17 );
		$sold = array_slice( $sold, 0, max( 0, $cap ) );

		if ( empty( $sold ) ) { return $active; }
		if ( 'preis-auf' === $sort || 'preis-ab' === $sort ) {
			return array_merge( $active, $sold ); // Verkauft ans Ende
		}
		// „neueste": aktiv + verkauft nach Datum mischen.
		return get_posts( array(
			'post_type'      => 'm24_teil',
			'post__in'       => array_merge( $active, $sold ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
	}

	/**
	 * Paginierte Liste fuers Grid. Parameter optional explizit (REST/AJAX); sonst aus dem
	 * Request (Server-Render). Rueckgabe: query (aktuelle Seite), total, pages, paged, q, sort, hub.
	 */
	public static function listing( $hub = '', $q = null, $sort = null, $paged = null ) {
		$hub   = $hub ?: self::current();
		$q     = ( null === $q )    ? self::current_q()    : trim( (string) $q );
		$sort  = ( null === $sort ) ? self::current_sort() : ( in_array( $sort, array( 'preis-auf', 'preis-ab' ), true ) ? $sort : 'neu' );
		$ids   = self::ordered_ids( $hub, $sort, $q );
		$total = count( $ids );
		$per   = self::PER_PAGE;
		$pages = max( 1, (int) ceil( $total / $per ) );
		$paged = ( null === $paged ) ? self::current_paged() : max( 1, (int) $paged );
		$paged = min( $paged, $pages );
		$slice = array_slice( $ids, ( $paged - 1 ) * $per, $per );

		if ( empty( $slice ) ) {
			$query = new WP_Query( array( 'post__in' => array( 0 ) ) );
		} else {
			$query = new WP_Query( array(
				'post_type'      => 'm24_teil',
				'post_status'    => 'publish',
				'post__in'       => $slice,
				'orderby'        => 'post__in', // exakte Reihenfolge aus ordered_ids halten
				'posts_per_page' => $per,
				'no_found_rows'  => true,
			) );
		}
		return compact( 'query', 'total', 'pages', 'paged', 'q', 'sort', 'hub' );
	}

	/** Karten-Markup der aktuellen Seite (oder Leer-Hinweis). Server-Render UND AJAX nutzen dies. */
	public static function cards_html( $list ) {
		$lq = $list['query'];
		if ( $lq->have_posts() && class_exists( 'M24_Catalog_Archive' ) ) {
			ob_start();
			while ( $lq->have_posts() ) {
				$lq->the_post();
				echo M24_Catalog_Archive::card_html( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			wp_reset_postdata();
			return ob_get_clean();
		}
		$hub_url = self::url( $list['hub'] );
		if ( '' !== $list['q'] ) {
			return '<p class="m24hub-empty">Keine Treffer für „' . esc_html( $list['q'] ) . '". '
				. '<a href="' . esc_url( $hub_url ) . '">Filter zurücksetzen</a> oder fragen Sie uns — wir haben mehr im Bestand, als online steht.</p>';
		}
		return '<p class="m24hub-empty">Aktuell sind keine Teile für dieses Modell gelistet. Fragen Sie uns — wir haben mehr im Bestand, als online steht.</p>';
	}

	/** Pagination-Nav (echte /seite/N/-Links; JS faengt Klicks ab — Progressive Enhancement). */
	public static function pager_html( $list, $hub_url = '' ) {
		$hub_url = $hub_url ?: self::url( $list['hub'] );
		$links = paginate_links( array(
			'base'      => $hub_url . '%_%',
			'format'    => 'seite/%#%/',
			'current'   => (int) $list['paged'],
			'total'     => (int) $list['pages'],
			'add_args'  => array_filter( array(
				'q'    => '' !== $list['q'] ? $list['q'] : null,
				'sort' => 'neu' !== $list['sort'] ? $list['sort'] : null,
			) ),
			'prev_text' => '‹',
			'next_text' => '›',
			'mid_size'  => 1,
		) );
		return $links ? '<nav class="m24hub-pager" aria-label="Seiten">' . $links . '</nav>' : '';
	}

	/** Zaehler-Label „{N} Teile · sortiert nach …". */
	public static function count_label( $total, $sort ) {
		$lbl = array( 'neu' => 'Neuheit', 'preis-auf' => 'Preis aufsteigend', 'preis-ab' => 'Preis absteigend' );
		return sprintf( _n( '%s Teil', '%s Teile', $total, 'm24-plattform' ), number_format_i18n( $total ) )
			. ' · sortiert nach ' . ( $lbl[ $sort ] ?? 'Neuheit' );
	}

	// ── AJAX (REST) ───────────────────────────────────────────────────────────
	public static function register_rest() {
		register_rest_route( 'm24/v1', '/hub-parts', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true', // oeffentliche Trefferliste (read-only)
			'callback'            => array( __CLASS__, 'rest_parts' ),
			'args'                => array(
				'hub'   => array( 'required' => true ),
				'q'     => array( 'default' => '' ),
				'sort'  => array( 'default' => 'neu' ),
				'paged' => array( 'default' => 1 ),
			),
		) );
	}

	public static function rest_parts( $req ) {
		$hub = sanitize_key( (string) $req['hub'] );
		if ( ! isset( self::hubs()[ $hub ] ) ) {
			return new WP_Error( 'm24_bad_hub', 'unknown hub', array( 'status' => 404 ) );
		}
		$list = self::listing( $hub, (string) $req['q'], (string) $req['sort'], (int) $req['paged'] );
		return rest_ensure_response( array(
			'cards' => self::cards_html( $list ),
			'pager' => self::pager_html( $list ),
			'count' => self::count_label( (int) $list['total'], $list['sort'] ),
			'total' => (int) $list['total'],
			'paged' => (int) $list['paged'],
			'pages' => (int) $list['pages'],
		) );
	}

	/** tagDiv-Logo-H1 → div degradieren (nur Header-Ausgabe; genau 1 H1 = Seitentitel). */
	public static function demote_logo_h1( $html ) {
		if ( false === strpos( $html, 'tdb-logo' ) ) { return $html; }
		if ( ! apply_filters( 'm24_hub_demote_logo_h1', true ) ) { return $html; }
		return preg_replace( '#<h1\b([^>]*)>(\s*<(?:span|div|a)\b[^>]*tdb-logo.*?)</h1>#is', '<div$1>$2</div>', $html );
	}

	/** Term-Meta-Schluessel (ohne Praefix) → Admin/Seite teilen sich diese Liste. */
	const META_PREFIX = '_m24_hub_';
	public static function meta_keys() {
		return array( 'h1', 'sub', 'intro_h2', 'intro', 'modell', 'motor', 'baujahre', 'seo_title', 'seo_desc', 'seo_text', 'images' );
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
			if ( '' !== $m( 'intro' ) ) { $cfg['intro_html'] = $m( 'intro' ); }       // WYSIWYG-Override (HTML)
			if ( '' !== $m( 'seo_text' ) ) { $cfg['seo_text_html'] = $m( 'seo_text' ); } // SEO-Textblock unter dem Grid
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
	public static function seo_robots( $robots )   {
		if ( ! self::is_hub() ) { return $robots; }
		// Filter-/Sortier-Querystrings sind nicht indexierbar (Duplicate-Schutz).
		return ( '' !== self::current_q() || 'neu' !== self::current_sort() ) ? 'noindex, follow' : 'index, follow';
	}
	public static function seo_canonical( $url )    {
		if ( ! self::is_hub() ) { return $url; }
		// Self-canonical je Seite (Pagination), OHNE ?q=/?sort= — NICHT auf Seite 1 zusammenfassen.
		$base  = self::url( self::current() );
		$paged = self::current_paged();
		return $paged > 1 ? $base . 'seite/' . $paged . '/' : $base;
	}

	private static function build_title() {
		$base = self::h1();
		foreach ( array( $base . ' | MOTORSPORT24 seit 2006', $base . ' | MOTORSPORT24', $base ) as $v ) {
			if ( mb_strlen( $v ) <= 65 ) { return $v; }
		}
		return $base;
	}
}
