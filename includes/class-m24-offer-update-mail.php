<?php
/**
 * M24 — Transaktionsmail „Angebot aktualisiert" / "Quote updated".
 * Modul: includes/class-m24-offer-update-mail.php
 *
 * RECHTLICHER RAHMEN (unverändert): Ein versendetes bindendes Angebot bindet nach § 145 BGB für die
 * Laufzeit; ein einseitiger Widerruf ist unwirksam. Der Text behauptet deshalb NICHT, die vorherige
 * Fassung sei ungültig oder zurückgezogen. Er stellt die neue Fassung daneben und BITTET um
 * Bestätigung — mehr nicht. Das gilt in beiden Sprachen wörtlich gleich.
 *
 * SPRACHE (16.09.2026): Die Mail folgt der ANGEBOTSSPRACHE, nicht dem Server. Gelesen wird
 * src_json.lang, dieselbe Quelle, aus der auch der Editor und die Angebotsmail ihre Sprache nehmen.
 *
 * KEINE FASSUNGSNUMMER NACH AUSSEN (16.09.2026): Der Kunde sah „version 4" — also wie oft intern
 * nachgebessert wurde. Das ist eine Zahl, die ihm nichts sagt und nur Fragen aufwirft. Ab sofort
 * nennt die Mail das DATUM des aktualisierten Stands. Die Fassung zählt intern weiter (Karte,
 * Verlauf, Beleg), sie verlässt das Haus nur nicht mehr.
 *
 * Bewusst „Stand vom <Datum>", nicht „von heute": Der Text friert beim Versand ein. Öffnet der
 * Kunde die Mail am nächsten Tag, wäre „heute" schlicht falsch — derselbe Fehler wie der Countdown,
 * der in 0.11.487 aus Mail und PDF entfernt wurde (§ 148 BGB: bestimmbar, nicht relativ).
 *
 * FREIGABE: Text am 08.09.2026 von Daniel geprüft und freigegeben. Der Filter
 * m24_offer_update_mail_approved bleibt als Notaus:
 *
 *     add_filter( 'm24_offer_update_mail_approved', '__return_false' );
 *
 * Design unverändert: bestehende m24_mail_shell (weißes Logo rechts auf blauem Verlauf 135°
 * #1f74c4 → #0e447e, Standardfuß), Du-Form.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Update_Mail {

	const DRAFT_MARK = '[ENTWURF — anwaltlich ungeprüft] ';

	/** Vorgabe true seit der Freigabe vom 08.09.2026. Per Filter jederzeit wieder sperrbar. */
	public static function approved(): bool {
		return (bool) apply_filters( 'm24_offer_update_mail_approved', true );
	}

	/**
	 * Sprache des Angebots — 'de' oder 'en'. Eine Quelle: src_json.lang, gesetzt vom Editor
	 * über den Schalter „Angebotssprache". Fehlt sie, bleibt es bei Deutsch.
	 */
	public static function lang( $o ): string {
		$sj = json_decode( (string) ( $o->src_json ?? '' ), true );
		$l  = is_array( $sj ) ? strtolower( trim( (string) ( $sj['lang'] ?? '' ) ) ) : '';
		return 'en' === $l ? 'en' : 'de';
	}

	/**
	 * Datum des aktualisierten Stands — der Zeitpunkt, zu dem diese Fassung geschrieben wurde.
	 * Fällt er aus, das heutige Datum: Die Mail geht im selben Vorgang raus, der die Fassung
	 * erzeugt hat, insofern ist das keine Schätzung, sondern derselbe Tag.
	 */
	private static function stand_datum( $o, bool $en ): string {
		$roh = trim( (string) ( $o->version_pending_at ?? '' ) );
		$ts  = '' !== $roh ? strtotime( $roh . ' UTC' ) : 0;
		if ( ! $ts ) { $ts = time(); }
		return date_i18n( $en ? 'j M Y' : 'd.m.Y', $ts );
	}

	/**
	 * @return array{ok:bool,msg:string} Darf diese Mail an den Kunden raus?
	 */
	public static function send_allowed(): array {
		if ( self::approved() ) { return array( 'ok' => true, 'msg' => '' ); }
		return array(
			'ok'  => false,
			'msg' => 'Die Mail „Angebot aktualisiert" ist gesperrt (Filter m24_offer_update_mail_approved). '
				. 'Bis zur Freigabe geht sie nicht an Kunden raus. Mail-Vorschau und Testversand an die eigene Adresse '
				. 'stehen an der Karte zur Verfügung.',
		);
	}

	public static function subject( $o ): string {
		$en = 'en' === self::lang( $o );
		$no = (string) $o->offer_no;
		// Ohne Fassungsnummer — sie sagt dem Kunden nichts und wirft Fragen auf.
		$s  = $en
			? sprintf( 'Your quote %s has been updated', $no )
			: sprintf( 'Dein Angebot %s wurde aktualisiert', $no );
		return self::approved() ? $s : self::DRAFT_MARK . $s;
	}

	/**
	 * @param object $o    Angebot im neuen Stand.
	 * @param array  $diff Ergebnis aus M24_Offer_Versions::diff().
	 */
	public static function render( $o, array $diff ): string {
		$lang = self::lang( $o );
		$en   = 'en' === $lang;

		$cust = json_decode( (string) $o->customer_json, true );
		$cust = is_array( $cust ) ? $cust : array();
		$name = trim( (string) ( $cust['vorname'] ?? '' ) );
		$no   = (string) $o->offer_no;
		$stand = self::stand_datum( $o, $en );
		// Datumsformat der Sprache folgen lassen: 26.09.2026 gegen 26 Sep 2026.
		$vu   = ! empty( $o->valid_until )
			? date_i18n( $en ? 'j M Y' : 'd.m.Y', strtotime( (string) $o->valid_until ) )
			: '';
		// Betrag ebenso: 4.280,00 € gegen € 4,280.00 — eine deutsche Zahl in einem
		// englischen Text liest ein Norweger als Tippfehler.
		$eur  = static function ( $v ) use ( $en ) {
			return $en
				? '€ ' . number_format( (float) $v, 2, '.', ',' )
				: number_format( (float) $v, 2, ',', '.' ) . ' €';
		};

		// Der Aenderungsblock erscheint nur, wenn sich tatsaechlich etwas geaendert hat.
		// "3 -> 3" und "4.280,00 EUR -> 4.280,00 EUR" unter der Ueberschrift "Was sich
		// geaendert hat" ist fuer den Kunden eine Zumutung — dann lieber gar nichts.
		$hat_diff = (int) $diff['positionen_vorher'] !== (int) $diff['positionen_nachher']
			|| round( (float) $diff['summe_vorher'], 2 ) !== round( (float) $diff['summe_nachher'], 2 )
			|| ! empty( $diff['neu'] ) || ! empty( $diff['entfallen'] );

		ob_start();
		?>
<?php if ( ! self::approved() ) : ?>
<div style="background:#fdf6e3;border:1px solid #e6dcc0;border-radius:6px;padding:12px 14px;margin:0 0 16px;font-size:13px;color:#5a4a1a;">
<strong>Interner Hinweis, nicht für den Kunden:</strong> Dieser Text ist gesperrt und wird erst nach Freigabe versendet.
</div>
<?php endif; ?>
<p style="font-size:15px;color:#222;margin:0 0 14px;"><?php
echo esc_html( $en ? 'Hello' : 'Hallo' ) . ( '' !== $name ? ' ' . esc_html( $name ) : '' ) . ',';
?></p>

<p style="font-size:14px;color:#222;line-height:1.6;margin:0 0 14px;">
<?php if ( $en ) : ?>
there is an updated version of your quote <strong><?php echo esc_html( $no ); ?></strong>.
You will find the updated version of <strong><?php echo esc_html( $stand ); ?></strong> attached to this e-mail.
<?php else : ?>
zu deinem Angebot <strong><?php echo esc_html( $no ); ?></strong> gibt es einen aktualisierten Stand.
Du findest die aktualisierte Fassung vom <strong><?php echo esc_html( $stand ); ?></strong> im Anhang dieser Mail.
<?php endif; ?>
</p>

<?php if ( $hat_diff ) : ?>
<!-- Was sich geändert hat — nur bei echter Änderung. -->
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;">
<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:8px;"><?php
echo esc_html( $en ? 'What has changed' : 'Was sich geändert hat' ); ?></div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;">
<tr><td style="padding:4px 12px 4px 0;color:#888;"><?php echo esc_html( $en ? 'Line items' : 'Positionen' ); ?></td><td style="padding:4px 0;color:#222;">
<?php echo (int) $diff['positionen_vorher']; ?> &rarr; <strong><?php echo (int) $diff['positionen_nachher']; ?></strong></td></tr>
<tr><td style="padding:4px 12px 4px 0;color:#888;"><?php echo esc_html( $en ? 'Total' : 'Gesamt' ); ?></td><td style="padding:4px 0;color:#222;">
<?php echo esc_html( $eur( $diff['summe_vorher'] ) ); ?> &rarr; <strong><?php echo esc_html( $eur( $diff['summe_nachher'] ) ); ?></strong></td></tr>
</table>
<?php if ( ! empty( $diff['neu'] ) ) : ?>
<p style="font-size:13px;color:#222;margin:10px 0 0;"><span style="color:#888;"><?php
echo esc_html( $en ? 'Added:' : 'Neu:' ); ?></span> <?php echo esc_html( implode( ', ', $diff['neu'] ) ); ?></p>
<?php endif; ?>
<?php if ( ! empty( $diff['entfallen'] ) ) : ?>
<p style="font-size:13px;color:#222;margin:4px 0 0;"><span style="color:#888;"><?php
echo esc_html( $en ? 'Removed:' : 'Entfallen:' ); ?></span> <?php echo esc_html( implode( ', ', $diff['entfallen'] ) ); ?></p>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- Frist -->
<?php if ( '' !== $vu ) : ?>
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;font-size:14px;color:#222;">
<?php
// Eingefrorenes Dokument: ausschliesslich das Datum. "10 Tage ab heute" waere schon falsch,
// wenn der Kunde die Mail einen Tag spaeter oeffnet (§ 148 BGB: die Frist muss bestimmbar sein).
echo esc_html( class_exists( 'M24_Offer_Validity' )
    ? M24_Offer_Validity::line( (string) $o->valid_until, $lang )
    : ( $en
        ? 'This quote is valid up to and including ' . $vu . '.'
        : 'Dieses Angebot ist gültig bis einschließlich ' . $vu . '.' ) );
?>
</div>
<?php endif; ?>

<!-- § 145 BGB: die alte Fassung wird NICHT für ungültig erklärt. Gilt in beiden Sprachen. -->
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;font-size:14px;color:#222;line-height:1.6;">
<?php if ( $en ) : ?>
Please let us know whether this updated version works for you. We will proceed with it
once you have confirmed.
<?php else : ?>
Bitte gib uns kurz Bescheid, ob der neue Stand für dich passt. Erst mit deiner Bestätigung
arbeiten wir mit dieser Fassung weiter.
<?php endif; ?>
</div>

<p style="font-size:13px;color:#5a6474;margin:16px 0 0;line-height:1.6;">
<?php echo $en
	? 'Any questions? Just reply to this e-mail — it comes straight to us.'
	: 'Fragen dazu? Antworte einfach auf diese Mail — sie landet direkt bei uns.'; ?>
</p>
		<?php
		$inner    = ob_get_clean();
		// Ueberschrift ohne Fassungsnummer, mit dem Stand als Datum.
		$headline = $en
			? 'Quote ' . $no . ' — ' . $stand
			: 'Angebot ' . $no . ' — Stand ' . $stand;
		return function_exists( 'm24_mail_shell' ) ? m24_mail_shell( $headline, $inner, array( 'lang' => $lang ) ) : $inner;
	}
}
