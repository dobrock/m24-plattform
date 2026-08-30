<?php
/**
 * M24 — Admin-Seite „Anfrage-Diagnose" (MOTORSPORT24 → System).
 * Modul: admin/class-m24-inquiry-diagnose.php
 *
 * Zeigt den Rohdaten-Ringpuffer aus M24_Inquiry_Recorder (letzte 50 Requests auf
 * POST /m24-plattform/v1/inquiry) und laesst Postmeta zweier Anfragen vergleichen
 * (M24_Inquiry_Diagnose_Meta). Read-only bis auf „Protokoll jetzt leeren".
 * Bestaetigungen als In-Page-Muster — kein confirm()/alert().
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Inquiry_Diagnose {

	const SLUG = 'm24-anfrage-diagnose';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
		add_action( 'admin_post_m24_inqdiag_clear', array( __CLASS__, 'handle_clear' ) );
	}

	public static function admin_menu() {
		add_submenu_page( 'm24-plattform', 'Anfrage-Diagnose', 'Anfrage-Diagnose', 'manage_options', self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Kein Zugriff.' ); }
		check_admin_referer( 'm24_inqdiag_clear' );
		if ( class_exists( 'M24_Inquiry_Recorder' ) ) { M24_Inquiry_Recorder::clear(); }
		wp_safe_redirect( self::url( array( 'cleared' => 1 ) ) );
		exit;
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$confirm = isset( $_GET['confirm'] ) ? sanitize_key( wp_unslash( $_GET['confirm'] ) ) : '';
		$cleared = ! empty( $_GET['cleared'] );
		$id_a    = isset( $_GET['inq'] ) ? absint( wp_unslash( $_GET['inq'] ) ) : 0;
		$id_b    = isset( $_GET['cmp'] ) ? absint( wp_unslash( $_GET['cmp'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$entries = class_exists( 'M24_Inquiry_Recorder' ) ? M24_Inquiry_Recorder::entries() : array();

		echo '<div class="wrap"><h1>Anfrage-Diagnose</h1>';
		echo '<p style="max-width:820px;color:#646970;">Rohdaten der eingehenden Anfrage-Requests. Damit ist entscheidbar, ob eine Anfrage ohne Positionen '
			. 'ankam (Client) oder ob der Server sie nicht geparst hat. Aufbewahrung 14 Tage, maximal 50 Eintraege.</p>';

		if ( $cleared ) {
			echo '<div class="notice notice-success is-dismissible"><p>Protokoll geleert.</p></div>';
		}

		self::render_clear_control( $confirm, count( $entries ) );
		self::render_log( $entries );
		self::render_meta_tools( $id_a, $id_b );

		echo '</div>';
	}

	/* ── Protokoll leeren (In-Page-Bestaetigung, kein confirm()) ────────── */

	private static function render_clear_control( string $confirm, int $count ): void {
		if ( 'clear' !== $confirm ) {
			echo '<p><a class="button" href="' . esc_url( self::url( array( 'confirm' => 'clear' ) ) ) . '">Protokoll jetzt leeren</a></p>';
			return;
		}
		echo '<div class="notice notice-warning" style="padding:12px;">'
			. '<p><strong>Protokoll leeren?</strong> ' . (int) $count . ' Eintraege werden unwiderruflich verworfen.</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
		wp_nonce_field( 'm24_inqdiag_clear' );
		echo '<input type="hidden" name="action" value="m24_inqdiag_clear">'
			. '<button class="button button-primary">Ja, leeren</button> '
			. '<a class="button" href="' . esc_url( self::url() ) . '">Abbrechen</a>'
			. '</form></div>';
	}

	/* ── a) Rohdaten-Tabelle ────────────────────────────────────────────── */

	private static function render_log( array $entries ): void {
		echo '<h2>Rohdaten-Protokoll (' . (int) count( $entries ) . ')</h2>';
		echo '<table class="widefat striped"><thead><tr>'
			. '<th style="width:150px;">Zeit (Berlin)</th><th>Host</th><th>Referrer</th><th>Sprach-Cookie</th>'
			. '<th style="width:70px;">Pos.</th><th style="width:80px;">Post-ID</th><th style="width:200px;">Ergebnis</th>'
			. '</tr></thead><tbody>';

		if ( empty( $entries ) ) {
			echo '<tr><td colspan="7"><em>Noch keine Requests erfasst.</em></td></tr>';
		}

		foreach ( $entries as $i => $e ) {
			if ( ! is_array( $e ) ) { continue; }
			$items = array_key_exists( 'items', $e ) ? $e['items'] : null;
			$ref   = (string) ( $e['referer'] ?? '' );
			$lang  = array();
			foreach ( (array) ( $e['lang_cookies'] ?? array() ) as $k => $v ) { $lang[] = $k . '=' . $v; }

			echo '<tr>';
			echo '<td style="white-space:nowrap;">' . esc_html( (string) ( $e['ts_berlin'] ?? '' ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) ( $e['host'] ?? '' ) ) . '</code></td>';
			echo '<td style="font-size:12px;word-break:break-all;" title="' . esc_attr( $ref ) . '">'
				. esc_html( '' === $ref ? '—' : self::shorten( $ref, 70 ) ) . '</td>';
			echo '<td style="font-size:12px;word-break:break-all;">' . esc_html( empty( $lang ) ? '—' : self::shorten( implode( ' · ', $lang ), 60 ) ) . '</td>';
			echo '<td>' . self::items_cell( $items ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . ( ! empty( $e['post_id'] ) ? (int) $e['post_id'] : '—' ) . '</td>';
			echo '<td>' . self::result_cell( (string) ( $e['result'] ?? '' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';

			// Aufklappbare Detailzeile: Metadaten + roher Body.
			echo '<tr><td colspan="7" style="background:#fbfbfb;">';
			echo '<details><summary style="cursor:pointer;">Rohdaten anzeigen (' . (int) ( $e['body_len'] ?? 0 ) . ' Bytes)</summary>';
			echo '<p style="font-size:12px;color:#646970;margin:8px 0 4px;">'
				. 'UTC: <code>' . esc_html( (string) ( $e['ts_utc'] ?? '' ) ) . '</code> &middot; '
				. 'Content-Type: <code>' . esc_html( (string) ( $e['content_type'] ?? '' ) ) . '</code> &middot; '
				. 'User-ID: <code>' . (int) ( $e['user_id'] ?? 0 ) . '</code>'
				. ( ! empty( $e['body_cut'] ) ? ' &middot; <strong>Body bei 20 KB gekappt</strong>' : '' )
				. ( '' !== (string) ( $e['note'] ?? '' ) ? ' &middot; Hinweis: <code>' . esc_html( (string) $e['note'] ) . '</code>' : '' )
				. '</p>';
			echo '<p style="font-size:12px;color:#646970;margin:0 0 4px;">User-Agent: <code>' . esc_html( (string) ( $e['user_agent'] ?? '' ) ) . '</code></p>';
			echo '<pre style="white-space:pre-wrap;word-break:break-all;background:#fff;border:1px solid #dcdcde;padding:10px;max-height:340px;overflow:auto;font-size:12px;">'
				. esc_html( (string) ( $e['body'] ?? '' ) ) . '</pre>';
			echo '</details></td></tr>';
			unset( $i );
		}
		echo '</tbody></table>';
	}

	private static function items_cell( $items ): string {
		if ( null === $items ) { return '<span style="color:#8a8a8a;" title="Kein Insert erreicht">—</span>'; }
		$n = (int) $items;
		return 0 === $n
			? '<strong style="color:#b32d2e;">0</strong>'
			: '<strong>' . $n . '</strong>';
	}

	private static function result_cell( string $result ): string {
		if ( '' === $result ) { return '—'; }
		$color = 'ok' === $result ? '#00753a' : ( 'exception' === $result ? '#7a0019' : '#b87000' );
		return '<span style="color:#fff;background:' . esc_attr( $color ) . ';border-radius:4px;padding:1px 7px;font-size:11px;word-break:break-all;">'
			. esc_html( $result ) . '</span>';
	}

	private static function shorten( string $s, int $max ): string {
		return mb_strlen( $s ) > $max ? mb_substr( $s, 0, $max ) . '…' : $s;
	}

	/* ── b) + c) Postmeta-Liste und Vergleich ───────────────────────────── */

	private static function render_meta_tools( int $id_a, int $id_b ): void {
		echo '<h2 style="margin-top:28px;">Postmeta einer Anfrage</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin:0 0 14px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="m24-inq">Anfrage-ID</label> '
			. '<input type="number" min="0" id="m24-inq" name="inq" value="' . ( $id_a > 0 ? (int) $id_a : '' ) . '" style="width:120px;"> ';
		echo '<label for="m24-cmp">Vergleichs-ID</label> '
			. '<input type="number" min="0" id="m24-cmp" name="cmp" value="' . ( $id_b > 0 ? (int) $id_b : '' ) . '" style="width:120px;"> ';
		echo '<button class="button button-primary">Anzeigen</button></form>';

		if ( ! class_exists( 'M24_Inquiry_Diagnose_Meta' ) ) {
			echo '<p><em>Vergleichsmodul nicht geladen.</em></p>';
			return;
		}
		if ( $id_a > 0 && $id_b > 0 ) {
			M24_Inquiry_Diagnose_Meta::render_diff( $id_a, $id_b );
			return;
		}
		if ( $id_a > 0 ) {
			M24_Inquiry_Diagnose_Meta::render_single( $id_a );
			return;
		}
		echo '<p style="color:#646970;"><em>Anfrage-ID eingeben. Mit zusaetzlicher Vergleichs-ID erscheinen beide Key-Listen nebeneinander; '
			. 'Keys, die nur auf einer Seite existieren, sind markiert.</em></p>';
	}
}
