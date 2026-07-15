<?php
/**
 * M24 Angebots-Annahme — Login-Gate + Magic-Link-Rückkehr.
 *
 * Verbindliche Annahme (= Kaufvertrag) NUR mit eingeloggtem Nutzer, dessen E-Mail der im Angebot hinterlegten
 * Kunden-E-Mail entspricht (verhindert Annahme über weitergeleitete Links). Für Gäste wird ein passwortloser
 * Magic-Link an die Angebots-E-Mail geschickt (bestehende M24_Login-Strecke) mit Rückkehr auf ?m24_angebot={token}.
 *
 * Verantwortung dieser Datei: Annahme-Gate-Helfer + Login-Anforderung/-Rückkehr. Der Statuswechsel/Tabellenzugriff
 * bleibt in M24_Offers::handle_accept.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Accept {

	const NS       = 'm24/v1';
	const RT_META  = '_m24_offer_login_rt'; // User-Meta: Rückkehr-URL nach Magic-Link-Login (einmalig)

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'm24_login_verify_dest', array( __CLASS__, 'login_return_dest' ), 10, 2 );
	}

	public static function register_routes() {
		// Gast-Annahme-Vorstufe: Magic-Link an die Angebots-E-Mail schicken. Öffentlich + Token (kein Login nötig).
		register_rest_route( self::NS, '/offers/request-login', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'handle_request_login' ),
		) );
	}

	/** E-Mail-Bindung: darf der aktuelle Nutzer dieses Angebot annehmen? (eingeloggt UND E-Mail == Angebots-E-Mail). */
	public static function may_accept( $offer ): bool {
		if ( ! is_user_logged_in() ) { return false; }
		$u = wp_get_current_user();
		return self::same_email( (string) $u->user_email, self::offer_email( $offer ) );
	}

	public static function offer_email( $offer ): string {
		$cust = json_decode( (string) $offer->customer_json, true );
		$cust = is_array( $cust ) ? $cust : array();
		return strtolower( trim( (string) ( $cust['email'] ?? '' ) ) );
	}

	private static function same_email( string $a, string $b ): bool {
		$a = strtolower( trim( $a ) ); $b = strtolower( trim( $b ) );
		return '' !== $a && $a === $b;
	}

	/**
	 * POST /offers/request-login — { token } → Magic-Link an die Angebots-E-Mail + Rückkehr auf die Angebotsseite.
	 * Neukunden: Konto wird per Magic-Link angelegt; Bestandskunden: Login. Antwort ist bewusst neutral (kein
	 * Enumeration-Leak über die Existenz eines Kontos).
	 */
	public static function handle_request_login( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24oa_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		if ( ! class_exists( 'M24_Offers' ) || ! class_exists( 'M24_Login' ) || ! M24_Login::enabled() ) {
			return new WP_Error( 'm24oa_off', 'Nicht verfügbar.', array( 'status' => 400 ) );
		}
		$o = M24_Offers::get_by_token( (string) $req->get_param( 'token' ) );
		if ( ! $o || 'entwurf' === (string) $o->status ) {
			return new WP_Error( 'm24oa_nf', 'Angebot nicht gefunden.', array( 'status' => 404 ) );
		}
		$email = self::offer_email( $o );
		$cust  = json_decode( (string) $o->customer_json, true ); $cust = is_array( $cust ) ? $cust : array();
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'm24oa_mail', 'Keine gültige E-Mail am Angebot.', array( 'status' => 400 ) );
		}
		// Rückkehr-Ziel = diese Angebotsseite + accept=1 (der Client setzt nach der Rückkehr sofort das Adressformular fort).
		$return = add_query_arg( 'accept', '1', M24_Offers::view_url( (string) $o->token ) );
		// PRIMÄR: Ziel FEST im Magic-Link (?rt=, an genau diesen Klick gebunden). SEKUNDÄR: User-Meta-Fallback.
		$ok = M24_Login::create_account_and_send_link( $email, trim( (string) ( $cust['name'] ?? '' ) ), false, $return );
		if ( ! $ok ) { return new WP_Error( 'm24oa_send', 'Versand fehlgeschlagen. Bitte später erneut.', array( 'status' => 429 ) ); }
		$u = get_user_by( 'email', $email );
		if ( $u ) { update_user_meta( (int) $u->ID, self::RT_META, esc_url_raw( $return ) ); }
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Filter m24_login_verify_dest: nach dem Login einmalig auf die vermerkte Angebotsseite zurückführen. */
	public static function login_return_dest( $dest, $uid ) {
		$rt = (string) get_user_meta( (int) $uid, self::RT_META, true );
		if ( '' !== $rt ) {
			delete_user_meta( (int) $uid, self::RT_META ); // einmalig
			// Nur Same-Host-Ziele zulassen (Defense-in-Depth; wp_safe_redirect prüft zusätzlich).
			$host = wp_parse_url( $rt, PHP_URL_HOST );
			if ( $host && $host === wp_parse_url( home_url(), PHP_URL_HOST ) ) { return $rt; }
		}
		return $dest;
	}
}
