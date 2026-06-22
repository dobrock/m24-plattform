<?php
/**
 * M24 Plattform — Brevo-API-Client (Phase 2)
 *
 * Schlanker Wrapper um wp_remote_* gegen die Brevo-API (v3).
 *   - Auth-Header `api-key` (aus Option m24_brevo_api_key ODER Konstante M24_BREVO_API_KEY)
 *   - Basis https://api.brevo.com/v3, Content-Type application/json, Timeout 15 s
 *   - Eine Upsert-Methode (POST /v3/contacts, updateEnabled:true → create-or-update, idempotent)
 *   - Jeder Call ins Sync-Log (Context 'brevo', E-Mail maskiert) — kein stiller Datenverlust
 *
 * Einheitliches Result-Format:  [ 'ok' => bool, 'code' => int, 'msg' => string, 'data' => array|null ]
 *
 * Der API-Key wird NIE im Klartext gerendert (s. M24_Settings) und nicht ins Log geschrieben.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Brevo_Client {

	const API_BASE    = 'https://api.brevo.com/v3';
	const OPTION_KEY  = 'm24_brevo_api_key';
	const LIST_ID     = 3; // Interessentenliste
	const TIMEOUT     = 15;

	/**
	 * Effektiver API-Key. Vorrang: wp-config.php-Konstante M24_BREVO_API_KEY → DB-Option.
	 * Leerer String, wenn nichts gesetzt.
	 */
	public static function api_key() {
		if ( defined( 'M24_BREVO_API_KEY' ) && '' !== (string) M24_BREVO_API_KEY ) {
			return (string) M24_BREVO_API_KEY;
		}
		return trim( (string) get_option( self::OPTION_KEY, '' ) );
	}

	/** Ist der Key via Konstante gesetzt (read-only im Backend)? */
	public static function key_locked_by_config() {
		return defined( 'M24_BREVO_API_KEY' ) && '' !== (string) M24_BREVO_API_KEY;
	}

	/** Key konfiguriert (egal ob Konstante oder Option)? */
	public static function is_configured() {
		return '' !== self::api_key();
	}

	/**
	 * Maskierte Anzeige des Keys: nur die letzten 4 Zeichen sichtbar.
	 * Leerer Key → leerer String (Aufrufer entscheidet über Platzhalter).
	 */
	public static function masked_key() {
		$key = self::api_key();
		if ( '' === $key ) {
			return '';
		}
		$tail = strlen( $key ) > 4 ? substr( $key, -4 ) : '';
		return '••••••••••••' . $tail;
	}

	/**
	 * Verbindungstest: GET /v3/account.
	 *
	 * @param string|null $override_key  Optionaler Key (z. B. frisch eingetippt, noch nicht gespeichert).
	 * @return array Result-Format; data['email'] = Account-E-Mail bei Erfolg.
	 */
	public static function account( $override_key = null ) {
		return self::request( 'GET', '/account', null, $override_key );
	}

	/**
	 * Kontakt anlegen oder aktualisieren (idempotent) und der Liste zuordnen.
	 * POST /v3/contacts mit updateEnabled:true.
	 *
	 * @param string $email       Kontakt-E-Mail.
	 * @param array  $attributes  Brevo-Attribute (NAME, KUNDENTYP, MODELLE, KATEGORIEN, ALLE, ...).
	 * @param array  $list_ids    Listen-IDs (Default [3]).
	 * @return array Result-Format.
	 */
	public static function upsert_contact( $email, $attributes, $list_ids = array( self::LIST_ID ) ) {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return array( 'ok' => false, 'code' => 0, 'msg' => 'Ungültige E-Mail', 'data' => null );
		}

		$payload = array(
			'email'         => $email,
			'attributes'    => (object) $attributes,
			'listIds'       => array_values( array_map( 'intval', (array) $list_ids ) ),
			'updateEnabled' => true,
		);

		$res = self::request( 'POST', '/contacts', $payload );

		// Strukturiertes Sync-Log je Call — E-Mail maskiert, kein Key, keine PII im Klartext.
		$log = array(
			'email'    => self::mask_email( $email ),
			'listIds'  => $payload['listIds'],
			'attrKeys' => array_keys( $attributes ),
			'code'     => $res['code'],
		);
		if ( $res['ok'] ) {
			M24_Logger::info( 'brevo', 'Kontakt-Upsert OK (' . self::mask_email( $email ) . ')', $log );
		} else {
			$log['msg'] = $res['msg'];
			M24_Logger::error( 'brevo', 'Kontakt-Upsert FEHLER (' . self::mask_email( $email ) . ')', $log );
		}

		return $res;
	}

	/**
	 * Generischer Request gegen die Brevo-API.
	 *
	 * @param string      $method        GET|POST|PUT|DELETE
	 * @param string      $path          z. B. /account, /contacts
	 * @param array|null  $body          JSON-Body (POST/PUT)
	 * @param string|null $override_key  Optionaler Key statt der gespeicherten Konfiguration.
	 * @return array Result-Format.
	 */
	private static function request( $method, $path, $body = null, $override_key = null ) {
		$key = is_string( $override_key ) && '' !== trim( $override_key ) ? trim( $override_key ) : self::api_key();
		if ( '' === $key ) {
			return array( 'ok' => false, 'code' => 0, 'msg' => 'Brevo API-Key nicht gesetzt', 'data' => null );
		}

		$args = array(
			'method'      => strtoupper( $method ),
			'timeout'     => self::TIMEOUT,
			'redirection' => 2,
			'sslverify'   => true,
			'headers'     => array(
				'api-key' => $key,
				'accept'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['content-type'] = 'application/json';
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'code' => 0, 'msg' => 'Netzwerk-Fehler: ' . $response->get_error_message(), 'data' => null );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = ( '' !== $raw ) ? json_decode( $raw, true ) : null;
		$ok   = ( $code >= 200 && $code < 300 );

		return array(
			'ok'   => $ok,
			'code' => $code,
			'msg'  => $ok ? 'OK' : self::error_msg( $code, $data, $raw ),
			'data' => is_array( $data ) ? $data : null,
		);
	}

	/** Sinnvolle Fehlermeldung aus dem Brevo-Response ziehen ({code,message}). */
	private static function error_msg( $code, $data, $raw ) {
		if ( is_array( $data ) && isset( $data['message'] ) ) {
			return 'HTTP ' . $code . ': ' . (string) $data['message'];
		}
		if ( '' !== $raw ) {
			return 'HTTP ' . $code . ': ' . substr( $raw, 0, 200 );
		}
		return 'HTTP ' . $code;
	}

	/** E-Mail für Logs maskieren: erstes Zeichen + Domain (m****@motorsport24.de). */
	public static function mask_email( $email ) {
		$email = (string) $email;
		$at    = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '***';
		}
		return substr( $email, 0, 1 ) . str_repeat( '*', max( 1, $at - 1 ) ) . substr( $email, $at );
	}
}
