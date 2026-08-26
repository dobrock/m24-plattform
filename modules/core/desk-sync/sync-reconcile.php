<?php
/**
 * M24 Plattform — Bidirektionale LWW-Sync: Reconcile-Pull WP ← Desk (Spec v1.3 §5.2).
 *
 * Holt periodisch alles, was sich im Desk seit dem letzten Lauf geändert hat, und gibt es an
 * M24_Sync_Apply. Der Event-Push (Desk→WP-Webhook) ist der schnelle Weg, dieser Pull der verlässliche:
 * ein verpasster Webhook — Deploy, Netzausfall, WP kurz nicht erreichbar — würde sonst nie nachgeholt.
 *
 *   GET /api/sync/changes?entity=<orders|offer_lines|customers>&updated_since=<ISO8601-UTC>&limit=&cursor=
 *
 * Der Wasserstand (`last_reconcile_at` je Entität) wird ERST nach erfolgreichem Apply fortgeschrieben.
 * Bricht ein Lauf ab, holt der nächste dieselbe Spanne noch einmal — der Apply ist idempotent, doppelt
 * Geholtes ist also harmlos, während ein zu früh gesetzter Wasserstand Änderungen dauerhaft verlöre.
 *
 * Overlap: der Pull setzt bewusst ein paar Sekunden vor dem letzten Stand an. Zwei Uhren laufen nie
 * exakt gleich, und ein Datensatz, der genau auf der Grenze liegt, fiele sonst zwischen zwei Läufe.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Sync_Reconcile {

	const ENDPOINT   = '/api/sync/changes';
	const CRON       = 'm24_sync_reconcile';
	const OPT_PREFIX = 'm24_sync_last_reconcile_';
	const TIMEOUT    = 20;
	const PAGE       = 200;
	const MAX_PAGES  = 25;   // Sicherheitsnetz gegen einen Cursor, der nie endet
	const OVERLAP    = 120;  // Sekunden Rückgriff gegen Uhren-Drift
	const ENTITIES   = array( 'orders', 'offer_lines', 'customers' );

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'run_cron' ) );
		if ( ! wp_next_scheduled( self::CRON ) && self::enabled() ) {
			wp_schedule_event( time() + 600, 'm24_10min', self::CRON );
		}
	}

	/** 10-Minuten-Takt (Spec §5.2), per Filter anpassbar. */
	public static function add_schedule( $s ) {
		if ( ! isset( $s['m24_10min'] ) ) {
			$iv = (int) apply_filters( 'm24_sync_reconcile_interval', 10 * MINUTE_IN_SECONDS );
			$s['m24_10min'] = array( 'interval' => max( 60, $iv ), 'display' => 'Alle 10 Minuten (M24 Sync)' );
		}
		return $s;
	}

	public static function enabled(): bool {
		return class_exists( 'M24_Desk_Push' ) && M24_Desk_Push::enabled()
			&& class_exists( 'M24_Rest_Client' ) && M24_Rest_Client::is_configured();
	}

	/* ── Wasserstand ──────────────────────────────────────────────────────── */

	public static function last_reconcile_at( string $entity ): string {
		return (string) get_option( self::OPT_PREFIX . $entity, '' );
	}

	private static function set_last_reconcile_at( string $entity, string $utc ): void {
		update_option( self::OPT_PREFIX . $entity, $utc, false );
	}

	/**
	 * Ab wann geholt wird. Beim allerersten Lauf 30 Tage zurück — nicht „seit Anbeginn": das zöge die
	 * komplette Desk-Historie durch den Applier, ohne dass ältere Datensätze noch relevant wären.
	 */
	private static function since_for( string $entity ): string {
		$last = self::last_reconcile_at( $entity );
		if ( '' === $last ) { return gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 * DAY_IN_SECONDS ); }
		$ms = M24_Sync_LWW::to_ms( $last );
		$ts = ( null === $ms ? time() : (int) ( $ms / 1000 ) ) - self::OVERLAP;
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/* ── Pull ─────────────────────────────────────────────────────────────── */

	/** Alle Entitäten. Reihenfolge ist Absicht: Kopf vor Positionen, damit die Zeile schon existiert. */
	public static function pull_all(): array {
		$out = array();
		foreach ( self::ENTITIES as $e ) { $out[ $e ] = self::pull( $e ); }
		return $out;
	}

	/**
	 * Eine Entität holen und anwenden, seitenweise.
	 *
	 * @return array{ok:bool,fetched:int,applied:int,note:string}
	 */
	public static function pull( string $entity, ?string $since = null ): array {
		if ( ! in_array( $entity, self::ENTITIES, true ) ) {
			return array( 'ok' => false, 'fetched' => 0, 'applied' => 0, 'note' => 'unknown_entity' );
		}
		if ( ! self::enabled() ) {
			return array( 'ok' => false, 'fetched' => 0, 'applied' => 0, 'note' => 'Sync nicht scharf oder Desk nicht konfiguriert.' );
		}

		$since   = $since ?? self::since_for( $entity );
		$started = gmdate( 'Y-m-d\TH:i:s\Z' ); // Wasserstand = Startzeitpunkt, nicht Endzeit: was während
		                                       // des Laufs drüben passiert, muss der nächste noch sehen.
		$cursor  = '';
		$fetched = 0;
		$applied = 0;

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$q = array( 'entity' => $entity, 'updated_since' => $since, 'limit' => self::PAGE );
			if ( '' !== $cursor ) { $q['cursor'] = $cursor; }
			$path = self::ENDPOINT . '?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 );

			$res    = M24_Rest_Client::request( 'GET', $path, null, array( 'timeout' => self::TIMEOUT ) );
			$status = (int) ( $res['status'] ?? 0 );
			if ( empty( $res['ok'] ) ) {
				// 401/403 = Scope fehlt (orders:read), 404 = Vertrag noch nicht deployt. Beides sauber
				// loggen und den Wasserstand NICHT fortschreiben — sonst gilt die Spanne als erledigt.
				$note = self::error_note( $status, $res );
				self::log( 'pull_failed', $entity . ' → ' . $note );
				return array( 'ok' => false, 'fetched' => $fetched, 'applied' => $applied, 'note' => $note );
			}

			$data    = is_array( $res['data'] ?? null ) ? $res['data'] : array();
			$records = (array) ( $data['records'] ?? array() );
			$fetched += count( $records );

			if ( ! empty( $records ) ) {
				$r = M24_Sync_Apply::records( $entity, $records );
				foreach ( (array) ( $r['results'] ?? array() ) as $one ) {
					if ( ! empty( $one['applied'] ) ) { $applied++; }
				}
			}

			$cursor = trim( (string) ( $data['cursor'] ?? $data['next_cursor'] ?? '' ) );
			if ( '' === $cursor || count( $records ) < self::PAGE ) { break; }
		}

		self::set_last_reconcile_at( $entity, $started );
		self::log( 'pull', $entity . ': ' . $fetched . ' geholt, ' . $applied . ' angewandt (seit ' . $since . ').' );
		return array( 'ok' => true, 'fetched' => $fetched, 'applied' => $applied, 'note' => 'ok' );
	}

	/** Cron-Lauf. */
	public static function run_cron(): void {
		if ( ! self::enabled() ) { return; }
		$r = self::pull_all();
		$sum = 0;
		foreach ( $r as $one ) { $sum += (int) ( $one['applied'] ?? 0 ); }
		if ( $sum > 0 ) { self::log( 'cron', $sum . ' Änderung(en) aus dem Desk übernommen.' ); }
	}

	/** Kurzfassung für Admin-Meldungen: „orders 3/12 · offer_lines 0/0 · customers 1/1". */
	public static function summary( array $result ): string {
		$parts = array();
		foreach ( $result as $entity => $r ) {
			$parts[] = empty( $r['ok'] )
				? $entity . ' ✗ (' . (string) ( $r['note'] ?? 'Fehler' ) . ')'
				: $entity . ' ' . (int) $r['applied'] . '/' . (int) $r['fetched'];
		}
		return implode( ' · ', $parts );
	}

	private static function error_note( int $status, array $res ): string {
		$data = is_array( $res['data'] ?? null ) ? $res['data'] : array();
		if ( 401 === $status || 403 === $status ) {
			$needed = (string) ( $data['needed'] ?? 'orders:read' );
			return 'HTTP ' . $status . ' — Token fehlt der Scope ' . $needed;
		}
		if ( 404 === $status ) { return 'HTTP 404 — /api/sync/changes ist Desk-seitig noch nicht deployt'; }
		return 'HTTP ' . $status . ' · ' . (string) ( $res['error'] ?? 'unbekannt' );
	}

	private static function log( string $step, string $msg ): void {
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'sync_reconcile', $step, array( 'msg' => $msg ) );
		}
	}
}
