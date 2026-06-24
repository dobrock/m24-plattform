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

	/** @var string Aktueller Suchbegriff (fuer den posts_where-Filter der Hauptabfrage). */
	private static $search = '';

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
		if ( 'alle' === self::current_kat() ) { return 'Alle Teile'; }
		return ( 'neu' === self::current_typ() ) ? 'Rennsport-Teile' : 'Gebrauchte Teile';
	}

	public static function current_modell() {
		return sanitize_title( (string) get_query_var( 'm24_modell' ) );
	}

	/**
	 * Aktive Kategorie (rennsport|gebraucht|alle). Default = Typ aus dem Pfad. „alle"
	 * (nur via ?kat=alle) hebt den Typ-Filter auf und zeigt Renn- + Gebrauchtteile.
	 */
	public static function current_kat() {
		$k = isset( $_GET['kat'] ) ? sanitize_key( wp_unslash( $_GET['kat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $k, array( 'rennsport', 'gebraucht', 'alle' ), true ) ) { return $k; }
		return ( 'neu' === self::current_typ() ) ? 'rennsport' : 'gebraucht';
	}

	/** Aktueller Suchbegriff aus ?q=. */
	public static function current_q() {
		return isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Counts je Kategorie (aktive Teile) — eine DB-Abfrage fuer den Umschalter.
	 * $modell (Term-Slug) eingeschraenkt: respektiert die aktuelle Modell-Auswahl.
	 */
	public static function kat_counts( $modell = '' ) {
		global $wpdb;
		$join  = "JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_m24_status' AND s.meta_value = 'aktiv'
			JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_m24_typ'";
		if ( '' !== $modell ) {
			$ttids = $wpdb->get_col( $wpdb->prepare(
				"SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} te ON te.term_id = tt.term_id WHERE tt.taxonomy = %s AND te.slug = %s",
				self::TAXONOMY, $modell
			) ); // phpcs:ignore WordPress.DB
			if ( empty( $ttids ) ) { return array( 'rennsport' => 0, 'gebraucht' => 0, 'alle' => 0 ); }
			$in    = implode( ',', array_map( 'intval', $ttids ) );
			$join .= " JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID AND tr.term_taxonomy_id IN ($in)";
		}
		$rows = $wpdb->get_results( "SELECT t.meta_value AS typ, COUNT(DISTINCT p.ID) AS n
			FROM {$wpdb->posts} p $join
			WHERE p.post_type = '" . esc_sql( self::POST_TYPE ) . "' AND p.post_status = 'publish'
			GROUP BY t.meta_value" ); // phpcs:ignore WordPress.DB
		$neu = 0; $geb = 0;
		foreach ( (array) $rows as $r ) {
			if ( 'neu' === $r->typ ) { $neu = (int) $r->n; } elseif ( 'gebraucht' === $r->typ ) { $geb = (int) $r->n; }
		}
		return array( 'rennsport' => $neu, 'gebraucht' => $geb, 'alle' => $neu + $geb );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'm24_sort';
		return $vars;
	}

	public static function current_sort() {
		$s = sanitize_key( (string) get_query_var( 'm24_sort' ) );
		// Default = „teuerste zuerst" (preis_ab); „neueste"/„preis_auf" bleiben explizit wählbar.
		return in_array( $s, array( 'neueste', 'preis_auf', 'preis_ab' ), true ) ? $s : 'preis_ab';
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
			// Robuste Preis-Sortierung (LEFT JOIN): preislose/0-Teile bleiben drin, landen am Ende.
			$q->set( 'm24_price_sort', ( 'preis_auf' === $sort ) ? 'ASC' : 'DESC' );
		} else {
			$q->set( 'orderby', 'date' );
			$q->set( 'order', 'DESC' );
		}

		// Kategorie: rennsport→neu, gebraucht→gebraucht, alle→kein Typ-Filter (beide).
		$kat  = self::current_kat();
		$meta = array( 'relation' => 'AND', array( 'key' => '_m24_status', 'value' => 'aktiv' ) );
		if ( 'alle' !== $kat ) {
			$meta[] = array( 'key' => '_m24_typ', 'value' => ( 'gebraucht' === $kat ) ? 'gebraucht' : 'neu' );
		}
		$q->set( 'meta_query', $meta );

		$modell = self::current_modell();
		if ( $modell ) {
			$q->set( 'tax_query', array( array(
				'taxonomy' => self::TAXONOMY,
				'field'    => 'slug',
				'terms'    => $modell,
			) ) );
		}

		// Suche (?q=): Titel ODER Artikel-/BMW-Nummer/Beschreibung. Eigener posts_where-Filter
		// (kein WP-'s'), strikt auf die Haupt-Archivabfrage begrenzt.
		self::$search = self::current_q();
		if ( '' !== self::$search ) {
			add_filter( 'posts_where', array( __CLASS__, 'where_search' ), 10, 2 );
		}
	}

	/** Such-WHERE NUR fuer die Haupt-Archivabfrage (Titel + Art-Nr/BMW-Nr/Beschreibung). */
	public static function where_search( $where, $query ) {
		if ( ! $query instanceof WP_Query || ! $query->is_main_query() || ! self::is_archive() || '' === self::$search ) {
			return $where;
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( self::$search ) . '%';
		$where .= $wpdb->prepare(
			" AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_m24_artikelnummer','_m24_bmw_teilenummer','_m24_beschreibung_de') AND meta_value LIKE %s ) )",
			$like, $like
		); // phpcs:ignore WordPress.DB
		return $where;
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
		// Filter-/Such-Facetten nicht indexieren (Duplicate-Schutz): Modell, Sortierung,
		// Suche, „alle"-Mischansicht.
		$faceted = self::current_modell() || 'neueste' !== self::current_sort() || '' !== self::current_q() || 'alle' === ( isset( $_GET['kat'] ) ? sanitize_key( wp_unslash( $_GET['kat'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( self::is_archive() && $faceted ) {
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

		// Mehrere Varianten mit UNTERSCHIEDLICHEN Preisen → „ab {min}" (server-seitig).
		$vp  = function_exists( 'm24_variant_price_info' ) ? m24_variant_price_info( $post_id ) : array();
		$ab  = ( ! empty( $vp['hat_varianten'] ) && empty( $vp['alle_gleich'] ) );
		$val = $ab ? $vp['min_fmt'] : $p['brutto_fmt'];
		$pre = $ab ? '<span class="m24-card__ab">ab&nbsp;</span>' : '';

		return sprintf(
			'<span class="m24-card__price">%s%s</span><span class="m24-card__pricenote">%s</span>',
			$pre,
			esc_html( $val ),
			$note
		);
	}

	public static function card_html( $post_id, $with_desc = false ) {
		$title  = get_the_title( $post_id );
		$artnr  = get_post_meta( $post_id, '_m24_artikelnummer', true );
		$oem    = get_post_meta( $post_id, '_m24_bmw_teilenummer', true );
		$status = get_post_meta( $post_id, '_m24_status', true );

		// Bildlos → zentraler Platzhalter als CSS-Background (kein <img> → nicht in Image-Sitemap).
		$noimg = function_exists( 'm24_noimg_placeholder_url' ) ? m24_noimg_placeholder_url() : '';
		$thumb = has_post_thumbnail( $post_id )
			? get_the_post_thumbnail( $post_id, 'medium_large', array( 'loading' => 'lazy', 'alt' => esc_attr( $title ), 'sizes' => '(max-width:560px) 100vw, (max-width:900px) 50vw, 25vw' ) )
			: '<span class="m24-card__noimg m24-card__noimg--ph" aria-hidden="true" style="background-image:url(\'' . esc_url( $noimg ) . '\')"></span>';

		$is_sold = ( 'verkauft' === $status );
		$badge   = $is_sold
			? '<span class="m24-card__badge m24-card__badge--sold">Verkauft</span>'
			: '';

		$meta = array();
		if ( $artnr ) { $meta[] = 'Art.-Nr. ' . esc_html( $artnr ); }
		if ( $oem )   { $meta[] = 'BMW ' . esc_html( $oem ); }
		$meta_html = $meta
			? '<span class="m24-card__meta">' . implode( ' · ', $meta ) . '</span>'
			: '';

		// Verkauft: kein Preisblock (Art.-Nr. rueckt per CSS ans untere Ende).
		$price_block = $is_sold ? '' : '<span class="m24-card__pricewrap">' . self::price_html( $post_id ) . '</span>';

		// Beschreibung NUR fuer die Listenansicht (per CSS auf 2 Zeilen geklemmt). Wird nur
		// gerendert, wenn explizit angefordert (Archiv-Loop) → Hub/Related bleiben unveraendert.
		$desc_html = '';
		if ( $with_desc ) {
			$desc = trim( preg_replace( '/\s+/', ' ', (string) wp_strip_all_tags( (string) get_post_meta( $post_id, '_m24_beschreibung_de', true ) ) ) );
			if ( '' !== $desc ) {
				$desc_html = '<div class="m24-card__desc">' . esc_html( $desc ) . '</div>';
			}
		}

		return sprintf(
			// Kartentitel als div (kein Heading) — H-Struktur sauber (genau 1 H1, Sektionen H2).
			'<article class="m24-card%1$s"><a class="m24-card__link" href="%2$s"><span class="m24-card__media">%3$s%4$s</span><span class="m24-card__body"><div class="m24-card__title">%5$s</div>%6$s%8$s%7$s</span></a></article>',
			$is_sold ? ' m24-card--sold' : '',
			esc_url( get_permalink( $post_id ) ),
			$thumb,
			$badge,
			esc_html( $title ),
			$meta_html,
			$price_block,
			$desc_html
		);
	}

	/**
	 * Toolbar fuer die Typ-Archive — optisch 1:1 zur Modell-Hub-Leiste: dieselben
	 * Klassen (m24hub-controls/search/katsw/viewsw/sortwrap) und dasselbe CSS (in der
	 * Archiv-View unter .m24-archiv gespiegelt). EINE Reihe: Suche · Rennsport/Gebraucht/
	 * Alle · 3/4/Liste · Modell + Sortieren. Server-seitig (Links/GET) → linkbare URLs.
	 */
	public static function toolbar() {
		$kat    = self::current_kat();
		$q      = self::current_q();
		$sort   = self::current_sort();
		$modell = self::current_modell();
		$counts = self::kat_counts( $modell ); // Counts respektieren die Modell-Auswahl
		$base   = home_url( '/' . self::current_prefix() . '/' );
		$label  = ( 'alle' === $kat ) ? 'allen Teilen' : ( ( 'gebraucht' === $kat ) ? 'Gebrauchtteilen' : 'Rennsport-Teilen' );

		// q + Sortierung + Modell in den Umschalter-Links erhalten (linkbar/kopierbar).
		$keep = array();
		if ( '' !== $q ) { $keep['q'] = $q; }
		if ( 'neueste' !== $sort ) { $keep['m24_sort'] = $sort; }
		if ( '' !== $modell ) { $keep['m24_modell'] = $modell; }
		$kats = array(
			'rennsport' => array( 'Rennsport', add_query_arg( $keep, home_url( '/rennsport-teile/' ) ), $counts['rennsport'] ),
			'gebraucht' => array( 'Gebraucht', add_query_arg( $keep, home_url( '/gebrauchtteile/' ) ),  $counts['gebraucht'] ),
			'alle'      => array( 'Alle',      add_query_arg( array_merge( $keep, array( 'kat' => 'alle' ) ), $base ), $counts['alle'] ),
		);

		ob_start();
		?>
		<form class="m24hub-controls" method="get" action="<?php echo esc_url( $base ); ?>" role="search">
			<?php if ( 'alle' === $kat ) : ?><input type="hidden" name="kat" value="alle"><?php endif; ?>
			<div class="m24hub-search">
				<svg class="si" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="16.5" y1="16.5" x2="21" y2="21"></line></svg>
				<input id="m24-atb-q" name="q" type="search" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( 'In ' . $label . ' suchen …' ); ?>" aria-label="Teile durchsuchen">
			</div>
			<div class="m24hub-controls-right">
				<div class="m24hub-katsw" role="group" aria-label="Kategorie wählen">
					<?php foreach ( $kats as $kv => $d ) : ?>
						<a href="<?php echo esc_url( $d[1] ); ?>" data-kat="<?php echo esc_attr( $kv ); ?>" class="<?php echo $kat === $kv ? 'on' : ''; ?>" rel="nofollow"><?php echo esc_html( $d[0] ); ?> <span class="m24hub-katn">(<?php echo esc_html( number_format_i18n( $d[2] ) ); ?>)</span></a>
					<?php endforeach; ?>
				</div>
				<div class="m24hub-viewsw" id="m24-archiv-viewsw" role="group" aria-label="Ansicht wählen">
					<button type="button" data-view="view-3" title="3 Spalten" aria-label="3 Spalten"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="1" y="2" width="3.2" height="12" rx="1"></rect><rect x="6.4" y="2" width="3.2" height="12" rx="1"></rect><rect x="11.8" y="2" width="3.2" height="12" rx="1"></rect></svg>3</button>
					<button type="button" data-view="view-4" class="on" title="4 Spalten" aria-label="4 Spalten"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="0.5" y="2" width="2.6" height="12" rx="0.8"></rect><rect x="4.5" y="2" width="2.6" height="12" rx="0.8"></rect><rect x="8.5" y="2" width="2.6" height="12" rx="0.8"></rect><rect x="12.5" y="2" width="2.6" height="12" rx="0.8"></rect></svg>4</button>
					<button type="button" data-view="view-list" title="Listenansicht" aria-label="Listenansicht"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><rect x="1" y="2.5" width="14" height="2.4" rx="1"></rect><rect x="1" y="6.8" width="14" height="2.4" rx="1"></rect><rect x="1" y="11.1" width="14" height="2.4" rx="1"></rect></svg>Liste</button>
				</div>
				<div class="m24hub-sortwrap">
					<label for="m24-modell">Modell:</label>
					<select id="m24-modell" name="m24_modell" onchange="this.form.submit()"><?php echo self::modell_options( $modell ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
					<label for="m24-sort">Sortieren:</label>
					<select id="m24-sort" name="m24_sort" onchange="this.form.submit()"><?php echo self::sort_options( $sort ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
					<noscript><button type="submit" class="m24hub-resetq">Anwenden</button></noscript>
				</div>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	/** Nur die <option>-Liste fuer den Modell-Select (Optgroups Eltern/Kind). */
	public static function modell_options( $current = '' ) {
		$terms = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => true, 'orderby' => 'name' ) );
		$out   = '<option value=""' . selected( $current, '', false ) . '>Alle Modelle</option>';
		if ( is_wp_error( $terms ) || empty( $terms ) ) { return $out; }
		$by_parent = array();
		foreach ( $terms as $t ) { $by_parent[ (int) $t->parent ][] = $t; }
		$roots = isset( $by_parent[0] ) ? $by_parent[0] : array();
		foreach ( $roots as $root ) {
			$children = isset( $by_parent[ $root->term_id ] ) ? $by_parent[ $root->term_id ] : array();
			if ( $children ) {
				$out .= '<optgroup label="' . esc_attr( $root->name ) . '">' . self::option( $root, $current );
				foreach ( $children as $child ) { $out .= self::option( $child, $current ); }
				$out .= '</optgroup>';
			} else {
				$out .= self::option( $root, $current );
			}
		}
		return $out;
	}

	/** Nur die <option>-Liste fuer den Sortier-Select. */
	public static function sort_options( $current = '' ) {
		$sorts = array(
			'neueste'   => 'Neueste zuerst',
			'preis_auf' => 'Günstigste zuerst',
			'preis_ab'  => 'Teuerste zuerst',
		);
		$out = '';
		foreach ( $sorts as $val => $lbl ) {
			$out .= '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
		}
		return $out;
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
			esc_html( function_exists( 'm24_model_label' ) ? m24_model_label( $term->name ) : $term->name )
		);
	}

	public static function grid_toggle() {
		// „2" entfernt (Vorgabe 0.9.14) — nur noch 3 · 4 · Liste, Default 4 (View-JS).
		$btns = array(
			'3'    => '3',
			'4'    => '4',
			'list' => 'Liste',
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
