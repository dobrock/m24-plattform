<?php
/**
 * M24 Plattform — „Meine Garage", Etappe 1: kontogebundener Warenkorb.
 *
 * Baut die bestehende „Meine Garage" (M24_Garage = E-Mail/DOI-Merkliste, ohne Menge) NICHT um,
 * sondern ergänzt sie um einen echten, PRO ACCOUNT persistenten Warenkorb mit Menge:
 *
 *  - Account-Auflösung über den BESTEHENDEN Login (B2B-Magic-Link setzt wp_set_auth_cookie →
 *    jeder eingeloggte WP-User, inkl. Admin, ist der aktuelle Account). Helper: current_account_id().
 *  - Saubere, generische Tabelle m24_garage_cart (account_id + post_type + post_id + qty) → später
 *    können auch Fahrzeuge rein, und Etappe 2/3 (Versenden-Link, PDF) lesen Positionen pro Account.
 *  - REST (nonce + Login): add (Re-Add erhöht Menge), remove, qty (absolut setzen), get.
 *  - Garage-Seite via Shortcode [m24_garage]: Bild, Titel, Artikelnummer, Einzelpreis, Menge ±,
 *    Positionssumme, Gesamtsumme, Leerzustand. Preise = öffentliche Brutto-Logik der Teile-Detailseite.
 *  - Schwebe-/Header-Zähler: live die Positionsanzahl ([data-m24-garage-count]).
 *
 * NICHT in Etappe 1: Versenden-Link (Etappe 2) + PDF (Etappe 3) — Datenmodell dockt darüber sauber an.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Garage_Cart {

	const NS            = 'm24/v1';
	const SCHEMA_OPTION = 'm24_garage_cart_schema_v';
	const SCHEMA_VER    = '1';
	const PAGE_OPTION   = 'm24_garage_page_id';
	const PAGE_SLUG     = 'meine-garage';
	const MAX_QTY       = 99;

	// Etappe 2: Garage-Link teilen. Ein aktueller Token je Account (usermeta); rotieren = alter Link tot.
	const SHARE_QUERY   = 'm24garage_share';        // öffentliche Read-only-Ansicht: /meine-garage/?m24garage_share=TOKEN
	const META_TOKEN    = 'm24_garage_share_token'; // usermeta: aktueller Roh-Token (Vorhandensein = aktiv)
	const META_CREATED  = 'm24_garage_share_created';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_table' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_page' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_counter' ) );
		// Share-Ansicht = Capability-URL → hart noindex,nofollow (Meta würde von Yoast überstimmt).
		add_filter( 'wp_robots', array( __CLASS__, 'force_robots' ) );
		add_filter( 'wpseo_robots', array( __CLASS__, 'yoast_robots_str' ), 99 );
		add_filter( 'wpseo_robots_array', array( __CLASS__, 'yoast_robots_arr' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_robots_header' ), 0 );
		// Social-Preview NUR auf der geteilten Read-only-Ansicht: Head puffern (vor Yoast prio 1),
		// konkurrierende og:/twitter:-Tags entfernen, eigenen Satz anhängen (keine Dubletten).
		add_action( 'wp_head', array( __CLASS__, 'og_buffer_start' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'og_buffer_end' ), 9 );
		// robots-Meta gibt tagDiv DIREKT im Header-Template aus (vor wp_head) → nur ein Vollseiten-Puffer
		// erreicht sie. Auf template_redirect (vor Theme-Header) starten, Callback ersetzt sie global.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start_page_buffer' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'hide_theme_title' ), 99 );
	}

	public static function table() { return M24_Database::table( 'garage_cart' ); }

	/** Generische, unterstützte Positionstypen (post_type). Fahrzeuge sind bewusst schon erlaubt. */
	public static function allowed_types() { return array( 'm24_teil', 'm24_fahrzeug' ); }

	/**
	 * Aktueller Account = eingeloggter WP-User (B2B-Login setzt die WP-Auth-Cookie, Admin zählt mit).
	 * 0 = nicht eingeloggt (Gast).
	 */
	public static function current_account_id(): int {
		return is_user_logged_in() ? (int) get_current_user_id() : 0;
	}

	/* ── Schema (idempotent) ─────────────────────────────────────────────── */

	public static function maybe_ensure_table() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VER ) {
			return;
		}
		self::ensure_table();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VER );
	}

	public static function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		$t  = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			post_type VARCHAR(20) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			qty INT UNSIGNED NOT NULL DEFAULT 1,
			added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_pos (account_id, post_type, post_id),
			KEY idx_account (account_id)
		) {$cc};" );
	}

	/* ── Garage-Seite automatisch bereitstellen (stabile URL für den Zähler) ── */

	public static function maybe_create_page() {
		if ( get_option( self::PAGE_OPTION ) ) {
			return;
		}
		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing ) {
			update_option( self::PAGE_OPTION, (int) $existing->ID );
			return;
		}
		$pid = wp_insert_post( array(
			'post_title'   => 'Meine Garage',
			'post_name'    => self::PAGE_SLUG,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[m24_garage]',
		) );
		if ( $pid && ! is_wp_error( $pid ) ) {
			update_option( self::PAGE_OPTION, (int) $pid );
		}
	}

	public static function page_url(): string {
		$pid = (int) get_option( self::PAGE_OPTION );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return (string) get_permalink( $pid );
		}
		$p = get_page_by_path( self::PAGE_SLUG );
		if ( $p ) {
			return (string) get_permalink( $p );
		}
		return home_url( '/' );
	}

	/* ── Etappe 2: Share-Token (ein aktueller Token je Account, in usermeta) ── */

	private static function new_token(): string {
		return function_exists( 'random_bytes' ) ? bin2hex( random_bytes( 32 ) ) : wp_generate_password( 64, false, false );
	}

	/** Vorhandenen Token lesen (kein Erzeugen). '' = noch keiner / widerrufen. */
	public static function share_token_existing( int $acc ): string {
		return $acc > 0 ? (string) get_user_meta( $acc, self::META_TOKEN, true ) : '';
	}

	/** Token holen oder (falls keiner) erzeugen. */
	public static function share_token_get_or_create( int $acc ): string {
		$tok = self::share_token_existing( $acc );
		return ( '' !== $tok ) ? $tok : self::share_token_rotate( $acc );
	}

	/** Neuen Token erzeugen → alter Token wird ungültig (zurückziehen + neu). */
	public static function share_token_rotate( int $acc ): string {
		$tok = self::new_token();
		update_user_meta( $acc, self::META_TOKEN, $tok );
		update_user_meta( $acc, self::META_CREATED, current_time( 'mysql' ) );
		return $tok;
	}

	/** Token widerrufen (Link tot, kein neuer). */
	public static function share_token_revoke( int $acc ): void {
		delete_user_meta( $acc, self::META_TOKEN );
		delete_user_meta( $acc, self::META_CREATED );
	}

	/** Token → account_id (0 = ungültig/widerrufen). */
	public static function resolve_share_token( string $token ): int {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );
		if ( strlen( $token ) < 32 ) { return 0; }
		global $wpdb;
		$uid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			self::META_TOKEN, $token
		) );
		return $uid > 0 ? $uid : 0;
	}

	public static function share_url( string $token ): string {
		return add_query_arg( self::SHARE_QUERY, $token, self::page_url() );
	}

	/* ── REST ────────────────────────────────────────────────────────────── */

	public static function register_routes() {
		register_rest_route( self::NS, '/garage/cart', array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_get' ),
		) );
		register_rest_route( self::NS, '/garage/cart/add', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_add' ),
		) );
		register_rest_route( self::NS, '/garage/cart/remove', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_remove' ),
		) );
		register_rest_route( self::NS, '/garage/cart/qty', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_qty' ),
		) );
		// Etappe 2: Share-Link erzeugen/rotieren/widerrufen (nonce + Login).
		register_rest_route( self::NS, '/garage/share', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_share' ),
		) );
		// Etappe 4: Garage als Anfrage an die bestehende Sammelanfrage-Strecke senden (nonce + Login).
		register_rest_route( self::NS, '/garage/submit', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_submit' ),
		) );
		// Etappe 2: Benachrichtigungs-Präferenz je Fahrzeug speichern (Senden folgt Etappe 3).
		register_rest_route( self::NS, '/garage/notify', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_notify' ),
		) );
	}

	/* ── Etappe 2: Benachrichtigungs-Präferenz je Fahrzeug (account-gebunden, usermeta) ── */

	const NOTIFY_META = '_m24_garage_notify'; // usermeta: array( post_id => array('price'=>bool,'sold'=>bool) )

	/** Prefs-Map des Accounts (immer Array). */
	public static function notify_all( int $acc ): array {
		$m = get_user_meta( $acc, self::NOTIFY_META, true );
		return is_array( $m ) ? $m : array();
	}

	/** Prefs für EIN Fahrzeug (Defaults false). */
	public static function notify_for( int $acc, int $pid ): array {
		$all = self::notify_all( $acc );
		$p   = isset( $all[ $pid ] ) && is_array( $all[ $pid ] ) ? $all[ $pid ] : array();
		return array( 'price' => ! empty( $p['price'] ), 'sold' => ! empty( $p['sold'] ) );
	}

	public static function handle_notify( WP_REST_Request $req ) {
		$acc = self::current_account_id();
		$pid = (int) $req->get_param( 'post_id' );
		$key = (string) $req->get_param( 'key' );
		$on  = filter_var( $req->get_param( 'on' ), FILTER_VALIDATE_BOOLEAN );
		if ( ! $pid || 'm24_fahrzeug' !== get_post_type( $pid ) ) {
			return new WP_Error( 'm24gc_bad', 'Fahrzeug unbekannt.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $key, array( 'price', 'sold' ), true ) ) {
			return new WP_Error( 'm24gc_bad', 'Unbekannte Präferenz.', array( 'status' => 400 ) );
		}
		$all = self::notify_all( $acc );
		if ( ! isset( $all[ $pid ] ) || ! is_array( $all[ $pid ] ) ) { $all[ $pid ] = array( 'price' => false, 'sold' => false ); }
		$all[ $pid ][ $key ] = $on;
		// Leere Einträge (beide false) wieder entfernen — schlank halten.
		if ( empty( $all[ $pid ]['price'] ) && empty( $all[ $pid ]['sold'] ) ) { unset( $all[ $pid ] ); }
		update_user_meta( $acc, self::NOTIFY_META, $all );
		return rest_ensure_response( array( 'ok' => true ) + self::notify_for( $acc, $pid ) );
	}

	public static function handle_share( WP_REST_Request $req ) {
		$acc    = self::current_account_id();
		$action = (string) $req->get_param( 'action' );

		if ( 'rotate' === $action ) {
			$tok = self::share_token_rotate( $acc );
		} elseif ( 'revoke' === $action ) {
			self::share_token_revoke( $acc );
			return rest_ensure_response( array( 'ok' => true, 'url' => '', 'token' => '' ) );
		} else { // generate / default: vorhandenen Token zeigen oder erzeugen
			$tok = self::share_token_get_or_create( $acc );
		}
		return rest_ensure_response( array( 'ok' => true, 'url' => self::share_url( $tok ), 'token' => $tok ) );
	}

	/* ── Etappe 4: Garage als Anfrage senden (reuse Sammelanfrage-Strecke) ── */

	/** Kontaktdaten des eingeloggten Accounts vorbefüllen (WP-User + ggf. Händler-Datensatz). */
	private static function account_contact( int $acc ): array {
		$u = get_userdata( $acc );
		$first = $u ? (string) get_user_meta( $acc, 'first_name', true ) : '';
		$last  = $u ? (string) get_user_meta( $acc, 'last_name', true ) : '';
		if ( '' === $first && '' === $last && $u ) {
			$first = (string) $u->display_name; // Fallback: Anzeigename in Vorname
		}
		$contact = array(
			'email'    => $u ? (string) $u->user_email : '',
			'vorname'  => $first,
			'nachname' => $last,
			'firma'    => '',
			'tel'      => '',
			'land'     => 'DE',
			'uid'      => '',
			'biz'      => '0',
		);
		// Händler-Datensatz (B2B) hat Vorrang für Firma/Land/USt-ID/Geschäftskunde.
		if ( class_exists( 'M24_B2B' ) ) {
			$h = M24_B2B::get_haendler_by_user( $acc );
			if ( $h ) {
				$contact['firma'] = isset( $h->firma ) ? (string) $h->firma : '';
				$contact['uid']   = isset( $h->uid ) ? (string) $h->uid : '';
				$land             = isset( $h->land ) ? (string) $h->land : '';
				if ( '' !== $land ) { $contact['land'] = $land; }
				$contact['biz']   = '1';
			}
		}
		return $contact;
	}

	/** Garage-Positionen → Item-Shape der bestehenden Push-Strecke (map_items: art/qty/price/src_*). */
	private static function inquiry_items( array $items ): array {
		$has_const = class_exists( 'M24_Inquiries' );
		$out = array();
		foreach ( $items as $it ) {
			$price  = ( null !== $it['unit'] ) ? number_format( (float) $it['unit'], 2, '.', '' ) : ''; // leer/nicht-numerisch → Preis auf Anfrage
			if ( 'm24_fahrzeug' === $it['post_type'] ) {
				$pillar = $has_const ? M24_Inquiries::PILLAR_FAHRZEUG : 'fahrzeug';
			} else {
				$pillar = $has_const ? M24_Inquiries::PILLAR_KATALOG : 'katalog';
			}
			$out[] = array(
				'art'        => (string) $it['title'],
				'qty'        => (int) $it['qty'],
				'price'      => $price,
				'src_url'    => (string) $it['url'],
				'src_pillar' => $pillar,
				'src_pid'    => (string) $it['post_id'],
				'src_art_nr' => (string) $it['artnr'],
			);
		}
		return $out;
	}

	public static function handle_submit( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		if ( ! class_exists( 'M24_Inquiries_Storage' ) ) {
			return new WP_Error( 'm24gc_no_pipeline', 'Anfrage-Strecke nicht verfügbar.', array( 'status' => 500 ) );
		}
		$acc   = self::current_account_id();
		$items = self::items( $acc );
		if ( empty( $items ) ) {
			return new WP_Error( 'm24gc_empty', 'Deine Garage ist leer.', array( 'status' => 422 ) );
		}

		$contact = self::account_contact( $acc );
		if ( '' === $contact['email'] ) {
			return new WP_Error( 'm24gc_no_email', 'Für dein Konto ist keine E-Mail hinterlegt.', array( 'status' => 422 ) );
		}

		$message = trim( (string) $req->get_param( 'message' ) );

		$data = array_merge( $contact, array(
			'notes'               => $message,
			'inquiry_source'      => class_exists( 'M24_Inquiries' ) ? M24_Inquiries::SOURCE_CART : 'cart',
			'inquiry_source_meta' => array( 'origin' => 'garage', 'account_id' => $acc ),
			'items'               => self::inquiry_items( $items ),
		) );

		// Bestehende Strecke: legt die Anfrage-CPT an und plant den Desk-Push (unabhängig vom Mailweg).
		$post_id = M24_Inquiries_Storage::insert_inquiry( $data );
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'm24gc_submit', $post_id->get_error_message(), array( 'status' => 422 ) );
		}

		// Garage bewusst NICHT leeren (Etappe-4-Vorgabe).
		return rest_ensure_response( array(
			'ok'         => true,
			'inquiry_id' => (int) $post_id,
			'message'    => 'Deine Garage wurde als Anfrage an MOTORSPORT24 gesendet. Wir melden uns zeitnah.',
		) );
	}

	/** Nonce + Login. Ohne Account → 401 (Frontend fällt für Gäste auf die DOI-Merkliste zurück). */
	public static function check( $req ) {
		$n = $req->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $n ) || ! wp_verify_nonce( $n, 'wp_rest' ) ) {
			return new WP_Error( 'm24gc_nonce', 'Sicherheitsprüfung fehlgeschlagen.', array( 'status' => 403 ) );
		}
		if ( self::current_account_id() <= 0 ) {
			return new WP_Error( 'm24gc_auth', 'Bitte einloggen, um deine Garage zu nutzen.', array( 'status' => 401 ) );
		}
		return true;
	}

	/** Validierten (post_id, post_type) aus dem Request ziehen oder WP_Error. */
	private static function resolve_post( WP_REST_Request $req ) {
		$pid = (int) $req->get_param( 'post_id' );
		$pt  = $pid ? (string) get_post_type( $pid ) : '';
		if ( ! $pid || ! in_array( $pt, self::allowed_types(), true ) || 'publish' !== get_post_status( $pid ) ) {
			return new WP_Error( 'm24gc_bad', 'Position unbekannt.', array( 'status' => 400 ) );
		}
		return array( $pid, $pt );
	}

	public static function handle_add( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		$res = self::resolve_post( $req );
		if ( is_wp_error( $res ) ) { return $res; }
		list( $pid, $pt ) = $res;

		global $wpdb;
		$acc = self::current_account_id();
		$t   = self::table();
		$now = current_time( 'mysql' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, qty FROM $t WHERE account_id = %d AND post_type = %s AND post_id = %d",
			$acc, $pt, $pid
		), ARRAY_A );

		if ( $row ) {
			$qty = min( self::MAX_QTY, (int) $row['qty'] + 1 );
			$wpdb->update( $t, array( 'qty' => $qty, 'updated_at' => $now ), array( 'id' => (int) $row['id'] ) );
		} else {
			$wpdb->insert( $t, array(
				'account_id' => $acc,
				'post_type'  => $pt,
				'post_id'    => $pid,
				'qty'        => 1,
				'added_at'   => $now,
			) );
		}
		return rest_ensure_response( self::state( $acc, $pid, $pt ) );
	}

	public static function handle_qty( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		$res = self::resolve_post( $req );
		if ( is_wp_error( $res ) ) { return $res; }
		list( $pid, $pt ) = $res;

		global $wpdb;
		$acc = self::current_account_id();
		$t   = self::table();
		$qty = (int) $req->get_param( 'qty' );

		if ( $qty <= 0 ) {
			$wpdb->delete( $t, array( 'account_id' => $acc, 'post_type' => $pt, 'post_id' => $pid ) );
			$st = self::state( $acc, $pid, $pt );
			$st['removed'] = true;
			return rest_ensure_response( $st );
		}
		$qty = min( self::MAX_QTY, $qty );
		$wpdb->query( $wpdb->prepare(
			"UPDATE $t SET qty = %d, updated_at = %s WHERE account_id = %d AND post_type = %s AND post_id = %d",
			$qty, current_time( 'mysql' ), $acc, $pt, $pid
		) );
		return rest_ensure_response( self::state( $acc, $pid, $pt ) );
	}

	public static function handle_remove( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		$res = self::resolve_post( $req );
		if ( is_wp_error( $res ) ) { return $res; }
		list( $pid, $pt ) = $res;

		global $wpdb;
		$acc = self::current_account_id();
		$wpdb->delete( self::table(), array( 'account_id' => $acc, 'post_type' => $pt, 'post_id' => $pid ) );
		$st = self::state( $acc, $pid, $pt );
		$st['removed'] = true;
		return rest_ensure_response( $st );
	}

	public static function handle_get( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		$acc   = self::current_account_id();
		$items = self::items( $acc );
		list( , $grand_fmt, $has_unpriced ) = self::grand_total( $items );
		return rest_ensure_response( array(
			'ok'           => true,
			'count'        => count( $items ),
			'items'        => $items,
			'grand_fmt'    => $grand_fmt,
			'has_unpriced' => $has_unpriced,
		) );
	}

	/* ── Lese-/Preis-Helfer ──────────────────────────────────────────────── */

	/** Anzahl Positionen (Zeilen) im Account-Warenkorb. */
	public static function count_positions( int $acc ): int {
		if ( $acc <= 0 ) { return 0; }
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE account_id = %d", $acc ) );
	}

	/**
	 * Einzelpreis (Brutto, EUR) je Position oder null („Preis auf Anfrage" / kein Preis).
	 * Respektiert die bestehende, öffentliche Preis-Logik der Teile-Detailseite (Brutto, _m24_preis_auf_anfrage).
	 */
	private static function unit_price( string $pt, int $pid ): ?float {
		if ( 'm24_teil' === $pt ) {
			if ( get_post_meta( $pid, '_m24_preis_auf_anfrage', true ) ) { return null; }
			if ( class_exists( 'M24_Catalog_Pricing' ) ) {
				$p = M24_Catalog_Pricing::get( $pid );
				return ( $p && ! empty( $p['brutto'] ) && (float) $p['brutto'] > 0 ) ? (float) $p['brutto'] : null;
			}
			return null;
		}
		if ( 'm24_fahrzeug' === $pt ) {
			$v = (int) get_post_meta( $pid, '_m24fz_preis', true );
			return $v > 0 ? (float) $v : null;
		}
		return null;
	}

	private static function fmt( float $v ): string {
		if ( class_exists( 'M24_Catalog_Pricing' ) ) {
			return M24_Catalog_Pricing::format( $v );
		}
		return number_format( $v, 2, ',', '.' ) . ' €';
	}

	private static function artnr( string $pt, int $pid ): string {
		if ( 'm24_teil' === $pt ) {
			return (string) get_post_meta( $pid, '_m24_artikelnummer', true );
		}
		if ( 'm24_fahrzeug' === $pt ) {
			return (string) get_post_meta( $pid, '_m24fz_fin', true );
		}
		return '';
	}

	/** Detaillierte, anzeigefertige Positionsliste (verwaiste/unveröffentlichte Posts werden übersprungen). */
	public static function items( int $acc ): array {
		if ( $acc <= 0 ) { return array(); }
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_type, post_id, qty FROM $t WHERE account_id = %d ORDER BY added_at DESC, id DESC",
			$acc
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$pid = (int) $r['post_id'];
			$pt  = (string) $r['post_type'];
			if ( 'publish' !== get_post_status( $pid ) ) { continue; }
			$qty   = max( 1, (int) $r['qty'] );
			$unit  = self::unit_price( $pt, $pid );
			$line  = ( null !== $unit ) ? $unit * $qty : null;
			$thumb = get_the_post_thumbnail_url( $pid, 'medium' );
			$out[] = array(
				'post_id'       => $pid,
				'post_type'     => $pt,
				'qty'           => $qty,
				'title'         => get_the_title( $pid ),
				'url'           => (string) get_permalink( $pid ),
				'thumb'         => $thumb ? (string) $thumb : '',
				'artnr'         => self::artnr( $pt, $pid ),
				'unit'          => $unit,
				'unit_fmt'      => ( null !== $unit ) ? self::fmt( $unit ) : null,
				'line_total'    => $line,
				'line_fmt'      => ( null !== $line ) ? self::fmt( $line ) : null,
			);
		}
		return $out;
	}

	/** @return array(float $sum, string $sum_fmt, bool $has_unpriced) */
	public static function grand_total( array $items ): array {
		$sum          = 0.0;
		$has_unpriced = false;
		foreach ( $items as $it ) {
			if ( null === $it['line_total'] ) { $has_unpriced = true; continue; }
			$sum += (float) $it['line_total'];
		}
		return array( $sum, self::fmt( $sum ), $has_unpriced );
	}

	/** Antwort-Snapshot nach einer Mutation: Zähler + betroffene Zeile + Gesamtsumme. */
	private static function state( int $acc, int $pid, string $pt ): array {
		global $wpdb;
		$t   = self::table();
		$qty = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT qty FROM $t WHERE account_id = %d AND post_type = %s AND post_id = %d",
			$acc, $pt, $pid
		) );
		$unit     = self::unit_price( $pt, $pid );
		$line     = ( $qty > 0 && null !== $unit ) ? $unit * $qty : null;
		$items    = self::items( $acc );
		list( , $grand_fmt, $has_unpriced ) = self::grand_total( $items );
		return array(
			'ok'           => true,
			'count'        => count( $items ),
			'qty'          => $qty,
			'line_fmt'     => ( null !== $line ) ? self::fmt( $line ) : null,
			'grand_fmt'    => $grand_fmt,
			'has_unpriced' => $has_unpriced,
		);
	}

	/* ── Assets (Zähler-Update + Button-Verdrahtung + Garage-Seite) ───────── */

	public static function assets() {
		if ( is_admin() ) { return; }
		// Site-weit für eingeloggte Accounts (Zähler + Button-Add); für Gäste nur auf der Garage-Seite.
		$on_garage = is_page() && (int) get_queried_object_id() === (int) get_option( self::PAGE_OPTION );
		if ( self::current_account_id() <= 0 && ! $on_garage ) { return; }

		$css = 'assets/css/m24-garage.css';
		$js  = 'assets/js/m24-garage.js';
		$cp  = M24_PLATTFORM_DIR . $css;
		$jp  = M24_PLATTFORM_DIR . $js;
		$cv  = file_exists( $cp ) ? (string) filemtime( $cp ) : M24_PLATTFORM_VERSION;
		$jv  = file_exists( $jp ) ? (string) filemtime( $jp ) : M24_PLATTFORM_VERSION;

		wp_enqueue_style( 'm24-garage', M24_PLATTFORM_URL . $css, array(), $cv );
		wp_enqueue_script( 'm24-garage', M24_PLATTFORM_URL . $js, array(), $jv, true );
		wp_localize_script( 'm24-garage', 'M24GarageCart', array(
			'rest'     => esc_url_raw( rest_url( self::NS . '/garage/cart' ) ),
			'share'    => esc_url_raw( rest_url( self::NS . '/garage/share' ) ),
			'submit'   => esc_url_raw( rest_url( self::NS . '/garage/submit' ) ),
			'notify'   => esc_url_raw( rest_url( self::NS . '/garage/notify' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'loggedIn' => self::current_account_id() > 0,
			'pageUrl'  => self::page_url(),
			'i18n'     => array(
				'added'       => 'In deine Garage gelegt.',
				'failed'      => 'Aktion fehlgeschlagen. Bitte später erneut.',
				'login'       => 'Bitte einloggen, um deine Garage zu nutzen.',
				'copied'      => 'Link kopiert.',
				'rotated'     => 'Neuer Link erzeugt — der alte Link ist nicht mehr gültig.',
				'mailSubject' => 'Meine MOTORSPORT24 Garage',
				'mailBody'    => 'Hier ist meine Garage bei MOTORSPORT24:',
				'sending'     => 'Anfrage wird gesendet …',
				'sent'        => 'Deine Garage wurde als Anfrage gesendet. Vielen Dank!',
			),
		) );
	}

	/* ── Schwebe-/Header-Zähler ──────────────────────────────────────────── */

	public static function render_counter() {
		if ( is_admin() || self::current_account_id() <= 0 ) { return; }
		$count = self::count_positions( self::current_account_id() );
		$url   = esc_url( self::page_url() );
		$empty = $count <= 0 ? ' is-empty' : '';
		?>
		<a class="m24gc-fab<?php echo esc_attr( $empty ); ?>" href="<?php echo $url; ?>" aria-label="Meine Garage">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-6 9 6v10a1 1 0 0 1-1 1h-4v-7H8v7H4a1 1 0 0 1-1-1z"/></svg>
			<span class="m24gc-fab-badge" data-m24-garage-count><?php echo (int) $count; ?></span>
		</a>
		<?php
	}

	/* ── Garage-Seite (Shortcode) ────────────────────────────────────────── */

	public static function register_shortcode() {
		add_shortcode( 'm24_garage', array( __CLASS__, 'shortcode' ) );
	}

	public static function shortcode( $atts = array() ) {
		// Etappe 2: öffentliche, schreibgeschützte Ansicht über Token (ohne Login, zeigt Token-Eigentümer-Garage).
		$share = isset( $_GET[ self::SHARE_QUERY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::SHARE_QUERY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $share ) {
			return self::render_shared( $share );
		}

		$acc = self::current_account_id();
		ob_start();

		if ( $acc <= 0 ) {
			?>
			<div class="m24gc-page m24gc-guest">
				<h2 class="m24gc-h">Meine Garage</h2>
				<p class="m24gc-empty">Bitte logge dich ein, um deine Garage zu sehen. Du findest den Login-Link über den „In meine Garage"-Dialog auf jeder Fahrzeug- oder Teile-Seite.</p>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		$items = self::items( $acc );
		// Nach Typ aufteilen: Teile → Teile-Merkzettel, Fahrzeuge → Geparkte Fahrzeuge.
		$parts    = array_values( array_filter( $items, static function ( $it ) { return 'm24_fahrzeug' !== $it['post_type']; } ) );
		$vehicles = array_values( array_filter( $items, static function ( $it ) { return 'm24_fahrzeug' === $it['post_type']; } ) );
		list( $grand_num, $grand_fmt, $has_unpriced ) = self::grand_total( $parts ); // Teile-Tab-Summe = NUR Teile
		$net_fmt   = self::fmt( $grand_num / 1.19 );
		$count     = count( $parts );
		$veh_count = count( $vehicles );
		$unpriced  = 0;
		foreach ( $parts as $it ) { if ( null === $it['line_total'] ) { $unpriced++; } }

		$share_tok = self::share_token_existing( $acc );
		$share_url = ( '' !== $share_tok ) ? self::share_url( $share_tok ) : '';

		$u        = wp_get_current_user();
		$email    = $u ? (string) $u->user_email : '';
		$initials = self::initials( $u && '' !== trim( (string) $u->display_name ) ? (string) $u->display_name : $email );
		$logout   = wp_logout_url( self::page_url() );
		?>
		<div class="m24gc-page m24gc-dash" data-m24gc-page>
			<header class="m24gc-dash-head">
				<h1 class="m24gc-dash-title">Meine Garage</h1>
				<div class="m24gc-userline">
					<span class="m24gc-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
					<span class="m24gc-user-email"><?php echo esc_html( $email ); ?></span>
					<a class="m24gc-logout" href="<?php echo esc_url( $logout ); ?>">abmelden</a>
				</div>
			</header>

			<nav class="m24gc-tabs" role="tablist" data-m24gc-tabs>
				<button type="button" class="m24gc-tab is-active" role="tab" aria-selected="true" data-m24gc-tab="vehicles">Geparkte Fahrzeuge <span class="m24gc-tab-badge" data-m24gc-badge="vehicles"<?php echo 0 === $veh_count ? ' hidden' : ''; ?>><?php echo (int) $veh_count; ?></span></button>
				<button type="button" class="m24gc-tab" role="tab" data-m24gc-tab="parts">Teile-Merkzettel <span class="m24gc-tab-badge" data-m24gc-badge="parts"<?php echo 0 === $count ? ' hidden' : ''; ?>><?php echo (int) $count; ?></span></button>
				<button type="button" class="m24gc-tab" role="tab" data-m24gc-tab="notify">Benachrichtigungen</button>
			</nav>

			<!-- TAB: Geparkte Fahrzeuge — volle Breite, eine Karte je Fahrzeug -->
			<section class="m24gc-panel is-active" role="tabpanel" data-m24gc-panel="vehicles">
				<?php if ( empty( $vehicles ) ) : ?>
					<div class="m24gc-emptybox">
						<div class="m24gc-emptybox-t">Deine geparkten Fahrzeuge erscheinen hier</div>
						<p class="m24gc-emptybox-s">Sobald du ein Fahrzeug über „In meine Garage" hinzufügst, findest du es in diesem Tab.</p>
					</div>
				<?php else : ?>
					<div class="m24gc-vlist" data-m24gc-list>
						<?php foreach ( $vehicles as $it ) : self::render_vehicle_card( $it, $acc ); endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<!-- TAB: Teile-Merkzettel — bestehender Cart (umgezogen) -->
			<section class="m24gc-panel" role="tabpanel" data-m24gc-panel="parts" hidden>
				<?php if ( empty( $parts ) ) : ?>
					<div class="m24gc-emptybox" data-m24gc-emptystate>
						<div class="m24gc-emptybox-t">Dein Teile-Merkzettel ist leer</div>
						<p class="m24gc-emptybox-s">Lege Teile über „In meine Garage" auf jeder Teile-Seite hinein.</p>
					</div>
				<?php else : ?>
					<div class="m24gc-grid">
						<div class="m24gc-col-main">
							<div class="m24gc-list" data-m24gc-list>
								<?php foreach ( $parts as $it ) : self::render_row( $it ); endforeach; ?>
							</div>
							<div class="m24gc-listfoot">
								<span class="m24gc-listfoot-l"><?php echo (int) $count; ?> Position<?php echo 1 === $count ? '' : 'en'; ?><?php if ( $unpriced > 0 ) : ?> · <?php echo (int) $unpriced; ?> auf Anfrage<?php endif; ?></span>
								<span class="m24gc-listfoot-r">
									<span class="m24gc-brutto" data-m24gc-grand><?php echo esc_html( $grand_fmt ); ?></span>
									<span class="m24gc-net"><span data-m24gc-net><?php echo esc_html( $net_fmt ); ?></span> netto</span>
								</span>
							</div>
						</div>
						<aside class="m24gc-col-side">
							<div class="m24gc-card m24gc-sumcard" data-m24gc-send>
								<h3 class="m24gc-card-h">Gesamt · inkl. 19 % USt</h3>
								<div class="m24gc-brutto-big" data-m24gc-grand><?php echo esc_html( $grand_fmt ); ?></div>
								<div class="m24gc-net"><span data-m24gc-net><?php echo esc_html( $net_fmt ); ?></span> netto</div>
								<?php if ( $has_unpriced ) : ?><p class="m24gc-note" data-m24gc-note>Einzelne Positionen sind „Preis auf Anfrage" und nicht in der Summe enthalten.</p><?php endif; ?>
								<textarea class="m24gc-send-msg" data-m24gc-send-msg rows="2" placeholder="Nachricht (optional)"></textarea>
								<button type="button" class="m24gc-send-btn m24gc-btn-blue" data-m24gc-send-btn>Als Anfrage senden</button>
								<span class="m24gc-send-status" data-m24gc-send-status role="status"></span>
								<p class="m24gc-hint">Deine Kontaktdaten sind hinterlegt.</p>
							</div>
							<div class="m24gc-card m24gc-sharecard" data-m24gc-share>
								<h3 class="m24gc-card-h">Teilen &amp; sichern</h3>
								<div class="m24gc-share-row">
									<input type="text" class="m24gc-share-input" data-m24gc-share-input readonly value="<?php echo esc_attr( $share_url ); ?>" placeholder="Noch kein Link erzeugt" aria-label="Geteilter Garage-Link">
									<button type="button" class="m24gc-share-btn" data-m24gc-share-copy<?php echo '' === $share_url ? ' hidden' : ''; ?>>Kopieren</button>
								</div>
								<div class="m24gc-share-actions">
									<a class="m24gc-share-link" data-m24gc-share-mail href="#"<?php echo '' === $share_url ? ' hidden' : ''; ?>>Teilen per E-Mail</a>
									<button type="button" class="m24gc-share-gen" data-m24gc-share-generate<?php echo '' === $share_url ? '' : ' hidden'; ?>>Garage-Link erzeugen</button>
									<button type="button" class="m24gc-share-rotate" data-m24gc-share-rotate<?php echo '' === $share_url ? ' hidden' : ''; ?>>Link zurückziehen / neu erzeugen</button>
									<span class="m24gc-share-msg" data-m24gc-share-msg role="status"></span>
								</div>
								<a class="m24gc-pdf-btn m24gc-btn-brass" href="<?php echo esc_url( M24_Garage_PDF::owner_url() ); ?>">Als PDF herunterladen</a>
							</div>
						</aside>
					</div>
				<?php endif; ?>
			</section>

			<!-- TAB: Benachrichtigungen — Etappe 3 Platzhalter -->
			<section class="m24gc-panel" role="tabpanel" data-m24gc-panel="notify" hidden>
				<div class="m24gc-emptybox">
					<div class="m24gc-emptybox-t">Noch keine Benachrichtigungen</div>
					<p class="m24gc-emptybox-s">Hier kannst du künftig Preis- und Verfügbarkeits-Alarme für deine Garage verwalten.</p>
				</div>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Initialen (max. 2) aus Anzeigename oder E-Mail für den Avatar. */
	private static function initials( string $base ): string {
		$base = trim( $base );
		$ini  = '';
		foreach ( preg_split( '/[\s@._\-]+/', $base ) as $w ) {
			if ( '' === $w ) { continue; }
			$ini .= mb_substr( $w, 0, 1 );
			if ( mb_strlen( $ini ) >= 2 ) { break; }
		}
		if ( '' === $ini ) { $ini = mb_substr( $base, 0, 2 ); }
		return mb_strtoupper( $ini );
	}

	/**
	 * Eine Warenkorb-Zeile. $readonly=true → geteilte Ansicht: Menge statisch, keine ±/Entfernen-Controls,
	 * keine Mutations-data-Attribute. Identische Positions-Darstellung wie im Eigentümer-Warenkorb.
	 */
	private static function render_row( array $it, bool $readonly = false ) {
		$pid  = (int) $it['post_id'];
		$pt   = (string) $it['post_type'];
		?>
		<div class="m24gc-row<?php echo $readonly ? ' is-readonly' : ''; ?>" data-line="<?php echo null !== $it['line_total'] ? esc_attr( number_format( (float) $it['line_total'], 2, '.', '' ) ) : ''; ?>"<?php echo $readonly ? '' : ' data-m24gc-row data-post-id="' . esc_attr( $pid ) . '" data-post-type="' . esc_attr( $pt ) . '"'; ?>>
			<a class="m24gc-thumb" href="<?php echo esc_url( $it['url'] ); ?>">
				<?php if ( $it['thumb'] ) : ?>
					<img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy">
				<?php else : ?>
					<span class="m24gc-thumb-ph" aria-hidden="true"></span>
				<?php endif; ?>
			</a>
			<div class="m24gc-info">
				<a class="m24gc-title" href="<?php echo esc_url( $it['url'] ); ?>"><?php echo esc_html( $it['title'] ); ?></a>
				<?php if ( '' !== $it['artnr'] ) : ?>
					<span class="m24gc-artnr">Art.-Nr.: <?php echo esc_html( $it['artnr'] ); ?></span>
				<?php endif; ?>
				<span class="m24gc-unit"><?php echo esc_html( null !== $it['unit_fmt'] ? $it['unit_fmt'] : 'Preis auf Anfrage' ); ?></span>
			</div>
			<?php if ( $readonly ) : ?>
				<div class="m24gc-qty m24gc-qty-static" aria-label="Menge"><span class="m24gc-qty-x">×</span><span class="m24gc-qty-val"><?php echo (int) $it['qty']; ?></span></div>
				<div class="m24gc-line"><?php echo esc_html( null !== $it['line_fmt'] ? $it['line_fmt'] : '—' ); ?></div>
			<?php else : ?>
				<div class="m24gc-qty" role="group" aria-label="Menge">
					<button type="button" class="m24gc-dec" aria-label="Menge verringern">−</button>
					<span class="m24gc-qty-val" data-m24gc-qty><?php echo (int) $it['qty']; ?></span>
					<button type="button" class="m24gc-inc" aria-label="Menge erhöhen">+</button>
				</div>
				<div class="m24gc-line" data-m24gc-line><?php echo esc_html( null !== $it['line_fmt'] ? $it['line_fmt'] : '—' ); ?></div>
				<button type="button" class="m24gc-remove" aria-label="Position entfernen" data-m24gc-remove>&times;</button>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Fahrzeug-Karte (volle Breite) für den „Geparkte Fahrzeuge"-Tab.
	 * Kopf: Thumb + Titel + Status (Farbpunkt) + Preis (§25a: NUR Brutto) + Entfernen (Cart-Remove).
	 * Fußzeile: 3 Textaktionen (Teilen/Exposé-PDF/Anfrage) + 2 Benachrichtigen-Pills (Präferenz-Toggle).
	 */
	private static function render_vehicle_card( array $it, int $acc ) {
		$pid = (int) $it['post_id'];
		$st  = class_exists( 'M24FZ_CPT' ) ? (string) M24FZ_CPT::status( $pid ) : '';
		$map = array(
			'gelistet'    => array( 'Gelistet', 'ok' ),
			'reserviert'  => array( 'Reserviert', 'warn' ),
			'verkauft'    => array( 'Verkauft', 'sold' ),
			'deaktiviert' => array( 'Nicht verfügbar', 'sold' ),
			'entwurf'     => array( 'In Vorbereitung', 'warn' ),
		);
		list( $st_label, $st_tone ) = $map[ $st ] ?? array( 'Gelistet', 'ok' );
		$price = ( null !== $it['unit_fmt'] ) ? $it['unit_fmt'] : 'Preis auf Anfrage';
		$pref  = self::notify_for( $acc, $pid );
		$mailto = 'mailto:?subject=' . rawurlencode( $it['title'] . ' — MOTORSPORT24' )
			. '&body=' . rawurlencode( $it['title'] . "\n" . $it['url'] );
		$anfrage = add_query_arg( 'm24anfrage', '1', $it['url'] );
		$pdf     = M24_Garage_PDF::vehicle_url( $pid );
		?>
		<div class="m24gc-vcard" data-m24gc-row data-post-id="<?php echo esc_attr( $pid ); ?>" data-post-type="m24_fahrzeug" data-line="">
			<div class="m24gc-vcard-head">
				<a class="m24gc-thumb" href="<?php echo esc_url( $it['url'] ); ?>">
					<?php if ( $it['thumb'] ) : ?><img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="m24gc-thumb-ph" aria-hidden="true"></span><?php endif; ?>
				</a>
				<div class="m24gc-vinfo">
					<a class="m24gc-title" href="<?php echo esc_url( $it['url'] ); ?>"><?php echo esc_html( $it['title'] ); ?></a>
					<span class="m24gc-vstatus m24gc-vstatus--<?php echo esc_attr( $st_tone ); ?>"><span class="m24gc-vdot" aria-hidden="true"></span><?php echo esc_html( $st_label ); ?></span>
				</div>
				<div class="m24gc-vprice"><?php echo esc_html( $price ); ?></div>
				<button type="button" class="m24gc-remove" aria-label="Fahrzeug entfernen" data-m24gc-remove>&times;</button>
			</div>
			<div class="m24gc-vcard-foot">
				<div class="m24gc-vactions">
					<a class="m24gc-vact" href="<?php echo esc_url( $mailto ); ?>">Teilen per E-Mail</a>
					<a class="m24gc-vact" href="<?php echo esc_url( $pdf ); ?>">Exposé als PDF</a>
					<a class="m24gc-vact" href="<?php echo esc_url( $anfrage ); ?>">Anfrage senden</a>
				</div>
				<div class="m24gc-vpills" data-m24gc-notify data-post-id="<?php echo esc_attr( $pid ); ?>">
					<button type="button" class="m24gc-pill<?php echo $pref['price'] ? ' is-on' : ''; ?>" data-m24gc-pref="price" aria-pressed="<?php echo $pref['price'] ? 'true' : 'false'; ?>"><span class="m24gc-pill-dot" aria-hidden="true"></span>Preisänderung</button>
					<button type="button" class="m24gc-pill<?php echo $pref['sold'] ? ' is-on' : ''; ?>" data-m24gc-pref="sold" aria-pressed="<?php echo $pref['sold'] ? 'true' : 'false'; ?>"><span class="m24gc-pill-dot" aria-hidden="true"></span>Verkauft / reserviert</button>
				</div>
			</div>
		</div>
		<?php
	}

	/* ── Etappe 2: öffentliche Read-only-Ansicht + noindex ───────────────── */

	/** Core-wp_robots: Share-View → noindex,nofollow (überschreibt index/follow). */
	public static function force_robots( $robots ) {
		if ( self::is_share_view() ) {
			unset( $robots['index'], $robots['follow'] );
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/** Yoast (String-Filter, ältere Versionen): Share-View → noindex,nofollow. */
	public static function yoast_robots_str( $robots ) {
		return self::is_share_view() ? 'noindex,nofollow' : $robots;
	}

	/** Yoast (Array-Filter, neuere Versionen): Share-View → noindex,nofollow. */
	public static function yoast_robots_arr( $robots ) {
		if ( self::is_share_view() ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'nofollow';
		}
		return $robots;
	}

	/** Gürtel & Hosenträger: HTTP-Header X-Robots-Tag auf der Share-View (vor jeder Ausgabe). */
	public static function maybe_robots_header() {
		if ( self::is_share_view() && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	/**
	 * Eigentümer-Garage-Seite: Theme-Chrome zurückdrängen, damit das Dashboard sauber + voll steht.
	 * (1) Theme-Seitentitel + Breadcrumb ausblenden (Dashboard rendert „Meine Garage" selbst).
	 * (2) WPBakery-Sidebar-Spalte (.tdi_6) ausblenden + Content auf volle Breite ziehen.
	 * NUR Eigentümer-View: NICHT auf der Share-View (unberührt), NICHT für Gäste. Scoped auf body.page-id-{ID}.
	 */
	public static function hide_theme_title() {
		if ( is_admin() ) { return; }
		$pid = (int) get_option( self::PAGE_OPTION );
		if ( ! $pid || ! is_page( $pid ) ) { return; }
		if ( ! empty( $_GET[ self::SHARE_QUERY ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification — Share-View unberührt
		if ( self::current_account_id() <= 0 ) { return; } // Gast-Hinweis behält Theme-Chrome
		$b = 'body.page-id-' . $pid . ' ';

		// (1) Titel + Breadcrumb ausblenden (filterbar).
		$hide = apply_filters( 'm24_garage_hide_title_selectors', array(
			'.td-page-header', '.td-page-title', '.entry-title', '.tdb-title-text', '.tdb_title', '.entry-crumbs',
		) );
		$scoped = array();
		foreach ( (array) $hide as $sel ) { $scoped[] = $b . $sel; }

		// (2) For-Sale-Sidebar (.tdi_6) weg + Content-Spalte 100% (filterbar, falls tagDiv die Klasse neu vergibt).
		$sidebar = apply_filters( 'm24_garage_sidebar_selector', '.tdi_6' );
		$css  = implode( ',', $scoped ) . '{display:none!important}';
		$css .= $b . $sidebar . '{display:none!important}';
		$css .= $b . '.td-pb-row>.td-pb-span8,' . $b . '.vc_row .wpb_column{width:100%!important;max-width:100%!important;flex:1 1 100%!important}';

		echo '<style id="m24gc-owner-layout">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** Ist der aktuelle Request die öffentliche, token-basierte Share-Ansicht der Garage-Seite? */
	private static function is_share_view(): bool {
		if ( empty( $_GET[ self::SHARE_QUERY ] ) ) { return false; } // phpcs:ignore WordPress.Security.NonceVerification
		return is_page() && (int) get_queried_object_id() === (int) get_option( self::PAGE_OPTION );
	}

	private static $og_buf = false;

	/** Head ab Prio 0 puffern (vor Yoast prio 1) — nur auf der Share-Ansicht. */
	public static function og_buffer_start() {
		if ( self::is_share_view() ) {
			self::$og_buf = true;
			ob_start();
		}
	}

	/** Konkurrierende og:/twitter:-/robots-Meta aus dem Puffer entfernen, eigenen Satz anhängen. */
	public static function og_buffer_end() {
		if ( ! self::$og_buf ) { return; }
		self::$og_buf = false;
		$head = (string) ob_get_clean();
		// Fremd-OG/Twitter (Yoast/tagDiv) für diese URL entfernen → keine Dubletten.
		$head = preg_replace(
			'#[ \t]*<meta[^>]+(?:property=[\'"]og:[^\'"]*[\'"]|name=[\'"]twitter:[^\'"]*[\'"])[^>]*>\s*#i',
			'',
			$head
		);
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — WP-Core-Head, unverändert durchgereicht
		echo self::share_og_tags(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — intern escaped
	}

	/**
	 * Vollseiten-Puffer NUR auf der Share-View (template_redirect läuft vor dem Theme-Header).
	 * Der Callback fasst das GESAMTE HTML → erreicht auch die vom Theme direkt gesetzte robots-Meta,
	 * die außerhalb von wp_head liegt.
	 */
	public static function maybe_start_page_buffer() {
		if ( self::is_share_view() ) {
			ob_start( array( __CLASS__, 'filter_page_robots' ) );
		}
	}

	/** Alle robots-Meta im Dokument entfernen, GENAU EINE noindex,nofollow direkt nach <head> setzen. */
	public static function filter_page_robots( $html ) {
		$html = (string) $html;
		if ( '' === $html ) { return $html; }
		$html = preg_replace( '#[ \t]*<meta[^>]+name=([\'"])robots\1[^>]*>\s*#i', '', $html );
		$one  = '<meta name="robots" content="noindex, nofollow">' . "\n";
		if ( preg_match( '#<head[^>]*>#i', $html ) ) {
			$html = preg_replace( '#(<head[^>]*>)#i', '$1' . "\n" . $one, $html, 1 );
		} else {
			$html = $one . $html;
		}
		return $html;
	}

	/**
	 * OG-/Twitter-Block für die geteilte Garage. Bild als Filter/Konstante (leicht austauschbar).
	 * WhatsApp bevorzugt < ~300 KB & Landscape → Photon-Resize auf 1200×630 (statt 2048er-Original).
	 * Kein Token-Leak über die kanonische Share-URL hinaus, keine PII.
	 */
	private static function share_og_tags(): string {
		$token = sanitize_text_field( wp_unslash( $_GET[ self::SHARE_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$url   = self::share_url( $token );
		$title = apply_filters( 'm24_garage_share_og_title', 'Sieh mal, in meine MOTORSPORT24-Garage' );
		$desc  = apply_filters( 'm24_garage_share_og_desc', 'Fahrzeuge & Teile, die ich bei MOTORSPORT24 zusammengestellt habe.' );
		// Feste Startvorschau (Kotflügel-WebP); Photon-Resize auf WhatsApp-taugliche 1200×630. Filterbar.
		$src   = 'https://i0.wp.com/www.motorsport24.de/wp-content/rennsport-teile-bilder/2026/06/m3-e92-kotflugel_01.webp';
		$img   = apply_filters( 'm24_garage_share_og_image', $src . '?resize=1200%2C630&ssl=1' );
		$w     = (int) apply_filters( 'm24_garage_share_og_w', 1200 );
		$h     = (int) apply_filters( 'm24_garage_share_og_h', 630 );

		$out  = "\n";
		$prop = function ( $key, $val ) {
			return '<meta property="' . esc_attr( $key ) . '" content="' . esc_attr( $val ) . '">' . "\n";
		};
		$name = function ( $key, $val ) {
			return '<meta name="' . esc_attr( $key ) . '" content="' . esc_attr( $val ) . '">' . "\n";
		};
		$out .= $prop( 'og:type', 'website' );
		$out .= $prop( 'og:site_name', 'MOTORSPORT24' );
		$out .= $prop( 'og:title', $title );
		$out .= $prop( 'og:description', $desc );
		$out .= $prop( 'og:url', esc_url_raw( $url ) );
		$out .= $prop( 'og:image', esc_url_raw( $img ) );
		$out .= $prop( 'og:image:secure_url', esc_url_raw( $img ) );
		if ( $w > 0 ) { $out .= $prop( 'og:image:width', (string) $w ); }
		if ( $h > 0 ) { $out .= $prop( 'og:image:height', (string) $h ); }
		$out .= $name( 'twitter:card', 'summary_large_image' );
		$out .= $name( 'twitter:title', $title );
		$out .= $name( 'twitter:description', $desc );
		$out .= $name( 'twitter:image', esc_url_raw( $img ) );
		return $out;
	}

	/**
	 * Read-only-Ansicht der AKTUELLEN Garage des Token-Eigentümers (live).
	 * Keine PII (E-Mail/Name) — nur Inhalt + Preise, neutral als „Geteilte Garage".
	 */
	private static function render_shared( string $token ): string {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		$acc = self::resolve_share_token( $token );
		ob_start();

		if ( $acc <= 0 ) {
			?>
			<div class="m24gc-page m24gc-shared">
				<h2 class="m24gc-h">Geteilte Garage</h2>
				<p class="m24gc-empty">Dieser Link ist nicht (mehr) gültig. Bitte den Eigentümer um einen aktuellen Link.</p>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		$items = self::items( $acc );
		list( , $grand_fmt, $has_unpriced ) = self::grand_total( $items );
		?>
		<div class="m24gc-page m24gc-shared">
			<h2 class="m24gc-h">Geteilte Garage</h2>
			<p class="m24gc-shared-hint">Schreibgeschützte Ansicht — aktueller Stand dieser Garage.</p>
			<?php if ( empty( $items ) ) : ?>
				<p class="m24gc-empty">Diese Garage ist aktuell leer.</p>
			<?php else : ?>
				<div class="m24gc-list">
					<?php foreach ( $items as $it ) : self::render_row( $it, true ); endforeach; ?>
				</div>
				<div class="m24gc-summary">
					<span class="m24gc-summary-label">Gesamtsumme</span>
					<span class="m24gc-grand"><?php echo esc_html( $grand_fmt ); ?></span>
				</div>
				<?php if ( $has_unpriced ) : ?>
					<p class="m24gc-note">Einzelne Positionen sind „Preis auf Anfrage" und nicht in der Summe enthalten.</p>
				<?php endif; ?>
				<div class="m24gc-pageactions">
					<a class="m24gc-pdf-btn" href="<?php echo esc_url( M24_Garage_PDF::share_url( $token ) ); ?>">Als PDF herunterladen</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
