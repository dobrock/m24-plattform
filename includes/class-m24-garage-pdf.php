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

		// 2) Inhalt aus der gemeinsamen Logik. Optional auf ein Fahrzeug scopen (Einzel-Exposé).
		$items = M24_Garage_Cart::items( $acc );
		$pid   = isset( $_GET['pid'] ) ? (int) $_GET['pid'] : 0;
		if ( $pid > 0 ) {
			$items = array_values( array_filter( $items, static function ( $it ) use ( $pid ) {
				return (int) $it['post_id'] === $pid;
			} ) );
			if ( empty( $items ) ) { wp_die( esc_html__( 'Fahrzeug nicht in deiner Garage.', 'm24-plattform' ), '', array( 'response' => 404 ) ); }
		}
		list( , $grand_fmt, $has_unpriced ) = M24_Garage_Cart::grand_total( $items );

		// 3) HTML → PDF.
		$autoload = M24_PLATTFORM_DIR . 'vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) { wp_die( 'PDF-Bibliothek nicht verfügbar.', '', array( 'response' => 500 ) ); }
		require_once $autoload;
		if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) { wp_die( 'PDF-Bibliothek nicht verfügbar.', '', array( 'response' => 500 ) ); }

		$html = self::html( $items, $grand_fmt, $has_unpriced );

		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );      // nur lokale data:-URIs (Logo/Thumbnails) — keine Remote-Fetches
		$options->set( 'defaultFont', 'DejaVu Sans' );  // € + Umlaute sicher
		$options->set( 'isHtml5ParserEnabled', true );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		nocache_headers();
		$filename = 'MOTORSPORT24-Garage-' . gmdate( 'Y-m-d' ) . '.pdf';
		$dompdf->stream( $filename, array( 'Attachment' => true ) ); // Content-Disposition: attachment
		exit;
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
		return self::file_data_uri( M24_PLATTFORM_DIR . 'assets/img/m24-logo.png', 400 * 1024 );
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

		$logo_html = $logo ? '<img class="logo" src="' . esc_attr( $logo ) . '">' : '<span class="logo-txt">MOTORSPORT24</span>';

		$css = '
			@page { margin: 28px 34px 70px 34px; }
			* { font-family: "DejaVu Sans", sans-serif; }
			body { margin: 0; color: #14161a; font-size: 11px; }
			.head { border-bottom: 3px solid #9a6b25; padding-bottom: 12px; margin-bottom: 16px; }
			.head .logo { height: 30px; }
			.head .logo-txt { font-size: 20px; font-weight: bold; color: #0e447e; }
			.head .meta { color: #0e447e; }
			.h-title { font-size: 19px; font-weight: bold; margin: 10px 0 2px; }
			.h-date { color: #5a6474; font-size: 11px; }
			table.items { width: 100%; border-collapse: collapse; }
			table.items th { text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #5a6474; border-bottom: 1px solid #c9ced4; padding: 0 6px 6px; }
			table.items td { padding: 8px 6px; border-bottom: 1px solid #eef0f2; vertical-align: middle; }
			.c-img { width: 46px; }
			.th { width: 44px; height: 34px; }
			.th-ph { display: inline-block; width: 44px; height: 34px; background: #eef0f2; }
			.tit { font-weight: bold; font-size: 11.5px; }
			.art { color: #8a929c; font-size: 9.5px; margin-top: 2px; }
			.c-unit { white-space: nowrap; }
			.c-qty { text-align: center; width: 44px; }
			.c-line { text-align: right; white-space: nowrap; font-weight: bold; width: 92px; }
			.ask { color: #9a6b25; }
			.empty { text-align: center; color: #5a6474; padding: 22px 0; }
			.sum { margin-top: 14px; padding-top: 10px; border-top: 2px solid #14161a; text-align: right; }
			.sum .lbl { color: #5a6474; font-size: 11px; }
			.sum .val { font-size: 17px; font-weight: bold; margin-left: 14px; }
			.note { color: #8a929c; font-size: 9.5px; text-align: right; margin: 6px 0 0; }
			.foot { position: fixed; bottom: -52px; left: 0; right: 0; border-top: 1px solid #c9ced4; padding-top: 8px; color: #8a929c; font-size: 9px; line-height: 1.5; }
		';

		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
			. '<div class="head">' . $logo_html
			. '<div class="h-title">Meine Garage</div>'
			. '<div class="h-date">Stand: ' . esc_html( $date ) . '</div>'
			. '</div>'
			. '<table class="items"><thead><tr>'
			. '<th></th><th>Position</th><th>Einzelpreis</th><th>Menge</th><th>Summe</th>'
			. '</tr></thead><tbody>' . $rows . '</tbody></table>'
			. '<div class="sum"><span class="lbl">Gesamtsumme</span><span class="val">' . esc_html( $grand_fmt ) . '</span></div>'
			. $note
			. '<div class="foot">MOTORSPORT24 GmbH · Scharfe Lanke 109–131 · 13595 Berlin</div>'
			. '</body></html>';
	}
}
