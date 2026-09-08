<?php
/**
 * M24 — Transaktionsmail „Angebot aktualisiert".
 * Modul: includes/class-m24-offer-update-mail.php
 *
 * RECHTLICHER RAHMEN (unverändert): Ein versendetes bindendes Angebot bindet nach § 145 BGB für die
 * Laufzeit; ein einseitiger Widerruf ist unwirksam. Der Text behauptet deshalb NICHT, die vorherige
 * Fassung sei ungültig oder zurückgezogen. Er stellt die neue Fassung daneben und BITTET um
 * Bestätigung — mehr nicht.
 *
 * FREIGABE: Text am 08.09.2026 von Daniel geprüft und freigegeben („Text passt"). Seither ist die
 * Vorgabe von approved() true. Der Filter m24_offer_update_mail_approved bleibt als Notaus:
 *
 *     add_filter( 'm24_offer_update_mail_approved', '__return_false' );
 *
 * sperrt den Versand sofort wieder (Betreff trägt dann die Entwurfsmarke, send_allowed() verweigert).
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
		$s = sprintf( 'Dein Angebot %s wurde aktualisiert (Fassung %d)',
			(string) $o->offer_no, max( 1, (int) ( $o->offer_version ?? 1 ) ) );
		return self::approved() ? $s : self::DRAFT_MARK . $s;
	}

	/**
	 * @param object $o    Angebot im neuen Stand.
	 * @param array  $diff Ergebnis aus M24_Offer_Versions::diff().
	 */
	public static function render( $o, array $diff ): string {
		$cust = json_decode( (string) $o->customer_json, true );
		$cust = is_array( $cust ) ? $cust : array();
		$name = trim( (string) ( $cust['vorname'] ?? '' ) );
		$no   = (string) $o->offer_no;
		$ver  = max( 1, (int) ( $o->offer_version ?? 1 ) );
		$vu   = ! empty( $o->valid_until ) ? date_i18n( 'd.m.Y', strtotime( (string) $o->valid_until ) ) : '';
		$eur  = static function ( $v ) { return number_format( (float) $v, 2, ',', '.' ) . ' €'; };

		ob_start();
		?>
<?php if ( ! self::approved() ) : ?>
<div style="background:#fdf6e3;border:1px solid #e6dcc0;border-radius:6px;padding:12px 14px;margin:0 0 16px;font-size:13px;color:#5a4a1a;">
<strong>Interner Hinweis, nicht für den Kunden:</strong> Dieser Text ist gesperrt und wird erst nach Freigabe versendet.
</div>
<?php endif; ?>
<p style="font-size:15px;color:#222;margin:0 0 14px;">Hallo<?php echo '' !== $name ? ' ' . esc_html( $name ) : ''; ?>,</p>

<p style="font-size:14px;color:#222;line-height:1.6;margin:0 0 14px;">
zu deinem Angebot <strong><?php echo esc_html( $no ); ?></strong> gibt es einen aktualisierten Stand.
Du findest ihn als <strong>Fassung <?php echo (int) $ver; ?></strong> im Anhang dieser Mail.
</p>

<!-- Was sich geändert hat -->
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;">
<div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#888;margin-bottom:8px;">Was sich geändert hat</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;">
<tr><td style="padding:4px 12px 4px 0;color:#888;">Positionen</td><td style="padding:4px 0;color:#222;">
<?php echo (int) $diff['positionen_vorher']; ?> &rarr; <strong><?php echo (int) $diff['positionen_nachher']; ?></strong></td></tr>
<tr><td style="padding:4px 12px 4px 0;color:#888;">Gesamt</td><td style="padding:4px 0;color:#222;">
<?php echo esc_html( $eur( $diff['summe_vorher'] ) ); ?> &rarr; <strong><?php echo esc_html( $eur( $diff['summe_nachher'] ) ); ?></strong></td></tr>
</table>
<?php if ( ! empty( $diff['neu'] ) ) : ?>
<p style="font-size:13px;color:#222;margin:10px 0 0;"><span style="color:#888;">Neu:</span> <?php echo esc_html( implode( ', ', $diff['neu'] ) ); ?></p>
<?php endif; ?>
<?php if ( ! empty( $diff['entfallen'] ) ) : ?>
<p style="font-size:13px;color:#222;margin:4px 0 0;"><span style="color:#888;">Entfallen:</span> <?php echo esc_html( implode( ', ', $diff['entfallen'] ) ); ?></p>
<?php endif; ?>
</div>

<!-- Frist -->
<?php if ( '' !== $vu ) : ?>
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;font-size:14px;color:#222;">
<?php
// Eingefrorenes Dokument: ausschliesslich das Datum. "10 Tage ab heute" waere schon falsch,
// wenn der Kunde die Mail einen Tag spaeter oeffnet (§ 148 BGB: die Frist muss bestimmbar sein).
echo esc_html( class_exists( 'M24_Offer_Validity' )
    ? M24_Offer_Validity::line( (string) $o->valid_until, 'de' )
    : 'Dieses Angebot ist gültig bis einschließlich ' . $vu . '.' );
?>
</div>
<?php endif; ?>

<!-- § 145 BGB: die alte Fassung wird NICHT für ungültig erklärt. -->
<div style="border-top:1px solid #eee;padding-top:14px;margin-top:14px;font-size:14px;color:#222;line-height:1.6;">
Bitte gib uns kurz Bescheid, ob der neue Stand für dich passt. Erst mit deiner Bestätigung
arbeiten wir mit dieser Fassung weiter.
</div>

<p style="font-size:13px;color:#5a6474;margin:16px 0 0;line-height:1.6;">
Fragen dazu? Antworte einfach auf diese Mail — sie landet direkt bei uns.
</p>
		<?php
		$inner    = ob_get_clean();
		$headline = 'Angebot ' . $no . ' — Fassung ' . $ver;
		return function_exists( 'm24_mail_shell' ) ? m24_mail_shell( $headline, $inner, array( 'lang' => 'de' ) ) : $inner;
	}
}
