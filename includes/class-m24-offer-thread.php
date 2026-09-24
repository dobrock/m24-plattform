<?php
/**
 * M24 — Verlauf am Angebot: Spiegel der Desk-Entitaet `thread`.
 *
 * Seit dem Mail-Rueckbau im Desk (24.09.) ist Gmail `m24desk@` das Postfach; Daniel liest und
 * antwortet in Apple Mail. Der Desk fuehrt am Auftrag nur noch den VERLAUF — Kundenantworten und
 * Ereignisse —, und spiegelt ihn hierher, damit an der WP-Angebotskarte dasselbe steht.
 *
 * Vertrag (BRIDGE_App.md, 22.09. 09:15 + Korrektur 24.09. 16:20):
 *   - NUR Desk → WP. Es gibt keine Gegenrichtung und deshalb auch keine Konfliktregel.
 *   - APPEND-ONLY. Ein Eintrag wird nie geaendert und nie geloescht: kein updated_at, kein rev,
 *     kein deleted_at, keine Tombstones, kein LWW.
 *   - SCHLUESSEL ist desk_id (comm_thread.id), nicht msg_id. Eine Message-ID hat nur, was aus einem
 *     Postfach gelesen wurde — 12 von 349 Zeilen. Ereignisse haben grundsaetzlich keine.
 *   - Eine bereits bekannte desk_id ist ein No-Op, kein Fehler.
 *
 * WP schreibt hier ausschliesslich lesend weiter: es gibt keinen Weg, aus WP zu antworten. Der
 * Verlauf ist ein Beleg, kein Postfach.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Thread {

	/**
	 * Erlaubte Eintragsarten. `email` ist eine Nachricht, alles andere ein Ereignis, das der Desk
	 * beim Versenden erzeugt hat. `test` steht ausdruecklich mit drin: der Wert existiert im
	 * Bestand (eine Zeile), und ein unbekannter Typ soll nicht stillschweigend zur Mail werden.
	 */
	const TYPES = array( 'email', 'offer_sent', 'payment', 'shipped', 'followup', 'doc_sent', 'test' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'm24_offer_thread';
	}

	/** Ist eine Nachricht (links/rechts als Sprechblase) oder ein Ereignis (schmale Zeile)? */
	public static function ist_nachricht( string $type ): bool {
		return 'email' === $type;
	}

	/**
	 * Einen Verlaufseintrag uebernehmen.
	 *
	 * @return array{ok:bool,reason:string} reason: '' | 'noop' | 'offer_unknown' | 'bad_record' | 'db_error'
	 */
	public static function add( array $rec ): array {
		global $wpdb;
		$desk_id = (int) ( $rec['desk_id'] ?? 0 );
		if ( $desk_id <= 0 ) { return array( 'ok' => false, 'reason' => 'bad_record' ); }

		$uid = trim( (string) ( $rec['wp_offer_uid'] ?? '' ) );
		if ( '' === $uid ) { return array( 'ok' => false, 'reason' => 'bad_record' ); }

		$t = self::table();

		// Schon da? Der Unique-Index faengt es ohnehin ab, aber ein sauberes 'noop' ist die
		// vertraglich vereinbarte Quittung — und der Desk soll an einer Wiederholung erkennen,
		// dass sie angekommen und nicht gescheitert ist.
		$have = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE desk_id = %d LIMIT 1", $desk_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $have > 0 ) { return array( 'ok' => false, 'reason' => 'noop' ); }

		// Das Angebot MUSS es geben — ein Verlauf ohne Vorgang ist nirgends sichtbar und waere nur
		// eine Zeile, die niemand je wiederfindet. Laut Vertrag NICHT puffern: der Desk schickt den
		// Eintrag beim naechsten Lauf erneut, und bis dahin ist der Auftrag vermutlich gespiegelt.
		$offer_id = self::offer_id_for_uid( $uid );
		if ( $offer_id <= 0 ) { return array( 'ok' => false, 'reason' => 'offer_unknown' ); }

		$type = sanitize_key( (string) ( $rec['type'] ?? 'email' ) );
		if ( ! in_array( $type, self::TYPES, true ) ) { $type = 'email'; }

		$row = array(
			'desk_id'      => $desk_id,
			'wp_offer_uid' => $uid,
			'offer_id'     => $offer_id,
			'dir'          => ( 'out' === (string) ( $rec['dir'] ?? '' ) ) ? 'out' : 'in',
			'type'         => $type,
			'from_addr'    => mb_substr( sanitize_text_field( (string) ( $rec['from_addr'] ?? '' ) ), 0, 190 ),
			// Der Desk liefert den Text bereits bereinigt (MIME-Ruempfe raus, HTML zu Text, Zitate
			// ab). WP putzt nicht nach — es wuerde nur eine zweite, abweichende Lesart erzeugen.
			// wp_kses_post waere hier falsch: das ist Text, kein HTML, und wird auch so gerendert.
			'body'         => (string) ( $rec['body'] ?? '' ),
			'msg_id'       => '' !== trim( (string) ( $rec['msg_id'] ?? '' ) ) ? mb_substr( sanitize_text_field( (string) $rec['msg_id'] ), 0, 255 ) : null,
			'mail_date'    => self::zeit( (string) ( $rec['mail_date'] ?? '' ) ),
			'created_at'   => self::zeit( (string) ( $rec['created_at'] ?? '' ) ),
			'synced_at'    => current_time( 'mysql', true ),
		);
		if ( false === $wpdb->insert( $t, $row ) ) {
			// Wettlauf zweier Laeufe: der Unique-Index hat gewonnen. Das ist genau der Fall, fuer den
			// er da ist — als 'noop' quittieren, nicht als Fehler.
			$again = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE desk_id = %d LIMIT 1", $desk_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $again > 0 ? array( 'ok' => false, 'reason' => 'noop' ) : array( 'ok' => false, 'reason' => 'db_error' );
		}
		return array( 'ok' => true, 'reason' => '' );
	}

	/** ISO-8601 → MySQL-UTC, leer/unlesbar → null (mail_date ist bei Ereignissen regulaer leer). */
	private static function zeit( string $iso ): ?string {
		$iso = trim( $iso );
		if ( '' === $iso ) { return null; }
		if ( class_exists( 'M24_Sync_LWW' ) ) {
			$v = M24_Sync_LWW::from_iso( $iso );
			if ( '' !== (string) $v ) { return (string) $v; }
		}
		$ts = strtotime( $iso );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
	}

	private static function offer_id_for_uid( string $uid ): int {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . M24_Offers::table() . ' WHERE wp_offer_uid = %s LIMIT 1', $uid ) ); // phpcs:ignore WordPress.DB
		// Rueckfall auf die Bauform der uid: eine Zeile, deren Spalte noch leer ist, gibt es im
		// Altbestand — sie traegt die uid erst nach dem naechsten init_row().
		if ( $id <= 0 && preg_match( '/^wpoffer_(\d+)$/', $uid, $m ) ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . M24_Offers::table() . ' WHERE id = %d LIMIT 1', (int) $m[1] ) ); // phpcs:ignore WordPress.DB
		}
		return $id;
	}

	/* ── Lesen ────────────────────────────────────────────────────────────── */

	/**
	 * Verlauf eines Angebots, aelteste zuerst.
	 *
	 * Sortiert ueber COALESCE(mail_date, created_at) — genau wie der Desk. Nach mail_date allein
	 * zu sortieren waere falsch: Ereignisse haben keins und stuenden alle am Anfang.
	 */
	public static function for_offer( int $offer_id ): array {
		global $wpdb;
		if ( $offer_id <= 0 ) { return array(); }
		$t = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM $t WHERE offer_id = %d ORDER BY COALESCE(mail_date, created_at) ASC, id ASC",
			$offer_id
		) );
	}

	/**
	 * Verlauf fuer eine ganze Liste, nach offer_id gruppiert — EINE Abfrage statt einer je Karte.
	 *
	 * Die Angebotsliste zeigt bis zu 50 Karten; je Karte einzeln zu fragen waere 50 Abfragen fuer
	 * einen Block, den meistens niemand aufklappt.
	 */
	public static function for_offers( array $offer_ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $offer_ids ) ) ) );
		if ( empty( $ids ) ) { return array(); }
		$t    = self::table();
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			"SELECT * FROM $t WHERE offer_id IN ($in) ORDER BY COALESCE(mail_date, created_at) ASC, id ASC",
			$ids
		) );
		$out = array();
		foreach ( (array) $rows as $r ) { $out[ (int) $r->offer_id ][] = $r; }
		return $out;
	}

	/* ── Darstellung (Angebotskarte, nur lesend) ──────────────────────────── */

	/** Einmalige Stile fuer den Verlaufsblock. Wird je Seite einmal ausgegeben. */
	public static function styles(): string {
		return '<style>'
			. '.m24thr{display:flex;flex-direction:column;gap:8px;padding:10px 2px 2px}'
			. '.m24thr-day{align-self:center;font-size:11px;color:#8a929c;background:#f3f4f6;border-radius:999px;padding:2px 10px;margin:4px 0}'
			. '.m24thr-msg{max-width:78%;border-radius:12px;padding:8px 12px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}'
			. '.m24thr-in{align-self:flex-start;background:#f3f4f6;color:#1f2328;border-bottom-left-radius:4px}'
			. '.m24thr-out{align-self:flex-end;background:#0e447e;color:#fff;border-bottom-right-radius:4px}'
			. '.m24thr-meta{display:block;font-size:11px;opacity:.75;margin-bottom:3px;font-weight:600}'
			. '.m24thr-ev{align-self:center;display:flex;align-items:center;gap:7px;font-size:11.5px;color:#6b7280}'
			. '.m24thr-ev::before{content:"";width:6px;height:6px;border-radius:50%;background:#c7ccd4;flex:0 0 auto}'
			. '.m24thr-empty{color:#8a929c;font-size:12.5px;padding:8px 2px}'
			. '</style>';
	}

	/** Lesbares Etikett je Ereignisart. Unbekanntes bleibt der rohe Wert — lieber roh als erfunden. */
	private static function ereignis_label( string $type ): string {
		$map = array(
			'offer_sent' => 'Angebot versendet',
			'payment'    => 'Zahlung',
			'shipped'    => 'Versand',
			'followup'   => 'Nachfassen',
			'doc_sent'   => 'Dokument versendet',
			'test'       => 'Test',
		);
		return $map[ $type ] ?? $type;
	}

	private static function stamp( $e ): int {
		$v = ! empty( $e->mail_date ) ? (string) $e->mail_date : (string) ( $e->created_at ?? '' );
		return '' !== $v ? (int) strtotime( $v . ' UTC' ) : 0;
	}

	private static function datum( int $ts, string $fmt ): string {
		if ( ! $ts ) { return ''; }
		return function_exists( 'wp_date' ) ? (string) wp_date( $fmt, $ts ) : gmdate( $fmt, $ts );
	}

	/**
	 * Der Verlauf als Gespraech — dieselbe Form wie im Desk: Kunde links grau, eigene Antwort
	 * rechts blau, Ereignisse als schmale Zeile, Datumstrenner je Tag.
	 *
	 * Bewusst ohne Antwortfeld. Geantwortet wird in Apple Mail; ein Eingabefeld hier waere ein
	 * zweiter Absendeweg fuer denselben Vorgang — und der Desk kennt ihn nicht.
	 */
	public static function render( array $eintraege ): string {
		if ( empty( $eintraege ) ) { return '<div class="m24thr-empty">Noch keine Nachrichten.</div>'; }
		$h    = '<div class="m24thr">';
		$tag  = '';
		foreach ( $eintraege as $e ) {
			$ts  = self::stamp( $e );
			$d   = self::datum( $ts, 'd.m.Y' );
			if ( '' !== $d && $d !== $tag ) {
				$h  .= '<span class="m24thr-day">' . esc_html( $d ) . '</span>';
				$tag = $d;
			}
			$zeit = self::datum( $ts, 'H:i' );
			$type = (string) $e->type;

			if ( ! self::ist_nachricht( $type ) ) {
				$h .= '<span class="m24thr-ev">' . esc_html( self::ereignis_label( $type ) )
					. ( '' !== $zeit ? ' · ' . esc_html( $zeit ) : '' ) . '</span>';
				continue;
			}
			$out  = ( 'out' === (string) $e->dir );
			$wer  = $out ? 'Wir' : ( '' !== (string) $e->from_addr ? (string) $e->from_addr : 'Kunde' );
			$body = trim( (string) $e->body );
			$h   .= '<div class="m24thr-msg ' . ( $out ? 'm24thr-out' : 'm24thr-in' ) . '">'
				. '<span class="m24thr-meta">' . esc_html( $wer ) . ( '' !== $zeit ? ' · ' . esc_html( $zeit ) : '' ) . '</span>'
				. esc_html( '' !== $body ? $body : '—' )
				. '</div>';
		}
		return $h . '</div>';
	}
}
