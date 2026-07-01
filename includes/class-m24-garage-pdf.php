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
		list( $grand_num, $grand_fmt, $has_unpriced ) = M24_Garage_Cart::grand_total( $items );
		$net_fmt = number_format( (float) $grand_num / 1.19, 2, ',', '.' ) . ' €';

		try { $dompdf = self::dompdf_render( self::html( $items, $grand_fmt, $has_unpriced, $net_fmt ) ); }
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
		list( $grand_num, $grand_fmt, $has_unpriced ) = M24_Garage_Cart::grand_total( $items );
		$net_fmt = number_format( (float) $grand_num / 1.19, 2, ',', '.' ) . ' €';
		try { return (string) self::dompdf_render( self::html( $items, $grand_fmt, $has_unpriced, $net_fmt ) )->output(); }
		catch ( \Throwable $t ) { return ''; }
	}

	/** Vorschau-PDF mit Dummy-Positionen (Admin-Testtool) — echtes html()+Dompdf, ohne DB. */
	public static function preview_pdf_string(): string {
		$items = array(
			array( 'post_id' => 0, 'post_type' => 'm24_teil', 'qty' => 2, 'title' => 'Bremsscheibe vorn (Muster)', 'url' => home_url( '/' ), 'thumb' => '', 'artnr' => 'ART-1001', 'unit' => 149.90, 'unit_fmt' => '149,90 €', 'line_total' => 299.80, 'line_fmt' => '299,80 €' ),
			array( 'post_id' => 0, 'post_type' => 'm24_teil', 'qty' => 1, 'title' => 'Sportfahrwerk-Kit (Muster)', 'url' => home_url( '/' ), 'thumb' => '', 'artnr' => 'ART-2002', 'unit' => 1290.00, 'unit_fmt' => '1.290,00 €', 'line_total' => 1290.00, 'line_fmt' => '1.290,00 €' ),
		);
		$net_fmt = number_format( 1589.80 / 1.19, 2, ',', '.' ) . ' €';
		try { return (string) self::dompdf_render( self::html( $items, '1.589,80 €', false, $net_fmt ) )->output(); }
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

	/* ── Gemeinsames Briefpapier (Logo-Header + 4-Spalten-Footer, Liberation Sans) ──────── */

	/**
	 * @font-face für Liberation Sans (Arial-metrisch) als Zusatz zu registerFont(): src = ABSOLUTER
	 * file://-Pfad, unter chroot auflösbar (isRemoteEnabled bleibt false). Leer, wenn TTFs fehlen → Arial.
	 */
	private static function font_face_css(): string {
		$reg  = M24_PLATTFORM_DIR . 'assets/fonts/LiberationSans-Regular.ttf';
		$bold = M24_PLATTFORM_DIR . 'assets/fonts/LiberationSans-Bold.ttf';
		if ( ! is_readable( $reg ) || ! is_readable( $bold ) ) { return ''; }
		$reg_url  = 'file://' . $reg;
		$bold_url = 'file://' . $bold;
		return '@font-face{font-family:"Liberation Sans";font-weight:normal;font-style:normal;src:url("' . $reg_url . '") format("truetype");}'
			. '@font-face{font-family:"Liberation Sans";font-weight:bold;font-style:normal;src:url("' . $bold_url . '") format("truetype");}';
	}

	/**
	 * Gemeinsamer Rahmen-CSS für BEIDE Exposés (identisch). Fixed Header/Footer → wiederholen auf jeder
	 * Seite; @page-Ränder halten oben 120pt (Logo-Band) und unten 90pt (4-Zeilen-Footer) frei → kein Overlap.
	 */
	private static function frame_css(): string {
		// Logo + 4-Spalten-Footer werden NICHT per CSS (position:fixed unzuverlässig), sondern per Canvas
		// in absoluten Seitenkoordinaten gezeichnet — siehe draw_frame(). Hier nur die Content-Zone.
		return self::font_face_css()
			// Content-Zone reservieren: oben 120pt (Logo-Band + Luft), unten 90pt (4-Zeilen-Footer + Luft),
			// seitlich 56pt. Fließtext läuft dadurch WEDER in die Header- NOCH in die Footer-Zone.
			. '@page{size:A4;margin:120pt 56pt 90pt 56pt;}'
			. 'body{margin:0;font-family:"Liberation Sans",Arial,sans-serif;color:#1a1d23;font-size:11px;}';
	}

	/**
	 * EINE gemeinsame Dokument-Hülle für BEIDE Exposés — garantiert identischen <head>/@page (frame_css).
	 * $content_css = NUR content-spezifisches CSS (KEIN zweites @page), $body = reiner Content.
	 * Logo + Footer kommen NICHT aus dem HTML, sondern per Canvas (draw_frame) in Seitenkoordinaten.
	 */
	private static function document( string $content_css, string $body ): string {
		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><style>'
			. self::frame_css() . $content_css
			. '</style></head><body>'
			. $body
			. '</body></html>';
	}

	/** Beschreibbares Font-Cache-Verzeichnis (vendor/ ist oft read-only). '' wenn nirgends beschreibbar. */
	private static function font_cache_dir(): string {
		$up   = wp_get_upload_dir();
		$dir  = trailingslashit( $up['basedir'] ) . 'm24-dompdf-fonts';
		if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); } // VOR dem Render anlegen, wenn er fehlt
		if ( is_dir( $dir ) && wp_is_writable( $dir ) ) { return $dir; }
		// Fallback: System-Temp.
		$tmp = trailingslashit( get_temp_dir() ) . 'm24-dompdf-fonts';
		if ( ! is_dir( $tmp ) ) { wp_mkdir_p( $tmp ); }
		if ( is_dir( $tmp ) && wp_is_writable( $tmp ) ) { return $tmp; }
		self::log_font_warning( 'Kein beschreibbares Font-Cache-Verzeichnis', $dir );
		return '';
	}

	/** Dompdf-Options: Liberation Sans als Default + beschreibbarer Font-Cache + chroot inkl. Plugin-Assets. */
	private static function dompdf_options() {
		$o = new \Dompdf\Options();
		$o->set( 'isRemoteEnabled', false );      // nur lokale data:-URIs + lokale TTF
		$o->set( 'isHtml5ParserEnabled', true );
		$o->set( 'defaultFont', 'Liberation Sans' );
		$o->set( 'isFontSubsettingEnabled', true );

		$cache = self::font_cache_dir();
		if ( '' !== $cache ) {
			$o->set( 'fontDir', $cache );
			$o->set( 'fontCache', $cache );
		}
		// chroot MUSS den Plugin-assets-Ordner enthalten, sonst verweigert Dompdf den TTF-Zugriff
		// (@font-face-src = absoluter lokaler Pfad; isRemoteEnabled bleibt false).
		$up      = wp_get_upload_dir();
		$chroot  = array_values( array_unique( array_filter( array(
			rtrim( M24_PLATTFORM_DIR, '/\\' ),
			(string) $up['basedir'],
			$cache,
		) ) ) );
		$o->set( 'chroot', $chroot );
		return $o;
	}

	/** Liberation-TTFs explizit registrieren (robuster als nur @font-face) + WARNING loggen, wenn's scheitert. */
	private static function register_fonts( $dompdf ): void {
		$fonts = array(
			'normal' => M24_PLATTFORM_DIR . 'assets/fonts/LiberationSans-Regular.ttf',
			'bold'   => M24_PLATTFORM_DIR . 'assets/fonts/LiberationSans-Bold.ttf',
		);
		$fm = method_exists( $dompdf, 'getFontMetrics' ) ? $dompdf->getFontMetrics() : null;
		if ( ! $fm || ! method_exists( $fm, 'registerFont' ) ) {
			self::log_font_warning( 'FontMetrics::registerFont nicht verfügbar', 'dompdf' );
			return;
		}
		foreach ( $fonts as $weight => $path ) {
			if ( ! is_readable( $path ) ) { self::log_font_warning( 'TTF nicht lesbar', $path ); continue; }
			$ok = false;
			try {
				$ok = (bool) $fm->registerFont(
					array( 'family' => 'Liberation Sans', 'weight' => $weight, 'style' => 'normal' ),
					$path
				);
			} catch ( \Throwable $t ) {
				self::log_font_warning( 'registerFont-Ausnahme (' . $weight . '): ' . $t->getMessage(), $path );
				continue;
			}
			if ( ! $ok ) { self::log_font_warning( 'registerFont fehlgeschlagen (' . $weight . ')', $path ); }
		}
	}

	private static function log_font_warning( string $msg, string $path ): void {
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::warning( 'pdf_font', $msg, array( 'file' => basename( $path ) ) );
		}
	}

	/**
	 * Logo + 4-Spalten-Footer per Dompdf-Canvas in ABSOLUTEN Seitenkoordinaten zeichnen (nach dem Render).
	 * Grund: position:fixed wird in dieser Dompdf-Version relativ zur @page-Content-Box positioniert, nicht
	 * zur physischen Seite → CSS-Fixed-Header/Footer landeten IM Content. page_script/page_text umgeht das
	 * und wiederholt Logo+Footer auf JEDER Seite in den Randbändern.
	 */
	private static function draw_frame( $dompdf ): void {
		$canvas = $dompdf->getCanvas();
		$fm     = $dompdf->getFontMetrics();
		if ( ! $canvas ) { return; }
		$W = $canvas->get_width(); // A4 = 595.28pt

		// Logo oben rechts im oberen Randband (rechte Kante x=549pt, 130×29,2pt, y=28).
		$logo = M24_PLATTFORM_DIR . 'assets/img/motorsport24-logo.jpg';
		if ( ! is_readable( $logo ) ) { $logo = M24_PLATTFORM_DIR . 'assets/img/m24-logo.png'; }
		if ( is_readable( $logo ) ) {
			$lw = 130.0; $lh = 29.2; $lx = $W - 46.28 - $lw; $ly = 28.0;
			$canvas->page_script( function ( $pageNumber, $pageCount, $canvas, $fontMetrics ) use ( $logo, $lx, $ly, $lw, $lh ) {
				$canvas->image( $logo, $lx, $ly, $lw, $lh );
			} );
		}

		// 4-Spalten-Footer im unteren Randband (y=795, 6.5pt, grau, Zeilenhöhe 8).
		$font = $fm ? $fm->getFont( 'Liberation Sans', 'normal' ) : null;
		if ( ! $font && $fm ) { $font = $fm->getFont( 'Helvetica', 'normal' ); }
		if ( $font ) {
			$grey = array( 0.42, 0.45, 0.49 ); // ~#6b7280
			$cols = array( 46.28, 46.28 + 120.75, 46.28 + 2 * 120.75, 46.28 + 3 * 120.75 );
			$foot = array(
				array( 'MOTORSPORT24 GmbH', 'Scharfe Lanke 109-131', 'D-13595 Berlin, Germany' ),
				array( 'Tel: +49 (0)30 692014090', 'E-Mail: info@motorsport24.de', 'www.motorsport24.de' ),
				array( 'Bank: Commerzbank AG', 'IBAN: DE81 1204 0000 0133 3905 00', 'BIC: COBADEFFXXX' ),
				array( 'VAT No.: DE 356992287', 'EORI: DE 243988567282961', 'HRB 244506 B, AG Charlottenburg' ),
			);
			$fy = 795.0; $size = 6.5; $lh2 = 8.0;
			foreach ( $cols as $ci => $x ) {
				foreach ( $foot[ $ci ] as $li => $line ) {
					$canvas->page_text( $x, $fy + $li * $lh2, $line, $font, $size, $grey );
				}
			}
		}
	}

	/** HTML → gerendertes Dompdf-Objekt (gemeinsamer Pfad). Wirft, wenn die Lib fehlt. */
	private static function dompdf_render( string $html ) {
		$autoload = M24_PLATTFORM_DIR . 'vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) { throw new \RuntimeException( 'PDF-Bibliothek fehlt.' ); }
		require_once $autoload;
		if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) { throw new \RuntimeException( 'PDF-Bibliothek fehlt.' ); }
		$dompdf = new \Dompdf\Dompdf( self::dompdf_options() );
		self::register_fonts( $dompdf ); // VOR loadHtml/render → Liberation Sans steht im Font-Cache
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		self::draw_frame( $dompdf ); // Logo + Footer in absoluten Seitenkoordinaten (jede Seite)
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

	private static function html( array $items, string $grand_fmt, bool $has_unpriced, string $net_fmt = '' ): string {
		$date = function_exists( 'wp_date' ) ? wp_date( 'd.m.Y' ) : gmdate( 'd.m.Y' );

		$rows = '';
		if ( empty( $items ) ) {
			$rows = '<tr><td colspan="4" class="empty">Diese Garage ist aktuell leer.</td></tr>';
		} else {
			foreach ( $items as $it ) {
				$unit  = ( null !== $it['unit_fmt'] ) ? esc_html( $it['unit_fmt'] ) : '<span class="ask">Preis auf Anfrage</span>';
				$line  = ( null !== $it['line_fmt'] ) ? esc_html( $it['line_fmt'] ) : '—';
				$artnr = ( '' !== $it['artnr'] ) ? '<div class="art">Art.-Nr.: ' . esc_html( $it['artnr'] ) . '</div>' : '';
				$t     = esc_html( $it['title'] );
				$tit   = ( '' !== $it['url'] ) ? '<a href="' . esc_url( $it['url'] ) . '">' . $t . '</a>' : $t;
				$rows .= '<tr>'
					. '<td class="c-pos"><div class="tit">' . $tit . '</div>' . $artnr . '</td>'
					. '<td class="r c-unit">' . $unit . '</td>'
					. '<td class="r c-qty">' . (int) $it['qty'] . '</td>'
					. '<td class="r c-line">' . $line . '</td>'
					. '</tr>';
			}
		}

		$note = $has_unpriced
			? '<p class="note">Einzelne Positionen sind „Preis auf Anfrage" und nicht in der Gesamtsumme enthalten.</p>'
			: '';

		$css = // NUR content-spezifisches CSS — @page/Frame kommt aus document()/frame_css().
			// Titelblock IM Content-Fluss (nicht fixed) — beginnt sauber unter der Header-Zone.
			'.g-head { margin: 0 0 14pt; }'
			. '.g-head .h-title { font-size: 16pt; font-weight: bold; margin: 0; color: #1a1d23; }'
			. '.g-head .h-title a { color: inherit; text-decoration: none; }'
			. '.g-head .h-garagelink { font-size: 9.5pt; font-style: italic; margin-top: 2px; }'
			. '.g-head .h-garagelink a { color: #1f74c4; text-decoration: none; }'
			. '.g-head .h-date { color: #6b7280; font-size: 9pt; margin-top: 3px; }'
			. '.tit a { color: inherit; text-decoration: none; }'
			// Referenz-Tabelle: kein Bild, Kopf hellgrau, Zahlen rechtsbündig, dünne Zeilentrenner.
			. 'table.items { width: 100%; border-collapse: collapse; }'
			. 'table.items th { background: #f3f4f6; text-align: left; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: .03em; color: #374151; padding: 7pt 8pt; border-bottom: 1px solid #e5e7eb; }'
			. 'table.items td { padding: 8pt; border-bottom: 1px solid #e5e7eb; vertical-align: top; font-size: 10pt; }'
			. 'table.items th.r, table.items td.r { text-align: right; }'
			. '.c-unit-col { width: 92pt; } .c-qty-col { width: 52pt; } .c-line-col { width: 92pt; }'
			. '.tit { font-weight: bold; font-size: 10.5pt; color: #1a1d23; }'
			. '.art { color: #9aa3b0; font-size: 8pt; margin-top: 2px; }'
			. '.c-unit, .c-line { white-space: nowrap; } .c-line { font-weight: bold; }'
			. '.ask { color: #9a6b25; }'
			. '.empty { text-align: center; color: #6b7280; padding: 22pt 0; }'
			// Gesamtsumme = rechtsbündiger grauer Block (wie „Gross total").
			. 'table.sum { width: 100%; border-collapse: collapse; margin-top: 12pt; }'
			. 'table.sum td { padding: 8pt; }'
			. 'table.sum .sum-spacer { width: 55%; }'
			. 'table.sum .sum-lbl { background: #f3f4f6; text-align: right; font-weight: bold; font-size: 10pt; color: #374151; }'
			. 'table.sum .sum-val { background: #f3f4f6; text-align: right; font-weight: bold; font-size: 12pt; color: #1a1d23; white-space: nowrap; }'
			// Netto- + USt-Zeilen: gleicher grauer Block, kleiner/grau, kompakt.
			. 'table.sum .sum-sub { background: #f3f4f6; text-align: right; font-size: 9pt; color: #5a6474; padding: 2pt 8pt; }'
			. 'table.sum .sum-sub-last { padding-bottom: 8pt; }'
			. '.note { color: #9aa3b0; font-size: 8pt; text-align: right; margin: 6pt 0 0; }';

		$garage_url = apply_filters( 'm24_teile_garage_url', class_exists( 'M24_Garage_Cart' ) ? M24_Garage_Cart::page_url() : home_url( '/meine-garage/' ) );
		$body = '<div class="g-head">'
			. '<div class="h-title"><a href="' . esc_url( home_url( '/' ) ) . '">Meine Garage</a></div>'
			. '<div class="h-garagelink"><a href="' . esc_url( $garage_url ) . '">Zur Teile-Garage</a></div>'
			. '<div class="h-date">Stand: ' . esc_html( $date ) . '</div></div>'
			. '<table class="items"><thead><tr>'
			. '<th class="c-pos">Position</th><th class="r c-unit-col">Einzelpreis</th><th class="r c-qty-col">Menge</th><th class="r c-line-col">Summe</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>'
			. '<table class="sum">'
			. '<tr><td class="sum-spacer"></td><td class="sum-lbl">Gesamtsumme (Brutto)</td><td class="sum-val">' . esc_html( $grand_fmt ) . '</td></tr>'
			. ( '' !== $net_fmt ? '<tr><td class="sum-spacer"></td><td class="sum-sub">Netto</td><td class="sum-sub">' . esc_html( $net_fmt ) . '</td></tr>' : '' )
			. '<tr><td class="sum-spacer"></td><td class="sum-sub sum-sub-last" colspan="2">inkl. 19 % USt</td></tr>'
			. '</table>'
			. $note;
		return self::document( $css, $body );
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
		$tax_note  = '';
		if ( $preis_auf_anfrage || $preis_val <= 0 ) {
			$preis_fmt = 'Preis auf Anfrage'; // KEIN Steuerhinweis
		} else {
			$preis_fmt = class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::format( (float) $preis_val ) : ( number_format( $preis_val, 2, ',', '.' ) . ' €' );
			// Steuer-/Netto-Hinweis exakt wie die Fahrzeugseite (preis_html) über _m24fz_mwst_ausweisbar.
			$ausweisbar = (int) get_post_meta( $pid, '_m24fz_mwst_ausweisbar', true );
			if ( $ausweisbar ) {
				$netto     = (float) $preis_val / 1.19;
				$netto_fmt = class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::format( $netto ) : ( number_format( $netto, 2, ',', '.' ) . ' €' );
				$tax_note  = 'inkl. 19 % MwSt. · Netto ' . $netto_fmt;
			} else {
				$tax_note = 'Differenzbesteuert nach §25a UStG'; // KEIN Netto/USt ausweisen
			}
		}

		$besch = (string) get_post_meta( $pid, '_m24fz_beschreibung', true );
		$besch_html = '' !== trim( $besch ) ? '<div class="sec-h">Beschreibung</div><div class="besch">' . nl2br( esc_html( wp_strip_all_tags( $besch ) ) ) . '</div>' : '';

		$img_html = '' !== $img ? '<img class="hero" src="' . esc_attr( $img ) . '">' : '';

		$css = // NUR content-spezifisches CSS — @page/Frame kommt aus document()/frame_css().
			'.v-title { font-size: 20px; font-weight: bold; margin: 0 0 2px; }'
			. '.v-sub { color: #5a6474; font-size: 12px; margin-bottom: 14px; }'
			// Hero begrenzt, damit es NIE in die Footer-Zone läuft (max-height statt height:auto ohne Grenze).
			. '.hero { display: block; max-width: 100%; max-height: 230pt; width: auto; height: auto; margin: 0 0 16px; }'
			. 'table.specs { width: 100%; border-collapse: collapse; margin-bottom: 8px; }'
			. 'table.specs td { padding: 7px 6px; border-bottom: 1px solid #eef0f2; vertical-align: top; font-size: 11px; }'
			. 'table.specs td.k { color: #5a6474; width: 150px; }'
			. 'table.specs td.v { font-weight: bold; }'
			. '.price { margin: 14px 0; padding-top: 10px; border-top: 2px solid #14161a; text-align: right; }'
			. '.price .lbl { color: #5a6474; font-size: 11px; }'
			. '.price .val { font-size: 17px; font-weight: bold; margin-left: 14px; }'
			. '.price-tax { text-align: right; color: #5a6474; font-size: 9.5px; margin-top: 3px; }'
			. '.sec-h { font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #5a6474; margin: 16px 0 6px; }'
			. '.besch { font-size: 11px; line-height: 1.55; color: #1a1d23; }';

		$body = '<div class="v-title">' . esc_html( $title ) . '</div>'
			. ( '' !== $marke ? '<div class="v-sub">' . esc_html( $marke ) . '</div>' : '' )
			. $img_html
			. ( '' !== $rows ? '<table class="specs">' . $rows . '</table>' : '' )
			. '<div class="price"><span class="lbl">Preis</span><span class="val">' . esc_html( $preis_fmt ) . '</span></div>'
			. ( '' !== $tax_note ? '<div class="price-tax">' . esc_html( $tax_note ) . '</div>' : '' )
			. $besch_html;
		return self::document( $css, $body );
	}
}
