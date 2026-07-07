<?php
/**
 * M24 Plattform — Länder-Flaggen (Ansatz A: ISO2 + Regional-Indicator-Codepoints).
 *
 * Wandelt eine (auch freie) Länder-Eingabe in ISO2 + Emoji-Flagge um, ohne die Roh-Eingabe zu verändern.
 * Braucht mbstring (WP-Standard). Namens-Auflösung nutzt M24_I18n::countries() (DE/EN) + kuratierte Aliase.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Country_Flags {

	/** Sonderfälle/Aliase (uppercase) → ISO2. Deckt gängige Schreibweisen ab, die nicht 1:1 im I18n-Namen stehen. */
	private static function aliases(): array {
		return array(
			'USA' => 'US', 'U.S.A.' => 'US', 'UNITED STATES' => 'US', 'VEREINIGTE STAATEN' => 'US', 'AMERIKA' => 'US',
			'UK' => 'GB', 'U.K.' => 'GB', 'ENGLAND' => 'GB', 'GROSSBRITANNIEN' => 'GB', 'GROẞBRITANNIEN' => 'GB',
			'GREAT BRITAIN' => 'GB', 'UNITED KINGDOM' => 'GB', 'VEREINIGTES KÖNIGREICH' => 'GB', 'VEREINIGTES KOENIGREICH' => 'GB',
			'DEUTSCHLAND' => 'DE', 'GERMANY' => 'DE', 'BRD' => 'DE',
			'ÖSTERREICH' => 'AT', 'OESTERREICH' => 'AT', 'AUSTRIA' => 'AT',
			'SCHWEIZ' => 'CH', 'SWITZERLAND' => 'CH', 'SUISSE' => 'CH',
			'FRANKREICH' => 'FR', 'FRANCE' => 'FR', 'ITALIEN' => 'IT', 'ITALY' => 'IT', 'SPANIEN' => 'ES', 'SPAIN' => 'ES',
			'NIEDERLANDE' => 'NL', 'NETHERLANDS' => 'NL', 'HOLLAND' => 'NL', 'BELGIEN' => 'BE', 'BELGIUM' => 'BE',
			'LUXEMBURG' => 'LU', 'POLEN' => 'PL', 'POLAND' => 'PL', 'TSCHECHIEN' => 'CZ', 'CZECHIA' => 'CZ',
			'DÄNEMARK' => 'DK', 'DAENEMARK' => 'DK', 'DENMARK' => 'DK', 'SCHWEDEN' => 'SE', 'SWEDEN' => 'SE',
			'NORWEGEN' => 'NO', 'NORWAY' => 'NO', 'FINNLAND' => 'FI', 'FINLAND' => 'FI', 'PORTUGAL' => 'PT',
			'GRIECHENLAND' => 'GR', 'GREECE' => 'GR', 'IRLAND' => 'IE', 'IRELAND' => 'IE',
			'KANADA' => 'CA', 'CANADA' => 'CA', 'AUSTRALIEN' => 'AU', 'AUSTRALIA' => 'AU', 'JAPAN' => 'JP',
			'CHINA' => 'CN', 'RUSSLAND' => 'RU', 'RUSSIA' => 'RU', 'TÜRKEI' => 'TR', 'TUERKEI' => 'TR', 'TURKEY' => 'TR',
			'VAE' => 'AE', 'UAE' => 'AE', 'UNITED ARAB EMIRATES' => 'AE', 'VEREINIGTE ARABISCHE EMIRATE' => 'AE',
		);
	}

	private static function upper( string $s ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s, 'UTF-8' ) : strtoupper( $s );
	}

	/** Freie Länder-Eingabe (Name/ISO2/Alias) → ISO2 ('' wenn nicht auflösbar). Verändert die Eingabe NICHT. */
	public static function countryToIso2( $land ): string {
		$s = trim( (string) $land );
		if ( '' === $s ) { return ''; }
		$u = self::upper( $s );
		if ( preg_match( '/^[A-Z]{2}$/', $u ) ) { return $u; } // bereits ISO2
		$alias = self::aliases();
		if ( isset( $alias[ $u ] ) ) { return $alias[ $u ]; }
		if ( class_exists( 'M24_I18n' ) ) {
			foreach ( array( 'de', 'en' ) as $lang ) {
				foreach ( M24_I18n::countries( $lang ) as $iso => $name ) {
					if ( self::upper( (string) $name ) === $u ) { return (string) $iso; }
				}
			}
		}
		return '';
	}

	/** ISO2 → Emoji-Flagge (Regional-Indicator-Codepoints). '' bei ungültigem Code. */
	public static function iso2ToFlag( $iso2 ): string {
		$iso2 = strtoupper( trim( (string) $iso2 ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $iso2 ) ) { return ''; }
		$base = 0x1F1E6; // Regional Indicator Symbol Letter A
		$out  = '';
		foreach ( str_split( $iso2 ) as $c ) {
			$cp   = $base + ( ord( $c ) - 65 );
			$out .= function_exists( 'mb_chr' ) ? mb_chr( $cp, 'UTF-8' ) : self::cp_to_utf8( $cp );
		}
		return $out;
	}

	/** Codepoint → UTF-8 (Fallback ohne mb_chr). */
	private static function cp_to_utf8( int $cp ): string {
		if ( $cp < 0x80 ) { return chr( $cp ); }
		if ( $cp < 0x800 ) { return chr( 0xC0 | $cp >> 6 ) . chr( 0x80 | $cp & 0x3F ); }
		if ( $cp < 0x10000 ) { return chr( 0xE0 | $cp >> 12 ) . chr( 0x80 | ( $cp >> 6 & 0x3F ) ) . chr( 0x80 | $cp & 0x3F ); }
		return chr( 0xF0 | $cp >> 18 ) . chr( 0x80 | ( $cp >> 12 & 0x3F ) ) . chr( 0x80 | ( $cp >> 6 & 0x3F ) ) . chr( 0x80 | $cp & 0x3F );
	}

	/** @return array{raw:string,iso2:string,flag:string,name:string} */
	public static function resolve( $land ): array {
		$raw  = trim( (string) $land );
		$iso  = self::countryToIso2( $raw );
		$flag = '' !== $iso ? self::iso2ToFlag( $iso ) : '';
		return array( 'raw' => $raw, 'iso2' => $iso, 'flag' => $flag, 'name' => $raw );
	}

	/** „🇺🇸 USA" — Flagge + (verbatim) Land. '' wenn Eingabe leer. */
	public static function getFlagAndCountry( $land ): string {
		$r = self::resolve( $land );
		if ( '' === $r['raw'] ) { return ''; }
		return trim( ( '' !== $r['flag'] ? $r['flag'] . ' ' : '' ) . $r['name'] );
	}
}
