<?php
/**
 * M24 Konto-/Einstellungsseite (Entwurf 1, Zwei-Spalten) — gerendert im Benachrichtigungen-Tab der Garage.
 *
 * SICHER (immer live, kleiner Blast-Radius): Profil, Anschriften, Sprache, Benachrichtigungs-Toggles
 * (Section 5 reuse der bestehenden garage.js-Pills), gemerkte Fahrzeuge, geteilte Links, Fahrzeug-Alerts-UI.
 *
 * FLAG-GATED (Default AUS, m24_account_danger_enabled) — nur lint-, nicht laufzeitgeprüft, erst nach Staging:
 *  - Konto-Löschung (Art. 17) — per E-Mail-Bestätigungslink (Token), räumt M24-Daten auf, LÄSST Desk-
 *    Rechnungen/Aufträge unangetastet (§147 AO/§257 HGB, Art. 17(3)(b)), protokolliert.
 *  - DSGVO-Datenexport (Art. 20).
 *  - Brevo-DOI-Schreibzugriffe der Fahrzeug-Alerts (Auswahl wird IMMER lokal als Meta gespeichert; der
 *    DOI-Upsert an Brevo läuft nur bei aktivem Flag).
 *
 * Alle Endpunkte: eingeloggt + wp_rest-Nonce. Ausgaben esc_*, Queries $wpdb->prepare. In-Page-Patterns.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Account {

	const NS          = 'm24/v1';
	const DANGER_FLAG  = 'm24_account_danger_enabled';
	const DELETE_PURPOSE = 'account_delete';
	const DELETE_QUERY = 'm24_del';

	// Konto-Meta.
	const M_KUNDENTYP = '_m24_kundentyp'; // 'b2b' | 'b2c'
	const M_FIRMA     = '_m24_firma';
	const M_USTID     = '_m24_ustid';
	const M_ADDR_BILL = '_m24_addr_billing';  // array
	const M_ADDR_SHIP = '_m24_addr_shipping'; // array
	const M_LANG      = '_m24_lang_pref';      // 'de' | 'en'
	const M_ALERTS    = '_m24_alert_lists';    // array chip-keys
	const M_MODELLE   = '_m24_alert_modelle';  // array
	const M_OPTIN     = '_m24_newsletter_optin'; // DOI-Zeitpunkt (mysql)
	const M_OPTOUT    = '_m24_alerts_optout';    // §7-Opt-out-Zeitpunkt

	/** Schnellauswahl-Chips → Kategorie/Marke-Hinweise für die Brevo-DOI-Pipeline (5 Alert-Listen). */
	private static function chip_map(): array {
		return array(
			'alle'    => array( 'label' => 'Alle Fahrzeuge', 'kategorien' => array( 'Sport', 'Oldtimer' ), 'marke' => '' ),
			'bmw'     => array( 'label' => 'Alle BMW',       'kategorien' => array( 'Sport', 'Oldtimer' ), 'marke' => 'BMW' ),
			'porsche' => array( 'label' => 'Alle Porsche',   'kategorien' => array( 'Sport', 'Oldtimer' ), 'marke' => 'Porsche' ),
			'race'    => array( 'label' => 'Alle Race',      'kategorien' => array( 'Sport' ),             'marke' => '' ),
			'classic' => array( 'label' => 'Alle Classic',   'kategorien' => array( 'Oldtimer' ),          'marke' => '' ),
		);
	}

	public static function danger_enabled(): bool {
		return (bool) (int) get_option( self::DANGER_FLAG, 0 );
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_delete_verify' ), 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	private static function acc(): int {
		return is_user_logged_in() ? (int) get_current_user_id() : 0;
	}

	/* ── Assets (nur auf der Garage-Seite) ──────────────────────────────── */

	public static function assets() {
		$pid = (int) get_option( 'm24_garage_page_id' );
		if ( $pid <= 0 || ! is_page( $pid ) || ! is_user_logged_in() ) { return; }
		$css = 'assets/css/m24-account.css';
		$js  = 'assets/js/m24-account.js';
		$cv  = file_exists( M24_PLATTFORM_DIR . $css ) ? (string) filemtime( M24_PLATTFORM_DIR . $css ) : M24_PLATTFORM_VERSION;
		$jv  = file_exists( M24_PLATTFORM_DIR . $js ) ? (string) filemtime( M24_PLATTFORM_DIR . $js ) : M24_PLATTFORM_VERSION;
		wp_enqueue_style( 'm24-account', M24_PLATTFORM_URL . $css, array(), $cv );
		wp_enqueue_script( 'm24-account', M24_PLATTFORM_URL . $js, array(), $jv, true );
		wp_localize_script( 'm24-account', 'M24Account', array(
			'base'   => esc_url_raw( rest_url( self::NS . '/account' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'danger' => self::danger_enabled(),
		) );
	}

	/* ── REST ───────────────────────────────────────────────────────────── */

	public static function register_routes() {
		$auth = function () { return is_user_logged_in(); };
		foreach ( array( 'profile', 'address', 'language', 'alerts', 'unsubscribe', 'export', 'delete-request' ) as $r ) {
			register_rest_route( self::NS, '/account/' . $r, array(
				'methods'             => 'POST',
				'permission_callback' => $auth,
				'callback'            => array( __CLASS__, 'handle_' . str_replace( '-', '_', $r ) ),
			) );
		}
	}

	private static function nonce_ok( WP_REST_Request $req ): bool {
		return (bool) wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	public static function handle_profile( WP_REST_Request $req ) {
		if ( ! self::nonce_ok( $req ) ) { return new WP_Error( 'm24acc_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) ); }
		$acc = self::acc();
		$name = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$kt   = ( 'b2b' === $req->get_param( 'kundentyp' ) ) ? 'b2b' : 'b2c';
		if ( '' !== $name ) {
			$parts = preg_split( '/\s+/', $name, 2 );
			wp_update_user( array( 'ID' => $acc, 'display_name' => $name, 'first_name' => $parts[0], 'last_name' => $parts[1] ?? '' ) );
		}
		update_user_meta( $acc, self::M_KUNDENTYP, $kt );
		update_user_meta( $acc, self::M_FIRMA, sanitize_text_field( (string) $req->get_param( 'firma' ) ) );
		update_user_meta( $acc, self::M_USTID, sanitize_text_field( (string) $req->get_param( 'ustid' ) ) );
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Deine Daten wurden gespeichert.' ) );
	}

	public static function handle_address( WP_REST_Request $req ) {
		if ( ! self::nonce_ok( $req ) ) { return new WP_Error( 'm24acc_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) ); }
		$acc = self::acc();
		update_user_meta( $acc, self::M_ADDR_BILL, self::clean_addr( (array) $req->get_param( 'billing' ) ) );
		update_user_meta( $acc, self::M_ADDR_SHIP, self::clean_addr( (array) $req->get_param( 'shipping' ) ) );
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Anschriften gespeichert.' ) );
	}

	private static function clean_addr( array $a ): array {
		$out = array();
		foreach ( array( 'name', 'strasse', 'plz', 'ort', 'land' ) as $k ) {
			$out[ $k ] = sanitize_text_field( (string) ( $a[ $k ] ?? '' ) );
		}
		return $out;
	}

	public static function handle_language( WP_REST_Request $req ) {
		if ( ! self::nonce_ok( $req ) ) { return new WP_Error( 'm24acc_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) ); }
		$lang = ( 'en' === $req->get_param( 'lang' ) ) ? 'en' : 'de';
		update_user_meta( self::acc(), self::M_LANG, $lang );
		if ( class_exists( 'M24_I18n' ) ) { M24_I18n::set_cookie( $lang ); }
		return rest_ensure_response( array( 'ok' => true, 'lang' => $lang, 'message' => 'Sprache gespeichert.' ) );
	}

	public static function handle_alerts( WP_REST_Request $req ) {
		if ( ! self::nonce_ok( $req ) ) { return new WP_Error( 'm24acc_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) ); }
		$acc     = self::acc();
		$chips   = array_values( array_intersect( array_keys( self::chip_map() ), (array) $req->get_param( 'chips' ) ) );
		$modelle = array_values( array_filter( array_map( 'sanitize_text_field', (array) $req->get_param( 'modelle' ) ) ) );
		// IMMER lokal speichern (sicher, verifizierbar).
		update_user_meta( $acc, self::M_ALERTS, $chips );
		update_user_meta( $acc, self::M_MODELLE, $modelle );

		// Brevo-DOI-Schreibzugriff NUR bei aktivem Flag (extern, un-getestet bis Staging).
		if ( self::danger_enabled() && ( ! empty( $chips ) || ! empty( $modelle ) ) && class_exists( 'M24FZ_Anfrage' ) ) {
			$u    = wp_get_current_user();
			$kat  = array();
			$marke = array();
			foreach ( $chips as $c ) {
				$m = self::chip_map()[ $c ];
				$kat = array_merge( $kat, $m['kategorien'] );
				if ( '' !== $m['marke'] ) { $marke[] = $m['marke']; }
			}
			M24FZ_Anfrage::register_interessent( 0, array(
				'name'       => (string) $u->display_name,
				'email'      => (string) $u->user_email,
				'kundentyp'  => ( 'b2b' === get_user_meta( $acc, self::M_KUNDENTYP, true ) ) ? 'Geschäftskunde' : 'Privat',
				'modelle'    => $modelle,
				'kategorien' => array_values( array_unique( $kat ) ),
				'marke'      => array_values( array_unique( $marke ) ),
			) );
			update_user_meta( $acc, self::M_OPTIN, current_time( 'mysql', true ) );
			return rest_ensure_response( array( 'ok' => true, 'message' => 'Auswahl gespeichert — Bestätigungslink (Double-Opt-in) ist unterwegs.' ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Auswahl gespeichert.' ) );
	}

	public static function handle_unsubscribe( WP_REST_Request $req ) {
		if ( ! self::nonce_ok( $req ) ) { return new WP_Error( 'm24acc_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) ); }
		$acc = self::acc();
		if ( class_exists( 'M24_Garage_Alerts' ) ) { update_user_meta( $acc, M24_Garage_Alerts::MASTER_META, '0' ); }
		update_user_meta( $acc, self::M_OPTOUT, current_time( 'mysql', true ) );
		// Brevo-Abmeldung nur bei aktivem Flag (extern).
		if ( self::danger_enabled() && class_exists( 'M24_Brevo_IL' ) && method_exists( 'M24_Brevo_IL', 'unsubscribe_email' ) ) {
			M24_Brevo_IL::unsubscribe_email( (string) wp_get_current_user()->user_email );
		}
		self::log( 'unsubscribe_all', $acc );
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Alle Benachrichtigungen & Alerts abbestellt.' ) );
	}

	/* ── DSGVO-Export (Art. 20) — flag-gated ────────────────────────────── */

	public static function handle_export( WP_REST_Request $req ) {
		if ( ! self::nonce_ok( $req ) ) { return new WP_Error( 'm24acc_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) ); }
		if ( ! self::danger_enabled() ) {
			return new WP_Error( 'm24acc_disabled', 'Datenexport ist noch nicht freigeschaltet.', array( 'status' => 403 ) );
		}
		$acc  = self::acc();
		$u    = get_userdata( $acc );
		$data = array(
			'exportiert_am' => current_time( 'mysql', true ),
			'konto'         => array(
				'name'           => $u ? $u->display_name : '',
				'email'          => $u ? $u->user_email : '',
				'registriert_am' => $u ? $u->user_registered : '',
				'kundentyp'      => (string) get_user_meta( $acc, self::M_KUNDENTYP, true ),
				'firma'          => (string) get_user_meta( $acc, self::M_FIRMA, true ),
				'ust_idnr'       => (string) get_user_meta( $acc, self::M_USTID, true ),
				'sprache'        => (string) get_user_meta( $acc, self::M_LANG, true ),
			),
			'anschriften'   => array(
				'rechnung'  => (array) get_user_meta( $acc, self::M_ADDR_BILL, true ),
				'lieferung' => (array) get_user_meta( $acc, self::M_ADDR_SHIP, true ),
			),
			'fahrzeug_alerts' => array(
				'listen'        => (array) get_user_meta( $acc, self::M_ALERTS, true ),
				'modelle'       => (array) get_user_meta( $acc, self::M_MODELLE, true ),
				'doi_datum'     => (string) get_user_meta( $acc, self::M_OPTIN, true ),
			),
			'garage'        => array_map( function ( $it ) {
				return array( 'titel' => $it['title'], 'typ' => $it['post_type'], 'menge' => $it['qty'], 'url' => $it['url'] );
			}, M24_Garage_Cart::items( $acc ) ),
			'benachrichtigungen' => M24_Garage_Cart::notify_all( $acc ),
		);
		self::log( 'gdpr_export', $acc );
		return rest_ensure_response( array( 'ok' => true, 'data' => $data, 'filename' => 'motorsport24-datenauskunft-' . $acc . '.json' ) );
	}

	/* ── Konto-Löschung (Art. 17) — flag-gated, E-Mail-Bestätigung ──────── */

	public static function handle_delete_request( WP_REST_Request $req ) {
		if ( ! self::nonce_ok( $req ) ) { return new WP_Error( 'm24acc_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) ); }
		if ( ! self::danger_enabled() ) {
			return new WP_Error( 'm24acc_disabled', 'Konto-Löschung ist noch nicht freigeschaltet.', array( 'status' => 403 ) );
		}
		$acc = self::acc();
		$u   = get_userdata( $acc );
		if ( ! $u ) { return new WP_Error( 'm24acc_nouser', 'Konto nicht gefunden.', array( 'status' => 400 ) ); }
		$raw = M24_B2B::issue_token( (string) $u->user_email, self::DELETE_PURPOSE, $acc, 15 * MINUTE_IN_SECONDS );
		self::send_delete_mail( (string) $u->user_email, $raw );
		self::log( 'delete_request', $acc );
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Zur Sicherheit haben wir dir einen Bestätigungslink geschickt. Erst nach dem Klick wird dein Konto gelöscht.' ) );
	}

	private static function send_delete_mail( string $email, string $raw ): void {
		$url  = home_url( '/?' . self::DELETE_QUERY . '=' . rawurlencode( $raw ) );
		$inner = '<p style="margin:0 0 14px;">Du hast die <strong>Löschung deines MOTORSPORT24-Kontos</strong> angefordert.</p>'
			. '<p style="margin:0 0 14px;">Klicke zum endgültigen Löschen auf den Button. Der Link ist einmalig gültig und läuft nach 15 Minuten ab. Deine gesetzlich aufzubewahrenden Rechnungs-/Auftragsdaten bleiben davon unberührt.</p>'
			. '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#9e2b2b;color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;font-size:15px;">Konto endgültig löschen</a></p>'
			. '<p style="margin:0;color:#9aa3b0;font-size:12px;">Nicht angefordert? Dann ignoriere diese E-Mail — es passiert nichts.</p>';
		$html = function_exists( 'm24_mail_shell' ) ? m24_mail_shell( 'Konto löschen', $inner, array( 'lang' => 'de' ) ) : $inner;
		wp_mail( $email, 'Konto-Löschung bestätigen — MOTORSPORT24', $html, array( 'Content-Type: text/html; charset=UTF-8', 'From: MOTORSPORT24 <service@motorsport24.de>' ) );
	}

	public static function handle_delete_verify() {
		if ( empty( $_GET[ self::DELETE_QUERY ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		nocache_headers();
		if ( ! headers_sent() ) { header( 'X-Robots-Tag: noindex', true ); }
		$raw = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET[ self::DELETE_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! self::danger_enabled() ) { self::simple_page( 'Konto-Löschung ist derzeit nicht freigeschaltet.' ); exit; }
		$row = ( '' !== $raw ) ? M24_B2B::consume_token( $raw, self::DELETE_PURPOSE ) : null;
		if ( ! $row || empty( $row->wp_user_id ) ) { self::simple_page( 'Dieser Bestätigungslink ist ungültig oder abgelaufen.' ); exit; }
		$uid = (int) $row->wp_user_id;
		if ( ! get_userdata( $uid ) ) { self::simple_page( 'Konto nicht gefunden.' ); exit; }

		self::purge_m24_data( $uid );
		self::log( 'delete_done', $uid );
		if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
		wp_delete_user( $uid ); // reassign=null → eigene WP-Posts weg; Desk-Rechnungen (extern) unberührt.
		wp_logout();
		self::simple_page( 'Dein Konto wurde gelöscht. Gesetzlich aufzubewahrende Rechnungs-/Auftragsdaten bleiben davon unberührt. Danke, dass du bei MOTORSPORT24 warst.' );
		exit;
	}

	/** M24-eigene Daten des Users aufräumen — NICHT Desk-Rechnungen/Aufträge (§147 AO/§257 HGB). */
	private static function purge_m24_data( int $uid ): void {
		global $wpdb;
		if ( class_exists( 'M24_Garage_Cart' ) ) {
			$wpdb->delete( M24_Garage_Cart::table(), array( 'account_id' => $uid ) );
			M24_Garage_Cart::delete_snapshots( $uid );
		}
		foreach ( array( self::M_KUNDENTYP, self::M_FIRMA, self::M_USTID, self::M_ADDR_BILL, self::M_ADDR_SHIP, self::M_LANG,
			self::M_ALERTS, self::M_MODELLE, self::M_OPTIN, self::M_OPTOUT, 'm24_garage_share_token', 'm24_garage_share_created',
			M24_Garage_Cart::NOTIFY_META ) as $mk ) {
			delete_user_meta( $uid, $mk );
		}
		if ( class_exists( 'M24_Garage_Alerts' ) ) { delete_user_meta( $uid, M24_Garage_Alerts::MASTER_META ); }
	}

	private static function simple_page( string $msg ) {
		status_header( 200 );
		if ( ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); }
		echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>MOTORSPORT24</title><meta name="robots" content="noindex,nofollow">'
			. '<style>body{margin:0;background:#fafafa;font-family:Saira,Arial,sans-serif;color:#14161a}'
			. '.b{max-width:520px;margin:12vh auto;padding:32px 24px;background:#fff;border:1px solid #e6e9ee;border-radius:12px;text-align:center}'
			. '.b p{color:#5a6474;line-height:1.6;margin:0 0 20px}.b a{display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:8px}</style></head>'
			. '<body><div class="b"><p>' . esc_html( $msg ) . '</p><a href="' . esc_url( home_url( '/' ) ) . '">Zur Startseite</a></div></body></html>';
	}

	private static function log( string $step, int $uid = 0 ) {
		if ( class_exists( 'M24_Logger' ) ) { M24_Logger::info( 'account', $step, array( 'user' => $uid ) ); }
	}

	/* ── Render: Zwei-Spalten-Konto-Seite ───────────────────────────────── */

	public static function render_panel( int $acc ): string {
		if ( $acc <= 0 ) { return ''; }
		$u        = get_userdata( $acc );
		$kt       = (string) get_user_meta( $acc, self::M_KUNDENTYP, true ); if ( '' === $kt ) { $kt = 'b2c'; }
		$firma    = (string) get_user_meta( $acc, self::M_FIRMA, true );
		$ustid    = (string) get_user_meta( $acc, self::M_USTID, true );
		$bill     = (array) get_user_meta( $acc, self::M_ADDR_BILL, true );
		$ship     = (array) get_user_meta( $acc, self::M_ADDR_SHIP, true );
		$lang     = (string) get_user_meta( $acc, self::M_LANG, true ); if ( '' === $lang ) { $lang = 'de'; }
		$chips_on  = (array) get_user_meta( $acc, self::M_ALERTS, true );
		$modelle_on = (array) get_user_meta( $acc, self::M_MODELLE, true );
		$doi_date = (string) get_user_meta( $acc, self::M_OPTIN, true );
		$reg      = $u ? $u->user_registered : '';
		$reg_fmt  = $reg ? ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y', strtotime( $reg ) ) : gmdate( 'd.m.Y', strtotime( $reg ) ) ) : '';
		$reg_year = $reg ? gmdate( 'Y', strtotime( $reg ) ) : '';
		$danger   = self::danger_enabled();

		$vehicles = array_values( array_filter( M24_Garage_Cart::items( $acc ), static function ( $it ) { return 'm24_fahrzeug' === $it['post_type']; } ) );
		$notify   = M24_Garage_Cart::notify_all( $acc );
		$master_on = M24_Garage_Cart::notify_master( $acc );

		ob_start();
		?>
		<div class="m24acc" data-m24acc>
			<div class="m24acc-grid">
				<!-- LINKS -->
				<div class="m24acc-col">
					<!-- 1) Deine Daten -->
					<section class="m24acc-card" data-m24acc-profile>
						<h3 class="m24acc-h">Deine Daten<?php if ( $reg_year ) : ?> <span class="m24acc-badge">Mitglied seit <?php echo esc_html( $reg_year ); ?></span><?php endif; ?></h3>
						<label class="m24acc-field"><span>Name</span><input type="text" data-f="name" value="<?php echo esc_attr( $u ? $u->display_name : '' ); ?>" autocomplete="name"></label>
						<label class="m24acc-field"><span>E-Mail (Login)</span><input type="email" value="<?php echo esc_attr( $u ? $u->user_email : '' ); ?>" readonly></label>
						<div class="m24acc-seg" role="radiogroup" aria-label="Kundentyp">
							<button type="button" class="m24acc-segbtn<?php echo 'b2b' === $kt ? ' is-on' : ''; ?>" data-kt="b2b">Gewerblich (B2B)</button>
							<button type="button" class="m24acc-segbtn<?php echo 'b2c' === $kt ? ' is-on' : ''; ?>" data-kt="b2c">Privat (B2C)</button>
						</div>
						<div class="m24acc-b2b"<?php echo 'b2b' === $kt ? '' : ' hidden'; ?> data-m24acc-b2b>
							<label class="m24acc-field"><span>Firmenname</span><input type="text" data-f="firma" value="<?php echo esc_attr( $firma ); ?>"></label>
							<label class="m24acc-field"><span>USt-IdNr.</span><input type="text" data-f="ustid" value="<?php echo esc_attr( $ustid ); ?>"></label>
						</div>
						<?php if ( $reg_fmt ) : ?><p class="m24acc-note">Registriert am <?php echo esc_html( $reg_fmt ); ?></p><?php endif; ?>
						<button type="button" class="m24acc-btn m24acc-btn-blue" data-m24acc-save="profile">Speichern</button>
						<span class="m24acc-status" data-status role="status"></span>
					</section>

					<!-- 2) Anschriften -->
					<section class="m24acc-card" data-m24acc-address>
						<button type="button" class="m24acc-collapse" data-m24acc-toggle="addr" aria-expanded="<?php echo ( $bill || $ship ) ? 'true' : 'false'; ?>">Anschriften <span class="m24acc-caret" aria-hidden="true">▾</span></button>
						<div class="m24acc-addrbody"<?php echo ( $bill || $ship ) ? '' : ' hidden'; ?> data-m24acc-addrbody>
							<?php foreach ( array( 'billing' => array( 'Rechnungsanschrift', $bill ), 'shipping' => array( 'Lieferanschrift', $ship ) ) as $grp => $meta ) : ?>
							<div class="m24acc-addrgrp" data-grp="<?php echo esc_attr( $grp ); ?>">
								<h4 class="m24acc-h4"><?php echo esc_html( $meta[0] ); ?> <span class="m24acc-opt">(optional)</span></h4>
								<label class="m24acc-field"><span>Name / Firma</span><input type="text" data-a="name" value="<?php echo esc_attr( (string) ( $meta[1]['name'] ?? '' ) ); ?>"></label>
								<label class="m24acc-field"><span>Straße &amp; Nr.</span><input type="text" data-a="strasse" value="<?php echo esc_attr( (string) ( $meta[1]['strasse'] ?? '' ) ); ?>"></label>
								<div class="m24acc-row2"><label class="m24acc-field"><span>PLZ</span><input type="text" data-a="plz" value="<?php echo esc_attr( (string) ( $meta[1]['plz'] ?? '' ) ); ?>"></label><label class="m24acc-field"><span>Ort</span><input type="text" data-a="ort" value="<?php echo esc_attr( (string) ( $meta[1]['ort'] ?? '' ) ); ?>"></label></div>
								<label class="m24acc-field"><span>Land</span><input type="text" data-a="land" value="<?php echo esc_attr( (string) ( $meta[1]['land'] ?? '' ) ); ?>"></label>
							</div>
							<?php endforeach; ?>
							<button type="button" class="m24acc-btn m24acc-btn-blue" data-m24acc-save="address">Anschriften speichern</button>
							<span class="m24acc-status" data-status role="status"></span>
						</div>
					</section>

					<!-- 3) Konto -->
					<section class="m24acc-card" data-m24acc-account>
						<h3 class="m24acc-h">Konto</h3>
						<div class="m24acc-field"><span>Sprache</span>
							<div class="m24acc-seg" role="radiogroup" aria-label="Sprache">
								<button type="button" class="m24acc-segbtn<?php echo 'de' === $lang ? ' is-on' : ''; ?>" data-lang="de">Deutsch</button>
								<button type="button" class="m24acc-segbtn<?php echo 'en' === $lang ? ' is-on' : ''; ?>" data-lang="en">English</button>
							</div>
						</div>
						<div class="m24acc-accactions">
							<button type="button" class="m24acc-btn m24acc-btn-ghost" data-m24acc-export<?php echo $danger ? '' : ' disabled'; ?>>Meine Daten exportieren (DSGVO)</button>
							<button type="button" class="m24acc-btn m24acc-btn-danger" data-m24acc-delete<?php echo $danger ? '' : ' disabled'; ?>>Konto löschen</button>
						</div>
						<?php if ( ! $danger ) : ?><p class="m24acc-note">Export &amp; Löschung werden nach der Freischaltung aktiv.</p><?php endif; ?>
						<div class="m24acc-danger" data-m24acc-delbox hidden>
							<p>Konto wirklich löschen? Wir schicken dir zur Sicherheit einen Bestätigungslink per E-Mail. Deine gesetzlich aufzubewahrenden Rechnungen bleiben erhalten.</p>
							<button type="button" class="m24acc-btn m24acc-btn-danger" data-m24acc-delconfirm>Ja, Löschung anfordern</button>
							<button type="button" class="m24acc-btn m24acc-btn-ghost" data-m24acc-delcancel>Abbrechen</button>
						</div>
						<span class="m24acc-status" data-status role="status"></span>
					</section>
				</div>

				<!-- RECHTS -->
				<div class="m24acc-col">
					<!-- 4) Fahrzeug-Alerts -->
					<section class="m24acc-card" data-m24acc-alerts>
						<h3 class="m24acc-h">Fahrzeug-Alerts <span class="m24acc-sub">Du erfährst es zuerst</span></h3>
						<div class="m24acc-chips">
							<?php foreach ( self::chip_map() as $key => $m ) : ?>
							<button type="button" class="m24acc-chip<?php echo in_array( $key, $chips_on, true ) ? ' is-on' : ''; ?>" data-chip="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $m['label'] ); ?></button>
							<?php endforeach; ?>
						</div>
						<h4 class="m24acc-h4">Modelle (Mehrfachauswahl)</h4>
						<div class="m24acc-chips" data-m24acc-modelle>
							<?php foreach ( self::model_terms() as $mname ) : ?>
							<button type="button" class="m24acc-chip m24acc-chip-model<?php echo in_array( $mname, $modelle_on, true ) ? ' is-on' : ''; ?>" data-model="<?php echo esc_attr( $mname ); ?>"><?php echo esc_html( $mname ); ?></button>
							<?php endforeach; ?>
						</div>
						<button type="button" class="m24acc-btn m24acc-btn-blue" data-m24acc-save="alerts">Alerts speichern</button>
						<p class="m24acc-note">Alle Änderungen laufen über Double-Opt-in (Bestätigungslink). Kein automatischer Versand.</p>
						<span class="m24acc-status" data-status role="status"></span>
					</section>

					<!-- 5) Benachrichtigungen zu beobachteten Fahrzeugen (reuse garage.js Pills) -->
					<section class="m24acc-card">
						<h3 class="m24acc-h">Benachrichtigungen</h3>
						<div class="m24gc-notify-master">
							<label class="m24gc-switch">
								<input type="checkbox" data-m24gc-master <?php checked( $master_on ); ?>>
								<span class="m24gc-switch-track" aria-hidden="true"></span>
								<span class="m24gc-switch-label">Alle Benachrichtigungen<?php echo $master_on ? '' : ' (aus)'; ?></span>
							</label>
						</div>
						<?php
						$rows = array();
						foreach ( $vehicles as $it ) {
							$pid = (int) $it['post_id'];
							$p   = isset( $notify[ $pid ] ) && is_array( $notify[ $pid ] ) ? $notify[ $pid ] : array();
							$rows[ $pid ] = array( 'it' => $it, 'price' => ! empty( $p['price'] ), 'sold' => ! empty( $p['sold'] ) );
						}
						if ( empty( $rows ) ) : ?>
							<p class="m24acc-note">Sobald du Fahrzeuge parkst, kannst du hier Preis- &amp; Status-Benachrichtigungen setzen (kein Teile-Alert).</p>
						<?php else : foreach ( $rows as $pid => $r ) : ?>
							<div class="m24gc-ncard" data-m24gc-notify data-post-id="<?php echo esc_attr( $pid ); ?>">
								<a class="m24gc-thumb" href="<?php echo esc_url( $r['it']['url'] ); ?>"><?php if ( $r['it']['thumb'] ) : ?><img src="<?php echo esc_url( $r['it']['thumb'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="m24gc-thumb-ph" aria-hidden="true"></span><?php endif; ?></a>
								<a class="m24gc-title" href="<?php echo esc_url( $r['it']['url'] ); ?>"><?php echo esc_html( $r['it']['title'] ); ?></a>
								<div class="m24gc-vpills">
									<button type="button" class="m24gc-pill<?php echo $r['price'] ? ' is-on' : ''; ?>" data-m24gc-pref="price" aria-pressed="<?php echo $r['price'] ? 'true' : 'false'; ?>"><span class="m24gc-pill-dot" aria-hidden="true"></span>Preisänderung</button>
									<button type="button" class="m24gc-pill<?php echo $r['sold'] ? ' is-on' : ''; ?>" data-m24gc-pref="sold" aria-pressed="<?php echo $r['sold'] ? 'true' : 'false'; ?>"><span class="m24gc-pill-dot" aria-hidden="true"></span>Verkauft / reserviert</button>
								</div>
							</div>
						<?php endforeach; endif; ?>
					</section>

					<!-- 6) Gemerkte Fahrzeuge -->
					<section class="m24acc-card">
						<h3 class="m24acc-h">Gemerkte Fahrzeuge</h3>
						<?php if ( empty( $vehicles ) ) : ?>
							<p class="m24acc-note">Noch keine Fahrzeuge gemerkt.</p>
						<?php else : foreach ( $vehicles as $it ) : $pid = (int) $it['post_id']; ?>
							<div class="m24acc-vrow" data-m24acc-vrow data-post-id="<?php echo esc_attr( $pid ); ?>">
								<a class="m24gc-thumb" href="<?php echo esc_url( $it['url'] ); ?>"><?php if ( $it['thumb'] ) : ?><img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="m24gc-thumb-ph" aria-hidden="true"></span><?php endif; ?></a>
								<div class="m24acc-vinfo"><a class="m24gc-title" href="<?php echo esc_url( $it['url'] ); ?>"><?php echo esc_html( $it['title'] ); ?></a><span class="m24acc-vmeta"><?php echo esc_html( self::vehicle_meta( $pid ) ); ?></span></div>
								<div class="m24acc-vactions">
									<a class="m24acc-vview" href="<?php echo esc_url( $it['url'] ); ?>">ansehen</a>
									<button type="button" class="m24acc-heart is-on" data-m24acc-unfav title="Aus Garage entfernen" aria-label="Aus Garage entfernen">♥</button>
								</div>
							</div>
						<?php endforeach; endif; ?>
					</section>

					<!-- 7) Geteilte Garage-Links -->
					<section class="m24acc-card" data-m24acc-shares>
						<h3 class="m24acc-h">Geteilte Garage-Links</h3>
						<?php
						$tok = M24_Garage_Cart::share_token_existing( $acc );
						if ( '' === $tok ) : ?>
							<p class="m24acc-note">Aktuell kein aktiver Link. Du kannst deine Garage im Tab „Teile-Merkzettel" teilen.</p>
						<?php else :
							$snap  = M24_Garage_Cart::read_snapshot( $tok );
							$n     = $snap ? count( (array) ( $snap['items'] ?? array() ) ) : 0;
							$cr    = (string) get_user_meta( $acc, 'm24_garage_share_created', true );
							$crfmt = $cr ? ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y', strtotime( $cr ) ) : gmdate( 'd.m.Y', strtotime( $cr ) ) ) : '';
							$exp   = $cr ? gmdate( 'd.m.Y', strtotime( $cr . ' +3 months' ) ) : '';
							$surl  = M24_Garage_Cart::share_url( $tok );
							?>
							<div class="m24acc-share" data-m24acc-share>
								<div class="m24acc-share-meta"><strong><?php echo (int) $n; ?> Teile</strong><?php if ( $crfmt ) : ?> · erstellt <?php echo esc_html( $crfmt ); ?><?php endif; ?><?php if ( $exp ) : ?> · gültig bis <?php echo esc_html( $exp ); ?><?php endif; ?></div>
								<div class="m24acc-share-actions">
									<a class="m24acc-vview" href="<?php echo esc_url( $surl ); ?>" target="_blank" rel="noopener">öffnen</a>
									<button type="button" class="m24acc-btn m24acc-btn-ghost" data-m24acc-share-revoke>zurückziehen</button>
								</div>
							</div>
						<?php endif; ?>
					</section>

					<!-- 8) Bestellhistorie (Quelle: m24_offers bezahlt/versandt, rein WP-seitig) -->
					<section class="m24acc-card">
						<h3 class="m24acc-h">Bestellhistorie</h3>
						<?php $orders = class_exists( 'M24_Offers' ) ? M24_Offers::orders_for_account( $acc ) : array(); ?>
						<?php if ( empty( $orders ) ) : ?>
							<p class="m24acc-note">Noch keine Bestellungen.</p>
						<?php else : foreach ( $orders as $ord ) :
							$obadge = 'versandt' === $ord['status'] ? array( 'Versandt', '#1f74c4' ) : array( 'Bezahlt', '#1a7f37' );
							$odate  = $ord['date'] ? ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y', strtotime( $ord['date'] ) ) : gmdate( 'd.m.Y', strtotime( $ord['date'] ) ) ) : '';
							?>
							<div class="m24acc-order">
								<div class="m24acc-order-head">
									<span class="m24acc-order-no">Bestell-Nr. <?php echo esc_html( $ord['offer_no'] ); ?></span>
									<span class="m24acc-order-badge" style="background:<?php echo esc_attr( $obadge[1] ); ?>;"><?php echo esc_html( $obadge[0] ); ?></span>
								</div>
								<div class="m24acc-order-meta"><?php echo esc_html( number_format( $ord['total'], 2, ',', '.' ) ); ?> €<?php if ( $odate ) : ?> · <?php echo esc_html( $odate ); ?><?php endif; ?> · <?php echo (int) $ord['count']; ?> Position<?php echo 1 === (int) $ord['count'] ? '' : 'en'; ?></div>
								<span class="m24acc-order-inv" title="Die Rechnungs-Auslieferung folgt mit M24-Desk" aria-disabled="true">⬇ Rechnung herunterladen <em>(folgt mit M24-Desk)</em></span>
							</div>
						<?php endforeach; endif; ?>
					</section>
				</div>
			</div>

			<!-- FOOTER: §7 UWG -->
			<div class="m24acc-foot" data-m24acc-foot>
				<span class="m24acc-foot-txt"><?php echo $doi_date ? 'Einwilligung (DOI) erteilt am ' . esc_html( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y', strtotime( $doi_date ) ) : gmdate( 'd.m.Y', strtotime( $doi_date ) ) ) . '.' : 'Keine aktive Marketing-Einwilligung hinterlegt.'; ?></span>
				<button type="button" class="m24acc-btn m24acc-btn-ghost" data-m24acc-unsub>Alle Benachrichtigungen &amp; Alerts abbestellen</button>
				<span class="m24acc-status" data-status role="status"></span>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Modell-Terme (Mehrfachauswahl) aus der Fahrzeug-Modell-Taxonomie; robust falls Taxonomie fehlt. */
	private static function model_terms(): array {
		$out = array();
		if ( taxonomy_exists( 'm24_fahrzeugkat' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'm24_fahrzeugkat', 'hide_empty' => false, 'number' => 40 ) );
			if ( ! is_wp_error( $terms ) ) { foreach ( $terms as $t ) { $out[] = $t->name; } }
		}
		return $out;
	}

	/** Kurze Eckdaten-Zeile für ein Fahrzeug (Marke/Baujahr), best-effort aus Meta. */
	private static function vehicle_meta( int $pid ): string {
		$bj = trim( (string) get_post_meta( $pid, '_m24fz_baujahr', true ) );
		$mk = trim( (string) get_post_meta( $pid, '_m24fz_marke', true ) );
		$parts = array_filter( array( $mk, $bj ) );
		return implode( ' · ', $parts );
	}
}
