<?php
/**
 * M24FZ_S14 — Bruecke „Auf s14.de inserieren".
 *
 * Vertrag: ~/m24-bridge/BRIDGE_s14-an-Plattform.md (Stand 02.09.2026).
 * M24 schickt EINEN Request mit allen Feldern; s14 legt einen Entwurf an,
 * kopiert die Bilder und schreibt die Texte um. M24 merkt sich nur s14_id
 * und die Links.
 *
 * Bewusst NICHT am Speichern haengen: Daniel will erst pruefen, dann senden.
 * Einzige Ausnahme ist die Statusmeldung (verkauft/reserviert/gelistet), die
 * laut Vertrag automatisch feuern darf.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_S14 {

	const NS         = 'm24/v1';
	const META_ID    = '_m24fz_s14_id';
	const META_URL   = '_m24fz_s14_url';
	const META_EDIT  = '_m24fz_s14_bearbeiten';
	const META_STAND = '_m24fz_s14_stand';   // Zeitpunkt der letzten Uebertragung

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// Statusabgleich: nach dem Speichern, aber nur wenn drueben etwas liegt.
		add_action( 'm24fz_after_save', array( __CLASS__, 'status_melden' ), 20, 1 );
	}

	/* ── Konfiguration ───────────────────────────────────────────────────────── */

	public static function basis() {
		$b = defined( 'M24_S14_BASIS' ) ? (string) M24_S14_BASIS : 'https://s14.de';
		return untrailingslashit( $b );
	}

	public static function token() {
		return defined( 'M24_S14_TOKEN' ) ? trim( (string) M24_S14_TOKEN ) : '';
	}

	public static function eingerichtet() {
		return '' !== self::token();
	}

	/**
	 * Authorization-Kopf. Das Anwendungspasswort darf Leerzeichen enthalten —
	 * die gehoeren laut Vertrag ausdruecklich dazu und werden NICHT entfernt.
	 */
	private static function auth_header() {
		return 'Basic ' . base64_encode( 'MOTORSPORT24:' . self::token() );
	}

	/* ── REST: der Knopf ─────────────────────────────────────────────────────── */

	public static function routes() {
		register_rest_route( self::NS, '/s14-senden', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_senden' ),
			'permission_callback' => array( __CLASS__, 'perm' ),
		) );
	}

	public static function perm( $req ) {
		$n = $req->get_header( 'x_wp_nonce' );
		if ( ! is_string( $n ) || ! wp_verify_nonce( $n, 'wp_rest' ) ) {
			return new WP_Error( 'm24_nonce', 'Sicherheitspruefung fehlgeschlagen.', array( 'status' => 403 ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'm24_cap', 'Keine Berechtigung.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_senden( $req ) {
		$id = (int) $req->get_param( 'post_id' );
		if ( ! $id || M24FZ_CPT::PT !== get_post_type( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'meldung' => 'Fahrzeug nicht gefunden.' ), 400 );
		}
		$r = self::senden( $id, (bool) $req->get_param( 'text_neu' ) );
		return new WP_REST_Response( $r, $r['ok'] ? 200 : 502 );
	}

	/* ── Uebertragung ────────────────────────────────────────────────────────── */

	public static function senden( $id, $text_neu = false ) {
		if ( ! self::eingerichtet() ) {
			return array( 'ok' => false, 'meldung' => 'M24_S14_TOKEN fehlt in der wp-config.php.' );
		}
		$payload = self::payload( $id );
		if ( $text_neu ) { $payload['text_neu'] = true; }

		// 45 s laut Vertrag: s14 holt die Bilder serverseitig, das dauert 5-30 s.
		$res = wp_remote_post( self::basis() . '/wp-json/s14/v1/m24/fahrzeug', array(
			'timeout' => 45,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => self::auth_header(),
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'meldung' => 'Verbindung zu s14 fehlgeschlagen: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['ok'] ) ) {
			// Die Klartextmeldung von s14 hat Vorrang — sie ist fuer den Editor gedacht.
			$meldung = is_array( $body ) && ! empty( $body['meldung'] )
				? (string) $body['meldung']
				: ( 401 === $code ? 'Token abgelehnt (HTTP 401). M24_S14_TOKEN pruefen.' : 's14 antwortete mit HTTP ' . $code . '.' );
			return array( 'ok' => false, 'meldung' => $meldung, 'code' => $code );
		}

		$s14_id = (int) ( $body['s14_id'] ?? 0 );
		if ( $s14_id ) {
			update_post_meta( $id, self::META_ID, $s14_id );
			update_post_meta( $id, self::META_URL, esc_url_raw( (string) ( $body['vorschau'] ?? '' ) ) );
			update_post_meta( $id, self::META_EDIT, esc_url_raw( (string) ( $body['bearbeiten'] ?? '' ) ) );
			update_post_meta( $id, self::META_STAND, current_time( 'mysql' ) );
		}
		return array(
			'ok'         => true,
			's14_id'     => $s14_id,
			'aktion'     => (string) ( $body['aktion'] ?? 'angelegt' ),
			'status'     => (string) ( $body['status'] ?? 'draft' ),
			'vorschau'   => (string) ( $body['vorschau'] ?? '' ),
			'bearbeiten' => (string) ( $body['bearbeiten'] ?? '' ),
			'bilder'     => $body['bilder']   ?? null,
			'hinweise'   => $body['hinweise'] ?? array(),
			'stand'      => date_i18n( 'H:i' ),
		);
	}

	/**
	 * Statusabgleich. Feuert nur, wenn drueben etwas liegt, und darf den
	 * M24-Save unter keinen Umstaenden aufhalten — deshalb kurzer Timeout und
	 * jeder Fehler nur ins Log.
	 */
	public static function status_melden( $id ) {
		$id = (int) $id;
		if ( ! $id || ! self::eingerichtet() ) { return; }
		if ( ! get_post_meta( $id, self::META_ID, true ) ) { return; }

		$status = M24FZ_CPT::status( $id );
		// s14 kennt nur diese drei. 'entwurf'/'deaktiviert' sind M24-intern.
		if ( ! in_array( $status, array( 'verkauft', 'reserviert', 'gelistet' ), true ) ) { return; }

		$body = array( 'm24_post_id' => $id, 'status' => $status );
		if ( 'verkauft' === $status ) { $body['verkauft_am'] = current_time( 'Y-m-d' ); }

		$res = wp_remote_post( self::basis() . '/wp-json/s14/v1/m24/fahrzeug/status', array(
			'timeout'  => 8,
			'blocking' => true,
			'headers'  => array( 'Content-Type' => 'application/json', 'Authorization' => self::auth_header() ),
			'body'     => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $res ) ) {
			error_log( '[m24fz-s14] Status ' . $id . ': ' . $res->get_error_message() );
		}
	}

	/* ── Nutzlast ────────────────────────────────────────────────────────────── */

	private static function m( $id, $k, $d = '' ) {
		$v = get_post_meta( $id, $k, true );
		return ( '' === $v || null === $v ) ? $d : $v;
	}

	private static function liste( $id, $k ) {
		$v = get_post_meta( $id, $k, true );
		if ( ! is_array( $v ) ) { return array(); }
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $v ) ), 'strlen' ) );
	}

	/** Galerie: Original-URLs laut Vertrag, keine Zwischengroesse. */
	private static function galerie( $id, $key ) {
		$out = array();
		foreach ( (array) get_post_meta( $id, $key, true ) as $aid ) {
			$aid = (int) $aid;
			$url = $aid ? wp_get_attachment_url( $aid ) : '';
			if ( ! $url ) { continue; }
			$out[] = array(
				'id'  => $aid,
				'url' => $url,
				'alt' => (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ),
			);
		}
		return $out;
	}

	public static function payload( $id ) {
		$id  = (int) $id;
		$typ = (string) self::m( $id, '_m24fz_template_typ', 'strasse' );

		// Zustand als SLUGS — so steht es im Beispiel des Vertrags
		// (["unrestauriert-original", "unfallfrei"]). Die Anweisung
		// „Bezeichnungen senden" gilt laut Vertrag ausdruecklich nur fuer
		// ausstattung. Die aufgeloesten Labels gehen zusaetzlich mit, falls
		// s14 sie anzeigen will.
		$zust_opts   = M24FZ_Telemetry::zustand_options();
		$zust_slugs  = self::liste( $id, '_m24fz_zustand' );
		$zust_labels = array();
		foreach ( $zust_slugs as $zs ) { $zust_labels[] = $zust_opts[ $zs ] ?? $zs; }

		// Ausstattung ist im Editor ein FREITEXTFELD und enthaelt bereits
		// Bezeichnungen, keine Slugs (siehe Bridge-Frage 7).
		$ausstattung = self::liste( $id, '_m24fz_ausstattung' );

		$felder = array(
			'template_typ'          => $typ,
			'marke'                 => (string) self::m( $id, '_m24fz_marke' ),
			'baureihe'              => (string) self::m( $id, '_m24fz_baureihe' ),
			'modell'                => (string) self::m( $id, '_m24fz_modell' ),
			'baujahr'               => (string) self::m( $id, '_m24fz_baujahr' ),
			'erstzulassung'         => (string) self::m( $id, '_m24fz_erstzulassung' ),
			'karosserie'            => (string) self::m( $id, '_m24fz_karosserie' ),
			'fin'                   => (string) self::m( $id, '_m24fz_fin' ),
			'laufleistung'          => (string) self::m( $id, '_m24fz_laufleistung' ),
			'laufleistung_einheit'  => (string) self::m( $id, '_m24fz_laufleistung_einheit', 'km' ),
			'land_erstauslieferung' => (string) self::m( $id, '_m24fz_land_erstauslieferung' ),
			'anzahl_halter'         => (string) self::m( $id, '_m24fz_anzahl_halter' ),
			'standort'              => (string) self::m( $id, '_m24fz_standort' ),
			'neu_gebraucht'         => (string) self::m( $id, '_m24fz_neu_gebraucht' ),
			'zustand'               => $zust_slugs,
			'zustand_labels'        => $zust_labels,
			'fahrbereit'            => (bool) self::m( $id, '_m24fz_fahrbereit', 0 ),
			'zugelassen'            => (bool) self::m( $id, '_m24fz_zugelassen', 0 ),
			'matching_numbers'      => (bool) self::m( $id, '_m24fz_matching_numbers', 0 ),
			'leistung_ps'           => (string) self::m( $id, '_m24fz_leistung_ps' ),
			'hubraum'               => (string) self::m( $id, '_m24fz_hubraum' ),
			'getriebe'              => (string) self::m( $id, '_m24fz_getriebe' ),
			'gewicht'               => (string) self::m( $id, '_m24fz_gewicht' ),
			'antrieb'               => (string) self::m( $id, '_m24fz_antrieb' ),
			'kraftstoff'            => (string) self::m( $id, '_m24fz_kraftstoff' ),
			'lenkung'               => (string) self::m( $id, '_m24fz_lenkung' ),
			'aussenfarbe'           => (string) self::m( $id, '_m24fz_aussenfarbe' ),
			'farbbez_hersteller'    => (string) self::m( $id, '_m24fz_farbbez_hersteller' ),
			'innenmaterial'         => (string) self::m( $id, '_m24fz_innenmaterial' ),
			'innenmaterial_stoff'   => (string) self::m( $id, '_m24fz_innenmaterial_stoff' ),
			'innenfarbe'            => (string) self::m( $id, '_m24fz_innenfarbe' ),
			'wagenpass'             => (bool) self::m( $id, '_m24fz_wagenpass', 0 ),
			'rennhistorie'          => (bool) self::m( $id, '_m24fz_rennhistorie', 0 ),
			'original_design'       => (bool) self::m( $id, '_m24fz_original_design', 0 ),
			'zusammenfassung'       => (string) self::m( $id, '_m24fz_zusammenfassung' ),
			'preis'                 => (string) self::m( $id, '_m24fz_preis' ),
			'waehrung'              => (string) self::m( $id, '_m24fz_waehrung', 'EUR' ),
			'preis_reduziert'       => (string) self::m( $id, '_m24fz_preis_reduziert' ),
			'mwst_ausweisbar'       => (bool) self::m( $id, '_m24fz_mwst_ausweisbar', 0 ),
			'preis_auf_anfrage'     => (bool) self::m( $id, '_m24fz_preis_auf_anfrage', 0 ),
		);

		for ( $i = 1; $i <= 3; $i++ ) {
			$felder[ "race_opt{$i}_label" ] = (string) self::m( $id, "_m24fz_race_opt{$i}_label" );
			$felder[ "race_opt{$i}_value" ] = (string) self::m( $id, "_m24fz_race_opt{$i}_value" );
		}
		foreach ( array( 'motor', 'getriebe', 'diff' ) as $lk ) {
			$felder[ "lauf_{$lk}" ]      = (string) self::m( $id, "_m24fz_lauf_{$lk}" );
			$felder[ "lauf_{$lk}_unit" ] = (string) self::m( $id, "_m24fz_lauf_{$lk}_unit", 'km' );
		}

		$og = (int) self::m( $id, '_m24fz_og_image', 0 );

		return array(
			'm24_post_id'       => $id,
			'm24_url'           => (string) get_permalink( $id ),
			'titel'             => (string) get_the_title( $id ),
			'beschreibung_html' => (string) self::m( $id, '_m24fz_beschreibung' ),
			'kat'               => M24FZ_CPT::kats( $id ),
			'felder'            => $felder,
			'keyfacts'          => self::liste( $id, '_m24fz_keyfacts' ),
			'ausstattung'       => $ausstattung,
			'ausstattung_slugs' => array_map( 'sanitize_title', $ausstattung ),
			'videos'            => self::liste( $id, '_m24fz_videos' ),
			'bilder'            => array(
				'aussen'     => self::galerie( $id, '_m24fz_gal_aussen' ),
				'innen'      => self::galerie( $id, '_m24fz_gal_innen' ),
				'motor'      => self::galerie( $id, '_m24fz_gal_motor' ),
				'unterboden' => self::galerie( $id, '_m24fz_gal_unterboden' ),
			),
			'social_image'      => array(
				'id'  => $og,
				'url' => $og ? (string) wp_get_attachment_url( $og ) : '',
			),
		);
	}
}
