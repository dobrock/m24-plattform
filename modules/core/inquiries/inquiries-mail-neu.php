<?php
/**
 * Inquiries — neue Betreiber-Mail „Neue Anfrage“ (02.11.2026, Daniel: Vorschlag A + B)
 *
 * Ersetzt für die Front-End-Benachrichtigungen (Kontaktformular, „Frage stellen“, Warenkorb, Merkzettel) die
 * alte Mail mit Technikangaben (src_pillar, Quelle contact_form, Anfrage-ID-Block) und dem Knopf
 * „Angebot erstellen“. Der Push-Fallback (Desk nicht erreichbar) behält seine Diagnose-Mail.
 *
 * Aufbau:
 *  - schmaler blauer Kopf (Logo links, Art der Anfrage rechts), wenig Rand
 *  - Vorschlag B „Artikel zuerst“, wenn genau EIN echter Artikel dranhängt: großes Bild, Titel, Preis,
 *    Knopf „Artikel ansehen“, darunter Kunde (Chips) und Nachricht als Blase
 *  - Vorschlag A „kompakt“ sonst (Kontaktformular, mehrere Positionen): Name, Meta-Zeile, Nachricht,
 *    Artikelkarten bzw. beim Kontaktformular „Gesucht: <Teilenummer>“ mit Suchlink
 *  - Die Mail wird beim Antworten zitiert: deshalb nur Angaben, die der Kunde ohnehin selbst gemacht hat.
 *
 * @package M24_Plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'm24_anfrage_mail_html' ) ) {

	/** Kopfzeilen aus der Kontaktformular-Notiz („Thema: …“, „Ich bin: …“) lösen. */
	function m24_anfrage_notiz_zerlegen( $notes ) {
		$kopf = array();
		$rest = array();
		$im_kopf = true;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $notes ) as $z ) {
			if ( $im_kopf && preg_match( '/^(Thema|Ich bin|Lieferland|Teilenummer \/ Artikel|Teilenummer|Kundentyp)\s*:\s*(.*)$/u', trim( $z ), $m ) ) {
				$kopf[ $m[1] ] = trim( $m[2] );
				continue;
			}
			if ( $im_kopf && '' === trim( $z ) && $kopf ) { $im_kopf = false; continue; }
			$im_kopf = false;
			$rest[]  = $z;
		}
		return array( $kopf, trim( implode( "\n", $rest ) ) );
	}

	/** Sprache grob erkennen (nur als Hinweis in der Meta-Zeile). */
	function m24_anfrage_sprache( $text ) {
		$t = ' ' . strtolower( (string) $text ) . ' ';
		$en = preg_match_all( '/\b(the|and|is|you|i|have|thank|hello|hi|please|still|available|price|shipping)\b/', $t );
		$de = preg_match_all( '/\b(der|die|das|und|ist|sie|ich|habe|danke|hallo|bitte|noch|verfügbar|preis|versand)\b/u', $t );
		if ( $en > $de && $en >= 2 ) { return 'Englisch'; }
		if ( $de >= 2 ) { return 'Deutsch'; }
		return '';
	}

	/** Land: ISO2 → Name (Liste der Anfrage-Formulare), sonst unverändert. */
	function m24_anfrage_land( $land ) {
		$land = trim( (string) $land );
		if ( 2 === strlen( $land ) && class_exists( 'M24_Inquiry_Frontend' ) && method_exists( 'M24_Inquiry_Frontend', 'lands' ) ) {
			$l = M24_Inquiry_Frontend::lands();
			if ( isset( $l[ strtoupper( $land ) ] ) ) { return (string) $l[ strtoupper( $land ) ]; }
		}
		return $land;
	}

	/** Artikel einer Position: Titel, Preis, Link, Bild (aus dem Beitrag, wenn die ID bekannt ist). */
	function m24_anfrage_artikel( array $item ) {
		$pid   = (int) ( $item['src_pid'] ?? 0 );
		$url   = (string) ( $item['src_url'] ?? '' );
		$titel = html_entity_decode( wp_strip_all_tags( (string) ( $item['art'] ?? '' ) ), ENT_QUOTES, 'UTF-8' );
		$bild  = '';
		if ( $pid > 0 ) {
			$bild = (string) get_the_post_thumbnail_url( $pid, 'medium_large' );
			if ( '' === $url ) { $url = (string) get_permalink( $pid ); }
			if ( '' === $titel ) { $titel = html_entity_decode( get_the_title( $pid ), ENT_QUOTES, 'UTF-8' ); }
		}
		if ( '' === $bild && '' !== $url ) {
			$p = url_to_postid( $url );
			if ( $p ) { $bild = (string) get_the_post_thumbnail_url( $p, 'medium_large' ); }
		}
		$preis = trim( (string) ( $item['price'] ?? '' ) );
		return array(
			'titel' => $titel,
			'preis' => '' !== $preis ? $preis : 'Preis auf Anfrage',
			'url'   => $url,
			'bild'  => $bild,
			'menge' => max( 1, (int) ( $item['qty'] ?? 1 ) ),
			'artnr' => (string) ( $item['src_art_nr'] ?? '' ),
		);
	}

	/**
	 * Komplette HTML-Mail.
	 *
	 * @param array  $data   Daten wie in M24_Inquiries_Mail_Fallback (collect_inquiry_data / normalize_data).
	 * @param string $art    Beschriftung oben rechts („Teile-Anfrage“, „Frage zum Artikel“ …).
	 * @return string
	 */
	function m24_anfrage_mail_html( array $data, $art ) {
		$e      = static function ( $s ) { return esc_html( (string) $s ); };
		$name   = trim( (string) $data['vorname'] . ' ' . (string) $data['nachname'] );
		$name   = '' !== $name ? $name : ( '' !== (string) $data['email'] ? (string) $data['email'] : 'Unbekannt' );
		$kontakt = 'contact_form' === (string) ( $data['source'] ?? '' );

		list( $kopf, $nachricht ) = $kontakt ? m24_anfrage_notiz_zerlegen( $data['notes'] ?? '' ) : array( array(), trim( (string) ( $data['notes'] ?? '' ) ) );

		$land  = m24_anfrage_land( '' !== (string) $data['land'] ? $data['land'] : ( $kopf['Lieferland'] ?? '' ) );
		$typ   = '1' === (string) ( $data['biz'] ?? '' ) ? 'Geschäftskunde' : ( $kopf['Ich bin'] ?? 'Privatkunde' );
		if ( '' !== (string) $data['firma'] ) { $typ .= ' · ' . $data['firma']; }
		$spr   = m24_anfrage_sprache( $nachricht );
		$ts    = strtotime( (string) ( $data['post_date'] ?? '' ) ) ?: current_time( 'timestamp' );
		$zeit  = wp_date( 'd.m., H:i', $ts );
		$meta  = array_values( array_filter( array( $land, $typ, $spr, $zeit ) ) );

		// Positionen ohne die Hilfsposition des Kontaktformulars.
		$artikel = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $it ) {
			if ( ! is_array( $it ) ) { continue; }
			if ( $kontakt || 0 === strpos( (string) ( $it['art'] ?? '' ), 'Kontaktanfrage:' ) ) { continue; }
			$artikel[] = m24_anfrage_artikel( $it );
		}
		$gesucht = $kontakt ? trim( (string) ( $kopf['Teilenummer / Artikel'] ?? ( $kopf['Teilenummer'] ?? '' ) ) ) : '';
		$b_form  = 1 === count( $artikel ) && '' !== $artikel[0]['url'];

		$blau  = '#24477f';
		$logo  = esc_url( apply_filters( 'm24fz_mail_logo_url', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2023/09/Logo-MOTORSPORT24.de_.gif' ) );
		$mailz = '' !== (string) $data['email'] ? '<a href="mailto:' . esc_attr( $data['email'] ) . '" style="color:#2c5aa0;">' . $e( $data['email'] ) . '</a>' : '';
		$tel   = '' !== (string) ( $data['tel'] ?? '' ) ? ' · ' . $e( $data['tel'] ) : '';
		$knopf = static function ( $url, $text ) {
			return '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#2c5aa0;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:9px 16px;border-radius:6px;">' . esc_html( $text ) . '</a>';
		};
		$blase = '' !== $nachricht
			? '<div style="background:#f2f4f7;border-radius:4px 14px 14px 14px;padding:11px 13px;font-size:15px;line-height:1.5;color:#16202a;white-space:pre-line;">' . $e( $nachricht ) . '</div>'
			: '';
		$zitat = '' !== $nachricht
			? '<div style="border-left:3px solid #2c5aa0;background:#f5f8fc;padding:11px 13px;border-radius:0 6px 6px 0;font-size:15px;line-height:1.5;color:#16202a;white-space:pre-line;">' . $e( $nachricht ) . '</div>'
			: '';

		$h  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
		$h .= '<body style="margin:0;padding:0;background:#eef0f3;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;color:#16202a;">';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f3;"><tr><td align="center" style="padding:10px 6px;">';
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;">';
		// Kopf
		$h .= '<tr><td style="background:' . $blau . ';padding:10px 14px;' . ( $b_form ? 'border-bottom:3px solid #c9a24a;' : '' ) . '">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
			. '<td><img src="' . $logo . '" alt="MOTORSPORT24" height="28" style="display:block;height:28px;width:auto;border:0;"></td>'
			. '<td align="right" style="color:#dbe6f5;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;">' . $e( $art ) . '</td>'
			. '</tr></table></td></tr>';

		if ( $b_form ) {
			// Vorschlag B: Artikel zuerst
			$a = $artikel[0];
			if ( '' !== $a['bild'] ) {
				$h .= '<tr><td><a href="' . esc_url( $a['url'] ) . '"><img src="' . esc_url( $a['bild'] ) . '" alt="" width="600" style="display:block;width:100%;max-width:600px;height:auto;border:0;"></a></td></tr>';
			}
			$h .= '<tr><td style="padding:12px 14px 4px;">'
				. '<div style="font-size:10.5px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#8a95a1;">Artikel</div>'
				. '<div style="font-size:18px;font-weight:800;line-height:1.25;margin:2px 0;">' . $e( $a['titel'] ) . '</div>'
				. '<div style="font-size:14px;color:#16202a;margin-bottom:10px;">' . $e( $a['preis'] ) . ( $a['menge'] > 1 ? ' · Menge ' . (int) $a['menge'] : '' ) . ( '' !== $a['artnr'] ? ' · Art.-Nr. ' . $e( $a['artnr'] ) : '' ) . '</div>'
				. $knopf( $a['url'], 'Artikel ansehen' ) . '</td></tr>';
			$chips = '';
			foreach ( $meta as $m ) { $chips .= '<span style="display:inline-block;background:#eef2f7;border-radius:999px;padding:3px 9px;margin:0 4px 4px 0;font-size:12px;color:' . $blau . ';">' . $e( $m ) . '</span>'; }
			$h .= '<tr><td style="padding:14px 14px 0;"><div style="font-size:15px;font-weight:700;">' . $e( $name ) . '</div><div style="margin:6px 0 8px;">' . $chips . '</div>' . $blase . '</td></tr>';
		} else {
			// Vorschlag A: kompakt
			$h .= '<tr><td style="padding:14px 14px 0;">'
				. '<div style="font-size:20px;font-weight:800;line-height:1.2;">' . $e( $name ) . '</div>'
				. '<div style="font-size:13px;color:#56636f;margin:3px 0 12px;">' . $e( implode( ' · ', $meta ) ) . '</div>'
				. $zitat . '</td></tr>';
			if ( '' !== $gesucht ) {
				$such = home_url( '/?s=' . rawurlencode( $gesucht ) );
				$h .= '<tr><td style="padding:12px 14px 0;"><div style="border:1px dashed #c9d2db;border-radius:8px;padding:10px 12px;font-size:13.5px;">'
					. '<b style="display:block;font-size:14px;">Gesucht: ' . $e( $gesucht ) . '</b>'
					. 'Kein Artikel verknüpft · <a href="' . esc_url( $such ) . '" style="color:#2c5aa0;font-weight:600;text-decoration:none;">Auf motorsport24.de suchen →</a></div></td></tr>';
			}
			foreach ( $artikel as $a ) {
				$bild = '' !== $a['bild'] ? '<td width="96" style="padding-right:10px;vertical-align:middle;"><img src="' . esc_url( $a['bild'] ) . '" alt="" width="96" style="display:block;width:96px;height:auto;border-radius:5px;border:0;"></td>' : '';
				$link = '' !== $a['url'] ? '<a href="' . esc_url( $a['url'] ) . '" style="color:#2c5aa0;font-weight:600;font-size:13.5px;text-decoration:none;">Artikel ansehen →</a>' : '';
				$h .= '<tr><td style="padding:10px 14px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e6ea;border-radius:8px;"><tr><td style="padding:8px;">'
					. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $bild
					. '<td style="vertical-align:middle;"><div style="font-weight:700;font-size:14px;">' . $e( $a['titel'] ) . '</div>'
					. '<div style="color:#56636f;font-size:13px;">' . $e( $a['preis'] ) . ( $a['menge'] > 1 ? ' · Menge ' . (int) $a['menge'] : '' ) . '</div>' . $link . '</td>'
					. '</tr></table></td></tr></table></td></tr>';
			}
		}

		// Kontaktzeile + Fuß
		$h .= '<tr><td style="padding:12px 14px 14px;"><div style="border-top:1px solid #eef0f3;padding-top:10px;font-size:13px;color:#56636f;">Antwort an: ' . $mailz . $tel . '</div></td></tr>';
		$h .= '</table>';
		$m24_aid = (int) ( $data['post_id'] ?? 0 );
		$h .= '<div style="font-size:11px;color:#8a95a1;padding:8px;">' . ( $m24_aid ? 'Anfrage ' . $m24_aid . ' · ' : '' ) . 'motorsport24.de</div>';
		$h .= '</td></tr></table></body></html>';
		return $h;
	}
}
