<?php
/**
 * M24 Plattform — Nutzer-Aktivität & Herkunft (Tracking).
 *
 * Verantwortung dieser Datei: schreibt ausschließlich zwei User-Metas —
 *   _m24_last_login   (int, time())  → letzter Login (wp_login UND Magic-Link-Verify)
 *   _m24_signup_source (string)      → wie das Konto entstand (offer|magic_link|alert_doi|manual)
 *
 * Historisch nicht vorhanden: Bestandsnutzer bleiben leer (→ „nie eingeloggt" / „unbekannt")
 * bis zum nächsten Login bzw. bis eine Anlage die Quelle setzt. Erst ab jetzt zuverlässig.
 *
 * Gelesen wird das in der Kundenkonten-Übersicht (M24_Haendler_Page) — dort NUR Anzeige, kein Schreiben.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_User_Activity {

	const LOGIN_META  = '_m24_last_login';
	const SOURCE_META = '_m24_signup_source';

	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'user_register', array( __CLASS__, 'on_register' ), 20, 1 );
	}

	/** Standard-Login (wp-login/Formular) → Last-Login stempeln. */
	public static function on_login( $user_login, $user ) {
		if ( $user instanceof WP_User && $user->ID ) {
			self::mark_login( (int) $user->ID );
		}
	}

	/** Magic-Link-Verify ruft das direkt nach wp_set_auth_cookie() auf (kein wp_login-Event dort). */
	public static function mark_login( int $uid ): void {
		if ( $uid > 0 ) {
			update_user_meta( $uid, self::LOGIN_META, time() );
		}
	}

	/**
	 * Konto wird im wp-admin manuell angelegt → Herkunft „manuell" (nur wenn noch keine Quelle gesetzt ist).
	 * Frontend-Anlagen (Magic-Link/REST) laufen NICHT über is_admin() → dort setzt der jeweilige Flow die Quelle
	 * anschließend via set_source() (offer/magic_link/…), ohne dass hier fälschlich „manuell" landet.
	 */
	public static function on_register( $uid ) {
		$uid = (int) $uid;
		if ( $uid > 0 && is_admin() && '' === (string) get_user_meta( $uid, self::SOURCE_META, true ) ) {
			update_user_meta( $uid, self::SOURCE_META, 'manual' );
		}
	}

	/** Herkunft einmalig festschreiben (überschreibt eine bereits gesetzte Quelle NICHT). */
	public static function set_source( int $uid, string $source ): void {
		$source = sanitize_key( $source );
		if ( $uid > 0 && '' !== $source && '' === (string) get_user_meta( $uid, self::SOURCE_META, true ) ) {
			update_user_meta( $uid, self::SOURCE_META, $source );
		}
	}

	/** Schlüssel → DE-Label für die Herkunft-Spalte. Leere/unbekannte Quelle → „unbekannt". */
	public static function source_label( string $source ): string {
		$map = array(
			'offer'      => 'Angebots-Magic-Link',
			'magic_link' => 'Magic-Link',
			'alert_doi'  => 'Alert-DOI',
			'manual'     => 'manuell',
		);
		$source = sanitize_key( $source );
		return $map[ $source ] ?? 'unbekannt';
	}
}
