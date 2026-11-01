<?php
/**
 * M24 — Neue Startseite „Design 2b" als Shortcode [m24_startseite_v2] (Vorschau, 31.10.2026)
 * Modul: modules/startseite/startseite-v2.php
 *
 * Wird unter den bestehenden Header + Big-Grid-Slider gesetzt (Vorschau-Seite /startseite-neu/,
 * privat). Noch NICHT die echte Startseite.
 *
 * Abschnitte: Hero „25 Jahre" (Jägermeister-Band) · 4 Kategorie-Karten · Fahrzeug-Slider
 * (verfügbar zuerst, aufgefüllt mit verkauften) · Teile-Umschalter Rennsport/Gebraucht mit
 * Modell-Liste + Zufallsartikeln · Fahrzeugbau (KK Automobile) · Zeitleiste 25 Jahre ·
 * s14.de-Band · Ableger-Hinweis.
 *
 * Nur lesend: m24_fahrzeug (Status über M24FZ_CPT::status) und m24_teil (_m24_status=aktiv,
 * _m24_typ=neu|gebraucht, Modell-Taxonomie m24_fahrzeugkat). Ausgabe gecacht (Transient 15 min).
 *
 * @package m24-plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Startseite_V2 {

	const CACHE_KEY = 'm24_startseite_v2_html_10';

	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) { return; }
		self::$initialized = true;
		add_shortcode( 'm24_startseite_v2', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		// Vorschauseite öffentlich erreichbar, aber nicht indexierbar und nicht in der Sitemap.
		add_action( 'template_redirect', array( __CLASS__, 'noindex_header' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex_meta' ), PHP_INT_MAX );
		add_filter( 'm24_sitemap_exclude_urls', array( __CLASS__, 'sitemap_exclude' ) );
		// tagDiv-Mobil-Theme gibt Composer-Seiten ohne Inhalt aus → auf Mobilgeräten eigenes Mini-Template.
		add_filter( 'template_include', array( __CLASS__, 'mobile_template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_on_preview' ), 20 );
	}

	public static function enqueue_on_preview() {
		if ( self::is_preview() ) {
			wp_enqueue_style( 'm24-startseite-v2' );
			wp_enqueue_script( 'm24-startseite-v2' );
		}
	}

	public static function mobile_template( $template ) {
		if ( self::is_preview() && ( wp_is_mobile() || isset( $_GET['m24_mobil'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification — ?m24_mobil = Testansicht
			$t = __DIR__ . '/startseite-v2-mobile-template.php';
			if ( file_exists( $t ) ) { return $t; }
		}
		return $template;
	}

	/** Seite mit dem Shortcode, die NICHT die Startseite ist (= Vorschau). */
	private static function is_preview() {
		if ( ! is_singular( 'page' ) || is_front_page() ) { return false; }
		$p = get_queried_object();
		return $p && isset( $p->post_content ) && has_shortcode( $p->post_content, 'm24_startseite_v2' );
	}

	public static function noindex_header() {
		if ( self::is_preview() && ! headers_sent() ) { header( 'X-Robots-Tag: noindex, nofollow', true ); }
	}

	public static function noindex_meta( $robots ) {
		if ( self::is_preview() ) {
			unset( $robots['index'], $robots['max-image-preview'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function sitemap_exclude( $urls ) {
		$urls[] = home_url( '/startseite-neu/' );
		return $urls;
	}

	public static function register_assets() {
		if ( is_admin() ) { return; }
		$base = plugin_dir_url( M24_PLATTFORM_FILE );
		wp_register_style( 'm24-startseite-v2', $base . 'assets/css/m24-startseite-v2.css', array(), M24_PLATTFORM_VERSION );
		wp_register_script( 'm24-startseite-v2', $base . 'assets/js/m24-startseite-v2.js', array(), M24_PLATTFORM_VERSION, true );
	}

	/** Bild-URL: verkleinerte Mediathek-Fassung (large), sonst Original. Große Originale lädt Mobile-Safari teils nicht. */
	private static function img( $path, $size = 'large' ) {
		$url = content_url( 'rennsport-teile-bilder/' . $path );
		$id  = attachment_url_to_postid( $url );
		if ( $id ) {
			$sized = wp_get_attachment_image_url( $id, $size );
			if ( $sized ) { return $sized; }
		}
		return $url;
	}

	/** Mobil: einfacher Wisch-Slider mit den Beiträgen der Kategorie 31 (wie der Desktop-Big-Grid). */
	public static function mobile_slider() {
		$ids = get_posts( array(
			'post_type'      => array( 'post', 'm24_fahrzeug' ),
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'cat'            => (int) apply_filters( 'm24_startseite_slider_cat', 31 ), // = Kategorie des Desktop-Big-Grids
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ),
		) );
		if ( ! $ids ) {
			$ids = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 6, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => array( array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ) ) ) );
		}
		if ( ! $ids ) { return ''; }
		$out = '';
		foreach ( $ids as $id ) {
			$out .= sprintf(
				'<a class="m24s-ms__i" href="%1$s">%2$s<span class="m24s-ms__sh"></span><span class="m24s-ms__tx"><span class="m24s-ms__k">MOTORSPORT24</span><b>%3$s</b><span class="m24s-ms__more">Ansehen →</span></span></a>',
				esc_url( get_permalink( $id ) ),
				get_the_post_thumbnail( $id, 'large', array( 'alt' => esc_attr( get_the_title( $id ) ) ) ),
				esc_html( get_the_title( $id ) )
			);
		}
		$dots = str_repeat( '<i></i>', count( $ids ) );
		return '<div class="m24s-ms"><div class="m24s-ms__track">' . $out . '</div><div class="m24s-ms__dots">' . $dots . '</div></div>';
	}

	public static function shortcode() {
		wp_enqueue_style( 'm24-startseite-v2' );
		wp_enqueue_script( 'm24-startseite-v2' );
		$html = get_transient( self::CACHE_KEY );
		if ( false === $html || isset( $_GET['m24_nocache'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$html = self::render();
			set_transient( self::CACHE_KEY, $html, 15 * MINUTE_IN_SECONDS );
		}
		return $html;
	}

	// ── Daten ──────────────────────────────────────────────────────────

	/** Fahrzeuge: verfügbar/reserviert zuerst, dann verkaufte bis max. 10. */
	private static function vehicles() {
		if ( ! post_type_exists( 'm24_fahrzeug' ) ) { return array(); }
		$ids = get_posts( array(
			'post_type'      => 'm24_fahrzeug',
			'post_status'    => 'publish',
			'posts_per_page' => 60,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$avail = array();
		$sold  = array();
		foreach ( $ids as $id ) {
			if ( ! has_post_thumbnail( $id ) ) { continue; }
			if ( ! self::is_bmw( $id ) ) { continue; } // Startseite: nur BMW (keine Fremdmarken)
			$st = class_exists( 'M24FZ_CPT' ) ? M24FZ_CPT::status( $id ) : 'gelistet';
			if ( 'verkauft' === $st ) { $sold[] = array( $id, $st ); }
			elseif ( in_array( $st, array( 'gelistet', 'reserviert' ), true ) ) { $avail[] = array( $id, $st ); }
		}
		$list = array_slice( $avail, 0, 16 ); // Reihenfolge mischt das JS bei jedem Laden
		if ( count( $list ) < 8 ) {
			$list = array_merge( $list, array_slice( $sold, 0, 8 - count( $list ) ) );
		}
		return $list;
	}

	/** BMW-Fahrzeug? Marke-Feld, sonst Titel. */
	private static function is_bmw( $id ) {
		$marke = strtolower( trim( (string) get_post_meta( $id, '_m24fz_marke', true ) ) );
		if ( '' !== $marke ) { return false !== strpos( $marke, 'bmw' ) || false !== strpos( $marke, 'alpina' ); }
		return (bool) preg_match( '/\b(bmw|alpina)\b/i', get_the_title( $id ) );
	}

	/** Term-IDs der Modell-Taxonomie, deren Name den Schlüssel enthält (z. B. „E30"). */
	private static function model_terms( $needle ) {
		$terms = get_terms( array( 'taxonomy' => 'm24_fahrzeugkat', 'hide_empty' => true, 'fields' => 'id=>name' ) );
		if ( is_wp_error( $terms ) ) { return array(); }
		$out = array();
		foreach ( $terms as $id => $name ) {
			if ( false !== stripos( $name, $needle ) ) { $out[] = (int) $id; }
		}
		return $out;
	}

	/** Bis zu $n zufällige aktive Teile eines Typs, optional auf Term-IDs (oder deren Ausschluss) begrenzt. */
	private static function parts( $typ, $terms = null, $exclude = false, $n = 4 ) {
		if ( ! post_type_exists( 'm24_teil' ) ) { return array(); }
		$args = array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => $n,
			'orderby'        => 'rand',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => '_m24_status', 'value' => 'aktiv' ),
				array( 'key' => '_m24_typ', 'value' => $typ ),
				array( 'key' => '_thumbnail_id', 'compare' => 'EXISTS' ),
			),
		);
		if ( is_array( $terms ) ) {
			if ( ! $terms && ! $exclude ) { return array(); }
			if ( $terms ) {
				$args['tax_query'] = array( array(
					'taxonomy' => 'm24_fahrzeugkat',
					'field'    => 'term_id',
					'terms'    => $terms,
					'operator' => $exclude ? 'NOT IN' : 'IN',
				) );
			}
		}
		return get_posts( $args );
	}

	private static function price( $id ) {
		if ( get_post_meta( $id, '_m24_preis_auf_anfrage', true ) || ! class_exists( 'M24_Catalog_Pricing' ) ) { return 'Preis auf Anfrage'; }
		$p = M24_Catalog_Pricing::get( $id );
		if ( ! ( $p['brutto'] > 0 ) ) { return 'Preis auf Anfrage'; }
		$vp = function_exists( 'm24_variant_price_info' ) ? m24_variant_price_info( $id ) : array();
		if ( ! empty( $vp['hat_varianten'] ) && empty( $vp['alle_gleich'] ) ) { return 'ab ' . $vp['min_fmt']; }
		return $p['brutto_fmt'];
	}

	// ── Bausteine ──────────────────────────────────────────────────────

	private static function part_card( $id ) {
		$artnr = get_post_meta( $id, '_m24_artikelnummer', true );
		return sprintf(
			'<a class="m24s-prod" href="%1$s"><span class="m24s-prod__im">%2$s</span><span class="m24s-prod__bd"><b>%3$s</b><small>%4$s</small><span class="m24s-prod__pr">%5$s</span></span></a>',
			esc_url( get_permalink( $id ) ),
			get_the_post_thumbnail( $id, 'medium_large', array( 'loading' => 'lazy', 'alt' => esc_attr( get_the_title( $id ) ) ) ),
			esc_html( wp_trim_words( get_the_title( $id ), 12, '…' ) ),
			$artnr ? 'Art.-Nr. ' . esc_html( $artnr ) : '&nbsp;',
			esc_html( self::price( $id ) )
		);
	}

	/** Modell-Chips + Artikel-Raster eines Teile-Typs (neu|gebraucht). */
	private static function parts_panel( $typ, $active ) {
		$archive = ( 'neu' === $typ ) ? '/rennsport-teile/' : '/gebrauchtteile/';
		$models  = array(
			'e30' => array( 'M3 E30', 'E30', '/teile/bmw-m3-e30/' ),
			'e36' => array( 'M3 E36', 'E36', '/teile/bmw-m3-e36/' ),
			'e46' => array( 'M3 E46', 'E46', '/teile/bmw-m3-e46/' ),
			'e9x' => array( 'M3 E9x', 'E9', '/teile/bmw-m3-e9x/' ),
			'z4'  => array( 'Z4 GT3', 'GT3', '/teile/bmw-z4-gt3/' ),
			'f82' => array( 'M4 F82', 'F82', '/rennsport-teile-passend-fur-f82/' ),
			'f87' => array( 'M2 F87', 'F87', '/rennsport-teile-passend-fur-f87-f22/' ),
		);
		$chips     = '';
		$grids     = '';
		$all_terms = array();
		$first     = true;
		$add = function ( $key, $label, $ids, $more_url, $more_label ) use ( &$chips, &$grids, &$first, $typ ) {
			$k      = $typ . '-' . $key;
			$chips .= sprintf( '<button type="button" class="m24s-opt m24s-opt--chip%1$s" data-model="%2$s">%3$s</button>', $first ? ' is-on' : '', esc_attr( $k ), esc_html( $label ) );
			$grids .= sprintf( '<div class="m24s-grid" data-grid="%1$s"%2$s>%3$s</div>', esc_attr( $k ), $first ? '' : ' hidden', implode( '', array_map( array( __CLASS__, 'part_card' ), $ids ) ) );
			$grids .= sprintf( '<a class="m24s-more" data-grid="%1$s" href="%2$s"%3$s>%4$s →</a>', esc_attr( $k ), esc_url( home_url( $more_url ) ), $first ? '' : ' hidden', esc_html( $more_label ) );
			$first  = false;
		};
		foreach ( $models as $key => $m ) {
			$terms     = self::model_terms( $m[1] );
			$all_terms = array_merge( $all_terms, $terms );
			$ids       = self::parts( $typ, $terms );
			if ( $ids ) { $add( $key, $m[0], $ids, $m[2], 'Alle Teile für ' . $m[0] ); }
		}
		// M3/M4 G8x: Artikel folgen – Chip sichtbar, aber noch nicht klickbar.
		$chips .= '<span class="m24s-opt m24s-opt--chip is-soon" aria-disabled="true" title="Artikel folgen in Kürze">M3/M4 G8x</span>';
		return sprintf(
			'<div class="m24s-parts" data-panel="%1$s"%2$s><div class="m24s-finder__row m24s-finder__row--chips"><span class="m24s-step"><i>2</i>Modell wählen</span><div class="m24s-chips">%3$s</div></div><div class="m24s-parts__res">%4$s</div></div>',
			esc_attr( $typ ),
			$active ? '' : ' hidden',
			$chips,
			$grids
		);
	}

	/** Anzahl aktiver Teile mit Modell-Term „GT3" (Z4 GT3), alle Typen. */
	private static function z4_count() {
		$terms = self::model_terms( 'GT3' );
		if ( ! $terms ) { return 0; }
		$q = new WP_Query( array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_m24_status', 'value' => 'aktiv' ) ),
			'tax_query'      => array( array( 'taxonomy' => 'm24_fahrzeugkat', 'field' => 'term_id', 'terms' => $terms ) ),
		) );
		return (int) $q->found_posts;
	}

	/** Z4-GT3-Kompetenzband (Vorschlag A, 31.10.2026). */
	private static function z4_band() {
		$n      = self::z4_count();
		$bodykit = self::img( '2025/02/BMW-Z4-GT3-Body-Parts-23.jpg', 'large' );
		$det    = array_map( array( __CLASS__, 'img' ), array( '2026/06/BMW-Z4-GT3-Ex-Vita4One-For-Sale-22.webp', '2026/06/BMW-Z4-GT3-Ex-Vita4One-For-Sale-28.webp', '2026/06/BMW-Z4-GT3-Ex-Vita4One-For-Sale-24.webp', '2026/06/BMW-Z4-GT3-Ex-Vita4One-For-Sale-15.webp' ) );
		$pts    = array(
			array( 'Komplettfahrzeuge', 'Aufbau kompletter BMW Z4 GT3 – vom Chassis bis zum rennfertigen Fahrzeug.' ),
			array( 'P65-Motoren', 'Revision und komplette Motoren, Hubraum-Kit 4,4 l, Nockenwellen, Ölpumpen.' ),
			array( 'Getriebe', 'Xtrac-Getriebe revidiert oder komplett – inklusive Ersatzteile.' ),
			array( 'Karosserie & Bodykit', 'Alle Karosserieteile bis zum kompletten Bodykit-Paket ab Lager.' ),
		);
		ob_start();
		?>
<section class="m24s-z4">
	<div class="m24s-w m24s-z4__grid">
		<a class="m24s-z4__pic" href="<?php echo esc_url( home_url( '/bmw-z4-gt3-bodykit-neuteile/' ) ); ?>"><img src="<?php echo esc_url( $bodykit ); ?>" alt="BMW Z4 GT3 Bodykit komplett" decoding="async"><span class="m24s-sh"></span><span class="m24s-tx"><span class="m24s-tx__k">Ab Lager</span><b>Komplettes Z4 GT3 Bodykit</b></span></a>
		<div class="m24s-z4__body">
			<div class="m24s-eb m24s-eb--light">Spezialist · BMW Z4 GT3</div>
			<h2 class="m24s-h2 m24s-h2--light">BMW Z4 GT3 – Teile, Motoren, Getriebe und Fahrzeugbau.</h2>
			<p class="m24s-z4__p">Aus unserem umfangreichen Sortiment an BMW Z4 GT3 Neu- und Gebrauchtteilen liefern wir vom einzelnen Längsträger bis zum kompletten Fahrzeug. Gemeinsam mit KK Automobile – selbst mit dem Z4 GT3 im Rennsport unterwegs – bauen und revidieren wir Fahrzeuge, Motoren und Getriebe.</p>
			<div class="m24s-z4__pts"><?php foreach ( $pts as $i => $p ) : ?><div class="m24s-z4__pt<?php echo $i % 2 ? ' is-blue' : ''; ?>"><b><?php echo esc_html( $p[0] ); ?></b><span><?php echo esc_html( $p[1] ); ?></span></div><?php endforeach; ?></div>
			<div class="m24s-z4__det"><?php foreach ( $det as $d ) : ?><a href="<?php echo esc_url( home_url( '/fahrzeuge/bmw-z4-gt3-ex-bartels-1033/' ) ); ?>"><img src="<?php echo esc_url( $d ); ?>" alt="BMW Z4 GT3 Detail" decoding="async"></a><?php endforeach; ?></div>
			<div class="m24s-z4__cta"><a class="m24s-btn" href="<?php echo esc_url( home_url( '/teile/bmw-z4-gt3/' ) ); ?>"><?php echo $n ? esc_html( 'Alle ' . $n . ' Z4 GT3 Teile ansehen' ) : 'Alle Z4 GT3 Teile ansehen'; ?></a><a class="m24s-btn m24s-btn--dark" href="<?php echo esc_url( home_url( '/kontakt/' ) ); ?>">Z4 GT3 Projekt anfragen</a><span class="m24s-z4__src">Bilder: BMW Z4 GT3 Ex-Bartels #1033</span></div>
		</div>
	</div>
</section>
		<?php
		return ob_get_clean();
	}

	// ── Render ─────────────────────────────────────────────────────────

	private static function render() {
		$J   = self::img( '2024/07/MOTORSPORT24-Rennsport-Teile-Online-Shop.jpg', 'full' );
		$sig = plugin_dir_url( M24_PLATTFORM_FILE ) . 'assets/img/s14-signet.png';
		$im  = array(
			'e30b'   => self::img( '2026/09/BMW-M3-E30-Diamantschwarz-S14B25-07.webp' ),
			'z4'     => self::img( '2026/05/BMW-Z4-GT3-Ex-Vita4One-For-Sale-32.jpg' ),
			'carbon' => self::img( '2024/07/Carbon-Karosserie-Teile-fuer-M3-E30_01.jpg' ),
			's14eng' => self::img( '2025/09/BMW-M3-E30-S14-Engine-For-Sale-24.jpg' ),
			'gtr'    => self::img( '2025/07/BMW-E36-GTR-M3-E46-GTR-M4-GTR-12.jpg' ),
			'dtmeng' => self::img( '2025/10/S14-DTM-1992-12.jpg' ),
			'dtm'    => self::img( '2025/11/BMW-E36-DTM-Tourenwagen-Jaegermeister-57.jpg' ),
			'cab'    => self::img( '2024/03/BMW-320i-E30-Cabrio-Sterlingsilber-For-Sale_8776.jpg' ),
		);
		$cats = array(
			array( self::img( '2026/06/FOR-SALE-BMW-M3-E30-1989-72.webp' ), 'Fahrzeuge', 'Klassische Fahrzeuge', '/classic-cars-for-sale/' ),
			array( $im['z4'], 'Fahrzeuge', 'Rennsport-Fahrzeuge', '/racecars-for-sale/' ),
			array( $im['carbon'], 'Teile · passend für BMW M', 'Rennsport-Teile', '/rennsport-teile/' ),
			array( $im['s14eng'], 'Teile · Original BMW', 'Gebrauchte BMW Teile', '/gebrauchtteile/' ),
		);
		$svc = array(
			array( '01', 'Fahrzeugbau aus einer Hand', 'Komplette Renn- und Clubsportfahrzeuge nach Kundenvorgaben – von der Konzeption bis zur Fertigung.' ),
			array( '02', 'Sport- & Rennmotorenbau', 'Klassischer Motorenbau ist eine unserer Königsdisziplinen – langlebig aufgebaut für jeden Einsatzzweck.' ),
			array( '03', 'Restauration & Neuaufbau', 'Historische Straßen- und Wettbewerbsfahrzeuge – sorgfältig wieder aufgebaut bis zur kompletten Vollrestauration.' ),
			array( '04', 'Saisonbetreuung & Prüfstand', 'Abstimmung auf dem Leistungsprüfstand und auf Wunsch Renn-Team-Betreuung über die gesamte Saison.' ),
		);
		$tl = array(
			array( '2001', 's14motorsport.de', 'Gründung von s14motorsport.de – der Ursprung, aus dem später MOTORSPORT24 und s14.de entstanden sind.' ),
			array( '2006', 'MOTORSPORT24', 'Exklusiver Vertrieb der Rennsport-Teile von KK Automobile – für anspruchsvolle Kunden in aller Welt.' ),
			array( 'Fahrzeugbau', 'BMW M · Gruppe A · DTM · GTR', 'Aufbau und Restauration historischer Renn- und Straßenfahrzeuge – von der Konzeption bis zur Fertigung.' ),
			array( 'Weltweit', 'Verkauf & Versand', 'Teile und Fahrzeuge für Kunden weltweit – als Komplettlösung, im geschlossenen Trailer oder per Container.' ),
			array( '2026', 's14.de', 'Gründungspartner von s14.de, dem Marktplatz für die klassischen BMW E-Baureihen von 1966 bis 2009.' ),
		);
		$feats = array(
			array( 'Marktplatz', 'Fahrzeuge und Teile von geprüften Händlern und privaten Liebhabern – ausschließlich klassische BMW.' ),
			array( 'Archiv', 'Wissen zu Modellen, Baureihen und Ausstattungen der BMW E‑Baureihen.' ),
			array( 'Magazin', 'Fahrzeugporträts, Geschichten und Neuigkeiten aus der Szene.' ),
		);
		$fam = array(
			array( '2001', 's14motorsport.de', 'Der Ursprung' ),
			array( '2006', 'MOTORSPORT24', 'Fahrzeuge & Rennsport-Teile' ),
			array( '2026', 's14.de', 'Marktplatz & Archiv' ),
		);

		ob_start();
		?>
<div class="m24s" id="m24-startseite">
<section class="m24s-hero" style="--m24s-bg:url('<?php echo esc_url( $J ); ?>')">
	<div class="m24s-hero__in">
		<div class="m24s-eb m24s-eb--light">Seit 2001 · s14motorsport.de · MOTORSPORT24</div>
		<div class="m24s-hero__25 m24s-gold">25</div>
		<h2 class="m24s-hero__h">Jahre Erfahrung in BMW Rennsport &amp; Klassik</h2>
		<p class="m24s-hero__lead">Klassische BMW, Rennfahrzeuge und Rennsport-Teile passend für BMW M Modelle – international seit 2006.</p>
		<div class="m24s-hero__btns"><a class="m24s-btn m24s-btn--hero" href="#m24s-angebot">Fahrzeuge ansehen</a><a class="m24s-btn m24s-btn--glass m24s-btn--hero" href="#m24s-teile">Teile finden</a></div>
	</div>
</section>
<div class="m24s-w m24s-cats">
	<?php foreach ( $cats as $c ) : ?>
	<a class="m24s-ph m24s-cat<?php echo 'Rennsport-Fahrzeuge' === $c[2] ? ' m24s-cat--zoom' : ''; ?>" href="<?php echo esc_url( home_url( $c[3] ) ); ?>"><img src="<?php echo esc_url( $c[0] ); ?>" alt="<?php echo esc_attr( $c[2] ); ?>" decoding="async"><span class="m24s-sh"></span><span class="m24s-tx"><span class="m24s-tx__k"><?php echo esc_html( $c[1] ); ?></span><b><?php echo esc_html( $c[2] ); ?></b></span></a>
	<?php endforeach; ?>
</div>

<section class="m24s-dark m24s-slider" id="m24s-angebot">
	<div class="m24s-w">
		<div class="m24s-head"><div><div class="m24s-eb m24s-eb--light">Aktuell im Angebot</div><h2 class="m24s-h2 m24s-h2--light">Klassische BMW, Rennfahrzeuge &amp; Sportwagen</h2></div>
			<div class="m24s-arrows"><button type="button" class="m24s-arr" data-dir="-1" aria-label="Zurück">←</button><button type="button" class="m24s-arr m24s-arr--gold" data-dir="1" aria-label="Weiter">→</button></div></div>
		<div class="m24s-track">
			<?php foreach ( self::vehicles() as $v ) :
				list( $vid, $st ) = $v;
				$ps  = get_post_meta( $vid, '_m24fz_leistung_ps', true );
				$sub = array_filter( array( get_post_meta( $vid, '_m24fz_baujahr', true ), $ps ? $ps . ' PS' : '' ) );
				$tag = ( 'verkauft' === $st ) ? array( 'sold', 'Verkauft' ) : ( ( 'reserviert' === $st ) ? array( 'res', 'Reserviert' ) : array( '', 'Verfügbar' ) );
				?>
			<a class="m24s-car<?php echo 'verkauft' === $st ? ' is-sold' : ''; ?>" href="<?php echo esc_url( get_permalink( $vid ) ); ?>"><span class="m24s-car__im"><?php echo get_the_post_thumbnail( $vid, 'large', array( 'alt' => esc_attr( get_the_title( $vid ) ) ) ); ?><span class="m24s-tag <?php echo esc_attr( $tag[0] ); ?>"><?php echo esc_html( $tag[1] ); ?></span></span><b><?php echo esc_html( get_the_title( $vid ) ); ?></b><span class="m24s-car__sub"><?php echo esc_html( implode( ' · ', $sub ) ); ?></span></a>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="m24s-sec" id="m24s-teile">
	<div class="m24s-w">
		<div class="m24s-intro"><div class="m24s-eb">Teile</div><h2 class="m24s-h2">Rennsport-Teile &amp; gebrauchte BMW M Teile</h2>
			<p class="m24s-lead">Neue Rennsport-Teile von KK Automobile und gebrauchte Original-Teile – wählen Sie die Teile-Art und Ihr Modell, wir zeigen Ihnen passende Artikel aus unserem Katalog.</p></div>
		<div class="m24s-finder m24s-card">
			<div class="m24s-finder__row"><span class="m24s-step"><i>1</i>Teile-Art wählen</span>
				<div class="m24s-types" role="tablist">
					<button type="button" class="m24s-opt m24s-opt--type is-on" data-tab="neu"><b>Rennsport-Teile</b><span>neu · passend für BMW M</span></button>
					<button type="button" class="m24s-opt m24s-opt--type" data-tab="gebraucht"><b>Gebrauchte BMW M Teile</b><span>Original-Teile · gebraucht</span></button>
				</div>
			</div>
			<?php echo self::parts_panel( 'neu', true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo self::parts_panel( 'gebraucht', false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
	</div>
</section>

<?php echo self::z4_band(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

<section class="m24s-sec">
	<div class="m24s-w">
		<div class="m24s-intro m24s-intro--left"><div class="m24s-eb">Fahrzeugbau · Motorenbau · Restauration</div><h2 class="m24s-h2">Fahrzeugbau, Motorenbau &amp; Restauration.</h2></div>
		<div class="m24s-bau">
		<div class="m24s-bau__img">
			<div class="m24s-ph m24s-ph--wide"><img src="<?php echo esc_url( $im['gtr'] ); ?>" alt="Fahrzeugbau" decoding="async"><span class="m24s-sh"></span><span class="m24s-tx"><b>Fahrzeugbau</b><span>BMW M · Gruppe A · DTM · GTR</span></span></div>
			<div class="m24s-ph"><img src="<?php echo esc_url( $im['dtmeng'] ); ?>" alt="Motorenbau" decoding="async"><span class="m24s-sh"></span><span class="m24s-tx"><b class="m24s-sm">Motorenbau</b></span></div>
			<div class="m24s-ph"><img src="<?php echo esc_url( $im['dtm'] ); ?>" alt="Restauration" decoding="async"><span class="m24s-sh"></span><span class="m24s-tx"><b class="m24s-sm">Restauration</b></span></div>
		</div>
		<div class="m24s-svc"><?php foreach ( $svc as $s ) : ?><div class="m24s-card m24s-svc__i"><span class="m24s-gold m24s-svc__n"><?php echo esc_html( $s[0] ); ?></span><b><?php echo esc_html( $s[1] ); ?></b><p><?php echo esc_html( $s[2] ); ?></p></div><?php endforeach; ?></div>
		</div>
		<div class="m24s-bau__foot">
			<p class="m24s-kk">Ausgeführt von unserem langjährigen Partnerbetrieb <b>KK Automobile</b> in Kalefeld – MOTORSPORT24 ist Ihr Ansprechpartner für Beratung, Angebot und Abwicklung.</p>
			<a class="m24s-btn m24s-btn--dark" href="<?php echo esc_url( home_url( '/kontakt/' ) ); ?>">Projekt anfragen</a>
		</div>
	</div>
</section>

<section class="m24s-tl">
	<div class="m24s-tl__bg25" aria-hidden="true">25</div>
	<div class="m24s-w m24s-tl__grid">
		<div><div class="m24s-gold m24s-tl__25">25</div><div class="m24s-eb">2001 – 2026</div><h2 class="m24s-h2">Jahre Erfahrung</h2><p class="m24s-lead">Seit 2001 setzen wir auf langfristige Zusammenarbeit – mit Kunden, Partnern und Fahrzeugen, die wir über viele Jahre begleiten. Vertrauen, das man nachprüfen kann.</p></div>
		<div class="m24s-tl__list"><?php foreach ( $tl as $t ) : ?><div class="m24s-tl__i"><span class="m24s-tl__dot"></span><div class="m24s-tl__card"><b class="m24s-tl__y"><?php echo esc_html( $t[0] ); ?></b><div><b><?php echo esc_html( $t[1] ); ?></b><p><?php echo esc_html( $t[2] ); ?></p></div></div></div><?php endforeach; ?></div>
	</div>
</section>

<section class="m24s-s14" style="--m24s-s14bg:url('<?php echo esc_url( $im['cab'] ); ?>')">
	<div class="m24s-w m24s-s14__grid">
		<div>
			<a class="m24s-s14logo" href="https://s14.de/" target="_blank" rel="noopener" aria-label="s14.de"><img src="<?php echo esc_url( $sig ); ?>" alt="" width="53" height="53"><span class="m24s-s14logo__wm">s14<span class="m24s-s14logo__dot"></span><span class="m24s-s14logo__tld">de</span></span><span class="m24s-s14logo__sep"></span><span class="m24s-s14logo__tag">Marktplatz &amp; Archiv<br>für klassische BMW</span></a>
			<div class="m24s-eb m24s-eb--light">MOTORSPORT24 ist Gründungspartner</div>
			<h2 class="m24s-h2 m24s-h2--light">Der Marktplatz für klassische BMW E‑Baureihen.</h2>
			<p class="m24s-s14__p">s14.de ist Archiv, Magazin und Marktplatz für die klassischen BMW E‑Baureihen von 1966 bis 2009 – vom 02er über E9, E21, E30 und E28 bis zum E46. Händler und Privatverkäufer bieten hier Fahrzeuge und Teile an, gelesen und gekauft von Menschen, die diese Autos wirklich kennen.</p>
			<a class="m24s-btn" href="https://s14.de/" target="_blank" rel="noopener">s14.de entdecken</a>
		</div>
		<div class="m24s-s14__feats"><?php foreach ( $feats as $i => $f ) : ?><div class="m24s-s14__f"><span>0<?php echo (int) ( $i + 1 ); ?></span><div><b><?php echo esc_html( $f[0] ); ?></b><p><?php echo esc_html( $f[1] ); ?></p></div></div><?php endforeach; ?></div>
	</div>
</section>

<section class="m24s-fam">
	<div class="m24s-w">
		<div class="m24s-intro"><div class="m24s-eb">Eine Familie seit 2001</div><h2 class="m24s-h2">Aus s14motorsport.de entstanden.</h2><p class="m24s-lead">MOTORSPORT24 und s14.de sind Ableger von s14motorsport.de – gegründet 2001, mit derselben Leidenschaft für BMW Rennsport und klassische BMW.</p></div>
		<div class="m24s-fam__row"><?php foreach ( $fam as $f ) : ?><div class="m24s-fam__i"><span class="m24s-fam__y"><?php echo esc_html( $f[0] ); ?></span><b><?php echo esc_html( $f[1] ); ?></b><span><?php echo esc_html( $f[2] ); ?></span></div><?php endforeach; ?></div>
	</div>
</section>
</div>
		<?php
		return ob_get_clean();
	}
}
