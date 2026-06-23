<?php
/**
 * M24 Plattform — Modell-Hubs (indexierbare Landing Pages je BMW-M-Modell)
 * Modul: modules/katalog/catalog-hub.php
 *
 * Routing + Daten/Logik fuer /modelle/{hub}/. Das Template (catalog-hub-view.php)
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
	const CPT = 'm24_modellhub';
	const META = '_m24_hub_';
	const BASE = 'teile';                       // neutrale URL-Basis /teile/{slug}/ (Hubs zeigen neu+gebraucht)
	const REWRITE_FLAG = 'm24_hub_rewrites_v5'; // v5: Base /modelle/ → /teile/
	const PER_PAGE = 24;                        // Teile pro Seite (Auftrag: 24–36)

	/** @var array|null Registry-Cache: slug => post_id (veroeffentlichte Hubs). */
	private static $registry = null;

	/** Hub-Registry aus dem CPT (eine Quelle). slug => post_id. */
	public static function registry() {
		if ( null !== self::$registry ) { return self::$registry; }
		self::$registry = array();
		if ( ! post_type_exists( self::CPT ) ) { return self::$registry; }
		$posts = get_posts( array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'numberposts' => -1,
			'orderby'     => 'menu_order title',
			'order'       => 'ASC',
		) );
		foreach ( $posts as $p ) {
			if ( '' !== $p->post_name ) { self::$registry[ $p->post_name ] = (int) $p->ID; }
		}
		return self::$registry;
	}

	public static function flush_registry() { self::$registry = null; }

	public static function post_id( $hub ) { $r = self::registry(); return isset( $r[ $hub ] ) ? (int) $r[ $hub ] : 0; }

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 20 );
		// Reihenfolge deterministisch erzwingen: Hub-Regel MUSS vor der generischen
		// Detail-Regel ^gebrauchtteile/([^/]+)/?$ stehen, sonst schluckt diese die
		// Hub-Slugs als Einzelteil ⇒ 404. Array-Union setzt unsere Regel nach vorn.
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'prepend_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_flush' ) );
		add_action( 'template_redirect', array( __CLASS__, 'legacy_redirect' ), 1 ); // umbenannte Slugs 301
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

	/** Hub-Slugs (regex-escaped, alternation) aus der Registry; '' wenn keine Hubs. */
	private static function slugs_pattern() {
		$keys = array_keys( self::registry() );
		return $keys ? implode( '|', array_map( 'preg_quote', $keys ) ) : '';
	}

	public static function add_rewrite() {
		$slugs = self::slugs_pattern();
		if ( '' === $slugs ) { return; }
		add_rewrite_rule( '^' . self::BASE . '/(' . $slugs . ')/seite/([0-9]+)/?$', 'index.php?' . self::QV . '=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( '^' . self::BASE . '/(' . $slugs . ')/?$', 'index.php?' . self::QV . '=$matches[1]', 'top' );
	}

	public static function query_vars( $vars ) { $vars[] = self::QV; return $vars; }

	/** Hub-Regeln garantiert an den Anfang des Rule-Arrays. */
	public static function prepend_rule( $rules ) {
		$slugs = self::slugs_pattern();
		if ( '' === $slugs ) { return $rules; }
		$hub = array(
			'^' . self::BASE . '/(' . $slugs . ')/seite/([0-9]+)/?$' => 'index.php?' . self::QV . '=$matches[1]&paged=$matches[2]',
			'^' . self::BASE . '/(' . $slugs . ')/?$'                => 'index.php?' . self::QV . '=$matches[1]',
		);
		return $hub + (array) $rules;
	}

	/**
	 * Alle Alt-Pfade → neue /teile/-URL (direkt, kein Doppel-Hop). Geteilt von 301-Redirect
	 * und Menue-/Cross-Link-Migration. M2/M4 (g82/f82/f87-f22) bewusst NICHT enthalten.
	 */
	public static function legacy_paths() {
		return apply_filters( 'm24_hub_legacy_paths', array(
			// Gebrauchtteile-Alt
			'/gebrauchtteile/m3-e30'                 => '/teile/bmw-m3-e30',
			'/gebrauchtteile/m3-e36'                 => '/teile/bmw-m3-e36',
			'/gebrauchtteile/m3-e46'                 => '/teile/bmw-m3-e46',
			'/gebrauchtteile/m3-e9x'                 => '/teile/bmw-m3-e9x',
			'/gebrauchtteile/sonstige-bmw-m-modelle' => '/teile/sonstige-bmw-m-modelle',
			'/gebrauchtteile/m-sonstige'             => '/teile/sonstige-bmw-m-modelle',
			// Nackt-Slug (Alt-Deeplink) — live 301→/m3-e36/→404 (wp_old_slug). Hier früh (prio 1)
			// abgefangen → direkt auf das gesunde Hub-Ziel. Nur dieser eine Slug ist defekt;
			// /bmw-z4-gt3/, /bmw-e30/, /bmw-e36/, /bmw-e46/ sind gesund und bleiben unberührt.
			'/bmw-m3-e36'                            => '/teile/bmw-m3-e36',
			// Doppel-Inserat: Alt-„for-sale"-Beitrag → neues Fahrzeug-Inserat (CPT m24_fahrzeug).
			'/for-sale-bmw-m3-e30-europameister-061-148' => '/fahrzeuge/bmw-m3-e30-europameister-061-148',
			'/for-sale-2016-porsche-991-r-im-sammlerzustand' => '/fahrzeuge/2016-porsche-991-r-609-991-im-sammlerzustand',
			// Modelle-Zwischenstand
			'/modelle/bmw-m3-e30'                    => '/teile/bmw-m3-e30',
			'/modelle/bmw-m3-e36'                    => '/teile/bmw-m3-e36',
			'/modelle/bmw-m3-e46'                    => '/teile/bmw-m3-e46',
			'/modelle/bmw-m3-e9x'                    => '/teile/bmw-m3-e9x',
			'/modelle/sonstige-bmw-m-modelle'        => '/teile/sonstige-bmw-m-modelle',
			'/modelle/bmw-z4-gt3'                    => '/teile/bmw-z4-gt3',
			// Rennsport-Alt-Seiten (nur M3 + Z4 — NICHT M2/M4 g82/f82/f87-f22)
			'/rennsport-teile-passend-fur-m3-e30'    => '/teile/bmw-m3-e30',
			'/rennsport-teile-passend-fur-e36'       => '/teile/bmw-m3-e36',
			'/rennsport-teile-passend-fur-m3-e46'    => '/teile/bmw-m3-e46',
			'/rennsport-teile-fur-e90'               => '/teile/bmw-m3-e9x',
			'/rennsport-teile-passend-fur-m3-e92'    => '/teile/bmw-m3-e9x',
			'/rennsport-teile-passend-fur-z4-gt3'    => '/teile/bmw-z4-gt3',
		) );
	}

	/**
	 * 301 von allen Alt-Pfaden auf die neuen /teile/-URLs. Erhaelt Subpfade (/seite/N/)
	 * UND Querystrings (?sort=/?q=/?kat=). Matcht NUR diese Pfade — Teile-Archiv
	 * /gebrauchtteile/, /rennsport-teile/ und Detailseiten bleiben unberuehrt.
	 */
	public static function legacy_redirect() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		if ( '' === $path ) { return; }
		$map = self::legacy_paths();
		foreach ( $map as $old => $new ) {
			if ( $path === $old || 0 === strpos( $path, $old . '/' ) ) {
				$tail = substr( $path, strlen( $old ) ); // '/seite/2/' o. '' o. '/'
				if ( '' === $tail ) { $tail = '/'; }
				$qs = ( isset( $_SERVER['QUERY_STRING'] ) && '' !== $_SERVER['QUERY_STRING'] )
					? '?' . preg_replace( '/[^A-Za-z0-9_\-=&%\.]/', '', wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
				wp_safe_redirect( home_url( $new . $tail . $qs ), 301 );
				exit;
			}
		}
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
		// Default = „teuerste zuerst" (preis-ab); „neu"/„preis-auf" bleiben explizit wählbar.
		return in_array( $s, array( 'preis-auf', 'preis-ab', 'neu' ), true ) ? $s : 'preis-ab';
	}
	public static function current_paged() { return max( 1, (int) get_query_var( 'paged' ) ); }
	/** ?kat= im Request (rennsport|gebraucht|alle) oder '' (⇒ Hub-Default greift). */
	public static function current_kat() {
		$k = isset( $_GET['kat'] ) ? sanitize_key( wp_unslash( $_GET['kat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $k, array( 'rennsport', 'gebraucht', 'alle' ), true ) ? $k : '';
	}
	/** Effektive Kategorie eines Hubs: ?kat= (Vorrang) → Hub-Default (default_kat) → 'alle'. */
	public static function effective_kat( $hub = '' ) {
		$k = self::current_kat();
		if ( '' !== $k ) { return $k; }
		$c = self::config( $hub );
		$dk = $c['default_kat'] ?? 'alle';
		return in_array( $dk, array( 'rennsport', 'gebraucht', 'alle' ), true ) ? $dk : 'alle';
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
		return ( is_string( $h ) && isset( self::registry()[ $h ] ) ) ? $h : '';
	}

	public static function is_hub() { return '' !== self::current(); }

	/** Term-IDs des aktuellen Hubs (CPT-Mapping; Fallback Slug→Modell-Term, nie leer für bekannte Hubs). */
	public static function term_ids( $hub = '' ) {
		$hub = $hub ?: self::current();
		$c   = self::config( $hub );
		if ( ! empty( $c['term_ids'] ) ) { return $c['term_ids']; }
		return self::default_term_ids( $hub ); // DEFENSIVE: fehlendes Mapping leert NIE die Seite
	}

	/**
	 * Standard-Zuordnung Hub-Slug → FLACHER Modell-Term (Hauptbestand, Slug == Hub-Slug).
	 * NICHT der hierarchische „BMW M3 …"-Term unter „BMW 3er" (kleiner Doppel-Satz).
	 * Sicherheitsnetz, filterbar.
	 */
	public static function default_term_ids( $hub ) {
		// Hub-Slug (bmw-…) → FLACHER Modell-Term-Slug (Hauptbestand). Filterbar.
		$map = apply_filters( 'm24_hub_default_terms', array(
			'bmw-m3-e30'             => array( 'm3-e30' ),
			'bmw-m3-e36'             => array( 'm3-e36' ),
			'bmw-m3-e46'             => array( 'm3-e46' ),
			'bmw-m3-e9x'             => array( 'm3-e9x' ),
			'sonstige-bmw-m-modelle' => array( 'sonstige-bmw-m-modelle' ),
			'bmw-z4-gt3'             => array( 'z4-gt3' ),
		) );
		$slugs = isset( $map[ $hub ] ) ? (array) $map[ $hub ] : array( $hub ); // sonst: gleichnamiger Term
		$ids   = array();
		foreach ( $slugs as $s ) {
			$t = get_term_by( 'slug', $s, self::TAX );
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
	 * Aktive Teile je Kategorie (gleiche Metrik wie count(): _m24_status=aktiv) — fuer
	 * die Switch-Mengen + Telemetrie. Rueckgabe [rennsport,gebraucht,alle].
	 */
	public static function kat_counts( $hub = '' ) {
		$res = array( 'rennsport' => 0, 'gebraucht' => 0, 'alle' => 0 );
		$ids = self::term_ids( $hub );
		if ( empty( $ids ) ) { return $res; }
		$base = array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => self::TAX, 'terms' => $ids ) ),
		);
		$r = $base; $r['meta_query'] = array( 'relation' => 'AND', array( 'key' => '_m24_status', 'value' => 'aktiv' ), self::typ_clause( 'rennsport' ) );
		$g = $base; $g['meta_query'] = array( 'relation' => 'AND', array( 'key' => '_m24_status', 'value' => 'aktiv' ), self::typ_clause( 'gebraucht' ) );
		$res['rennsport'] = count( ( new WP_Query( $r ) )->posts );
		$res['gebraucht'] = count( ( new WP_Query( $g ) )->posts );
		$res['alle']      = $res['rennsport'] + $res['gebraucht'];
		return $res;
	}

	/**
	 * Voll geordnete Teile-ID-Liste des Hubs: Suche (q) + Sortierung (neu/preis-auf/-ab),
	 * Verkauft-Teile auf max. 15 % des Sets gedeckelt. Bei Preissortierung Verkauft ans Ende,
	 * bei „neueste" nach Datum eingemischt.
	 */
	/** kat (rennsport|gebraucht|alle) → _m24_typ-Klausel; 'alle'/leer ⇒ keine. */
	private static function typ_clause( $kat ) {
		if ( 'rennsport' === $kat ) { return array( 'key' => '_m24_typ', 'value' => 'neu' ); }
		if ( 'gebraucht' === $kat ) {
			// gebraucht = _m24_typ != 'neu' (deckt auch ungesetzte Alt-Teile ab).
			return array( 'relation' => 'OR',
				array( 'key' => '_m24_typ', 'value' => 'neu', 'compare' => '!=' ),
				array( 'key' => '_m24_typ', 'compare' => 'NOT EXISTS' ),
			);
		}
		return null; // alle
	}

	public static function ordered_ids( $hub, $sort, $q, $kat = 'alle' ) {
		$ids = self::term_ids( $hub );
		if ( empty( $ids ) ) { return array(); }
		$typ  = self::typ_clause( $kat );
		$base = array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => self::TAX, 'terms' => $ids ) ),
		);
		if ( '' !== $q ) { $base['s'] = $q; }

		// Aktive Teile (sortiert) + optionaler Kategorie-Filter.
		$a = $base;
		$a['meta_query'] = array( 'relation' => 'AND', array( 'key' => '_m24_status', 'value' => 'aktiv' ) );
		if ( $typ ) { $a['meta_query'][] = $typ; }
		if ( 'preis-auf' === $sort || 'preis-ab' === $sort ) {
			// Robuste Preis-Sortierung (LEFT JOIN): preislose/0-Teile bleiben drin, landen am Ende.
			$a['m24_price_sort'] = ( 'preis-auf' === $sort ) ? 'ASC' : 'DESC';
		} else {
			$a['orderby'] = 'date';
			$a['order']   = 'DESC';
		}
		$active = ( new WP_Query( $a ) )->posts;

		// Verkaufte Teile (Datum DESC), auf 15 % des Sets gedeckelt: sold <= aktiv * 3/17.
		$s = $base;
		$s['meta_query'] = array( 'relation' => 'AND', array( 'key' => '_m24_status', 'value' => 'verkauft' ) );
		if ( $typ ) { $s['meta_query'][] = $typ; }
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
	public static function listing( $hub = '', $q = null, $sort = null, $paged = null, $kat = null ) {
		$hub   = $hub ?: self::current();
		$q     = ( null === $q )    ? self::current_q()    : trim( (string) $q );
		$sort  = ( null === $sort ) ? self::current_sort() : ( in_array( $sort, array( 'preis-auf', 'preis-ab', 'neu' ), true ) ? $sort : 'preis-ab' );
		$kat   = ( null === $kat )  ? self::effective_kat( $hub ) : ( in_array( $kat, array( 'rennsport', 'gebraucht', 'alle' ), true ) ? $kat : 'alle' );
		$ids   = self::ordered_ids( $hub, $sort, $q, $kat );
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
		return compact( 'query', 'total', 'pages', 'paged', 'q', 'sort', 'kat', 'hub' );
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
				'kat'  => ( '' !== self::current_kat() ) ? $list['kat'] : null, // nur wenn explizit im URL
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
				'sort'  => array( 'default' => 'preis-ab' ), // Default „teuerste zuerst" (= Server-Render)
				'paged' => array( 'default' => 1 ),
				'kat'   => array( 'default' => 'alle' ),
			),
		) );
	}

	public static function rest_parts( $req ) {
		$hub = sanitize_title( (string) $req['hub'] );
		if ( ! self::post_id( $hub ) ) {
			return new WP_Error( 'm24_bad_hub', 'unknown hub', array( 'status' => 404 ) );
		}
		$kat  = sanitize_key( (string) $req['kat'] );
		$kat  = in_array( $kat, array( 'rennsport', 'gebraucht', 'alle' ), true ) ? $kat : 'alle';
		$list = self::listing( $hub, (string) $req['q'], (string) $req['sort'], (int) $req['paged'], $kat );
		return rest_ensure_response( array(
			'cards' => self::cards_html( $list ),
			'pager' => self::pager_html( $list ),
			'count' => self::count_label( (int) $list['total'], $list['sort'] ),
			'total' => (int) $list['total'],
			'paged' => (int) $list['paged'],
			'pages' => (int) $list['pages'],
			'kat'   => $list['kat'],
		) );
	}

	/** tagDiv-Logo-H1 → div degradieren (nur Header-Ausgabe; genau 1 H1 = Seitentitel). */
	public static function demote_logo_h1( $html ) {
		if ( false === strpos( $html, 'tdb-logo' ) ) { return $html; }
		if ( ! apply_filters( 'm24_hub_demote_logo_h1', true ) ) { return $html; }
		return preg_replace( '#<h1\b([^>]*)>(\s*<(?:span|div|a)\b[^>]*tdb-logo.*?)</h1>#is', '<div$1>$2</div>', $html );
	}

	/** Hub-Key, der diesen Modell-Term-Slug mappt (fuer Archiv-Canonical) — sonst ''. */
	public static function hub_for_term_slug( $slug ) {
		$t = get_term_by( 'slug', $slug, self::TAX );
		if ( ! $t || is_wp_error( $t ) ) { return ''; }
		$tid = (int) $t->term_id;
		foreach ( array_keys( self::registry() ) as $hub ) {
			if ( in_array( $tid, self::term_ids( $hub ), true ) ) { return $hub; }
		}
		return '';
	}

	/** Hub-Konfiguration aus dem CPT (eine Quelle): Texte, Telemetrie, Bilder, Terms, Cross-Links. */
	public static function config( $hub = '' ) {
		$hub = $hub ?: self::current();
		$id  = self::post_id( $hub );
		if ( ! $id ) { return array(); }
		$g     = function ( $k ) use ( $id ) { return get_post_meta( $id, self::META . $k, true ); };
		$cross = $g( 'cross_links' );
		return array(
			'post_id'       => $id,
			'modell'        => (string) $g( 'modell' ),
			'motor'         => (string) $g( 'motor' ),
			'baujahre'      => (string) $g( 'baujahre' ),
			'sub'           => (string) $g( 'sub' ),
			'intro_h2'      => (string) $g( 'intro_h2' ),
			'h1'            => (string) $g( 'h1' ),
			'intro_html'    => (string) $g( 'intro' ),
			'seo_text_html' => (string) $g( 'seo_text' ),
			'seo_title'     => (string) $g( 'seo_title' ),
			'seo_desc'      => (string) $g( 'seo_desc' ),
			'images'        => array_values( array_filter( array_map( 'intval', explode( ',', (string) $g( 'images' ) ) ) ) ),
			'term_ids'      => array_values( array_filter( array_map( 'intval', (array) ( $g( 'terms' ) ?: array() ) ) ) ),
			'cross_links'   => is_array( $cross ) ? $cross : array(),
			'default_kat'   => (string) $g( 'default_kat' ) ?: 'gebraucht',
		);
	}

	public static function url( $hub ) { return home_url( '/' . self::BASE . '/' . $hub . '/' ); }

	/** H1/Title-Muster (Markenrecht: Bestimmungsangabe). Term-Meta-Override gewinnt. */
	public static function h1( $hub = '' ) {
		$c = self::config( $hub );
		if ( ! empty( $c['h1'] ) ) { return $c['h1']; }
		// Neutral (Hubs zeigen neu+gebraucht): „Teile passend für BMW {Modell}".
		return 'Teile passend für BMW ' . ( $c['modell'] ?? '' );
	}

	/** Erste N Teilebilder des Hubs (Featured Images aktiver Teile) — Slideshow-Fallback. */
	public static function part_image_ids( $hub = '', $limit = 8 ) {
		$tids = self::term_ids( $hub );
		if ( empty( $tids ) ) { return array(); }
		$parts = get_posts( array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => 40,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => self::TAX, 'terms' => $tids ) ),
			'meta_query'     => array( array( 'key' => '_m24_status', 'value' => 'aktiv' ) ),
		) );
		$out = array();
		foreach ( $parts as $pid ) {
			$tid = (int) get_post_thumbnail_id( $pid );
			if ( $tid ) { $out[] = $tid; if ( count( $out ) >= $limit ) { break; } }
		}
		return $out;
	}

	/** Slideshow-Bilder: kuratierte Hub-Bilder (CPT) → sonst erste N Teilebilder → [id,url,w,h,alt]. */
	public static function images( $hub = '' ) {
		$c   = self::config( $hub );
		$ids = ! empty( $c['images'] ) ? $c['images'] : array();
		if ( empty( $ids ) ) {
			$ids = self::part_image_ids( $hub, (int) apply_filters( 'm24_hub_slide_fallback_count', 8, $hub ) );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( ! is_array( $src ) || empty( $src[0] ) ) { continue; }
			$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			if ( '' === $alt ) { $alt = 'Teile passend für BMW ' . ( $c['modell'] ?? '' ); }
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
		$intro = ! empty( $c['intro_html'] ) ? trim( wp_strip_all_tags( $c['intro_html'] ) ) : '';
		if ( '' !== $intro ) { return mb_substr( $intro, 0, 158 ); }
		return self::h1() . ' — geprüft, mit Historie, weltweiter Versand bei MOTORSPORT24 seit 2006.';
	}
	public static function seo_robots( $robots ) {
		if ( ! self::is_hub() ) { return $robots; }
		// Entscheidung auf Basis ROHER Request-Parameter (nicht der aufgelösten
		// Defaults — current_sort() liefert sonst 'preis-ab' und kippt die saubere URL).
		$has_param = ( isset( $_GET['q'] ) && '' !== trim( (string) wp_unslash( $_GET['q'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification
			|| isset( $_GET['sort'] )   // phpcs:ignore WordPress.Security.NonceVerification
			|| isset( $_GET['kat'] )    // phpcs:ignore WordPress.Security.NonceVerification
			|| self::current_paged() > 1;
		if ( $has_param ) { return 'noindex, follow'; }
		// Nur freigegebene (überarbeitete) Hubs index,follow. Default: m3-e36 + z4-gt3.
		$allow = (array) apply_filters( 'm24_indexable_hub_slugs', array( 'bmw-m3-e36', 'bmw-z4-gt3' ) );
		return in_array( self::current(), $allow, true ) ? 'index, follow' : 'noindex, follow';
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
