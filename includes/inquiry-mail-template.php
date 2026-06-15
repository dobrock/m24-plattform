<?php
/**
 * M24 Plattform – E-Mail-Template für Produkt-/Sammelanfragen
 * --------------------------------------------------------------------------
 * Variante A (Relaunch 2026-06):
 *   - schmal ~440px, mobil-optimiert (iPhone), weiß
 *   - dünner blauer Top-Balken (3px, #1763ad), Haarlinien zwischen Sektionen
 *   - Kopf einzeilig: Titel links, MOTORSPORT24-Wortmarke rechtsbündig
 *   - Sektionen: KONTAKT · POSITION(EN) · NACHRICHT · Footer (Anfrage-ID/Datum)
 *   - Outlook-robust: Tabellen-Layout, Inline-Styles, web-safe Fonts
 *
 * Verantwortung: NUR HTML-Rendering. Kein Versand, keine REST-Logik.
 * Das Modul ist Produkt- UND Sammelanfrage-tauglich (Positionen = Array).
 *
 * @package m24-plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ==========================================================================
 * Helfer
 * ====================================================================== */

if ( ! function_exists( 'm24_country_name' ) ) {
	/**
	 * ISO-3166-1-alpha-2-Code → ausgeschriebener deutscher Ländername.
	 * Fallback: Code in Großbuchstaben (falls unbekannt).
	 */
	function m24_country_name( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		$map  = array(
			// EU
			'DE' => 'Deutschland', 'AT' => 'Österreich', 'BE' => 'Belgien',
			'BG' => 'Bulgarien', 'HR' => 'Kroatien', 'CY' => 'Zypern',
			'CZ' => 'Tschechien', 'DK' => 'Dänemark', 'EE' => 'Estland',
			'FI' => 'Finnland', 'FR' => 'Frankreich', 'GR' => 'Griechenland',
			'HU' => 'Ungarn', 'IE' => 'Irland', 'IT' => 'Italien',
			'LV' => 'Lettland', 'LT' => 'Litauen', 'LU' => 'Luxemburg',
			'MT' => 'Malta', 'NL' => 'Niederlande', 'PL' => 'Polen',
			'PT' => 'Portugal', 'RO' => 'Rumänien', 'SK' => 'Slowakei',
			'SI' => 'Slowenien', 'ES' => 'Spanien', 'SE' => 'Schweden',
			// Europa (Nicht-EU) + häufige Märkte
			'CH' => 'Schweiz', 'GB' => 'Vereinigtes Königreich', 'UK' => 'Vereinigtes Königreich',
			'NO' => 'Norwegen', 'LI' => 'Liechtenstein', 'IS' => 'Island',
			'US' => 'USA', 'CA' => 'Kanada', 'AU' => 'Australien',
			'AE' => 'Vereinigte Arabische Emirate', 'JP' => 'Japan',
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : ( $code !== '' ? $code : '—' );
	}
}

if ( ! function_exists( 'm24_truncate_link' ) ) {
	/**
	 * Anzeigetext für einen Link einzeilig kürzen (kein Umbruch).
	 * Schema (https://) wird für die Anzeige entfernt, am Ende „…" angehängt.
	 * Der volle Link gehört in das href – hier wird NUR der Anzeigetext erzeugt.
	 */
	function m24_truncate_link( $url, $max = 44 ) {
		$disp = preg_replace( '#^https?://#i', '', (string) $url );
		$disp = rtrim( $disp, '/' );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $disp ) : strlen( $disp );
		if ( $len > $max ) {
			$disp = function_exists( 'mb_substr' )
				? mb_substr( $disp, 0, $max - 1 ) . '…'
				: substr( $disp, 0, $max - 1 ) . '…';
		}
		return $disp;
	}
}

