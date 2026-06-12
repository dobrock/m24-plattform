<?php
/**
 * M24 Plattform — Katalog: Übersicht / Archiv (Controller)
 * Modul: catalog-template-archive.php
 *
 * Steuert die virtuellen Archive /gebrauchtteile/ und /rennsport-teile/:
 *  - pre_get_posts: nur aktive Teile des jeweiligen Typs, optional Modell-Filter
 *  - template_include: lädt catalog-archive-view.php
 *  - SEO: noindex bei aktivem Filter-Facet (?m24_modell=), saubere Titles
 *  - Render-Helfer: Karte, Preis, Filterleiste, Raster-Umschalter
 *
 * Logik hier, Markup in der View. Sichtbarkeit Frontend = _m24_status=aktiv.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Archive {

	const POST_TYPE = 'm24_teil';
	const TAXONOMY  = 'm24_fahrzeugkat';
	const PER_PAGE  = 24;

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'pre_get_posts' ) );
		add_action( 'wp', array( __CLASS__, 'fix_status' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'title' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/* ---------- Kontext ---------- */

	public static function is_archive() {
		return (bool) get_query_var( 'm24_teil_archiv' );
	}

	public static function current_typ() {
		return ( 'neu' === get_query_var( 'm24_teil_archiv' ) ) ? 'neu' : 'gebraucht';
	}

	public static function current_prefix() {
		return ( 'neu' === self::current_typ() ) ? 'rennsport-teile' : 'gebrauchtteile';
	}

	public static function heading() {
		return ( 'neu' === self::current_typ() ) ? 'Rennsport-Teile' : 'Gebrauchte Teile';
	}

	public static function current_modell() {
		return sanitize_title( (string) get_query_var( 'm24_modell' ) );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'm24_sort';
		return $vars;
	}

	public static function current_sort() {
		$s = sanitize_key( (string) get_query_var( 'm24_sort' ) );
		return in_array( $s, array( 'neueste', 'preis_auf', 'preis_ab' ), true ) ? $s : 'neueste';
	}

	/* ---------- Query / Status / Template ---------- */

	public static function pre_get_posts( $q ) {
		if ( is_admin() || ! $q->is_main_query() || ! self::is_archive() ) {
			return;
		}
		$q->set( 'post_type', self::POST_TYPE );
		$q->set( 'post_status', 'publish' );
		$q->set( 'posts_per_page', self::PER_PAGE );
		$q->set( 'ignore_sticky_posts', true );

		$sort = self::current_sort();
		if ( 'preis_auf' === $sort || 'preis_ab' === $sort ) {
			$q->set( 'meta_key', '_m24_preis_netto' );
			$q->set( 'orderby', 'meta_value_num' );
			$q->set( 'order', ( 'preis_auf' === $sort ) ? 'ASC' : 'DESC' );
		} else {
			$q->set( 'orderby', 'date' );
			$q->set( 'order', 'DESC' );
		}

		$q->set( 'meta_query', array(
			'relation' => 'AND',
			array( 'key' => '_m24_typ', 'value' => self::current_typ() ),
			array( 'key' => '_m24_status', 'value' => 'aktiv' ),
		) );

		$modell = self::current_modell();
		if ( $modell ) {
			$q->set( 'tax_query', array( array(
				'taxonomy' => self::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $modell,
			) ) );
		}
	}

	/** Leeres Archiv darf nicht 404 werfen. */
	public static function fix_status() {
		if ( is_admin() || ! self::is_archive() ) {
			return;
		}
		global $wp_query;
		$wp_query->is_404    = false;
		$wp_query->is_archive = true;
		status_header( 200 );
	}

	public static function template_include( $template ) {
		if ( ! self::is_archive() ) {
			return $template;
		}
		$view = dirname( __FILE__ ) . '/catalog-archive-view.php';
		return file_exists( $view ) ? $view : $template;
	}

	/* ---------- SEO ---------- */

	public static function robots( $robots ) {
		if ( self::is_archive() && ( self::current_modell() || 'neueste' !== self::current_sort() ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	public static function title( $parts ) {
		if ( self::is_archive() ) {
			$parts['title'] = ( 'neu' === self::current_typ() )
				? 'Rennsport-Teile (Neuteile) für BMW'
				: 'Gebrauchte BMW-Teile';
		}
		return $parts;
	}

	public static function body_class( $classes ) {
		if ( self::is_archive() ) {
			$classes[] = 'm24-archiv-page';
		}
		return $classes;
	}

	/* ---------- Render-Helfer ---------- */

	public static function price_html( $post_id ) {
		// Zentrale Preisquelle (gleiche wie Detailseite): liest _m24_preisoptionen
		// (Default-Option) statt eigene Berechnung. Behebt Archive-Fehlanzeige bei
		// Floats wie 1.470,59 (sanitize_price strippt Dezimalpunkt → ×1000).
		$p = M24_Catalog_Pricing::get( $post_id );
		if ( ! ( $p['brutto'] > 0 ) ) {
			return '<span class="m24-card__price m24-card__price--ask">Preis auf Anfrage</span>';
		}
		$is25a = ( 'paragraf25a' === $p['modus'] );
		$note  = $is25a ? 'inkl. MwSt. (§25a)' : 'inkl. 19&nbsp;% MwSt.';
		return sprintf(
			'<span class="m24-card__price">%s</span><span class="m24-card__pricenote">%s</span>',
			esc_html( $p['brutto_fmt'] ),
			$note
		);
	}

	public static function card_html( $post_id ) {
		$title  = get_the_title( $post_id );
		$artnr  = get_post_meta( $post_id, '_m24_artikelnummer', true );
		$oem    = get_post_meta( $post_id, '_m24_bmw_teilenummer', true );
		$status = get_post_meta( $post_id, '_m24_status', true );

		$thumb = has_post_thumbnail( $post_id )
			? get_the_post_thumbnail( $post_id, 'medium_large', array( 'loading' => 'lazy', 'alt' => esc_attr( $title ) ) )
			: '<span class="m24-card__noimg" aria-hidden="true">MOTORSPORT24</span>';

		$badge = ( 'verkauft' === $status )
			? '<span class="m24-card__badge m24-card__badge--sold">Verkauft</span>'
			: '';

		$meta = array();
		if ( $artnr ) { $meta[] = 'Art.-Nr. ' . esc_html( $artnr ); }
		if ( $oem )   { $meta[] = 'BMW ' . esc_html( $oem ); }
		$meta_html = $meta
			? '<span class="m24-card__meta">' . implode( ' · ', $meta ) . '</span>'
			: '';

		return sprintf(
			'<article class="m24-card"><a class="m24-card__link" href="%1$s"><span class="m24-card__media">%2$s%3$s</span><span class="m24-card__body"><h2 class="m24-card__title">%4$s</h2>%5$s<span class="m24-card__pricewrap">%6$s</span></span></a></article>',
			esc_url( get_permalink( $post_id ) ),
			$thumb,
			$badge,
			esc_html( $title ),
			$meta_html,
			self::price_html( $post_id )
		);
	}

	public static function controls_form() {
		$modell = self::modell_select();
		$sort   = self::sort_select();
		if ( '' === $modell && '' === $sort ) {
			return '';
		}
		$base = home_url( '/' . self::current_prefix() . '/' );
		return '<form class="m24-controls" method="get" action="' . esc_url( $base ) . '">'
			. $modell . $sort
			. '<noscript><button type="submit" class="m24-filter__go">Anwenden</button></noscript>'
			. '</form>';
	}

	public static function modell_select() {
		$terms = get_terms( array(
			'taxonomy'   => self::TAXONOMY,
			'hide_empty' => true,
			'orderby'    => 'name',
		) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		$current = self::current_modell();

		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}
		$roots   = isset( $by_parent[0] ) ? $by_parent[0] : array();
		$options = '';
		foreach ( $roots as $root ) {
			$children = isset( $by_parent[ $root->term_id ] ) ? $by_parent[ $root->term_id ] : array();
			if ( $children ) {
				$options .= '<optgroup label="' . esc_attr( $root->name ) . '">';
				$options .= self::option( $root, $current );
				foreach ( $children as $child ) {
					$options .= self::option( $child, $current );
				}
				$options .= '</optgroup>';
			} else {
				$options .= self::option( $root, $current );
			}
		}

		return '<span class="m24-control">'
			. '<label class="m24-filter__label" for="m24-modell">Modell:</label>'
			. '<select class="m24-filter__select" id="m24-modell" name="m24_modell" onchange="this.form.submit()">'
			. '<option value=""' . selected( $current, '', false ) . '>Alle Modelle</option>'
			. $options
			. '</select></span>';
	}

	public static function sort_select() {
		$sorts = array(
			'neueste'   => 'Zuletzt hinzugefügt',
			'preis_auf' => 'Preis: günstigste zuerst',
			'preis_ab'  => 'Preis: teuerste zuerst',
		);
		$current = self::current_sort();
		$options = '';
		foreach ( $sorts as $val => $label ) {
			$options .= '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return '<span class="m24-control">'
			. '<label class="m24-filter__label" for="m24-sort">Sortieren:</label>'
			. '<select class="m24-filter__select" id="m24-sort" name="m24_sort" onchange="this.form.submit()">'
			. $options
			. '</select></span>';
	}

	private static function option( $term, $current ) {
		return sprintf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $term->slug ),
			selected( $current, $term->slug, false ),
			esc_html( $term->name )
		);
	}

	public static function grid_toggle() {
		$btns = array(
			'list' => 'Liste',
			'2'    => '2',
			'3'    => '3',
			'4'    => '4',
		);
		$out = '<div class="m24-gridswitch" role="group" aria-label="Darstellung">';
		foreach ( $btns as $val => $label ) {
			$out .= sprintf(
				'<button type="button" class="m24-gridswitch__btn" data-grid="%1$s" aria-pressed="false" title="Ansicht: %2$s">%2$s</button>',
				esc_attr( $val ),
				esc_html( $label )
			);
		}
		return $out . '</div>';
	}
}
