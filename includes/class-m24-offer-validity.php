<?php
/**
 * M24 — Angebots-Laufzeit: eine Rechnung, ein Wortlaut.
 * Modul: includes/class-m24-offer-validity.php
 *
 * Die Frist selbst ist korrekt (VALID_DAYS ab Versand, Ende des 10. Tages). Falsch war die Anzeige:
 * sie maß die Spanne bis zum Tagesende und rundete auf — der Rest des Anlagetags zählte als voller
 * Tag, also "noch 11 Tage" bei 10 Tagen Frist. Gerechnet wird deshalb in KALENDERTAGEN in
 * Europe/Berlin: Datum von gültig-bis minus heutiges Datum, keine Uhrzeiten, kein ceil.
 *
 * Wichtig — was wohin gehört:
 *   label()  Countdown. NUR an live gerenderten Stellen (Operator-Karte, Kunden-Webansicht).
 *   line()   Datum. Für eingefrorene Dokumente (Mail, PDF). Der Text friert beim Versand ein, die
 *            Frist läuft weiter: wer die Mail drei Tage später öffnet, läse sonst eine falsche
 *            Frist. Bei einem bindenden Angebot (§ 145 BGB) muss die Annahmefrist nach § 148 BGB
 *            bestimmbar sein — ein relativer Wert in einem eingefrorenen Dokument ist das nicht.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Validity {

	const TZ = 'Europe/Berlin';

	/**
	 * Kalendertage bis einschließlich gültig-bis. 0 = läuft heute ab, negativ = vorbei.
	 * null, wenn kein Datum hinterlegt ist.
	 *
	 * @param DateTimeImmutable|null $now Nur für Tests (Grenzfälle um Mitternacht und über die
	 *                                    Sommerzeit-Umstellung); im Betrieb immer null.
	 */
	public static function days_left( $valid_until, ?DateTimeImmutable $now = null ): ?int {
		$vu = trim( (string) $valid_until );
		if ( '' === $vu ) { return null; }
		try {
			$tz  = new DateTimeZone( self::TZ );
			$end = DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $vu, 0, 10 ), $tz );
			if ( ! $end instanceof DateTimeImmutable ) { return null; }
			// Beide Seiten auf Mitternacht Berlin: die Differenz ist damit eine reine Kalenderrechnung
			// und übersteht die Sommerzeit-Umstellung (der 23-/25-Stunden-Tag zählt trotzdem als ein Tag).
			$ref   = $now instanceof DateTimeImmutable ? $now->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );
			$today = $ref->setTime( 0, 0, 0 );
			return (int) $today->diff( $end )->format( '%r%a' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Countdown-Wortlaut. NUR live rendern, nie in Mail oder PDF.
	 *
	 * @param string $lang de|en
	 */
	public static function label( $valid_until, string $lang = 'de', ?DateTimeImmutable $now = null ): string {
		$d = self::days_left( $valid_until, $now );
		if ( null === $d ) { return ''; }
		$en = ( 'en' === $lang );

		if ( $d < 0 )   { return $en ? 'Expired' : 'Abgelaufen'; }
		if ( 0 === $d ) { return $en ? 'expires today' : 'läuft heute ab'; }
		if ( 1 === $d ) { return $en ? '1 day left' : 'noch 1 Tag'; }
		return $en ? $d . ' days left' : 'noch ' . $d . ' Tage';
	}

	/** Countdown + Datum, wie es an den live gerenderten Stellen zusammen stehen soll. */
	public static function label_with_date( $valid_until, string $lang = 'de', ?DateTimeImmutable $now = null ): string {
		$l = self::label( $valid_until, $lang, $now );
		$d = self::date_str( $valid_until );
		if ( '' === $l ) { return ''; }
		if ( '' === $d ) { return $l; }
		return $l . ( 'en' === $lang ? ' · until ' : ' · bis ' ) . $d;
	}

	/**
	 * Satz für eingefrorene Dokumente — ausschließlich das Datum, kein relativer Wert.
	 */
	public static function line( $valid_until, string $lang = 'de' ): string {
		$d = self::date_str( $valid_until );
		if ( '' === $d ) { return ''; }
		return ( 'en' === $lang )
			? 'This offer is valid up to and including ' . $d . '.'
			: 'Dieses Angebot ist gültig bis einschließlich ' . $d . '.';
	}

	/** Datum als d.m.Y — dieselbe Schreibweise wie im übrigen Angebots-Chrome. */
	public static function date_str( $valid_until ): string {
		$vu = trim( (string) $valid_until );
		if ( '' === $vu ) { return ''; }
		$ts = strtotime( substr( $vu, 0, 10 ) );
		return $ts ? gmdate( 'd.m.Y', $ts ) : '';
	}
}
