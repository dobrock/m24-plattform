<?php
/**
 * M24 — Allgemeines Kontaktformular [m24_kontakt] (ersetzt das Formidable-Formular auf /kontakt/, 31.10.2026)
 * Modul: includes/class-m24-kontakt-form.php
 *
 * Optik wie das Teile-Modal „Frage stellen": Themen-Chips, Name/E-Mail, „Ich bin" (Privat/Geschäftskunde/
 * Werkstatt), Lieferland (Liste aus M24_Inquiry_Frontend::lands()), Teilenummer, EU-Privatkunden-Hinweis,
 * Nachricht, Einwilligung.
 *
 * Versand: REST POST m24-plattform/v1/kontakt (Nonce wp_rest, Gast-tauglich) → DIESELBE Pipeline wie
 * „Frage stellen": M24_Inquiries_Storage::insert_inquiry() (inquiry_source = contact_form) → Anfragen-Liste
 * im WP-Admin + Info-Mail an fallback_mail_to (service@, Betreff „Kontaktanfrage: <Thema> — Name")
 * + Auto-Push ans M24 Desk. Eine Position „Kontaktanfrage: <Thema>" (die Pipeline braucht ≥ 1 Position).
 *
 * Schutz: Honeypot (website_confirm), Nonce, keine Links, max. 3 Nachrichten/h je IP (geteilt mit
 * M24_Kontakt_Schutz). Keine Bestätigungsmail an die eingetragene Adresse (Phishing-Weg bleibt zu).
 * Das PPWR-Lieferland-Gate gilt hier bewusst NICHT — eine Kontaktnachricht ist keine Bestellung.
 *
 * @package m24-plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Kontakt_Form {

	const ROUTE = '/kontakt';

	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) { return; }
		self::$initialized = true;
		add_shortcode( 'm24_kontakt', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/** Themen (Schlüssel => Label). Erstes = Vorauswahl. */
	public static function topics() {
		return array(
			'teile'     => 'Teile-Anfrage',
			'fahrzeug'  => 'Fahrzeug',
			'werkstatt' => 'Werkstatt / Rennsport-Technik',
			'sonstiges' => 'Sonstiges',
		);
	}

	/** „Ich bin" (Wert => Label). Wert „Privat" wie im Teile-Modal → gleicher EU-Hinweis-Check. */
	public static function kundentypen() {
		return array(
			'Privat'         => 'Privatkunde',
			'Geschäftskunde' => 'Geschäftskunde',
			'Werkstatt'      => 'Werkstatt',
		);
	}

	// ── Assets ─────────────────────────────────────────────────────────

	public static function register_assets() {
		if ( is_admin() ) { return; }
		$base    = plugin_dir_url( M24_PLATTFORM_FILE );
		$version = defined( 'M24_PLATTFORM_VERSION' ) ? M24_PLATTFORM_VERSION : '0.1.0';
		wp_register_style( 'm24-kontakt', $base . 'assets/css/m24-kontakt.css', array(), $version );
		wp_register_script( 'm24-kontakt', $base . 'assets/js/m24-kontakt.js', array(), $version, true );
		wp_localize_script( 'm24-kontakt', 'M24KontaktConfig', array(
			'restUrl' => esc_url_raw( rest_url( M24_Inquiry_Submit::NS . self::ROUTE ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'i18nDe'  => array(
				'name'    => 'Bitte Ihren Namen angeben (mind. 2 Zeichen).',
				'email'   => 'Bitte eine gültige E-Mail-Adresse angeben.',
				'kund'    => 'Bitte auswählen: Privatkunde, Geschäftskunde oder Werkstatt.',
				'land'    => 'Bitte ein Lieferland wählen.',
				'consent' => 'Bitte der Datenschutzerklärung zustimmen.',
				'err'     => 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es erneut oder schreiben Sie an service@motorsport24.de.',
				'sending' => 'Wird gesendet …',
				'ok'      => 'Vielen Dank! Ihre Nachricht ist bei uns eingegangen. Wir antworten Ihnen per E-Mail.',
			),
			'i18nEn'  => array(
				'name'    => 'Please enter your name (at least 2 characters).',
				'email'   => 'Please enter a valid email address.',
				'kund'    => 'Please choose: private customer, business customer or workshop.',
				'land'    => 'Please choose a delivery country.',
				'consent' => 'Please accept the privacy policy.',
				'err'     => 'An error occurred. Please try again or write to service@motorsport24.de.',
				'sending' => 'Sending …',
				'ok'      => 'Thank you! We have received your message and will reply by email.',
			),
		) );
		// Früh einbinden, wenn die Seite den Shortcode enthält (vermeidet ungestylten Aufbau).
		$post = get_post();
		if ( $post && is_singular() && has_shortcode( (string) $post->post_content, 'm24_kontakt' ) ) {
			wp_enqueue_style( 'm24-kontakt' );
			wp_enqueue_script( 'm24-kontakt' );
		}
	}

	// ── Shortcode ──────────────────────────────────────────────────────

	public static function shortcode( $atts = array() ) {
		// Fallback, falls der Shortcode außerhalb von post_content steht (Widget/Template).
		wp_enqueue_style( 'm24-inquiry-fields' );
		wp_enqueue_script( 'm24-inquiry-fields' );
		wp_enqueue_style( 'm24-kontakt' );
		wp_enqueue_script( 'm24-kontakt' );

		$topics    = self::topics();
		$kunden    = self::kundentypen();
		$countries = function_exists( 'm24_inquiry_countries' ) ? m24_inquiry_countries() : array();
		$first     = (string) key( $topics );
		$uid       = 'm24kf-' . wp_rand( 1000, 999999 );

		ob_start();
		?>
		<div class="m24kf" data-m24kf>
			<form class="m24kf-form" data-m24kf-form novalidate>
				<div class="m24kf-field">
					<span class="m24kf-label" id="<?php echo esc_attr( $uid ); ?>-thema"><?php esc_html_e( 'Worum geht es?', 'm24-plattform' ); ?></span>
					<div class="m24kf-chips" role="radiogroup" aria-labelledby="<?php echo esc_attr( $uid ); ?>-thema" data-m24kf-chips>
						<?php foreach ( $topics as $key => $label ) : $on = ( $key === $first ); ?>
							<button type="button" class="m24kf-chip" role="radio" aria-checked="<?php echo $on ? 'true' : 'false'; ?>" tabindex="<?php echo $on ? '0' : '-1'; ?>" data-val="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
						<input type="hidden" name="thema" value="<?php echo esc_attr( $first ); ?>">
					</div>
				</div>

				<div class="m24kf-grid">
					<div class="m24kf-field">
						<label class="m24kf-label" for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Vor- und Nachname', 'm24-plattform' ); ?> *</label>
						<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="name" class="m24kf-input" required minlength="2" maxlength="120" autocomplete="name">
					</div>
					<div class="m24kf-field">
						<label class="m24kf-label" for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'E-Mail', 'm24-plattform' ); ?> *</label>
						<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" class="m24kf-input" required maxlength="160" autocomplete="email">
					</div>
				</div>

				<div class="m24kf-field">
					<span class="m24kf-label" id="<?php echo esc_attr( $uid ); ?>-kund"><?php esc_html_e( 'Ich bin', 'm24-plattform' ); ?> *</span>
					<div class="m24-iqf-seg m24kf-seg" role="radiogroup" aria-labelledby="<?php echo esc_attr( $uid ); ?>-kund" data-m24-kundentyp>
						<?php $i = 0; foreach ( $kunden as $val => $label ) : ?>
							<button type="button" class="m24-iqf-seg-btn m24kf-seg-btn" role="radio" aria-checked="false" data-val="<?php echo esc_attr( $val ); ?>" tabindex="<?php echo 0 === $i++ ? '0' : '-1'; ?>"><?php echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
						<input type="hidden" name="kundentyp" value="">
					</div>
				</div>

				<div class="m24kf-grid">
					<div class="m24kf-field">
						<label class="m24kf-label" for="<?php echo esc_attr( $uid ); ?>-land"><?php esc_html_e( 'Lieferland', 'm24-plattform' ); ?> *</label>
						<select id="<?php echo esc_attr( $uid ); ?>-land" name="lieferland" class="m24kf-input m24kf-select" required>
							<option value="" disabled selected><?php esc_html_e( 'Bitte wählen', 'm24-plattform' ); ?></option>
							<?php foreach ( $countries as $iso => $label ) : ?>
								<option value="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="m24kf-field">
						<label class="m24kf-label" for="<?php echo esc_attr( $uid ); ?>-tnr"><?php esc_html_e( 'Teilenummer / Artikel (optional)', 'm24-plattform' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $uid ); ?>-tnr" name="teilenummer" class="m24kf-input" maxlength="120">
					</div>
				</div>

				<?php if ( function_exists( 'm24_inquiry_eu_note_html' ) ) { echo m24_inquiry_eu_note_html(); } // phpcs:ignore WordPress.Security.EscapeOutput — statisches Markup, Attribute escaped. ?>

				<div class="m24kf-field">
					<label class="m24kf-label" for="<?php echo esc_attr( $uid ); ?>-msg"><?php esc_html_e( 'Nachricht', 'm24-plattform' ); ?></label>
					<textarea id="<?php echo esc_attr( $uid ); ?>-msg" name="nachricht" class="m24kf-input m24kf-textarea" rows="5" maxlength="5000" placeholder="<?php esc_attr_e( 'Fahrzeug, Baujahr, was Sie suchen …', 'm24-plattform' ); ?>"></textarea>
				</div>

				<label class="m24kf-consent">
					<input type="checkbox" name="consent" value="1" required>
					<span><?php echo function_exists( 'm24_inquiry_consent_html' ) ? m24_inquiry_consent_html() : esc_html__( 'Ich willige in die Verarbeitung meiner Angaben zur Bearbeitung der Anfrage ein. *', 'm24-plattform' ); // phpcs:ignore WordPress.Security.EscapeOutput — via wp_kses. ?></span>
				</label>

				<input type="text" name="website_confirm" class="m24kf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

				<p class="m24kf-error" data-m24kf-error role="alert" hidden></p>

				<button type="submit" class="m24kf-submit" data-m24kf-submit><span aria-hidden="true">✉</span> <?php esc_html_e( 'Anfrage senden', 'm24-plattform' ); ?></button>
				<p class="m24kf-foot"><?php esc_html_e( 'Antwort per E-Mail · Deutsch & English', 'm24-plattform' ); ?></p>
			</form>
			<div class="m24kf-success" data-m24kf-success role="status" hidden></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// ── REST ───────────────────────────────────────────────────────────

	public static function register_route() {
		register_rest_route( M24_Inquiry_Submit::NS, self::ROUTE, array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => array( 'M24_Inquiry_Submit', 'check_nonce' ),
		) );
	}

	private static function fail( $code, $msg, $status = 422 ) {
		return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'error' => $msg ), $status );
	}

	public static function handle( WP_REST_Request $request ) {
		$p = $request->get_params();

		// Honeypot: still „ok", der Bot merkt nichts.
		if ( '' !== trim( (string) ( $p['website_confirm'] ?? '' ) ) ) {
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$topics  = self::topics();
		$kunden  = self::kundentypen();
		$thema   = sanitize_key( (string) ( $p['thema'] ?? '' ) );
		$thema   = isset( $topics[ $thema ] ) ? $thema : (string) key( $topics );
		$name    = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$email   = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$kund    = (string) ( $p['kundentyp'] ?? '' );
		$kund    = isset( $kunden[ $kund ] ) ? $kund : '';
		$land    = strtoupper( sanitize_text_field( (string) ( $p['lieferland'] ?? '' ) ) );
		$tnr     = mb_substr( sanitize_text_field( (string) ( $p['teilenummer'] ?? '' ) ), 0, 120 );
		$msg     = mb_substr( sanitize_textarea_field( (string) ( $p['nachricht'] ?? '' ) ), 0, 5000 );
		$consent = ! empty( $p['consent'] );

		if ( mb_strlen( $name ) < 2 )   { return self::fail( 'm24_name_missing', 'Bitte Ihren Namen angeben (mind. 2 Zeichen). / Please enter your name.' ); }
		if ( ! is_email( $email ) )     { return self::fail( 'm24_email_invalid', 'Bitte eine gültige E-Mail-Adresse angeben. / Please enter a valid email address.' ); }
		if ( '' === $kund )             { return self::fail( 'm24_kund_missing', 'Bitte Privatkunde, Geschäftskunde oder Werkstatt wählen. / Please choose a customer type.' ); }
		$accepted = class_exists( 'M24_Inquiry_Frontend' ) ? M24_Inquiry_Frontend::accepted_iso() : array();
		if ( '' === $land || ( $accepted && ! in_array( $land, $accepted, true ) ) ) {
			return self::fail( 'm24_land_unknown', 'Bitte ein Lieferland aus der Liste wählen. / Please choose a delivery country.' );
		}
		if ( ! $consent )               { return self::fail( 'm24_consent_missing', 'Bitte der Datenschutzerklärung zustimmen. / Please accept the privacy policy.' ); }

		// Phishing-/Spam-Schutz wie beim Formidable-Formular (abschaltbar über M24_KONTAKT_SCHUTZ=false).
		$schutz = class_exists( 'M24_Kontakt_Schutz' ) && ! ( defined( 'M24_KONTAKT_SCHUTZ' ) && ! M24_KONTAKT_SCHUTZ );
		if ( $schutz ) {
			if ( M24_Kontakt_Schutz::hat_link( $name . ' ' . $tnr . ' ' . $msg ) ) { return self::fail( 'm24_schutz_link', M24_Kontakt_Schutz::meldung_link() ); }
			if ( ! M24_Kontakt_Schutz::limit_frei() ) { return self::fail( 'm24_schutz_limit', M24_Kontakt_Schutz::meldung_limit(), 429 ); }
		}

		$thema_label = $topics[ $thema ];
		$land_name   = function_exists( 'm24_inquiry_country_name' ) ? m24_inquiry_country_name( $land ) : $land;
		$form_url    = esc_url_raw( (string) ( $p['form_url'] ?? '' ) );
		if ( $form_url && wp_parse_url( $form_url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$form_url = ''; // nur eigene Seiten als Quelle übernehmen
		}
		if ( '' === $form_url ) { $form_url = home_url( '/kontakt/' ); }

		// Kopfzeilen in die Notiz → erscheinen in der Info-Mail (Block „Nachricht") und als notes im Desk.
		$kopf  = 'Thema: ' . $thema_label . "\n";
		$kopf .= 'Ich bin: ' . $kunden[ $kund ] . "\n";
		$kopf .= 'Lieferland: ' . $land_name . "\n";
		if ( '' !== $tnr ) { $kopf .= 'Teilenummer / Artikel: ' . $tnr . "\n"; }
		$notes = $kopf . ( '' !== $msg ? "\n" . $msg : '' );

		$art   = 'Kontaktanfrage: ' . $thema_label;
		$items = M24_Inquiries_Form::sanitize_items( array( array(
			'art'        => $art . ( '' !== $tnr ? ' — ' . $tnr : '' ),
			'qty'        => '1',
			'price'      => '',
			'src_url'    => $form_url,
			'src_pillar' => ( 'fahrzeug' === $thema ) ? M24_Inquiries::PILLAR_FAHRZEUG : M24_Inquiries::PILLAR_KATALOG,
			'src_art_nr' => $tnr,
		) ) );

		$data = array(
			'title'               => $art . ' — ' . $name,
			'vorname'             => $name,
			'nachname'            => '',
			'email'               => $email,
			'tel'                 => '',
			'firma'               => '',
			'anrede'              => '',
			'strasse'             => '',
			'plz'                 => '',
			'ort'                 => '',
			'land'                => $land,
			'uid'                 => '',
			'biz'                 => ( 'Privat' === $kund ) ? 0 : 1, // Geschäftskunde + Werkstatt = B2B
			'notes'               => $notes,
			'inquiry_source'      => M24_Inquiries::SOURCE_CONTACT,
			'inquiry_source_meta' => M24_Inquiries_Validation::parse_source_meta( M24_Inquiries::SOURCE_CONTACT, (string) wp_json_encode( array(
				'form_url'             => $form_url,
				'user_message_excerpt' => '' !== $msg ? $msg : $thema_label,
			) ) ),
			'items'               => $items,
		);

		$post_id = M24_Inquiries_Storage::insert_inquiry( $data );
		if ( is_wp_error( $post_id ) ) {
			if ( class_exists( 'M24_Logger' ) ) {
				M24_Logger::error( 'kontakt_form', 'insert_inquiry fehlgeschlagen', array( 'code' => $post_id->get_error_code() ) );
			}
			return self::fail( $post_id->get_error_code(), 'Ihre Nachricht konnte nicht gespeichert werden. Bitte schreiben Sie an service@motorsport24.de. / Your message could not be saved.', 500 );
		}
		if ( $schutz ) { M24_Kontakt_Schutz::limit_zaehlen(); }

		do_action( 'm24_event', 'contact_form', array( 'post_id' => (int) $post_id, 'thema' => $thema ) );
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'inquiry_event', 'contact_form', array( 'post_id' => (int) $post_id, 'thema' => $thema ) );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
