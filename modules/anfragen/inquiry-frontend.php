<?php
/**
 * M24 Plattform — Anfragen: Front-End-Controller
 * Modul: inquiry-frontend.php
 *
 * Bindet Modal-CSS/JS auf dem Front-End ein, reicht Konfiguration (REST-URL,
 * Nonce, PPWR-Daten, Laenderliste) nach JS und druckt das Modal-Markup in den
 * Footer. Das JS verdrahtet die vorhandenen Detail-Buttons:
 *   .m24-frage  -> Modal (REST /inquiry)
 *   .m24-merken -> window.M24Sidebar.addItem() (bestehende Sammelanfrage)
 * und injiziert "Per E-Mail an mich senden" ins bestehende Sidebar-Panel.
 *
 * Kein neues Merkzettel-System — die Sidebar IST der Merkzettel.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Inquiry_Frontend {

	private static $initialized = false;

	/**
	 * Europaeische Laender (ISO -> Name) fuer das Autocomplete-Feld im Modal.
	 * EU-27 + EFTA (CH/NO/IS/LI) + UK + weitere europaeische Staaten.
	 */
	public static function lands() {
		return array(
			'AL' => 'Albanien', 'AD' => 'Andorra', 'AT' => 'Österreich', 'BE' => 'Belgien',
			'BA' => 'Bosnien und Herzegowina', 'BG' => 'Bulgarien', 'HR' => 'Kroatien',
			'CY' => 'Zypern', 'CZ' => 'Tschechien', 'DK' => 'Dänemark', 'DE' => 'Deutschland',
			'EE' => 'Estland', 'FI' => 'Finnland', 'FR' => 'Frankreich', 'GR' => 'Griechenland',
			'GB' => 'Großbritannien', 'HU' => 'Ungarn', 'IE' => 'Irland', 'IS' => 'Island',
			'IT' => 'Italien', 'XK' => 'Kosovo', 'LV' => 'Lettland', 'LI' => 'Liechtenstein',
			'LT' => 'Litauen', 'LU' => 'Luxemburg', 'MT' => 'Malta', 'MD' => 'Moldau',
			'MC' => 'Monaco', 'ME' => 'Montenegro', 'NL' => 'Niederlande', 'MK' => 'Nordmazedonien',
			'NO' => 'Norwegen', 'PL' => 'Polen', 'PT' => 'Portugal', 'RO' => 'Rumänien',
			'SM' => 'San Marino', 'SE' => 'Schweden', 'CH' => 'Schweiz', 'RS' => 'Serbien',
			'SK' => 'Slowakei', 'SI' => 'Slowenien', 'ES' => 'Spanien', 'TR' => 'Türkei',
			'UA' => 'Ukraine',
		);
	}

	/**
	 * Server-seitig akzeptierte ISO-Codes (Superset): europaeische Liste + die
	 * Nicht-EU-Codes, die das bestehende Sammelanfrage-Formular weiter anbietet
	 * (US) — damit der gemeinsame Validation-Check jenes Formular nicht bricht.
	 */
	public static function accepted_iso() {
		return array_merge( array_keys( self::lands() ), array( 'US' ) );
	}

	public static function init() {
		if ( self::$initialized ) { return; }
		self::$initialized = true;
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_modal' ) );
	}

	public static function enqueue() {
		if ( is_admin() ) { return; }

		$base    = plugin_dir_url( M24_PLATTFORM_FILE );
		$version = defined( 'M24_PLATTFORM_VERSION' ) ? M24_PLATTFORM_VERSION : '0.1.0';

		// Gemeinsames Feld-Styling/-Verhalten (beide Anfrage-Modals identisch).
		wp_enqueue_style( 'm24-inquiry-fields', $base . 'assets/css/m24-inquiry-fields.css', array(), $version );
		wp_enqueue_script( 'm24-inquiry-fields', $base . 'assets/js/m24-inquiry-fields.js', array(), $version, true );

		wp_enqueue_style( 'm24-inquiry-modal', $base . 'assets/css/inquiry-modal.css', array( 'm24-inquiry-fields' ), $version );
		wp_enqueue_script( 'm24-inquiry-modal', $base . 'assets/js/inquiry-modal.js', array( 'm24-inquiry-fields' ), $version, true );

		wp_localize_script( 'm24-inquiry-modal', 'M24InquiryConfig', array(
			'restUrl'          => esc_url_raw( rest_url( M24_Inquiry_Submit::NS . '/' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'ppwr'             => class_exists( 'M24_PPWR' ) ? M24_PPWR::js_data() : array(),
			'lands'            => self::lands(),
			'userCanSeePrices' => class_exists( 'M24_Inquiries' ) ? M24_Inquiries::user_can_see_prices() : false,
			'i18n'             => array(
				'emailToMe'   => __( 'Merkzettel per E-Mail senden', 'm24-plattform' ),
				'emailPrompt' => __( 'Deine E-Mail (für Rückfragen, optional):', 'm24-plattform' ),
				'sent'        => __( 'Gesendet ✓', 'm24-plattform' ),
				'added'       => __( 'Zum Merkzettel hinzugefügt', 'm24-plattform' ),
				'genericErr'  => __( 'Es ist ein Fehler aufgetreten.', 'm24-plattform' ),
				'landUnknown' => __( 'Bitte ein Land aus der Liste wählen.', 'm24-plattform' ),
				'success'     => 'Vielen Dank, deine Anfrage ist eingegangen. Wir melden uns kurzfristig bei dir.',
			),
		) );
	}

	/** Modal-Skelett (versteckt) im Footer. Felder spiegeln den insert_inquiry-Vertrag. */
	public static function render_modal() {
		if ( is_admin() ) { return; }
		$lands = self::lands();
		?>
		<div class="m24iq-overlay" id="m24iq-overlay" hidden>
			<div class="m24iq-modal" role="dialog" aria-modal="true" aria-labelledby="m24iq-title">
				<button type="button" class="m24iq-close" data-m24iq="close" aria-label="<?php esc_attr_e( 'Schließen', 'm24-plattform' ); ?>">&times;</button>
				<h2 class="m24iq-title" id="m24iq-title"><?php esc_html_e( 'Frage stellen', 'm24-plattform' ); ?></h2>

				<div class="m24iq-ref" data-m24iq="ref"></div>

				<form class="m24iq-form" data-m24iq="form" novalidate>
					<div class="m24iq-notice" data-m24iq="notice" hidden></div>

					<?php m24_inquiry_fields(); // gemeinsames Feld-Set (name, email, kundentyp, lieferland, nachricht, consent) ?>

					<input type="text" name="website_confirm" class="m24iq-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

					<div class="m24iq-actions">
						<button type="submit" class="m24iq-submit" data-m24iq="submit"><?php esc_html_e( 'Anfrage absenden', 'm24-plattform' ); ?></button>
					</div>
				</form>

				<div class="m24iq-success" data-m24iq="success" hidden></div>
			</div>
		</div>
		<?php
	}
}
