<?php
/**
 * M24 Fahrzeug — Tracking-Zähler (views/merkliste/anfragen/tel-klicks)
 * Modul: includes/fahrzeug/class-m24fz-tracking.php
 *
 * Views cache-sicher per JS-Beacon (REST), 1×/Session via Cookie-Dedup. Aktionen
 * (merken/anfrage/tel) per AJAX-Action. Zähler sind editierbar (Meta in der Box).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Tracking {

	public static function init() {
		add_action( 'wp_ajax_nopriv_m24fz_track', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_m24fz_track', array( __CLASS__, 'ajax' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	/** Cache-sicherer Aufrufe-Zähler (§6.1): REST-Beacon, läuft auch bei WP-Rocket-Cache. */
	public static function register_rest() {
		register_rest_route( 'm24/v1', '/view-ping', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'rest_view_ping' ),
			'args'                => array( 'post_id' => array( 'required' => true ) ),
		) );
	}

	public static function rest_view_ping( $req ) {
		$pid = (int) $req['post_id'];
		if ( ! $pid || M24FZ_CPT::PT !== get_post_type( $pid ) ) { return new WP_Error( 'm24fz_bad', 'invalid', array( 'status' => 400 ) ); }
		// Admins/Redakteure nicht zählen.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) { return rest_ensure_response( array( 'counted' => false, 'reason' => 'staff' ) ); }
		// Bots grob ausschließen.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|bing|google|facebookexternalhit|embedly|preview|monitor|pingdom|lighthouse/i', $ua ) ) {
			return rest_ensure_response( array( 'counted' => false, 'reason' => 'bot' ) );
		}
		// Session-Throttle (Cookie, 6h) → kein Reload-Spam.
		$ck = 'm24fz_v_' . $pid;
		if ( isset( $_COOKIE[ $ck ] ) ) { return rest_ensure_response( array( 'counted' => false, 'reason' => 'dup' ) ); }
		setcookie( $ck, '1', time() + 6 * HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/' );
		$new = (int) get_post_meta( $pid, '_m24fz_views', true ) + 1;
		update_post_meta( $pid, '_m24fz_views', $new );
		return rest_ensure_response( array( 'counted' => true, 'value' => $new ) );
	}

	/** Erlaubte Felder → Meta-Key. */
	private static function keys() {
		return array(
			'view'     => '_m24fz_views',
			'merken'   => '_m24fz_merkliste_count',
			'anfrage'  => '_m24fz_anfragen_count',
			'tel'      => '_m24fz_tel_klicks',
		);
	}

	public static function ajax() {
		$pid  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$what = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		$keys = self::keys();
		if ( ! $pid || M24FZ_CPT::PT !== get_post_type( $pid ) || ! isset( $keys[ $what ] ) ) {
			wp_send_json_error( array( 'message' => 'ungültig' ) );
		}
		// View: 1×/Session je Fahrzeug (Cookie-Dedup) → kein Reload-Spam, cache-safe (Beacon läuft auch bei Rocket-Cache).
		if ( 'view' === $what ) {
			$ck = 'm24fz_v_' . $pid;
			if ( isset( $_COOKIE[ $ck ] ) ) { wp_send_json_success( array( 'counted' => false ) ); }
			setcookie( $ck, '1', time() + 6 * HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/' );
		}
		$key = $keys[ $what ];
		$new = (int) get_post_meta( $pid, $key, true ) + 1;
		update_post_meta( $pid, $key, $new );
		wp_send_json_success( array( 'counted' => true, 'value' => $new ) );
	}

	public static function get( $post_id, $what ) {
		$keys = self::keys();
		return isset( $keys[ $what ] ) ? (int) get_post_meta( (int) $post_id, $keys[ $what ], true ) : 0;
	}

	/**
	 * INTEGRATIONSPUNKT (§6.2/§6.3): vom echten Merklisten-/Anfrage-Capture aufrufen, sobald live.
	 * Beispiel: M24FZ_Tracking::increment( $fahrzeug_id, 'merken' ); bzw. 'anfrage'.
	 * Bis dahin zählen die Klick-Beacons (data-m24fz-track) als Näherung.
	 */
	public static function increment( $post_id, $what ) {
		$keys = self::keys();
		if ( ! isset( $keys[ $what ] ) || M24FZ_CPT::PT !== get_post_type( (int) $post_id ) ) { return; }
		$key = $keys[ $what ];
		update_post_meta( (int) $post_id, $key, (int) get_post_meta( (int) $post_id, $key, true ) + 1 );
	}
}
