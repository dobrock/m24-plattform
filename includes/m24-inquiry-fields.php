<?php
/**
 * M24 — Gemeinsames Anfrage-Formular-Partial
 * Modul: includes/m24-inquiry-fields.php
 *
 * EINE Quelle für das Feld-Set BEIDER Anfrage-Formulare (Teile „Frage stellen" + Fahrzeug
 * „Jetzt anfragen") — garantiert identisch. Felder: name, email, kundentyp (Segmented Toggle),
 * lieferland (Select, value = ISO), nachricht (optional), consent. Reine Render-Helfer.
 *
 * Styling: assets/css/m24-inquiry-fields.css (.m24-iqf …); Verhalten/Validierung:
 * assets/js/m24-inquiry-fields.js (window.M24IqFields). Beide werden von M24_Inquiry_Frontend
 * global im Frontend enqueued, sodass beide Modals identisch aussehen/funktionieren.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Lieferländer (ISO => Name) — eine Liste für beide Formulare. */
function m24_inquiry_countries() {
	if ( class_exists( 'M24_Inquiry_Frontend' ) ) {
		return M24_Inquiry_Frontend::lands();
	}
	return array( 'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz' );
}

/** ISO-Code → lesbarer Ländername (für Mail/Desk/Brevo). Unbekannt → Eingabe unverändert. */
function m24_inquiry_country_name( $iso ) {
	$iso = strtoupper( trim( (string) $iso ) );
	$map = m24_inquiry_countries();
	return isset( $map[ $iso ] ) ? $map[ $iso ] : $iso;
}

/** Consent-Text inkl. Datenschutz-Link (eine Quelle für beide Formulare). */
function m24_inquiry_consent_html() {
	$ds_url = function_exists( 'm24_datenschutz_url' ) ? m24_datenschutz_url() : '';
	$link   = esc_html__( 'Datenschutzerklärung', 'm24-plattform' );
	$link   = $ds_url ? '<a href="' . esc_url( $ds_url ) . '" target="_blank" rel="noopener">' . $link . '</a>' : $link;
	$tpl    = function_exists( 'm24_consent_text' )
		? m24_consent_text()
		: 'Ich willige in die Verarbeitung meiner Angaben zur Bearbeitung der Anfrage ein. Hinweise zur Verarbeitung finde ich in der %s. *';
	return wp_kses( sprintf( $tpl, $link ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) );
}

/**
 * Rendert das gemeinsame Feld-Set (innerhalb des jeweiligen <form>). Honeypot/Hidden-Context
 * (post_id, items_json) bleiben pro Modal außerhalb — hier nur die sichtbaren Nutzerfelder.
 */
function m24_inquiry_fields() {
	$countries = m24_inquiry_countries();
	?>
	<div class="m24-iqf">
		<div class="m24-iqf-field">
			<input type="text" name="name" class="m24-iqf-input" placeholder="<?php esc_attr_e( 'Vor- und Nachname', 'm24-plattform' ); ?>" required minlength="2" autocomplete="name">
		</div>
		<div class="m24-iqf-field">
			<input type="email" name="email" class="m24-iqf-input" placeholder="<?php esc_attr_e( 'E-Mail *', 'm24-plattform' ); ?>" required autocomplete="email">
		</div>
		<div class="m24-iqf-field">
			<div class="m24-iqf-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Kundentyp', 'm24-plattform' ); ?>" data-m24-kundentyp>
				<button type="button" class="m24-iqf-seg-btn" role="radio" aria-checked="false" data-val="Privat" tabindex="0"><?php esc_html_e( 'Privat', 'm24-plattform' ); ?></button>
				<button type="button" class="m24-iqf-seg-btn" role="radio" aria-checked="false" data-val="Geschäftskunde" tabindex="-1"><?php esc_html_e( 'Geschäftskunde', 'm24-plattform' ); ?></button>
				<input type="hidden" name="kundentyp" value="">
			</div>
		</div>
		<div class="m24-iqf-field">
			<select name="lieferland" class="m24-iqf-input m24-iqf-select" required>
				<option value="" disabled selected><?php esc_html_e( 'Lieferland (bitte wählen) *', 'm24-plattform' ); ?></option>
				<?php foreach ( $countries as $iso => $label ) : ?>
					<option value="<?php echo esc_attr( $iso ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="m24-iqf-field">
			<textarea name="nachricht" class="m24-iqf-input m24-iqf-textarea" rows="3" placeholder="<?php esc_attr_e( 'Nachricht (optional)', 'm24-plattform' ); ?>"></textarea>
		</div>
		<label class="m24-iqf-consent">
			<input type="checkbox" name="consent" value="1" required>
			<span><?php echo m24_inquiry_consent_html(); // phpcs:ignore WordPress.Security.EscapeOutput — bereits via wp_kses. ?></span>
		</label>
		<label class="m24-iqf-optin">
			<input type="checkbox" name="il_optin" value="1">
			<span><?php esc_html_e( 'Ja, informiert mich über passende Fahrzeuge/Teile (Interessentenliste, jederzeit abbestellbar).', 'm24-plattform' ); ?></span>
		</label>
		<p class="m24-iqf-error" data-m24-iqf-error role="alert" hidden></p>
	</div>
	<?php
}
