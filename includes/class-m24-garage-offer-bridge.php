<?php
/**
 * M24 Garage → Angebot-Brücke: Ein eingeloggter Operator (manage_options) macht aus einer Garage mit einem Klick
 * einen Angebots-Entwurf. Reine Mapping-/Route-Schicht — die Angebots-Tabelle bleibt in M24_Offers.
 *
 * ⚠️ Preis-/Steuer-Mapping (die kritische Falle): Garagenpreise sind BRUTTO (inkl. 19 % bzw. §25a-Brutto). Der
 * Angebots-Builder behandelt unit_price regelbesteuerter Positionen als NETTO (b2b_de_19 rechnet ×1,19). Darum:
 *   - §25a-Position (M24_Catalog_Pricing::is_25a) → unit_price = Garagen-Brutto UNVERÄNDERT (kein MwSt-Rechnen).
 *   - regelbesteuert                              → unit_price = Garagen-Brutto ÷ 1,19 (Netto) → round-trippt bei b2b_de_19.
 * tax25a wird zusätzlich in clean_items aus dem Teil geerbt (dieselbe Quelle) → konsistent.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Garage_Offer_Bridge {

	const NS = 'm24/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/** Operator? (serverseitiges Gate — dieselbe Cap wie der Angebots-Builder/-Routen). */
	public static function is_operator(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes() {
		register_rest_route( self::NS, '/offers/from-garage', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'is_operator' ), // NICHT __return_true — Kunden-Pfad darf das nie
			'callback'            => array( __CLASS__, 'handle_from_garage' ),
		) );
	}

	/**
	 * POST /offers/from-garage
	 *  - { account_id?: int, garage_no?: string }  → serverseitige Kontogarage (Preise vertrauenswürdig).
	 *  - { items: [{ teil_id|id, qty, variant }] }  → Gast-/localStorage-Garage; Preise werden SERVERSEITIG aus
	 *    dem Teil neu abgeleitet (nie Client-Preise übernehmen).
	 * Antwort: { ok, draft_id, edit_url } → Builder öffnet vorbefüllt auf diesem Entwurf.
	 */
	public static function handle_from_garage( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24gob_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		if ( ! class_exists( 'M24_Offers' ) || ! class_exists( 'M24_Garage_Cart' ) ) {
			return new WP_Error( 'm24gob_dep', 'Nicht verfügbar.', array( 'status' => 400 ) );
		}
		$p = (array) $req->get_json_params();

		// Doppelklick-Guard: pro Operator kurzzeitig denselben Entwurf zurückgeben (verhindert zwei Drafts).
		$guard_key = 'm24gob_' . get_current_user_id();
		$recent    = (int) get_transient( $guard_key );
		if ( $recent > 0 ) {
			$o = M24_Offers::get_by_id( $recent );
			if ( $o && 'entwurf' === (string) $o->status ) {
				return rest_ensure_response( array(
					'ok'       => true,
					'draft_id' => $recent,
					'edit_url' => add_query_arg( array( M24_Offers::QV_NEW => 1, 'draft' => $recent ), home_url( '/' ) ),
					'deduped'  => true,
				) );
			}
		}

		$src_items = self::resolve_items( $p );
		if ( empty( $src_items ) ) {
			return new WP_Error( 'm24gob_empty', 'Die Garage enthält keine (gültigen) Positionen.', array( 'status' => 400 ) );
		}

		$offer_items = array();
		foreach ( $src_items as $it ) {
			$pid = (int) $it['post_id'];
			if ( $pid <= 0 ) { continue; }
			$is25   = class_exists( 'M24_Catalog_Pricing' ) ? (bool) M24_Catalog_Pricing::is_25a( $pid ) : false;
			$brutto = ( null !== $it['unit'] ) ? (float) $it['unit'] : 0.0; // Garagen-Brutto (variant_price hat Vorrang)
			// §25a: Brutto unverändert · regelbesteuert: Brutto → Netto (÷1,19), damit b2b_de_19 exakt round-trippt.
			$unit_price = $is25 ? round( $brutto, 2 ) : round( $brutto / 1.19, 2 );
			$offer_items[] = array(
				'teil_id'    => $pid,
				'title'      => (string) $it['title'],
				'title_de'   => (string) $it['title'],
				'qty'        => max( 1, (int) $it['qty'] ),
				'unit_price' => $unit_price,
				'tax25a'     => $is25, // clean_items erbt dies ohnehin aus dem Teil — hier explizit, konsistent
				'variant'    => (string) $it['variant'],
				'custom'     => ( '' !== (string) $it['variant'] ), // Varianten-/Sonderpositionen als Custom-Text markieren
				'thumb'      => (string) $it['thumb'],
			);
		}

		$res = M24_Offers::create_garage_draft( $offer_items );
		if ( empty( $res['ok'] ) ) {
			return new WP_Error( 'm24gob_draft', (string) ( $res['error'] ?? 'Entwurf fehlgeschlagen.' ), array( 'status' => 500 ) );
		}
		set_transient( $guard_key, (int) $res['draft_id'], 8 ); // 8 s Doppelklick-Fenster
		return rest_ensure_response( array( 'ok' => true, 'draft_id' => (int) $res['draft_id'], 'edit_url' => (string) $res['edit_url'] ) );
	}

	/**
	 * Positionen auflösen — Preise IMMER serverseitig (Garagen-Brutto). Rückgabe je Item:
	 * { post_id, title, qty, variant, unit (Brutto|null), thumb }.
	 */
	private static function resolve_items( array $p ): array {
		// (a) Kontogarage: explizite account_id/garage_no, sonst die Garage des eingeloggten Operators.
		$acc = 0;
		if ( ! empty( $p['account_id'] ) ) {
			$acc = (int) $p['account_id'];
		} elseif ( ! empty( $p['garage_no'] ) && method_exists( 'M24_Garage_Cart', 'resolve_garage_no' ) ) {
			$acc = (int) M24_Garage_Cart::resolve_garage_no( (string) $p['garage_no'] );
		}
		if ( $acc <= 0 && empty( $p['items'] ) && method_exists( 'M24_Garage_Cart', 'current_account_id' ) ) {
			$acc = (int) M24_Garage_Cart::current_account_id(); // Fallback: eigene Operator-Garage
		}

		$out = array();
		if ( $acc > 0 ) {
			foreach ( (array) M24_Garage_Cart::items( $acc ) as $r ) {
				$pid = (int) ( $r['post_id'] ?? 0 );
				if ( $pid <= 0 ) { continue; }
				$out[] = array(
					'post_id' => $pid,
					'title'   => (string) ( $r['title'] ?? get_the_title( $pid ) ),
					'qty'     => max( 1, (int) ( $r['qty'] ?? 1 ) ),
					'variant' => (string) ( $r['variant'] ?? '' ),
					'unit'    => ( isset( $r['unit'] ) && null !== $r['unit'] ) ? (float) $r['unit'] : null,
					'thumb'   => (string) ( $r['thumb'] ?? '' ),
				);
			}
			return $out;
		}

		// (b) Gast-/localStorage-Garage: nur IDs/Mengen/Varianten vom Client — Preis serverseitig neu ableiten.
		foreach ( (array) ( $p['items'] ?? array() ) as $it ) {
			$pid = (int) ( $it['teil_id'] ?? ( $it['id'] ?? 0 ) );
			if ( $pid <= 0 ) { continue; }
			$qty = max( 1, (int) ( $it['qty'] ?? ( $it['q'] ?? 1 ) ) );
			$disp = method_exists( 'M24_Garage_Cart', 'item_display' ) ? M24_Garage_Cart::item_display( $pid, $qty ) : null;
			if ( ! is_array( $disp ) ) { continue; }
			$out[] = array(
				'post_id' => $pid,
				'title'   => (string) ( $disp['title'] ?? get_the_title( $pid ) ),
				'qty'     => $qty,
				'variant' => sanitize_text_field( (string) ( $it['variant'] ?? ( $it['vl'] ?? '' ) ) ),
				'unit'    => ( isset( $disp['unit'] ) && null !== $disp['unit'] ) ? (float) $disp['unit'] : null,
				'thumb'   => (string) ( $disp['thumb'] ?? '' ),
			);
		}
		return $out;
	}
}
