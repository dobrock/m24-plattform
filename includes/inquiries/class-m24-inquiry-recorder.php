<?php
/**
 * M24 — Rohdaten-Recorder fuer eingehende Teile-/Sammelanfragen.
 * Modul: includes/inquiries/class-m24-inquiry-recorder.php
 *
 * Zweck (Diagnose, KEINE Verarbeitung): entscheidbar machen, ob bei einer Anfrage
 * ohne Positionen der Client nichts geschickt hat oder der Server nicht geparst hat.
 * Haengt sich rein beobachtend an POST /wp-json/m24-plattform/v1/inquiry
 * (rest_pre_dispatch / rest_post_dispatch) — die Verarbeitungslogik bleibt unberuehrt.
 *
 * Ablage: Option m24_inquiry_raw_log (autoload = no), Ringpuffer 50 Eintraege.
 * DSGVO: Eintraege aelter als 14 Tage fallen bei jedem Schreibvorgang raus (kein Cron).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Inquiry_Recorder {

	const OPTION   = 'm24_inquiry_raw_log';
	const MAX_ROWS = 50;
	const MAX_DAYS = 14;
	const BODY_MAX = 20480;                        // 20 KB
	const ROUTE    = '/m24-plattform/v1/inquiry';

	/** @var array|null Snapshot des laufenden Requests, bis das Ergebnis feststeht. */
	private static $pending = null;
	/** @var bool Schutz gegen Doppelschreiben (post_dispatch + shutdown). */
	private static $flushed = false;

	public static function init() {
		add_filter( 'rest_pre_dispatch',  array( __CLASS__, 'on_pre_dispatch' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'on_post_dispatch' ), 10, 3 );
		// Teil 3: Marker fuer den Fallback — gilt fuer JEDEN Anlagepfad, nicht nur /inquiry.
		add_action( 'm24_inquiry_created', array( __CLASS__, 'on_inquiry_created' ), 99, 1 );
	}

	/* ── Erfassung ──────────────────────────────────────────────────────── */

	/** Beobachtet nur: Snapshot anlegen, $result unveraendert zurueckgeben. */
	public static function on_pre_dispatch( $result, $server, $request ) {
		if ( $request instanceof WP_REST_Request
			&& 'POST' === strtoupper( (string) $request->get_method() )
			&& self::ROUTE === (string) $request->get_route() ) {
			self::$pending = self::snapshot( $request );
			self::$flushed = false;
			register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );
		}
		return $result;
	}

	/** Ergebnis aus der Antwort ableiten und den Eintrag schreiben. */
	public static function on_post_dispatch( $response, $server, $request ) {
		if ( null === self::$pending || self::$flushed
			|| ! ( $request instanceof WP_REST_Request )
			|| self::ROUTE !== (string) $request->get_route() ) {
			return $response;
		}
		self::$pending['result'] = self::result_label( $response );
		self::flush();
		return $response;
	}

	/**
	 * Fatal/Exception im Handler: rest_post_dispatch laeuft dann nicht mehr.
	 * Der Eintrag darf trotzdem nicht verloren gehen — genau dieser Fall ist die
	 * dritte Antwortmoeglichkeit auf „Client oder Server?".
	 */
	public static function on_shutdown() {
		if ( null === self::$pending || self::$flushed ) { return; }
		$last  = error_get_last();
		$fatal = is_array( $last ) && in_array( (int) $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true );
		self::$pending['result'] = 'exception';
		self::$pending['note']   = $fatal ? mb_substr( (string) $last['message'], 0, 300 ) : 'Antwort nicht abgeschlossen';
		self::flush();
	}

	/**
	 * Teil 3: nach dem Anlegen die TATSAECHLICH im Postmeta stehenden Positionen
	 * zaehlen (dieselbe Stelle, die schreibt) und bei 0 den Marker setzen.
	 */
	public static function on_inquiry_created( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) { return; }

		$items = get_post_meta( $post_id, '_m24_items', true );
		$count = is_array( $items ) ? count( $items ) : 0;

		if ( null !== self::$pending ) {
			self::$pending['post_id'] = $post_id;
			self::$pending['items']   = $count;
		}

		if ( 0 === $count ) {
			update_post_meta( $post_id, '_m24_items_missing', 1 );
			update_post_meta( $post_id, '_m24_items_missing_host', self::server( 'HTTP_HOST' ) );
		}
	}

	/* ── Snapshot ───────────────────────────────────────────────────────── */

	private static function snapshot( WP_REST_Request $request ): array {
		$body = (string) $request->get_body();
		return array(
			'ts'           => time(),
			'ts_utc'       => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'ts_berlin'    => self::berlin_now(),
			'body'         => mb_strcut( $body, 0, self::BODY_MAX ),
			'body_len'     => strlen( $body ),
			'body_cut'     => strlen( $body ) > self::BODY_MAX ? 1 : 0,
			'content_type' => (string) $request->get_header( 'content_type' ),
			'referer'      => self::server( 'HTTP_REFERER' ),
			'host'         => self::server( 'HTTP_HOST' ),
			'user_agent'   => self::server( 'HTTP_USER_AGENT' ),
			'lang_cookies' => self::lang_cookies(),
			'user_id'      => get_current_user_id(),
			'items'        => null,   // null = kein Insert erreicht
			'post_id'      => 0,
			'result'       => '',
			'note'         => '',
		);
	}

	/** Berliner Zeit inkl. Zeitzonenkuerzel (CET/CEST) — unabhaengig von der WP-Zeitzone. */
	private static function berlin_now(): string {
		try {
			return ( new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Berlin' ) ) )->format( 'Y-m-d H:i:s T' );
		} catch ( \Throwable $e ) {
			return gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		}
	}

	/** GTranslate-Sprachstate: alle Cookies, deren Name mit googtrans oder gt_ beginnt. */
	private static function lang_cookies(): array {
		$out = array();
		foreach ( (array) $_COOKIE as $name => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
			$name = (string) $name;
			if ( 0 !== strpos( $name, 'googtrans' ) && 0 !== strpos( $name, 'gt_' ) ) { continue; }
			$out[ $name ] = mb_substr( (string) wp_unslash( $value ), 0, 200 );
		}
		return $out;
	}

	private static function server( string $key ): string {
		return isset( $_SERVER[ $key ] ) ? mb_substr( (string) wp_unslash( $_SERVER[ $key ] ), 0, 500 ) : '';
	}

	/** ok | validation_error <code> | exception (Letzteres kommt aus on_shutdown). */
	private static function result_label( $response ): string {
		$status = ( $response instanceof WP_REST_Response ) ? (int) $response->get_status() : 200;
		$data   = ( $response instanceof WP_REST_Response ) ? $response->get_data() : null;
		$code   = ( is_array( $data ) && isset( $data['code'] ) ) ? (string) $data['code'] : '';
		$failed = $status >= 400 || ( is_array( $data ) && isset( $data['ok'] ) && ! $data['ok'] );
		if ( ! $failed ) { return 'ok'; }
		return 'validation_error ' . ( '' !== $code ? $code : (string) $status );
	}

	/* ── Ringpuffer ─────────────────────────────────────────────────────── */

	private static function flush(): void {
		self::$flushed = true;
		$entry         = self::$pending;
		self::$pending = null;
		if ( ! is_array( $entry ) ) { return; }

		$log = self::entries_raw();
		$log[] = $entry;

		// DSGVO-Prune bei jedem Schreibvorgang (kein Cron): alles aelter als 14 Tage raus.
		$cut = time() - ( self::MAX_DAYS * DAY_IN_SECONDS );
		$log = array_values( array_filter( $log, static function ( $e ) use ( $cut ) {
			return is_array( $e ) && isset( $e['ts'] ) && (int) $e['ts'] >= $cut;
		} ) );
		if ( count( $log ) > self::MAX_ROWS ) {
			$log = array_slice( $log, -self::MAX_ROWS );
		}
		update_option( self::OPTION, $log, false );
	}

	private static function entries_raw(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Neueste zuerst — fuer die Admin-Ansicht. */
	public static function entries(): array {
		return array_reverse( self::entries_raw() );
	}

	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
