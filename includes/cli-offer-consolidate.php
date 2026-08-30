<?php
/**
 * M24 — Altbestand aus der Nachfolger-Automatik einsammeln (WP-CLI).
 * Modul: includes/cli-offer-consolidate.php
 *
 *     wp m24 offer-consolidate           Trockenlauf
 *     wp m24 offer-consolidate --go      ausfuehren
 *
 * Zwei Muster, beide aus dem Vorfall „Brand the Build" (30.08.):
 *   1. Kette: A liegt abgeloest im Papierkorb, der Nachfolger B war nie beim Kunden. Dann ist B
 *      grundlos entstanden — A kommt zurueck, B geht in den Papierkorb. Die Nummer von B bleibt
 *      verbraucht; der Nummernkreis wird NICHT rueckwaerts manipuliert.
 *   2. Nummernloser Entwurf, der inhaltlich zu einem versendeten Angebot desselben Kunden gehoert:
 *      wird dessen naechste Fassung (version_pending) und der Entwurf aufgeloest.
 *
 * Versendet NICHTS. Ob eine Fassung rausgeht, entscheidet Daniel pro Vorgang im Operator.
 * Der Sonderfall `wp m24 supersede-undo` bleibt davon unberuehrt.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Consolidate {

	/** Ketten, in denen der Nachfolger nie beim Kunden war. */
	public static function stray_chains( int $limit = 100 ): array {
		global $wpdb;
		$t = M24_Offers::table();
		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT a.id AS alt_id, a.offer_no AS alt_no, b.id AS neu_id, b.offer_no AS neu_no
			   FROM {$t} a JOIN {$t} b ON b.wp_offer_uid = a.superseded_by
			  WHERE a.superseded_by <> '' AND b.deleted_at IS NULL
			    AND ( b.sent_at IS NULL OR b.needs_resend = 1 )
			  ORDER BY a.id ASC LIMIT %d", $limit ) );
	}

	/** Nummernlose Entwuerfe mit einem passenden versendeten Angebot desselben Kunden. */
	public static function stray_drafts( int $limit = 100 ): array {
		global $wpdb;
		$t   = M24_Offers::table();
		$out = array();
		$dr  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT * FROM {$t} WHERE status = 'entwurf' AND ( offer_no = '' OR offer_no IS NULL )
			  AND deleted_at IS NULL ORDER BY id ASC LIMIT %d", $limit ) );
		foreach ( $dr as $d ) {
			$target = self::target_for( $d );
			if ( $target ) { $out[] = array( 'draft' => $d, 'target' => $target ); }
		}
		return $out;
	}

	/** Versendetes Angebot desselben Kunden, das eine neue Fassung aufnehmen kann. */
	private static function target_for( $draft ) {
		global $wpdb;
		$t    = M24_Offers::table();
		$cuid = trim( (string) ( $draft->customer_uid ?? '' ) );
		if ( '' !== $cuid ) {
			return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				"SELECT * FROM {$t} WHERE customer_uid = %s AND offer_no <> '' AND deleted_at IS NULL
				   AND status IN ('offen','versandt') ORDER BY id DESC LIMIT 1", $cuid ) );
		}
		$cust  = json_decode( (string) $draft->customer_json, true );
		$email = is_array( $cust ) ? trim( (string) ( $cust['email'] ?? '' ) ) : '';
		if ( '' === $email ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT * FROM {$t} WHERE customer_json LIKE %s AND offer_no <> '' AND deleted_at IS NULL
			   AND status IN ('offen','versandt') ORDER BY id DESC LIMIT 1",
			'%' . $wpdb->esc_like( $email ) . '%' ) );
	}

	/**
	 * @return array{zeilen:array,summe:array}
	 */
	public static function run( bool $go = false ): array {
		global $wpdb;
		$out = array( 'zeilen' => array(), 'summe' => array() );
		$bump = static function ( &$s, $k ) { $s[ $k ] = ( $s[ $k ] ?? 0 ) + 1; };

		// 1) Grundlos entstandene Nachfolger aufloesen.
		foreach ( self::stray_chains() as $c ) {
			$out['zeilen'][] = sprintf( 'Kette: %s zurueckholen, %s in den Papierkorb (Nummer bleibt verbraucht)',
				(string) $c->alt_no, (string) $c->neu_no );
			if ( ! $go ) { continue; }
			$wpdb->update( M24_Offers::table(), array( 'deleted_at' => null, 'superseded_by' => '' ), array( 'id' => (int) $c->alt_id ) );
			$wpdb->update( M24_Offers::table(), array( 'deleted_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $c->neu_id ) );
			$bump( $out['summe'], 'ketten_aufgeloest' );
		}

		// 2) Nummernlose Entwuerfe als naechste Fassung uebernehmen — ohne Versand.
		foreach ( self::stray_drafts() as $pair ) {
			$d = $pair['draft'];
			$t = $go ? M24_Offers::get_by_id( (int) $pair['target']->id ) : $pair['target']; // nach Schritt 1 neu lesen
			$out['zeilen'][] = sprintf( 'Entwurf #%d (%d Pos., %s EUR) -> Fassung %d von %s',
				(int) $d->id,
				count( (array) json_decode( (string) $d->items_json, true ) ),
				number_format( (float) $d->total_gross, 2, ',', '.' ),
				max( 1, (int) ( $t->offer_version ?? 1 ) ) + 1,
				(string) $t->offer_no );
			if ( ! $go ) { continue; }

			$row = array(
				'customer_json' => (string) $t->customer_json, // Kundenstand des Angebots bleibt massgeblich
				'items_json'    => (string) $d->items_json,
				'extras_json'   => (string) $d->extras_json,
				'delivery_time' => (string) $d->delivery_time,
				'tax_mode'      => (string) $d->tax_mode,
				'tax_rate'      => (float) $d->tax_rate,
				'tax_note'      => (string) $d->tax_note,
				'subtotal_net'  => (float) $d->subtotal_net,
				'tax_amount'    => (float) $d->tax_amount,
				'total_gross'   => (float) $d->total_gross,
				'currency'      => (string) $d->currency,
				'src_json'      => (string) $d->src_json,
			);
			$st = M24_Offer_Update::stage( (int) $t->id, $row, (int) $d->id );
			if ( $st['ok'] ) {
				// Bewusst NICHT versenden: die Fassung bleibt bereit, Daniel entscheidet pro Vorgang.
				M24_Offer_Update::hold( (int) $t->id, 'Aus Altbestand uebernommen — Versand bewusst offen' );
				$bump( $out['summe'], 'fassungen_angelegt' );
			} else {
				$out['zeilen'][] = '   FEHLER: ' . $st['msg'];
				$bump( $out['summe'], 'fehler' );
			}
		}
		return $out;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'm24 offer-consolidate', function ( $args, $assoc ) {
		$go = ! empty( $assoc['go'] );
		$r  = M24_Offer_Consolidate::run( $go );
		WP_CLI::log( ( $go ? 'AUSFUEHREN' : 'TROCKENLAUF' ) . ' — ' . count( $r['zeilen'] ) . ' Vorgang/Vorgaenge:' );
		foreach ( $r['zeilen'] as $z ) { WP_CLI::log( '  ' . $z ); }
		if ( ! $go ) {
			WP_CLI::success( 'Trockenlauf. Zum Ausfuehren: wp m24 offer-consolidate --go' );
			return;
		}
		$teile = array();
		foreach ( $r['summe'] as $k => $v ) { $teile[] = $v . ' ' . $k; }
		WP_CLI::success( ( empty( $teile ) ? 'Nichts zu tun.' : implode( ', ', $teile ) )
			. ' — nichts versendet, das entscheidest du pro Vorgang im Operator.' );
	} );
}
