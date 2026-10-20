<?php
/**
 * M24 Plattform — Garage teilen: Sprache (DE/EN) und Steuerfall je geteilter Garage.
 *
 * AUFTRAG (Daniel, 20.10.2026, 14:34): „Ich möchte als eingeloggter Admin Artikel in die Garage senden und genau
 * diese Garage per E-Mail an einen Kunden senden … eine Auswahlmöglichkeit, ob der Kunde die Garage in DE oder EN
 * sieht … und ich muss noch einen Steuerfall auswählen können. Der Kunde soll dann entsprechend brutto oder netto
 * angezeigt bekommen." Call-to-Action: verbindliches Angebot inkl. Verpackung, Transport, ggf. Zollabwicklung.
 *
 * Die Wahl wird je Account gemerkt (usermeta) und beim Einfrieren in den Snapshot geschrieben (totals_json →
 * lang/tax), damit ein verschickter Link genau so bleibt, wie er verschickt wurde.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Garage_Share_Opts {

	const META = 'm24_garage_share_opts';

	/** Steuerfälle: Schlüssel → [Label im Formular, Anzeige netto?, Steuerzeile DE, Steuerzeile EN]. */
	public static function tax_cases(): array {
		return array(
			'brutto'       => array( 'Brutto inkl. 19 % USt (Deutschland, Privatkunden EU)', false,
				'Alle Preise inkl. 19 % USt.',
				'All prices include 19 % German VAT.' ),
			'netto_de'     => array( 'Netto zzgl. 19 % USt (Geschäftskunden Deutschland)', true,
				'Alle Preise netto zzgl. 19 % USt.',
				'All prices are net, plus 19 % German VAT.' ),
			'netto_eu'     => array( 'Netto · innergemeinschaftlich, Reverse Charge (EU-Firma mit USt-IdNr.)', true,
				'Alle Preise netto. Steuerfreie innergemeinschaftliche Lieferung bei gültiger USt-IdNr. (Reverse Charge, § 6a UStG).',
				'All prices are net. VAT-exempt intra-community supply with a valid VAT ID (reverse charge, Art. 138 VAT Directive).' ),
			'netto_export' => array( 'Netto · Export Drittland (Schweiz, UK, USA …)', true,
				'Alle Preise netto. Steuerfreie Ausfuhrlieferung; Einfuhrabgaben und Zoll im Bestimmungsland trägt der Empfänger.',
				'All prices are net. VAT-exempt export; import duties and customs in the destination country are paid by the recipient.' ),
		);
	}

	public static function sanitize_lang( $v ): string { return 'en' === strtolower( (string) $v ) ? 'en' : 'de'; }
	public static function sanitize_tax( $v ): string { $v = sanitize_key( (string) $v ); return isset( self::tax_cases()[ $v ] ) ? $v : 'brutto'; }

	/** Gemerkte Wahl des Accounts (Standard DE/brutto). */
	public static function get( int $acc ): array {
		$m = $acc > 0 ? get_user_meta( $acc, self::META, true ) : array();
		$m = is_array( $m ) ? $m : array();
		return array( 'lang' => self::sanitize_lang( $m['lang'] ?? 'de' ), 'tax' => self::sanitize_tax( $m['tax'] ?? 'brutto' ) );
	}

	/** Wahl aus einem Request übernehmen und merken; liefert die bereinigte Wahl. */
	public static function from_request( int $acc, $lang, $tax ): array {
		$o = self::get( $acc );
		if ( null !== $lang && '' !== $lang ) { $o['lang'] = self::sanitize_lang( $lang ); }
		if ( null !== $tax && '' !== $tax ) { $o['tax'] = self::sanitize_tax( $tax ); }
		if ( $acc > 0 ) { update_user_meta( $acc, self::META, $o ); }
		return $o;
	}

	/** Wahl aus einem Snapshot (totals_json) — alte Snapshots ohne Felder → DE/brutto. */
	public static function from_snapshot( $snap ): array {
		$t = is_array( $snap ) ? (array) ( $snap['totals'] ?? array() ) : array();
		return array( 'lang' => self::sanitize_lang( $t['lang'] ?? 'de' ), 'tax' => self::sanitize_tax( $t['tax'] ?? 'brutto' ) );
	}

	public static function is_net( string $tax ): bool { return ! empty( self::tax_cases()[ $tax ][1] ); }

	public static function tax_line( string $tax, string $lang ): string {
		$c = self::tax_cases()[ $tax ] ?? self::tax_cases()['brutto'];
		return 'en' === $lang ? $c[3] : $c[2];
	}

	/** Texte der geteilten Ansicht und der Mail. */
	public static function t( string $lang ): array {
		if ( 'en' === $lang ) {
			return array(
				'title'        => 'Your parts selection',
				'page_title'   => 'Shared garage – MOTORSPORT24',
				'hint'         => 'Put together for you by MOTORSPORT24 on %s.',
				'garage_no'    => 'Garage no. %s',
				'vehicles'     => 'Parked vehicles',
				'parts'        => 'Parts',
				'positions'    => '%d item(s)',
				'on_request'   => '%d on request',
				'price_req'    => 'Price on request',
				'variant'      => 'Variant: ',
				'artnr'        => 'Part no.: ',
				'net'          => 'net',
				'gross'        => 'gross',
				'total_net'    => 'Total net',
				'total_gross'  => 'Total incl. 19 % VAT',
				'incl_vat'     => 'incl. 19 % VAT',
				'empty'        => 'This garage is currently empty',
				'cta_btn'      => 'Request a binding quote',
				'cta_h'        => 'Request a binding quote',
				'cta_sub'      => 'We take over your selection automatically. You will receive a binding quote including packaging, transport and, if required, customs clearance for your country.',
				'f_type'       => 'Customer type', 'f_private' => 'Private', 'f_business' => 'Business',
				'f_country'    => 'Delivery country *', 'f_name' => 'Name *', 'f_email' => 'E-mail *', 'f_msg' => 'Message (optional)',
				'f_msg_ph'     => 'Notes on your request …',
				'f_consent'    => 'I agree that my details are processed to handle this request. *',
				'f_register'   => 'Create a free MOTORSPORT24 account and keep this garage.',
				'f_logged'     => 'Signed in as %s – we use your account details.',
				'f_submit'     => 'Send request',
				'flash_ok'     => 'Thank you! We have received your request and will get back to you with a binding quote.',
				'flash_dup'    => 'We have already received your request – please bear with us.',
				'expired'      => 'This link has expired. Please ask the sender for a new one.',
				'invalid'      => 'This link is no longer valid. Please ask the sender for a current one.',
				'foot_claim'   => 'Classic & Race Cars and Parts Sales since 2006',
				'foot_imprint' => 'Imprint', 'foot_privacy' => 'Privacy',
				// Mail
				'm_subject'    => 'Your parts selection – MOTORSPORT24',
				'm_hello'      => 'Hello,',
				'm_intro'      => 'here is your personal MOTORSPORT24 parts selection%s, prepared on %s.',
				'm_link'       => 'The link%s is valid for three months.',
				'm_btn'        => 'View parts selection',
				'm_pdf'        => 'The selection is attached as a PDF summary.',
				'm_cta_p'      => 'Would you like a binding offer? Just let us know – we add packaging, transport and, if required, customs clearance and send you a binding quote. Your selection is taken over automatically, nothing needs to be entered again.',
				'm_cta_btn'    => 'Request a binding quote',
				'm_garage'     => ' for garage no. <strong>%s</strong>',
				'm_from'       => ' from %s',
			);
		}
		return array(
			'title'        => 'Deine Teile-Auswahl',
			'page_title'   => 'Geteilte Garage – MOTORSPORT24',
			'hint'         => 'Zusammengestellt für Dich von MOTORSPORT24 am %s.',
			'garage_no'    => 'Garagen-Nr. %s',
			'vehicles'     => 'Geparkte Fahrzeuge',
			'parts'        => 'Teile',
			'positions'    => '%d Position(en)',
			'on_request'   => '%d auf Anfrage',
			'price_req'    => 'Preis auf Anfrage',
			'variant'      => 'Variante: ',
			'artnr'        => 'Art.-Nr.: ',
			'net'          => 'netto',
			'gross'        => 'brutto',
			'total_net'    => 'Gesamt netto',
			'total_gross'  => 'Gesamt inkl. 19 % USt',
			'incl_vat'     => 'inkl. 19 % MwSt',
			'empty'        => 'Diese Garage ist aktuell leer',
			'cta_btn'      => 'Verbindliches Angebot anfordern',
			'cta_h'        => 'Verbindliches Angebot anfordern',
			'cta_sub'      => 'Wir übernehmen Deine Auswahl automatisch. Du erhältst ein verbindliches Angebot inkl. Verpackung, Transport und – falls nötig – Zollabwicklung für Dein Land.',
			'f_type'       => 'Kundenart', 'f_private' => 'Privat', 'f_business' => 'Geschäftskunde',
			'f_country'    => 'Lieferland *', 'f_name' => 'Name *', 'f_email' => 'E-Mail *', 'f_msg' => 'Nachricht (optional)',
			'f_msg_ph'     => 'Anmerkungen zu Deiner Anfrage …',
			'f_consent'    => 'Ich willige in die Verarbeitung meiner Angaben zur Bearbeitung der Anfrage ein. *',
			'f_register'   => 'Kostenloses MOTORSPORT24-Kundenkonto anlegen und meine Garage übernehmen.',
			'f_logged'     => 'Angemeldet als %s — wir nutzen Deine hinterlegten Kontodaten.',
			'f_submit'     => 'Anfrage senden',
			'flash_ok'     => 'Danke! Deine Anfrage ist bei uns eingegangen — wir melden uns mit einem verbindlichen Angebot.',
			'flash_dup'    => 'Wir haben Deine Anfrage bereits erhalten — bitte etwas Geduld.',
			'expired'      => 'Dieser Link ist abgelaufen. Bitte den Absender um einen neuen Link.',
			'invalid'      => 'Dieser Link ist nicht (mehr) gültig. Bitte den Absender um einen aktuellen Link.',
			'foot_claim'   => 'Classic & Race Cars and Parts Sales since 2006',
			'foot_imprint' => 'Impressum', 'foot_privacy' => 'Datenschutz',
			'm_subject'    => 'Deine Teile-Auswahl – MOTORSPORT24',
			'm_hello'      => 'Hallo,',
			'm_intro'      => 'hier ist Deine persönliche MOTORSPORT24-Teile-Auswahl%s, zusammengestellt am %s Uhr.',
			'm_link'       => 'Der Link%s ist drei Monate gültig.',
			'm_btn'        => 'Zur Teile-Übersicht',
			'm_pdf'        => 'Im Anhang befindet sich die Auswahl zusammengefasst als PDF-Datei.',
			'm_cta_p'      => 'Möchtest Du daraus ein verbindliches Angebot machen? Melde Dich einfach bei uns — wir ergänzen Verpackung, Transport und (falls nötig) die Zollabwicklung und senden Dir ein verbindliches Angebot zurück. Deine Auswahl übernehmen wir dabei automatisch, Du musst nichts erneut eingeben.',
			'm_cta_btn'    => 'Verbindliches Angebot anfordern',
			'm_garage'     => ' zur Garagen-Nummer <strong>%s</strong>',
			'm_from'       => ' von %s',
		);
	}

	/** Formular-Felder (Sprache + Steuerfall) für die Karte „Garage teilen". */
	public static function render_fields( int $acc ): void {
		$o = self::get( $acc );
		?>
		<div class="m24gc-shareopts" data-m24gc-shareopts>
			<label class="m24gc-field">
				<span class="m24gc-field-lbl">Sprache für den Kunden</span>
				<span class="m24sa-seg m24gc-seg">
					<label><input type="radio" name="m24gc_lang" value="de" <?php checked( 'de', $o['lang'] ); ?>><span>Deutsch</span></label>
					<label><input type="radio" name="m24gc_lang" value="en" <?php checked( 'en', $o['lang'] ); ?>><span>English</span></label>
				</span>
			</label>
			<label class="m24gc-field">
				<span class="m24gc-field-lbl">Steuerfall · so sieht der Kunde die Preise</span>
				<select class="m24gc-field-input" name="m24gc_tax" data-m24gc-tax>
					<?php foreach ( self::tax_cases() as $k => $c ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $o['tax'] ); ?>><?php echo esc_html( $c[0] ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>
		<?php
	}
}
