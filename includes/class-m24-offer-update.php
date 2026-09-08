<?php
/**
 * M24 — „Angebot aktualisieren": nächste Fassung derselben Nummer.
 * Modul: includes/class-m24-offer-update.php
 *
 * Ersetzt die Nachfolger-Automatik. offer_no bleibt, offer_version zählt hoch, die abgelöste Fassung
 * wandert als Beleg in m24_offer_versions. Es entsteht KEINE neue Angebotsnummer und kein zweiter
 * Listeneintrag — genau die drei Einträge aus dem Vorfall „Brand the Build" sollen nicht mehr
 * entstehen können.
 *
 * Ablauf (Entscheidung A mit Vorschau): stage() schreibt die Fassung und stößt den Desk-Push an →
 * gate() wartet auf das Artefakt → erst danach zeigt die Vorschau die fertige Mail, und die
 * Bestätigung IN der Vorschau ist der Versand (send()). Den Versand löst ausschließlich ein
 * Mensch aus.
 *
 * OHNE ARTEFAKT KEIN VERSAND: Das Angebots-PDF kommt vom Desk. Fehlt es, bleibt die Fassung in
 * version_pending mit begründetem Hinweis an der Karte stehen — nie eine Angebotsmail ohne Anhang.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Offer_Update {

	/** Gründe für den einzigen erlaubten Zwischenzustand. */
	const PENDING_ARTIFACT = 'Desk nicht erreichbar — Angebots-PDF steht aus';
	const PENDING_STAGED   = 'Fassung geschrieben — Artefakt wird geholt';

	/**
	 * Nur diese Stati dürfen aktualisiert werden: raus beim Kunden, Vorgang nicht abgeschlossen.
	 * `abgelaufen` gehört dazu (07.09.): ein abgelaufenes Angebot zu erneuern IST der Kernfall der
	 * Fassung — gleiche Nummer, neue Frist ab Versand. release() setzt den Status dann zurück auf
	 * „offen", sonst stünde neben einer frischen 10-Tage-Frist weiter „Abgelaufen".
	 */
	const UPDATABLE = array( 'offen', 'versandt', 'abgelaufen' );

	public static function can_update( $o ): bool {
		return $o
			&& empty( $o->deleted_at )
			&& '' !== trim( (string) ( $o->offer_no ?? '' ) )
			&& in_array( (string) ( $o->status ?? '' ), self::UPDATABLE, true );
	}

	/**
	 * Nummernloser Entwurf desselben Kunden — der Zustand, in dem am 30.08. die 6. Position
	 * hängenblieb. Wird beim Öffnen als Inhalt übernommen und beim Schreiben der Fassung aufgelöst.
	 */
	public static function orphan_draft( $o ) {
		global $wpdb;
		$cuid  = trim( (string) ( $o->customer_uid ?? '' ) );
		$where = "status = 'entwurf' AND ( offer_no = '' OR offer_no IS NULL ) AND deleted_at IS NULL AND id <> %d";
		if ( '' !== $cuid ) {
			return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
				'SELECT * FROM ' . M24_Offers::table() . " WHERE {$where} AND customer_uid = %s ORDER BY id DESC LIMIT 1",
				(int) $o->id, $cuid
			) );
		}
		$cust  = json_decode( (string) ( $o->customer_json ?? '' ), true );
		$email = is_array( $cust ) ? trim( (string) ( $cust['email'] ?? '' ) ) : '';
		if ( '' === $email ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			'SELECT * FROM ' . M24_Offers::table() . " WHERE {$where} AND customer_json LIKE %s ORDER BY id DESC LIMIT 1",
			(int) $o->id, '%' . $wpdb->esc_like( $email ) . '%'
		) );
	}

	/**
	 * Editor-Vorbelegung: aktueller Stand des Angebots, überlagert vom nummernlosen Entwurf,
	 * falls es einen gibt. Der Entwurf wird hier NICHT aufgelöst — bricht Daniel ab, darf nichts
	 * verloren gehen.
	 */
	public static function prefill( int $offer_id ): ?array {
		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! self::can_update( $o ) ) { return null; }

		$src    = $o;
		$draft  = self::orphan_draft( $o );
		$absorb = 0;
		if ( $draft ) { $src = $draft; $absorb = (int) $draft->id; }

		$sj = json_decode( (string) ( $src->src_json ?? '' ), true );
		$sj = is_array( $sj ) ? $sj : array();

		return array(
			'offer_id'    => (int) $o->id,
			'offer_no'    => (string) $o->offer_no,
			'version'     => max( 1, (int) $o->offer_version ),
			'next'        => max( 1, (int) $o->offer_version ) + 1,
			'absorb_id'   => $absorb,
			'items'       => json_decode( (string) $src->items_json, true ) ?: array(),
			'extras'      => json_decode( (string) $src->extras_json, true ) ?: array(),
			'customer'    => json_decode( (string) $o->customer_json, true ) ?: array(),
			'delivery'    => (string) $src->delivery_time,
			'tax_mode'    => (string) $src->tax_mode,
			'tax_rate'    => (float) $src->tax_rate,
			'lang'        => ( 'en' === ( $sj['lang'] ?? '' ) ) ? 'en' : 'de',
			'anrede_form' => ( 'du' === ( $sj['anrede_form'] ?? 'sie' ) ) ? 'du' : 'sie',
			'salutation'  => (string) ( $sj['salutation'] ?? '' ),
			'note'        => (string) ( $sj['note'] ?? '' ),
		);
	}

	/**
	 * Nächste Fassung schreiben. Kein Versand, keine Mail — nur der Datenstand plus Desk-Push,
	 * damit drüben das Artefakt für DIESE Fassung entsteht.
	 *
	 * @param array $row Bereits geprüfte Inhaltsfelder (items_json, extras_json, Summen, …).
	 * @return array{ok:bool,version:int,msg:string}
	 */
	public static function stage( int $offer_id, array $row, int $absorb_id = 0 ): array {
		global $wpdb;
		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! self::can_update( $o ) ) {
			return array( 'ok' => false, 'version' => 0, 'msg' => 'Dieses Angebot kann nicht aktualisiert werden.' );
		}

		// 1) Die abgelöste Fassung als Beleg sichern, BEVOR sie überschrieben wird.
		M24_Offer_Versions::archive( $o );

		// 2) Fassung hochzählen. offer_no, token und wp_offer_uid bleiben unangetastet — daran hängt
		//    drüben ON CONFLICT (wp_offer_uid) DO UPDATE, also die Frage ob der Desk denselben
		//    Auftrag aktualisiert oder einen zweiten anlegt.
		$next = max( 1, (int) $o->offer_version ) + 1;
		$row['offer_version']          = $next;
		$row['version_pending']        = 1;
		$row['version_pending_reason'] = self::PENDING_STAGED;
		$row['version_pending_at']     = gmdate( 'Y-m-d H:i:s' );
		$row['valid_until']            = gmdate( 'Y-m-d', time() + M24_Offers::VALID_DAYS * DAY_IN_SECONDS );
		unset( $row['offer_no'], $row['token'], $row['id'], $row['wp_offer_uid'] );

		$wpdb->update( M24_Offers::table(), $row, array( 'id' => $offer_id ) );

		// 3) Nummernlosen Entwurf auflösen — er darf nicht als eigener Eintrag stehenbleiben.
		if ( $absorb_id > 0 ) {
			$wpdb->update( M24_Offers::table(), array( 'deleted_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $absorb_id ) );
		}

		// 4) LWW: lokale Änderung stempeln (rev+1), sonst lehnt der Desk die Fassung als veraltet ab.
		//    Das IST der Weg, auf dem die Fassung drüben ankommt (Sync-Push: Kopf + Positionen).
		if ( class_exists( 'M24_Sync_LWW' ) ) { M24_Sync_LWW::touch( $offer_id, 'wp' ); }

		// 5) W1-Hook. Bei bestehendem Desk-Auftrag greift dort der Create-only-Guard und setzt nur
		//    needs_update — kein zweiter POST, keine Dublette. Der Monitor zeigt das als Altlast an.
		do_action( 'm24_offer_sent', $offer_id );

		self::log( $offer_id, 'staged', 'Fassung ' . $next . ' geschrieben, Sync-Push angestoßen.' );
		return array( 'ok' => true, 'version' => $next, 'msg' => 'Fassung ' . $next . ' vorbereitet.' );
	}

	/**
	 * Artefakt-Gate. OHNE ARTEFAKT KEIN VERSAND.
	 *
	 * @return array{ok:bool,artifact:string,msg:string}
	 */
	public static function gate( int $offer_id ): array {
		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! $o ) { return array( 'ok' => false, 'artifact' => '', 'msg' => 'Angebot nicht gefunden.' ); }

		$att = class_exists( 'M24_Desk_Push' ) ? (array) M24_Desk_Push::offer_pdf_attachment( $o ) : array();
		if ( empty( $att ) ) {
			self::hold( $offer_id, self::PENDING_ARTIFACT );
			return array( 'ok' => false, 'artifact' => '', 'msg' => self::PENDING_ARTIFACT );
		}

		$name = (string) ( $att['filename'] ?? ( $att['name'] ?? 'angebot.pdf' ) );
		M24_Offer_Versions::set_artifact( $offer_id, max( 1, (int) $o->offer_version ), $name );
		return array( 'ok' => true, 'artifact' => $name, 'msg' => 'Artefakt liegt vor.' );
	}

	/** Fassung im einzigen erlaubten Zwischenzustand halten — begründet und an der Karte sichtbar. */
	public static function hold( int $offer_id, string $reason ): void {
		global $wpdb;
		$wpdb->update( M24_Offers::table(), array(
			'version_pending'        => 1,
			'version_pending_reason' => mb_substr( $reason, 0, 190 ),
			'version_pending_at'     => gmdate( 'Y-m-d H:i:s' ),
		), array( 'id' => $offer_id ) );
		self::log( $offer_id, 'pending', $reason );
	}

	/** Zwischenzustand auflösen — die Fassung ist raus. */
	public static function release( int $offer_id ): void {
		global $wpdb;
		$wpdb->update( M24_Offers::table(), array(
			'version_pending'        => 0,
			'version_pending_reason' => null,
			'version_pending_at'     => null,
			'sent_at'                => current_time( 'mysql', true ),
		), array( 'id' => $offer_id ) );
		// Ein erneuertes, vorher abgelaufenes Angebot ist mit dem Versand wieder offen: stage() hat
		// valid_until neu gesetzt, die Karte würde sonst „Abgelaufen" neben einer laufenden Frist zeigen.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'UPDATE ' . M24_Offers::table() . " SET status = 'offen' WHERE id = %d AND status = 'abgelaufen'",
			$offer_id
		) );
		if ( class_exists( 'M24_Offer_Drift' ) ) { M24_Offer_Drift::clear( $offer_id ); }
	}

	public static function is_pending( $o ): bool {
		return ! empty( $o->version_pending );
	}

	/**
	 * Die aktuelle Fassung an den Kunden senden. EINE Stelle für beide Auslöser: die Bestätigung im
	 * Vorschau-Dialog und „erneut auslösen" an der Karte. Prüft die Mail-Freigabe und das Artefakt
	 * selbst — kein Aufrufer darf am Gate vorbei.
	 *
	 * @return array{ok:bool,pending:bool,msg:string}
	 */
	public static function send_version( int $offer_id ): array {
		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! $o ) { return array( 'ok' => false, 'pending' => false, 'msg' => 'Angebot nicht gefunden.' ); }

		$allowed = M24_Offer_Update_Mail::send_allowed();
		if ( ! $allowed['ok'] ) {
			return array( 'ok' => false, 'pending' => true, 'msg' => $allowed['msg'] );
		}

		$gate = self::gate( $offer_id ); // setzt bei fehlendem Artefakt selbst den Zwischenzustand
		if ( ! $gate['ok'] ) {
			return array( 'ok' => false, 'pending' => true, 'msg' => $gate['msg'] . ' — kein Versand ohne Anhang.' );
		}

		$cust = json_decode( (string) $o->customer_json, true );
		$to   = is_array( $cust ) ? trim( (string) ( $cust['email'] ?? '' ) ) : '';
		if ( ! is_email( $to ) ) {
			return array( 'ok' => false, 'pending' => true, 'msg' => 'Keine gültige Kundenadresse am Angebot.' );
		}

		$hist = M24_Offer_Versions::history( $offer_id );
		$diff = M24_Offer_Versions::diff( $hist ? $hist[0] : $o, $o );
		$att  = class_exists( 'M24_Desk_Push' ) ? (array) M24_Desk_Push::offer_pdf_attachment( $o ) : array();
		$sent = wp_mail(
			$to,
			M24_Offer_Update_Mail::subject( $o ),
			M24_Offer_Update_Mail::render( $o, $diff ),
			array( 'Content-Type: text/html; charset=UTF-8' ),
			isset( $att['path'] ) ? array( $att['path'] ) : array()
		);
		if ( ! $sent ) {
			self::hold( $offer_id, 'Mailversand fehlgeschlagen' );
			return array( 'ok' => false, 'pending' => true, 'msg' => 'Versand fehlgeschlagen — die Fassung bleibt bereit.' );
		}

		self::release( $offer_id );
		$ver = max( 1, (int) $o->offer_version );
		self::log( $offer_id, 'sent', 'Fassung ' . $ver . ' an ' . $to . ' versendet.' );
		return array( 'ok' => true, 'pending' => false, 'msg' => 'Fassung ' . $ver . ' an ' . $to . ' versendet.' );
	}

	/**
	 * „Erneut auslösen" an der Karte: nur für eine bereitliegende Fassung. Legt nichts an und zählt
	 * nichts hoch — es ist derselbe Versand, nur ein zweiter Anlauf.
	 */
	public static function retry( int $offer_id ): array {
		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! $o ) { return array( 'ok' => false, 'msg' => 'Angebot nicht gefunden.' ); }
		if ( ! self::is_pending( $o ) ) {
			return array( 'ok' => false, 'msg' => 'Für dieses Angebot liegt keine unversendete Fassung bereit.' );
		}
		$r = self::send_version( $offer_id );
		return array( 'ok' => $r['ok'], 'msg' => $r['msg'] );
	}

	/* ── Mail-Vorschau / Testversand (Operator, ohne Kundenkontakt) ─────────────────────── */

	/**
	 * Vorschau der Fassungs-Mail im Browser bzw. Testversand an die EIGENE Adresse des angemeldeten
	 * Operators. Beides berührt den Kunden nicht und ist unabhängig von approved() — genau der Weg,
	 * den send_allowed() in Aussicht stellt („Vorschau und Testversand … sind möglich"). Vorher gab es
	 * ihn nur im Editor-Dialog nach dem Speichern; um ihn erneut zu sehen, hätte man Fassung 3 anlegen
	 * müssen.
	 */
	public static function preview_url( int $offer_id, bool $test = false ): string {
		$args = array( 'action' => 'm24_offer_update_preview', 'offer' => $offer_id );
		if ( $test ) { $args['test'] = 1; }
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'm24_offer_update_preview_' . $offer_id );
	}

	public static function handle_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Kein Zugriff.' ); }
		$id = (int) ( $_GET['offer'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'm24_offer_update_preview_' . $id );
		$o = M24_Offers::get_by_id( $id );
		if ( ! $o ) { wp_die( 'Angebot nicht gefunden.' ); }

		$hist = M24_Offer_Versions::history( $id );
		$diff = M24_Offer_Versions::diff( $hist ? $hist[0] : $o, $o );
		$subj = M24_Offer_Update_Mail::subject( $o );
		$html = M24_Offer_Update_Mail::render( $o, $diff );
		$me   = wp_get_current_user();
		$mine = $me ? (string) $me->user_email : '';

		if ( ! empty( $_GET['test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// Testversand: ausschließlich an den angemeldeten Operator, nie an den Kunden. Ohne Anhang —
			// es geht um den Text, nicht um das Artefakt.
			$sent = is_email( $mine ) && wp_mail( $mine, $subj, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
			self::log( $id, 'preview_test', 'Testversand an ' . $mine . ( $sent ? ' ok' : ' FEHLGESCHLAGEN' ) );
			wp_safe_redirect( add_query_arg( 'm24_mailtest', $sent ? 'ok' : 'fail', admin_url( 'admin.php?page=m24-offers' ) ) );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<div style="position:sticky;top:0;background:#111417;color:#fff;padding:10px 16px;font:13px/1.5 system-ui,sans-serif;">'
			. '<strong>Vorschau</strong> · Betreff: ' . esc_html( $subj )
			. ( is_email( $mine ) ? ' · <a href="' . esc_url( self::preview_url( $id, true ) ) . '" style="color:#9cc4ff;">Testversand an ' . esc_html( $mine ) . '</a>' : '' )
			. ' · <a href="' . esc_url( admin_url( 'admin.php?page=m24-offers' ) ) . '" style="color:#9cc4ff;">zurück</a></div>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput — eigene, bereits escapte Mail-Ausgabe
		exit;
	}

	/** Kartenhinweis für den Zwischenzustand — begründet, damit er kein stiller Liegenbleiber wird. */
	public static function pending_badge( $o ): string {
		if ( ! self::is_pending( $o ) ) { return ''; }
		$reason = trim( (string) ( $o->version_pending_reason ?? '' ) );
		$when   = '';
		if ( ! empty( $o->version_pending_at ) && ( $ts = strtotime( (string) $o->version_pending_at . ' UTC' ) ) ) {
			$when = ' seit ' . ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y H:i', $ts ) : gmdate( 'd.m.Y H:i', $ts ) . ' UTC' );
		}
		return '<span style="color:#b45309;" title="' . esc_attr( $reason ) . '">▲ Fassung '
			. (int) ( $o->offer_version ?? 1 ) . ' bereit, nicht versendet' . esc_html( $when )
			. ( '' !== $reason ? ' — ' . esc_html( $reason ) : '' ) . '</span>'
			. ' <a href="' . esc_url( self::preview_url( (int) $o->id ) ) . '" target="_blank" rel="noopener" style="font-weight:600;">Mail-Vorschau</a>';
	}

	private static function log( int $offer_id, string $step, string $msg ): void {
		if ( class_exists( 'M24_Logger' ) ) {
			M24_Logger::info( 'offer_update', $msg, array( 'offer_id' => $offer_id, 'schritt' => $step ) );
		}
	}
}

// Vorschau/Testversand als admin-post-Aktion. Auf Dateiebene registriert, weil diese Klasse keinen
// eigenen init() hat und die Hauptdatei dafür nicht angefasst werden soll.
add_action( 'admin_post_m24_offer_update_preview', array( 'M24_Offer_Update', 'handle_preview' ) );
