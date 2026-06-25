<?php
/**
 * M24 Fahrzeug — CPT + Taxonomie + URL-Rewrite
 * Modul: includes/fahrzeug/class-m24fz-cpt.php
 *
 * CPT m24_fahrzeug (/fahrzeuge/{slug}/), Taxonomie m24_fahrzeug_kat (race-cars,
 * sold-race-cars, classic-cars, sold-classic-cars). Status liegt in Meta _m24fz_status
 * (gelistet|verkauft|reserviert|deaktiviert) — NICHT im WP-Post-Status.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_CPT {

	const PT  = 'm24_fahrzeug';
	const TAX = 'm24_fahrzeug_kat';
	const REWRITE_FLAG = 'm24fz_rewrites_v1';

	/** Status-Quelle (§2): post_status + dieses Meta. Legacy-Spiegel: _m24fz_status. */
	const INSERAT_META = '_m24_inserat_status';     // '' (gelistet) | 'reserviert' | 'verkauft'
	const FIRST_PUB    = '_m24_erstveroeffentlicht'; // einmalig beim ersten publish (§3)

	/** Aktiv-Kategorie → Verkauft-Pendant (Kategorie-Flip bei „Verkauft"). */
	const SOLD_MAP = array( 'race-cars' => 'sold-race-cars', 'classic-cars' => 'sold-classic-cars' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_seed_terms' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_flush' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_status' ) );
		// Erstveröffentlichung einmalig festhalten (§3) — unabhängig von post_date.
		add_action( 'transition_post_status', array( __CLASS__, 'mark_first_publish' ), 10, 3 );
		// Featured → Slider-Term synchron halten (egal über welchen Pfad _m24_featured gesetzt wird).
		add_action( 'added_post_meta', array( __CLASS__, 'on_featured_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_featured_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_featured_meta' ), 10, 3 );
		// Rubrik-WP-Kategorie (racecars/classic-for-sale) nach _m24fz_kat synchron halten + Backfill.
		add_action( 'save_post_' . self::PT, array( __CLASS__, 'sync_rubrik_category' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_rubrik' ) );
		// Robust: bei JEDER Änderung von _m24fz_kat (egal über welchen Pfad) Rubrik-Kategorie nachziehen.
		add_action( 'added_post_meta', array( __CLASS__, 'on_kat_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_kat_meta' ), 10, 3 );
		// CPT-Fahrzeuge IN den tagDiv-„FOR SALE"-Slider einhängen (Query um m24_fahrzeug erweitern).
		add_action( 'pre_get_posts', array( __CLASS__, 'inject_into_rubrik_query' ) );
		// Featured-Slider-Term für bereits markierte CPT nachziehen (einmalig).
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_featured' ) );
		// Reclaim: Alt-Beitrag→CPT-301-Map in die Katalog-Hub-Legacy-Pfade einspeisen + Einmal-Seed.
		add_filter( 'm24_hub_legacy_paths', array( __CLASS__, 'merge_reclaim_paths' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_reclaim_seed' ) );
	}

	/* ── Reclaim: Alt-Beitrag durch CPT-Inserat ablösen (301 + Entwurf) §Reclaim ───── */

	const RECLAIM_MAP = 'm24fz_reclaim_map'; // [ alt-pfad => neuer-pfad ]

	/**
	 * Löst einen Alt-Beitrag (post) durch dieses CPT-Inserat ab: registriert 301 (Alt-URL→CPT-URL)
	 * in der Reclaim-Map und setzt DANN den Alt-Beitrag auf Entwurf (Reihenfolge: kein 404-Fenster).
	 * Läuft nur, wenn der Alt-Beitrag aktuell veröffentlicht ist (Pfad sauber, idempotent).
	 */
	public static function reclaim_old_post( $cpt_id, $old_id ) {
		$cpt_id = (int) $cpt_id; $old_id = (int) $old_id;
		if ( ! $cpt_id || ! $old_id || $cpt_id === $old_id ) { return; }
		$old = get_post( $old_id );
		if ( ! $old || 'post' !== $old->post_type || 'publish' !== $old->post_status ) { return; }

		$old_path = self::path_only( get_permalink( $old_id ) );   // Pretty-URL solange noch publish
		$new_path = self::path_only( get_permalink( $cpt_id ) );
		if ( '' === $old_path || '' === $new_path || $old_path === $new_path ) { return; }

		// Nicht doppeln: in den statischen Katalog-Hub-Legacy-Pfaden bereits vorhanden? → nur Entwurf.
		$static = class_exists( 'M24_Catalog_Hub' ) ? (array) M24_Catalog_Hub::legacy_paths() : array();
		if ( ! array_key_exists( $old_path, $static ) ) {
			$map = (array) get_option( self::RECLAIM_MAP, array() );
			if ( ! isset( $map[ $old_path ] ) || $map[ $old_path ] !== $new_path ) {
				$map[ $old_path ] = $new_path;
				update_option( self::RECLAIM_MAP, $map );
			}
		}
		// Erst nach registriertem 301 → Alt-Beitrag auf Entwurf.
		wp_update_post( array( 'ID' => $old_id, 'post_status' => 'draft' ) );
	}

	/** Reine Pfad-Komponente einer URL, ohne Trailing-Slash (für den Pfadvergleich im Redirect). */
	private static function path_only( $url ) {
		$p = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		return ( '' === $p || '/' === $p ) ? $p : untrailingslashit( $p );
	}

	/** Reclaim-Map in die Katalog-Hub-Legacy-Pfade einspeisen (nutzt deren getesteten 301-Handler). */
	public static function merge_reclaim_paths( $paths ) {
		$map = (array) get_option( self::RECLAIM_MAP, array() );
		foreach ( $map as $old => $new ) {
			if ( is_string( $old ) && is_string( $new ) && ! isset( $paths[ $old ] ) ) { $paths[ $old ] = $new; }
		}
		return $paths;
	}

	/**
	 * Einmal-Seed (§Reclaim „sofort anzuwenden"): Alt-Europameister #26472 auf Entwurf.
	 * 301 ist bereits in M24_Catalog_Hub::legacy_paths() statisch hinterlegt — daher KEIN Map-Eintrag
	 * (kein Doppel-301). 991 R (#27038) ist bereits erledigt. Slug-Guard schützt fremde DB-Kopien.
	 */
	public static function maybe_reclaim_seed() {
		if ( get_option( 'm24fz_reclaim_seed_v1' ) || ! current_user_can( 'manage_options' ) ) { return; }
		$oid = 26472;
		if ( 'post' === get_post_type( $oid ) && 'publish' === get_post_status( $oid )
			&& 'for-sale-bmw-m3-e30-europameister-061-148' === get_post_field( 'post_name', $oid ) ) {
			wp_update_post( array( 'ID' => $oid, 'post_status' => 'draft' ) );
		}
		update_option( 'm24fz_reclaim_seed_v1', 1 );
	}

	/**
	 * Zentraler Accessor: Rubrik-Kategorien eines Fahrzeugs IMMER als Array (Teilmenge von
	 * race-cars|classic-cars). Abwärtskompatibel: alter Einzel-String → [string]. Default race-cars.
	 */
	public static function kats( $post_id ): array {
		$raw = get_post_meta( (int) $post_id, '_m24fz_kat', true );
		$arr = is_array( $raw ) ? $raw : ( '' !== (string) $raw ? array( (string) $raw ) : array() );
		$arr = array_values( array_intersect( array_map( 'strval', $arr ), array( 'race-cars', 'classic-cars' ) ) );
		return $arr ?: array( 'race-cars' );
	}

	/** _m24fz_kat → WP-Kategorie-Slug (filterbar). No-op, wenn der Term nicht existiert (kein Risiko). */
	public static function rubrik_map() {
		return apply_filters( 'm24fz_rubrik_categories', array(
			'race-cars'    => 'race-cars-for-sale',
			'classic-cars' => 'classic-cars-for-sale',
		) );
	}

	/** Fahrzeug der passenden Rubrik-Kategorie zuordnen (damit Rubrik-Seiten/Blöcke die CPT ziehen). */
	public static function sync_rubrik_category( $post_id ) {
		if ( self::$busy || self::PT !== get_post_type( $post_id ) ) { return; }
		$map     = self::rubrik_map();   // kat → for-sale-Slug
		$sold    = self::SOLD_MAP;       // kat → sold-Slug
		$kats    = self::kats( $post_id );
		$is_sold = ( 'verkauft' === self::status( $post_id ) );

		// Alle vier Rubrik-Terme (for-sale + sold) entfernen, dann je gewähltem kat neu setzen.
		$remove = array();
		foreach ( array_merge( array_values( $map ), array_values( $sold ) ) as $s ) {
			$t = get_term_by( 'slug', $s, 'category' );
			if ( $t ) { $remove[] = (int) $t->term_id; }
		}
		if ( $remove ) { wp_remove_object_terms( $post_id, $remove, 'category' ); }

		$add = array();
		foreach ( $kats as $k ) {
			$slug = $is_sold ? ( $sold[ $k ] ?? '' ) : ( $map[ $k ] ?? '' );
			if ( '' === $slug ) { continue; }
			$t = get_term_by( 'slug', $slug, 'category' );
			if ( $t && ! is_wp_error( $t ) ) { $add[] = (int) $t->term_id; }
		}
		if ( $add ) { wp_set_object_terms( $post_id, $add, 'category', true ); }
	}

	/** Einmaliger Backfill der Rubrik-Kategorie für bestehende Fahrzeuge (v2 erzwingt Re-Sync). */
	public static function maybe_backfill_rubrik() {
		if ( get_option( 'm24fz_rubrik_backfill_v2' ) || ! current_user_can( 'edit_posts' ) ) { return; }
		$ids = get_posts( array( 'post_type' => self::PT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $pid ) { self::sync_rubrik_category( $pid ); }
		update_option( 'm24fz_rubrik_backfill_v2', 1 );
	}

	/** _m24fz_kat-Meta-Änderung (jeder Pfad) → Rubrik-Kategorie nachziehen. */
	public static function on_kat_meta( $meta_id, $object_id, $meta_key ) {
		if ( '_m24fz_kat' !== $meta_key || self::PT !== get_post_type( $object_id ) ) { return; }
		self::sync_rubrik_category( (int) $object_id );
	}

	/** Einmalig: Slider-Term („featured") für bestehende Featured-CPT setzen (v2 erzwingt Re-Sync). */
	public static function maybe_backfill_featured() {
		if ( get_option( 'm24fz_featured_backfill_v2' ) || ! current_user_can( 'edit_posts' ) ) { return; }
		$ids = get_posts( array( 'post_type' => self::PT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $pid ) { self::sync_featured_term( (int) $pid ); }
		update_option( 'm24fz_featured_backfill_v2', 1 );
	}

	/* ── CPT-Fahrzeuge in den tagDiv-„FOR SALE"-Slider einhängen §Slider-Integration ── */

	/**
	 * pre_get_posts: Abfragen, die eine Rubrik-Kategorie (race-cars-for-sale / classic-cars-for-sale)
	 * ziehen, um den Post-Typ m24_fahrzeug erweitern — der tagDiv-Slider rendert die CPT dann in
	 * seinem eigenen Layout. Greift NUR bei Default-„post"-Abfragen (keine bereits spezifischen
	 * Post-Type-Queries), Frontend (inkl. AJAX-„load more"), Kategorie verlässlich erkannt.
	 */
	public static function inject_into_rubrik_query( $q ) {
		if ( is_admin() && ! wp_doing_ajax() ) { return; }
		$pt = $q->get( 'post_type' );
		// Leer (= implizit „post") oder explizit „post"/Array-mit-„post" → erweitern; sonst unangetastet.
		if ( ! empty( $pt ) && 'post' !== $pt && ! ( is_array( $pt ) && in_array( 'post', $pt, true ) ) ) { return; }
		if ( ! self::query_targets_rubrik_cat( $q ) ) { return; }
		$q->set( 'post_type', array( 'post', self::PT ) );
	}

	/**
	 * category-Slugs, in deren Abfragen die CPT eingehängt werden: Rubrik-Kategorien +
	 * (falls die Featured-Taxonomie „category" ist) der Startseiten-Slider-Term.
	 */
	private static function inject_category_slugs() {
		$slugs = array_values( self::rubrik_map() ); // race-cars-for-sale, classic-cars-for-sale
		if ( 'category' === self::featured_taxonomy() ) {
			$fs = self::featured_term_slug();
			if ( '' !== $fs ) { $slugs[] = $fs; } // startseite-slider
		}
		return array_values( array_unique( $slugs ) );
	}

	/** Prüft, ob eine WP_Query eine der Ziel-Kategorien anspricht (cat/category_name/__in/tax_query). */
	private static function query_targets_rubrik_cat( $q ) {
		$slugs = self::inject_category_slugs();
		$ids   = array();
		foreach ( $slugs as $s ) { $t = get_term_by( 'slug', $s, 'category' ); if ( $t && ! is_wp_error( $t ) ) { $ids[ (int) $t->term_id ] = $s; } }
		if ( ! $ids ) { return false; }

		$cn = (string) $q->get( 'category_name' );
		if ( '' !== $cn ) { foreach ( $slugs as $s ) { if ( false !== strpos( $cn, $s ) ) { return true; } } }

		$cat = $q->get( 'cat' );
		if ( $cat ) { foreach ( preg_split( '/[\s,+]+/', (string) $cat ) as $c ) { if ( '' !== $c && isset( $ids[ (int) $c ] ) ) { return true; } } }

		foreach ( array( 'category__in', 'category__and' ) as $key ) {
			foreach ( (array) $q->get( $key ) as $c ) { if ( isset( $ids[ (int) $c ] ) ) { return true; } }
		}

		$tq = $q->get( 'tax_query' );
		if ( is_array( $tq ) ) {
			foreach ( $tq as $clause ) {
				if ( is_array( $clause ) && 'category' === ( isset( $clause['taxonomy'] ) ? $clause['taxonomy'] : '' ) ) {
					foreach ( (array) ( isset( $clause['terms'] ) ? $clause['terms'] : array() ) as $term ) {
						if ( isset( $ids[ (int) $term ] ) || in_array( (string) $term, $slugs, true ) ) { return true; }
					}
				}
			}
		}
		return false;
	}

	/** Slider-Taxonomie + Term-Slug (filterbar). Standard: WP-Kategorie „startseite-slider". */
	public static function featured_taxonomy() { return (string) apply_filters( 'm24_featured_taxonomy', 'category' ); }
	public static function featured_term_slug() { return (string) apply_filters( 'm24_featured_term_slug', get_option( 'm24_featured_term_slug', 'featured' ) ); }

	/** Bei Änderung von _m24_featured den Slider-Term zuweisen/entfernen. */
	public static function on_featured_meta( $meta_id, $object_id, $meta_key ) {
		if ( '_m24_featured' !== $meta_key || self::PT !== get_post_type( $object_id ) ) { return; }
		self::sync_featured_term( (int) $object_id );
	}

	/** Featured-Fahrzeug dem Slider-Term zuordnen (Term wird bei Bedarf angelegt), sonst entfernen. */
	public static function sync_featured_term( $post_id ) {
		$tax  = self::featured_taxonomy();
		$slug = self::featured_term_slug();
		if ( ! taxonomy_exists( $tax ) || '' === $slug ) { return; }
		$term = get_term_by( 'slug', $slug, $tax );
		if ( ! $term && self::is_featured( $post_id ) ) {
			$res  = wp_insert_term( 'Startseite-Slider', $tax, array( 'slug' => $slug ) );
			$term = is_wp_error( $res ) ? null : get_term( $res['term_id'], $tax );
		}
		if ( ! $term || is_wp_error( $term ) ) { return; }
		if ( self::is_featured( $post_id ) ) {
			wp_set_object_terms( $post_id, array( (int) $term->term_id ), $tax, true ); // anhängen, andere Terms bleiben
		} else {
			wp_remove_object_terms( $post_id, array( (int) $term->term_id ), $tax );    // nur den Slider-Term entfernen
		}
	}

	public static function register() {
		register_post_type( self::PT, array(
			'labels' => array(
				'name'          => 'Fahrzeuge',
				'singular_name' => 'Fahrzeug',
				'add_new_item'  => 'Neues Fahrzeug',
				'edit_item'     => 'Fahrzeug bearbeiten',
				'menu_name'     => 'Fahrzeuge',
			),
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'show_in_menu' => 'm24-plattform', // §1: unter dem Dach „MOTORSPORT24"
			'menu_icon'    => 'dashicons-car',
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'rewrite'      => array( 'slug' => 'fahrzeuge', 'with_front' => false ),
		) );

		register_taxonomy( self::TAX, self::PT, array(
			'labels'            => array( 'name' => 'Fahrzeug-Kategorien', 'singular_name' => 'Kategorie' ),
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'fahrzeuge-kategorie', 'with_front' => false ),
		) );

		// Standard-Taxonomien (category/post_tag) für den CPT verfügbar machen → Featured-Fahrzeuge
		// können den Slider-Term tragen, den der tagDiv-Block zieht.
		register_taxonomy_for_object_type( 'category', self::PT );
		register_taxonomy_for_object_type( 'post_tag', self::PT );
	}

	/** Pflicht-Terms einmalig anlegen (idempotent). */
	public static function maybe_seed_terms() {
		if ( ! taxonomy_exists( self::TAX ) ) { return; }
		$terms = array(
			'race-cars'         => 'Race Cars for Sale',
			'sold-race-cars'    => 'Sold Race Cars',
			'classic-cars'      => 'Classic Cars for Sale',
			'sold-classic-cars' => 'Sold Classic Cars',
		);
		foreach ( $terms as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX ) ) {
				wp_insert_term( $name, self::TAX, array( 'slug' => $slug ) );
			}
		}
	}

	/** Rewrites einmalig flushen (nach Deploy/Aktivierung). */
	public static function maybe_flush() {
		if ( get_option( self::REWRITE_FLAG ) === self::PT ) { return; }
		self::register();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, self::PT, false );
	}

	/* ── Status-Modell (eine Quelle: post_status + _m24_inserat_status) §2 ─────── */

	/** Kanonischer Status: entwurf|gelistet|reserviert|verkauft|deaktiviert. */
	public static function status( $post_id ) {
		$post_id = (int) $post_id;
		$ps = get_post_status( $post_id );
		if ( in_array( $ps, array( 'draft', 'pending', 'auto-draft' ), true ) ) { return 'entwurf'; }
		if ( 'private' === $ps ) { return 'deaktiviert'; }
		$m = (string) get_post_meta( $post_id, self::INSERAT_META, true );
		if ( 'verkauft' === $m )   { return 'verkauft'; }
		if ( 'reserviert' === $m ) { return 'reserviert'; }
		return 'gelistet';
	}
	public static function is_sold( $post_id )     { return 'verkauft'    === self::status( $post_id ); }
	public static function is_reserved( $post_id ) { return 'reserviert'  === self::status( $post_id ); }
	public static function is_disabled( $post_id ) { return 'deaktiviert' === self::status( $post_id ); }

	/** Rekursionsschutz, damit set_status() im save_post-Kontext nicht erneut speichert. */
	private static $busy = false;
	public static function is_busy() { return self::$busy; }

	/**
	 * Status setzen (eine Quelle). Setzt post_status und/oder _m24_inserat_status konsistent,
	 * hält den Legacy-Spiegel _m24fz_status synchron, flippt bei „Verkauft" die Kategorie.
	 * Erhält post_date (edit_date=true). Sicher außerhalb UND innerhalb save_post (Guard).
	 */
	public static function set_status( $post_id, $new ) {
		$post_id = (int) $post_id;
		if ( ! in_array( $new, array( 'entwurf', 'gelistet', 'reserviert', 'verkauft', 'deaktiviert' ), true ) ) { return; }
		$cur_ps = get_post_status( $post_id );

		$target_ps = null; $ins = null; // null = nicht anfassen
		switch ( $new ) {
			case 'entwurf':     $target_ps = 'draft';   break;
			case 'deaktiviert': $target_ps = 'private'; break; // _m24_inserat_status bleibt erhalten
			case 'verkauft':    $target_ps = 'publish'; $ins = 'verkauft';   break;
			case 'reserviert':  $target_ps = 'publish'; $ins = 'reserviert'; break;
			case 'gelistet':    $target_ps = 'publish'; $ins = '';           break;
		}
		if ( null !== $ins ) {
			if ( '' === $ins ) { delete_post_meta( $post_id, self::INSERAT_META ); }
			else { update_post_meta( $post_id, self::INSERAT_META, $ins ); }
		}
		if ( $target_ps && $cur_ps !== $target_ps ) {
			self::$busy = true;
			wp_update_post( array( 'ID' => $post_id, 'post_status' => $target_ps, 'edit_date' => true ) );
			self::$busy = false;
		}
		// Legacy-Spiegel (similar.php & Co. lesen weiter _m24fz_status).
		update_post_meta( $post_id, '_m24fz_status', $new );
		// Verkauft → Kategorie-Flip (bestehende Logik).
		if ( 'verkauft' === $new ) {
			$kat = (string) get_post_meta( $post_id, '_m24fz_kat', true );
			if ( isset( self::SOLD_MAP[ $kat ] ) ) { wp_set_object_terms( $post_id, self::SOLD_MAP[ $kat ], self::TAX, false ); }
		}
	}

	/** „Wieder aktivieren": private → publish, _m24_inserat_status (verkauft/reserviert/leer) bleibt. */
	public static function reactivate( $post_id ) {
		$post_id = (int) $post_id;
		if ( 'private' !== get_post_status( $post_id ) ) { return; }
		self::$busy = true;
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish', 'edit_date' => true ) );
		self::$busy = false;
		update_post_meta( $post_id, '_m24fz_status', self::status( $post_id ) );
	}

	/** Featured-Fahrzeuge (Startseiten-Slider): veröffentlichte m24_fahrzeug mit _m24_featured=1. */
	public static function featured_ids( $limit = 12 ) {
		return get_posts( array(
			'post_type'      => self::PT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date', 'order' => 'DESC',
			'meta_query'     => array( array( 'key' => '_m24_featured', 'value' => '1' ) ),
		) );
	}
	public static function is_featured( $post_id ) { return '1' === (string) get_post_meta( (int) $post_id, '_m24_featured', true ); }

	/* ── §3: „Online seit" (unveränderlich, von post_date entkoppelt) ─────────── */

	/** Erstveröffentlichung einmalig festhalten (erster Übergang nach publish). */
	public static function mark_first_publish( $new_status, $old_status, $post ) {
		if ( ! $post || self::PT !== $post->post_type ) { return; }
		if ( 'publish' !== $new_status ) { return; }
		if ( get_post_meta( $post->ID, self::FIRST_PUB, true ) ) { return; }
		update_post_meta( $post->ID, self::FIRST_PUB, current_time( 'Y-m-d H:i:s' ) );
	}

	/** Tage online seit Erstveröffentlichung (oder null, wenn nie veröffentlicht). */
	public static function days_online( $post_id ) {
		$first = (string) get_post_meta( (int) $post_id, self::FIRST_PUB, true );
		if ( '' === $first ) { return null; }
		$ts = strtotime( $first );
		if ( ! $ts ) { return null; }
		return max( 0, (int) floor( ( current_time( 'timestamp' ) - $ts ) / DAY_IN_SECONDS ) );
	}

	/** Anzeige-Label „Online seit X Tagen" / Sonderfälle je Status (§3). */
	public static function online_label( $post_id ) {
		$st = self::status( $post_id );
		if ( 'entwurf' === $st )     { return 'Entwurf'; }
		if ( 'deaktiviert' === $st ) { return 'Nicht öffentlich'; }
		if ( 'verkauft' === $st )    {
			$first = self::days_online( $post_id );
			return ( null !== $first ) ? 'Verkauft · war ' . $first . ' Tage online' : 'Verkauft';
		}
		$d = self::days_online( $post_id );
		if ( null === $d ) { return '—'; }
		return 0 === $d ? 'Online seit heute' : ( 1 === $d ? 'Online seit 1 Tag' : 'Online seit ' . $d . ' Tagen' );
	}

	/* ── §2: Einmalige Migration von _m24fz_status → neues Modell ─────────────── */

	public static function maybe_migrate_status() {
		if ( get_option( 'm24fz_status_migrated_v1' ) ) { return; }
		if ( ! current_user_can( 'edit_posts' ) ) { return; }
		$ids = get_posts( array( 'post_type' => self::PT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $pid ) {
			$old = (string) get_post_meta( $pid, '_m24fz_status', true );
			$ps  = get_post_status( $pid );
			if ( 'verkauft' === $old )      { update_post_meta( $pid, self::INSERAT_META, 'verkauft' ); }
			elseif ( 'reserviert' === $old ){ update_post_meta( $pid, self::INSERAT_META, 'reserviert' ); }
			elseif ( 'deaktiviert' === $old ) {
				delete_post_meta( $pid, self::INSERAT_META );
				if ( 'publish' === $ps ) { wp_update_post( array( 'ID' => $pid, 'post_status' => 'private', 'edit_date' => true ) ); }
			} else { // gelistet / leer
				delete_post_meta( $pid, self::INSERAT_META );
			}
			// Erstveröffentlichung nachtragen, wenn schon publish und noch nicht gesetzt.
			if ( 'publish' === get_post_status( $pid ) && ! get_post_meta( $pid, self::FIRST_PUB, true ) ) {
				$d = get_post_field( 'post_date', $pid );
				update_post_meta( $pid, self::FIRST_PUB, $d ?: current_time( 'Y-m-d H:i:s' ) );
			}
		}
		update_option( 'm24fz_status_migrated_v1', 1 );
	}
}
