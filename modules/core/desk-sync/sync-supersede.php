<?php
/**
 * M24 Plattform — Bidirektionale LWW-Sync: Supersede (Spec v1.3 §3).
 *
 * Ein bereits versendetes Angebot ist ein Dokument, das beim Kunden liegt. Ändern sich danach Positionen,
 * Preise oder die Adresse, darf man das nicht still am selben Angebot nachziehen: der Kunde hat ein PDF mit
 * anderen Zahlen in der Hand, und sein Zugriffslink zeigte plötzlich etwas anderes als das, was er bekommen
 * hat. Stattdessen wird ersetzt:
 *
 *   1. Altes Angebot → Papierkorb (Soft-Delete, 10-Tage-Retention). Das archivierte PDF bleibt als Beleg,
 *      der Kunden-Link ist tot (Guard in M24_Offers_Render::customer).
 *   2. Neues Angebot mit NEUER Nummer und neuem Token, Positionen/Adresse aus dem aktuellen Stand,
 *      Summen frisch gerechnet.
 *   3. Das PDF erzeugt der Desk: WP pusht das neue Angebot per W1, dort wird gerendert und in order_docs
 *      archiviert — genau wie beim Erstversand. WP hat keinen Angebots-Renderer (bestätigt 26.08., v1.3).
 *   4. supersedes / superseded_by verknüpfen beide Richtungen.
 *   5. needs_resend=true. KEIN automatischer Mailversand — wer ein Angebot ersetzt, schaut vorher drauf.
 *      Der Versand läuft über den vorhandenen „Erneut senden"-Button.
 *
 * Nicht jede Änderung löst das aus: Status, Zahlung, Tracking und Carrier sind Abwicklungsdaten und ändern
 * nichts am Angebot. Nur Positionen/Preise und die Adresse zählen.
 *
 * @package M24_Plattform
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Sync_Supersede {

	/** Verzögerter W1-Push des Ersatz-Angebots. */
	const EVENT = 'm24_sync_supersede_push';

	/**
	 * Platzhalter in superseded_by, mit dem ein Lauf das Angebot beansprucht, bevor der Ersatz existiert.
	 * Wird von execute() durch die echte uid ersetzt. Bewusst ein Wert, der nie eine gültige uid ist.
	 */
	const CLAIM = '__wird_ersetzt__';

	/**
	 * Nur diese Stati gelten als „draußen beim Kunden". Storniert und erledigt lösen keinen Supersede aus —
	 * da ist der Vorgang beendet und ein Ersatz-Angebot wäre Unsinn (bestätigt 26.08.).
	 */
	const SENT_STATUS = array( 'offen', 'versandt', 'angenommen' );

	public static function init() {
		add_action( self::EVENT, array( __CLASS__, 'push_replacement' ), 10, 1 );
	}

	/**
	 * Ist das Angebot bereits beim Kunden? `sent_at` ist maßgeblich — der Desk-seitige Test
	 * („archiviertes order_docs-PDF existiert") ist von WP aus nicht abfragbar.
	 */
	public static function is_sent( $o ): bool {
		return ! empty( $o->sent_at ) && in_array( (string) $o->status, self::SENT_STATUS, true );
	}

	/**
	 * Prüfen und ggf. ersetzen. Wird EINMAL pro Angebot am Ende einer Apply-Charge gerufen, nicht je
	 * geänderter Zeile — sonst entstünden bei einem Edit an drei Positionen drei Ersatz-Angebote.
	 *
	 * @param int    $offer_id Das geänderte (alte) Angebot.
	 * @param string $reason   Was sich geändert hat, fürs Log.
	 * @return int|null Neue Angebots-ID, oder null wenn kein Supersede nötig/möglich war.
	 */
	public static function maybe_supersede( int $offer_id, string $reason = '' ): ?int {
		global $wpdb;
		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! $o ) { return null; }
		$old_id = (int) $o->id;

		// Noch nicht versendet → Positions-/Adress-Edits fließen normal per LWW, das PDF entsteht
		// ohnehin erst beim Versand.
		if ( ! self::is_sent( $o ) ) { return null; }

		// Idempotenz (§3): ein bereits ersetztes Angebot wird nicht ein zweites Mal ersetzt. Ohne diesen
		// Guard erzeugte jeder weitere Sync-Lauf eine neue Kopie.
		if ( '' !== trim( (string) $o->superseded_by ) ) {
			self::log( 'skip_already', self::CLAIM === (string) $o->superseded_by
				? (string) $o->offer_no . ': ein Ersatz wird gerade angelegt.'
				: (string) $o->offer_no . ' ist bereits ersetzt durch ' . (string) $o->superseded_by );
			return null;
		}
		if ( ! empty( $o->deleted_at ) ) {
			self::log( 'skip_deleted', (string) $o->offer_no . ' liegt im Papierkorb — nichts zu ersetzen.' );
			return null;
		}
		// Das Angebot IST bereits ein Ersatz (trägt supersedes) und wurde noch nicht versendet: dann
		// steht der Neuversand ohnehin aus, ein weiterer Ersatz erzeugte nur eine Kette von Karteileichen.
		if ( '' !== trim( (string) $o->supersedes ) && ! empty( $o->needs_resend ) ) {
			self::log( 'skip_pending', (string) $o->offer_no . ' wartet selbst noch auf den Neuversand — kein zweiter Ersatz.' );
			return null;
		}
		// Dubletten-Schutz: Teilen sich zwei aktive Zeilen dieselbe Desk-Order, ist unklar, welche die
		// gültige ist — dann wird nicht ersetzt, sondern gemeldet. Ein Supersede würde die Verwirrung
		// verdoppeln statt sie aufzulösen (siehe 2026-1044/1045).
		if ( '' !== trim( (string) $o->desk_order_id ) ) {
			global $wpdb;
			$twin = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COUNT(*) FROM ' . M24_Offers::table() . ' WHERE desk_order_id = %s AND deleted_at IS NULL',
				(string) $o->desk_order_id
			) );
			if ( $twin > 1 ) {
				self::log( 'skip_duplicate', sprintf(
					'%s: %d aktive Angebote teilen Desk-Auftrag #%s — kein Supersede, bitte zuerst die Dublette klären.',
					(string) $o->offer_no, $twin, (string) $o->desk_order_id
				) );
				if ( class_exists( 'M24_Error_Log' ) ) {
					M24_Error_Log::capture( 'sync_supersede', 'warning', 'Supersede wegen Dublette ausgesetzt', array(
						'offer_no' => (string) $o->offer_no, 'desk_order_id' => (string) $o->desk_order_id, 'aktive' => $twin,
					) );
				}
				return null;
			}
		}

		// ── Vorhandenen, noch nicht versendeten Nachfolger WIEDERVERWENDEN statt einen zweiten anzulegen.
		// Trägt schon ein Angebot supersedes = diese uid und wartet auf den Versand, ist der Ersatz da;
		// ein weiterer wäre nur eine Karteileiche mehr.
		$pending = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT id, offer_no FROM ' . M24_Offers::table()
			. ' WHERE supersedes = %s AND deleted_at IS NULL AND sent_at IS NULL ORDER BY id ASC LIMIT 1',
			(string) $o->wp_offer_uid
		) );
		if ( $pending ) {
			// Rückverweis nachziehen, falls er beim vorherigen Lauf nicht ankam — sonst zeigt das alte
			// Angebot ins Leere und der Idempotenz-Guard oben greift beim nächsten Mal wieder nicht.
			if ( '' === trim( (string) $o->superseded_by ) ) {
				$wpdb->update( M24_Offers::table(), array( 'superseded_by' => M24_Sync_LWW::offer_uid( (int) $pending->id ) ), array( 'id' => $old_id ) );
			}
			self::log( 'reuse_pending', sprintf(
				'%s: Ersatz %s existiert bereits und wartet auf den Versand — kein zweiter angelegt.',
				(string) $o->offer_no, (string) $pending->offer_no
			) );
			return (int) $pending->id;
		}

		// ── Atomar beanspruchen, BEVOR etwas entsteht.
		// Der Guard oben liest superseded_by, geschrieben wird es aber erst am Ende von execute() —
		// dazwischen liegen mehrere DB-Operationen. Zwei Apply-Läufe in diesem Fenster sehen beide ein
		// leeres Feld und legen beide an. Genau so sind 2026-1052 und 2026-1053 entstanden, beide mit
		// "ersetzt 2026-1051".
		//
		// Das bedingte UPDATE entscheidet den Wettlauf in der Datenbank: nur EIN Lauf trifft eine Zeile
		// mit leerem superseded_by, alle weiteren bekommen 0 betroffene Zeilen und steigen aus.
		$claimed = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			'UPDATE ' . M24_Offers::table() . " SET superseded_by = %s WHERE id = %d AND ( superseded_by = '' OR superseded_by IS NULL )",
			self::CLAIM, $old_id
		) );
		if ( ! $claimed ) {
			self::log( 'skip_race', (string) $o->offer_no . ': ein paralleler Lauf legt den Ersatz bereits an.' );
			return null;
		}

		$new_id = self::execute( $o, $reason );
		if ( null === $new_id ) {
			// Anlage gescheitert → Anspruch zurückgeben, sonst bleibt das Angebot für immer gesperrt.
			$wpdb->update( M24_Offers::table(), array( 'superseded_by' => '' ), array( 'id' => $old_id, 'superseded_by' => self::CLAIM ) );
		}
		return $new_id;
	}

	/** Führt die Ersetzung aus. */
	private static function execute( $o, string $reason ): ?int {
		global $wpdb;
		$t   = M24_Offers::table();
		$old = (int) $o->id;

		// ── Neues Angebot als Kopie des AKTUELLEN Stands (die Sync-Änderung ist bereits eingespielt).
		$new_no    = M24_Offers::next_number();
		$new_token = bin2hex( random_bytes( 16 ) );
		$valid     = gmdate( 'Y-m-d', time() + ( (int) M24_Offers::VALID_DAYS * DAY_IN_SECONDS ) );

		$row = array(
			'offer_no'      => $new_no,
			'token'         => $new_token,
			'account_id'    => (int) $o->account_id,
			// 'offen', damit das Ersatz-Angebot in der Liste als versandbereit erscheint und der
			// „Erneut senden"-Button greift. sent_at bleibt NULL — es ist ja noch nicht raus, und damit
			// löst es auch selbst keinen weiteren Supersede aus.
			'status'        => 'offen',
			'customer_json' => (string) $o->customer_json,
			'items_json'    => (string) $o->items_json,
			'extras_json'   => (string) $o->extras_json,
			'delivery_time' => (string) $o->delivery_time,
			'tax_mode'      => (string) $o->tax_mode,
			'tax_rate'      => (float) $o->tax_rate,
			'tax_note'      => (string) $o->tax_note,
			'subtotal_net'  => (float) $o->subtotal_net,
			'tax_amount'    => (float) $o->tax_amount,
			'total_gross'   => (float) $o->total_gross,
			'currency'      => (string) $o->currency,
			'valid_until'   => $valid,
			'src_json'      => (string) $o->src_json,
			'customer_uid'  => (string) $o->customer_uid, // derselbe Kunde
			'supersedes'    => (string) $o->wp_offer_uid,
			'needs_resend'  => 1,
			'created_at'    => current_time( 'mysql', true ),
			'sent_at'       => null,
		);
		// Adressspalten mitnehmen — der Ersatz soll dieselbe Rechnungs-/Lieferanschrift tragen.
		foreach ( array( 'bill_anrede', 'bill_vorname', 'bill_nachname', 'bill_firma', 'bill_ustid', 'bill_ustid_vies', 'bill_eori', 'bill_strasse', 'bill_plz', 'bill_ort', 'bill_land', 'bill_telefon',
			'ship_diff', 'ship_anrede', 'ship_vorname', 'ship_nachname', 'ship_firma', 'ship_ustid', 'ship_strasse', 'ship_strasse2', 'ship_plz', 'ship_ort', 'ship_land', 'ship_telefon' ) as $col ) {
			$row[ $col ] = $o->$col;
		}
		// deleted_lines_json bewusst NICHT kopieren: die Tombstones gehören zur Historie des alten
		// Angebots. Das neue startet mit genau den Positionen, die es hat.

		if ( false === $wpdb->insert( $t, $row ) ) {
			self::log( 'failed', (string) $o->offer_no . ' — Insert des Ersatz-Angebots fehlgeschlagen: ' . $wpdb->last_error );
			return null;
		}
		$new_id = (int) $wpdb->insert_id;
		if ( $new_id <= 0 ) { return null; }

		M24_Sync_LWW::init_row( $new_id, 'wp', (int) $o->account_id );
		$new_uid = M24_Sync_LWW::offer_uid( $new_id );

		// ── Altes Angebot: Papierkorb + Rückverweis. Der Kunden-Link ist damit tot (Guard in
		// M24_Offers_Render::customer), das archivierte PDF bleibt als Beleg bestehen.
		$wpdb->update( $t, array(
			'deleted_at'    => current_time( 'mysql', true ),
			'superseded_by' => $new_uid, // ersetzt den CLAIM-Platzhalter aus maybe_supersede()
		), array( 'id' => $old ) );
		M24_Sync_LWW::touch( $old, 'wp' );

		// ── Das PDF erzeugt der Desk. W1 entkoppelt einplanen, NICHT hier direkt: wir stecken mitten in
		// einer Apply-Charge, deren Echo-Schutz jeden Push unterdrückt — und ein wartender Desk-Call
		// würde den laufenden Request in die Länge ziehen.
		if ( ! wp_next_scheduled( self::EVENT, array( $new_id ) ) ) {
			wp_schedule_single_event( time() + 10, self::EVENT, array( $new_id ) );
		}

		self::log( 'superseded', sprintf(
			'%s → %s (neue ID %d)%s · altes Angebot im Papierkorb, Neuversand nötig.',
			(string) $o->offer_no, $new_no, $new_id, '' !== $reason ? ' · Auslöser: ' . $reason : ''
		) );
		if ( class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'sync_supersede', 'info', 'Angebot ersetzt — Neuversand nötig', array(
				'alt' => (string) $o->offer_no, 'neu' => $new_no, 'grund' => $reason,
			) );
		}
		return $new_id;
	}

	/**
	 * W1-Push des Ersatz-Angebots (Cron-Kontext). Der Desk legt den Auftrag an, rendert das Angebots-PDF
	 * und archiviert es in order_docs — derselbe Weg wie beim Erstversand.
	 *
	 * Bewusst M24_Desk_Push::push() statt do_action('m24_offer_sent'): der Trigger würde bei aktivem
	 * Sync-Apply am Echo-Schutz abprallen, und ein „gesendet"-Signal wäre hier ohnehin falsch — die Mail
	 * an den Kunden ist noch nicht raus.
	 */
	public static function push_replacement( $offer_id ): void {
		$offer_id = (int) $offer_id;
		if ( $offer_id <= 0 || ! class_exists( 'M24_Desk_Push' ) ) { return; }
		if ( ! M24_Desk_Push::enabled() ) {
			self::log( 'push_skipped', 'Desk-Sync nicht scharf — Ersatz-Angebot ' . $offer_id . ' bleibt ohne PDF, bis gepusht wird.' );
			return;
		}
		$res = M24_Desk_Push::push( $offer_id, false );
		self::log( empty( $res['ok'] ) ? 'push_failed' : 'push_ok', 'Ersatz-Angebot ' . $offer_id . ' → ' . (string) ( $res['note'] ?? '' ) );
	}

	private static function log( string $step, string $msg ): void {
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'sync_supersede', $step, array( 'msg' => $msg ) );
		}
	}
}