if ( ! function_exists( 'm24_kundentyp_label' ) ) {
	/** Kundentyp → deutsches Label. */
	function m24_kundentyp_label( $typ ) {
		$typ = strtolower( trim( (string) $typ ) );
		if ( in_array( $typ, array( 'business', 'geschaeftlich', 'geschäftlich', 'b2b', 'gewerblich' ), true ) ) {
			return 'Geschäftlich (B2B)';
		}
		if ( in_array( $typ, array( 'private', 'privat', 'b2c' ), true ) ) {
			return 'Privat';
		}
		return $typ !== '' ? esc_html( $typ ) : '—';
	}
}

/* ==========================================================================
 * Haupt-Renderer
 * ====================================================================== */

if ( ! function_exists( 'm24_render_inquiry_email' ) ) {
	/**
	 * Rendert die Anfrage-Mail (Variante A) als vollständiges HTML-Dokument.
	 *
	 * @param array $a {
	 *   @type string $titel        Kopf-Titel. Default „Neue Produktanfrage".
	 *   @type string $name         Vor- + Nachname (optional).
	 *   @type string $firma        Firma (optional).
	 *   @type string $email        E-Mail (Pflicht).
	 *   @type string $land         ISO2-Code, wird ausgeschrieben.
	 *   @type string $kundentyp    'business' | 'private'.
	 *   @type array  $positionen   Liste von Positionen, je:
	 *                              [ titel, menge, preis, link, artikelnummer ].
	 *   @type string $nachricht    Freitext (optional, Section entfällt wenn leer).
	 *   @type int    $anfrage_id   ID für Footer.
	 *   @type int    $datum_ts     Unix-Timestamp (Default: now).
	 * }
	 * @return string Vollständiges HTML.
	 */
	function m24_render_inquiry_email( array $a ) {
		// --- Tokens -------------------------------------------------------
		$ANTHRAZIT = '#14161a';
		$BLAU      = '#1763ad';
		$MUTED     = '#6b7075';
		$HAIR      = '#e7e7e4';
		$PAPER     = '#ffffff';
		$BG        = '#f4f4f2';
		$FF        = "Arial, Helvetica, 'Helvetica Neue', sans-serif";
		$MONO      = "Consolas, 'Courier New', monospace";

		// Marken-Logo (eigenes M24-Logo, public URL – Outlook-/Brevo-tauglich)
		$LOGO_URL  = 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2025/10/MOTORSPORT24-Logo_280px.png';
		$LOGO_W    = 150; // Anzeigebreite px (Native 280px → scharf auf Retina)

		// --- Daten normalisieren -----------------------------------------
		$titel      = isset( $a['titel'] ) && $a['titel'] !== '' ? $a['titel'] : 'Neue Produktanfrage';
		$name       = isset( $a['name'] ) ? trim( (string) $a['name'] ) : '';
		$firma      = isset( $a['firma'] ) ? trim( (string) $a['firma'] ) : '';
		$email      = isset( $a['email'] ) ? trim( (string) $a['email'] ) : '';
		$land       = isset( $a['land'] ) ? m24_country_name( $a['land'] ) : '—';
		$kunde      = m24_kundentyp_label( isset( $a['kundentyp'] ) ? $a['kundentyp'] : '' );
		$nachricht  = isset( $a['nachricht'] ) ? trim( (string) $a['nachricht'] ) : '';
		$anfrage_id = isset( $a['anfrage_id'] ) ? (int) $a['anfrage_id'] : 0;
		$datum_ts   = isset( $a['datum_ts'] ) ? (int) $a['datum_ts'] : time();
		$positionen = isset( $a['positionen'] ) && is_array( $a['positionen'] ) ? $a['positionen'] : array();

		$datum = date_i18n( 'd.m.Y H:i', $datum_ts ); // ohne Sekunden

		// --- KONTAKT-Zeilen (nur befüllte) -------------------------------
		$kontakt_rows = '';
		$kv = function( $label, $value_html ) use ( $FF, $ANTHRAZIT, $MUTED ) {
			return '<tr>'
				. '<td style="padding:3px 0;font-family:' . $FF . ';font-size:13px;line-height:18px;color:' . $MUTED . ';white-space:nowrap;vertical-align:top;width:118px;">' . $label . '</td>'
				. '<td style="padding:3px 0;font-family:' . $FF . ';font-size:14px;line-height:18px;color:' . $ANTHRAZIT . ';vertical-align:top;">' . $value_html . '</td>'
				. '</tr>';
		};
		if ( $name !== '' ) {
			$kontakt_rows .= $kv( 'Name', esc_html( $name ) );
		}
		if ( $firma !== '' ) {
			$kontakt_rows .= $kv( 'Firma', esc_html( $firma ) );
		}
		if ( $email !== '' ) {
			$kontakt_rows .= $kv(
				'E-Mail',
				'<a href="mailto:' . esc_attr( $email ) . '" style="color:' . $BLAU . ';text-decoration:none;">' . esc_html( $email ) . '</a>'
			);
		}
		$kontakt_rows .= $kv( 'Land', esc_html( $land ) );
		$kontakt_rows .= $kv( 'Kunde', $kunde );

		// --- POSITION(EN) -------------------------------------------------
		$pos_blocks = '';
		$last = count( $positionen ) - 1;
		foreach ( $positionen as $i => $p ) {
			$p_titel = isset( $p['titel'] ) ? trim( (string) $p['titel'] ) : '';
			$p_menge = isset( $p['menge'] ) ? (int) $p['menge'] : 1;
			$p_preis = isset( $p['preis'] ) ? trim( (string) $p['preis'] ) : '';
			$p_link  = isset( $p['link'] ) ? trim( (string) $p['link'] ) : '';
			$p_art   = isset( $p['artikelnummer'] ) ? trim( (string) $p['artikelnummer'] ) : '';

			// „Menge X · Preis" (Preis nur wenn vorhanden)
			$meta = 'Menge ' . max( 1, $p_menge );
			if ( $p_preis !== '' ) {
				$meta .= ' &middot; ' . esc_html( $p_preis );
			}

			$rows = '';
			if ( $p_titel !== '' ) {
				$rows .= '<tr><td style="padding:0 0 4px;font-family:' . $FF . ';font-size:15px;line-height:20px;font-weight:bold;color:' . $ANTHRAZIT . ';">' . esc_html( $p_titel ) . '</td></tr>';
			}
			$rows .= '<tr><td style="padding:0 0 4px;font-family:' . $FF . ';font-size:13px;line-height:18px;color:' . $MUTED . ';">' . $meta . '</td></tr>';
			if ( $p_link !== '' ) {
				$rows .= '<tr><td style="padding:0 0 4px;font-family:' . $FF . ';font-size:13px;line-height:18px;white-space:nowrap;">'
					. '<a href="' . esc_url( $p_link ) . '" style="color:' . $BLAU . ';text-decoration:none;white-space:nowrap;">' . esc_html( m24_truncate_link( $p_link ) ) . '</a>'
					. '</td></tr>';
			}
			if ( $p_art !== '' ) {
				$rows .= '<tr><td style="padding:0;font-family:' . $MONO . ';font-size:12px;line-height:16px;color:' . $MUTED . ';">Art.-Nr.: ' . esc_html( $p_art ) . '</td></tr>';
			}

			// Trenn-Haarlinie zwischen mehreren Positionen (Sammelanfrage)
			$sep = ( $i < $last )
				? ' border-bottom:1px solid ' . $HAIR . ';'
				: '';

			$pos_blocks .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;"><tr><td style="padding:' . ( $i === 0 ? '0' : '10px' ) . ' 0 ' . ( $i < $last ? '10px' : '0' ) . ';' . $sep . '">'
				. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
				. '</td></tr></table>';
		}

		// --- Sektion-Helfer ----------------------------------------------
		$section_label = function( $txt ) use ( $FF, $MUTED ) {
			return '<div style="font-family:' . $FF . ';font-size:11px;line-height:14px;letter-spacing:1.2px;font-weight:bold;color:' . $MUTED . ';text-transform:uppercase;">' . $txt . '</div>';
		};
		$hairline = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="border-top:1px solid ' . $HAIR . ';font-size:0;line-height:0;height:1px;">&nbsp;</td></tr></table>';

		// --- NACHRICHT (nur wenn vorhanden) ------------------------------
		$nachricht_section = '';
		if ( $nachricht !== '' ) {
			$nachricht_section =
				'<tr><td style="padding:0 22px;">' . $hairline . '</td></tr>'
				. '<tr><td style="padding:16px 22px 4px;">' . $section_label( 'Nachricht' ) . '</td></tr>'
				. '<tr><td style="padding:0 22px 18px;font-family:' . $FF . ';font-size:14px;line-height:20px;color:' . $ANTHRAZIT . ';">' . nl2br( esc_html( $nachricht ) ) . '</td></tr>';
		}

		// --- Footer-Text --------------------------------------------------
		$footer = 'Anfrage-ID: ' . ( $anfrage_id > 0 ? $anfrage_id : '—' ) . ' &middot; eingegangen ' . esc_html( $datum );

		// --- Preheader (unsichtbar, verbessert Inbox-Vorschau) -----------
		$preheader = esc_html( $titel );
		if ( ! empty( $positionen[0]['titel'] ) ) {
			$preheader .= ' – ' . esc_html( $positionen[0]['titel'] );
		}

		// --- Zusammenbau --------------------------------------------------
		$html = '<!DOCTYPE html><html lang="de" xmlns="http://www.w3.org/1999/xhtml"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
			. '<title>' . esc_html( $titel ) . '</title>'
			. '</head>'
			. '<body style="margin:0;padding:0;background:' . $BG . ';-webkit-text-size-adjust:100%;">'
			// Preheader
			. '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:' . $BG . ';">' . $preheader . '</div>'
			// Äußere Bühne
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $BG . ';"><tr><td align="center" style="padding:24px 12px;">'
			// Karte 440px
			. '<table role="presentation" width="440" cellpadding="0" cellspacing="0" border="0" style="width:440px;max-width:440px;background:' . $PAPER . ';border:1px solid ' . $HAIR . ';">'
			// Blauer Top-Balken 3px
			. '<tr><td style="background:' . $BLAU . ';font-size:0;line-height:0;height:3px;">&nbsp;</td></tr>'
			// Kopf: Titel links, Wortmarke rechts
			. '<tr><td style="padding:18px 22px 14px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
			. '<td style="font-family:' . $FF . ';font-size:17px;line-height:22px;font-weight:bold;color:' . $ANTHRAZIT . ';vertical-align:middle;">' . esc_html( $titel ) . '</td>'
			. '<td align="right" style="vertical-align:middle;white-space:nowrap;">'
			. '<img src="' . esc_url( $LOGO_URL ) . '" width="' . (int) $LOGO_W . '" alt="MOTORSPORT24" style="display:inline-block;width:' . (int) $LOGO_W . 'px;height:auto;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;" />'
			. '</td>'
			. '</tr></table>'
			. '</td></tr>'
			// KONTAKT
			. '<tr><td style="padding:0 22px;">' . $hairline . '</td></tr>'
			. '<tr><td style="padding:16px 22px 6px;">' . $section_label( 'Kontakt' ) . '</td></tr>'
			. '<tr><td style="padding:0 22px 16px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $kontakt_rows . '</table></td></tr>'
			// POSITION(EN)
			. '<tr><td style="padding:0 22px;">' . $hairline . '</td></tr>'
			. '<tr><td style="padding:16px 22px 6px;">' . $section_label( count( $positionen ) > 1 ? 'Positionen' : 'Position' ) . '</td></tr>'
			. '<tr><td style="padding:0 22px 16px;">' . $pos_blocks . '</td></tr>'
			// NACHRICHT (optional)
			. $nachricht_section
			// Footer
			. '<tr><td style="padding:0 22px;">' . $hairline . '</td></tr>'
			. '<tr><td style="padding:14px 22px 18px;font-family:' . $FF . ';font-size:12px;line-height:16px;color:' . $MUTED . ';">' . $footer . '</td></tr>'
			. '</table>'
			. '</td></tr></table>'
			. '</body></html>';

		return $html;
	}
}
