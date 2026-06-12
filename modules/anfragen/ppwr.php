<?php
/**
 * M24 Plattform — Anfragen: PPWR-Lieferland-Gate
 * Modul: ppwr.php  (reiner Helfer — kein init()/keine Hooks)
 *
 * EU-Lieferung nur nach NL/BE/FR/ES erlaubt (PPWR-Uebergangsphase). Drittlaender
 * (nicht-EU) sind nicht betroffen -> erlaubt. Uebrige EU-Mitgliedstaaten gesperrt.
 *   blocked = land ∈ EU_MEMBER && land ∉ ALLOWED_EU
 *
 * Server-seitig in M24_Inquiries_Validation::validate() genutzt (gilt damit fuer
 * Modal UND Sammelanfrage). Client-Daten via M24_PPWR::js_data().
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Globale Konstanten (vom Brief §4 so benannt). Arrays via define() ab PHP 7.
if ( ! defined( 'M24_EU_MEMBER' ) ) {
	define( 'M24_EU_MEMBER', array(
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
		'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
		'SI', 'ES', 'SE',
	) );
}
if ( ! defined( 'M24_PPWR_ALLOWED_EU' ) ) {
	define( 'M24_PPWR_ALLOWED_EU', array( 'DE', 'NL', 'BE', 'FR', 'ES' ) );
}
if ( ! defined( 'M24_PPWR_NOTICE' ) ) {
	// PLATZHALTER — Daniel finalisiert spaeter (Ein-Zeilen-Aenderung bzw. Filter unten).
	define( 'M24_PPWR_NOTICE', '[PLATZHALTER PPWR-HINWEIS] Eine Lieferung in dieses EU-Land ist aufgrund der EU-Verpackungsverordnung (PPWR) derzeit leider nicht möglich. Bitte kontaktieren Sie uns für Alternativen.' );
}

class M24_PPWR {

	/** Ist eine Lieferung in dieses ISO-Land PPWR-gesperrt? */
	public static function is_blocked( $iso ) {
		$iso = strtoupper( trim( (string) $iso ) );
		if ( '' === $iso ) {
			return false;
		}
		return in_array( $iso, M24_EU_MEMBER, true )
			&& ! in_array( $iso, M24_PPWR_ALLOWED_EU, true );
	}

	/** Hinweistext (per Filter spaeter finalisierbar ohne Code-Edit). */
	public static function notice() {
		return (string) apply_filters( 'm24_ppwr_notice', M24_PPWR_NOTICE );
	}

	/** Daten-Paket fuer wp_localize_script (Client-Spiegelung der Server-Logik). */
	public static function js_data() {
		return array(
			'euMember'  => array_values( M24_EU_MEMBER ),
			'allowedEu' => array_values( M24_PPWR_ALLOWED_EU ),
			'notice'    => self::notice(),
		);
	}
}
