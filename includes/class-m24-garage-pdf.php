<?php
/**
 * M24 Plattform — „Meine Garage", Etappe 3: Garage als PDF herunterladen.
 *
 * Baut auf Etappe 1/2 (M24_Garage_Cart) auf, nur ergänzt. Server-seitige PDF-Erzeugung über einen
 * auth-geschützten admin-post-Endpoint (Download, Content-Disposition: attachment). Inhalt + Preise
 * stammen 1:1 aus M24_Garage_Cart::items()/grand_total() (gemeinsame Pricing-Logik, NICHT dupliziert).
 *
 * PDF-Engine: Dompdf (HTML+CSS→PDF), gebündelt unter vendor/ (composer, dompdf/dompdf ^2.0).
 * Schrift: DejaVu Sans (Dompdf-Default) als sauberer Fallback — rendert € und Umlaute korrekt,
 * daher bewusst KEIN Saira-Embedding (vermeidet kaputte Glyphen).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Garage_PDF {

	const ACTION = 'm24_garage_pdf';
	const NONCE  = 'm24_garage_pdf';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) ); // nur mit gültigem Token (geteilte Ansicht)
	}

	/** Download-URL für den eingeloggten Eigentümer (nonce-geschützt). */
	public static function owner_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::NONCE );
	}

	/** Einzel-Fahrzeug-Exposé (scoped auf eine post_id) — gleiche Dompdf-Maschinerie, nonce-gated. */
	public static function vehicle_url( int $pid ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&pid=' . $pid ), self::NONCE );
	}

	/** Download-URL für die geteilte Read-only-Ansicht (token-gated, ohne Login). */
	public static function share_url( string $token ): string {
		return add_query_arg(
			array( 'action' => self::ACTION, 'share' => $token ),
			admin_url( 'admin-post.php' )
		);
	}

	/* ── Endpoint ────────────────────────────────────────────────────────── */

	public static function handle() {
		// 1) Account auflösen: entweder Token (geteilt, ohne Login) ODER eingeloggter Eigentümer (nonce).
		$token = isset( $_GET['share'] ) ? sanitize_text_field( wp_unslash( $_GET['share'] ) ) : '';
		if ( '' !== $token ) {
			$acc = M24_Garage_Cart::resolve_share_token( $token );
			if ( $acc <= 0 ) { wp_die( esc_html__( 'Dieser Link ist nicht (mehr) gültig.', 'm24-plattform' ), '', array( 'response' => 410 ) ); }
		} else {
			if ( ! is_user_logged_in() ) { wp_die( 'Bitte einloggen.', '', array( 'response' => 401 ) ); }
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) { wp_die( 'Sicherheitsprüfung fehlgeschlagen.', '', array( 'response' => 403 ) ); }
			$acc = M24_Garage_Cart::current_account_id();
		}

		$pid    = isset( $_GET['pid'] ) ? (int) $_GET['pid'] : 0;
		$expose = isset( $_GET['expose'] ) ? sanitize_key( wp_unslash( $_GET['expose'] ) ) : '';

		// 2) Fahrzeug-Exposé (TEIL B): eigenständiges Fahrzeug-Datenblatt statt Warenkorb-PDF.
		if ( 'vehicle' === $expose ) {
			if ( $pid <= 0 || 'm24_fahrzeug' !== get_post_type( $pid ) ) {
				wp_die( esc_html__( 'Fahrzeug nicht gefunden.', 'm24-plattform' ), '', array( 'response' => 404 ) );
			}
			try { $dompdf = self::dompdf_render( self::vehicle_html( $pid ) ); }
			catch ( \Throwable $t ) { wp_die( 'PDF-Bibliothek nicht verfügbar.', '', array( 'response' => 500 ) ); }
			while ( ob_get_level() > 0 ) { ob_end_clean(); }
			nocache_headers();
			$dompdf->stream( 'MOTORSPORT24-Expose-' . $pid . '.pdf', array( 'Attachment' => true ) );
			exit;
		}

		// 3) Garage-Exposé (Warenkorb). Optional auf ein Fahrzeug scopen (Alt-Pfad).
		$items = M24_Garage_Cart::items( $acc );
		if ( $pid > 0 ) {
			$items = array_values( array_filter( $items, static function ( $it ) use ( $pid ) {
				return (int) $it['post_id'] === $pid;
			} ) );
			if ( empty( $items ) ) { wp_die( esc_html__( 'Fahrzeug nicht in deiner Garage.', 'm24-plattform' ), '', array( 'response' => 404 ) ); }
		}
		list( , $grand_fmt, $has_unpriced ) = M24_Garage_Cart::grand_total( $items );

		try { $dompdf = self::dompdf_render( self::html( $items, $grand_fmt, $has_unpriced ) ); }
		catch ( \Throwable $t ) { wp_die( 'PDF-Bibliothek nicht verfügbar.', '', array( 'response' => 500 ) ); }

		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		nocache_headers();
		$dompdf->stream( 'MOTORSPORT24-Garage-' . gmdate( 'Y-m-d' ) . '.pdf', array( 'Attachment' => true ) ); // Content-Disposition: attachment
		exit;
	}

	/**
	 * PDF-Bytes für einen Account (optional auf ein Fahrzeug gescoped) — für den Mail-Anhang.
	 * Dieselbe Dompdf-Maschinerie + dasselbe HTML wie handle(); '' bei Fehler/fehlender Lib.
	 */
	public static function render_pdf_string( int $acc, int $pid = 0 ): string {
		if ( $acc <= 0 ) { return ''; }
		$items = M24_Garage_Cart::items( $acc );
		if ( $pid > 0 ) {
			$items = array_values( array_filter( $items, static function ( $it ) use ( $pid ) {
				return (int) $it['post_id'] === $pid;
			} ) );
		}
		list( , $grand_fmt, $has_unpriced ) = M24_Garage_Cart::grand_total( $items );
		try { return (string) self::dompdf_render( self::html( $items, $grand_fmt, $has_unpriced ) )->output(); }
		catch ( \Throwable $t ) { return ''; }
	}

	/** Vorschau-PDF mit Dummy-Positionen (Admin-Testtool) — echtes html()+Dompdf, ohne DB. */
	public static function preview_pdf_string(): string {
		$items = array(
			array( 'post_id' => 0, 'post_type' => 'm24_teil', 'qty' => 2, 'title' => 'Bremsscheibe vorn (Muster)', 'url' => home_url( '/' ), 'thumb' => '', 'artnr' => 'ART-1001', 'unit' => 149.90, 'unit_fmt' => '149,90 €', 'line_total' => 299.80, 'line_fmt' => '299,80 €' ),
			array( 'post_id' => 0, 'post_type' => 'm24_teil', 'qty' => 1, 'title' => 'Sportfahrwerk-Kit (Muster)', 'url' => home_url( '/' ), 'thumb' => '', 'artnr' => 'ART-2002', 'unit' => 1290.00, 'unit_fmt' => '1.290,00 €', 'line_total' => 1290.00, 'line_fmt' => '1.290,00 €' ),
		);
		try { return (string) self::dompdf_render( self::html( $items, '1.589,80 €', false ) )->output(); }
		catch ( \Throwable $t ) { return ''; }
	}

	/* ── Lokale Assets als data:-URI (offline, kein Remote nötig) ─────────── */

	private static function file_data_uri( string $file, int $max_bytes = 0 ): string {
		if ( '' === $file || ! is_readable( $file ) ) { return ''; }
		$size = (int) @filesize( $file );
		if ( $max_bytes > 0 && $size > $max_bytes ) { return ''; }
		$data = @file_get_contents( $file );
		if ( false === $data ) { return ''; }
		$mime = function_exists( 'mime_content_type' ) ? (string) mime_content_type( $file ) : 'image/png';
		if ( '' === $mime ) { $mime = 'image/png'; }
		return 'data:' . $mime . ';base64,' . base64_encode( $data );
	}

	private static function logo_uri(): string {
		// Farb-Logo (motorsport24-logo.jpg, 600×135) bevorzugt; Fallback auf bestehendes PNG.
		$jpg = self::file_data_uri( M24_PLATTFORM_DIR . 'assets/img/motorsport24-logo.jpg', 800 * 1024 );
		return '' !== $jpg ? $jpg : self::file_data_uri( M24_PLATTFORM_DIR . 'assets/img/m24-logo.png', 400 * 1024 );
	}

	/* ── Gemeinsames Briefpapier (Logo-Header + 4-Spalten-Footer, Liberation Sans) ──────── */

	/** @font-face für Liberation Sans (Arial-metrisch). Leer, wenn die TTFs (noch) fehlen → Arial-Fallback. */
	private static function font_face_css(): string {
		$reg  = M24_PLATTFORM_DIR . 'assets/fonts/LiberationSans-Regular.ttf';
		$bold = M24_PLATTFORM_DIR . 'assets/fonts/LiberationSans-Bold.ttf';
		if ( ! is_readable( $reg ) || ! is_readable( $bold ) ) { return ''; }
		return '@font-face{font-family:"Liberation Sans";font-weight:normal;font-style:normal;src:url("' . $reg . '") format("truetype");}'
			. '@font-face{font-family:"Liberation Sans";font-weight:bold;font-style:normal;src:url("' . $bold . '") format("truetype");}';
	}

	/**
	 * Gemeinsamer Rahmen-CSS für BEIDE Exposés (identisch). Fixed Header/Footer → wiederholen auf jeder
	 * Seite; @page-Ränder halten oben ~90pt (Logo) und unten ~70pt (Footer) frei, Content überlappt nicht.
	 */
	private static function frame_css(): string {
		return self::font_face_css()
			. '@page{size:A4;margin:90pt 46.28pt 70pt 46.28pt;}'
			. 'body{margin:0;font-family:"Liberation Sans",Arial,sans-serif;color:#1a1d23;font-size:11px;}'
			. '.m24-logo{position:fixed;right:46.28pt;top:28pt;width:130pt;height:29.2pt;}'
			. '.m24-foot{position:fixed;left:46.28pt;right:46.28pt;bottom:28pt;}'
			. '.m24-foot table{width:100%;border-collapse:collapse;}'
			. '.m24-foot td{width:120.75pt;font-size:6.5pt;line-height:1.5;color:#6b7280;vertical-align:top;padding:0;}';
	}

	/** Fixed Logo-Header (rechts oben) + fixed 4-Spalten-Footer — auf jeder Seite. */
	private static function frame_html( string $logo ): string {
		$logo_html = '' !== $logo ? '<img class="m24-logo" src="' . esc_attr( $logo ) . '">' : '';
		$foot = '<div class="m24-foot"><table><tr>'
			. '<td>MOTORSPORT24 GmbH<br>Scharfe Lanke 109-131<br>D-13595 Berlin, Germany</td>'
			. '<td>Tel: +49 (0)30 692014090<br>E-Mail: info@motorsport24.de<br>www.motorsport24.de</td>'
			. '<td>Bank: Commerzbank AG<br>IBAN: DE81 1204 0000 0133 3905 00<br>BIC: COBADEFFXXX</td>'
			. '<td>VAT No.: DE 356992287<br>EORI: DE 243988567282961<br>HRB 244506 B, AG Charlottenburg</td>'
			. '</tr></table></div>';
		return $logo_html . $foot;
	}

	/** Dompdf-Options: Liberation Sans als Default + beschreibbares Font-Cache-Verzeichnis (vendor/ oft read-only). */
	private static function dompdf_options() {
		$o = new \Dompdf\Options();
		$o->set( 'isRemoteEnabled', false );      // nur lokale data:-URIs + lokale @font-face-TTF
		$o->set( 'isHtml5ParserEnabled', true );
		$o->set( 'defaultFont', 'Liberation Sans' );
		$o->set( 'isFontSubsettingEnabled', true );
		$up   = wp_get_upload_dir();
		$fdir = trailingslashit( $up['basedir'] ) . 'm24-dompdf-fonts';
		if ( wp_mkdir_p( $fdir ) ) { $o->set( 'fontDir', $fdir ); $o->set( 'fontCache', $fdir ); }
		return $o;
	}

	/** HTML → gerendertes Dompdf-Objekt (gemeinsamer Pfad). Wirft, wenn die Lib fehlt. */
	private static function dompdf_render( string $html ) {
		$autoload = M24_PLATTFORM_DIR . 'vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) { throw new \RuntimeException( 'PDF-Bibliothek fehlt.' ); }
		require_once $autoload;
		if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) { throw new \RuntimeException( 'PDF-Bibliothek fehlt.' ); }
		$dompdf = new \Dompdf\Dompdf( self::dompdf_options() );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		return $dompdf;
	}

	/** Kleines Thumbnail einer Position als data:-URI (Intermediate-Größe, sonst Original; große Dateien überspringen). */
	private static function thumb_uri( int $pid ): string {
		$tid = (int) get_post_thumbnail_id( $pid );
		if ( ! $tid ) { return ''; }
		$file = '';
		$inter = image_get_intermediate_size( $tid, 'thumbnail' );
		if ( $inter && ! empty( $inter['path'] ) ) {
			$up   = wp_get_upload_dir();
			$file = trailingslashit( $up['basedir'] ) . $inter['path'];
		}
		if ( '' === $file || ! is_readable( $file ) ) {
			$file = (string) get_attached_file( $tid );
		}
		return self::file_data_uri( $file, 600 * 1024 );
	}

	/* ── HTML (CI: Messing #9a6b25, Blau #0e447e) ────────────────────────── */

	private static function html( array $items, string $grand_fmt, bool $has_unpriced ): string {
		$logo = self::logo_uri();
		$date = function_exists( 'wp_date' ) ? wp_date( 'd.m.Y' ) : gmdate( 'd.m.Y' );

		$rows = '';
		if ( empty( $items ) ) {
			$rows = '<tr><td colspan="5" class="empty">Diese Garage ist aktuell leer.</td></tr>';
		} else {
			foreach ( $items as $it ) {
				$thumb = self::thumb_uri( (int) $it['post_id'] );
				$img   = $thumb ? '<img class="th" src="' . esc_attr( $thumb ) . '">' : '<span class="th th-ph"></span>';
				$unit  = ( null !== $it['unit_fmt'] ) ? esc_html( $it['unit_fmt'] ) : '<span class="ask">Preis auf Anfrage</span>';
				$line  = ( null !== $it['line_fmt'] ) ? esc_html( $it['line_fmt'] ) : '—';
				$artnr = ( '' !== $it['artnr'] ) ? '<div class="art">Art.-Nr.: ' . esc_html( $it['artnr'] ) . '</div>' : '';
				$rows .= '<tr>'
					. '<td class="c-img">' . $img . '</td>'
					. '<td class="c-tit"><div class="tit">' . esc_html( $it['title'] ) . '</div>' . $artnr . '</td>'
					. '<td class="c-unit">' . $unit . '</td>'
					. '<td class="c-qty">' . (int) $it['qty'] . '</td>'
					. '<td class="c-line">' . $line . '</td>'
					. '</tr>';
			}
		}

		$note = $has_unpriced
			? '<p class="note">Einzelne Positionen sind „Preis auf Anfrage" und nicht in der Gesamtsumme enthalten.</p>'
			: '';

		$css = self::frame_css()
			. '.h-title { font-size: 19px; font-weight: bold; margin: 0 0 2px; }'
			. '.h-date { color: #5a6474; font-size: 11px; margin-bottom: 16px; }'
			. 'table.items { width: 100%; border-collapse: collapse; }'
			. 'table.items th { text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #5a6474; border-bottom: 1px solid #c9ced4; padding: 0 6px 6px; }'
			. 'table.items td { padding: 8px 6px; border-bottom: 1px solid #eef0f2; vertical-align: middle; }'
			. '.c-img { width: 46px; }'
			. '.th { width: 44px; height: 34px; }'
			. '.th-ph { display: inline-block; width: 44px; height: 34px; background: #eef0f2; }'
			. '.tit { font-weight: bold; font-size: 11.5px; }'
			. '.art { color: #8a929c; font-size: 9.5px; margin-top: 2px; }'
			. '.c-unit { white-space: nowrap; }'
			. '.c-qty { text-align: center; width: 44px; }'
			. '.c-line { text-align: right; white-space: nowrap; font-weight: bold; width: 92px; }'
			. '.ask { color: #9a6b25; }'
			. '.empty { text-align: center; color: #5a6474; padding: 22px 0; }'
			. '.sum { margin-top: 14px; padding-top: 10px; border-top: 2px solid #14161a; text-align: right; }'
			. '.sum .lbl { color: #5a6474; font-size: 11px; }'
			. '.sum .val { font-size: 17px; font-weight: bold; margin-left: 14px; }'
			. '.note { color: #8a929c; font-size: 9.5px; text-align: right; margin: 6px 0 0; }';

		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
			. self::frame_html( $logo )
			. '<div class="h-title">Meine Garage</div>'
			. '<div class="h-date">Stand: ' . esc_html( $date ) . '</div>'
			. '<table class="items"><thead><tr>'
			. '<th></th><th>Position</th><th>Einzelpreis</th><th>Menge</th><th>Summe</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>'
			. '<div class="sum"><span class="lbl">Gesamtsumme</span><span class="val">' . esc_html( $grand_fmt ) . '</span></div>'
			. $note
			. '</body></html>';
	}

	/* ── TEIL B: Fahrzeug-Exposé (gleicher Rahmen, Fahrzeug-Datenblatt) ──────────────────── */

	/** Nonce-URL für das Fahrzeug-Exposé (gleiches Muster wie owner_url, scoped auf ein Fahrzeug). */
	public static function vehicle_expose_url( int $pid ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&expose=vehicle&pid=' . $pid ), self::NONCE );
	}

	/** Fahrzeug-Hauptbild als data:-URI (Featured → sonst erstes Außen-Galeriebild). */
	private static function vehicle_image_uri( int $pid ): string {
		$tid = (int) get_post_thumbnail_id( $pid );
		if ( ! $tid ) {
			$gal = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $pid, '_m24fz_gal_aussen', true ) ) ) );
			$tid = ! empty( $gal ) ? (int) $gal[0] : 0;
		}
		if ( ! $tid ) { return ''; }
		$file  = '';
		$inter = image_get_intermediate_size( $tid, 'large' );
		if ( $inter && ! empty( $inter['path'] ) ) {
			$up   = wp_get_upload_dir();
			$file = trailingslashit( $up['basedir'] ) . $inter['path'];
		}
		if ( '' === $file || ! is_readable( $file ) ) { $file = (string) get_attached_file( $tid ); }
		return self::file_data_uri( $file, 2 * 1024 * 1024 );
	}

	/** HTML des Fahrzeug-Exposés — gleicher Rahmen + Tabellenstil wie das Garage-Exposé. */
	private static function vehicle_html( int $pid ): string {
		$logo  = self::logo_uri();
		$img   = self::vehicle_image_uri( $pid );
		$title = get_the_title( $pid );
		$marke = trim( (string) get_post_meta( $pid, '_m24fz_marke', true ) . ' ' . (string) get_post_meta( $pid, '_m24fz_modell', true ) );

		$m = function ( $key ) use ( $pid ) { return trim( (string) get_post_meta( $pid, '_m24fz_' . $key, true ) ); };

		// Eckdaten — nur vorhandene Felder ausgeben.
		$km_val = $m( 'laufleistung' );
		$km_ein = $m( 'laufleistung_einheit' );
		$specs = array(
			'Baujahr'       => $m( 'baujahr' ),
			'Erstzulassung' => $m( 'erstzulassung' ),
			'Laufleistung'  => '' !== $km_val ? trim( $km_val . ' ' . ( '' !== $km_ein ? $km_ein : 'km' ) ) : '',
			'Leistung'      => '' !== $m( 'leistung_ps' ) ? $m( 'leistung_ps' ) . ' PS' : '',
			'Hubraum'       => $m( 'hubraum' ),
			'Getriebe'      => $m( 'getriebe' ),
			'Kraftstoff'    => $m( 'kraftstoff' ),
			'Antrieb'       => $m( 'antrieb' ),
			'Karosserie'    => $m( 'karosserie' ),
			'Außenfarbe'    => $m( 'aussenfarbe' ),
			'FIN'           => $m( 'fin' ),
		);
		$rows = '';
		foreach ( $specs as $label => $val ) {
			if ( '' === $val ) { continue; }
			$rows .= '<tr><td class="k">' . esc_html( $label ) . '</td><td class="v">' . esc_html( $val ) . '</td></tr>';
		}

		// Preis (Brutto, §25a) oder „auf Anfrage".
		$preis_auf_anfrage = (bool) (int) get_post_meta( $pid, '_m24fz_preis_auf_anfrage', true );
		$preis_val = (int) get_post_meta( $pid, '_m24fz_preis', true );
		if ( $preis_auf_anfrage || $preis_val <= 0 ) {
			$preis_fmt = 'Preis auf Anfrage';
		} else {
			$preis_fmt = class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::format( (float) $preis_val ) : ( number_format( $preis_val, 2, ',', '.' ) . ' €' );
		}

		$besch = (string) get_post_meta( $pid, '_m24fz_beschreibung', true );
		$besch_html = '' !== trim( $besch ) ? '<div class="sec-h">Beschreibung</div><div class="besch">' . nl2br( esc_html( wp_strip_all_tags( $besch ) ) ) . '</div>' : '';

		$img_html = '' !== $img ? '<img class="hero" src="' . esc_attr( $img ) . '">' : '';

		$css = self::frame_css()
			. '.v-title { font-size: 20px; font-weight: bold; margin: 0 0 2px; }'
			. '.v-sub { color: #5a6474; font-size: 12px; margin-bottom: 14px; }'
			. '.hero { width: 100%; height: auto; margin: 0 0 16px; }'
			. 'table.specs { width: 100%; border-collapse: collapse; margin-bottom: 8px; }'
			. 'table.specs td { padding: 7px 6px; border-bottom: 1px solid #eef0f2; vertical-align: top; font-size: 11px; }'
			. 'table.specs td.k { color: #5a6474; width: 150px; }'
			. 'table.specs td.v { font-weight: bold; }'
			. '.price { margin: 14px 0; padding-top: 10px; border-top: 2px solid #14161a; text-align: right; }'
			. '.price .lbl { color: #5a6474; font-size: 11px; }'
			. '.price .val { font-size: 17px; font-weight: bold; margin-left: 14px; }'
			. '.sec-h { font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #5a6474; margin: 16px 0 6px; }'
			. '.besch { font-size: 11px; line-height: 1.55; color: #1a1d23; }';

		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
			. self::frame_html( $logo )
			. '<div class="v-title">' . esc_html( $title ) . '</div>'
			. ( '' !== $marke ? '<div class="v-sub">' . esc_html( $marke ) . '</div>' : '' )
			. $img_html
			. ( '' !== $rows ? '<table class="specs">' . $rows . '</table>' : '' )
			. '<div class="price"><span class="lbl">Preis</span><span class="val">' . esc_html( $preis_fmt ) . '</span></div>'
			. $besch_html
			. '</body></html>';
	}
}
