<?php
/**
 * M24 Fahrzeug — „Jetzt anfragen" (Fahrzeug-Anfrageformular)
 * Modul: includes/fahrzeug/class-m24fz-anfrage.php
 *
 * Modal-Form auf der Detailseite. Submit → REST (Nonce wp_rest, Gast-tauglich) → Mail an
 * service@ + (optional) M24-Desk-Pipeline. Honeypot + Rate-Limit. Erst bei erfolgreichem
 * Submit wird _m24fz_anfragen_count erhöht (NICHT beim Button-Klick).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Anfrage {

	const NS = 'm24/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_reset_testdata' ) );
	}

	/** Einmalig: Anfragen-Zähler des Europameister (34294) auf 0 (Test-/Altdaten, §2). */
	public static function maybe_reset_testdata() {
		if ( get_option( 'm24fz_anfrage_reset_v2' ) || ! current_user_can( 'manage_options' ) ) { return; }
		if ( M24FZ_CPT::PT === get_post_type( 34294 ) ) { update_post_meta( 34294, '_m24fz_anfragen_count', 0 ); }
		update_option( 'm24fz_anfrage_reset_v2', 1 );
	}

	public static function register() {
		register_rest_route( self::NS, '/fahrzeug-anfrage', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'callback'            => array( __CLASS__, 'handle' ),
		) );
		// Interessentenliste-Opt-in — eigener Pfad, KEIN Anfragen-Zähler.
		register_rest_route( self::NS, '/fahrzeug-interessent', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'callback'            => array( __CLASS__, 'handle_il' ),
		) );
		register_rest_route( self::NS, '/fahrzeug-offmarket', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'callback'            => array( __CLASS__, 'handle_offmarket' ),
		) );
		register_rest_route( self::NS, '/fahrzeug-parken', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'callback'            => array( __CLASS__, 'handle_park' ),
		) );
	}

	public static function check_nonce( $req ) {
		$n = $req->get_header( 'X-WP-Nonce' );
		return ( is_string( $n ) && wp_verify_nonce( $n, 'wp_rest' ) ) ? true : new WP_Error( 'm24fz_nonce', 'Sicherheitsprüfung fehlgeschlagen.', array( 'status' => 403 ) );
	}

	public static function handle( WP_REST_Request $req ) {
		$p = $req->get_params();

		// Honeypot (verstecktes Feld muss leer sein).
		if ( ! empty( $p['website'] ) ) { return rest_ensure_response( array( 'ok' => true ) ); } // still „ok", Bot merkt nichts

		$pid = (int) ( $p['post_id'] ?? 0 );
		if ( ! $pid || M24FZ_CPT::PT !== get_post_type( $pid ) ) { return new WP_Error( 'm24fz_bad', 'Fahrzeug unbekannt.', array( 'status' => 400 ) ); }

		// Rate-Limit: max 5 Anfragen / Stunde je IP.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'x';
		$rk  = 'm24fz_anf_' . md5( $ip );
		$cnt = (int) get_transient( $rk );
		if ( $cnt >= 5 ) { return new WP_Error( 'm24fz_rate', 'Zu viele Anfragen. Bitte später erneut.', array( 'status' => 429 ) ); }

		// Gemeinsames Feld-Set (name, email, kundentyp, lieferland, nachricht, consent).
		$name       = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$mail       = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$land_iso   = strtoupper( sanitize_text_field( (string) ( $p['lieferland'] ?? '' ) ) );
		$lieferland = function_exists( 'm24_inquiry_country_name' ) ? m24_inquiry_country_name( $land_iso ) : $land_iso;
		$kundentyp  = ( 'Geschäftskunde' === ( $p['kundentyp'] ?? '' ) ) ? 'Geschäftskunde' : ( ( 'Privat' === ( $p['kundentyp'] ?? '' ) ) ? 'Privat' : '' );
		$msg        = sanitize_textarea_field( (string) ( $p['nachricht'] ?? '' ) );
		$consent    = ! empty( $p['consent'] );

		if ( mb_strlen( $name ) < 2 || ! is_email( $mail ) ) { return new WP_Error( 'm24fz_form', 'Bitte Name (mind. 2 Zeichen) und gültige E-Mail angeben.', array( 'status' => 422 ) ); }
		if ( '' === $kundentyp ) { return new WP_Error( 'm24fz_form', 'Bitte „Privat" oder „Geschäftskunde" wählen.', array( 'status' => 422 ) ); }
		if ( '' === $land_iso )  { return new WP_Error( 'm24fz_form', 'Bitte ein Lieferland wählen.', array( 'status' => 422 ) ); }
		if ( ! $consent )        { return new WP_Error( 'm24fz_form', 'Bitte der Datenschutzerklärung zustimmen.', array( 'status' => 422 ) ); }

		$title = get_the_title( $pid );
		$url   = get_permalink( $pid );
		$to    = apply_filters( 'm24fz_anfrage_to', get_option( 'admin_email' ) );

		// Kundentyp auf das Template-Vokabular normalisieren (→ „Geschäftlich (B2B)" / „Privat", wie die Teileanfrage).
		$kt_norm = ( 'Geschäftskunde' === $kundentyp ) ? 'business' : 'private';

		if ( function_exists( 'm24_render_inquiry_email' ) ) {
			// Dasselbe designte Shell wie die Teileanfrage: Blau-Header + Logo, KONTAKT/FAHRZEUG/NACHRICHT, „eingegangen …".
			$body  = m24_render_inquiry_email( array(
				'titel'      => 'Neue Fahrzeug-Anfrage',
				'name'       => $name,
				'email'      => $mail,
				'land'       => $land_iso,   // ISO2 → im Template ausgeschrieben
				'kundentyp'  => $kt_norm,
				'pos_label'  => 'Fahrzeug',  // Sektions-Titel statt „Position"
				'positionen' => array( array(
					'titel'         => $title,
					'link'          => $url,
					'artikelnummer' => (string) $pid,
					'art_label'     => 'Inserat-ID', // analog zur Artikelnummer-Zeile
					// keine Menge/kein Preis → Meta-Zeile entfällt
				) ),
				'nachricht'  => $msg,
				'anfrage_id' => 0,           // kein m24_inquiry-Post; die Inserats-ID steht in der Fahrzeug-Karte
				'datum_ts'   => time(),
			) );
			// Brass „Angebot erstellen →"-CTA (nur wenn Angebote aktiv) — wie in der Teileanfrage vor </body> injizieren.
			$op_links = apply_filters( 'm24_inquiry_operator_links', array(), array(
				'email'      => $mail,
				'name'       => $name,
				'kundentyp'  => ( 'business' === $kt_norm ) ? 'b2b' : 'b2c',
				'land'       => $land_iso,
				'src_modell' => $title,
				'src_pid'    => (string) $pid,
				'src_pillar' => 'fahrzeug',
				'src_url'    => $url,
			) );
			if ( ! empty( $op_links ) ) {
				$cta = '<div style="text-align:center;margin:18px 0;">';
				foreach ( $op_links as $l ) {
					$cta .= '<a href="' . esc_url( $l['url'] ) . '" style="display:inline-block;background:#9a6b25;color:#fff;text-decoration:none;font-weight:700;padding:11px 22px;border-radius:8px;margin:4px;">' . esc_html( $l['label'] ) . '</a>';
				}
				$cta .= '</div>';
				$body = ( false !== strpos( $body, '</body>' ) ) ? str_replace( '</body>', $cta . '</body>', $body ) : $body . $cta;
			}
			$ctype = 'text/html; charset=UTF-8';
		} else {
			// Fallback (Template nicht geladen): bisheriger Plain-Text.
			$body  = "Neue Fahrzeug-Anfrage\n\n";
			$body .= "Fahrzeug: {$title}\n{$url}\nInserat-ID: {$pid}\n\n";
			$body .= "Name: {$name}\nE-Mail: {$mail}\nKundentyp: {$kundentyp}\n";
			if ( '' !== $lieferland ) { $body .= "Lieferland: {$lieferland}\n"; }
			if ( '' !== $msg )        { $body .= "\nNachricht:\n{$msg}\n"; }
			$ctype = 'text/plain; charset=UTF-8';
		}

		// From-Name = Kunde, From-Adresse = Domain (SPF/DKIM); Reply-To = Kunde, damit „Antworten" passt.
		$headers = array(
			'Content-Type: ' . $ctype,
			'From: ' . self::from_header( $name, self::from_email() ),
			'Reply-To: ' . $name . ' <' . $mail . '>',
		);
		$sent    = wp_mail( $to, 'Fahrzeug-Anfrage: ' . $title, $body, $headers );

		// Desk/Brevo-Payload (Migration ohne Bruch): NAME + KUNDENTYP neu; abwärtskompatibel vorname = voller Name, nachname = "".
		do_action( 'm24fz_anfrage_submitted', $pid, array(
			'name'       => $name,
			'email'      => $mail,
			'kundentyp'  => $kundentyp,
			'lieferland' => $lieferland,
			'nachricht'  => $msg,
			'vorname'    => $name,
			'nachname'   => '',
		) );

		// IL-Opt-in (optional): bei gesetztem Häkchen zusätzlich in die Interessentenliste (Liste 3) —
		// NAME + KUNDENTYP + Fahrzeug-Attribute. Ohne Häkchen: nichts (nur Anfrage-Mail/Desk wie gehabt).
		if ( ! empty( $p['il_optin'] ) ) {
			self::register_interessent( $pid, array( 'name' => $name, 'email' => $mail, 'kundentyp' => $kundentyp, 'lieferland' => $lieferland ) );
		}

		// „Konto anlegen"-Opt-in (geteilte register-Checkbox, nur Gäste): passwortloses Konto + DOI-Magic-Link —
		// 1:1 wie der Teile-Handler (inquiry-submit.php). il_optin fließt als Newsletter-Präferenz ins Konto.
		if ( ! empty( $p['register'] ) && ! is_user_logged_in() && class_exists( 'M24_Login' ) ) {
			$reg_ok = M24_Login::create_account_and_send_link( $mail, $name, ! empty( $p['il_optin'] ) );
			if ( class_exists( 'M24_Logger' ) ) {
				M24_Logger::info( 'register', $reg_ok ? 'account-created+mail-queued' : 'account-create-FAILED', array( 'ctx' => 'fahrzeug-anfrage' ) );
			}
		}

		// Erst bei erfolgreichem Submit zählen (§2).
		if ( class_exists( 'M24FZ_Tracking' ) ) { M24FZ_Tracking::increment( $pid, 'anfrage' ); }

		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'ok' => (bool) $sent, 'message' => $sent ? 'Danke! Deine Anfrage ist eingegangen.' : 'Anfrage gespeichert, Mailversand verzögert.' ) );
	}

	/**
	 * Interessentenliste-Opt-in (separat von der Fahrzeug-Anfrage). Erhöht NICHT _m24fz_anfragen_count.
	 * DOI ist plugin-managed (Brevo Phase 2). Solange kein API-Key gesetzt: Fallback-Mail (klar als
	 * „Interessentenliste-Eintrag" markiert) + Hook m24fz_interessent_submitted für die spätere DOI-Pipeline.
	 */
	public static function handle_il( WP_REST_Request $req ) {
		$p = $req->get_params();

		if ( ! empty( $p['website'] ) ) { return rest_ensure_response( array( 'ok' => true ) ); } // Honeypot

		$pid = (int) ( $p['post_id'] ?? 0 );
		if ( ! $pid || M24FZ_CPT::PT !== get_post_type( $pid ) ) { return new WP_Error( 'm24fz_bad', 'Fahrzeug unbekannt.', array( 'status' => 400 ) ); }

		// Rate-Limit: max 5 Einträge / Stunde je IP (eigener Schlüssel, getrennt von der Anfrage).
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'x';
		$rk  = 'm24fz_il_' . md5( $ip );
		$cnt = (int) get_transient( $rk );
		if ( $cnt >= 5 ) { return new WP_Error( 'm24fz_rate', 'Zu viele Einträge. Bitte später erneut.', array( 'status' => 429 ) ); }

		$vorname  = sanitize_text_field( (string) ( $p['vorname'] ?? '' ) );
		$nachname = sanitize_text_field( (string) ( $p['nachname'] ?? '' ) );
		$mail     = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$tel      = sanitize_text_field( (string) ( $p['tel'] ?? '' ) );
		$lang     = ( 'en' === strtolower( (string) ( $p['lang'] ?? '' ) ) ) ? 'en' : 'de';
		// Abwärtskompat: falls noch ein altes Single-Name-Feld kommt, als Vorname nehmen.
		if ( '' === $vorname ) { $vorname = sanitize_text_field( (string) ( $p['name'] ?? '' ) ); }
		if ( '' === $vorname || ! is_email( $mail ) ) { return new WP_Error( 'm24fz_form', 'Bitte Vorname und gültige E-Mail angeben.', array( 'status' => 422 ) ); }
		$name = trim( $vorname . ' ' . $nachname );

		self::register_interessent( $pid, array( 'name' => $name, 'vorname' => $vorname, 'nachname' => $nachname, 'lang' => $lang, 'email' => $mail, 'tel' => $tel ) );

		// BEWUSST KEIN M24FZ_Tracking::increment() — IL ist keine Anfrage.
		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'ok' => true, 'message' => 'Eingetragen! Du erhältst deine Bestätigung in Kürze per E-Mail.' ) );
	}

	/**
	 * Off-Market-Anmeldung (nur E-Mail) → gleiche DOI-Pipeline wie IL, markiert als Off-Market.
	 * Honeypot + eigener Rate-Limit + Anti-Enumeration (immer dieselbe Erfolgsmeldung).
	 */
	public static function handle_offmarket( WP_REST_Request $req ) {
		$p = $req->get_params();

		if ( ! empty( $p['website'] ) ) { return rest_ensure_response( array( 'ok' => true ) ); } // Honeypot

		// Rate-Limit: max 5 Einträge / Stunde je IP (eigener Schlüssel).
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'x';
		$rk  = 'm24fz_om_' . md5( $ip );
		$cnt = (int) get_transient( $rk );
		if ( $cnt >= 5 ) { return new WP_Error( 'm24fz_rate', 'Zu viele Einträge. Bitte später erneut.', array( 'status' => 429 ) ); }

		$mail = sanitize_email( (string) ( $p['email'] ?? '' ) );
		if ( ! is_email( $mail ) ) { return new WP_Error( 'm24fz_form', 'Bitte eine gültige E-Mail angeben.', array( 'status' => 422 ) ); }

		$vorname  = sanitize_text_field( (string) ( $p['vorname'] ?? '' ) );
		$nachname = sanitize_text_field( (string) ( $p['nachname'] ?? '' ) );
		if ( '' === $vorname ) { return new WP_Error( 'm24fz_form', 'Bitte deinen Vornamen angeben.', array( 'status' => 422 ) ); }
		$lang = ( 'en' === strtolower( (string) ( $p['lang'] ?? '' ) ) ) ? 'en' : 'de';
		$name = trim( $vorname . ' ' . $nachname );

		$pid = (int) ( $p['post_id'] ?? 0 ); // optionaler Kontext (auslösendes Inserat)

		do_action( 'm24fz_interessent_submitted', $pid, array(
			'name'      => $name,
			'vorname'   => $vorname,
			'nachname'  => $nachname,
			'lang'      => $lang,
			'email'     => $mail,
			'offmarket' => true,
		) );

		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		// Anti-Enumeration: immer dieselbe Antwort, egal ob neu/bestehend.
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Bitte bestätige deine Off-Market-Anmeldung per E-Mail.' ) );
	}

	/**
	 * „Fahrzeug parken" (No-Account-Variante) → gleiche DOI-Pipeline, markiert als parked.
	 * Fahrzeug-spezifisch (post_id Pflicht). Honeypot + eigener Rate-Limit + Anti-Enumeration.
	 * Merkliste-Zähler erst hier (erfolgreicher Submit), nicht mehr passiv per Tracking-Ping.
	 */
	public static function handle_park( WP_REST_Request $req ) {
		$p = $req->get_params();

		if ( ! empty( $p['website'] ) ) { return rest_ensure_response( array( 'ok' => true ) ); } // Honeypot

		$pid = (int) ( $p['post_id'] ?? 0 );
		if ( ! $pid || M24FZ_CPT::PT !== get_post_type( $pid ) ) { return new WP_Error( 'm24fz_bad', 'Fahrzeug unbekannt.', array( 'status' => 400 ) ); }

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'x';
		$rk  = 'm24fz_pk_' . md5( $ip );
		$cnt = (int) get_transient( $rk );
		if ( $cnt >= 5 ) { return new WP_Error( 'm24fz_rate', 'Zu viele Einträge. Bitte später erneut.', array( 'status' => 429 ) ); }

		$mail = sanitize_email( (string) ( $p['email'] ?? '' ) );
		if ( ! is_email( $mail ) ) { return new WP_Error( 'm24fz_form', 'Bitte eine gültige E-Mail angeben.', array( 'status' => 422 ) ); }

		$vorname  = sanitize_text_field( (string) ( $p['vorname'] ?? '' ) );
		$nachname = sanitize_text_field( (string) ( $p['nachname'] ?? '' ) );
		if ( '' === $vorname ) { return new WP_Error( 'm24fz_form', 'Bitte deinen Vornamen angeben.', array( 'status' => 422 ) ); }
		$lang = ( 'en' === strtolower( (string) ( $p['lang'] ?? '' ) ) ) ? 'en' : 'de';
		$name = trim( $vorname . ' ' . $nachname );

		// Fahrzeug-Attribute (Modell/Kategorie) wie IL ableiten + parked-Markierung.
		$attr = self::il_attributes( $pid );
		do_action( 'm24fz_interessent_submitted', $pid, array(
			'name'         => $name,
			'vorname'      => $vorname,
			'nachname'     => $nachname,
			'lang'         => $lang,
			'email'        => $mail,
			'parked'       => true,
			'parked_title' => get_the_title( $pid ),
			'modelle'      => $attr['modelle'],
			'kategorien'   => $attr['kategorien'],
		) );

		// Merkliste-Zähler erst bei erfolgreichem Submit (§2).
		if ( class_exists( 'M24FZ_Tracking' ) ) { M24FZ_Tracking::increment( $pid, 'merken' ); }

		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'ok' => true, 'message' => '✓ Gemerkt — bitte E-Mail bestätigen.' ) );
	}

	/**
	 * Brevo-/Listen-Attribute aus dem Fahrzeug ableiten (einheitlich für IL-Modal + Anfrage-Opt-in):
	 * MODELLE ← Baureihe (Fallback Modell), KATEGORIEN ← Fahrzeugtyp (Straße/Sport). Werte filterbar.
	 */
	public static function il_attributes( $pid ) {
		$baureihe = trim( (string) get_post_meta( $pid, '_m24fz_baureihe', true ) );
		$modell   = trim( (string) get_post_meta( $pid, '_m24fz_modell', true ) );
		$mod      = '' !== $baureihe ? $baureihe : $modell;
		$modelle  = ( '' !== $mod ) ? array( $mod ) : array();
		$typ      = ( 'renn' === get_post_meta( $pid, '_m24fz_template_typ', true ) ) ? 'Sport' : 'Straße';
		return array(
			'modelle'    => apply_filters( 'm24fz_il_modelle', $modelle, $pid ),
			'kategorien' => apply_filters( 'm24fz_il_kategorien', array( $typ ), $pid ),
		);
	}

	/**
	 * Interessentenlisten-Registrierung (geteilt von IL-Modal und Anfrage-Opt-in). Erhöht NICHT den
	 * Anfragen-Zähler. Fallback-Mail (klar markiert) + Hook m24fz_interessent_submitted für Brevo Phase 2 (DOI).
	 */
	public static function register_interessent( $context_id, $contact ) {
		$name       = (string) ( $contact['name'] ?? '' );
		$mail       = (string) ( $contact['email'] ?? '' );
		$tel        = (string) ( $contact['tel'] ?? '' );
		$kundentyp  = (string) ( $contact['kundentyp'] ?? '' );
		$lieferland = (string) ( $contact['lieferland'] ?? '' ); // für die DOI-Erinnerungs-TZ (Anfrage-Pfad)
		if ( '' === $name || ! is_email( $mail ) ) { return; }

		// Attribute: explizit übergeben (z. B. Teile-Kontext) ODER aus dem Fahrzeug ableiten.
		if ( isset( $contact['modelle'] ) || isset( $contact['kategorien'] ) ) {
			$attr = array( 'modelle' => (array) ( $contact['modelle'] ?? array() ), 'kategorien' => (array) ( $contact['kategorien'] ?? array() ) );
		} else {
			$attr = self::il_attributes( (int) $context_id );
		}
		// Interne Operator-Benachrichtigung „Interessentenliste-Eintrag (Marketing-Opt-in)" — Default AUS
		// (wird nicht gebraucht). Re-aktivierbar via Filter m24fz_interessent_admin_notify → true bzw. Option
		// m24fz_interessent_notify_enabled. Die Kunden-DOI-Bestätigungsmail (do_action unten) bleibt unberührt.
		$notify_on = apply_filters( 'm24fz_interessent_admin_notify', (bool) (int) get_option( 'm24fz_interessent_notify_enabled', 0 ) );
		if ( $notify_on ) {
			$title = get_the_title( $context_id );
			$url   = get_permalink( $context_id );
			$to    = apply_filters( 'm24fz_interessent_to', apply_filters( 'm24fz_anfrage_to', get_option( 'admin_email' ) ) );

			$body  = "Neuer Interessentenlisten-Eintrag (Marketing-Opt-in)\n\n";
			$body .= "Auslösendes Inserat/Teil: {$title}\n{$url}\nID: {$context_id}\n\n";
			$body .= "Name: {$name}\nE-Mail: {$mail}\n";
			if ( '' !== $kundentyp ) { $body .= "Kundentyp: {$kundentyp}\n"; }
			if ( '' !== $tel )       { $body .= "Telefon/WhatsApp: {$tel}\n"; }
			$body .= "\nMODELLE: " . ( $attr['modelle'] ? implode( ', ', (array) $attr['modelle'] ) : '—' ) . "\n";
			$body .= "KATEGORIEN: " . ( $attr['kategorien'] ? implode( ', ', (array) $attr['kategorien'] ) : '—' ) . "\n";
			$brevo_ready = class_exists( 'M24_Brevo_Client' ) ? M24_Brevo_Client::is_configured() : ( '' !== (string) get_option( 'm24_brevo_api_key', '' ) );
			$body .= "\nListe-ID 3 + DOI: plugin-managed (Brevo Phase 2 — " . ( $brevo_ready ? 'API-Key gesetzt, DOI-Mail läuft' : 'API-Key noch nicht gesetzt' ) . ").\n";

			$headers = array(
				'From: ' . self::from_header( $name, self::from_email() ),
				'Reply-To: ' . $name . ' <' . $mail . '>',
			);
			wp_mail( $to, 'Interessentenliste-Eintrag: ' . $title, $body, $headers );
		}

		// Hook für die plugin-managed DOI-Pipeline (Liste-ID 3; NAME + KUNDENTYP + Attribute MODELLE/KATEGORIEN).
		do_action( 'm24fz_interessent_submitted', $context_id, array(
			'name'       => $name,
			'vorname'    => (string) ( $contact['vorname'] ?? '' ),
			'nachname'   => (string) ( $contact['nachname'] ?? '' ),
			'lang'       => (string) ( $contact['lang'] ?? '' ),
			'email'      => $mail,
			'kundentyp'  => $kundentyp,
			'tel'        => $tel,
			'lieferland' => $lieferland,
			'modelle'    => $attr['modelle'],
			'kategorien' => $attr['kategorien'],
		) );
	}

	/** Domain-Absenderadresse für die Benachrichtigungen (SPF/DKIM/DMARC-tauglich, filterbar). */
	private static function from_email() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./i', '', $host );
		if ( '' === $host ) { $host = 'motorsport24.de'; }
		return apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host );
	}

	/**
	 * From-Header bauen: Anzeigename = Kundenname, Adresse = Domain-Absender (nicht die Kunden-Mail).
	 * Leerer Name → Fallback auf reine Adresse (WP-Standard). Sonderzeichen/Umlaute sauber kodiert.
	 */
	private static function from_header( $name, $email ) {
		$name = trim( preg_replace( '/[\r\n]+/', ' ', (string) $name ) );
		if ( '' === $name ) { return $email; }
		$disp = function_exists( 'mb_encode_mimeheader' ) ? mb_encode_mimeheader( $name, 'UTF-8' ) : $name;
		// Reiner ASCII-Name mit Header-Sonderzeichen (z. B. Klammern) → in Anführungszeichen kapseln.
		if ( $disp === $name && preg_match( '/[(),<>@";:\\\\\[\]]/', $name ) ) { $disp = '"' . str_replace( '"', '', $name ) . '"'; }
		return $disp . ' <' . $email . '>';
	}

	/** Kurzer Datenschutzhinweis mit Link zur Datenschutzerklärung (beide Modals, kein Pflicht-Häkchen). */
	public static function datenschutz_hint() {
		$url  = apply_filters( 'm24fz_datenschutz_url', get_privacy_policy_url() );
		$link = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Datenschutzerklärung</a>' : 'Datenschutzerklärung';
		return '<p class="m24fz-dsgvo">Mit dem Absenden verarbeiten wir deine Angaben zur Bearbeitung deines Anliegens. Mehr in der ' . $link . '.</p>';
	}

	/** Modal-Markup (einmal pro Detailseite ausgeben). */
	public static function modal_html( $post_id ) {
		?>
		<div class="m24fz-anfrage-modal" id="m24fz-anfrage-modal" hidden aria-hidden="true">
			<div class="m24fz-anfrage-box" role="dialog" aria-modal="true" aria-label="Fahrzeug anfragen">
				<button type="button" class="m24fz-anfrage-close" aria-label="Schließen">&times;</button>
				<h3>Fahrzeug anfragen</h3>
				<p class="m24fz-anfrage-veh"><?php echo esc_html( get_the_title( $post_id ) ); ?></p>
				<form class="m24fz-anfrage-form" data-pid="<?php echo (int) $post_id; ?>" novalidate>
					<?php m24_inquiry_fields(); // gemeinsames Feld-Set (name, email, kundentyp, lieferland, nachricht, consent) ?>
					<input type="text" name="website" class="m24fz-anf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button type="submit" class="m24fz-btn m24fz-anf-submit">Anfrage senden</button>
					<p class="m24fz-anf-msg" role="status"></p>
				</form>
			</div>
		</div>
		<?php
	}

	/** Interessentenliste-Opt-in-Modal (eigener Container, getrennt vom Anfrage-Modal). */
	public static function il_modal_html( $post_id ) {
		?>
		<div class="m24fz-anfrage-modal m24fz-il-modal" id="m24fz-il-modal" hidden aria-hidden="true">
			<div class="m24fz-anfrage-box" role="dialog" aria-modal="true" aria-label="Auf die Interessentenliste">
				<button type="button" class="m24fz-anfrage-close" aria-label="Schließen">&times;</button>
				<h3>Auf die Interessentenliste</h3>
				<p class="m24fz-anfrage-veh">Trag dich ein und erfahre als Erster, sobald dieses oder ein ähnliches Fahrzeug verfügbar ist.</p>
				<form class="m24fz-anfrage-form m24fz-il-form" data-pid="<?php echo (int) $post_id; ?>">
					<div class="m24fz-frow">
						<div class="m24-ci-field"><label class="m24-ci-label" for="m24ilV">Vorname <span class="req">*</span></label><input id="m24ilV" class="m24-ci-input" type="text" name="vorname" placeholder="Dein Vorname" required></div>
						<div class="m24-ci-field"><label class="m24-ci-label" for="m24ilNn">Nachname</label><input id="m24ilNn" class="m24-ci-input" type="text" name="nachname" placeholder="optional"></div>
					</div>
					<div class="m24fz-frow">
						<div class="m24-ci-field"><label class="m24-ci-label" for="m24ilE">E-Mail <span class="req">*</span></label><input id="m24ilE" class="m24-ci-input" type="email" name="email" placeholder="deine@email.de" required></div>
						<div class="m24-ci-field"><label class="m24-ci-label" for="m24ilT">Telefon / WhatsApp</label><input id="m24ilT" class="m24-ci-input" type="tel" name="tel" placeholder="optional"></div>
					</div>
					<input type="hidden" name="lang" value="<?php echo esc_attr( class_exists( 'M24_I18n' ) ? M24_I18n::resolve_lang() : 'de' ); ?>"><?php // Seitensprache automatisch übernehmen (kein In-Form-Switch) ?>
					<label class="m24fz-anf-check"><input type="checkbox" name="consent" value="1" required> Ich möchte per E-Mail über ähnliche Fahrzeuge benachrichtigt werden und stimme der Anmeldung (Double-Opt-in) zu.</label>
					<input type="text" name="website" class="m24fz-anf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button type="submit" class="m24fz-btn m24fz-anf-submit m24fz-il-submit">Eintragen</button>
					<?php echo self::datenschutz_hint(); // phpcs:ignore ?>
					<p class="m24fz-anf-msg" role="status"></p>
				</form>
			</div>
		</div>
		<?php
	}

	/** „Fahrzeug parken"-Modal (No-Account-DOI, nur E-Mail + Consent). */
	public static function park_modal_html( $post_id ) {
		?>
		<div class="m24fz-anfrage-modal m24fz-park-modal" id="m24fz-park-modal" hidden aria-hidden="true">
			<div class="m24fz-anfrage-box" role="dialog" aria-modal="true" aria-label="Fahrzeug parken">
				<button type="button" class="m24fz-anfrage-close" aria-label="Schließen">&times;</button>
				<h3>Fahrzeug parken</h3>
				<p class="m24fz-anfrage-veh">Wir merken uns dieses Fahrzeug für dich und informieren dich zu diesem und ähnlichen Fahrzeugen.</p>
				<form class="m24fz-anfrage-form m24fz-park-form" data-pid="<?php echo (int) $post_id; ?>">
					<div class="m24fz-frow">
						<div class="m24-ci-field"><label class="m24-ci-label" for="m24pkV">Vorname <span class="req">*</span></label><input id="m24pkV" class="m24-ci-input" type="text" name="vorname" placeholder="Dein Vorname" required></div>
						<div class="m24-ci-field"><label class="m24-ci-label" for="m24pkN">Nachname</label><input id="m24pkN" class="m24-ci-input" type="text" name="nachname" placeholder="optional"></div>
					</div>
					<div class="m24-ci-field"><label class="m24-ci-label" for="m24pkE">E-Mail <span class="req">*</span></label><input id="m24pkE" class="m24-ci-input" type="email" name="email" placeholder="deine@email.de" required></div>
					<input type="hidden" name="lang" value="<?php echo esc_attr( class_exists( 'M24_I18n' ) ? M24_I18n::resolve_lang() : 'de' ); ?>"><?php // Seitensprache automatisch übernehmen (kein In-Form-Switch) ?>
					<label class="m24fz-anf-check"><input type="checkbox" name="consent" value="1" required> Ich möchte zu diesem und ähnlichen Fahrzeugen per E-Mail informiert werden und stimme der Anmeldung zu.</label>
					<input type="text" name="website" class="m24fz-anf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button type="submit" class="m24fz-btn m24fz-anf-submit m24fz-park-submit">Fahrzeug parken</button>
					<?php echo self::datenschutz_hint(); // phpcs:ignore ?>
					<p class="m24fz-anf-msg" role="status"></p>
				</form>
			</div>
		</div>
		<?php
	}
}
