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
			'subject'      => M24_Offer_Update_Mail::subject( $new ),
			'mail_html'    => M24_Offer_Update_Mail::render( $new, $diff ),
			'send_allowed' => $allowed['ok'],
			'send_hint'    => $allowed['msg'],
		) );
	}

	/** Bestätigung aus der Vorschau: DAS ist der Versand. */
	public static function send( WP_REST_Request $r ) {
		$id = (int) $r->get_param( 'id' );
		$o  = M24_Offers::get_by_id( $id );
		if ( ! $o ) { return new WP_Error( 'm24upd_404', 'Angebot nicht gefunden.', array( 'status' => 404 ) ); }

		$allowed = M24_Offer_Update_Mail::send_allowed();
		if ( ! $allowed['ok'] ) {
			return new WP_Error( 'm24upd_draft', $allowed['msg'], array( 'status' => 409 ) );
		}
		$gate = M24_Offer_Update::gate( $id );
		if ( ! $gate['ok'] ) {
			return new WP_Error( 'm24upd_artifact', $gate['msg'] . ' — kein Versand ohne Anhang.', array( 'status' => 409 ) );
		}

		$cust = json_decode( (string) $o->customer_json, true );
		$to   = is_array( $cust ) ? trim( (string) ( $cust['email'] ?? '' ) ) : '';
		if ( ! is_email( $to ) ) { return new WP_Error( 'm24upd_mail', 'Keine gültige Kundenadresse am Angebot.', array( 'status' => 400 ) ); }

		$prev = M24_Offer_Versions::history( $id );
		$diff = M24_Offer_Versions::diff( $prev ? $prev[0] : $o, $o );
		$att  = (array) M24_Desk_Push::offer_pdf_attachment( $o );
		$sent = wp_mail( $to, M24_Offer_Update_Mail::subject( $o ), M24_Offer_Update_Mail::render( $o, $diff ),
			array( 'Content-Type: text/html; charset=UTF-8' ), isset( $att['path'] ) ? array( $att['path'] ) : array() );

		if ( ! $sent ) {
			M24_Offer_Update::hold( $id, 'Mailversand fehlgeschlagen' );
			return new WP_Error( 'm24upd_send', 'Versand fehlgeschlagen — die Fassung bleibt bereit.', array( 'status' => 500 ) );
		}
		M24_Offer_Update::release( $id );
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Fassung ' . (int) $o->offer_version . ' an ' . $to . ' versendet.' ) );
	}
}
