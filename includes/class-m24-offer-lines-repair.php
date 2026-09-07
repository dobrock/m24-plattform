<?php
/**
 * M24 — Positions-Dubletten aus dem Sync-Erstlauf bereinigen (rein lokal).
 * Modul: includes/class-m24-offer-lines-repair.php
 *
 * BEFUND (07.09.2026): Angebote mit der Desk-Änderung vom 26.08. 16:22 führen ihre Positionen
 * doppelt — einmal mit Preis (WP-Zeile), einmal mit 0,00 € (vom Desk angehängte Zeile). Dazu
 * Desk-Service-Zeilen (Verpackung/Versand/Zoll) als 0-€-Positionen, obwohl WP sie als Extras
 * führt. Ursache war der erste Lauf der bidirektionalen Sync: der Desk schickte seine Zeilen mit
 * eigenen line_uids, WP kannte sie nicht und hängte sie an. Der Schutz gegen vorläufige Desk-UIDs
 * kam danach; die Quelle ist zu, der Schaden liegt in items_json.
 *
 * WARUM REIN LOKAL, OHNE TOMBSTONE, OHNE PUSH:
 * Der Desk adoptiert Zeilen über Artikel + Menge, nicht über die UID (s. M24_Sync_Push::push_offer).
 * Eine Desk-Zeile kann deshalb inzwischen unter UNSERER UID laufen — und die 0-€-Kopie in WP ist
 * meist die Desk-Zeile selbst, nur ohne übertragenen Preis (Fall 2026-1037: drei aktive Desk-Zeilen
 * zu 1.259,44 €, bezahlt, fakturiert). Jeder Tombstone, egal für welche UID, kann drüben eine echte
 * Zeile löschen. Deshalb: die Kopien werden nur aus items_json genommen und ihre UIDs kommen auf
 * eine Sperrliste, damit der Applier sie beim nächsten Sync nicht wieder anhängt. Der Desk bleibt
 * unangetastet; sein Stand ist für diese Aufträge ohnehin der führende.
 *
 * REGELN (nur Zeilen mit origin = 'desk' werden je ausgeblendet):
 *   A  Null-Dublette:   unit_price = 0 und dieselbe Bezeichnung existiert mit Preis > 0.
 *   B  Service-Zeile:   unit_price = 0, kein teil_id, Bezeichnung nach Verpackung/Versand/Zoll.
 *   C  Exakte Dublette: gleiche Bezeichnung, Menge und Preis wie eine frühere, behaltene Zeile.
 * Zwei WP-Zeilen, die sich exakt gleichen, werden NUR gemeldet.
 *
 * @package M24_Plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Lines_Repair {

	/** Bezeichnungen, die WP als Extras führt, nicht als Positionen. */
	const SERVICE_RE = '/(verpack|packag|versand|shipping|customs|zoll|handling)/iu';

	/**
	 * Sperrliste: offer_id => [line_uid, …]. Eine Option (autoload nein), gelesen vom Applier
	 * (M24_Sync_Apply::apply_line) OHNE Abhängigkeit von dieser Klasse — der Sync läuft auch dort,
	 * wo diese Datei nicht geladen ist (REST, Cron).
	 */
	const OPT_SUPPRESS = 'm24_line_suppress';

	/**
	 * @param array $ids Optional: Angebotsnummern (2026-1041) oder Zeilen-IDs. Leer = alle aktiven.
	 * @param bool  $go  false = Trockenlauf, true = schreiben.
	 * @return array{zeilen:array,geprueft:int,summe:array}
	 */
	public static function run( array $ids = array(), bool $go = false ): array {
		$out   = array( 'zeilen' => array(), 'geprueft' => 0, 'summe' => array( 'Angebote mit Dubletten' => 0, 'Zeilen ausgeblendet' => 0, 'manuell prüfen' => 0 ) );
		$rows  = self::load( $ids );
		$out['geprueft'] = count( $rows );

		foreach ( $rows as $o ) {
			$items = json_decode( (string) $o->items_json, true );
			if ( ! is_array( $items ) || count( $items ) < 2 ) { continue; }

			$plan = self::plan( $items );
			if ( empty( $plan['entfernen'] ) && empty( $plan['manuell'] ) ) { continue; }

			$cust = json_decode( (string) $o->customer_json, true );
			$name = is_array( $cust ) ? (string) ( $cust['name'] ?? '' ) : '';
			$out['zeilen'][] = sprintf( '%s · %s · %s · %d Positionen · %s netto',
				(string) $o->offer_no, $name, (string) $o->status, count( $items ), self::eur( (float) $o->subtotal_net ) );

			foreach ( $plan['entfernen'] as $e ) {
				$out['zeilen'][] = sprintf( '    − [%s] %s · %d × %s  (uid %s)',
					$e['regel'], $e['title'], (int) $e['qty'], self::eur( (float) $e['unit_price'] ), substr( (string) $e['line_uid'], 0, 8 ) );
			}
			foreach ( $plan['manuell'] as $m ) {
				$out['zeilen'][] = sprintf( '    ? manuell prüfen: %s · %d × %s steht %dx (beide WP-Zeilen — nicht angefasst)',
					$m['title'], (int) $m['qty'], self::eur( (float) $m['unit_price'] ), (int) $m['anzahl'] );
			}
			$out['summe']['manuell prüfen'] += count( $plan['manuell'] );
			if ( empty( $plan['entfernen'] ) ) { continue; }

			$out['summe']['Angebote mit Dubletten']++;
			$out['summe']['Zeilen ausgeblendet'] += count( $plan['entfernen'] );

			if ( $go ) {
				$ok = self::write( $o, $plan );
				$n  = M24_Offers::get_by_id( (int) $o->id );
				$out['zeilen'][] = $ok
					? sprintf( '    ✓ lokal bereinigt: %d Positionen · %s netto · UIDs gesperrt · kein Push, kein Tombstone',
						count( $plan['behalten'] ), self::eur( (float) ( $n->subtotal_net ?? 0 ) ) )
					: '    ✗ Schreiben fehlgeschlagen — Angebot unverändert';
			} else {
				$out['zeilen'][] = sprintf( '    → danach %d Positionen · nur WP, Desk bleibt unangetastet', count( $plan['behalten'] ) );
			}
		}
		return $out;
	}

	/**
	 * Entscheidet je Zeile. Reihenfolge bleibt erhalten; ausgeblendet wird immer die SPÄTERE Zeile,
	 * damit die ursprüngliche WP-Position stehen bleibt.
	 *
	 * @return array{behalten:array,entfernen:array,manuell:array}
	 */
	public static function plan( array $items ): array {
		$keep = array(); $drop = array(); $manual = array();

		$max_by_title = array();
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) { continue; }
			$t = self::norm( (string) ( $it['title'] ?? '' ) );
			$p = round( (float) ( $it['unit_price'] ?? 0 ), 2 );
			if ( ! isset( $max_by_title[ $t ] ) || $p > $max_by_title[ $t ] ) { $max_by_title[ $t ] = $p; }
		}

		$seen_sig = array();
		foreach ( $items as $it ) {
			if ( ! is_array( $it ) ) { continue; }
			$title = (string) ( $it['title'] ?? '' );
			$t     = self::norm( $title );
			$qty   = max( 1, (int) ( $it['qty'] ?? 1 ) );
			$price = round( (float) ( $it['unit_price'] ?? 0 ), 2 );
			$desk  = 'desk' === (string) ( $it['origin'] ?? '' );
			$sig   = $t . '|' . $qty . '|' . number_format( $price, 2, '.', '' );
			$row   = array( 'title' => $title, 'qty' => $qty, 'unit_price' => $price, 'line_uid' => (string) ( $it['line_uid'] ?? '' ) );

			$regel = '';
			if ( $desk ) {
				if ( 0.0 === $price && ( $max_by_title[ $t ] ?? 0 ) > 0 ) {
					$regel = 'A';
				} elseif ( 0.0 === $price && empty( $it['teil_id'] ) && preg_match( self::SERVICE_RE, $title ) ) {
					$regel = 'B';
				} elseif ( ! empty( $seen_sig[ $sig ] ) ) {
					$regel = 'C';
				}
			}

			if ( '' !== $regel ) {
				$row['regel'] = $regel;
				$drop[] = $row;
				continue;
			}
			if ( ! empty( $seen_sig[ $sig ] ) ) {
				$row['anzahl'] = $seen_sig[ $sig ] + 1;
				$manual[ $sig ] = $row;
			}
			$seen_sig[ $sig ] = ( $seen_sig[ $sig ] ?? 0 ) + 1;
			$keep[] = $it;
		}
		return array( 'behalten' => $keep, 'entfernen' => $drop, 'manuell' => array_values( $manual ) );
	}

	/**
	 * Rein lokal schreiben: items_json ohne die Kopien, Kopfsummen neu, UIDs auf die Sperrliste.
	 * KEIN touch() — sonst liefe ein Push mit geändertem Kopf zum Desk. rev/last_synced bleiben,
	 * der nächste Desk-Push gewinnt wie bisher.
	 */
	private static function write( $o, array $plan ): bool {
		global $wpdb;
		$items  = array_values( $plan['behalten'] );
		$extras = json_decode( (string) $o->extras_json, true );
		$extras = is_array( $extras ) ? $extras : array();
		$cust   = json_decode( (string) $o->customer_json, true );
		$cust   = is_array( $cust ) ? $cust : array();
		$bd     = M24_Offers::compute_totals( $items, $extras, (string) $o->tax_mode, (float) $o->tax_rate, (string) ( $cust['land'] ?? '' ) );

		$ok = $wpdb->update( M24_Offers::table(), array(
			'items_json'   => wp_json_encode( $items ),
			'subtotal_net' => $bd['net'] + $bd['st25a'],
			'tax_amount'   => $bd['tax'],
			'total_gross'  => $bd['total'],
		), array( 'id' => (int) $o->id ) );
		if ( false === $ok ) { return false; }

		$sup = get_option( self::OPT_SUPPRESS, array() );
		$sup = is_array( $sup ) ? $sup : array();
		$cur = isset( $sup[ (int) $o->id ] ) && is_array( $sup[ (int) $o->id ] ) ? $sup[ (int) $o->id ] : array();
		foreach ( $plan['entfernen'] as $e ) {
			if ( '' !== (string) $e['line_uid'] ) { $cur[] = (string) $e['line_uid']; }
		}
		$sup[ (int) $o->id ] = array_values( array_unique( $cur ) );
		update_option( self::OPT_SUPPRESS, $sup, false );

		if ( class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'maintenance', 'info', 'Positions-Dubletten lokal ausgeblendet', array(
				'offer_no' => (string) $o->offer_no, 'ausgeblendet' => count( $plan['entfernen'] ), 'verbleibend' => count( $items ),
			) );
		}
		return true;
	}

	private static function load( array $ids ): array {
		global $wpdb;
		$t = M24_Offers::table();
		if ( empty( $ids ) ) {
			return (array) $wpdb->get_results( "SELECT * FROM $t WHERE deleted_at IS NULL ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			$rows = array_merge( $rows, (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE id IN ($ph)", $pks ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( ! empty( $nos ) ) {
			$ph = implode( ',', array_fill( 0, count( $nos ), '%s' ) );
			$rows = array_merge( $rows, (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE offer_no IN ($ph)", $nos ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $rows;
	}

	private static function norm( string $s ): string {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	private static function eur( float $v ): string {
		return number_format( $v, 2, ',', '.' ) . ' €';
	}
}
