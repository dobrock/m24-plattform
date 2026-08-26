<?php
/**
 * M24 Plattform — Bidirektionale LWW-Sync: Push-Seite WP → Desk (Spec v1.3 §5.1/§6).
 *
 * Schickt lokale Änderungen an POST /api/sync/apply. Der Desk wendet sie nach derselben Konfliktregel an
 * und antwortet je Record mit applied/rev — daraufhin verbucht WP last_synced_rev/last_synced_at.
 *
 * Die Warteschlange ist keine eigene Tabelle, sondern die Buchhaltung selbst: `rev > last_synced_rev`
 * heißt „lokal geändert, drüben noch nicht bekannt". Das kann nicht mit dem echten Zustand
 * auseinanderlaufen, wie es eine separate Queue könnte, und übersteht jeden Absturz.
 *
 * Zwei Auslöser:
 *   - sofort: M24_Sync_LWW::touch() feuert m24_sync_touched → Einzel-Event (entkoppelt, blockiert keinen Klick)
 *   - periodisch: Cron holt nach, was der Einzel-Event nicht geschafft hat (Desk down, Timeout, WP-Cron aus)
 *
 * Echo-Schutz (§6): läuft gerade ein Apply, wird NICHT gepusht — sonst liefe die eben eingespielte
 * Desk-Änderung sofort wieder zurück.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Sync_Push {

	const ENDPOINT    = '/api/sync/apply';
	const CRON        = 'm24_sync_push_pending';
	const EVENT       = 'm24_sync_push_offer';
	const TIMEOUT     = 15;
	const BATCH       = 25;  // Angebote je Cron-Lauf — hält den Request kurz
	const MAX_RECORDS = 200; // Records je HTTP-Call

	public static function init() {
		// Sofort-Push nach jeder lokalen Änderung (M24_Sync_LWW::touch feuert das).
		add_action( 'm24_sync_touched', array( __CLASS__, 'on_touched' ), 10, 2 );
		add_action( self::EVENT, array( __CLASS__, 'push_offer' ), 10, 1 );

		// Nachzügler: alles, was rev > last_synced_rev hat.
		add_action( self::CRON, array( __CLASS__, 'run_pending' ) );
		if ( ! wp_next_scheduled( self::CRON ) && self::enabled() ) {
			wp_schedule_event( time() + 300, 'm24_10min', self::CRON );
		}
	}

	/** Sync scharf? Nutzt denselben Schalter wie der W-Kanal — ein Sync-Modell, ein Schalter. */
	public static function enabled(): bool {
		return class_exists( 'M24_Desk_Push' ) && M24_Desk_Push::enabled()
			&& class_exists( 'M24_Rest_Client' ) && M24_Rest_Client::is_configured();
	}

	/** Läuft gerade ein Apply? Dann ist die Änderung fremd und darf nicht zurücklaufen (§6). */
	private static function applying(): bool {
		return ( class_exists( 'M24_Sync_Apply' ) && M24_Sync_Apply::$applying )
			|| ( class_exists( 'M24_Desk_Inbound' ) && M24_Desk_Inbound::$applying );
	}

	/* ── Auslöser ─────────────────────────────────────────────────────────── */

	public static function on_touched( $offer_id, $origin ): void {
		if ( 'wp' !== (string) $origin || self::applying() || ! self::enabled() ) { return; }
		$offer_id = (int) $offer_id;
		if ( $offer_id <= 0 ) { return; }
		// Entkoppelt einplanen: ein hängender Desk-Call darf keinen Speichern-Klick blockieren.
		// wp_next_scheduled dedupliziert über die Args — mehrere Edits kurz hintereinander = ein Push.
		if ( ! wp_next_scheduled( self::EVENT, array( $offer_id ) ) ) {
			wp_schedule_single_event( time() + 5, self::EVENT, array( $offer_id ) );
		}
	}

	/** Cron: alles nachholen, was noch nicht drüben ist. */
	public static function run_pending(): void {
		global $wpdb;
		if ( ! self::enabled() ) { return; }
		$t   = M24_Offers::table();
		$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id FROM $t WHERE rev > last_synced_rev AND wp_offer_uid <> '' ORDER BY updated_at ASC LIMIT %d",
			self::BATCH
		) );
		foreach ( (array) $ids as $id ) { self::push_offer( (int) $id ); }
		if ( ! empty( $ids ) ) { self::log( 'cron', count( $ids ) . ' Angebot(e) nachgepusht.' ); }
	}

	/* ── Push ─────────────────────────────────────────────────────────────── */

	/**
	 * Ein Angebot pushen: Kopf, Positionen (aktive + Tombstones) und den Kunden.
	 * Erst wenn der Kopf angenommen wurde, wird last_synced_* gesetzt.
	 */
	public static function push_offer( $offer_id ): void {
		$offer_id = (int) $offer_id;
		if ( $offer_id <= 0 || ! self::enabled() || self::applying() ) { return; }

		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! $o || '' === (string) $o->wp_offer_uid ) { return; }
		if ( ! M24_Sync_LWW::needs_push( $o ) ) { return; } // schon drüben — nichts zu tun

		$head = self::send( 'orders', array( self::order_record( $o ) ) );
		if ( empty( $head['ok'] ) ) {
			self::log( 'push_failed', (string) $o->offer_no . ' · orders → ' . (string) ( $head['note'] ?? '' ) );
			return; // last_synced_* NICHT setzen → der Cron versucht es erneut
		}

		$lines = self::line_records( $o );
		if ( ! empty( $lines ) ) {
			$res = self::send( 'offer_lines', $lines );
			if ( empty( $res['ok'] ) ) {
				self::log( 'push_failed', (string) $o->offer_no . ' · offer_lines → ' . (string) ( $res['note'] ?? '' ) );
				return;
			}
		}

		$cust = self::customer_record( $o );
		if ( ! empty( $cust ) ) { self::send( 'customers', array( $cust ) ); } // nicht blockierend: Kopf ist durch

		M24_Sync_LWW::mark_synced( $offer_id, (int) $o->rev );
		self::log( 'pushed', (string) $o->offer_no . ' (rev ' . (int) $o->rev . ', ' . count( $lines ) . ' Positionen)' );
	}

	/** Kopf-Record fürs Wire-Format. Feldnamen wie in M24_Sync_Apply::ORDER_FIELDS (beide Seiten gleich). */
	public static function order_record( $o ): array {
		$rec = M24_Sync_LWW::envelope( $o );
		$rec['offer_no']      = (string) $o->offer_no;
		$rec['desk_order_id'] = (string) $o->desk_order_id;
		$rec['status']        = (string) $o->status;
		$rec['delivery_time'] = (string) $o->delivery_time;
		$rec['currency']      = (string) $o->currency;
		$rec['subtotal_net']  = (float) $o->subtotal_net;
		$rec['tax_amount']    = (float) $o->tax_amount;
		$rec['total_gross']   = (float) $o->total_gross;
		$rec['supersedes']    = (string) $o->supersedes;
		$rec['superseded_by'] = (string) $o->superseded_by;
		foreach ( array( 'bill_firma', 'bill_anrede', 'bill_vorname', 'bill_nachname', 'bill_strasse', 'bill_plz', 'bill_ort', 'bill_land', 'bill_ustid', 'bill_telefon',
			'ship_firma', 'ship_strasse', 'ship_strasse2', 'ship_plz', 'ship_ort', 'ship_land', 'carrier', 'tracking' ) as $col ) {
			$rec[ $col ] = (string) ( $o->$col ?? '' );
		}
		$rec['payment_date'] = ! empty( $o->payment_date ) ? M24_Sync_LWW::to_iso( (string) $o->payment_date ) : null;
		return $rec;
	}

	/**
	 * Positions-Records: aktive Zeilen plus Tombstones. Die Tombstones MÜSSEN mit — sonst erführe der Desk
	 * nie, dass eine Zeile entfernt wurde, und hielte sie beim nächsten Reconcile für neu.
	 */
	public static function line_records( $o ): array {
		$out   = array();
		$uid   = (string) $o->wp_offer_uid;
		$items = json_decode( (string) $o->items_json, true );
		foreach ( (array) ( is_array( $items ) ? $items : array() ) as $it ) {
			if ( ! is_array( $it ) || empty( $it['line_uid'] ) ) { continue; }
			$out[] = array(
				'wp_offer_uid' => $uid,
				'line_uid'     => (string) $it['line_uid'],
				'teil_id'      => (int) ( $it['teil_id'] ?? 0 ),
				'title'        => (string) ( $it['title'] ?? '' ),
				'title_de'     => (string) ( $it['title_de'] ?? '' ),
				'title_en'     => (string) ( $it['title_en'] ?? '' ),
				'art_nr'       => (string) ( $it['art_nr'] ?? '' ),
				'variant'      => (string) ( $it['variant'] ?? '' ),
				'qty'          => max( 1, (int) ( $it['qty'] ?? 1 ) ),
				'unit_price'   => round( (float) ( $it['unit_price'] ?? 0 ), 2 ),
				'tax25a'       => ! empty( $it['tax25a'] ),
				'custom'       => ! empty( $it['custom'] ),
				'updated_at'   => M24_Sync_LWW::to_iso( (string) ( $it['updated_at'] ?? '' ) ),
				'origin'       => (string) ( $it['origin'] ?? 'wp' ),
				'rev'          => (int) ( $it['rev'] ?? 1 ),
				'deleted_at'   => null,
			);
		}
		foreach ( M24_Sync_LWW::tombstones( $o ) as $t ) {
			if ( empty( $t['line_uid'] ) ) { continue; }
			$out[] = array(
				'wp_offer_uid' => $uid,
				'line_uid'     => (string) $t['line_uid'],
				'updated_at'   => M24_Sync_LWW::to_iso( (string) ( $t['deleted_at'] ?? '' ) ),
				'origin'       => (string) ( $t['origin'] ?? 'wp' ),
				'rev'          => (int) ( $t['rev'] ?? 1 ),
				'deleted_at'   => M24_Sync_LWW::to_iso( (string) ( $t['deleted_at'] ?? '' ) ),
			);
		}
		return $out;
	}

	/** Kunden-Record aus dem Angebots-Snapshot (+ Konto, falls vorhanden). */
	public static function customer_record( $o ): array {
		$cuid = (string) $o->customer_uid;
		if ( '' === $cuid ) { return array(); }
		$c = json_decode( (string) $o->customer_json, true );
		$c = is_array( $c ) ? $c : array();

		$rec = array(
			'customer_uid' => $cuid,
			'email'        => (string) ( $c['email'] ?? '' ),
			'firma'        => (string) ( $c['firma'] ?? $c['firmenname'] ?? '' ),
			'name'         => (string) ( $c['name'] ?? '' ),
			'strasse'      => (string) ( $c['strasse'] ?? '' ),
			'plz'          => (string) ( $c['plz'] ?? '' ),
			'ort'          => (string) ( $c['ort'] ?? '' ),
			'land'         => (string) ( $c['land'] ?? '' ),
			'tel'          => (string) ( $c['telefon'] ?? '' ),
			'uid'          => (string) ( $c['ustid'] ?? '' ),
			'updated_at'   => M24_Sync_LWW::to_iso( (string) $o->updated_at ),
			'origin'       => (string) $o->origin,
			'rev'          => (int) $o->rev,
			'deleted_at'   => null,
		);
		$acc = (int) $o->account_id;
		if ( $acc > 0 ) {
			$rev = (int) get_user_meta( $acc, '_m24_sync_rev', true );
			$upd = (string) get_user_meta( $acc, '_m24_sync_updated_at', true );
			if ( $rev > 0 ) { $rec['rev'] = $rev; }
			if ( '' !== $upd ) { $rec['updated_at'] = M24_Sync_LWW::to_iso( $upd ); }
		}
		return $rec;
	}

	/**
	 * Eine Charge an POST /api/sync/apply. Der Desk antwortet je Record {key, applied, rev}.
	 *
	 * @return array{ok:bool,note:string,results:array}
	 */
	public static function send( string $entity, array $records ): array {
		if ( empty( $records ) ) { return array( 'ok' => true, 'note' => 'leer', 'results' => array() ); }
		$results = array();
		foreach ( array_chunk( $records, self::MAX_RECORDS ) as $chunk ) {
			$res    = M24_Rest_Client::request( 'POST', self::ENDPOINT, array( 'entity' => $entity, 'records' => $chunk ), array( 'timeout' => self::TIMEOUT ) );
			$status = (int) ( $res['status'] ?? 0 );
			if ( empty( $res['ok'] ) ) {
				// 404 = Desk hat den Sync-Vertrag noch nicht deployt. Kein Fehler-Spam, nur eine Notiz —
				// die Buchhaltung bleibt offen, der Cron versucht es weiter.
				$note = 404 === $status ? 'endpoint_missing (Desk-Seite noch nicht live)' : 'HTTP ' . $status . ' · ' . (string) ( $res['error'] ?? '' );
				return array( 'ok' => false, 'note' => $note, 'results' => $results );
			}
			$data    = is_array( $res['data'] ?? null ) ? $res['data'] : array();
			$results = array_merge( $results, (array) ( $data['results'] ?? array() ) );
		}
		return array( 'ok' => true, 'note' => 'ok', 'results' => $results );
	}

	private static function log( string $step, string $msg ): void {
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'sync_push', $step, array( 'msg' => $msg ) );
		}
	}
}
