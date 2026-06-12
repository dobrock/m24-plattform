<?php
/**
 * M24 Plattform — BMW-Teilenummer-Extractor
 * Modul: modules/importer/class-m24-bmw-teilenummer-extractor.php
 *
 * Findet originale BMW-OEM-Nummern in einem Beschreibungstext und normalisiert
 * sie auf das BMW-Standardformat "XX XX X XXX XXX".
 *
 * Strategie (Reihenfolge = Konfidenz):
 *  1. Cue-basiert:  „BMW(-)Teilenummer [links|rechts|vorne|hinten|oben|unten]?[: ]"
 *     gefolgt von einer Ziffernfolge mit beliebigen Separatoren (Space/Punkt/Dash).
 *  2. Muster-basiert: exaktes Pattern \d{2}\s\d{2}\s\d\s\d{3}\s\d{3} (genau 1 Treffer).
 *  3. Bei mehreren Kandidaten: skip (Reporting an CLI/CSV).
 *
 * Heuristik gegen Falschtreffer:
 *  - Preise „… EUR/€", Laufleistung „… km", Jahreszahlen, interne Art.-Nrn (M24…, 23-12 …)
 *    sind zu kurz oder strukturell anders → fallen automatisch raus.
 *  - Idempotent: Aufruf-Seite (CLI/Importer) prueft Override-Schutz selbst.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_BMW_Teilenummer_Extractor {

	/** Mögliche Qualifier nach "BMW-Teilenummer" (BMW unterscheidet links/rechts etc.). */
	const QUALIFIER_REGEX = '(?:links|rechts|vorne|hinten|oben|unten|innen|aussen|außen)?';

	/**
	 * Extrahiert eine BMW-Teilenummer aus Text.
	 *
	 * @param string $text Roh-Text (HTML wird intern gestrippt).
	 * @return array{number: string|null, source: string, candidates: string[]}
	 *   source ∈ {cue, muster, skip, none}
	 *   number nur gesetzt wenn source = cue|muster
	 *   candidates listet alle erkannten Roh-Nummern (fuer Skip-Reports)
	 */
	public static function extract( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return array( 'number' => null, 'source' => 'none', 'candidates' => array() );
		}
		// HTML strippen + normalize whitespace
		$plain = wp_strip_all_tags( $text );
		$plain = preg_replace( '/\s+/u', ' ', $plain );

		// 1. Cue-basiert
		$cue_pattern = '/BMW[\s\-]?Teilenummer\s*' . self::QUALIFIER_REGEX . '\s*[:\s]?\s*((?:\d[\d\s\.\-]*\d|\d))/iu';
		$cue_candidates = array();
		if ( preg_match_all( $cue_pattern, $plain, $matches ) ) {
			foreach ( $matches[1] as $raw ) {
				$norm = self::normalize( $raw );
				if ( null !== $norm ) { $cue_candidates[] = $norm; }
			}
			$unique = array_values( array_unique( $cue_candidates ) );
			if ( 1 === count( $unique ) ) {
				return array( 'number' => $unique[0], 'source' => 'cue', 'candidates' => $unique );
			}
			if ( count( $unique ) > 1 ) {
				return array( 'number' => null, 'source' => 'skip', 'candidates' => $unique );
			}
			// Cue gefunden, aber keine 11-stellige Nummer extrahiert → fall through zu Muster.
		}

		// 2. Muster-basiert (strikt mit Spaces)
		$muster_pattern = '/(?<!\d)(\d{2}\s\d{2}\s\d\s\d{3}\s\d{3})(?!\d)/u';
		if ( preg_match_all( $muster_pattern, $plain, $mm ) ) {
			$unique = array_values( array_unique( array_map( 'trim', $mm[1] ) ) );
			if ( 1 === count( $unique ) ) {
				return array( 'number' => $unique[0], 'source' => 'muster', 'candidates' => $unique );
			}
			if ( count( $unique ) > 1 ) {
				return array( 'number' => null, 'source' => 'skip', 'candidates' => $unique );
			}
		}

		return array( 'number' => null, 'source' => 'none', 'candidates' => array() );
	}

	/**
	 * Entfernt interne Leerzeichen aus BMW-Teilenummer-Patterns im Titel — für die
	 * Google-Findbarkeit als zusammenhaengende Ziffernfolge. Idempotent.
	 *
	 * Erkannte Patterns:
	 *  - 11-stellig (klassische OEM):  XX XX X XXX XXX → XXXXXXXXXXX
	 *  - 7-stellig (Kurzform):         X XXX XXX → XXXXXXX
	 *
	 * Wortgrenzen (\b) verhindern Verwechslung mit anderen Zahlenfolgen im Titel.
	 */
	public static function compact_in_title( $title ) {
		$title = (string) $title;
		// 11-Ziffer
		$title = preg_replace( '/\b(\d{2})\s(\d{2})\s(\d)\s(\d{3})\s(\d{3})\b/', '$1$2$3$4$5', $title );
		// 7-Ziffer
		$title = preg_replace( '/\b(\d)\s(\d{3})\s(\d{3})\b/', '$1$2$3', $title );
		return $title;
	}

	/**
	 * Normalisiert eine Rohnummer (mit Separatoren) auf BMW-Standardformat.
	 *  - 11 Ziffern → "XX XX X XXX XXX" (klassisches OEM-Format)
	 *  - 7  Ziffern → "X XXX XXX"        (Kurzformat, z.B. Steuergeraet/DSC)
	 *  - Andere Längen → null.
	 *
	 * Wird in extract() vom Cue-Pfad genutzt. Das Standalone-Muster-Pattern bleibt
	 * absichtlich strikt 11-stellig (Falsch-Positiv-Schutz ohne Cue).
	 */
	public static function normalize( $raw ) {
		$digits = preg_replace( '/\D/', '', (string) $raw );
		$len    = strlen( $digits );
		if ( 11 === $len ) {
			return substr( $digits, 0, 2 ) . ' '
				. substr( $digits, 2, 2 ) . ' '
				. substr( $digits, 4, 1 ) . ' '
				. substr( $digits, 5, 3 ) . ' '
				. substr( $digits, 8, 3 );
		}
		if ( 7 === $len ) {
			return substr( $digits, 0, 1 ) . ' '
				. substr( $digits, 1, 3 ) . ' '
				. substr( $digits, 4, 3 );
		}
		return null;
	}
}
