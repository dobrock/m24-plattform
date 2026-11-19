<?php
/**
 * M24 Plattform — Anmeldung per Fingerabdruck / Face ID (Passkey, WebAuthn) — 19.11.2026
 *
 * WARUM (Daniel, 19.11.2026): „Ich will mich hier einfach per Fingerabdruck einloggen können" — Touch ID am Mac mini
 * und MacBook, Face ID am iPhone; E-Mail + Passwort nur noch als Link darunter.
 *
 * WIE: Standard-WebAuthn mit auffindbaren Schlüsseln (kein Benutzername nötig).
 *   Einrichten (nur angemeldet): POST m24/v1/passkey/reg-optionen → Browser erzeugt den Schlüssel → reg-fertig
 *     speichert Kennung + öffentlichen Schlüssel am Benutzer. Der private Schlüssel verlässt das Gerät nie.
 *   Anmelden (öffentlich): POST auth-optionen (Einmal-Challenge, 5 min) → Gerät signiert → auth-fertig prüft
 *     Challenge, Herkunft, rpId-Hash, „Nutzer geprüft" (Fingerabdruck/Gesicht) und die Signatur gegen den
 *     gespeicherten öffentlichen Schlüssel. Erst dann wird das Anmelde-Cookie gesetzt (30 Tage).
 * Kein Passwort wird hier verarbeitet oder gespeichert.
 *
 * @package m24-plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Passkey {

	const META_ID  = 'm24_passkey_id';   // je Schlüssel eine Zeile: Kennung (base64url) — zum Auffinden des Benutzers
	const META_KEY = 'm24_passkey_';     // + md5(Kennung): array( id, pem, alg, zaehler, am, geraet )

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routen' ) );
	}

	private static function b64u_dec( string $s ) {
		$s = strtr( $s, '-_', '+/' );
		return base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
	}
	private static function b64u_enc( string $b ): string { return rtrim( strtr( base64_encode( $b ), '+/', '-_' ), '=' ); }

	private static function rp_id(): string { return (string) wp_parse_url( home_url(), PHP_URL_HOST ); }
	private static function herkunft(): string {
		$u = wp_parse_url( home_url() );
		return ( $u['scheme'] ?? 'https' ) . '://' . ( $u['host'] ?? '' ) . ( isset( $u['port'] ) ? ':' . $u['port'] : '' );
	}

	private static function rate_ok(): bool {
		$k = 'm24_pk_rl_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$n = (int) get_transient( $k );
		if ( $n >= 20 ) { return false; }
		set_transient( $k, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	public static function hat_schluessel( int $uid ): bool { return (bool) get_user_meta( $uid, self::META_ID, false ); }

	public static function routen() {
		$an = function () { return is_user_logged_in(); };
		register_rest_route( 'm24/v1', '/passkey/reg-optionen', array( 'methods' => 'POST', 'permission_callback' => $an, 'callback' => array( __CLASS__, 'reg_optionen' ) ) );
		register_rest_route( 'm24/v1', '/passkey/reg-fertig', array( 'methods' => 'POST', 'permission_callback' => $an, 'callback' => array( __CLASS__, 'reg_fertig' ) ) );
		register_rest_route( 'm24/v1', '/passkey/auth-optionen', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'auth_optionen' ) ) );
		register_rest_route( 'm24/v1', '/passkey/auth-fertig', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'auth_fertig' ) ) );
	}

	/** clientDataJSON prüfen: Typ, Challenge (Einmalwert) und Herkunft. Rückgabe true oder Fehlertext. */
	private static function client_daten( string $roh, string $typ, string $challenge ) {
		$c = json_decode( $roh, true );
		if ( ! is_array( $c ) || ( $c['type'] ?? '' ) !== $typ ) { return 'Falscher Vorgang.'; }
		if ( ! hash_equals( $challenge, (string) ( $c['challenge'] ?? '' ) ) ) { return 'Die Anfrage ist abgelaufen.'; }
		if ( ! hash_equals( self::herkunft(), (string) ( $c['origin'] ?? '' ) ) ) { return 'Falsche Herkunft.'; }
		return true;
	}

	/** authenticatorData prüfen: rpId-Hash, „anwesend" und „geprüft". Rückgabe Zähler (int) oder Fehlertext. */
	private static function auth_daten( string $ad ) {
		if ( strlen( $ad ) < 37 ) { return 'Unvollständige Antwort.'; }
		if ( ! hash_equals( hash( 'sha256', self::rp_id(), true ), substr( $ad, 0, 32 ) ) ) { return 'Falsche Seite.'; }
		$flags = ord( $ad[32] );
		if ( 0x05 !== ( $flags & 0x05 ) ) { return 'Fingerabdruck oder Face ID wurde nicht bestätigt.'; }
		$z = unpack( 'N', substr( $ad, 33, 4 ) );
		return (int) $z[1];
	}

	public static function reg_optionen() {
		$u  = wp_get_current_user();
		$ch = self::b64u_enc( random_bytes( 32 ) );
		set_transient( 'm24_pk_reg_' . $u->ID, $ch, 5 * MINUTE_IN_SECONDS );
		$aus = array();
		foreach ( (array) get_user_meta( $u->ID, self::META_ID, false ) as $id ) { $aus[] = array( 'type' => 'public-key', 'id' => (string) $id ); }
		return array(
			'challenge' => $ch,
			'rp'        => array( 'id' => self::rp_id(), 'name' => 'MOTORSPORT24' ),
			'user'      => array( 'id' => self::b64u_enc( 'm24-' . $u->ID ), 'name' => (string) $u->user_email, 'displayName' => (string) $u->display_name ),
			'pubKeyCredParams' => array( array( 'type' => 'public-key', 'alg' => -7 ), array( 'type' => 'public-key', 'alg' => -257 ) ),
			'authenticatorSelection' => array( 'residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required' ),
			'attestation' => 'none', 'timeout' => 120000, 'excludeCredentials' => $aus,
		);
	}

	public static function reg_fertig( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$ch  = (string) get_transient( 'm24_pk_reg_' . $uid );
		delete_transient( 'm24_pk_reg_' . $uid );
		if ( '' === $ch ) { return new WP_Error( 'm24_pk', 'Die Anfrage ist abgelaufen. Bitte noch einmal.', array( 'status' => 400 ) ); }
		$cd = self::b64u_dec( (string) $r->get_param( 'clientDataJSON' ) );
		$ok = self::client_daten( (string) $cd, 'webauthn.create', $ch );
		if ( true !== $ok ) { return new WP_Error( 'm24_pk', $ok, array( 'status' => 400 ) ); }
		$ad = self::b64u_dec( (string) $r->get_param( 'authenticatorData' ) );
		$z  = self::auth_daten( (string) $ad );
		if ( ! is_int( $z ) ) { return new WP_Error( 'm24_pk', $z, array( 'status' => 400 ) ); }
		$id  = (string) $r->get_param( 'id' );
		$alg = (int) $r->get_param( 'alg' );
		$der = self::b64u_dec( (string) $r->get_param( 'publicKey' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,1024}$/', $id ) || ! in_array( $alg, array( -7, -257 ), true ) || ! $der ) { return new WP_Error( 'm24_pk', 'Unvollständige Antwort.', array( 'status' => 400 ) ); }
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		$key = openssl_pkey_get_public( $pem );
		if ( ! $key ) { return new WP_Error( 'm24_pk', 'Der Schlüssel konnte nicht gelesen werden.', array( 'status' => 400 ) ); }
		if ( get_users( array( 'meta_key' => self::META_ID, 'meta_value' => $id, 'fields' => 'ids', 'number' => 1 ) ) ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			return new WP_Error( 'm24_pk', 'Dieses Gerät ist schon eingerichtet.', array( 'status' => 409 ) );
		}
		add_user_meta( $uid, self::META_ID, $id );
		update_user_meta( $uid, self::META_KEY . md5( $id ), array(
			'id' => $id, 'pem' => $pem, 'alg' => $alg, 'zaehler' => $z, 'am' => gmdate( 'Y-m-d H:i' ),
			'geraet' => mb_substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 120 ),
		) );
		return array( 'ok' => true );
	}

	public static function auth_optionen() {
		if ( ! self::rate_ok() ) { return new WP_Error( 'm24_pk', 'Zu viele Versuche. Bitte kurz warten.', array( 'status' => 429 ) ); }
		$ch = self::b64u_enc( random_bytes( 32 ) );
		set_transient( 'm24_pk_ch_' . md5( $ch ), 1, 5 * MINUTE_IN_SECONDS );
		return array( 'challenge' => $ch, 'rpId' => self::rp_id(), 'userVerification' => 'required', 'timeout' => 120000 );
	}

	public static function auth_fertig( WP_REST_Request $r ) {
		$fehler = function ( string $t ) { return new WP_Error( 'm24_pk', $t, array( 'status' => 401 ) ); };
		if ( ! self::rate_ok() ) { return new WP_Error( 'm24_pk', 'Zu viele Versuche. Bitte kurz warten.', array( 'status' => 429 ) ); }
		$cd = (string) self::b64u_dec( (string) $r->get_param( 'clientDataJSON' ) );
		$c  = json_decode( $cd, true );
		$ch = is_array( $c ) ? (string) ( $c['challenge'] ?? '' ) : '';
		$tk = 'm24_pk_ch_' . md5( $ch );
		if ( '' === $ch || ! get_transient( $tk ) ) { return $fehler( 'Die Anfrage ist abgelaufen. Bitte noch einmal.' ); }
		delete_transient( $tk ); // Einmalwert
		$ok = self::client_daten( $cd, 'webauthn.get', $ch );
		if ( true !== $ok ) { return $fehler( $ok ); }
		$ad = (string) self::b64u_dec( (string) $r->get_param( 'authenticatorData' ) );
		$z  = self::auth_daten( $ad );
		if ( ! is_int( $z ) ) { return $fehler( $z ); }
		$id = (string) $r->get_param( 'id' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,1024}$/', $id ) ) { return $fehler( 'Unbekanntes Gerät.' ); }
		$uids = get_users( array( 'meta_key' => self::META_ID, 'meta_value' => $id, 'fields' => 'ids', 'number' => 2 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( 1 !== count( $uids ) ) { return $fehler( 'Dieses Gerät ist hier nicht eingerichtet.' ); }
		$uid = (int) $uids[0];
		$s   = get_user_meta( $uid, self::META_KEY . md5( $id ), true );
		if ( ! is_array( $s ) || empty( $s['pem'] ) || ! hash_equals( (string) $s['id'], $id ) ) { return $fehler( 'Dieses Gerät ist hier nicht eingerichtet.' ); }
		$sig = (string) self::b64u_dec( (string) $r->get_param( 'signature' ) );
		$key = openssl_pkey_get_public( (string) $s['pem'] );
		if ( ! $key || '' === $sig || 1 !== openssl_verify( $ad . hash( 'sha256', $cd, true ), $sig, $key, OPENSSL_ALGO_SHA256 ) ) { return $fehler( 'Die Anmeldung konnte nicht bestätigt werden.' ); }
		$alt = (int) ( $s['zaehler'] ?? 0 );
		if ( ( $z > 0 || $alt > 0 ) && $z <= $alt ) { return $fehler( 'Die Anmeldung konnte nicht bestätigt werden.' ); }
		$s['zaehler'] = $z; $s['zuletzt'] = gmdate( 'Y-m-d H:i' );
		update_user_meta( $uid, self::META_KEY . md5( $id ), $s );

		$user = get_userdata( $uid );
		if ( ! $user ) { return $fehler( 'Konto nicht gefunden.' ); }
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true ); // 30 Tage (M24_B2B_Auth::cookie_30_tage)
		do_action( 'wp_login', $user->user_login, $user );
		$ziel = user_can( $user, 'edit_posts' ) ? admin_url() : home_url( '/?willkommen=1' );
		$rt   = (string) $r->get_param( 'redirect_to' );
		if ( '' !== $rt && user_can( $user, 'edit_posts' ) && wp_validate_redirect( $rt, '' ) ) { $ziel = $rt; }
		return array( 'ok' => true, 'ziel' => $ziel );
	}

	/** Skript für die Login-Seite (Anmelden + Einrichten). */
	public static function skript(): string {
		$cfg = array( 'rest' => esc_url_raw( rest_url( 'm24/v1/passkey/' ) ), 'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' );
		ob_start(); ?>
<script>(function(){
var C=<?php echo wp_json_encode( $cfg ); ?>;
function dec(s){s=s.replace(/-/g,'+').replace(/_/g,'/');while(s.length%4){s+='=';}var b=atob(s),a=new Uint8Array(b.length);for(var i=0;i<b.length;i++){a[i]=b.charCodeAt(i);}return a.buffer;}
function enc(buf){var a=new Uint8Array(buf),s='';for(var i=0;i<a.length;i++){s+=String.fromCharCode(a[i]);}return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
function post(p,d){var h={'Content-Type':'application/json'};if(C.nonce){h['X-WP-Nonce']=C.nonce;}return fetch(C.rest+p,{method:'POST',credentials:'same-origin',headers:h,body:JSON.stringify(d||{})}).then(function(r){return r.json().then(function(j){if(!r.ok){throw new Error(j.message||'Fehler');}return j;});});}
function meld(el,t,ok){if(!el){return;}el.textContent=t;el.hidden=!t;el.className=ok?'m24b2b-ok':'m24b2b-err';}
var kann=!!(window.PublicKeyCredential);
window.m24Passkey={kann:kann,
anmelden:function(msg,rt){if(!kann){meld(msg,'Dieser Browser unterstützt die Anmeldung per Fingerabdruck nicht.');return;}meld(msg,'');
post('auth-optionen').then(function(o){return navigator.credentials.get({publicKey:{challenge:dec(o.challenge),rpId:o.rpId,userVerification:'required',timeout:o.timeout}});})
.then(function(c){return post('auth-fertig',{id:c.id,clientDataJSON:enc(c.response.clientDataJSON),authenticatorData:enc(c.response.authenticatorData),signature:enc(c.response.signature),redirect_to:rt||''});})
.then(function(j){try{localStorage.setItem('m24_pk','1');}catch(x){}location.href=j.ziel;})
.catch(function(e){meld(msg,(e.name==='NotAllowedError')?'Abgebrochen. Bitte noch einmal versuchen oder mit E-Mail und Passwort anmelden.':(e.message||'Die Anmeldung hat nicht geklappt.'));});},
einrichten:function(msg){if(!kann){meld(msg,'Dieser Browser unterstützt das nicht.');return;}meld(msg,'');
post('reg-optionen').then(function(o){o.challenge=dec(o.challenge);o.user.id=dec(o.user.id);o.excludeCredentials=(o.excludeCredentials||[]).map(function(x){return {type:x.type,id:dec(x.id)};});return navigator.credentials.create({publicKey:o});})
.then(function(c){var r=c.response;return post('reg-fertig',{id:c.id,clientDataJSON:enc(r.clientDataJSON),authenticatorData:enc(r.getAuthenticatorData()),publicKey:enc(r.getPublicKey()),alg:r.getPublicKeyAlgorithm()});})
.then(function(){try{localStorage.setItem('m24_pk','1');}catch(x){}meld(msg,'Eingerichtet. Ab jetzt kannst du dich auf diesem Gerät per Fingerabdruck anmelden.',true);})
.catch(function(e){meld(msg,(e.name==='NotAllowedError')?'Abgebrochen.':(e.name==='InvalidStateError')?'Dieses Gerät ist schon eingerichtet.':(e.message||'Das Einrichten hat nicht geklappt.'));});}};
})();</script>
		<?php
		return (string) ob_get_clean();
	}
}
