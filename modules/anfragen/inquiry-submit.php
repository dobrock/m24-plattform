<?php
/**
 * M24 Plattform — Anfragen: REST-Submit-Handler
 * Modul: inquiry-submit.php
 *
 * Zwei REST-Routen (Namespace wie Bestand: m24-plattform/v1):
 *   POST /inquiry          — Einzel-/Produktanfrage aus dem Modal. Ruft die
 *                            BESTEHENDE Pipeline: Validation::validate() ->
 *                            Storage::insert_inquiry() (loest Auto-Push D.1b +
 *                            Auto-Mail-Fallback D.2 aus). KEIN Duplikat.
 *   POST /merkzettel-email — schickt die Sidebar-Liste per wp_mail an die
 *                            Besucheradresse. KEINE Inquiry, KEIN Desk-Push.
 *
 * Auth: Nonce 'wp_rest' (X-WP-Nonce) + permission_callback. Gast-tauglich.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Inquiry_Submit {

	const NS = 'm24-plattform/v1';

	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) { return; }
		self::$initialized = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/inquiry', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_inquiry' ),
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
		) );
		register_rest_route( self::NS, '/merkzettel-email', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_merkzettel_email' ),
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
		) );
	}

	/** Nonce-Pruefung (funktioniert auch fuer ausgeloggte Besucher, uid 0). */
	public static function check_nonce( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( is_string( $nonce ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}
		return new WP_Error( 'm24_bad_nonce', __( 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.', 'm24-plattform' ), array( 'status' => 403 ) );
	}

	// ── /inquiry — "Frage stellen": NUR E-Mail an service@, KEIN CPT/Push ──
	public static function handle_inquiry( WP_REST_Request $request ) {
		$params = $request->get_params();

		// validate() unslasht intern -> wp_slash fuer identische Behandlung wie POST.
		$input = wp_slash( (array) $params );
		$input['inquiry_source'] = M24_Inquiries::SOURCE_PRODUCT;

		$result = M24_Inquiries_Validation::validate( $input );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			if ( 'm24_honeypot' === $code ) {
				return new WP_REST_Response( array( 'ok' => true ), 200 );
			}
			return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'error' => $result->get_error_message() ), 422 );
		}

		// E-Mail an service@ via bestehenden Mail-Builder (kein insert_inquiry, kein Push).
		$sent = M24_Inquiries_Mail_Fallback::send_data( $result, M24_Inquiries_Mail_Fallback::REASON_PRODUCT );
		self::event( 'product_inquiry', array( 'items' => count( $result['items'] ), 'ok' => (bool) $sent ) );
		if ( ! $sent ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'Versand fehlgeschlagen. Bitte später erneut versuchen.', 'm24-plattform' ) ), 500 );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	// ── /merkzettel-email — Warenkorb "Per E-Mail": NUR an service@, kein Push ──
	public static function handle_merkzettel_email( WP_REST_Request $request ) {
		$params = $request->get_params();
		if ( ! empty( $params['website_confirm'] ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}
		// Besucher-Mail optional -> Reply-To, damit der Vertrieb direkt antworten kann.
		$email = sanitize_email( (string) ( $params['email'] ?? '' ) );

		$items_raw = $params['items'] ?? array();
		if ( is_string( $items_raw ) ) {
			$decoded   = json_decode( $items_raw, true );
			$items_raw = is_array( $decoded ) ? $decoded : array();
		}
		$items = M24_Inquiries_Form::sanitize_items( (array) $items_raw );
		if ( empty( $items ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'Ihr Merkzettel ist leer.', 'm24-plattform' ) ), 422 );
		}

		$sent = M24_Inquiries_Mail_Fallback::send_data( array(
			'email'          => is_email( $email ) ? $email : '',
			'items'          => $items,
			'inquiry_source' => M24_Inquiries::SOURCE_CART,
		), M24_Inquiries_Mail_Fallback::REASON_MERKZETTEL );

		self::event( 'merkzettel_email', array( 'count' => count( $items ), 'ok' => (bool) $sent ) );
		if ( ! $sent ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => __( 'Versand fehlgeschlagen. Bitte später erneut versuchen.', 'm24-plattform' ) ), 500 );
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** First-Party-Event: Hook fuer spaetere Listener + Logger in die sync_log-Tabelle. */
	private static function event( $name, $data ) {
		do_action( 'm24_event', $name, $data );
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'inquiry_event', (string) $name, (array) $data );
		}
	}
}
