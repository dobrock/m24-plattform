<?php
/**
 * M24 Zentrales Fehlerprotokoll — sichtbar + abarbeitbar. Alles admin-gated, keine Frontend-Ausgabe.
 *
 * Erfassung: Plugin-Fatals (register_shutdown_function, plugin-scoped) + Plugin-Warnings (set_error_handler),
 * fehlgeschlagene M24-REST-Antworten, wp_mail_failed, sowie explizite capture()-Aufrufe (Brevo/Desk/Magic-
 * Link/Updater). PII-Schutz: E-Mails/Tokens werden maskiert. Auto-Prune (> 90 Tage ODER > 5.000 Zeilen).
 * Optional (Flag m24_error_digest, Default AUS): tägliche Mail mit neuen critical-Fehlern an den Admin.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Error_Log {

	const CRON       = 'm24_error_log_maint';
	const DIGEST_FLAG = 'm24_error_digest';
	const MAX_ROWS   = 5000;
	const MAX_DAYS   = 90;
	const SEVERITIES = array( 'info', 'warning', 'error', 'critical' );

	private static $table = '';

	public static function table(): string {
		if ( '' === self::$table ) { global $wpdb; self::$table = $wpdb->prefix . 'm24_error_log'; }
		return self::$table;
	}

	public static function init() {
		// Plugin-scoped Fatal-/Fehler-Erfassung.
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		set_error_handler( array( __CLASS__, 'on_php_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_handling_set_error_handler
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_mail_failed' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'on_rest_dispatch' ), 10, 3 );

		// Wartung (Prune + optionaler Digest) täglich.
		add_action( self::CRON, array( __CLASS__, 'maintenance' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + 3600, 'daily', self::CRON ); }

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
			add_action( 'admin_post_m24_errlog_action', array( __CLASS__, 'handle_admin_action' ) );
		}
	}

	/* ── Erfassung ──────────────────────────────────────────────────────── */

	/**
	 * @param string $context  z. B. offer_send, brevo_sync, mail, magiclink, desk_push, rest, fatal
	 * @param string $severity info|warning|error|critical
	 */
	public static function capture( string $context, string $severity, string $message, array $meta = array() ): void {
		global $wpdb;
		$severity = in_array( $severity, self::SEVERITIES, true ) ? $severity : 'error';
		$row = array(
			'severity' => $severity,
			'context'  => substr( sanitize_key( $context ), 0, 40 ),
			'message'  => self::mask( (string) $message ),
			'meta'     => wp_json_encode( self::mask_arr( $meta ) ),
			'user_id'  => get_current_user_id(),
			'url'      => self::current_url(),
		);
		// Fehler beim Loggen dürfen NIE die Seite killen.
		try {
			$wpdb->insert( self::table(), $row, array( '%s', '%s', '%s', '%s', '%d', '%s' ) );
		} catch ( \Throwable $e ) { return; }
	}

	/** PII maskieren: E-Mails → a***@domain, lange Hex-/Token-Strings → ersten 6 + …. */
	public static function mask( string $s ): string {
		if ( '' === $s ) { return $s; }
		$s = preg_replace_callback( '/([a-z0-9._%+\-])[a-z0-9._%+\-]*(@[a-z0-9.\-]+\.[a-z]{2,})/i', function ( $m ) {
			return $m[1] . '***' . $m[2];
		}, $s );
		$s = preg_replace_callback( '/\b[a-f0-9]{16,}\b/i', function ( $m ) {
			return substr( $m[0], 0, 6 ) . '…';
		}, $s );
		return (string) $s;
	}
	private static function mask_arr( array $a ): array {
		$out = array();
		foreach ( $a as $k => $v ) {
			if ( is_array( $v ) ) { $out[ $k ] = self::mask_arr( $v ); }
			elseif ( is_string( $v ) ) { $out[ $k ] = self::mask( $v ); }
			else { $out[ $k ] = $v; }
		}
		return $out;
	}
	private static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return substr( esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri ), 0, 255 );
	}
	private static function in_plugin( string $file ): bool {
		return '' !== $file && false !== strpos( wp_normalize_path( $file ), wp_normalize_path( M24_PLATTFORM_DIR ) );
	}

	/* ── Hooks ──────────────────────────────────────────────────────────── */

	public static function on_shutdown() {
		$e = error_get_last();
		if ( ! $e || ! in_array( (int) $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) { return; }
		if ( ! self::in_plugin( (string) ( $e['file'] ?? '' ) ) ) { return; } // nur Plugin-Fatals
		self::capture( 'fatal', 'critical', (string) $e['message'], array( 'file' => basename( (string) $e['file'] ), 'line' => (int) ( $e['line'] ?? 0 ) ) );
	}

	/** Nicht-fatale PHP-Fehler aus Plugin-Dateien als warning; Rückgabe false → normaler PHP-Handler läuft weiter. */
	public static function on_php_error( $errno, $errstr, $errfile = '', $errline = 0 ) {
		if ( self::in_plugin( (string) $errfile ) && ( error_reporting() & $errno ) ) {
			$sev = in_array( (int) $errno, array( E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ? 'error' : 'warning';
			self::capture( 'php', $sev, (string) $errstr, array( 'file' => basename( (string) $errfile ), 'line' => (int) $errline ) );
		}
		return false;
	}

	public static function on_mail_failed( $wp_error ) {
		if ( $wp_error instanceof WP_Error ) {
			$to = '';
			$data = $wp_error->get_error_data();
			if ( is_array( $data ) && isset( $data['to'] ) ) { $to = is_array( $data['to'] ) ? implode( ',', $data['to'] ) : (string) $data['to']; }
			self::capture( 'mail', 'error', $wp_error->get_error_message(), array( 'to' => $to ) );
		}
	}

	/** Fehlgeschlagene M24-REST-Antworten (WP_Error) mitschneiden. */
	public static function on_rest_dispatch( $response, $server, $request ) {
		if ( $request instanceof WP_REST_Request && 0 === strpos( (string) $request->get_route(), '/m24/' ) ) {
			$err = null;
			if ( $response instanceof WP_REST_Response ) {
				$status = (int) $response->get_status();
				if ( $status >= 500 ) { $err = 'HTTP ' . $status; }
			} elseif ( is_wp_error( $response ) ) {
				$err = $response->get_error_message();
			}
			if ( null !== $err ) {
				self::capture( 'rest', 'warning', $err, array( 'route' => $request->get_route(), 'method' => $request->get_method() ) );
			}
		}
		return $response;
	}

	/* ── Wartung: Prune + Digest ────────────────────────────────────────── */

	public static function maintenance() {
		self::prune();
		if ( (bool) (int) get_option( self::DIGEST_FLAG, 0 ) ) { self::send_digest(); }
	}

	public static function prune() {
		global $wpdb;
		$t = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", self::MAX_DAYS ) );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
		if ( $count > self::MAX_ROWS ) {
			$over = $count - self::MAX_ROWS;
			$wpdb->query( $wpdb->prepare( "DELETE FROM $t ORDER BY id ASC LIMIT %d", $over ) );
		}
	}

	private static function send_digest() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT context, message, created_at FROM $t WHERE severity = 'critical' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) ORDER BY id DESC LIMIT 50" );
		if ( empty( $rows ) ) { return; }
		$body = '<p>Neue kritische Fehler der letzten 24 Stunden (' . count( $rows ) . '):</p><ul>';
		foreach ( $rows as $r ) {
			$body .= '<li><strong>' . esc_html( $r->context ) . '</strong> — ' . esc_html( wp_trim_words( (string) $r->message, 24 ) ) . ' <span style="color:#888;">(' . esc_html( $r->created_at ) . ')</span></li>';
		}
		$body .= '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=m24-error-log' ) ) . '">Fehlerprotokoll öffnen</a></p>';
		wp_mail( (string) get_option( 'admin_email' ), 'M24 Fehler-Digest — ' . count( $rows ) . ' kritische Fehler', $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/* ── Admin-UI ───────────────────────────────────────────────────────── */

	public static function admin_menu() {
		add_submenu_page( 'm24-plattform', 'Fehlerprotokoll', 'Fehlerprotokoll', 'manage_options', 'm24-error-log', array( __CLASS__, 'render_page' ) );
	}

	public static function handle_admin_action() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Kein Zugriff.' ); }
		check_admin_referer( 'm24_errlog' );
		global $wpdb; $t = self::table();
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'resolve' === $do ) {
			$id = (int) ( $_POST['id'] ?? 0 );
			if ( $id ) { $wpdb->update( $t, array( 'resolved' => 1 ), array( 'id' => $id ) ); }
		} elseif ( 'unresolve' === $do ) {
			$id = (int) ( $_POST['id'] ?? 0 );
			if ( $id ) { $wpdb->update( $t, array( 'resolved' => 0 ), array( 'id' => $id ) ); }
		} elseif ( 'delete_old' === $do ) {
			self::prune();
			$wpdb->query( "DELETE FROM $t WHERE resolved = 1 AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)" );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=m24-error-log' ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb; $t = self::table();

		$f_sev = isset( $_GET['sev'] ) ? sanitize_key( wp_unslash( $_GET['sev'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$f_ctx = isset( $_GET['ctx'] ) ? substr( sanitize_key( wp_unslash( $_GET['ctx'] ) ), 0, 40 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$f_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$f_to  = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$where = array( '1=1' ); $args = array();
		if ( in_array( $f_sev, self::SEVERITIES, true ) ) { $where[] = 'severity = %s'; $args[] = $f_sev; }
		if ( '' !== $f_ctx ) { $where[] = 'context = %s'; $args[] = $f_ctx; }
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f_from ) ) { $where[] = 'created_at >= %s'; $args[] = $f_from . ' 00:00:00'; }
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f_to ) ) { $where[] = 'created_at <= %s'; $args[] = $f_to . ' 23:59:59'; }
		$wsql  = implode( ' AND ', $where );
		$query = "SELECT * FROM $t WHERE $wsql ORDER BY id DESC LIMIT 300";
		$rows  = $args ? $wpdb->get_results( $wpdb->prepare( $query, $args ) ) : $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ctxs  = $wpdb->get_col( "SELECT DISTINCT context FROM $t ORDER BY context" );
		$colors = array( 'info' => '#5a6474', 'warning' => '#b87000', 'error' => '#c8102e', 'critical' => '#7a0019' );

		echo '<div class="wrap"><h1>Fehlerprotokoll</h1>';
		// Filter.
		echo '<form method="get" style="margin:10px 0;"><input type="hidden" name="page" value="m24-error-log">';
		echo '<select name="sev"><option value="">Alle Severities</option>';
		foreach ( self::SEVERITIES as $s ) { echo '<option value="' . esc_attr( $s ) . '"' . selected( $f_sev, $s, false ) . '>' . esc_html( ucfirst( $s ) ) . '</option>'; }
		echo '</select> ';
		echo '<select name="ctx"><option value="">Alle Kontexte</option>';
		foreach ( (array) $ctxs as $c ) { echo '<option value="' . esc_attr( $c ) . '"' . selected( $f_ctx, $c, false ) . '>' . esc_html( $c ) . '</option>'; }
		echo '</select> ';
		echo 'Von <input type="date" name="from" value="' . esc_attr( $f_from ) . '"> Bis <input type="date" name="to" value="' . esc_attr( $f_to ) . '"> ';
		echo '<button class="button">Filtern</button></form>';
		// „Alte löschen".
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 12px;">';
		wp_nonce_field( 'm24_errlog' );
		echo '<input type="hidden" name="action" value="m24_errlog_action"><input type="hidden" name="do" value="delete_old">';
		echo '<button class="button" onclick="return confirm(\'Alte (> 90 Tage / Überzahl) und erledigte (> 7 Tage) Einträge löschen?\')">Alte löschen</button></form>';

		echo '<table class="widefat striped"><thead><tr><th>Zeit</th><th>Severity</th><th>Kontext</th><th>Nachricht</th><th>URL</th><th></th></tr></thead><tbody>';
		if ( empty( $rows ) ) { echo '<tr><td colspan="6">Keine Einträge.</td></tr>'; }
		foreach ( (array) $rows as $r ) {
			$col  = $colors[ $r->severity ] ?? '#5a6474';
			$meta = json_decode( (string) $r->meta, true );
			echo '<tr' . ( $r->resolved ? ' style="opacity:.55;"' : '' ) . '>';
			echo '<td style="white-space:nowrap;">' . esc_html( $r->created_at ) . '</td>';
			echo '<td><span style="color:#fff;background:' . esc_attr( $col ) . ';border-radius:4px;padding:1px 7px;font-size:11px;">' . esc_html( $r->severity ) . '</span></td>';
			echo '<td><code>' . esc_html( $r->context ) . '</code></td>';
			echo '<td><details><summary>' . esc_html( wp_trim_words( (string) $r->message, 20 ) ) . '</summary>'
				. '<div style="white-space:pre-wrap;font-size:12px;margin-top:6px;">' . esc_html( (string) $r->message ) . '</div>'
				. ( $meta ? '<pre style="font-size:11px;background:#f6f7f7;padding:8px;overflow:auto;max-width:600px;">' . esc_html( wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>' : '' )
				. ( $r->user_id ? '<p style="font-size:11px;color:#888;">user_id: ' . (int) $r->user_id . '</p>' : '' )
				. '</details></td>';
			echo '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:#888;">' . esc_html( (string) $r->url ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'm24_errlog' );
			echo '<input type="hidden" name="action" value="m24_errlog_action"><input type="hidden" name="id" value="' . (int) $r->id . '">';
			echo '<input type="hidden" name="do" value="' . ( $r->resolved ? 'unresolve' : 'resolve' ) . '">';
			echo '<button class="button button-small">' . ( $r->resolved ? '↺' : '✓ erledigt' ) . '</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}
