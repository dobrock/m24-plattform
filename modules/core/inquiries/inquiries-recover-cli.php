<?php
/**
 * M24 Plattform — Anfragen: Nachlauf fuer haengengebliebene Desk-Pushs (WP-CLI).
 * Modul: modules/core/inquiries/inquiries-recover-cli.php
 *
 * Auswahlkriterium ist EINE Tatsache: keine _m24_desk_order_num. Drueben existiert dann kein
 * Auftrag, egal was der Status hier behauptet. Der Status ist nur noch Zusatzinformation und
 * steht je Zeile in der Ausgabe, damit erkennbar bleibt, welcher Fall vorliegt.
 *
 * Der Status als Filter griff zu kurz: #34967 (Douglas Wong, 21.08.) steht auf pending_api_push,
 * ohne Push-Versuch, ohne Fehler, ohne Desk-Nummer — das Event ist schlicht verloren gegangen,
 * dasselbe Muster wie der seit 05.07. faellige Retry von #34814. Ein Filter auf
 * synced_via_mail/sync_failed liess genau diese Faelle durchfallen.
 *
 *     wp m24 inquiry-recover                  Trockenlauf ueber alle haengenden
 *     wp m24 inquiry-recover --ids=34814,34818
 *     wp m24 inquiry-recover --go             senden
 *
 * Trockenlauf ist die Vorgabe. Dublettenschutz doppelt: Eintraege mit Desk-Nummer werden
 * uebersprungen, und run_push() prueft es selbst noch einmal.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Inquiries_Recover {

	/**
	 * Alle vier M24-Status — pending_api_push ausdruecklich eingeschlossen.
	 *
	 * Bewusst eine explizite Liste statt 'any': 'any' haengt an exclude_from_search der jeweiligen
	 * Status-Registrierung und wuerde Faelle still verschlucken. Der Papierkorb bleibt so ebenfalls
	 * aussen vor, ohne dass man sich darauf verlassen muss.
	 */
	public static function stati(): array {
		return array(
			M24_Inquiries::STATUS_PENDING,
			M24_Inquiries::STATUS_SYNCED,
			M24_Inquiries::STATUS_SYNCED_MAIL,
			M24_Inquiries::STATUS_FAILED,
		);
	}

	/** Haengende Anfragen: keine Desk-Nummer. Der Status entscheidet nicht mehr mit. */
	public static function find( int $limit = 200 ): array {
		$q = new WP_Query( array(
			'post_type'      => M24_Inquiries_Storage::CPT_SLUG,
			'post_status'    => self::stati(),
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array( 'key' => '_m24_desk_order_num', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_m24_desk_order_num', 'value' => '', 'compare' => '=' ),
			),
			'no_found_rows'  => true,
		) );
		return $q->posts ?: array();
	}

	/**
	 * Einen Eintrag erneut pushen.
	 *
	 * @return array{ergebnis:string,text:string}
	 */
	public static function recover_one( int $post_id ): array {
		$num = trim( (string) get_post_meta( $post_id, '_m24_desk_order_num', true ) );
		if ( '' !== $num ) {
			return array( 'ergebnis' => 'uebersprungen', 'text' => 'bereits im Desk (' . $num . ')' );
		}

		// Zaehler zuruecksetzen. Die Altlast steht bei 60+ Versuchen und liefe sofort gegen
		// MAX_ATTEMPTS — die Versuche gingen aber auf den Trigger-Bug, nicht auf die Anfrage.
		delete_post_meta( $post_id, '_m24_push_attempts' );
		delete_post_meta( $post_id, '_m24_push_last_error' );
		delete_post_meta( $post_id, '_m24_push_next_retry' );

		// Status zurueck auf pending — Voraussetzung fuer den Status-Guard in run_push().
		// Loest KEINEN zweiten Push aus: der Trigger haengt an m24_inquiry_created, nicht
		// mehr an transition_post_status.
		wp_update_post( array( 'ID' => $post_id, 'post_status' => M24_Inquiries::STATUS_PENDING ) );

		M24_Inquiries_Push::run_push( $post_id );

		$num = trim( (string) get_post_meta( $post_id, '_m24_desk_order_num', true ) );
		if ( '' !== $num ) {
			return array( 'ergebnis' => 'gesendet', 'text' => 'Desk-Nummer ' . $num );
		}

		$status = (string) get_post_status( $post_id );
		$err    = trim( (string) get_post_meta( $post_id, '_m24_push_last_error', true ) );
		if ( M24_Inquiries::STATUS_PENDING === $status ) {
			return array( 'ergebnis' => 'geplant', 'text' => 'Retry eingeplant' . ( '' !== $err ? ' — ' . $err : '' ) );
		}
		return array( 'ergebnis' => 'fehler', 'text' => $status . ( '' !== $err ? ' — ' . $err : '' ) );
	}

	/**
	 * @param int[] $ids Leer = alle haengenden.
	 * @return array{geprueft:int,zeilen:array,summe:array}
	 */
	public static function run( array $ids = array(), bool $go = false, int $limit = 200 ): array {
		$posts = array();
		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$p = get_post( (int) $id );
				if ( $p && M24_Inquiries_Storage::CPT_SLUG === $p->post_type ) { $posts[] = $p; }
			}
		} else {
			$posts = self::find( $limit );
		}

		$out = array( 'geprueft' => count( $posts ), 'zeilen' => array(), 'summe' => array() );
		foreach ( $posts as $p ) {
			$id   = (int) $p->ID;
			$base = sprintf(
				'#%d · %s · %s · %s',
				$id,
				mysql2date( 'd.m.Y H:i', (string) $p->post_date ),
				str_pad( (string) $p->post_status, 15 ),
				(string) $p->post_title
			);
			if ( ! $go ) {
				$out['zeilen'][] = $base;
				continue;
			}
			$r = self::recover_one( $id );
			$out['zeilen'][] = $base . '  →  ' . $r['ergebnis'] . ': ' . $r['text'];
			$out['summe'][ $r['ergebnis'] ] = ( $out['summe'][ $r['ergebnis'] ] ?? 0 ) + 1;
		}
		return $out;
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'm24 inquiry-recover', function ( $args, $assoc ) {
		$go    = ! empty( $assoc['go'] );
		$limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 200;
		$ids   = array();
		if ( ! empty( $assoc['ids'] ) ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) );
		}

		$r = M24_Inquiries_Recover::run( $ids, $go, $limit );
		WP_CLI::log( sprintf( '%s — %d Anfrage(n) ohne Desk-Nummer:', $go ? 'SENDEN' : 'TROCKENLAUF', $r['geprueft'] ) );
		foreach ( $r['zeilen'] as $z ) { WP_CLI::log( '  ' . $z ); }

		if ( ! $go ) {
			WP_CLI::success( 'Trockenlauf. Zum Senden: wp m24 inquiry-recover --go' );
			return;
		}
		$teile = array();
		foreach ( $r['summe'] as $k => $v ) { $teile[] = $v . ' ' . $k; }
		WP_CLI::success( empty( $teile ) ? 'Nichts zu tun.' : implode( ', ', $teile ) );
	} );
}
