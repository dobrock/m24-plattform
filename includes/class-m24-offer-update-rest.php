<?php
/**
 * M24 — „Angebot aktualisieren": Bedienung (Knopf + REST).
 * Modul: includes/class-m24-offer-update-rest.php
 *
 * Ein Knopf an der Karte eines versendeten Angebots. Er öffnet den Editor auf dem aktuellen Stand
 * (?update_offer=<id>, inklusive des Inhalts eines nummernlosen Entwurfs, falls einer herumliegt).
 *
 * Entscheidung A mit Vorschau: stage → Artefakt-Gate → Vorschau → Bestätigung IN der Vorschau
 * versendet. Es gibt keinen separaten „Senden"-Knopf und keinen „bereit"-Zustand im Normalfall.
 * Der EINZIGE Zwischenzustand entsteht, wenn der Desk kein Artefakt liefert — dann bleibt die
 * Fassung begründet liegen und Daniel löst später erneut aus.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Update_REST {

	const NS = 'm24/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$args = array( 'permission_callback' => array( __CLASS__, 'may' ) );
		register_rest_route( self::NS, '/offers/update-open',  array_merge( $args, array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'open' ) ) ) );
		register_rest_route( self::NS, '/offers/update-stage', array_merge( $args, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'stage' ) ) ) );
		register_rest_route( self::NS, '/offers/update-send',  array_merge( $args, array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send' ) ) ) );
	}

	public static function may(): bool {
		return current_user_can( 'manage_options' );
	}

	/** Editor-Vorbelegung auf dem aktuellen Stand (+ nummernloser Entwurf). */
	public static function open( WP_REST_Request $r ) {
		$pf = M24_Offer_Update::prefill( (int) $r->get_param( 'id' ) );
		if ( null === $pf ) {
			return new WP_Error( 'm24upd_no', 'Dieses Angebot kann nicht aktualisiert werden.', array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'prefill' => $pf ) );
	}

	/**
	 * Fassung schreiben, Desk-Push anstoßen, Artefakt prüfen, Vorschau liefern.
	 * Versendet NICHTS — das tut erst die Bestätigung in der Vorschau.
	 */
	public static function stage( WP_REST_Request $r ) {
		$id  = (int) $r->get_param( 'id' );
		$o   = M24_Offers::get_by_id( $id );
		if ( ! M24_Offer_Update::can_update( $o ) ) {
			return new WP_Error( 'm24upd_no', 'Dieses Angebot kann nicht aktualisiert werden.', array( 'status' => 400 ) );
		}
		$row = M24_Offers::row_from_payload( (array) $r->get_param( 'offer' ) );
		if ( is_wp_error( $row ) ) { return $row; }

		$prev = $o; // Stand vor der Änderung — Basis des Fassungsdiffs.
		$st   = M24_Offer_Update::stage( $id, $row, (int) $r->get_param( 'absorb_id' ) );
		if ( ! $st['ok'] ) { return new WP_Error( 'm24upd_stage', $st['msg'], array( 'status' => 400 ) ); }

		$gate = M24_Offer_Update::gate( $id );
		$new  = M24_Offers::get_by_id( $id );
		$diff = M24_Offer_Versions::diff( $prev, $new );

		if ( ! $gate['ok'] ) {
			// OHNE ARTEFAKT KEIN VERSAND. Fassung bleibt begründet liegen.
			return rest_ensure_response( array(
				'ok' => false, 'pending' => true, 'version' => $st['version'],
				'message' => $gate['msg'] . ' — die Fassung ist gespeichert und wartet. Kein Versand ohne Anhang.',
				'diff' => $diff,
			) );
		}

		$allowed = M24_Offer_Update_Mail::send_allowed();
		return rest_ensure_response( array(
			'ok'           => true,
			'pending'      => false,
			'version'      => $st['version'],
			'artifact'     => $gate['artifact'],
			'diff'         => $diff,
			'valid_until'  => class_exists( 'M24_Offer_Validity' )
				? M24_Offer_Validity::date_str( (string) $new->valid_until )
				: (string) $new->valid_until,
			'subject'      => M24_Offer_Update_Mail::subject( $new ),
			'mail_html'    => M24_Offer_Update_Mail::render( $new, $diff ),
			'send_allowed' => $allowed['ok'],
			'send_hint'    => $allowed['msg'],
		) );
	}

	/**
	 * Bestätigung aus der Vorschau: DAS ist der Versand. Die Arbeit macht
	 * M24_Offer_Update::send_version() — dieselbe Stelle, die auch „erneut auslösen" an der Karte
	 * ruft. Es darf nie zwei Versandpfade geben.
	 *
	 * Antwortet IMMER mit Text: ok, blockiert (pending) oder Fehler. Ein Klick ohne sichtbare
	 * Wirkung war der Auslöser des Vorfalls vom 30.08.
	 */
	public static function send( WP_REST_Request $r ) {
		$res = M24_Offer_Update::send_version( (int) $r->get_param( 'id' ) );
		return rest_ensure_response( array(
			'ok'      => $res['ok'],
			'pending' => $res['pending'],
			'message' => $res['msg'],
		) );
	}
}
