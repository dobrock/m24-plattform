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
}
