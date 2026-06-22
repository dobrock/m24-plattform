<?php
/**
 * M24 Plattform — Interessentenliste, plugin-managed Double-Opt-In (Brevo Phase 2)
 *
 * Ablauf:
 *   1. IL-Opt-in-Submit → register_interessent() feuert `m24fz_interessent_submitted`.
 *   2. Hier: KEIN sofortiger Brevo-Call. Stattdessen Pending-Record (E-Mail, fertiges
 *      Attribut-Array, Kundentyp, Token, created_at) speichern + DOI-Mail an den
 *      Interessenten senden. Token-Gültigkeit 7 Tage. Bereits-pending-E-Mail → Token
 *      erneuern + Mail erneut senden (kein Duplikat).
 *   3. Klick auf den Bestätigungslink (/anmeldung-bestaetigt/?m24il=TOKEN, Seite 34308):
 *      Token validieren → Kontakt an Brevo upserten (Liste 3, bestätigt) → Pending
 *      erledigt → freundliche Erfolgsseite. Upsert-Fehler → Lead per Fallback-Mail an
 *      service@ retten + Log „Fail", Nutzer trotzdem Erfolgsseite. Ungültiger/abgelaufener
 *      Token → neutrale „Link abgelaufen"-Meldung.
 *
 * Die Fallback-Mail aus register_interessent() bleibt als Sicherheitsnetz parallel aktiv.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Brevo_IL {

	const PENDING_OPTION = 'm24_brevo_il_pending';
	const CONFIRM_PAGE   = 34308; // WP-Seite /anmeldung-bestaetigt/
	const TTL            = 604800; // 7 Tage in Sekunden
	const QUERY_VAR      = 'm24il';

	/** Ergebnis des Confirm-Vorgangs für die_content: 'ok' | 'invalid' | null. */
	private static $confirm_state = null;

	public static function init() {
		// DOI-Pipeline an den bestehenden generischen Hook hängen.
		add_action( 'm24fz_interessent_submitted', array( __CLASS__, 'on_submitted' ), 10, 2 );

		// Confirm-Handling auf der Bestätigungsseite.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_confirm' ) );
		add_filter( 'the_content', array( __CLASS__, 'confirm_notice' ), 9 );
	}

	/* =====================================================================
	 * 1) Opt-in-Submit → Pending + DOI-Mail
	 * ================================================================== */

	/**
	 * Hook-Callback für `m24fz_interessent_submitted`.
	 * $contact: name, email, kundentyp, tel, modelle[], kategorien[].
	 */
	public static function on_submitted( $context_id, $contact ) {
		$email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $contact['name'] ?? '' ) );
		if ( ! is_email( $email ) || '' === $name ) {
			return;
		}

		// Ohne konfigurierten Key: kein DOI-Versand (nur die Fallback-Mail greift) — kein toter Link.
		if ( ! M24_Brevo_Client::is_configured() ) {
			M24_Logger::warning( 'brevo', 'IL-Opt-in ohne API-Key — nur Fallback-Mail (' . M24_Brevo_Client::mask_email( $email ) . ')', array(
				'email' => M24_Brevo_Client::mask_email( $email ),
			) );
			return;
		}

		$attributes = self::attributes_for( $contact );

		// Bereits-pending-E-Mail → vorhandenen Record wiederverwenden, Token erneuern, Daten auffrischen.
		$store = self::load();
		$token = self::find_token_by_email( $store, $email );
		if ( null === $token ) {
			$token = self::new_token();
		}

		$store[ $token ] = array(
			'email'      => $email,
			'name'       => $name,
			'kundentyp'  => sanitize_text_field( (string) ( $contact['kundentyp'] ?? '' ) ),
			'attributes' => $attributes,
			'created'    => time(),
		);
		self::save( $store );

		self::send_doi_mail( $email, $name, $token );

		M24_Logger::info( 'brevo', 'DOI-Mail gesendet (' . M24_Brevo_Client::mask_email( $email ) . ')', array(
			'email'    => M24_Brevo_Client::mask_email( $email ),
			'attrKeys' => array_keys( $attributes ),
		) );
	}

	/**
	 * Brevo-Attribute aus dem generischen Kontakt ableiten. Einheitliche Quelle der Wahrheit.
	 * NAME, KUNDENTYP, MODELLE, KATEGORIEN (Text) + Segment-Flags ALLE / ALLE_OLDTIMER / ALLE_SPORT.
	 * Filterbar via `m24_brevo_il_attributes`.
	 */
	public static function attributes_for( $contact ) {
		$modelle    = array_values( array_filter( array_map( 'trim', (array) ( $contact['modelle'] ?? array() ) ) ) );
		$kategorien = array_values( array_filter( array_map( 'trim', (array) ( $contact['kategorien'] ?? array() ) ) ) );

		$attr = array(
			'NAME'       => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
			'KUNDENTYP'  => sanitize_text_field( (string) ( $contact['kundentyp'] ?? '' ) ),
			'MODELLE'    => implode( ', ', $modelle ),
			'KATEGORIEN' => implode( ', ', $kategorien ),
			'ALLE'       => true, // jeder Listen-Kontakt
		);

		// Kategorie-abgeleitete Segment-Flags. Oldtimer/Straße → ALLE_OLDTIMER, Sport → ALLE_SPORT.
		$katz_lower = array_map( 'mb_strtolower', $kategorien );
		if ( in_array( 'oldtimer', $katz_lower, true ) || in_array( 'straße', $katz_lower, true ) || in_array( 'strasse', $katz_lower, true ) ) {
			$attr['ALLE_OLDTIMER'] = true;
		}
		if ( in_array( 'sport', $katz_lower, true ) ) {
			$attr['ALLE_SPORT'] = true;
		}

		return apply_filters( 'm24_brevo_il_attributes', $attr, $contact );
	}

	/* =====================================================================
	 * 2) Confirm-Handler (Seite 34308 / ?m24il=TOKEN)
	 * ================================================================== */

	/** Token früh (vor Render) verarbeiten, damit der Brevo-Upsert vor der Ausgabe steht. */
	public static function maybe_confirm() {
		if ( is_admin() || ! is_page( self::CONFIRM_PAGE ) ) {
			return;
		}
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $token ) {
			return; // Seite normal aufgerufen, ohne Token — nichts tun.
		}
		self::$confirm_state = self::confirm_token( $token );
	}

	/** Status-Box vor den Seiteninhalt setzen (nur Seite 34308, nur wenn ein Token verarbeitet wurde). */
	public static function confirm_notice( $content ) {
		if ( null === self::$confirm_state || ! is_page( self::CONFIRM_PAGE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		return self::render_box( self::$confirm_state ) . $content;
	}

	/**
	 * Token validieren und — falls gültig — Kontakt bestätigt an Brevo upserten.
	 * @return string 'ok' (Erfolg ODER soft-fail mit gerettetem Lead) | 'invalid' (ungültig/abgelaufen)
	 */
	private static function confirm_token( $token ) {
		$store = self::load();
		if ( ! isset( $store[ $token ] ) ) {
			return 'invalid';
		}
		$rec = $store[ $token ];

		// Abgelaufen?
		if ( ( time() - (int) ( $rec['created'] ?? 0 ) ) > self::TTL ) {
			unset( $store[ $token ] );
			self::save( $store );
			return 'invalid';
		}

		$email      = (string) $rec['email'];
		$attributes = (array) ( $rec['attributes'] ?? array() );

		$res = M24_Brevo_Client::upsert_contact( $email, $attributes, array( M24_Brevo_Client::LIST_ID ) );

		// Pending in jedem Fall erledigt (kein Re-Processing). Lead bei Fehler per Fallback-Mail gerettet.
		unset( $store[ $token ] );
		self::save( $store );

		if ( ! $res['ok'] ) {
			self::send_fail_fallback( $rec, $res );
			// Nutzer sieht trotzdem die Erfolgsseite — Lead ist gesichert.
		}

		return 'ok';
	}

	/* =====================================================================
	 * Mails
	 * ================================================================== */

	/** DOI-Bestätigungsmail an den Interessenten (CI-konform, Bestätigungs-Button). */
	private static function send_doi_mail( $email, $name, $token ) {
		$confirm_url = add_query_arg( self::QUERY_VAR, $token, self::confirm_page_url() );
		$subject     = 'Bitte bestätigen Sie Ihre Anmeldung — MOTORSPORT24';

		$body = self::mail_html(
			'Fast geschafft!',
			'<p style="margin:0 0 14px;">Hallo ' . esc_html( $name ) . ',</p>'
			. '<p style="margin:0 0 14px;">vielen Dank für Ihr Interesse. Bitte bestätigen Sie mit einem Klick, dass wir Sie '
			. 'über passende Fahrzeuge und Angebote informieren dürfen:</p>'
			. '<p style="margin:24px 0;text-align:center;">'
			. '<a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;background:#1763ad;color:#ffffff;'
			. 'text-decoration:none;font-weight:600;padding:13px 28px;border-radius:6px;font-size:15px;">Anmeldung bestätigen</a>'
			. '</p>'
			. '<p style="margin:0 0 8px;color:#5a6474;font-size:13px;">Falls der Button nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:</p>'
			. '<p style="margin:0 0 14px;font-size:12px;word-break:break-all;"><a href="' . esc_url( $confirm_url ) . '" style="color:#1763ad;">' . esc_html( $confirm_url ) . '</a></p>'
			. '<p style="margin:0;color:#9aa3b0;font-size:12px;">Wenn Sie sich nicht angemeldet haben, ignorieren Sie diese E-Mail einfach — es passiert nichts.</p>'
		);

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::from_header(),
			'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
		);

		wp_mail( $email, $subject, $body, $headers );
	}

	/** Fallback-Mail an service@, falls der Brevo-Upsert nach Bestätigung scheitert (Lead-Rettung). */
	private static function send_fail_fallback( $rec, $res ) {
		$to   = apply_filters( 'm24fz_interessent_to', apply_filters( 'm24fz_anfrage_to', get_option( 'admin_email' ) ) );
		$attr = (array) ( $rec['attributes'] ?? array() );

		$body  = "Brevo-Upsert nach DOI-Bestätigung FEHLGESCHLAGEN — Lead bitte manuell in Liste 3 eintragen.\n\n";
		$body .= 'Name: ' . ( $rec['name'] ?? '' ) . "\n";
		$body .= 'E-Mail: ' . ( $rec['email'] ?? '' ) . "\n";
		$body .= 'Kundentyp: ' . ( $rec['kundentyp'] ?? '' ) . "\n";
		$body .= 'MODELLE: ' . ( $attr['MODELLE'] ?? '—' ) . "\n";
		$body .= 'KATEGORIEN: ' . ( $attr['KATEGORIEN'] ?? '—' ) . "\n\n";
		$body .= 'Brevo-Antwort: HTTP ' . (int) $res['code'] . ' — ' . (string) $res['msg'] . "\n";

		wp_mail( $to, 'IL-Bestätigung: Brevo-Eintrag fehlgeschlagen', $body, array( 'From: ' . self::from_header() ) );

		M24_Logger::error( 'brevo', 'DOI bestätigt, aber Brevo-Upsert fehlgeschlagen — Fallback-Mail an Daniel', array(
			'email' => M24_Brevo_Client::mask_email( (string) ( $rec['email'] ?? '' ) ),
			'code'  => (int) $res['code'],
			'msg'   => (string) $res['msg'],
		) );
	}

	/* =====================================================================
	 * Render & Helfer
	 * ================================================================== */

	/** Status-Box für die Bestätigungsseite. */
	private static function render_box( $state ) {
		if ( 'ok' === $state ) {
			$title = 'Anmeldung bestätigt';
			$text  = 'Vielen Dank! Ihre Anmeldung ist bestätigt. Wir melden uns, sobald passende Fahrzeuge oder Angebote verfügbar sind.';
			$color = '#1a7a3c';
			$bg    = '#edf7f1';
		} else {
			$title = 'Link abgelaufen';
			$text  = 'Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte melden Sie sich erneut an.';
			$color = '#b87000';
			$bg    = '#fdf5e6';
		}
		return '<div class="m24-il-confirm" style="max-width:560px;margin:24px auto;padding:22px 26px;border-radius:8px;'
			. 'border-left:4px solid ' . $color . ';background:' . $bg . ';">'
			. '<h2 style="margin:0 0 8px;color:' . $color . ';font-size:20px;">' . esc_html( $title ) . '</h2>'
			. '<p style="margin:0;color:#3a414c;font-size:15px;line-height:1.5;">' . esc_html( $text ) . '</p>'
			. '</div>';
	}

	/** Schmales CI-konformes HTML-Mail-Gerüst (blauer Top-Balken, MOTORSPORT24-Wortmarke). */
	private static function mail_html( $headline, $inner ) {
		return '<!DOCTYPE html><html lang="de"><body style="margin:0;padding:0;background:#f2f4f7;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:24px 0;"><tr><td align="center">'
			. '<table role="presentation" width="440" cellpadding="0" cellspacing="0" style="max-width:440px;background:#ffffff;border-radius:8px;overflow:hidden;">'
			. '<tr><td style="height:3px;background:#1763ad;"></td></tr>'
			. '<tr><td style="padding:20px 28px 0;text-align:right;font-family:Arial,Helvetica,sans-serif;font-weight:700;letter-spacing:1px;color:#10243a;font-size:14px;">MOTORSPORT24</td></tr>'
			. '<tr><td style="padding:8px 28px 24px;font-family:Arial,Helvetica,sans-serif;color:#10243a;">'
			. '<h1 style="margin:8px 0 16px;font-size:21px;color:#10243a;">' . esc_html( $headline ) . '</h1>'
			. '<div style="font-size:15px;line-height:1.55;color:#3a414c;">' . $inner . '</div>'
			. '</td></tr>'
			. '<tr><td style="padding:14px 28px;border-top:1px solid #e6e9ee;font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#9aa3b0;">MOTORSPORT24 GmbH · www.motorsport24.de</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** Absender-Header: From-Name MOTORSPORT24, Domain-Adresse noreply@<domain> (SPF/DKIM, Logik wie 0.11.20). */
	private static function from_header() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./i', '', $host );
		if ( '' === $host ) {
			$host = 'motorsport24.de';
		}
		$email = apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host );
		$name  = apply_filters( 'm24_brevo_doi_from_name', 'MOTORSPORT24' );
		return $name . ' <' . $email . '>';
	}

	/** URL der Bestätigungsseite (Seite 34308, mit Domain-Fallback). */
	private static function confirm_page_url() {
		$url = get_permalink( self::CONFIRM_PAGE );
		if ( ! $url ) {
			$url = home_url( '/anmeldung-bestaetigt/' );
		}
		return $url;
	}

	/* =====================================================================
	 * Pending-Store (Option, autoload aus)
	 * ================================================================== */

	private static function load() {
		$store = get_option( self::PENDING_OPTION, array() );
		return is_array( $store ) ? $store : array();
	}

	/** Speichern + abgelaufene Records beim Schreiben aufräumen. */
	private static function save( $store ) {
		$now = time();
		foreach ( $store as $tok => $rec ) {
			if ( ( $now - (int) ( $rec['created'] ?? 0 ) ) > self::TTL ) {
				unset( $store[ $tok ] );
			}
		}
		update_option( self::PENDING_OPTION, $store, false );
	}

	private static function find_token_by_email( $store, $email ) {
		foreach ( $store as $tok => $rec ) {
			if ( isset( $rec['email'] ) && strtolower( (string) $rec['email'] ) === strtolower( $email ) ) {
				return $tok;
			}
		}
		return null;
	}

	private static function new_token() {
		if ( function_exists( 'random_bytes' ) ) {
			return bin2hex( random_bytes( 16 ) );
		}
		return bin2hex( wp_generate_password( 16, false, false ) ); // Fallback
	}
}
