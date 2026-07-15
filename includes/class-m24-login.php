<?php
/**
 * M24 Passwordless-Login („Vorschlag D") — Magic-Link für ALLE Rollen, state-aware Konto-Header.
 *
 * SICHERHEIT: baut auf den auditierten Token-Primitiven M24_B2B::issue_token()/consume_token() auf
 * (Tabelle magic_tokens: nur SHA-256-Hash, TTL, Einmal-Nutzung, IP-Hash) — kein zweites Krypto-Layer.
 * Enumeration-Schutz: der Request-Endpoint antwortet IMMER neutral. Rate-Limit pro E-Mail UND IP.
 *
 * FLAG m24_login_enabled (Default AUS): solange aus, ist die gesamte Strecke inaktiv (Header, Modal,
 * REST, /m24-login/-Rewrite, wp-login-Umleitung) → Deploy ohne Lockout-Risiko. wp-login.php bleibt
 * IMMER als Break-Glass erreichbar (?m24_classic=1).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Login {

	const FLAG        = 'm24_login_enabled';        // Feature-Flag, Default AUS
	const ADMINS_OPT  = 'm24_login_admin_allowlist'; // Komma-Liste erlaubter Admin-Mails
	const PURPOSE     = 'account_login';             // eigener Token-Purpose (getrennt von B2B verify/login)
	const QV          = 'm24_login_token';
	const NS          = 'm24/v1';
	const TTL         = 600;                          // 10 min (Kunden)
	const TTL_ADMIN   = 300;                          // 5 min (Admin, härter)

	private static $rendered = false;

	public static function enabled(): bool {
		return (bool) (int) get_option( self::FLAG, 0 );
	}

	public static function init() {
		// Token-Strecke IMMER aktiv (sicher: rate-limited, neutral, Single-Use) — damit Magic-/Registrierungs-
		// Links AUCH bei UI-Flag AUS einlösbar sind (Account-Anlage im Anfrage-Modal funktioniert unabhängig).
		add_action( 'init', array( __CLASS__, 'add_rewrite' ), 5 );
		add_filter( 'query_vars', array( __CLASS__, 'register_qv' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_verify' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Break-Glass IMMER: wp-login.php bleibt erreichbar; die Umleitung ist flag- + GET-gated.
		add_action( 'login_init', array( __CLASS__, 'maybe_redirect_wp_login' ) );

		// Nur die SICHTBARE Header-UI ist flag-gated (m24_login_enabled).
		if ( ! self::enabled() ) { return; }
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// tagDiv-Block-Header umgeht Theme-Hooks → per Footer rendern, JS platziert in die Header-Actions.
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'render' ) );
	}

	/**
	 * Guest-Registrierung aus dem Anfrage-Modal: WP-User (customer/subscriber) OHNE Passwort anlegen (falls
	 * neu) + ersten Magic-Link schicken. Unabhängig vom UI-Flag. @return bool true = Link verschickt.
	 */
	public static function create_account_and_send_link( string $email, string $name = '', bool $newsletter_optin = false ): bool {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) { return false; }

		// Rate-Limit (E-Mail UND IP) — verhindert Massen-Kontoanlage/Mail-Spam über die Register-Checkbox.
		if ( ! self::rate_ok( 'e', md5( $email ) ) || ! self::rate_ok( 'i', self::ip_key() ) ) {
			self::log( 'register:rate_limited' );
			return false;
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			// Edge-Case „E-Mail existiert bereits": KEIN Duplikat-Konto → nur Magic-Link ans bestehende Konto.
			$user_id = (int) $user->ID;
			self::log( 'register:existing', $user_id );
		} else {
			$first = trim( $name );
			$last  = '';
			if ( false !== strpos( $first, ' ' ) ) { list( $first, $last ) = array_map( 'trim', explode( ' ', $first, 2 ) ); }
			$role = get_role( 'customer' ) ? 'customer' : 'subscriber';
			$uid  = wp_insert_user( array(
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 28, true, true ), // Zufalls-PW (nie genutzt; Login passwordless)
				'display_name' => '' !== trim( $name ) ? $name : $email,
				'first_name'   => $first,
				'last_name'    => $last,
				'role'         => $role,
			) );
			if ( is_wp_error( $uid ) ) { self::log( 'register:insert_failed' ); return false; }
			$user_id = (int) $uid;
			self::log( 'register:created', $user_id );
		}

		// Opt-in → Konto-Präferenzen: Newsletter-/Alert-Einwilligung auf dem Konto vermerken (DOI-Status
		// pending; der Brevo-DOI läuft separat über register_interessent). Master-Alerts bleiben Default AN.
		// KEIN Scharfschalten von Alert-Versand (global via m24_garage_alerts_enabled gegated).
		if ( $newsletter_optin ) {
			update_user_meta( $user_id, '_m24_newsletter_optin', current_time( 'mysql', true ) );
			if ( class_exists( 'M24_Garage_Alerts' ) && '' === (string) get_user_meta( $user_id, M24_Garage_Alerts::MASTER_META, true ) ) {
				update_user_meta( $user_id, M24_Garage_Alerts::MASTER_META, '1' ); // explizit AN (Default war ohnehin AN)
			}
		}

		$raw = M24_B2B::issue_token( $email, self::PURPOSE, $user_id, self::TTL );
		self::send_login_mail( $email, $raw );
		self::log( 'register:link_sent', $user_id );
		return true;
	}

	/** Eindeutigen user_login aus dem E-Mail-Local-Part ableiten. */
	private static function unique_login( string $email ): string {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base ) { $base = 'kunde'; }
		$login = $base; $i = 1;
		while ( username_exists( $login ) ) { $login = $base . $i; $i++; }
		return $login;
	}

	/* ── Rewrite /m24-login/{token}/ ─────────────────────────────────────── */

	public static function add_rewrite() {
		add_rewrite_rule( '^m24-login/([A-Za-z0-9]+)/?$', 'index.php?' . self::QV . '=$matches[1]', 'top' );
	}
	public static function register_qv( $vars ) { $vars[] = self::QV; return $vars; }

	/* ── Verify: Token → Login → Rollen-Redirect ────────────────────────── */

	public static function handle_verify() {
		$raw = get_query_var( self::QV );
		if ( ! is_string( $raw ) || '' === $raw ) { return; }
		nocache_headers();
		if ( ! headers_sent() ) { header( 'X-Robots-Tag: noindex', true ); }

		$raw = preg_replace( '/[^a-f0-9]/', '', $raw ); // Token ist hex (issue_token: bin2hex)
		$row = ( '' !== $raw ) ? M24_B2B::consume_token( $raw, self::PURPOSE ) : null;

		if ( ! $row || empty( $row->wp_user_id ) ) {
			self::log( 'verify:invalid' );
			self::render_invalid_page();
			exit;
		}
		$uid  = (int) $row->wp_user_id;
		$user = get_user_by( 'id', $uid );
		if ( ! $user ) { self::log( 'verify:no_user' ); self::render_invalid_page(); exit; }

		$is_admin = user_can( $user, 'manage_options' );
		if ( $is_admin && ! self::admin_allowed( (string) $user->user_email ) ) {
			// Admin nicht auf der Allowlist → kein Admin-Login per Magic-Link (Break-Glass bleibt wp-login).
			self::log( 'verify:admin_not_allowed', $uid );
			self::render_invalid_page( 'Für dieses Konto ist der Login per Link nicht freigeschaltet.' );
			exit;
		}

		wp_set_auth_cookie( $uid, true );
		delete_user_meta( $uid, '_m24_doi_pending' ); // DOI bestätigt → aus dem Claim-Stub wird ein echtes Konto (Garage-Karte)
		self::log( 'verify:ok', $uid );

		$dest = $is_admin ? admin_url() : self::garage_url();
		// Rückkehr-Ziel überschreibbar (z. B. Angebots-Annahme → zurück auf ?m24_angebot={token}). wp_safe_redirect
		// erzwingt Same-Host → keine Open-Redirects. Nicht-Admins nur; Admin bleibt beim Admin-Dashboard.
		if ( ! $is_admin ) { $dest = (string) apply_filters( 'm24_login_verify_dest', $dest, $uid ); }
		wp_safe_redirect( $dest );
		exit;
	}

	private static function admin_allowed( string $email ): bool {
		$email = strtolower( trim( $email ) );
		$list  = array_filter( array_map( function ( $e ) { return strtolower( trim( $e ) ); }, explode( ',', (string) get_option( self::ADMINS_OPT, '' ) ) ) );
		// Site-Admin-Mail ist immer erlaubt (kein Lockout, wenn die Allowlist leer/unvollständig ist).
		$list[] = strtolower( (string) get_option( 'admin_email' ) );
		return in_array( $email, $list, true );
	}

	/* ── REST: Magic-Link anfordern ─────────────────────────────────────── */

	public static function register_routes() {
		register_rest_route( self::NS, '/login/request', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // öffentlich (pre-auth); CSRF via wp_rest-Nonce
			'callback'            => array( __CLASS__, 'handle_request' ),
		) );
	}

	public static function handle_request( WP_REST_Request $req ) {
		// Neutrale Antwort — verrät NIE, ob die Adresse existiert (Enumeration-Schutz).
		$neutral = rest_ensure_response( array(
			'ok'      => true,
			'message' => 'Wenn ein Konto zu dieser Adresse existiert, haben wir dir einen Login-Link geschickt. Prüfe dein Postfach.',
		) );

		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) { return $neutral; }

		$email = strtolower( sanitize_email( (string) $req->get_param( 'email' ) ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'm24_login_bad_email', 'Bitte eine gültige E-Mail-Adresse angeben.', array( 'status' => 400 ) );
		}
		self::maybe_send_login_link( $email );
		return $neutral;
	}

	/**
	 * Einheitlicher, immer-aktiver Login-Link-Versand (gemeinsame Strecke für das passwordless-Modal UND
	 * den G2a-Header-Login für Nicht-Händler). Rate-limitiert pro E-Mail UND IP; sendet NUR, wenn ein Konto
	 * existiert (Enumeration-Schutz: kein Konto → still no-op). Admin nur auf der Allowlist. Link zeigt auf
	 * /m24-login/{token} → M24_Login::handle_verify (Rewrite immer registriert). @return bool true = gesendet.
	 */
	public static function maybe_send_login_link( string $email ): bool {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) { return false; }

		if ( ! self::rate_ok( 'e', md5( $email ) ) || ! self::rate_ok( 'i', self::ip_key() ) ) {
			self::log( 'request:rate_limited' );
			return false;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) { self::log( 'request:no_user' ); return false; }

		$is_admin = user_can( $user, 'manage_options' );
		if ( $is_admin && ! self::admin_allowed( $email ) ) {
			self::log( 'request:admin_not_allowed', (int) $user->ID );
			return false;
		}
		$ttl = $is_admin ? self::TTL_ADMIN : self::TTL;
		$raw = M24_B2B::issue_token( $email, self::PURPOSE, (int) $user->ID, $ttl ); // invalidiert alte offene Tokens
		self::send_login_mail( $email, $raw );
		self::log( 'request:sent', (int) $user->ID );
		return true;
	}

	/** Rate-Limit: max 3 / 15 min UND max 10 / Tag je Key. true = erlaubt (Zähler erhöht). */
	private static function rate_ok( string $scope, string $id ): bool {
		$ok = true;
		foreach ( array( array( '15_', 3, 15 * MINUTE_IN_SECONDS ), array( 'd_', 10, DAY_IN_SECONDS ) ) as $w ) {
			$key = 'm24lg_' . $scope . $w[0] . $id;
			$n   = (int) get_transient( $key );
			if ( $n >= $w[1] ) { $ok = false; }
			set_transient( $key, $n + 1, $w[2] );
		}
		return $ok;
	}

	private static function ip_key(): string {
		return hash( 'sha256', ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' ) . wp_salt( 'auth' ) );
	}

	private static function send_login_mail( string $email, string $raw ): void {
		$url  = home_url( '/m24-login/' . rawurlencode( $raw ) . '/' ); // Token NUR im Pfad, nie geloggt
		$btn  = '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1f74c4;color:#fff;text-decoration:none;font-weight:600;padding:13px 28px;border-radius:8px;font-size:15px;">Jetzt anmelden</a></p>';
		$inner = '<p style="margin:0 0 14px;">Hallo,</p>'
			. '<p style="margin:0 0 14px;">klicke auf den Button, um dich bei MOTORSPORT24 anzumelden. Der Link ist einmalig gültig und läuft nach kurzer Zeit ab.</p>'
			. $btn
			. '<p style="margin:0 0 8px;color:#5a6474;font-size:13px;">Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:</p>'
			. '<p style="margin:0;font-size:12px;word-break:break-all;"><a href="' . esc_url( $url ) . '" style="color:#1f74c4;">' . esc_html( $url ) . '</a></p>'
			. '<p style="margin:16px 0 0;color:#9aa3b0;font-size:12px;">Diese E-Mail nicht angefordert? Dann ignoriere sie einfach — es passiert nichts.</p>';
		$html = function_exists( 'm24_mail_shell' ) ? m24_mail_shell( 'Anmelden', $inner, array( 'lang' => 'de' ) ) : $inner;

		$host      = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) { $host = 'motorsport24.de'; }
		$from_mail = apply_filters( 'm24fz_mail_from_email', 'service@' . $host );
		$headers   = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: MOTORSPORT24 <' . $from_mail . '>',
			'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
		);
		wp_mail( $email, 'Dein Login-Link — MOTORSPORT24', $html, $headers );
	}

	/* ── wp-login.php-Ersatz (flag-gated, Break-Glass erhalten) ──────────── */

	public static function maybe_redirect_wp_login() {
		if ( ! self::enabled() ) { return; }
		// Break-Glass: ?m24_classic=1 zeigt weiter das Passwort-Formular.
		if ( isset( $_GET['m24_classic'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		// Nur reine Login-Seiten-Aufrufe umleiten (GET, kein action / action=login). logout/lostpassword/
		// resetpass/register/postpass + POST (echte Anmeldung, Break-Glass) NICHT anfassen → kein Lockout.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method ) { return; }
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $action && 'login' !== $action ) { return; }
		wp_safe_redirect( home_url( '/?m24_login=1' ) ); // Startseite mit geöffnetem Magic-Link-Modal
		exit;
	}

	/* ── Frontend: Header-Element D + Modal ─────────────────────────────── */

	public static function assets() {
		$css = 'assets/css/m24-login.css';
		$js  = 'assets/js/m24-login.js';
		$cv  = file_exists( M24_PLATTFORM_DIR . $css ) ? (string) filemtime( M24_PLATTFORM_DIR . $css ) : M24_PLATTFORM_VERSION;
		$jv  = file_exists( M24_PLATTFORM_DIR . $js ) ? (string) filemtime( M24_PLATTFORM_DIR . $js ) : M24_PLATTFORM_VERSION;
		wp_enqueue_style( 'm24-login', M24_PLATTFORM_URL . $css, array(), $cv );
		wp_enqueue_script( 'm24-login', M24_PLATTFORM_URL . $js, array(), $jv, true );

		$u        = wp_get_current_user();
		$logged   = ( $u && $u->ID > 0 );
		$first    = '';
		if ( $logged ) {
			$fn    = trim( (string) get_user_meta( $u->ID, 'first_name', true ) );
			$base  = '' !== $fn ? $fn : ( '' !== trim( (string) $u->display_name ) ? (string) $u->display_name : (string) $u->user_email );
			$first = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $base, 0, 1 ) ) : strtoupper( substr( $base, 0, 1 ) );
		}
		wp_localize_script( 'm24-login', 'M24Login', array(
			'request'    => esc_url_raw( rest_url( self::NS . '/login/request' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'loggedIn'   => $logged,
			'isAdmin'    => $logged && user_can( $u, 'manage_options' ),
			'initial'    => $first,
			'garageUrl'  => esc_url_raw( self::garage_url() ),
			'settingsUrl'=> esc_url_raw( add_query_arg( 'tab', 'benachrichtigungen', self::garage_url() ) ), // Deep-Link → Benachrichtigungen-Tab
			'adminUrl'   => esc_url_raw( admin_url() ),
			'logoutUrl'  => esc_url_raw( wp_logout_url( home_url( '/' ) ) ),
			'autoOpen'   => isset( $_GET['m24_login'] ), // phpcs:ignore WordPress.Security.NonceVerification
		) );
	}

	public static function render() {
		if ( self::$rendered ) { return; }
		self::$rendered = true;
		?>
		<div id="m24-login-modal" class="m24lg-modal" hidden role="dialog" aria-modal="true" aria-labelledby="m24lg-title">
			<div class="m24lg-overlay" data-m24lg-close></div>
			<div class="m24lg-card" role="document">
				<button type="button" class="m24lg-x" data-m24lg-close aria-label="Schließen">&times;</button>
				<h2 id="m24lg-title" class="m24lg-title">Anmelden</h2>
				<p class="m24lg-sub">Gib deine E-Mail-Adresse ein — wir schicken dir einen Login-Link. Kein Passwort nötig.</p>
				<form class="m24lg-form" data-m24lg-form novalidate>
					<label class="m24lg-field">
						<span class="m24lg-lbl">E-Mail-Adresse</span>
						<input type="email" class="m24lg-input" data-m24lg-email required autocomplete="email" placeholder="du@example.com">
					</label>
					<button type="submit" class="m24lg-submit" data-m24lg-submit>Magic-Link senden</button>
					<p class="m24lg-status" data-m24lg-status role="status"></p>
				</form>
			</div>
		</div>
		<?php
	}

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	private static function garage_url(): string {
		return class_exists( 'M24_Garage_Cart' ) ? M24_Garage_Cart::page_url() : home_url( '/meine-garage/' );
	}

	private static function render_invalid_page( string $msg = '' ) {
		$msg = '' !== $msg ? $msg : 'Dieser Login-Link ist ungültig oder abgelaufen.';
		$req = esc_url( home_url( '/?m24_login=1' ) );
		status_header( 410 );
		nocache_headers();
		if ( function_exists( 'get_header' ) && ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); }
		echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>Login-Link ungültig — MOTORSPORT24</title><meta name="robots" content="noindex,nofollow">'
			. '<style>body{margin:0;background:#fafafa;font-family:Saira,Arial,sans-serif;color:#14161a}'
			. '.b{max-width:520px;margin:12vh auto;padding:32px 24px;background:#fff;border:1px solid #e6e9ee;border-radius:12px;text-align:center}'
			. '.b h1{font-size:22px;margin:0 0 10px}.b p{color:#5a6474;line-height:1.6;margin:0 0 20px}'
			. '.b a{display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:8px}</style></head>'
			. '<body><div class="b"><h1>Anmeldung nicht möglich</h1><p>' . esc_html( $msg ) . ' Fordere einfach einen neuen Link an.</p>'
			. '<a href="' . $req . '">Neuen Login-Link anfordern</a></div></body></html>';
	}

	/** Sync-Log (Kontext „login"): Schritt + optional user_id, NIE Token/Klartext-Mail. */
	private static function log( string $step, int $uid = 0 ) {
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'login', $step, array( 'user' => $uid, 'ip' => substr( self::ip_key(), 0, 12 ) ) );
		}
	}
}
