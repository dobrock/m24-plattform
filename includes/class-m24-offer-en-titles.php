<?php
/**
 * M24 — EN-Angebote nachziehen: Positionstitel auf Englisch an den Desk.
 * Modul: includes/class-m24-offer-en-titles.php
 *
 * BEFUND (08.09.2026): 2026-1043 und 2026-1050 gingen englisch an den Kunden, im Desk standen die
 * Positionen deutsch. M24_Desk_Push::map_item() übergab immer $it['title']; die Sprachlogik griff nur
 * in Mail und Kunden-Ansicht. Seit 0.11.494 nutzt map_item() dieselbe Ableitung
 * (M24_Offers_Render::item_title) — die Quelle ist zu, der alte Stand liegt aber noch im Desk.
 *
 * Dieser Lauf stößt für betroffene Angebote einen erneuten Push an, mehr nicht: kein Mailversand,
 * kein neuer Auftrag, keine neue Nummer. M24_Sync_LWW::touch() bumpt rev und updated_at, der
 * Sync-Push nimmt die Zeile beim nächsten Lauf mit — derselbe Weg wie bei jeder anderen Änderung.
 *
 * BETROFFEN ist ein Angebot, wenn ALLE gelten:
 *   - Angebotssprache ist 'en'
 *   - es ist im Desk bekannt (desk_order_id gesetzt) — sonst gibt es drüben nichts zu korrigieren
 *   - mindestens eine Position trägt einen title_en, der vom title abweicht
 * Ohne den letzten Punkt wäre der Push wirkungslos: ohne Übersetzung sendet map_item() weiterhin den
 * deutschen Titel, und ein Push ohne Änderung ist nur Last.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_EN_Titles {

	/**
	 * @param array $ids Angebotsnummern oder IDs; leer = alle betroffenen.
	 * @param bool  $go  false = nur zeigen, was gepusht würde.
	 * @return array{zeilen:array,summe:array}
	 */
	public static function run( array $ids = array(), bool $go = false ): array {
		global $wpdb;
		$t      = M24_Offers::table();
		$zeilen = array();
		$treffer = 0;

		$where = "deleted_at IS NULL AND desk_order_id <> ''";
		$args  = array();
		if ( ! empty( $ids ) ) {
			$ph    = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
			$where .= " AND ( offer_no IN ($ph) OR id IN ($ph) )";
			$args   = array_merge( array_map( 'strval', $ids ), array_map( 'strval', $ids ) );
		}
		$sql  = "SELECT id, offer_no, src_json, items_json, desk_order_num FROM $t WHERE $where ORDER BY id DESC LIMIT 300";
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB

		foreach ( (array) $rows as $o ) {
			$src = json_decode( (string) $o->src_json, true );
			$lang = ( is_array( $src ) && 'en' === ( $src['lang'] ?? $src['src_lang'] ?? '' ) ) ? 'en' : 'de';
			if ( 'en' !== $lang ) { continue; }

			$items = json_decode( (string) $o->items_json, true );
			if ( ! is_array( $items ) ) { continue; }

			$uebersetzt = array();
			foreach ( $items as $it ) {
				if ( ! is_array( $it ) ) { continue; }
				$en = trim( (string) ( $it['title_en'] ?? '' ) );
				$de = trim( (string) ( $it['title'] ?? '' ) );
				if ( '' !== $en && $en !== $de ) { $uebersetzt[] = $en; }
			}
			if ( empty( $uebersetzt ) ) {
				$zeilen[] = sprintf( '%s — EN-Angebot, aber keine übersetzten Titel: Push würde nichts ändern, übersprungen', (string) $o->offer_no );
				continue;
			}

			$treffer++;
			$zeilen[] = sprintf(
				'%s (Desk %s) — %d Position(en) mit EN-Titel, z. B. „%s"%s',
				(string) $o->offer_no,
				(string) ( $o->desk_order_num ?: '—' ),
				count( $uebersetzt ),
				(string) $uebersetzt[0],
				$go ? ' → Push angestoßen' : ''
			);
			if ( ! $go ) { continue; }

			// Nur stempeln. Der Sync-Push holt die Zeile daraufhin ab; kein Direktversand, damit ein
			// hängender Desk-Call den Wartungslauf nicht blockiert.
			M24_Sync_LWW::touch( (int) $o->id, 'wp' );
		}

		return array(
			'zeilen' => $zeilen,
			'summe'  => array(
				'Geprüfte Angebote'  => count( (array) $rows ),
				'Mit EN-Titeln'      => $treffer,
				$go ? 'Push angestoßen' : 'Würde pushen' => $treffer,
			),
		);
	}
}
