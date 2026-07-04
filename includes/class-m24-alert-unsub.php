<?php
/**
 * M24 §7-UWG-Opt-out für Fahrzeug-Alerts: signierter 1-Klick-Abmelde-Link (kein Login) im Mailfooter.
 *
 * Token = HMAC-SHA256 über (E-Mail|Scope) mit wp_salt('auth') — stateless, kein DB-Eintrag, idempotent.
 * Landing (template_redirect) verifiziert, meldet ab (Konto-Master aus + Brevo unlink/blacklist) und
 * protokolliert DSGVO-konform über M24_Error_Log (PII maskiert). Mehrfachaufruf ist unschädlich.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Alert_Unsub {

	const QV           = 'm24_unsub';
	const SCOPE_ALERTS = 'alerts';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 3 );
	}

	private static function secret(): string {
		return wp_salt( 'auth' ) . '|m24-alert-unsub-v1';
	}
	public static function sign( string $email, string $scope ): string {
		return substr( hash_hmac( 'sha256', strtolower( trim( $email ) ) . '|' . $scope, self::secret() ), 0, 32 );
	}
	/** Signierter 1-Klick-Abmelde-Link für einen Empfänger. */
	public static function url( string $email, string $scope = self::SCOPE_ALERTS ): string {
		$email = strtolower( trim( $email ) );
		return add_query_arg( array(
			self::QV => '1',
			'e'      => rawurlencode( $email ),
			's'      => $scope,
			'k'      => self::sign( $email, $scope ),
		), home_url( '/' ) );
	}

	public static function handle() {
		if ( empty( $_GET[ self::QV ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		nocache_headers();
		if ( ! headers_sent() ) { header( 'X-Robots-Tag: noindex', true ); }

		$email = isset( $_GET['e'] ) ? strtolower( sanitize_email( wp_unslash( $_GET['e'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$scope = isset( $_GET['s'] ) ? sanitize_key( wp_unslash( $_GET['s'] ) ) : '';                 // phpcs:ignore WordPress.Security.NonceVerification
		$k     = isset( $_GET['k'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$valid = ( '' !== $email && is_email( $email ) && '' !== $scope && '' !== $k && hash_equals( self::sign( $email, $scope ), $k ) );
		if ( $valid ) { self::unsubscribe( $email, $scope ); }
		self::render( $valid );
		exit;
	}

	/** Abmeldung ausführen — idempotent: Konto-Master aus + Brevo unlink/blacklist + DSGVO-Log. */
	private static function unsubscribe( string $email, string $scope ) {
		// 1) Konto-seitig: Master-Schalter aus (falls WP-Konto zur Adresse existiert).
		$u = get_user_by( 'email', $email );
		if ( $u && class_exists( 'M24_Garage_Alerts' ) ) {
			update_user_meta( (int) $u->ID, M24_Garage_Alerts::MASTER_META, '0' );
			update_user_meta( (int) $u->ID, '_m24_alerts_optout', current_time( 'mysql', true ) );
		}
		// 2) Brevo: aus allen bekannten Listen entfernen + blacklisten (§7 = keine Marketing-Mails mehr). Idempotent.
		if ( class_exists( 'M24_Brevo_Client' ) && M24_Brevo_Client::is_configured() ) {
			$lists = array_map( 'intval', (array) M24_Brevo_Client::all_known_list_ids() );
			M24_Brevo_Client::update_contact( $email, array( 'emailBlacklisted' => true, 'unlinkListIds' => $lists ) );
		}
		// 3) DSGVO-Nachweis (PII wird von M24_Error_Log maskiert).
		if ( class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'alert_optout', 'info', 'Fahrzeug-Alert §7-Opt-out bestätigt (1-Klick)', array(
				'email' => $email, 'scope' => $scope, 'account' => $u ? (int) $u->ID : 0,
			) );
		}
	}

	private static function render( bool $ok ) {
		status_header( 200 );
		if ( ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); }
		$logo = esc_url( apply_filters( 'm24fz_mail_logo_url', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2023/09/Logo-MOTORSPORT24.de_.gif' ) );
		$msg  = $ok
			? 'Du bist erfolgreich abgemeldet. Du erhältst keine Fahrzeug-Alerts mehr. Falls du deine Meinung änderst, kannst du die Benachrichtigungen in deinem Konto jederzeit wieder aktivieren.'
			: 'Dieser Abmelde-Link ist ungültig oder unvollständig. Bitte nutze den Link aus einer aktuellen Benachrichtigungs-E-Mail oder verwalte deine Benachrichtigungen im Konto.';
		echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>Abmeldung – MOTORSPORT24</title><meta name="robots" content="noindex,nofollow">'
			. '<style>html,body{margin:0}body{background:#fafafa;font-family:Saira,Arial,sans-serif;color:#14161a}'
			. '.b{background:#1f74c4;background:linear-gradient(135deg,#1f74c4,#0e447e);padding:16px 24px;text-align:right}.b img{height:30px}'
			. '.c{max-width:520px;margin:10vh auto;padding:32px 24px;background:#fff;border:1px solid #e6e9ee;border-radius:12px;text-align:center}'
			. '.c h1{font-size:22px;margin:0 0 12px}.c p{color:#5a6474;line-height:1.6;margin:0 0 20px}'
			. '.c a{display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:8px}</style></head>'
			. '<body><div class="b"><img src="' . $logo . '" alt="MOTORSPORT24"></div>'
			. '<div class="c"><h1>' . ( $ok ? 'Abgemeldet ✓' : 'Abmeldung nicht möglich' ) . '</h1><p>' . esc_html( $msg ) . '</p>'
			. '<a href="' . esc_url( home_url( '/' ) ) . '">Zur Startseite</a></div></body></html>';
	}
}
