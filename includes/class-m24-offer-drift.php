<?php
/**
 * M24 — Abweichung gegenüber der versendeten Fassung („Drift").
 * Modul: includes/class-m24-offer-drift.php
 *
 * Seit 0.11.484 legt sync_supersede keinen Nachfolger mehr an. Ohne Ersatz-Angebot fehlte damit
 * aber auch jedes Zeichen, dass ein versendetes Angebot inzwischen inhaltlich abweicht — der
 * Notschalter allein hätte die Abweichung nur unsichtbar gemacht. Statt einer neuen Nummer merkt
 * sich das Angebot die Abweichung an sich selbst und zeigt sie ruhig an der Karte.
 *
 * Angebote liegen in wp_m24_offers, nicht als Post: deshalb Spalten (Migration 031), kein Postmeta.
 * Der Marker ist reine Anzeige-Information — er zählt bewusst NICHT `rev` hoch und löst keinen
 * Desk-Push aus.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Drift {

	/** Mehr Gründe bringen keinen Erkenntnisgewinn, blähen die Spalte aber auf. */
	const MAX_REASONS = 10;

	/**
	 * Abweichung festhalten. Nur an bereits versendeten Angeboten — bei einem Entwurf ist eine
	 * Änderung der Normalfall und kein Hinweis wert.
	 *
	 * @param int    $offer_id Angebot in wp_m24_offers.
	 * @param string $reason   Was abweicht, z.B. "Adresse geändert (strasse,ort)".
	 */
	public static function mark( int $offer_id, string $reason ): void {
		global $wpdb;
		if ( $offer_id <= 0 || ! class_exists( 'M24_Offers' ) ) { return; }

		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! $o ) { return; }
		if ( class_exists( 'M24_Sync_Supersede' ) && ! M24_Sync_Supersede::is_sent( $o ) ) { return; }
		if ( ! empty( $o->deleted_at ) ) { return; }

		$reason = trim( $reason );
		$fields = self::fields( $o );
		if ( '' !== $reason && ! in_array( $reason, $fields, true ) ) {
			$fields[] = $reason;
		}
		if ( count( $fields ) > self::MAX_REASONS ) {
			$fields = array_slice( $fields, -self::MAX_REASONS );
		}

		$wpdb->update( M24_Offers::table(), array(
			'offer_drift'        => 1,
			'offer_drift_at'     => gmdate( 'Y-m-d H:i:s' ),
			'offer_drift_fields' => wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		), array( 'id' => $offer_id ) );

		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'offer_drift', 'Abweichung zur versendeten Fassung vermerkt', array(
				'offer_no' => (string) $o->offer_no,
				'grund'    => $reason,
			) );
		}
	}

	/**
	 * Marker löschen — der Kunde hat den aktuellen Stand bekommen.
	 * Gehört an jede Stelle, die sent_at auf „jetzt" setzt.
	 */
	public static function clear( int $offer_id ): void {
		global $wpdb;
		if ( $offer_id <= 0 || ! class_exists( 'M24_Offers' ) ) { return; }
		$wpdb->update( M24_Offers::table(), array(
			'offer_drift'        => 0,
			'offer_drift_at'     => null,
			'offer_drift_fields' => null,
		), array( 'id' => $offer_id ) );
	}

	public static function has( $o ): bool {
		return ! empty( $o->offer_drift );
	}

	/** Gründe als Liste. Defensiv: die Spalte kann aus Altbeständen leer oder kaputt sein. */
	public static function fields( $o ): array {
		$raw = isset( $o->offer_drift_fields ) ? (string) $o->offer_drift_fields : '';
		if ( '' === $raw ) { return array(); }
		$d = json_decode( $raw, true );
		return is_array( $d ) ? array_values( array_filter( array_map( 'strval', $d ) ) ) : array();
	}

	/**
	 * Kartenhinweis. Bewusst ruhig: dieselbe gedeckte Farbe wie die übrigen Sync-Hinweise,
	 * kein Alarmrot — es ist ein Hinweis, kein Fehler.
	 */
	public static function badge( $o ): string {
		if ( ! self::has( $o ) ) { return ''; }

		$when = '';
		if ( ! empty( $o->offer_drift_at ) && ( $ts = strtotime( (string) $o->offer_drift_at . ' UTC' ) ) ) {
			$when = ' ' . ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y H:i', $ts ) : gmdate( 'd.m.Y H:i', $ts ) . ' UTC' );
		}
		$title = 'Diese Fassung weicht von der ab, die der Kunde bekommen hat.';
		$f     = self::fields( $o );
		if ( ! empty( $f ) ) { $title .= ' ' . implode( ' · ', $f ); }

		return '<span style="color:#6b7280;" title="' . esc_attr( $title ) . '">'
			. '● Änderung gegenüber der versendeten Fassung' . esc_html( $when ) . '</span>';
	}
}
