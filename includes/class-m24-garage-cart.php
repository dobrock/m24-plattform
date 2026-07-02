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
		// Share-Token als bekannten Query-Var registrieren, damit redirect_canonical (Core + tagDiv-
		// Mobile-Theme) ihn NICHT aus der URL strippt — sonst landet der Empfänger auf der leeren Garage.
		add_filter( 'query_vars', array( __CLASS__, 'register_share_query_var' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'keep_share_url' ) );
	}

	public static function register_share_query_var( $vars ) {
		$vars[] = self::SHARE_QUERY;
		return $vars;
	}

	/** Kein kanonischer Redirect auf der Share-Ansicht (würde den Token-Query-Param verwerfen). */
	public static function keep_share_url( $redirect ) {
		return self::is_share_view() ? false : $redirect;
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

	/* ── Share-Snapshot (eingefroren je Token; Migration 008) ────────────── */

	private static function snapshot_table(): string {
		return M24_Database::table( 'garage_snapshot' );
	}

	/**
	 * Aktuelle Teile-Merkzettel-Positionen (NUR Teile, keine Fahrzeuge) für $token einfrieren.
	 * Bei generate + rotate aufgerufen → frischer Snapshot am (neuen) Token. Reihenfolge = items()
	 * (sort_order, sobald Migration 009 aktiv). Alte Snapshots des Accounts werden ersetzt.
	 */
	public static function write_snapshot( int $acc, string $token ): void {
		if ( $acc <= 0 || '' === $token ) { return; }
		global $wpdb;
		$t     = self::snapshot_table();
		$parts = array_values( array_filter( self::items( $acc ), static function ( $it ) { return 'm24_fahrzeug' !== $it['post_type']; } ) );

		$items = array();
		$gross_sum = 0.0; $unpriced = 0; $i = 0;
		foreach ( $parts as $it ) {
			$gross = ( null !== $it['unit'] ) ? (float) $it['unit'] : null;
			$net   = ( null !== $gross ) ? $gross / 1.19 : null;
			if ( null !== $gross ) { $gross_sum += $gross * (int) $it['qty']; } else { $unpriced++; }
			$items[] = array(
				'article_id'  => (int) $it['post_id'],
				'title'       => (string) $it['title'],
				'art_nr'      => (string) $it['artnr'],
				'price_gross' => $gross,
				'price_net'   => $net,
				'qty'         => (int) $it['qty'],
				'image_url'   => (string) $it['thumb'],
				'sort_order'  => $i++,
			);
		}
		$totals = array(
			'gross'     => $gross_sum,
			'net'       => $gross_sum / 1.19,
			'gross_fmt' => self::fmt( $gross_sum ),
			'net_fmt'   => self::fmt( $gross_sum / 1.19 ),
			'count'     => count( $items ),
			'unpriced'  => $unpriced,
		);

		// Pro Account genau einen aktuellen Snapshot halten (alter Token ist nach rotate ohnehin tot).
		$wpdb->delete( $t, array( 'account_id' => $acc ) );
		$wpdb->insert( $t, array(
			'share_token' => $token,
			'account_id'  => $acc,
			'created_at'  => current_time( 'mysql' ),
			'items_json'  => wp_json_encode( $items ),
			'totals_json' => wp_json_encode( $totals ),
		) );
	}

	/** Snapshot zum Token lesen (items + totals) oder null. */
	public static function read_snapshot( string $token ) {
		global $wpdb;
		$t   = self::snapshot_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT items_json, totals_json FROM $t WHERE share_token = %s ORDER BY id DESC LIMIT 1", $token ), ARRAY_A );
		if ( ! $row ) { return null; }
		$items  = json_decode( (string) $row['items_json'], true );
		$totals = json_decode( (string) $row['totals_json'], true );
		return array( 'items' => is_array( $items ) ? $items : array(), 'totals' => is_array( $totals ) ? $totals : array() );
	}

	public static function delete_snapshots( int $acc ): void {
		if ( $acc <= 0 ) { return; }
		global $wpdb;
		$wpdb->delete( self::snapshot_table(), array( 'account_id' => $acc ) );
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
		// Etappe 3: Master-Schalter (alle Garage-Alerts des Accounts an/aus).
		register_rest_route( self::NS, '/garage/notify-master', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_notify_master' ),
		) );
		// Teile-Merkzettel per Drag & Drop sortieren (Reihenfolge persistent, account-gebunden).
		register_rest_route( self::NS, '/garage/reorder', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_reorder' ),
		) );
		// Garage server-seitig per E-Mail an einen Kunden senden (+ optional Exposé-PDF-Anhang).
		register_rest_route( self::NS, '/garage/send', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_send' ),
		) );
	}

	/** Vorschau/Test-Versand der Garage-Mail (Admin-Tool) — echtes m24_mail_shell + Dummy-PDF-Anhang. */
	public static function preview_send_mail( string $to ): bool {
		if ( ! is_email( $to ) ) { return false; }
		$share_url = self::page_url();
		$inner  = '<p style="margin:0 0 14px;">Guten Tag,</p>'
			. '<p style="margin:0 0 14px;">anbei Ihre persönliche Teile-Auswahl von MOTORSPORT24.</p>'
			. '<p style="margin:0 0 14px;padding:12px 14px;background:#f2f4f7;border-radius:6px;white-space:pre-wrap;">' . esc_html( 'Beispiel-Nachricht: Ihre angefragten Teile im Überblick.' ) . '</p>'
			. '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( $share_url ) . '" style="display:inline-block;background:#1f74c4;color:#fff;text-decoration:none;font-weight:600;padding:12px 26px;border-radius:6px;font-size:15px;">Zur Teile-Übersicht</a></p>'
			. '<p style="margin:0;color:#5a6474;font-size:13px;">Das vollständige Exposé finden Sie im PDF-Anhang dieser E-Mail.</p>';
		$html = function_exists( 'm24_mail_shell' ) ? m24_mail_shell( 'Ihre Teile-Auswahl', $inner ) : '<h1>Ihre Teile-Auswahl</h1>' . $inner;

		$attachments = array();
		$tmp_dir     = '';
		if ( class_exists( 'M24_Garage_PDF' ) ) {
			$pdf = M24_Garage_PDF::preview_pdf_string();
			if ( '' !== $pdf ) {
				$tmp_dir = trailingslashit( get_temp_dir() ) . 'm24gc-' . wp_generate_password( 10, false, false ) . '/';
				if ( wp_mkdir_p( $tmp_dir ) ) {
					$file = $tmp_dir . 'MOTORSPORT24-Teileauswahl.pdf';
					if ( false !== file_put_contents( $file, $pdf ) ) { $attachments[] = $file; } // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		}
		$host      = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) { $host = 'motorsport24.de'; }
		$headers   = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: MOTORSPORT24 <' . apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host ) . '>',
			'Reply-To: MOTORSPORT24 <info@motorsport24.de>',
		);
		$ok = (bool) wp_mail( $to, '[TEST] Ihre Teile-Auswahl – MOTORSPORT24', $html, $headers, $attachments );
		foreach ( $attachments as $f ) { if ( is_file( $f ) ) { @unlink( $f ); } } // phpcs:ignore
		if ( '' !== $tmp_dir && is_dir( $tmp_dir ) ) { @rmdir( $tmp_dir ); } // phpcs:ignore
		return $ok;
	}

	/** Empfänger-Adresse für den Sync-Log redigieren (kein Klartext-PII). */
	private static function redact_email( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at ) { return '***'; }
		$local = substr( $email, 0, $at );
		$dom   = substr( $email, $at );
		$keep  = mb_substr( $local, 0, 2 );
		return $keep . str_repeat( '*', max( 1, mb_strlen( $local ) - 2 ) ) . $dom;
	}

	/** POST /garage/send {to,message,attach_pdf} → frischer Snapshot + Mail (m24_mail_shell) + PDF-Anhang. */
	public static function handle_send( WP_REST_Request $req ) {
		$acc = self::current_account_id();
		$to  = sanitize_email( (string) $req->get_param( 'to' ) );
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'm24gc_bad_email', 'Bitte eine gültige E-Mail-Adresse angeben.', array( 'status' => 400 ) );
		}
		$items = self::items( $acc );
		if ( empty( $items ) ) {
			return new WP_Error( 'm24gc_empty', 'Deine Garage ist leer.', array( 'status' => 422 ) );
		}
		$message = trim( (string) $req->get_param( 'message' ) );
		$attach  = filter_var( $req->get_param( 'attach_pdf' ), FILTER_VALIDATE_BOOLEAN );

		// A) Frischen Snapshot am neuen Token (wie share-rotate) → gemailter Link = Abbild zum Sendezeitpunkt.
		$token     = self::share_token_rotate( $acc );
		self::write_snapshot( $acc, $token );
		$share_url = self::share_url( $token );

		// B) Exposé-PDF optional in eine Temp-Datei (sauberer Anhangsname, kollisionsfrei).
		$attachments = array();
		$tmp_dir     = '';
		if ( $attach && class_exists( 'M24_Garage_PDF' ) ) {
			$pdf = M24_Garage_PDF::render_pdf_string( $acc );
			if ( '' !== $pdf ) {
				$tmp_dir = trailingslashit( get_temp_dir() ) . 'm24gc-' . wp_generate_password( 10, false, false ) . '/';
				if ( wp_mkdir_p( $tmp_dir ) ) {
					$file = $tmp_dir . 'MOTORSPORT24-Teileauswahl.pdf';
					if ( false !== file_put_contents( $file, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
						$attachments[] = $file;
					}
				}
			}
		}

		// C) Mail über die kanonische Basis-Vorlage (blauer Header + weißes Logo).
		$subject   = 'Ihre Teile-Auswahl – MOTORSPORT24';
		$btn       = '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( $share_url ) . '" style="display:inline-block;background:#1f74c4;color:#fff;text-decoration:none;font-weight:600;padding:12px 26px;border-radius:6px;font-size:15px;">Zur Teile-Übersicht</a></p>';
		$inner     = '<p style="margin:0 0 14px;">Guten Tag,</p>'
			. '<p style="margin:0 0 14px;">anbei Ihre persönliche Teile-Auswahl von MOTORSPORT24.</p>';
		if ( '' !== $message ) {
			$inner .= '<p style="margin:0 0 14px;padding:12px 14px;background:#f2f4f7;border-radius:6px;white-space:pre-wrap;">' . nl2br( esc_html( $message ) ) . '</p>';
		}
		$inner .= $btn;
		if ( ! empty( $attachments ) ) {
			$inner .= '<p style="margin:0;color:#5a6474;font-size:13px;">Das vollständige Exposé finden Sie im PDF-Anhang dieser E-Mail.</p>';
		}
		$html = function_exists( 'm24_mail_shell' )
			? m24_mail_shell( 'Ihre Teile-Auswahl', $inner )
			: '<h1>Ihre Teile-Auswahl</h1>' . $inner;

		$host      = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) { $host = 'motorsport24.de'; }
		$from_mail = apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host );
		// 1:1-Transaktionsmail → BEWUSST KEIN List-Unsubscribe (kein „Mailing-Liste"-Banner). Nur Marketing/DOI.
		$headers   = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: MOTORSPORT24 <' . $from_mail . '>',
			'Reply-To: MOTORSPORT24 <info@motorsport24.de>',
		);

		$err   = '';
		$catch = function ( $e ) use ( &$err ) { if ( is_wp_error( $e ) ) { $err = $e->get_error_message(); } };
		add_action( 'wp_mail_failed', $catch );
		$ok = false;
		try { $ok = (bool) wp_mail( $to, $subject, $html, $headers, $attachments ); }
		catch ( \Throwable $t ) { $err = 'exception: ' . $t->getMessage(); }
		remove_action( 'wp_mail_failed', $catch );

		// Temp-Datei + -Verzeichnis aufräumen.
		foreach ( $attachments as $f ) { if ( is_file( $f ) ) { @unlink( $f ); } } // phpcs:ignore
		if ( '' !== $tmp_dir && is_dir( $tmp_dir ) ) { @rmdir( $tmp_dir ); } // phpcs:ignore

		$sent = $ok && '' === $err;
		if ( class_exists( 'M24_Logger' ) ) {
			$payload = array(
				'to'            => self::redact_email( $to ),
				'attach_pdf'    => ! empty( $attachments ),
				'token_present' => ( '' !== $token ),
				'result'        => $sent ? 'sent' : 'failed',
			);
			if ( $sent ) { M24_Logger::info( 'garage_mail', 'Garage per E-Mail gesendet', $payload ); }
			else { M24_Logger::error( 'garage_mail', 'Garage-Mail fehlgeschlagen', array_merge( $payload, array( 'error' => $err ) ) ); }
		}

		if ( ! $sent ) {
			return new WP_Error( 'm24gc_send_failed', 'Die E-Mail konnte nicht gesendet werden. Bitte später erneut versuchen.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'sent' => true ) );
	}

	/** {order:[row_id,...]} → sort_order = Index. Nur eigene Zeilen (account-gebunden). */
	public static function handle_reorder( WP_REST_Request $req ) {
		global $wpdb;
		$acc   = self::current_account_id();
		$order = $req->get_param( 'order' );
		if ( ! is_array( $order ) ) {
			return new WP_Error( 'm24gc_bad', 'Ungültige Reihenfolge.', array( 'status' => 400 ) );
		}
		$t = self::table();
		$i = 0;
		foreach ( $order as $rid ) {
			$rid = (int) $rid;
			if ( $rid <= 0 ) { continue; }
			$wpdb->update( $t, array( 'sort_order' => $i ), array( 'id' => $rid, 'account_id' => $acc ) );
			$i++;
		}
		return rest_ensure_response( array( 'ok' => true, 'count' => $i ) );
	}

	/** Master-Schalter: '0' = Account bekommt KEINE Garage-Alerts (überschreibt alle per-Fahrzeug-Prefs). */
	public static function notify_master( int $acc ): bool {
		return '0' !== (string) get_user_meta( $acc, M24_Garage_Alerts::MASTER_META, true ); // Default AN
	}

	public static function handle_notify_master( WP_REST_Request $req ) {
		$acc = self::current_account_id();
		$on  = filter_var( $req->get_param( 'on' ), FILTER_VALIDATE_BOOLEAN );
		update_user_meta( $acc, M24_Garage_Alerts::MASTER_META, $on ? '1' : '0' );
		return rest_ensure_response( array( 'ok' => true, 'master' => $on ) );
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
			self::write_snapshot( $acc, $tok ); // neuer Token → frischer Snapshot
		} elseif ( 'revoke' === $action ) {
			self::share_token_revoke( $acc );
			self::delete_snapshots( $acc );
			return rest_ensure_response( array( 'ok' => true, 'url' => '', 'token' => '' ) );
		} else { // generate / default: vorhandenen Token zeigen oder erzeugen
			$tok = self::share_token_get_or_create( $acc );
			self::write_snapshot( $acc, $tok ); // generate → frischer Snapshot am aktuellen Token
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
		// Sortierung: manuelle Reihenfolge (sort_order, Migration 009) zuerst, id als stabiler Tiebreaker.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, post_type, post_id, qty, sort_order FROM $t WHERE account_id = %d ORDER BY sort_order ASC, id ASC",
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
				'row_id'        => (int) $r['id'],
				'sort_order'    => (int) $r['sort_order'],
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
			'notifyMaster' => esc_url_raw( rest_url( self::NS . '/garage/notify-master' ) ),
			'reorder'  => esc_url_raw( rest_url( self::NS . '/garage/reorder' ) ),
			'sendMail' => esc_url_raw( rest_url( self::NS . '/garage/send' ) ),
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

		// Etappe 3: Benachrichtigungs-Abos des Accounts (Präferenz-Center).
		$subs      = self::notify_all( $acc );
		$master_on = self::notify_master( $acc );
		// Default-Tab: „Teile-Merkzettel", wenn keine Fahrzeuge geparkt sind — sonst „Geparkte Fahrzeuge".
		$def_parts = ( 0 === $veh_count );

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
				<button type="button" class="m24gc-tab<?php echo $def_parts ? '' : ' is-active'; ?>" role="tab"<?php echo $def_parts ? '' : ' aria-selected="true"'; ?> data-m24gc-tab="vehicles">Geparkte Fahrzeuge <span class="m24gc-tab-badge" data-m24gc-badge="vehicles"<?php echo 0 === $veh_count ? ' hidden' : ''; ?>><?php echo (int) $veh_count; ?></span></button>
				<button type="button" class="m24gc-tab<?php echo $def_parts ? ' is-active' : ''; ?>" role="tab"<?php echo $def_parts ? ' aria-selected="true"' : ''; ?> data-m24gc-tab="parts">Teile-Merkzettel <span class="m24gc-tab-badge" data-m24gc-badge="parts"<?php echo 0 === $count ? ' hidden' : ''; ?>><?php echo (int) $count; ?></span></button>
				<button type="button" class="m24gc-tab" role="tab" data-m24gc-tab="notify">Benachrichtigungen</button>
			</nav>

			<!-- TAB: Geparkte Fahrzeuge — volle Breite, eine Karte je Fahrzeug -->
			<section class="m24gc-panel<?php echo $def_parts ? '' : ' is-active'; ?>" role="tabpanel" data-m24gc-panel="vehicles"<?php echo $def_parts ? ' hidden' : ''; ?>>
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
			<section class="m24gc-panel<?php echo $def_parts ? ' is-active' : ''; ?>" role="tabpanel" data-m24gc-panel="parts"<?php echo $def_parts ? '' : ' hidden'; ?>>
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
									<button type="button" class="m24gc-share-gen" data-m24gc-share-generate<?php echo '' === $share_url ? '' : ' hidden'; ?>>Garage-Link erzeugen</button>
									<button type="button" class="m24gc-share-rotate" data-m24gc-share-rotate<?php echo '' === $share_url ? ' hidden' : ''; ?>>Link zurückziehen / neu erzeugen</button>
									<span class="m24gc-share-msg" data-m24gc-share-msg role="status"></span>
								</div>

								<!-- Server-seitiger Versand an Kunden (ersetzt den mailto:-Link) -->
								<div class="m24gc-sendmail" data-m24gc-sendmail>
									<h4 class="m24gc-sendmail-h">Per E-Mail an Kunden senden</h4>
									<label class="m24gc-field">
										<span class="m24gc-field-lbl">E-Mail-Adresse des Kunden</span>
										<input type="email" class="m24gc-field-input" data-m24gc-sendmail-to required placeholder="kunde@example.com" autocomplete="off">
									</label>
									<label class="m24gc-field">
										<span class="m24gc-field-lbl">Nachricht (optional)</span>
										<textarea class="m24gc-field-input m24gc-field-textarea" data-m24gc-sendmail-msg rows="3" placeholder="Persönliche Nachricht an den Kunden"></textarea>
									</label>
									<label class="m24gc-check">
										<input type="checkbox" data-m24gc-sendmail-pdf checked>
										<span>Exposé-PDF anhängen</span>
									</label>
									<button type="button" class="m24gc-pdf-btn m24gc-btn-brass" data-m24gc-sendmail-btn>An Kunden senden</button>
									<span class="m24gc-sendmail-status" data-m24gc-sendmail-status role="status"></span>
								</div>

								<a class="m24gc-pdf-btn m24gc-btn-brass" href="<?php echo esc_url( M24_Garage_PDF::owner_url() ); ?>">Als PDF herunterladen</a>
							</div>
						</aside>
					</div>
				<?php endif; ?>
			</section>

			<!-- TAB: Benachrichtigungen — Präferenz-Center (Etappe 3) -->
			<section class="m24gc-panel" role="tabpanel" data-m24gc-panel="notify" hidden>
				<?php
				// Nur veröffentlichte Fahrzeuge mit mind. einer aktiven Pref auflisten.
				$sub_rows = array();
				foreach ( (array) $subs as $spid => $p ) {
					$spid = (int) $spid;
					if ( 'm24_fahrzeug' !== get_post_type( $spid ) || 'publish' !== get_post_status( $spid ) ) { continue; }
					if ( empty( $p['price'] ) && empty( $p['sold'] ) ) { continue; }
					$sub_rows[ $spid ] = array( 'price' => ! empty( $p['price'] ), 'sold' => ! empty( $p['sold'] ) );
				}
				?>
				<div class="m24gc-notify-master">
					<label class="m24gc-switch">
						<input type="checkbox" data-m24gc-master <?php checked( $master_on ); ?>>
						<span class="m24gc-switch-track" aria-hidden="true"></span>
						<span class="m24gc-switch-label">Alle Benachrichtigungen<?php echo $master_on ? '' : ' (aus)'; ?></span>
					</label>
					<p class="m24gc-hint" style="text-align:left;margin:6px 0 0;">Globaler Schalter — aus = du bekommst keine Garage-Mails, unabhängig von den Einstellungen unten.</p>
				</div>
				<?php if ( empty( $sub_rows ) ) : ?>
					<div class="m24gc-emptybox">
						<div class="m24gc-emptybox-t">Noch keine Benachrichtigungen aktiv</div>
						<p class="m24gc-emptybox-s">Aktiviere „Preisänderung" oder „Verkauft / reserviert" bei einem geparkten Fahrzeug — es erscheint dann hier.</p>
					</div>
				<?php else : ?>
					<div class="m24gc-vlist">
						<?php foreach ( $sub_rows as $spid => $pref ) :
							$thumb = get_the_post_thumbnail_url( $spid, 'medium' );
							?>
							<div class="m24gc-ncard" data-m24gc-notify data-post-id="<?php echo esc_attr( $spid ); ?>">
								<a class="m24gc-thumb" href="<?php echo esc_url( (string) get_permalink( $spid ) ); ?>">
									<?php if ( $thumb ) : ?><img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy"><?php else : ?><span class="m24gc-thumb-ph" aria-hidden="true"></span><?php endif; ?>
								</a>
								<a class="m24gc-title" href="<?php echo esc_url( (string) get_permalink( $spid ) ); ?>"><?php echo esc_html( get_the_title( $spid ) ); ?></a>
								<div class="m24gc-vpills">
									<button type="button" class="m24gc-pill<?php echo $pref['price'] ? ' is-on' : ''; ?>" data-m24gc-pref="price" aria-pressed="<?php echo $pref['price'] ? 'true' : 'false'; ?>"><span class="m24gc-pill-dot" aria-hidden="true"></span>Preisänderung</button>
									<button type="button" class="m24gc-pill<?php echo $pref['sold'] ? ' is-on' : ''; ?>" data-m24gc-pref="sold" aria-pressed="<?php echo $pref['sold'] ? 'true' : 'false'; ?>"><span class="m24gc-pill-dot" aria-hidden="true"></span>Verkauft / reserviert</button>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
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
		<div class="m24gc-row<?php echo $readonly ? ' is-readonly' : ' has-drag'; ?>" data-line="<?php echo null !== $it['line_total'] ? esc_attr( number_format( (float) $it['line_total'], 2, '.', '' ) ) : ''; ?>"<?php echo $readonly ? '' : ' data-m24gc-row data-row-id="' . esc_attr( (int) ( $it['row_id'] ?? 0 ) ) . '" data-post-id="' . esc_attr( $pid ) . '" data-post-type="' . esc_attr( $pt ) . '"'; ?>>
			<?php if ( ! $readonly ) : ?><span class="m24gc-drag" data-m24gc-drag aria-label="Zum Sortieren ziehen" title="Ziehen zum Sortieren">⋮⋮</span><?php endif; ?>
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
	private static function render_vehicle_card( array $it, int $acc, bool $readonly = false ) {
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
		?>
		<div class="m24gc-vcard<?php echo $readonly ? ' is-readonly' : ''; ?>"<?php echo $readonly ? '' : ' data-m24gc-row data-post-id="' . esc_attr( $pid ) . '" data-post-type="m24_fahrzeug" data-line=""'; ?>>
			<div class="m24gc-vcard-head">
				<a class="m24gc-thumb" href="<?php echo esc_url( $it['url'] ); ?>">
					<?php if ( $it['thumb'] ) : ?><img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="m24gc-thumb-ph" aria-hidden="true"></span><?php endif; ?>
				</a>
				<div class="m24gc-vinfo">
					<a class="m24gc-title" href="<?php echo esc_url( $it['url'] ); ?>"><?php echo esc_html( $it['title'] ); ?></a>
					<span class="m24gc-vstatus m24gc-vstatus--<?php echo esc_attr( $st_tone ); ?>"><span class="m24gc-vdot" aria-hidden="true"></span><?php echo esc_html( $st_label ); ?></span>
				</div>
				<div class="m24gc-vprice"><?php echo esc_html( $price ); ?></div>
				<?php if ( ! $readonly ) : ?><button type="button" class="m24gc-remove" aria-label="Fahrzeug entfernen" data-m24gc-remove>&times;</button><?php endif; ?>
			</div>
			<?php if ( ! $readonly ) :
				$mailto  = 'mailto:?subject=' . rawurlencode( $it['title'] . ' — MOTORSPORT24' ) . '&body=' . rawurlencode( $it['title'] . "\n" . $it['url'] );
				$anfrage = add_query_arg( 'm24anfrage', '1', $it['url'] );
				$pdf     = M24_Garage_PDF::vehicle_expose_url( $pid ); // TEIL B: eigenständiges Fahrzeug-Exposé (Datenblatt)
				$pref    = self::notify_for( $acc, $pid );
				?>
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
			<?php endif; ?>
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

		// (2) tagDiv-Sidebar weg + Content-Spalte 100%. STABILE Klasse .td-main-sidebar (nicht die
		//     numerische .tdi_*, die beim Rebuild neu vergeben wird). Filterbar.
		$sidebar = apply_filters( 'm24_garage_sidebar_selector', '.td-main-sidebar' );
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
		$title = apply_filters( 'm24_garage_share_og_title', 'Sieh mal in meine MOTORSPORT24-Garage' );
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

		// Aus dem EINGEFRORENEN Snapshot rendern (nicht aus dem Live-Cart). Fehlt einer (Alt-Token vor 008),
		// lazy erzeugen. Reine Teile-Liste — keine Fahrzeuge, keine Aktions-Boxen (Prompt).
		$snap = self::read_snapshot( $token );
		if ( null === $snap ) { self::write_snapshot( $acc, $token ); $snap = self::read_snapshot( $token ); }
		$items  = ( $snap && ! empty( $snap['items'] ) ) ? $snap['items'] : array();
		$totals = ( $snap && ! empty( $snap['totals'] ) ) ? $snap['totals'] : array();
		$p_count   = (int) ( $totals['count'] ?? count( $items ) );
		$unpriced  = (int) ( $totals['unpriced'] ?? 0 );
		$grand_fmt = (string) ( $totals['gross_fmt'] ?? self::fmt( 0.0 ) );
		$net_fmt   = (string) ( $totals['net_fmt'] ?? self::fmt( 0.0 ) );
		?>
		<div class="m24gc-page m24gc-dash m24gc-shared">
			<header class="m24gc-dash-head">
				<h1 class="m24gc-dash-title">Geteilte Garage</h1>
				<p class="m24gc-shared-hint">Schreibgeschützte Ansicht — Stand zum Zeitpunkt des Teilens.</p>
			</header>

			<?php if ( empty( $items ) ) : ?>
				<div class="m24gc-emptybox"><div class="m24gc-emptybox-t">Diese Garage ist aktuell leer</div></div>
			<?php else : ?>
				<section class="m24gc-shared-sec">
					<div class="m24gc-list">
						<?php foreach ( $items as $it ) : self::render_snapshot_row( (array) $it ); endforeach; ?>
					</div>
					<div class="m24gc-listfoot">
						<span class="m24gc-listfoot-l"><?php echo (int) $p_count; ?> Position<?php echo 1 === $p_count ? '' : 'en'; ?><?php if ( $unpriced > 0 ) : ?> · <?php echo (int) $unpriced; ?> auf Anfrage<?php endif; ?></span>
						<span class="m24gc-listfoot-r">
							<span class="m24gc-brutto"><?php echo esc_html( $grand_fmt ); ?></span>
							<span class="m24gc-net"><?php echo esc_html( $net_fmt ); ?> netto</span>
						</span>
					</div>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Eine read-only Teile-Zeile aus einem Snapshot-Item (items_json-Shape). */
	private static function render_snapshot_row( array $it ) {
		$gross = isset( $it['price_gross'] ) && null !== $it['price_gross'] ? (float) $it['price_gross'] : null;
		$qty   = max( 1, (int) ( $it['qty'] ?? 1 ) );
		$line  = ( null !== $gross ) ? self::fmt( $gross * $qty ) : '—';
		$unit  = ( null !== $gross ) ? self::fmt( $gross ) : 'Preis auf Anfrage';
		?>
		<div class="m24gc-row is-readonly">
			<span class="m24gc-thumb">
				<?php if ( ! empty( $it['image_url'] ) ) : ?><img src="<?php echo esc_url( (string) $it['image_url'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="m24gc-thumb-ph" aria-hidden="true"></span><?php endif; ?>
			</span>
			<div class="m24gc-info">
				<span class="m24gc-title"><?php echo esc_html( (string) ( $it['title'] ?? '' ) ); ?></span>
				<?php if ( ! empty( $it['art_nr'] ) ) : ?><span class="m24gc-artnr">Art.-Nr.: <?php echo esc_html( (string) $it['art_nr'] ); ?></span><?php endif; ?>
				<span class="m24gc-unit"><?php echo esc_html( $unit ); ?></span>
			</div>
			<div class="m24gc-qty m24gc-qty-static" aria-label="Menge"><span class="m24gc-qty-x">×</span><span class="m24gc-qty-val"><?php echo (int) $qty; ?></span></div>
			<div class="m24gc-line"><?php echo esc_html( $line ); ?></div>
		</div>
		<?php
	}
}
