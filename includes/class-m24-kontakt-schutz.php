<?php
/**
 * M24 — Kontaktformular-Schutz gegen Phishing-Missbrauch (Daniel, 30.10.2026)
 *
 * Anlass: Über das Kontaktformular (Formidable, /kontakt/) wurden Phishing-Nachrichten verschickt
 * (PayPal-Konten). Muster: Angreifer trägt die Opfer-Adresse als „E-Mail“ ein, die Bestätigungsmail
 * an den Absender trägt dann seinen Text samt Link – von unserem Server, mit unserem Namen.
 *
 * Schutz, ohne Hoster und ohne die Formulare umzubauen:
 *  1. Mails aus Formidable gehen NUR an eigene Adressen (@motorsport24.de / @s14.de). Eine Kopie an die
 *     eingetragene Absender-Adresse wird gestrichen – damit ist der Phishing-Weg zu.
 *  2. Links in Nachrichten werden abgewiesen (echte Kunden schreiben fast nie URLs ins Kontaktformular).
 *  3. Höchstens 3 Nachrichten pro IP und Stunde (Transient, nur ein Hash der IP wird gespeichert).
 * Abschaltbar über die Konstante M24_KONTAKT_SCHUTZ=false.
 *
 * @package m24-plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Kontakt_Schutz {
	const EIGEN = '/@(motorsport24\.de|s14\.de)$/i';
	const MAX_PRO_STUNDE = 3;

	public static function init() {
		if ( defined( 'M24_KONTAKT_SCHUTZ' ) && ! M24_KONTAKT_SCHUTZ ) { return; }
		add_filter( 'frm_to_email', array( __CLASS__, 'nur_eigene_empfaenger' ), 999, 1 );
		add_filter( 'frm_validate_entry', array( __CLASS__, 'pruefen' ), 20, 2 );
	}

	/** 1 · Formidable-Benachrichtigungen nur an eigene Adressen. */
	public static function nur_eigene_empfaenger( $empfaenger ) {
		$liste = is_array( $empfaenger ) ? $empfaenger : array_filter( array_map( 'trim', explode( ',', (string) $empfaenger ) ) );
		$ok    = array();
		foreach ( $liste as $e ) {
			$adr = trim( preg_replace( '/^.*<([^>]+)>.*$/', '$1', (string) $e ) );
			if ( preg_match( self::EIGEN, $adr ) ) { $ok[] = $e; }
		}
		return is_array( $empfaenger ) ? $ok : implode( ',', $ok );
	}

	/** 2 + 3 · Links und Häufigkeit prüfen. */
	public static function pruefen( $errors, $values ) {
		if ( ! is_array( $errors ) ) { $errors = array(); }
		$text = '';
		foreach ( (array) ( $values['item_meta'] ?? array() ) as $v ) {
			$text .= ' ' . ( is_array( $v ) ? implode( ' ', array_map( 'strval', $v ) ) : (string) $v );
		}
		if ( self::hat_link( $text ) ) {
			$errors['m24_schutz'] = self::meldung_link();
			return $errors;
		}
		if ( ! self::limit_frei() ) {
			$errors['m24_schutz'] = self::meldung_limit();
			return $errors;
		}
		if ( empty( $errors ) ) { self::limit_zaehlen(); }
		return $errors;
	}

	// ── Gemeinsame Bausteine (auch fürs eigene Kontaktformular [m24_kontakt], M24_Kontakt_Form) ──

	/** Enthält der Text einen Link / eine Domain? */
	public static function hat_link( $text ) {
		return (bool) preg_match( '~(https?://|www\.|\b[a-z0-9-]+\.(com|net|org|io|xyz|top|info|ru|cn|me|link|click|shop|site|online)\b|paypal|bit\.ly|t\.me/)~i', (string) $text );
	}

	/** Transient-Schlüssel je IP (nur Hash der IP). Gemeinsames Kontingent für Formidable + [m24_kontakt]. */
	private static function limit_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return 'm24_kf_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/** Noch unter MAX_PRO_STUNDE Nachrichten in dieser Stunde? */
	public static function limit_frei() {
		return (int) get_transient( self::limit_key() ) < self::MAX_PRO_STUNDE;
	}

	/** Eine erfolgreiche Nachricht zählen. */
	public static function limit_zaehlen() {
		$key = self::limit_key();
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	public static function meldung_link() {
		return __( 'Bitte keine Links in die Nachricht schreiben. / Please do not include links in your message.', 'm24-plattform' );
	}

	public static function meldung_limit() {
		return __( 'Zu viele Nachrichten – bitte später erneut versuchen. / Too many messages – please try again later.', 'm24-plattform' );
	}
}
M24_Kontakt_Schutz::init();
