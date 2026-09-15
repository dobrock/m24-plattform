<?php
/**
 * M24 — Verwaiste Autosave-Entwürfe aufräumen.
 * Modul: includes/class-m24-offer-draft-cleanup.php
 *
 * BEFUND (15.09.2026): Nils Eirik Wenaas steht dreimal in der Angebotsliste — zweimal als
 * nummernloser „Entwurf" über 2.705,57 € und einmal als versendetes 2026-1063 über denselben
 * Betrag mit derselben Position. Die beiden Entwürfe sind keine echten Vorgänge, sondern
 * Rückstände des Editor-Autosave: jede Editor-Sitzung legt über /offers/save-draft eine Zeile an;
 * beim Versand wird nur die Zeile der laufenden Sitzung zum Angebot, die früheren bleiben liegen.
 *
 * Dieses Modul räumt AUF. Es behebt die Ursache NICHT — die sitzt im Editor und ist mit 0.11.501
 * dort behoben (Entwurf kennt seine Anfrage, Wiederverwendung beim Öffnen, Auflösung beim
 * Versand). Ein Automatismus hinter dem Editor wäre genau die Sorte zweiter Weg, die wir bei den
 * Angebotsknöpfen beseitigt haben.
 *
 * ENTWURF ODER VERSENDET — am STATUS entschieden, nicht an der Nummer.
 * Erste Fassung prüfte `offer_no = '' OR IS NULL`. Entwürfe tragen aber einen Platzhalter (E-…):
 * damit fand die Suche keinen einzigen aktuellen Entwurf, und schlimmer — ein Entwurf mit
 * Platzhalter galt in load_sent() als VERSENDETES Angebot. `status` ist die verlässliche Angabe;
 * die Nummernprüfung bleibt nur als zusätzlicher Riegel gegen Platzhalter stehen.
 *
 * SICHERHEIT — ein Entwurf wird nur dann angefasst, wenn ALLE Bedingungen zutreffen:
 *   - Status entwurf, keine echte Angebotsnummer, nicht im Papierkorb
 *   - keine desk_order_id (nie an den Desk gepusht — sonst erzeugt der Papierkorb drüben
 *     einen Tombstone auf einen echten Auftrag)
 *   - derselbe Kunde wie ein VERSENDETES Angebot (customer_uid, sonst E-Mail)
 *   - gleiche Positionsanzahl UND gleiche Nettosumme auf den Cent
 *   - angelegt vor dem Versand des Angebots, höchstens FENSTER_TAGE davor
 * Alles andere bleibt stehen. Ein echter, inhaltlich abweichender Entwurf wird nie getroffen.
 *
 * Papierkorb, nicht löschen: purge_trashed() räumt nach zehn Tagen selbst auf, bis dahin ist
 * jeder Griff umkehrbar.
 *
 * @package M24_Plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Draft_Cleanup {

	/** Wie weit vor dem Versand ein Entwurf entstanden sein darf, um als Rückstand zu gelten. */
	const FENSTER_TAGE = 30;

	/** SQL-Bedingung „trägt keine echte Angebotsnummer" — leer, NULL oder Platzhalter E-…. */
	const OHNE_NUMMER = "( offer_no = '' OR offer_no IS NULL OR offer_no LIKE 'E-%' )";

	/** Gegenstück: eine echte, vergebene Angebotsnummer. */
	const MIT_NUMMER  = "( offer_no <> '' AND offer_no IS NOT NULL AND offer_no NOT LIKE 'E-%' )";

	/**
	 * @param array $ids Optional: Angebotsnummern (2026-1063) oder Zeilen-IDs der VERSENDETEN
	 *                   Angebote. Leer = alle versendeten der letzten 180 Tage.
	 * @param bool  $go  false = Trockenlauf, true = in den Papierkorb legen.
	 * @return array{zeilen:array,geprueft:int,summe:array}
	 */
	public static function run( array $ids = array(), bool $go = false ): array {
		global $wpdb;
		$t   = M24_Offers::table();
		$out = array(
			'zeilen'   => array(),
			'geprueft' => 0,
			'summe'    => array( 'Angebote mit Rückständen' => 0, 'Entwürfe in den Papierkorb' => 0 ),
		);

		$sent = self::load_sent( $ids );
		$out['geprueft'] = count( $sent );

		foreach ( $sent as $o ) {
			$drafts = self::orphans( $o );
			if ( empty( $drafts ) ) { continue; }

			$cust = json_decode( (string) $o->customer_json, true );
			$name = is_array( $cust ) ? (string) ( $cust['name'] ?? '' ) : '';
			$out['zeilen'][] = sprintf( '%s · %s · %s · %d Positionen · %s netto',
				(string) $o->offer_no, $name, (string) $o->status,
				self::item_count( $o ), self::eur( (float) $o->subtotal_net ) );

			foreach ( $drafts as $d ) {
				$out['zeilen'][] = sprintf( '    − Entwurf #%d vom %s · %d Positionen · %s netto%s',
					(int) $d->id,
					mysql2date( 'd.m.Y H:i', (string) $d->created_at ),
					self::item_count( $d ),
					self::eur( (float) $d->subtotal_net ),
					$go ? '  → Papierkorb' : '' );

				if ( ! $go ) { continue; }
				$wpdb->update( $t, array( 'deleted_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $d->id ) );
			}

			$out['summe']['Angebote mit Rückständen']++;
			$out['summe']['Entwürfe in den Papierkorb'] += count( $drafts );
		}

		if ( $go && $out['summe']['Entwürfe in den Papierkorb'] > 0 && class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'maintenance', 'info', 'Verwaiste Autosave-Entwürfe aufgeräumt', array(
				'angebote'  => (int) $out['summe']['Angebote mit Rückständen'],
				'entwuerfe' => (int) $out['summe']['Entwürfe in den Papierkorb'],
			) );
		}
		return $out;
	}

	/**
	 * Versendete Angebote, gegen die geprüft wird.
	 * Status UND Nummer: ein Entwurf mit Platzhalter darf hier nie hereinrutschen, sonst
	 * suchte die Bereinigung Rückstände zu einem Angebot, das gar keines ist.
	 */
	private static function load_sent( array $ids ): array {
		global $wpdb;
		$t    = M24_Offers::table();
		$echt = "status <> 'entwurf' AND " . self::MIT_NUMMER;

		if ( empty( $ids ) ) {
			return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				"SELECT * FROM $t WHERE deleted_at IS NULL AND $echt AND created_at >= %s ORDER BY id DESC",
				gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS )
			) );
		}

		$nos = array(); $pks = array();
		foreach ( $ids as $id ) {
			$id = trim( (string) $id );
			if ( '' === $id ) { continue; }
			if ( ctype_digit( $id ) ) { $pks[] = (int) $id; } else { $nos[] = $id; }
		}
		$rows = array();
		if ( ! empty( $pks ) ) {
			$ph = implode( ',', array_fill( 0, count( $pks ), '%d' ) );
			$rows = array_merge( $rows, (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE id IN ($ph) AND $echt", $pks ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( ! empty( $nos ) ) {
			$ph = implode( ',', array_fill( 0, count( $nos ), '%s' ) );
			$rows = array_merge( $rows, (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE offer_no IN ($ph) AND $echt", $nos ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $rows;
	}

	/**
	 * Entwürfe, die inhaltlich dasselbe sind wie das versendete Angebot.
	 * Bewusst streng: Anzahl UND Summe müssen stimmen, sonst ist es ein eigener Vorgang.
	 *
	 * @return array<int,object>
	 */
	public static function orphans( $o ): array {
		global $wpdb;
		$t = M24_Offers::table();

		$cuid  = trim( (string) ( $o->customer_uid ?? '' ) );
		$cust  = json_decode( (string) ( $o->customer_json ?? '' ), true );
		$email = is_array( $cust ) ? trim( (string) ( $cust['email'] ?? '' ) ) : '';
		if ( '' === $cuid && '' === $email ) { return array(); }

		$ab  = gmdate( 'Y-m-d H:i:s', strtotime( (string) $o->created_at . ' UTC' ) - self::FENSTER_TAGE * DAY_IN_SECONDS );
		$bis = (string) $o->created_at;

		$sql = "SELECT * FROM $t WHERE status = 'entwurf' AND " . self::OHNE_NUMMER
			. " AND deleted_at IS NULL AND ( desk_order_id = '' OR desk_order_id IS NULL )"
			. ' AND id <> %d AND created_at BETWEEN %s AND %s';

		if ( '' !== $cuid ) {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' AND customer_uid = %s', (int) $o->id, $ab, $bis, $cuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' AND customer_json LIKE %s', (int) $o->id, $ab, $bis, '%' . $wpdb->esc_like( $email ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		$n   = self::item_count( $o );
		$sum = round( (float) $o->subtotal_net, 2 );
		$hit = array();
		foreach ( (array) $rows as $r ) {
			if ( self::item_count( $r ) !== $n ) { continue; }
			if ( round( (float) $r->subtotal_net, 2 ) !== $sum ) { continue; }
			$hit[] = $r;
		}
		return $hit;
	}

	private static function item_count( $row ): int {
		$items = json_decode( (string) ( $row->items_json ?? '' ), true );
		return is_array( $items ) ? count( $items ) : 0;
	}

	private static function eur( float $v ): string {
		return number_format( $v, 2, ',', '.' ) . ' €';
	}
}
