<?php
/**
 * M24 Plattform — BMW-Modell-Parser
 * Modul: modules/importer/class-m24-bmw-models.php
 *
 * Liest data/bmw-models.json und matcht Chassis-Codes (z.B. "E36", "X5 F15")
 * gegen einen Produkt-Namen + optional Kategorie-Kontext.
 *
 * Spec v3.1 (Daniel):
 *  - M-Annahme NUR durch M-Wort (M2/M3/M4/...) oder M-Motorcode IM PRODUKTNAMEN.
 *    Kategorie-Sammelnamen wie „BMW 2er F22/F23/F87" duerfen ein regulaeres
 *    F22-Teil NICHT zu M2 machen.
 *  - Chassis-Detection darf weiter im Name + Kategorie-Kontext suchen (wichtig
 *    fuer Faelle wo nur die Kategorie das Chassis nennt).
 *  - Z4M nie M3: S54 + Z-Chassis (E85/E86/E89) ODER „Z4 M" im Name → „Sonstige
 *    BMW M Modelle", nicht M3 E46. „M3 E89" gibt es nicht.
 *  - Motorcode-Match per Praefix → faengt auch volle Codes „S14B25", „S65B40".
 *  - Serie-Term mit „BMW "-Praefix → „BMW 1er/2er/3er/X5/Z4/...".
 *  - CSV-Spalte zeigt term_name (= finaler Term), nicht den rohen display.
 *
 * Erweiterbar via Filter `m24_bmw_models` (Array von Model-Objekten).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_BMW_Models {

	/** M3-Fokus: nur diese Chassis bekommen den spezifischen Term "M3 <Chassis>"
	 *  (bzw. "M3 E9x" fuer die E90/E91/E92/E93-Familie, die zusammenfaellt). */
	const M3_CHASSIS = array( 'E30', 'E36', 'E46', 'E90', 'E91', 'E92', 'E93' );

	/** E9x-Familie kollabiert auf einen Term "M3 E9x". */
	const M3_E9X_FAMILY = array( 'E90', 'E91', 'E92', 'E93' );

	/** M3-Motorcodes — implizit M3-Erkennung (auch ohne "M3" im Namen) + implied Chassis.
	 *  Praefix-Match: "S14" faengt auch "S14B25" etc. */
	const M3_MOTORCODES = array(
		'S14' => 'E30',
		'S50' => 'E36',
		'S52' => 'E36',
		'S54' => 'E46',
		'S65' => 'E92',
	);

	/** Z4-Chassis (Roadster). E85/E86 hatten Z4M-Variante mit S54-Motor.
	 *  E89 hatte keine M-Variante, wird hier trotzdem gegen Falsch-M3 abgeschirmt. */
	const Z_CHASSIS = array( 'E85', 'E86', 'E89' );

	/** Sammelkategorie "Sonstige BMW M Modelle" — Erkennung NUR ueber:
	 *  - M-Wort (M2/M4/M5/M6/M7/M8) im PRODUKTNAMEN
	 *  - M-Motorcode (S55/S58/S62/S63/S38/S68) im PRODUKTNAMEN
	 *  - "M3"-Wort im Name + Chassis nicht in M3_CHASSIS-Fokus
	 *  Chassis-Codes (F80/G80/F87/etc.) ALLEIN reichen NICHT mehr. */
	const SONSTIGE_M_WORDS      = array( 'M2', 'M4', 'M5', 'M6', 'M7', 'M8' );
	const SONSTIGE_M_MOTORCODES = array( 'S55', 'S58', 'S62', 'S63', 'S38', 'S68' );

	private static $models = null;

	/** Laedt Modell-Liste aus JSON, cached pro Request. Filter `m24_bmw_models`. */
	public static function load() {
		if ( null !== self::$models ) { return self::$models; }
		$path = M24_PLATTFORM_DIR . 'data/bmw-models.json';
		$models = array();
		if ( file_exists( $path ) ) {
			$raw  = file_get_contents( $path );
			$data = json_decode( (string) $raw, true );
			if ( is_array( $data ) && isset( $data['models'] ) && is_array( $data['models'] ) ) {
				$models = $data['models'];
			}
		}
		self::$models = apply_filters( 'm24_bmw_models', $models );
		return self::$models;
	}

	/**
	 * Parst Modell aus Produkt-Name + optional Kategorie-Kontext.
	 *
	 * @param string $name    Produkt-Name (primaere und einzige Quelle fuer M-Erkennung).
	 * @param string $context Zusatztext (z.B. Shopware-Kategorien joined). Wird NUR fuer
	 *                        Chassis-Detection verwendet, NICHT fuer M-Wort/Motorcode-Erkennung.
	 *
	 * @return array|null Map mit chassis/display/slug/serie/is_m3/term_name, oder null.
	 */
	public static function parse_from_name( $name, $context = '' ) {
		$name = (string) $name;
		if ( '' === trim( $name ) ) { return null; }
		$scan = $name . ' ' . (string) $context;

		// ── Helpers ──────────────────────────────────────────────
		// M-Detection NUR im Namen (Spec v3.1).
		$has_word_in_name = function( $w ) use ( $name ) {
			return (bool) preg_match( '/\b' . preg_quote( (string) $w, '/' ) . '\b/i', $name );
		};
		// Praefix-Match fuer Motorcodes — faengt auch volle Codes wie "S14B25", "S65B40".
		$has_motor_prefix_in_name = function( $code ) use ( $name ) {
			return (bool) preg_match( '/\b' . preg_quote( (string) $code, '/' ) . '\w*/i', $name );
		};
		// Chassis-Detection darf den Kategorie-Kontext mitlesen.
		$has_word_in_scan = function( $w ) use ( $scan ) {
			return (bool) preg_match( '/\b' . preg_quote( (string) $w, '/' ) . '\b/i', $scan );
		};

		// ── 1. M-Indikatoren aus dem NAMEN ───────────────────────
		$m3_motor_chassis = '';
		foreach ( self::M3_MOTORCODES as $code => $implied ) {
			if ( $has_motor_prefix_in_name( $code ) ) { $m3_motor_chassis = $implied; break; }
		}
		$sonstige_motor_found = false;
		foreach ( self::SONSTIGE_M_MOTORCODES as $code ) {
			if ( $has_motor_prefix_in_name( $code ) ) { $sonstige_motor_found = true; break; }
		}
		$sonstige_word_found = false;
		foreach ( self::SONSTIGE_M_WORDS as $word ) {
			if ( $has_word_in_name( $word ) ) { $sonstige_word_found = true; break; }
		}
		$name_has_m3 = $has_word_in_name( 'M3' );

		// ── 2. Chassis-Match aus JSON (Name + Kategorie-Kontext) ─
		$chassis = ''; $display = ''; $slug = ''; $serie = '';
		foreach ( self::load() as $m ) {
			$patterns = ( isset( $m['match'] ) && is_array( $m['match'] ) )
				? $m['match']
				: array( isset( $m['chassis'] ) ? (string) $m['chassis'] : '' );
			foreach ( $patterns as $pattern ) {
				$pattern = trim( (string) $pattern );
				if ( '' === $pattern ) { continue; }
				if ( $has_word_in_scan( $pattern ) ) {
					$chassis = (string) ( $m['chassis'] ?? $pattern );
					$display = (string) ( $m['display'] ?? $chassis );
					$slug    = (string) ( $m['slug']    ?? sanitize_title( $chassis ) );
					$serie_explicit = isset( $m['serie'] ) ? trim( (string) $m['serie'] ) : '';
					$serie   = ( '' !== $serie_explicit ) ? $serie_explicit : (string) ( strtok( $display, ' ' ) ?: $chassis );
					break 2;
				}
			}
		}

		// ── 3. Fallback: kein Chassis aus JSON, aber M3-Motorcode → implied Chassis
		if ( '' === $chassis && '' !== $m3_motor_chassis ) {
			$chassis = $m3_motor_chassis;
			$slug    = sanitize_title( $chassis );
			$serie   = '3er';
			$display = 'M3 ' . $chassis;
		}

		// Helper: valide Serie fuer „BMW <Serie>"-Multi-Term?
		// M-Codes (M2/M3/M4/...) und „Sonstige M" sind keine Serien-Terms, sondern
		// Sonstige-Marker — kein „BMW M2"-Term in der gewuenschten Liste.
		$is_valid_serie_for_bmw_prefix = function( $s ) {
			if ( '' === $s ) { return false; }
			if ( 'Sonstige M' === $s ) { return false; }
			if ( preg_match( '/^M[2-8]$/', $s ) ) { return false; }
			return true;
		};

		// ── 4. Z4M-Override (vor M3-Fokus) ───────────────────────
		// Spec: S54 + Z-Chassis (E85/E86/E89) ODER „Z4 M" im Namen → Sonstige BMW M Modelle,
		// nicht M3 E46. „M3 E89" gibt es nicht.
		$is_z_chassis    = in_array( $chassis, self::Z_CHASSIS, true );
		$has_z4_m_phrase = (bool) preg_match( '/\bZ4\s+M\b/i', $name );
		$z4m_detected    = $has_z4_m_phrase
			|| ( $is_z_chassis && '' !== $m3_motor_chassis );
		if ( $z4m_detected ) {
			$term_name = 'Sonstige BMW M Modelle';
			if ( '' === $serie ) { $serie = 'Sonstige M'; }
			// Multi-Term: Sonstige + „BMW Z4" (Z4M passt auch in Z4-Filter)
			$term_names = array( $term_name );
			if ( $is_valid_serie_for_bmw_prefix( $serie ) ) {
				$term_names[] = 'BMW ' . $serie;
			}
			return array(
				'chassis'    => $chassis,
				'display'    => $term_name,
				'slug'       => $slug,
				'serie'      => $serie,
				'is_m3'      => false,
				'term_name'  => $term_name,
				'term_names' => $term_names,
			);
		}

		// ── 5. M3-Fokus (E30/E36/E46/E9x) ────────────────────────
		// Bewusst single-term: M3-Fokus-Liste soll den 3er-Filter nicht zumuellen.
		$is_m3_focus_chassis = in_array( $chassis, self::M3_CHASSIS, true );
		$is_m3_focus         = ( $is_m3_focus_chassis && $name_has_m3 ) || ( '' !== $m3_motor_chassis );
		if ( $is_m3_focus ) {
			$term_chassis = in_array( $chassis, self::M3_E9X_FAMILY, true ) ? 'E9x' : $chassis;
			$term_name    = 'M3 ' . $term_chassis;
			return array(
				'chassis'    => $chassis,
				'display'    => $term_name,
				'slug'       => $slug,
				'serie'      => 'M3',
				'is_m3'      => true,
				'term_name'  => $term_name,
				'term_names' => array( $term_name ),
			);
		}

		// ── 6. Sonstige BMW M Modelle ────────────────────────────
		// Nur echtes M: M-Wort/Motorcode im NAMEN, oder „M3" + Nicht-Fokus-Chassis.
		// Multi-Term: Sonstige + Serie-Term, falls Serie valide.
		$is_sonstige_m = $sonstige_motor_found
			|| $sonstige_word_found
			|| ( $name_has_m3 && ! $is_m3_focus_chassis );
		if ( $is_sonstige_m ) {
			$term_name = 'Sonstige BMW M Modelle';
			if ( '' === $serie ) { $serie = 'Sonstige M'; }
			$term_names = array( $term_name );
			if ( $is_valid_serie_for_bmw_prefix( $serie ) ) {
				$term_names[] = 'BMW ' . $serie;
			}
			return array(
				'chassis'    => $chassis,
				'display'    => $term_name,
				'slug'       => $slug,
				'serie'      => $serie,
				'is_m3'      => false,
				'term_name'  => $term_name,
				'term_names' => $term_names,
			);
		}

		// ── 7. Serie (Fallback) ───────────────────────────────────
		// Term-Name mit „BMW "-Praefix → „BMW 1er/2er/3er/4er/5er/X5/X6/Z4/...".
		if ( '' === $chassis ) { return null; }
		$term_name = ( '' !== $serie ) ? ( 'BMW ' . $serie ) : '';
		return array(
			'chassis'    => $chassis,
			'display'    => $display,
			'slug'       => $slug,
			'serie'      => $serie,
			'is_m3'      => false,
			'term_name'  => $term_name,
			'term_names' => ( '' !== $term_name ) ? array( $term_name ) : array(),
		);
	}
}
