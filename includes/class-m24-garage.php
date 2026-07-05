<?php
/**
 * M24 Plattform — „Meine Garage", Phase G1: Store + Add-to-Garage + DOI (No-Account).
 *
 * G1-Umfang: zwei Tabellen (idempotent via ensure_tables), Add-Modal auf Fahrzeug-/Teil-Detail
 * (ersetzt „Fahrzeug parken" bzw. „Auf den Merkzettel"), REST /garage/add (Upsert User + Item,
 * Preis/Status snapshotten), DOI-Mail „Bestätige deine Garage" (du, „Hallo {Vorname},", DE/EN),
 * Confirm-Handler (?m24garage=TOKEN) → doi_status=confirmed.
 *
 * NICHT in G1: Magic-Link-Login, Garage-Seite, Notifications (→ G2/G3).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Garage {

	const NS             = 'm24/v1';
	const SCHEMA_OPTION  = 'm24_garage_schema_v';
	const SCHEMA_VER     = '1';
	const PENDING_OPTION = 'm24_garage_pending'; // token => array(email, created)
	const QUERY_VAR      = 'm24garage';
	const TTL            = 1209600; // 14 Tage (DOI-Confirm)
	const LOGIN_PURPOSE  = 'garage_login';
	const LOGIN_TTL      = 1800;    // 30 Min Magic-Link
	const LOGIN_QUERY    = 'm24login';
	const LOGOUT_QUERY   = 'm24garage_logout';
	const SESSION_COOKIE = 'm24_garage_sess';
	const SESSION_TTL    = 604800;  // 7 Tage Session

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_tables' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_confirm' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_login' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_logout' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ) );
	}

	public static function users_table() { return M24_Database::table( 'garage_users' ); }
	public static function items_table() { return M24_Database::table( 'garage_items' ); }

	/* ── Schema (idempotent) ─────────────────────────────────────────────── */

	public static function maybe_ensure_tables() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VER ) {
			return;
		}
		self::ensure_tables();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VER );
	}

	public static function ensure_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc    = $wpdb->get_charset_collate();
		$users = self::users_table();
		$items = self::items_table();

		dbDelta( "CREATE TABLE {$users} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			vorname VARCHAR(120) NULL,
			nachname VARCHAR(120) NULL,
			sprache VARCHAR(5) NOT NULL DEFAULT 'de',
			doi_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			pref_price_drop TINYINT(1) NOT NULL DEFAULT 1,
			pref_monthly TINYINT(1) NOT NULL DEFAULT 1,
			pref_sold TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			confirmed_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_email (email),
			KEY idx_doi (doi_status)
		) {$cc};" );

		dbDelta( "CREATE TABLE {$items} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL,
			item_type VARCHAR(20) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			last_price BIGINT NULL,
			last_status VARCHAR(40) NULL,
			added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_item (email, item_type, post_id),
			KEY idx_email (email)
		) {$cc};" );
	}

	/* ── REST ────────────────────────────────────────────────────────────── */

	public static function register_routes() {
		register_rest_route( self::NS, '/garage/add', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'callback'            => array( __CLASS__, 'handle_add' ),
		) );
		register_rest_route( self::NS, '/garage/login-request', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'callback'            => array( __CLASS__, 'handle_login_request' ),
		) );
	}

	public static function check_nonce( $req ) {
		$n = $req->get_header( 'X-WP-Nonce' );
		return ( is_string( $n ) && wp_verify_nonce( $n, 'wp_rest' ) ) ? true : new WP_Error( 'm24g_nonce', 'Sicherheitsprüfung fehlgeschlagen.', array( 'status' => 403 ) );
	}

	public static function handle_add( WP_REST_Request $req ) {
		self::maybe_ensure_tables(); // lazy: REST kann vor jedem Admin-Besuch laufen
		global $wpdb;
		$p = $req->get_params();

		if ( ! empty( $p['website'] ) ) { return rest_ensure_response( array( 'ok' => true ) ); } // Honeypot

		// Rate-Limit: max 8 / Stunde je IP.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'x';
		$rk  = 'm24g_add_' . md5( $ip );
		$cnt = (int) get_transient( $rk );
		if ( $cnt >= 8 ) { return new WP_Error( 'm24g_rate', 'Zu viele Einträge. Bitte später erneut.', array( 'status' => 429 ) ); }

		$email    = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$vorname  = sanitize_text_field( (string) ( $p['vorname'] ?? '' ) );
		$nachname = sanitize_text_field( (string) ( $p['nachname'] ?? '' ) );
		$lang     = ( 'en' === strtolower( (string) ( $p['lang'] ?? '' ) ) ) ? 'en' : 'de';
		$type     = ( 'part' === ( $p['item_type'] ?? '' ) ) ? 'part' : 'vehicle';
		$pid      = (int) ( $p['post_id'] ?? 0 );
		$consent  = ! empty( $p['consent'] );
		$en       = ( 'en' === $lang );

		// Diagnose: welche Felder ANWESEND (nur bool, keine Werte) — zeigt bei „consent fehlt", ob die
		// Checkbox überhaupt mitgesendet wurde (unchecked → nicht im FormData).
		self::log_step( 'add:received', 'ok', array(
			'has_email'   => '' !== (string) ( $p['email'] ?? '' ),
			'has_vorname' => '' !== (string) ( $p['vorname'] ?? '' ),
			'has_consent' => isset( $p['consent'] ) && '' !== (string) $p['consent'],
			'has_post_id' => $pid > 0,
			'type'        => $type,
		) );

		if ( ! is_email( $email ) ) { self::log_step( 'add:gate', 'FAIL:email_invalid' ); return new WP_Error( 'm24g_form', $en ? 'Please enter a valid email.' : 'Bitte eine gültige E-Mail angeben.', array( 'status' => 422 ) ); }
		if ( '' === $vorname )      { self::log_step( 'add:gate', 'FAIL:vorname_missing' ); return new WP_Error( 'm24g_form', $en ? 'Please enter your first name.' : 'Bitte deinen Vornamen angeben.', array( 'status' => 422 ) ); }
		// Häkchen = NUR Marketing-Opt-in (kein Gate mehr): $consent steuert nur den Newsletter/IL-Opt-in, blockt nicht.

		$expected_pt = ( 'part' === $type ) ? 'm24_teil' : 'm24_fahrzeug';
		if ( ! $pid || $expected_pt !== get_post_type( $pid ) ) {
			self::log_step( 'add:gate', 'FAIL:item_invalid' );
			return new WP_Error( 'm24g_bad', $en ? 'Item not found.' : 'Eintrag unbekannt.', array( 'status' => 400 ) );
		}
		self::log_step( 'add:gates', 'passed' );

		$now = current_time( 'mysql' );

		// 1) User upsert (per E-Mail). doi_status bei Bestand erhalten.
		$users   = self::users_table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, doi_status FROM $users WHERE email = %s", $email ), ARRAY_A );
		if ( $existing ) {
			$wpdb->update( $users, array(
				'vorname'  => $vorname,
				'nachname' => $nachname,
				'sprache'  => $lang,
			), array( 'id' => (int) $existing['id'] ) );
			$doi_status = (string) $existing['doi_status'];
		} else {
			$ins = $wpdb->insert( $users, array(
				'email'      => $email,
				'vorname'    => $vorname,
				'nachname'   => $nachname,
				'sprache'    => $lang,
				'doi_status' => 'pending',
				'created_at' => $now,
			) );
			$doi_status = 'pending';
			self::log_step( 'user:insert', false === $ins ? 'FAIL:' . ( $wpdb->last_error ?: 'db' ) : 'ok' );
		}

		// 2) Item upsert mit Preis-/Status-Snapshot.
		list( $price, $status ) = self::snapshot( $type, $pid );
		$items = self::items_table();
		$item_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $items WHERE email = %s AND item_type = %s AND post_id = %d",
			$email, $type, $pid
		) );
		if ( $item_id ) {
			$wpdb->update( $items, array( 'last_price' => $price, 'last_status' => $status ), array( 'id' => $item_id ) );
		} else {
			$wpdb->insert( $items, array(
				'email'       => $email,
				'item_type'   => $type,
				'post_id'     => $pid,
				'last_price'  => $price,
				'last_status' => $status,
				'added_at'    => $now,
			) );
		}

		// 3) DOI-Mail nur bei neuem/unbestätigtem User.
		if ( 'confirmed' !== $doi_status ) {
			self::log_step( 'doi:reached', 'ok' ); // Sendefunktion erreicht (Ergebnis loggt send_mail)
			self::send_doi_mail( $email, $vorname, $lang );
		} else {
			self::log_step( 'doi:skipped', 'already_confirmed' );
		}

		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		if ( 'confirmed' === $doi_status ) {
			$msg = $en ? 'Added to your garage.' : 'In deine Garage gelegt.';
		} else {
			$msg = $en ? 'Added to your garage — please confirm your email.' : 'In deine Garage gelegt — bitte E-Mail bestätigen.';
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => $msg ) );
	}

	/** Preis-/Status-Snapshot je Typ. @return array(int|null $price, string $status) */
	private static function snapshot( $type, $pid ) {
		if ( 'part' === $type ) {
			$price  = class_exists( 'M24_Catalog_Pricing' ) ? (int) M24_Catalog_Pricing::price_num( $pid ) : (int) get_post_meta( $pid, '_m24_preis', true );
			$status = (string) get_post_meta( $pid, '_m24_status', true );
			return array( $price > 0 ? $price : null, '' !== $status ? $status : 'aktiv' );
		}
		$price  = (int) get_post_meta( $pid, '_m24fz_preis', true );
		$status = class_exists( 'M24FZ_CPT' ) ? (string) M24FZ_CPT::status( $pid ) : '';
		return array( $price > 0 ? $price : null, '' !== $status ? $status : 'gelistet' );
	}

	/* ── DOI ─────────────────────────────────────────────────────────────── */

	private static function new_token() {
		return function_exists( 'random_bytes' ) ? bin2hex( random_bytes( 16 ) ) : bin2hex( wp_generate_password( 16, false, false ) );
	}

	private static function pending_load() {
		$s = get_option( self::PENDING_OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function pending_token_for( $store, $email ) {
		foreach ( $store as $tok => $rec ) {
			if ( isset( $rec['email'] ) && strtolower( (string) $rec['email'] ) === strtolower( $email ) ) {
				return $tok;
			}
		}
		return null;
	}

	private static function send_doi_mail( $email, $vorname, $lang = 'de' ) {
		$store = self::pending_load();
		$token = self::pending_token_for( $store, $email );
		if ( null === $token ) { $token = self::new_token(); }
		$store[ $token ] = array( 'email' => $email, 'created' => time() );
		update_option( self::PENDING_OPTION, $store, false );

		$url = add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
		$en  = ( 'en' === strtolower( (string) $lang ) );

		if ( $en ) {
			$subject  = 'Confirm your garage — MOTORSPORT24';
			$headline = 'Almost done!';
			$hallo    = ( '' !== trim( (string) $vorname ) ) ? 'Hi ' . esc_html( $vorname ) . ',' : 'Hi,';
			$intro    = 'thank you for using your garage. Please confirm with one click that we may keep you posted about your saved vehicles and parts:';
			$cta      = 'Confirm garage';
			$hint     = 'If the button does not work, copy this link into your browser:';
			$ignore   = 'If you did not sign up, simply ignore this email — nothing happens.';
		} else {
			$subject  = 'Bestätige deine Garage — MOTORSPORT24';
			$headline = 'Fast geschafft!';
			$hallo    = ( '' !== trim( (string) $vorname ) ) ? 'Hallo ' . esc_html( $vorname ) . ',' : 'Hallo,';
			$intro    = 'schön, dass du deine Garage nutzt. Bitte bestätige mit einem Klick, dass wir dich zu deinen gemerkten Fahrzeugen und Teilen auf dem Laufenden halten dürfen:';
			$cta      = 'Garage bestätigen';
			$hint     = 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:';
			$ignore   = 'Wenn du dich nicht angemeldet hast, ignoriere diese E-Mail einfach — es passiert nichts.';
		}

		$inner = '<p style="margin:0 0 14px;">' . $hallo . '</p>'
			. '<p style="margin:0 0 14px;">' . esc_html( $intro ) . '</p>'
			. '<p style="margin:24px 0;text-align:center;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1f74c4;color:#ffffff;text-decoration:none;font-weight:600;padding:13px 28px;border-radius:6px;font-size:15px;">' . esc_html( $cta ) . '</a></p>'
			. '<p style="margin:0 0 8px;color:#5a6474;font-size:13px;">' . esc_html( $hint ) . '</p>'
			. '<p style="margin:0 0 14px;font-size:12px;word-break:break-all;"><a href="' . esc_url( $url ) . '" style="color:#1f74c4;">' . esc_html( $url ) . '</a></p>'
			. '<p style="margin:0;color:#9aa3b0;font-size:12px;">' . esc_html( $ignore ) . '</p>';

		self::send_mail( $email, $subject, ( function_exists( 'm24_mail_shell' ) ? m24_mail_shell( $headline, $inner, array( 'lang' => $lang ) ) : self::mail_html( $headline, $inner, $lang ) ), 'doi' );
	}

	/**
	 * Absender-Header. Honoriert denselben Filter wie B2B/IL (m24fz_mail_from_email) → EIN
	 * verifizierter Brevo-Sender site-weit. Vorher hardcodete Garage noreply@ → wenn der Filter
	 * einen verifizierten Sender setzte, verschickte Brevo Garage-Mails still nicht (Bug).
	 */
	private static function from_header() {
		$host = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) { $host = 'motorsport24.de'; }
		$email = apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host );
		return 'MOTORSPORT24 <' . $email . '>';
	}

	/**
	 * Mail senden + Ergebnis loggen (Schritt/Status, KEINE PII außer maskierter Adresse).
	 * „Verbindung testen" prüft nur GET /account — der echte Transactional-Send ist der Blindspot;
	 * hier machen wir Erfolg/Fehler sichtbar. wp_mail_failed fängt SMTP-/Brevo-Ablehnungen ab.
	 */
	private static function send_mail( $email, $subject, $html, $kind ) {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::from_header(),
			'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
		);
		$err = '';
		$catch = function ( $wp_error ) use ( &$err ) {
			if ( is_wp_error( $wp_error ) ) { $err = $wp_error->get_error_message(); }
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = false;
		try {
			$ok = (bool) wp_mail( $email, $subject, $html, $headers );
		} catch ( \Throwable $t ) { // PHP 8.x: Fatal/TypeError im Sendepfad sichtbar machen
			$err = 'exception: ' . $t->getMessage();
		}
		remove_action( 'wp_mail_failed', $catch );
		self::log_step( 'mail_send:' . $kind, ( $ok && '' === $err ) ? 'ok' : 'FAIL', array(
			'to'   => class_exists( 'M24_Brevo_Client' ) ? M24_Brevo_Client::mask_email( (string) $email ) : 'x',
			'from' => self::from_header(),
			'err'  => $err ?: null,
		) );
		return $ok && '' === $err;
	}

	/** Diagnose-Log (Sync-Log): Schritt + Status, ohne PII/Token/Link. */
	private static function log_step( $step, $status, $extra = array() ) {
		if ( ! class_exists( 'M24_Logger' ) ) { return; }
		$payload = array_merge( array( 'step' => $step, 'status' => $status ), (array) $extra );
		if ( 'FAIL' === $status ) { M24_Logger::warning( 'garage', $step . ' → ' . $status, $payload ); }
		else { M24_Logger::info( 'garage', $step . ' → ' . $status, $payload ); }
	}

	/** CI-Mail-Gerüst (Gradient-Header, Saira, 600px). */
	private static function mail_html( $headline, $inner, $lang = 'de' ) {
		$logo = esc_url( plugins_url( 'assets/img/m24-logo.png', M24_PLATTFORM_FILE ) );
		return '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f2f4f7;font-family:Saira,Arial,Helvetica,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:24px 0;"><tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:94%;background:#ffffff;border-radius:12px;overflow:hidden;">'
			. '<tr><td style="background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);padding:22px 28px;">'
			. '<img src="' . $logo . '" alt="MOTORSPORT24" height="30" style="height:30px;display:block;border:0;"></td></tr>'
			. '<tr><td style="padding:28px 28px 8px;"><h1 style="margin:0;font-size:22px;font-weight:800;color:#14161a;">' . esc_html( $headline ) . '</h1></td></tr>'
			. '<tr><td style="padding:6px 28px 28px;color:#14161a;font-size:15px;line-height:1.55;">' . $inner . '</td></tr>'
			. '<tr><td style="padding:16px 28px;background:#f7f8fa;color:#9aa3b0;font-size:12px;">MOTORSPORT24 GmbH'
			. ( class_exists( 'M24_I18n' ) ? M24_I18n::mail_lang_footer( $lang ) : '' )
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** Confirm-Handler: ?m24garage=TOKEN → doi_status=confirmed (single-use). */
	public static function maybe_confirm() {
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore
		$store = self::pending_load();
		$ok    = false;
		$email = '';
		if ( isset( $store[ $token ]['email'] ) && ( time() - (int) ( $store[ $token ]['created'] ?? 0 ) ) <= self::TTL ) {
			$email = sanitize_email( (string) $store[ $token ]['email'] );
			global $wpdb;
			$wpdb->update(
				self::users_table(),
				array( 'doi_status' => 'confirmed', 'confirmed_at' => current_time( 'mysql' ) ),
				array( 'email' => $email )
			);
			$ok = true;
		}
		unset( $store[ $token ] );
		update_option( self::PENDING_OPTION, $store, false );

		// Nach erfolgreichem Confirm (Single-Use-Link = Nachweis des E-Mail-Besitzes): vorhandenes WP-Konto einloggen
		// und in die kontogebundene Garage leiten — statt auf der Startseite zu landen.
		$garage_url = class_exists( 'M24_Garage_Cart' ) ? M24_Garage_Cart::page_url() : home_url( '/meine-garage/' );
		if ( $ok && '' !== $email && is_email( $email ) ) {
			$u = get_user_by( 'email', $email );
			if ( $u ) {
				wp_set_current_user( (int) $u->ID );
				wp_set_auth_cookie( (int) $u->ID, true );
			}
			wp_safe_redirect( add_query_arg( 'm24_garage', 'confirmed', $garage_url ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'm24_garage', 'invalid', home_url( '/' ) ) );
		exit;
	}

	/* ── G2a: Magic-Link-Login ───────────────────────────────────────────── */

	/**
	 * Bekannte E-Mail? (Garage-User ODER bestätigter IL-/Interessenten-Mirror.)
	 * @return array|null array(vorname, lang) wenn bekannt, sonst null.
	 */
	private static function lookup_known( $email ) {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) { return null; }

		$u = self::users_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT vorname, sprache FROM $u WHERE email = %s", $email ), ARRAY_A );
		if ( $row ) {
			return array( 'vorname' => (string) $row['vorname'], 'lang' => ( 'en' === strtolower( (string) $row['sprache'] ) ) ? 'en' : 'de' );
		}
		// IL-/Interessenten-Spiegel (Migration 007: vorname/sprache vorhanden).
		$il = M24_Database::table( 'il_interessenten' );
		if ( $il === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $il ) ) ) {
			$r2 = $wpdb->get_row( $wpdb->prepare( "SELECT vorname, sprache FROM $il WHERE email = %s", $email ), ARRAY_A );
			if ( $r2 ) {
				return array( 'vorname' => (string) ( $r2['vorname'] ?? '' ), 'lang' => ( 'en' === strtolower( (string) ( $r2['sprache'] ?? '' ) ) ) ? 'en' : 'de' );
			}
		}
		return null;
	}

	public static function handle_login_request( WP_REST_Request $req ) {
		$p = $req->get_params();
		if ( ! empty( $p['website'] ) ) { return rest_ensure_response( array( 'ok' => true ) ); } // Honeypot

		// Rate-Limit: max 5 Login-Anfragen / Stunde je IP.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'x';
		$rk  = 'm24g_login_' . md5( $ip );
		$cnt = (int) get_transient( $rk );
		if ( $cnt >= 5 ) { return new WP_Error( 'm24g_rate', 'Zu viele Anfragen. Bitte später erneut.', array( 'status' => 429 ) ); }
		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		$email = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$en_req = ( 'en' === strtolower( (string) ( $p['lang'] ?? '' ) ) );
		// Einheitliche Erfolgsmeldung (Anti-Enumeration) — egal ob bekannt oder nicht.
		$msg = $en_req
			? 'If this email is known, we have sent you a login link.'
			: 'Falls diese E-Mail bekannt ist, haben wir dir einen Login-Link geschickt.';

		if ( is_email( $email ) ) {
			$known = self::lookup_known( $email );
			if ( $known && class_exists( 'M24_B2B' ) ) {
				$raw = M24_B2B::issue_token( $email, self::LOGIN_PURPOSE, null, self::LOGIN_TTL );
				self::send_login_mail( $email, $known['vorname'], $known['lang'], $raw );
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => $msg ) );
	}

	private static function send_login_mail( $email, $vorname, $lang, $raw_token ) {
		$url = add_query_arg( self::LOGIN_QUERY, $raw_token, home_url( '/' ) );
		$en  = ( 'en' === strtolower( (string) $lang ) );

		if ( $en ) {
			$subject  = 'Your garage login — MOTORSPORT24';
			$headline = 'Your garage login';
			$hallo    = ( '' !== trim( (string) $vorname ) ) ? 'Hi ' . esc_html( $vorname ) . ',' : 'Hi,';
			$intro    = 'click the button to open your garage. The link is valid for 30 minutes and can be used once:';
			$cta      = 'Open garage';
			$hint     = 'If the button does not work, copy this link into your browser:';
			$ignore   = 'If you did not request this, simply ignore this email.';
		} else {
			$subject  = 'Dein Garage-Login — MOTORSPORT24';
			$headline = 'Dein Garage-Login';
			$hallo    = ( '' !== trim( (string) $vorname ) ) ? 'Hallo ' . esc_html( $vorname ) . ',' : 'Hallo,';
			$intro    = 'klicke auf den Button, um deine Garage zu öffnen. Der Link gilt 30 Minuten und ist einmal nutzbar:';
			$cta      = 'Garage öffnen';
			$hint     = 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:';
			$ignore   = 'Wenn du das nicht angefordert hast, ignoriere diese E-Mail einfach.';
		}

		$inner = '<p style="margin:0 0 14px;">' . $hallo . '</p>'
			. '<p style="margin:0 0 14px;">' . esc_html( $intro ) . '</p>'
			. '<p style="margin:24px 0;text-align:center;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1f74c4;color:#ffffff;text-decoration:none;font-weight:600;padding:13px 28px;border-radius:6px;font-size:15px;">' . esc_html( $cta ) . '</a></p>'
			. '<p style="margin:0 0 8px;color:#5a6474;font-size:13px;">' . esc_html( $hint ) . '</p>'
			. '<p style="margin:0 0 14px;font-size:12px;word-break:break-all;"><a href="' . esc_url( $url ) . '" style="color:#1f74c4;">' . esc_html( $url ) . '</a></p>'
			. '<p style="margin:0;color:#9aa3b0;font-size:12px;">' . esc_html( $ignore ) . '</p>';

		self::send_mail( $email, $subject, ( function_exists( 'm24_mail_shell' ) ? m24_mail_shell( $headline, $inner, array( 'lang' => $lang ) ) : self::mail_html( $headline, $inner, $lang ) ), 'login' );
	}

	/** ?m24login=TOKEN → Token verbrauchen → E-Mail-gebundene Session → Redirect Garage-Seite (G2b). */
	public static function maybe_login() {
		if ( empty( $_GET[ self::LOGIN_QUERY ] ) || ! class_exists( 'M24_B2B' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$raw = sanitize_text_field( wp_unslash( $_GET[ self::LOGIN_QUERY ] ) ); // phpcs:ignore
		$row = M24_B2B::consume_token( $raw, self::LOGIN_PURPOSE );
		$ok  = false;
		if ( $row && ! empty( $row->email ) ) {
			self::start_session( (string) $row->email );
			$ok = true;
		}
		// G2b liefert die Garage-Seite; bis dahin Redirect auf Home mit Status-Flag.
		wp_safe_redirect( add_query_arg( 'm24_garage', $ok ? 'eingeloggt' : 'login-ungueltig', home_url( '/' ) ) );
		exit;
	}

	public static function maybe_logout() {
		if ( empty( $_GET[ self::LOGOUT_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		self::end_session();
		wp_safe_redirect( add_query_arg( 'm24_garage', 'abgemeldet', home_url( '/' ) ) );
		exit;
	}

	/* ── Session (E-Mail-gebunden; Cookie = Zufalls-SID, Transient hält die E-Mail) ── */

	private static function start_session( $email ) {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) { return; }
		$sid = bin2hex( random_bytes( 24 ) );
		set_transient( 'm24_garage_sess_' . $sid, $email, self::SESSION_TTL );
		$secure = is_ssl();
		setcookie( self::SESSION_COOKIE, $sid, array(
			'expires'  => time() + self::SESSION_TTL,
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN ?: '',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		$_COOKIE[ self::SESSION_COOKIE ] = $sid; // sofort im selben Request verfügbar
	}

	private static function end_session() {
		$sid = isset( $_COOKIE[ self::SESSION_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::SESSION_COOKIE ] ) ) : '';
		if ( '' !== $sid ) { delete_transient( 'm24_garage_sess_' . $sid ); }
		setcookie( self::SESSION_COOKIE, '', array(
			'expires'  => time() - 3600,
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN ?: '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		) );
		unset( $_COOKIE[ self::SESSION_COOKIE ] );
	}

	/** Eingeloggte Garage-E-Mail (für G2b) oder '' wenn keine gültige Session. */
	public static function current_email(): string {
		$sid = isset( $_COOKIE[ self::SESSION_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::SESSION_COOKIE ] ) ) : '';
		if ( '' === $sid ) { return ''; }
		$email = get_transient( 'm24_garage_sess_' . $sid );
		return ( is_string( $email ) && is_email( $email ) ) ? $email : '';
	}

	/* ── Frontend: Modal + JS + CSS (Detailseiten) ───────────────────────── */

	public static function render_modal() {
		if ( ! is_singular( array( 'm24_teil', 'm24_fahrzeug' ) ) ) {
			return;
		}
		$lang  = class_exists( 'M24_I18n' ) ? M24_I18n::resolve_lang() : 'de'; // aktuelle Seitensprache übernehmen
		$rest  = esc_url_raw( rest_url( self::NS . '/garage/add' ) );
		$login = esc_url_raw( rest_url( self::NS . '/garage/login-request' ) );
		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<style id="m24g-css">
		.m24g-modal{position:fixed;inset:0;z-index:100001;background:rgba(10,12,16,.6);display:none;align-items:center;justify-content:center;padding:18px}
		.m24g-modal.open{display:flex}
		.m24g-box{position:relative;background:#fff;border-radius:16px;max-width:480px;width:100%;max-height:92vh;overflow:auto;padding:26px 28px 24px;font-family:'Saira',Arial,sans-serif;color:#14161a}
		.m24g-close{position:absolute;top:12px;right:16px;background:none;border:0;font-size:26px;line-height:1;cursor:pointer;color:#9aa0a8}
		.m24g-box h3{font-size:21px;font-weight:800;margin:0 0 2px}
		.m24g-box p.sub{color:#6b7077;font-size:14px;margin:0 0 18px}
		.m24g-form{display:flex;flex-direction:column;gap:14px}
		.m24g-frow{display:grid;grid-template-columns:1fr 1fr;gap:14px}
		.m24g-hp{position:absolute!important;left:-9999px;width:1px;height:1px;opacity:0}
		.m24g-check{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:#3a3f47;line-height:1.45}
		.m24g-check input{flex:0 0 auto;width:18px;height:18px;margin-top:1px;accent-color:#9a6b25}
		.m24g-submit{height:48px;border:0;border-radius:11px;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;font-weight:700;font-size:15px;cursor:pointer;font-family:inherit}
		.m24g-submit:disabled{opacity:.6;cursor:progress}
		.m24g-msg{font-size:13px;color:#9a6b25;min-height:1em;margin:0;text-align:center}
		.m24-langpick{display:flex;gap:10px;align-items:center}
		.m24-langpick .m24-flag{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:7px;font-size:13px;font-weight:600;color:#5a6474;opacity:.5;cursor:pointer;transition:opacity .15s,background .15s}
		.m24-langpick .m24-flag.active{opacity:1;color:#9a6b25;background:#f3ede1}
		.m24-langpick .m24-flag input{position:absolute;opacity:0;width:0;height:0}
		.m24-langpick .m24-flag svg{width:20px;height:14px;border-radius:3px;box-shadow:0 1px 2px rgba(0,0,0,.18);display:block}
		.m24g-loginlink{margin:14px 0 0;text-align:center;font-size:13px}
		.m24g-loginlink a{color:#5a6474;text-decoration:underline}
		.m24g-login-form{display:flex;flex-direction:column;gap:14px;margin-top:14px;border-top:1px solid #eef0f2;padding-top:16px}
		.m24g-login-msg{font-size:13px;color:#9a6b25;min-height:1em;margin:0;text-align:center}
		</style>
		<div class="m24g-modal" id="m24g-modal" aria-hidden="true">
			<div class="m24g-box" role="dialog" aria-modal="true" aria-label="In meine Garage">
				<button type="button" class="m24g-close" aria-label="Schließen">&times;</button>
				<h3>In meine Garage</h3>
				<p class="sub">Park das Fahrzeug in Deiner Garage. Über einen Direktlink kannst Du auch später auf Deine Garage zugreifen oder sie teilen.</p>
				<form class="m24g-form" novalidate>
					<input type="hidden" name="item_type" value="">
					<input type="hidden" name="post_id" value="">
					<div class="m24g-frow">
						<div class="m24-ci-field"><label class="m24-ci-label">Vorname <span class="req">*</span></label><input type="text" name="vorname" class="m24-ci-input" placeholder="Dein Vorname" required></div>
						<div class="m24-ci-field"><label class="m24-ci-label">Nachname</label><input type="text" name="nachname" class="m24-ci-input" placeholder="optional"></div>
					</div>
					<div class="m24-ci-field"><label class="m24-ci-label">E-Mail <span class="req">*</span></label><input type="email" name="email" class="m24-ci-input" placeholder="deine@email.de" required></div>
					<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>"><?php // Seitensprache; Wechsel später via Garage-Einstellungen (G2b) / Mail-Footer ?>
					<label class="m24g-check"><input type="checkbox" name="consent" value="1"> <span class="m24g-check-t">Ja, ich möchte zu meinen gemerkten Fahrzeugen per E-Mail informiert werden.</span></label>
					<input type="text" name="website" class="m24g-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button type="submit" class="m24g-submit">In meiner Garage parken</button>
					<p class="m24g-msg" role="status"></p>
				</form>
				<p class="m24g-loginlink"><a href="#" class="m24g-login-toggle">Schon eine Garage? Einloggen</a></p>
				<form class="m24g-login-form" hidden novalidate>
					<div class="m24-ci-field"><label class="m24-ci-label">E-Mail</label><input type="email" name="email" class="m24-ci-input" placeholder="deine@email.de" required></div>
					<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>">
					<input type="text" name="website" class="m24g-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button type="submit" class="m24g-submit">Login-Link senden</button>
					<p class="m24g-login-msg" role="status"></p>
				</form>
			</div>
		</div>
		<script>
		(function(){
			var cfg={rest:<?php echo wp_json_encode( $rest ); ?>,login:<?php echo wp_json_encode( $login ); ?>,nonce:<?php echo wp_json_encode( $nonce ); ?>};
			var modal=document.getElementById('m24g-modal');if(!modal)return;
			var form=modal.querySelector('.m24g-form'),msg=modal.querySelector('.m24g-msg');
			var loginForm=modal.querySelector('.m24g-login-form'),loginMsg=modal.querySelector('.m24g-login-msg'),loginLink=modal.querySelector('.m24g-loginlink');
			function open(type,id){form.item_type.value=type;form.post_id.value=id;
				var isPart=(type==='part'||type==='teil');
				var sub=modal.querySelector('.sub');
				if(sub){sub.textContent=isPart?'Leg das Teil in Deine Garage – über einen Direktlink kannst Du auch später auf Deine Garage zugreifen oder sie teilen.':'Park das Fahrzeug in Deiner Garage. Über einen Direktlink kannst Du auch später auf Deine Garage zugreifen oder sie teilen.';}
				var ct=modal.querySelector('.m24g-check-t');
				if(ct){ct.textContent=isPart?'Ja, ich möchte zu meinen gemerkten Teilen per E-Mail informiert werden.':'Ja, ich möchte zu meinen gemerkten Fahrzeugen per E-Mail informiert werden.';}
				modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';var f=form.querySelector('[name=vorname]');if(f)f.focus();}
			function close(){modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';if(msg)msg.textContent='';}
			document.addEventListener('click',function(e){
				var t=e.target.closest('.m24-garage-open');
				if(t){e.preventDefault();open(t.getAttribute('data-garage-type')||'vehicle',t.getAttribute('data-garage-id')||'0');return;}
				if(e.target.closest('.m24g-close')||e.target===modal){close();}
			});
			document.addEventListener('keydown',function(e){if(e.key==='Escape'&&modal.classList.contains('open'))close();});
			form.addEventListener('submit',function(e){
				e.preventDefault();
				var btn=form.querySelector('button[type=submit]');if(btn)btn.disabled=true;if(msg)msg.textContent='Wird gesendet …';
				fetch(cfg.rest,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce},body:new FormData(form)})
					.then(function(r){return r.json();})
					.then(function(d){if(msg)msg.textContent=(d&&d.message)?d.message:'In deine Garage gelegt — bitte E-Mail bestätigen.';if(d&&d.ok){form.reset();}if(btn)btn.disabled=false;})
					.catch(function(){if(msg)msg.textContent='Senden fehlgeschlagen. Bitte später erneut.';if(btn)btn.disabled=false;});
			});
			// Bestandsnutzer: Magic-Link-Login (G2) — Toggle blendet das Add-Formular aus, Login-Form ein.
			if(loginLink&&loginForm){
				loginLink.addEventListener('click',function(e){e.preventDefault();var show=loginForm.hidden;loginForm.hidden=!show;form.hidden=show;var fe=loginForm.querySelector('[name=email]');if(show&&fe)fe.focus();});
				loginForm.addEventListener('submit',function(e){
					e.preventDefault();
					var b=loginForm.querySelector('button[type=submit]');if(b)b.disabled=true;if(loginMsg)loginMsg.textContent='Wird gesendet …';
					fetch(cfg.login,{method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce},body:new FormData(loginForm)})
						.then(function(r){return r.json();})
						.then(function(d){if(loginMsg)loginMsg.textContent=(d&&d.message)?d.message:'Falls diese E-Mail bekannt ist, haben wir dir einen Login-Link geschickt.';if(d&&d.ok){loginForm.reset();}if(b)b.disabled=false;})
						.catch(function(){if(loginMsg)loginMsg.textContent='Senden fehlgeschlagen. Bitte später erneut.';if(b)b.disabled=false;});
				});
			}
		})();
		</script>
		<?php
	}
}
