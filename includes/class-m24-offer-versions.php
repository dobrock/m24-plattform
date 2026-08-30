<?php
/**
 * M24 — Angebots-Fassungen: Beleg-Historie.
 * Modul: includes/class-m24-offer-versions.php
 *
 * Eine Angebotsnummer, mehrere Fassungen, EIN Listeneintrag. Die laufende Fassung steht an der
 * Angebotszeile (offer_version); die abgelösten Fassungen liegen hier als Beleg — vollständiger
 * Zeilen-Snapshot, damit das, was der Kunde bekommen hat, rekonstruierbar bleibt.
 *
 * Bewusst eine eigene Tabelle: uniq_offer_no lässt keine zweite Zeile je Nummer zu, und die
 * Angebotsliste soll je Vorgang genau einen Eintrag zeigen. purge_trashed() räumt ausschließlich
 * in m24_offers auf und fasst diese Tabelle nie an.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Versions {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'm24_offer_versions';
	}

	/**
	 * Die AKTUELLE Zeile als Beleg wegschreiben, bevor die nächste Fassung sie überschreibt.
	 * Idempotent über uniq_offer_version: ein zweiter Lauf legt keinen zweiten Beleg an.
	 *
	 * @param object $o Angebotszeile in ihrem noch gültigen Stand.
	 */
	public static function archive( $o, string $artifact = '' ): bool {
		global $wpdb;
		$offer_id = (int) ( $o->id ?? 0 );
		if ( $offer_id <= 0 ) { return false; }

		$version = max( 1, (int) ( $o->offer_version ?? 1 ) );
		if ( self::exists( $offer_id, $version ) ) { return true; }

		$items = json_decode( (string) ( $o->items_json ?? '' ), true );
		$ok    = $wpdb->insert( self::table(), array(
			'offer_id'      => $offer_id,
			'offer_no'      => (string) ( $o->offer_no ?? '' ),
			'version'       => $version,
			'snapshot_json' => wp_json_encode( $o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'item_count'    => is_array( $items ) ? count( $items ) : 0,
			'total_gross'   => (float) ( $o->total_gross ?? 0 ),
			'valid_until'   => ! empty( $o->valid_until ) ? (string) $o->valid_until : null,
			'desk_artifact' => '' !== $artifact ? $artifact : null,
			'sent_at'       => ! empty( $o->sent_at ) ? (string) $o->sent_at : null,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		) );
		return (bool) $ok;
	}

	public static function exists( int $offer_id, int $version ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE offer_id = %d AND version = %d', $offer_id, $version
		) );
	}

	/** Vorfassungen, neueste zuerst. */
	public static function history( int $offer_id ): array {
		global $wpdb;
		if ( $offer_id <= 0 ) { return array(); }
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT * FROM ' . self::table() . ' WHERE offer_id = %d ORDER BY version DESC', $offer_id
		) );
		return is_array( $rows ) ? $rows : array();
	}

	/** Artefakt-Referenz an einer Fassung nachtragen (der Desk legt je Fassung ein eigenes ab). */
	public static function set_artifact( int $offer_id, int $version, string $artifact ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'desk_artifact' => $artifact ), array( 'offer_id' => $offer_id, 'version' => $version ) );
	}

	/**
	 * Was sich gegenüber der Vorfassung geändert hat — für Vorschau und Kundenmail.
	 *
	 * @return array{positionen_vorher:int,positionen_nachher:int,summe_vorher:float,summe_nachher:float,neu:array,entfallen:array}
	 */
	public static function diff( $prev, $next ): array {
		$pi = self::items_of( $prev );
		$ni = self::items_of( $next );

		$label = static function ( $it ) {
			$t = trim( (string) ( $it['title'] ?? ( $it['name'] ?? '' ) ) );
			return '' !== $t ? $t : '(ohne Bezeichnung)';
		};
		$pl = array_map( $label, $pi );
		$nl = array_map( $label, $ni );

		return array(
			'positionen_vorher'  => count( $pi ),
			'positionen_nachher' => count( $ni ),
			'summe_vorher'       => (float) ( $prev->total_gross ?? 0 ),
			'summe_nachher'      => (float) ( $next->total_gross ?? 0 ),
			'neu'                => array_values( array_diff( $nl, $pl ) ),
			'entfallen'          => array_values( array_diff( $pl, $nl ) ),
		);
	}

	private static function items_of( $row ): array {
		$raw = '';
		if ( isset( $row->items_json ) ) { $raw = (string) $row->items_json; }
		elseif ( isset( $row->snapshot_json ) ) {
			$snap = json_decode( (string) $row->snapshot_json, true );
			$raw  = is_array( $snap ) ? (string) ( $snap['items_json'] ?? '' ) : '';
		}
		$d = '' !== $raw ? json_decode( $raw, true ) : array();
		return is_array( $d ) ? $d : array();
	}
}
