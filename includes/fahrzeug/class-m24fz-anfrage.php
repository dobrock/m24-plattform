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

		$body  = "Neue Fahrzeug-Anfrage\n\n";
		$body .= "Fahrzeug: {$title}\n{$url}\nInserat-ID: {$pid}\n\n";
		$body .= "Name: {$name}\nE-Mail: {$mail}\nKundentyp: {$kundentyp}\n";
		if ( '' !== $lieferland ) { $body .= "Lieferland: {$lieferland}\n"; }
		if ( '' !== $msg )        { $body .= "\nNachricht:\n{$msg}\n"; }

		// From-Name = Kunde, From-Adresse = Domain (SPF/DKIM); Reply-To = Kunde, damit „Antworten" passt.
		$headers = array(
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
			self::register_interessent( $pid, array( 'name' => $name, 'email' => $mail, 'kundentyp' => $kundentyp ) );
		}

		// Erst bei erfolgreichem Submit zählen (§2).
		if ( class_exists( 'M24FZ_Tracking' ) ) { M24FZ_Tracking::increment( $pid, 'anfrage' ); }

		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'ok' => (bool) $sent, 'message' => $sent ? 'Danke! Ihre Anfrage ist eingegangen.' : 'Anfrage gespeichert, Mailversand verzögert.' ) );
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

		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$mail = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$tel  = sanitize_text_field( (string) ( $p['tel'] ?? '' ) );
		if ( '' === $name || ! is_email( $mail ) ) { return new WP_Error( 'm24fz_form', 'Bitte Name und gültige E-Mail angeben.', array( 'status' => 422 ) ); }

		self::register_interessent( $pid, array( 'name' => $name, 'email' => $mail, 'tel' => $tel ) );

		// BEWUSST KEIN M24FZ_Tracking::increment() — IL ist keine Anfrage.
		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'ok' => true, 'message' => 'Eingetragen! Sie erhalten Ihre Bestätigung in Kürze per E-Mail.' ) );
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
		$name      = (string) ( $contact['name'] ?? '' );
		$mail      = (string) ( $contact['email'] ?? '' );
		$tel       = (string) ( $contact['tel'] ?? '' );
		$kundentyp = (string) ( $contact['kundentyp'] ?? '' );
		if ( '' === $name || ! is_email( $mail ) ) { return; }

		// Attribute: explizit übergeben (z. B. Teile-Kontext) ODER aus dem Fahrzeug ableiten.
		if ( isset( $contact['modelle'] ) || isset( $contact['kategorien'] ) ) {
			$attr = array( 'modelle' => (array) ( $contact['modelle'] ?? array() ), 'kategorien' => (array) ( $contact['kategorien'] ?? array() ) );
		} else {
			$attr = self::il_attributes( (int) $context_id );
		}
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
		$body .= "\nListe-ID 3 + DOI: plugin-managed (Brevo Phase 2 — API-Key noch nicht gesetzt).\n";

		$headers = array(
			'From: ' . self::from_header( $name, self::from_email() ),
			'Reply-To: ' . $name . ' <' . $mail . '>',
		);
		wp_mail( $to, 'Interessentenliste-Eintrag: ' . $title, $body, $headers );

		// Hook für die plugin-managed DOI-Pipeline (Liste-ID 3; NAME + KUNDENTYP + Attribute MODELLE/KATEGORIEN).
		do_action( 'm24fz_interessent_submitted', $context_id, array(
			'name'       => $name,
			'email'      => $mail,
			'kundentyp'  => $kundentyp,
			'tel'        => $tel,
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
		return '<p class="m24fz-dsgvo">Mit dem Absenden verarbeiten wir Ihre Angaben zur Bearbeitung Ihres Anliegens. Mehr in der ' . $link . '.</p>';
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
				<p class="m24fz-anfrage-veh">Tragen Sie sich ein und erfahren Sie als Erster, sobald dieses oder ein ähnliches Fahrzeug verfügbar ist.</p>
				<form class="m24fz-anfrage-form m24fz-il-form" data-pid="<?php echo (int) $post_id; ?>">
					<div class="m24fz-frow">
						<div class="m24fz-f"><label for="m24ilN">Name <span class="req">*</span></label><input id="m24ilN" type="text" name="name" placeholder="Ihr Name" required></div>
						<div class="m24fz-f"><label for="m24ilE">E-Mail <span class="req">*</span></label><input id="m24ilE" type="email" name="email" placeholder="ihre@email.de" required></div>
					</div>
					<div class="m24fz-f"><label for="m24ilT">Telefon / WhatsApp</label><input id="m24ilT" type="tel" name="tel" placeholder="optional"></div>
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
}
