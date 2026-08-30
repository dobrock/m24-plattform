<?php
/**
 * M24 — Transaktionsmail „Angebot aktualisiert" (ENTWURF, anwaltlich ungeprüft).
 * Modul: includes/class-m24-offer-update-mail.php
 *
 * ⚠️ RECHTLICHER VORBEHALT — NICHT UNGEPRÜFT SCHARF SCHALTEN.
 * Ein versendetes bindendes Angebot bindet nach § 145 BGB für die Laufzeit; ein einseitiger Widerruf
 * ist unwirksam. Der Text darf deshalb NICHT behaupten, die vorherige Fassung sei ungültig oder
 * zurückgezogen. Er stellt die neue Fassung daneben und BITTET um Bestätigung — mehr nicht.
 *
 * Solange approved() false liefert (Vorgabe), trägt der Betreff eine sichtbare Entwurfsmarke und
 * send_allowed() verweigert den Versand. Freigabe nach anwaltlicher Prüfung ausschließlich über:
 *
 *     add_filter( 'm24_offer_update_mail_approved', '__return_true' );
 *
 * Design unverändert: bestehende m24_mail_shell (weißes Logo rechts auf blauem Verlauf 135°
 * #1f74c4 → #0e447e, Standardfuß), Du-Form.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Update_Mail {

	const DRAFT_MARK = '[ENTWURF — anwaltlich ungeprüft] ';

	/** Vorgabe false. Erst nach anwaltlicher Prüfung per Filter freigeben. */
	public static function approved(): bool {
		return (bool) apply_filters( 'm24_offer_update_mail_approved', false );
	}

	/**
	 * @return array{ok:bool,msg:string} Darf diese Mail an den Kunden raus?
	 */
	public static function send_allowed(): array {
		if ( self::approved() ) { return array( 'ok' => true, 'msg' => '' ); }
		return array(
			'ok'  => false,
			'msg' => 'Die Mail „Angebot aktualisiert" ist ein Entwurf und anwaltlich noch nicht geprüft. '
				. 'Bis zur Freigabe (Filter m24_offer_update_mail_approved) geht sie nicht an Kunden raus. '
				. 'Vorschau und Testversand an die eigene Adresse sind möglich.',
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
<strong>Interner Hinweis, nicht für den Kunden:</strong> Dieser Text ist ein Entwurf und anwaltlich nicht geprüft.
Er wird erst nach Freigabe versendet.
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
Die neue Fassung gilt bis <strong><?php echo esc_html( $vu ); ?></strong>
(<?php echo (int) M24_Offers::VALID_DAYS; ?> Tage ab heute).
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
