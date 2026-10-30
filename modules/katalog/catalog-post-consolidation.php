<?php
/**
 * M24 Plattform — SEO-Konsolidierung alter dünner Übersichts-Beiträge (2016–2019) auf die Modell-Hubs.
 * Modul: modules/katalog/catalog-post-consolidation.php
 *
 * Problem: alte Blog-Beiträge im Stil „Felgen passend für M2 F87" / „Rennsport Zubehör passend für …"
 * sind dünn (kaum Text, oft ohne Bild) und konkurrieren mit den Hubs /teile/{slug}/.
 * Ziel (Daniel): „aus bester SEO-Sicht, dass nichts verloren geht" → 301 auf den passenden Hub,
 * aber NUR wo das Ziel die Signale auch aufnimmt. Es wird NICHTS gelöscht, kein Beitrag verändert.
 *
 * Regeln (laufzeit-generiert, datengetrieben — keine fest verdrahtete Post-Liste):
 *  1) Kandidat = veröffentlichter Beitrag (post) mit Übersichts-Signal (Kategorie-Name „passend für …" /
 *     „Modell-Übersicht" ODER Titel „… passend für …") und < LIST_MAX_WORDS Wörtern.
 *  2) Modell aus Titel + Kategorienamen (Chassis-Codes) → Hub-Slug über die bestehende Hub-Tabelle
 *     (M24_Catalog_Hub::hub_for_term_slug / Registry). Kein eindeutiger Hub → keine Weiterleitung.
 *  3) AUTOMATISCH aktiv nur bei klarem Treffer: Übersichts-KATEGORIE + < auto_max_words (80) + kein
 *     Beitragsbild + genau EIN Hub + Ziel-Hub ist indexierbar (Allowlist m24_indexable_hub_slugs).
 *     Ein 301 auf einen noindex-Hub würde die Linkkraft verschenken → solche Fälle warten auf Freigabe.
 *  4) M2/M4 (F87/F22/F23/G87, F82/F83/G82/G83) sowie M3 F80/G80: bewusst NICHT automatisch.
 *     Grund (catalog-hub.php legacy_paths „NICHT M2/M4", Importer-Kategorie-Map, Sonstige-Hub-Seed):
 *     es gibt dafür keinen eigenen Hub und keine importierten M2/M4-Rennsport-Teile; der Sammel-Hub
 *     „sonstige M-Modelle" zeigt nur Gebrauchtteile → Ziel wäre thematisch schwächer als der Alt-Beitrag
 *     (Soft-404-Risiko). Sobald es einen M2-/M4-Hub gibt: Filter m24_post_consol_model_hubs ergänzen.
 *  5) Fahrzeug-Beiträge (SOLD/FOR SALE/Neuaufbau/Event …, Fahrzeug-Kategorien) werden NIE umgeleitet.
 *  6) Bereits über legacy_paths/legacy_reclaims umgeleitete Pfade werden nicht doppelt behandelt.
 *  7) Daniel kann je Beitrag freigeben (optional mit eigenem Ziel) oder ausschließen — gewinnt immer.
 *
 * Aktive Weiterleitungen fallen aus allen Sitemaps (M24-Native, Yoast, WP-Core, Jetpack); WP-Rocket-Cache
 * der betroffenen URLs wird beim Aktivieren geleert. Kill-Switch: Option (Admin-Seite) oder Konstante
 * M24_POST_CONSOLIDATION=false. Admin-Vorschau ohne Umleitung: ?m24_consol_preview=1 (eingeloggt).
 *
 * Dry-Run-Liste: MOTORSPORT24 → Alt-Beiträge (SEO)   ·   CLI: wp m24 post-consolidation [--status=…]
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Post_Consolidation {

	const OPTION     = 'm24_post_consol';         // Einstellungen + Freigaben/Ausschlüsse
	const MAP_OPTION = 'm24_post_consol_active';  // gecachte aktive Map {sig, map{post_id: path}}
	const SALT       = 'm24_post_consol_salt';
	const PAGE_SLUG  = 'm24-alt-beitraege';
	const CAP        = 'manage_options';
	const LIST_MAX_WORDS = 120;                   // Dry-Run-Liste: „dünn" = unter 120 Wörtern

	/* ── Einstellungen ─────────────────────────────────────────────────── */

	public static function settings(): array {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array(
			'enabled'        => isset( $o['enabled'] ) ? (bool) $o['enabled'] : true,  // Kill-Switch
			'auto'           => isset( $o['auto'] ) ? (bool) $o['auto'] : true,        // klare Treffer automatisch
			'auto_max_words' => isset( $o['auto_max_words'] ) ? max( 0, (int) $o['auto_max_words'] ) : 80,
			'approved'       => isset( $o['approved'] ) && is_array( $o['approved'] ) ? $o['approved'] : array(), // id => Zielpfad
			'excluded'       => isset( $o['excluded'] ) && is_array( $o['excluded'] ) ? $o['excluded'] : array(), // id => 1
		);
	}

	private static function save_settings( array $s ) {
		update_option( self::OPTION, $s, false );
		self::bump();
	}

	public static function enabled(): bool {
		if ( defined( 'M24_POST_CONSOLIDATION' ) && ! M24_POST_CONSOLIDATION ) { return false; }
		return self::settings()['enabled'];
	}

	/* ── Hooks ─────────────────────────────────────────────────────────── */

	public static function init() {
		// Frontend-301 (nach legacy_redirect, gleiche Prio, später registriert).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );

		// Sitemaps: aktive Weiterleitungs-Quellen raus.
		add_filter( 'm24_sitemap_include_post', array( __CLASS__, 'sitemap_include' ), 10, 3 );       // M24-Native
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'yoast_exclude' ) );   // Yoast
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'core_sitemap_args' ), 10, 2 );  // WP-Core
		add_filter( 'jetpack_sitemap_skip_post', array( __CLASS__, 'jetpack_skip' ), 10, 2 );          // Jetpack

		// Cache-Invalidierung (Map wird beim nächsten Bedarf neu berechnet).
		add_action( 'save_post_post', array( __CLASS__, 'bump' ) );
		add_action( 'save_post_m24_modellhub', array( __CLASS__, 'bump' ) );
		add_action( 'deleted_post', array( __CLASS__, 'bump_deleted' ), 10, 2 );
		add_action( 'edited_category', array( __CLASS__, 'bump' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'bump_terms' ), 10, 4 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
			add_action( 'admin_post_m24_consol_settings', array( __CLASS__, 'handle_settings' ) );
			add_action( 'admin_post_m24_consol_row', array( __CLASS__, 'handle_row' ) );
		}
	}

	public static function bump() {
		update_option( self::SALT, (string) ( (int) get_option( self::SALT, '1' ) + 1 ), false );
	}

	public static function bump_deleted( $post_id, $post = null ) {
		$pt = ( $post instanceof WP_Post ) ? $post->post_type : '';
		if ( in_array( $pt, array( 'post', 'm24_modellhub', '' ), true ) ) { self::bump(); }
	}

	/** Nur Kategorie-Zuordnungen von Beiträgen (nicht jede Teile-Taxonomie beim Import). */
	public static function bump_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'category' === $taxonomy ) { self::bump(); }
	}

	/* ── Hub-Auflösung (bestehende Hub-Tabelle) ────────────────────────── */

	/**
	 * Modell-Schlüssel → Regex (auf Titel + Kategorienamen, lowercase). Reihenfolge = Priorität beim Anzeigen.
	 * Filterbar: m24_post_consol_model_patterns.
	 */
	private static function model_patterns(): array {
		return (array) apply_filters( 'm24_post_consol_model_patterns', array(
			'z4-gt3' => '/\bz4[\s\-]*gt3\b|\be89[\s\-]*gt3\b/u',
			'm2'     => '/\bm2\b|\bf87\b|\bg87\b|\bf22\b|\bf23\b/u',
			'm4'     => '/\bm4\b|\bf82\b|\bf83\b|\bg82\b|\bg83\b/u',
			'f80'    => '/\bf80\b|\bg80\b|\bg81\b/u',
			'e30'    => '/\be30\b/u',
			'e36'    => '/\be36\b/u',
			'e46'    => '/\be46\b/u',
			'e9x'    => '/\be9[0-3x]\b/u',
		) );
	}

	/**
	 * Modell-Schlüssel → Modell-Term-Slugs (Hub-Tabelle M24_Catalog_Hub::default_term_ids, alt + nach
	 * strip_brand-Rename). Leeres Array = bewusst KEIN Hub (M2/M4/F80, s. Kopfkommentar Regel 4).
	 * Filterbar: m24_post_consol_model_hubs — z. B. später 'm2' => array( 'm2' ) für einen M2-Hub.
	 */
	private static function model_terms(): array {
		return (array) apply_filters( 'm24_post_consol_model_hubs', array(
			'z4-gt3' => array( 'z4-gt3' ),
			'e30'    => array( 'e30', 'm3-e30' ),
			'e36'    => array( 'e36', 'm3-e36' ),
			'e46'    => array( 'e46', 'm3-e46' ),
			'e9x'    => array( 'e9x', 'm3-e9x' ),
			'm2'     => array(),
			'm4'     => array(),
			'f80'    => array(),
		) );
	}

	/** Modell-Schlüssel → live Hub-Slug ('' = kein Hub). */
	private static function hub_for_model( string $model ): string {
		static $cache = array();
		if ( isset( $cache[ $model ] ) ) { return $cache[ $model ]; }
		$terms = self::model_terms();
		$hub   = '';
		if ( ! empty( $terms[ $model ] ) && class_exists( 'M24_Catalog_Hub' ) ) {
			$reg = M24_Catalog_Hub::registry();
			foreach ( (array) $terms[ $model ] as $ts ) {
				$h = M24_Catalog_Hub::hub_for_term_slug( (string) $ts );
				if ( '' !== $h ) { $hub = $h; break; }
			}
			if ( '' === $hub ) { // Fallback: Hub-Slug direkt (e30 / bmw-m3-e30 …)
				foreach ( (array) $terms[ $model ] as $ts ) {
					foreach ( array( $ts, 'bmw-' . $ts ) as $cand ) {
						if ( isset( $reg[ $cand ] ) ) { $hub = $cand; break 2; }
					}
				}
			}
		}
		return $cache[ $model ] = $hub;
	}

	private static function hub_indexable( string $hub ): bool {
		$allow = (array) apply_filters( 'm24_indexable_hub_slugs', array( 'e36', 'z4-gt3' ) );
		return in_array( $hub, $allow, true );
	}

	/* ── Analyse eines Beitrags ────────────────────────────────────────── */

	/** Wörter im sichtbaren Text (Shortcode-Klammern + HTML raus, Shortcode-Inhalte bleiben). */
	public static function word_count( string $content ): int {
		$t = preg_replace( '/\[\/?[A-Za-z0-9_\-]+[^\]]*\]/', ' ', $content );
		$t = wp_strip_all_tags( (string) $t );
		$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
		return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\-\.\/]*/u', $t );
	}

	private static function overview_cat_regex(): string {
		return (string) apply_filters( 'm24_post_consol_category_regex', '/passend\s+f(?:ü|ue|u)r|modell[\s\-]*(?:ü|ue|u)bersicht|(?:ü|ue)bersicht/iu' );
	}

	private static function overview_title_regex(): string {
		return (string) apply_filters( 'm24_post_consol_title_regex', '/passend\s+f(?:ü|ue|u)r|(?:ü|ue)bersicht|^rennsport[\s\-]*(?:teile|zubeh(?:ö|oe)r)\b/iu' );
	}

	/** Fahrzeug-/Verkaufs-/Event-Beiträge — nie umleiten (Backlinks, eigener Wert). */
	private static function vehicle_title_regex(): string {
		return (string) apply_filters( 'm24_post_consol_vehicle_regex', '/\bsold\b|for\s*sale|verkauft|zu\s+verkaufen|neuaufbau|restaurier|\bevent\b|\brennen\b|nordschleife|hockenheim|\bvln\b|\bmesse\b|treffen/iu' );
	}

	private static function vehicle_cat_slugs(): array {
		$slugs = array( 'classic-cars-for-sale', 'race-cars-for-sale', 'sold-classic-cars', 'racecars-sold' );
		return (array) apply_filters( 'm24_search_fahrzeug_categories', $slugs );
	}

	/** Pfade, die schon über legacy_paths / legacy_reclaims laufen (normalisiert). */
	private static function legacy_sources(): array {
		static $src = null;
		if ( null !== $src ) { return $src; }
		$src = array();
		if ( class_exists( 'M24_Catalog_Hub' ) ) {
			foreach ( array_keys( (array) M24_Catalog_Hub::legacy_paths() ) as $p )    { $src[] = strtolower( trim( (string) $p, '/' ) ); }
			foreach ( array_keys( (array) M24_Catalog_Hub::legacy_reclaims() ) as $p ) { $src[] = strtolower( trim( (string) $p, '/' ) ); }
		}
		return $src = array_values( array_filter( array_unique( $src ) ) );
	}

	private static function norm_path( string $url ): string {
		return strtolower( trim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' ) );
	}

	/**
	 * Vollständige Bewertung eines Beitrags.
	 * status: active-auto | active-approved | candidate | keep | excluded | legacy
	 */
	public static function evaluate( WP_Post $p, ?array $s = null ): array {
		$s       = $s ?? self::settings();
		$id      = (int) $p->ID;
		$title   = html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' );
		$cats    = get_the_category( $id );
		$catn    = array();
		$cats_sl = array();
		foreach ( (array) $cats as $c ) { $catn[] = $c->name; $cats_sl[] = $c->slug; }
		$words   = self::word_count( (string) $p->post_content );
		$thumb   = has_post_thumbnail( $id );
		$c_img   = (bool) preg_match( '/<img\b|\[gallery\b|wp:image|\[vc_single_image/i', (string) $p->post_content );
		$url     = (string) get_permalink( $id );
		$path    = self::norm_path( $url );

		$cat_sig   = (bool) preg_grep( self::overview_cat_regex(), $catn );
		$title_sig = (bool) preg_match( self::overview_title_regex(), $title );
		$vehicle   = (bool) preg_match( self::vehicle_title_regex(), $title ) || (bool) array_intersect( $cats_sl, self::vehicle_cat_slugs() );

		// Modelle erkennen.
		$hay    = mb_strtolower( $title . ' | ' . implode( ' | ', $catn ), 'UTF-8' );
		$models = array();
		foreach ( self::model_patterns() as $key => $rx ) {
			if ( @preg_match( $rx, $hay ) ) { $models[] = $key; }
		}
		$terms   = self::model_terms();
		$blocked = array_values( array_filter( $models, static function ( $m ) use ( $terms ) { return isset( $terms[ $m ] ) && empty( $terms[ $m ] ); } ) );
		$hubs    = array();
		foreach ( $models as $m ) { $h = self::hub_for_model( $m ); if ( '' !== $h ) { $hubs[ $h ] = true; } }
		$hubs = array_keys( $hubs );
		// z4-gt3 + e9x im selben Beitrag (P65/S65-Verwandtschaft): Z4 GT3 gewinnt, sonst mehrdeutig.
		if ( count( $hubs ) > 1 && in_array( 'z4-gt3', $models, true ) ) {
			$z4 = self::hub_for_model( 'z4-gt3' );
			if ( '' !== $z4 ) { $hubs = array( $z4 ); }
		}

		$suggest = ( 1 === count( $hubs ) && empty( $blocked ) ) ? '/' . M24_Catalog_Hub::BASE . '/' . $hubs[0] . '/' : '';
		$target_hub = ( 1 === count( $hubs ) ) ? $hubs[0] : '';

		$r = array(
			'id' => $id, 'title' => $title, 'url' => $url, 'date' => get_the_date( 'Y-m-d', $p ),
			'words' => $words, 'thumb' => $thumb, 'content_img' => $c_img, 'cats' => $catn,
			'cat_sig' => $cat_sig, 'title_sig' => $title_sig, 'vehicle' => $vehicle,
			'models' => $models, 'blocked' => $blocked, 'hubs' => $hubs,
			'suggest' => $suggest, 'target' => '', 'status' => 'keep', 'reason' => '',
			'target_noindex' => ( '' !== $target_hub && ! self::hub_indexable( $target_hub ) ),
		);

		// Reihenfolge: Legacy → Ausschluss → Freigabe → Automatik → Kandidat → behalten.
		foreach ( self::legacy_sources() as $src ) {
			if ( $path === $src || 0 === strpos( $path, $src . '/' ) ) {
				$r['status'] = 'legacy'; $r['reason'] = 'Läuft bereits über die feste Legacy-301-Map.';
				return $r;
			}
		}
		if ( ! empty( $s['excluded'][ $id ] ) ) {
			$r['status'] = 'excluded'; $r['reason'] = 'Von Hand ausgeschlossen — bleibt indexierbar.';
			return $r;
		}
		if ( ! empty( $s['approved'][ $id ] ) ) {
			$r['status'] = 'active-approved'; $r['target'] = (string) $s['approved'][ $id ];
			$r['reason'] = 'Von Hand freigegeben.';
			return $r;
		}
		if ( $vehicle ) {
			$r['reason'] = 'Fahrzeug-/Verkaufs-/Event-Beitrag — bleibt (Backlinks, eigener Wert).';
			return $r;
		}
		if ( ! $cat_sig && ! $title_sig ) {
			$r['reason'] = 'Kein Übersichts-Beitrag (weder Kategorie noch Titel „passend für …").';
			return $r;
		}
		if ( $words >= self::LIST_MAX_WORDS ) {
			$r['reason'] = 'Genug Text (≥ ' . self::LIST_MAX_WORDS . ' Wörter) — bleibt.';
			return $r;
		}
		if ( ! empty( $blocked ) ) {
			$r['reason'] = 'M2/M4/F80: kein eigener Hub (bewusste Regel) — bleibt indexierbar. Nur per Freigabe mit eigenem Ziel.';
			return $r;
		}
		if ( empty( $hubs ) ) {
			$r['status'] = 'candidate'; $r['reason'] = 'Kein Modell/Hub erkannt — Ziel nur per Freigabe.';
			return $r;
		}
		if ( count( $hubs ) > 1 ) {
			$r['status'] = 'candidate'; $r['reason'] = 'Mehrere Modelle (' . implode( ', ', $hubs ) . ') — Ziel bitte wählen.';
			return $r;
		}

		// Ein eindeutiger Hub.
		$why = array();
		if ( ! $cat_sig )                             { $why[] = 'nur Titel-Signal (keine Übersichts-Kategorie)'; }
		if ( $words >= (int) $s['auto_max_words'] )   { $why[] = $words . ' Wörter (Auto-Grenze ' . (int) $s['auto_max_words'] . ')'; }
		if ( $thumb )                                 { $why[] = 'hat Beitragsbild'; }
		if ( $r['target_noindex'] )                   { $why[] = 'Ziel-Hub „' . $target_hub . '" ist noch noindex (301 würde Linkkraft verschenken)'; }
		if ( ! $s['auto'] )                           { $why[] = 'Automatik ausgeschaltet'; }

		if ( empty( $why ) ) {
			$r['status'] = 'active-auto'; $r['target'] = $suggest;
			$r['reason'] = 'Klarer Treffer: Übersichts-Kategorie, < ' . (int) $s['auto_max_words'] . ' Wörter, kein Bild, Hub indexierbar.';
		} else {
			$r['status'] = 'candidate';
			$r['reason'] = 'Wartet auf Freigabe: ' . implode( '; ', $why ) . '.';
		}
		return $r;
	}

	/** Alle veröffentlichten Beiträge bewerten. */
	public static function evaluate_all(): array {
		$s   = self::settings();
		$out = array();
		$ids = get_posts( array(
			'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1,
			'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'date', 'order' => 'ASC',
			'suppress_filters' => true,
		) );
		foreach ( (array) $ids as $id ) {
			$p = get_post( (int) $id );
			if ( $p instanceof WP_Post ) { $out[] = self::evaluate( $p, $s ); }
		}
		return $out;
	}

	/* ── Aktive Map (gecacht) ──────────────────────────────────────────── */

	private static function signature(): string {
		$allow = (array) apply_filters( 'm24_indexable_hub_slugs', array( 'e36', 'z4-gt3' ) );
		$reg   = class_exists( 'M24_Catalog_Hub' ) ? array_keys( M24_Catalog_Hub::registry() ) : array();
		$ver   = defined( 'M24_PLATTFORM_VERSION' ) ? M24_PLATTFORM_VERSION : '';
		return md5( $ver . '|' . get_option( self::SALT, '1' ) . '|' . implode( ',', $allow ) . '|' . implode( ',', $reg ) );
	}

	/** post_id => Zielpfad (nur aktive Weiterleitungen). Leer, wenn Kill-Switch aus. */
	public static function active_map(): array {
		static $mem = null;
		if ( null !== $mem ) { return $mem; }
		if ( ! self::enabled() ) { return $mem = array(); }
		$sig    = self::signature();
		$stored = get_option( self::MAP_OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['sig'], $stored['map'] ) && $stored['sig'] === $sig && is_array( $stored['map'] ) ) {
			return $mem = $stored['map'];
		}
		$map = array();
		foreach ( self::evaluate_all() as $r ) {
			if ( 0 === strpos( $r['status'], 'active-' ) && '' !== $r['target'] ) { $map[ (int) $r['id'] ] = (string) $r['target']; }
		}
		$old = ( is_array( $stored ) && isset( $stored['map'] ) && is_array( $stored['map'] ) ) ? $stored['map'] : array();
		update_option( self::MAP_OPTION, array( 'sig' => $sig, 'map' => $map, 'built' => gmdate( 'c' ) ), true );
		if ( $map !== $old ) { self::after_map_change( $map, $old ); }
		return $mem = $map;
	}

	/** Neue/geänderte/entfernte Weiterleitungen: Seiten-Cache + Sitemap-Cache leeren. */
	private static function after_map_change( array $new, array $old ) {
		$changed = array();
		foreach ( $new as $id => $t ) { if ( ! isset( $old[ $id ] ) || $old[ $id ] !== $t ) { $changed[] = (int) $id; } }
		foreach ( $old as $id => $t ) { if ( ! isset( $new[ $id ] ) ) { $changed[] = (int) $id; } }
		if ( function_exists( 'rocket_clean_post' ) ) {
			foreach ( array_unique( $changed ) as $id ) { rocket_clean_post( $id ); }
		}
		do_action( 'm24_flush_sitemap' );
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'seo', 'Alt-Beitrags-Konsolidierung: Weiterleitungs-Map geändert', array( 'aktiv' => count( $new ), 'geaendert' => array_values( array_unique( $changed ) ) ) );
		}
	}

	/* ── Frontend ──────────────────────────────────────────────────────── */

	public static function maybe_redirect() {
		if ( ! is_singular( 'post' ) || is_preview() ) { return; }
		if ( isset( $_GET['m24_consol_preview'] ) && current_user_can( 'edit_posts' ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		$id  = (int) get_queried_object_id();
		$map = self::active_map();
		if ( ! $id || empty( $map[ $id ] ) ) { return; }
		$target = (string) $map[ $id ];
		// Sicherheitsnetz: Hub-Ziel muss noch existieren (kein 301 ins Leere).
		$base = '/' . M24_Catalog_Hub::BASE . '/';
		if ( 0 === strpos( $target, $base ) ) {
			$slug = trim( substr( $target, strlen( $base ) ), '/' );
			if ( ! M24_Catalog_Hub::post_id( $slug ) ) { return; }
		}
		wp_safe_redirect( home_url( $target ), 301, 'M24 Alt-Beitrags-Konsolidierung' );
		exit;
	}

	public static function sitemap_include( $include, $id, $post_type = '' ) {
		if ( 'post' !== $post_type ) { return $include; }
		return isset( self::active_map()[ (int) $id ] ) ? false : $include;
	}

	public static function yoast_exclude( $ids ) {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), array_map( 'intval', array_keys( self::active_map() ) ) ) ) );
	}

	public static function core_sitemap_args( $args, $post_type ) {
		if ( 'post' !== $post_type ) { return $args; }
		$ex = array_keys( self::active_map() );
		if ( $ex ) { $args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $ex ); }
		return $args;
	}

	public static function jetpack_skip( $skip, $post ) {
		$id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		return ( $id && isset( self::active_map()[ $id ] ) ) ? true : $skip;
	}

	/* ── Admin: Dry-Run-Liste ──────────────────────────────────────────── */

	public static function register_menu() {
		add_submenu_page( 'm24-plattform', 'Alt-Beiträge (SEO)', 'Alt-Beiträge (SEO)', self::CAP, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	private static function back( $msg = '' ) {
		$u = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		if ( isset( $_POST['m24_filter'] ) && '' !== $_POST['m24_filter'] ) { $u = add_query_arg( 'f', sanitize_key( wp_unslash( $_POST['m24_filter'] ) ), $u ); } // phpcs:ignore WordPress.Security.NonceVerification
		return $msg ? add_query_arg( 'm24c', $msg, $u ) : $u;
	}

	/** Ziel säubern: nur interne Pfade (oder volle URL der eigenen Domain) → '/pfad/'. '' = ungültig. */
	public static function sanitize_target( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) { return ''; }
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$h    = wp_parse_url( $raw, PHP_URL_HOST );
		if ( $h && strtolower( (string) $h ) !== strtolower( (string) $host ) && strtolower( (string) $h ) !== 'www.' . strtolower( (string) $host ) && 'www.' . strtolower( (string) $h ) !== strtolower( (string) $host ) ) { return ''; }
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		if ( '' === $path || '/' !== $path[0] || 0 === strpos( $path, '//' ) ) { return ''; }
		$path = preg_replace( '#[^A-Za-z0-9/_\-\.%]#', '', $path );
		return trailingslashit( $path );
	}

	public static function handle_settings() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.' ); }
		check_admin_referer( 'm24_consol_settings' );
		$s = self::settings();
		$s['enabled']        = ! empty( $_POST['enabled'] );
		$s['auto']           = ! empty( $_POST['auto'] );
		$s['auto_max_words'] = isset( $_POST['auto_max_words'] ) ? min( self::LIST_MAX_WORDS, max( 0, (int) $_POST['auto_max_words'] ) ) : 80;
		$was = self::settings()['enabled'];
		self::save_settings( $s );
		// Kill-Switch umgelegt: gecachte Seiten der betroffenen Beiträge + Sitemaps sofort neu.
		if ( $was !== $s['enabled'] ) {
			$stored = get_option( self::MAP_OPTION, array() );
			$ids    = ( is_array( $stored ) && isset( $stored['map'] ) && is_array( $stored['map'] ) ) ? array_keys( $stored['map'] ) : array();
			if ( function_exists( 'rocket_clean_post' ) ) { foreach ( $ids as $pid ) { rocket_clean_post( (int) $pid ); } }
			do_action( 'm24_flush_sitemap' );
		}
		wp_safe_redirect( self::back( 'saved' ) );
		exit;
	}

	public static function handle_row() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.' ); }
		$id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_admin_referer( 'm24_consol_row_' . $id );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$p  = get_post( $id );
		if ( ! $p || 'post' !== $p->post_type ) { wp_safe_redirect( self::back( 'err' ) ); exit; }
		$s = self::settings();
		unset( $s['approved'][ $id ], $s['excluded'][ $id ] );
		$msg = 'reset';
		if ( 'approve' === $op ) {
			$t = self::sanitize_target( isset( $_POST['target'] ) ? (string) wp_unslash( $_POST['target'] ) : '' );
			if ( '' === $t || self::norm_path( $t ) === self::norm_path( (string) get_permalink( $id ) ) ) {
				self::save_settings( $s ); // Zustand trotzdem sauber
				wp_safe_redirect( self::back( 'badtarget' ) ); exit;
			}
			$s['approved'][ $id ] = $t;
			$msg = 'approved';
		} elseif ( 'exclude' === $op ) {
			$s['excluded'][ $id ] = 1;
			$msg = 'excluded';
		}
		self::save_settings( $s );
		wp_safe_redirect( self::back( $msg ) );
		exit;
	}

	private static function status_label( string $st ): array {
		$map = array(
			'active-auto'     => array( 'Leitet um (automatisch)', '#1a7a3c' ),
			'active-approved' => array( 'Leitet um (freigegeben)', '#1a7a3c' ),
			'candidate'       => array( 'Wartet auf Freigabe', '#b87000' ),
			'keep'            => array( 'Bleibt', '#5a6474' ),
			'excluded'        => array( 'Ausgeschlossen', '#5a6474' ),
			'legacy'          => array( 'Legacy-301', '#1f74c4' ),
		);
		return $map[ $st ] ?? array( $st, '#5a6474' );
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.' ); }
		$s    = self::settings();
		$all  = self::evaluate_all();
		$map  = self::active_map();
		// Liste: alles Dünne (< 120 Wörter) + alles mit Übersichts-Signal + alles mit Freigabe/Ausschluss.
		$rows = array_values( array_filter( $all, static function ( $r ) {
			return $r['words'] < self::LIST_MAX_WORDS || $r['cat_sig'] || $r['title_sig'] || 'keep' !== $r['status'];
		} ) );
		$cnt = array( 'active' => 0, 'candidate' => 0, 'keep' => 0 );
		foreach ( $rows as $r ) {
			if ( 0 === strpos( $r['status'], 'active-' ) ) { $cnt['active']++; }
			elseif ( 'candidate' === $r['status'] ) { $cnt['candidate']++; }
			else { $cnt['keep']++; }
		}
		$f = isset( $_GET['f'] ) ? sanitize_key( wp_unslash( $_GET['f'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( $f, array( 'active', 'candidate', 'keep' ), true ) ) {
			$rows = array_values( array_filter( $rows, static function ( $r ) use ( $f ) {
				if ( 'active' === $f ) { return 0 === strpos( $r['status'], 'active-' ); }
				if ( 'candidate' === $f ) { return 'candidate' === $r['status']; }
				return 0 !== strpos( $r['status'], 'active-' ) && 'candidate' !== $r['status'];
			} ) );
		}
		$msg  = isset( $_GET['m24c'] ) ? sanitize_key( wp_unslash( $_GET['m24c'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = array(
			'saved' => 'Einstellungen gespeichert.', 'approved' => 'Freigegeben — Weiterleitung ist aktiv.',
			'excluded' => 'Ausgeschlossen — Beitrag bleibt.', 'reset' => 'Zurückgesetzt auf Automatik.',
			'badtarget' => 'Ungültiges Ziel (nur interne Pfade wie /teile/e36/).', 'err' => 'Beitrag nicht gefunden.',
		);
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$kill = defined( 'M24_POST_CONSOLIDATION' ) && ! M24_POST_CONSOLIDATION;
		?>
		<div class="wrap m24c-wrap">
			<h1>Alt-Beiträge (SEO-Konsolidierung)</h1>
			<style>
				.m24c-wrap .cards{display:flex;gap:12px;margin:14px 0;flex-wrap:wrap}
				.m24c-wrap .card2{background:#fafafa;border:1px solid #e6e9ee;border-radius:10px;padding:12px 18px;min-width:170px;text-decoration:none;color:#3a414c}
				.m24c-wrap .card2 .n{font-size:30px;font-weight:800;line-height:1.1}
				.m24c-wrap .card2.on{border-color:#9a6b25;box-shadow:0 0 0 1px #9a6b25}
				.m24c-wrap table td{vertical-align:top}
				.m24c-wrap .st{font-weight:700}
				.m24c-wrap .small{font-size:12px;color:#5a6474}
				.m24c-wrap input.t{width:210px}
				.m24c-wrap .box{background:#fff;border:1px solid #e6e9ee;border-radius:10px;padding:12px 16px;margin:0 0 14px}
			</style>
			<?php if ( $msg && isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo in_array( $msg, array( 'badtarget', 'err' ), true ) ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $msgs[ $msg ] ); ?></p></div>
			<?php endif; ?>

			<div class="box">
				<p style="margin-top:0">Dünne Übersichts-Beiträge („… passend für …") werden per <strong>301</strong> auf den passenden Modell-Hub
				<code>/teile/…/</code> umgeleitet — <strong>nichts wird gelöscht</strong>. Automatisch nur bei klarem Treffer
				(Übersichts-Kategorie, unter <?php echo (int) $s['auto_max_words']; ?> Wörtern, kein Beitragsbild, Ziel-Hub indexierbar).
				M2/M4 bleiben bewusst stehen (kein eigener Hub). Umgeleitete Beiträge fallen aus allen Sitemaps.
				Original ansehen ohne Umleitung: Beitrag mit <code>?m24_consol_preview=1</code> öffnen.</p>
				<?php if ( $kill ) : ?><p style="color:#b32d2e"><strong>Per Konstante M24_POST_CONSOLIDATION=false abgeschaltet.</strong></p><?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="m24_consol_settings">
					<?php wp_nonce_field( 'm24_consol_settings' ); ?>
					<label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> <strong>Weiterleitungen aktiv</strong> (Kill-Switch — aus = keine einzige Umleitung, Liste bleibt als Vorschau)</label><br>
					<label><input type="checkbox" name="auto" value="1" <?php checked( $s['auto'] ); ?>> Klare Treffer automatisch umleiten (sonst nur Freigegebene)</label><br>
					<label>Auto-Grenze: unter <input type="number" name="auto_max_words" min="0" max="<?php echo (int) self::LIST_MAX_WORDS; ?>" value="<?php echo (int) $s['auto_max_words']; ?>" style="width:70px"> Wörtern</label>
					<p style="margin-bottom:0"><button class="button button-primary">Speichern</button></p>
				</form>
			</div>

			<div class="cards">
				<a class="card2 <?php echo '' === $f ? 'on' : ''; ?>" href="<?php echo esc_url( $base ); ?>"><div class="n"><?php echo (int) ( $cnt['active'] + $cnt['candidate'] + $cnt['keep'] ); ?></div>alle gelisteten</a>
				<a class="card2 <?php echo 'active' === $f ? 'on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'f', 'active', $base ) ); ?>"><div class="n" style="color:#1a7a3c"><?php echo (int) $cnt['active']; ?></div>leiten um<?php echo $s['enabled'] && ! $kill ? '' : ' (wenn aktiv)'; ?></a>
				<a class="card2 <?php echo 'candidate' === $f ? 'on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'f', 'candidate', $base ) ); ?>"><div class="n" style="color:#b87000"><?php echo (int) $cnt['candidate']; ?></div>warten auf Freigabe</a>
				<a class="card2 <?php echo 'keep' === $f ? 'on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'f', 'keep', $base ) ); ?>"><div class="n" style="color:#5a6474"><?php echo (int) $cnt['keep']; ?></div>bleiben</a>
			</div>
			<p class="small">Von <?php echo (int) count( $all ); ?> veröffentlichten Beiträgen gelistet: alle unter <?php echo (int) self::LIST_MAX_WORDS; ?> Wörtern oder mit „passend für"-Signal. Live aktiv: <?php echo (int) count( $map ); ?> Weiterleitungen.</p>

			<table class="widefat striped">
				<thead><tr>
					<th>Beitrag</th><th style="width:60px">Wörter</th><th style="width:60px">Bild</th><th>Kategorien</th>
					<th style="width:90px">Modell</th><th style="width:230px">Status / Grund</th><th style="width:330px">Ziel &amp; Aktion</th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7">Keine Einträge.</td></tr>
				<?php endif; foreach ( $rows as $r ) :
					list( $lbl, $col ) = self::status_label( $r['status'] );
					$tgt = '' !== $r['target'] ? $r['target'] : $r['suggest'];
					?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'm24_consol_preview', '1', $r['url'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r['title'] ); ?></a>
							<div class="small"><?php echo esc_html( $r['date'] ); ?> · <a href="<?php echo esc_url( (string) get_edit_post_link( $r['id'] ) ); ?>">bearbeiten</a> · ID <?php echo (int) $r['id']; ?></div></td>
						<td><?php echo (int) $r['words']; ?></td>
						<td><?php echo $r['thumb'] ? 'Beitrag' : ( $r['content_img'] ? 'im Text' : '—' ); ?></td>
						<td class="small"><?php echo esc_html( implode( ', ', $r['cats'] ) ); ?></td>
						<td class="small"><?php echo esc_html( implode( ', ', $r['models'] ) ?: '—' ); ?></td>
						<td><span class="st" style="color:<?php echo esc_attr( $col ); ?>"><?php echo esc_html( $lbl ); ?></span><div class="small"><?php echo esc_html( $r['reason'] ); ?></div></td>
						<td>
							<?php if ( 'legacy' === $r['status'] ) : ?>
								<span class="small">feste Map in catalog-hub.php</span>
							<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
								<input type="hidden" name="action" value="m24_consol_row">
								<input type="hidden" name="post_id" value="<?php echo (int) $r['id']; ?>">
								<input type="hidden" name="m24_filter" value="<?php echo esc_attr( $f ); ?>">
								<?php wp_nonce_field( 'm24_consol_row_' . (int) $r['id'] ); ?>
								<input class="t" type="text" name="target" value="<?php echo esc_attr( $tgt ); ?>" placeholder="/teile/…/">
								<?php if ( 'active-approved' !== $r['status'] ) : ?><button class="button button-small" name="op" value="approve">Freigeben</button><?php else : ?><button class="button button-small" name="op" value="approve">Ziel ändern</button><?php endif; ?>
								<?php if ( 'excluded' !== $r['status'] ) : ?><button class="button button-small" name="op" value="exclude">Ausschließen</button><?php endif; ?>
								<?php if ( in_array( $r['status'], array( 'active-approved', 'excluded' ), true ) ) : ?><button class="button button-small" name="op" value="reset">Zurücksetzen</button><?php endif; ?>
							</form>
							<?php if ( $r['target_noindex'] ) : ?><div class="small" style="color:#b87000">Ziel-Hub ist noch noindex — erst unter MOTORSPORT24 → Sitemap freigeben.</div><?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * Dry-Run-Liste der Alt-Beitrags-Konsolidierung.
	 * wp m24 post-consolidation [--status=active|candidate|keep|all] [--format=table|csv|json]
	 */
	WP_CLI::add_command( 'm24 post-consolidation', function ( $args, $assoc ) {
		$want = isset( $assoc['status'] ) ? (string) $assoc['status'] : 'all';
		$rows = array();
		foreach ( M24_Post_Consolidation::evaluate_all() as $r ) {
			if ( $r['words'] >= M24_Post_Consolidation::LIST_MAX_WORDS && ! $r['cat_sig'] && ! $r['title_sig'] && 'keep' === $r['status'] ) { continue; }
			$grp = 0 === strpos( $r['status'], 'active-' ) ? 'active' : ( 'candidate' === $r['status'] ? 'candidate' : 'keep' );
			if ( 'all' !== $want && $grp !== $want ) { continue; }
			$rows[] = array(
				'id' => $r['id'], 'status' => $r['status'], 'words' => $r['words'], 'thumb' => $r['thumb'] ? 'y' : 'n',
				'models' => implode( ',', $r['models'] ), 'target' => '' !== $r['target'] ? $r['target'] : $r['suggest'],
				'url' => $r['url'], 'reason' => $r['reason'],
			);
		}
		WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? (string) $assoc['format'] : 'table', $rows, array( 'id', 'status', 'words', 'thumb', 'models', 'target', 'url', 'reason' ) );
	} );
}
