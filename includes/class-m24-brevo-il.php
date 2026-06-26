<?php
/**
 * M24 Plattform — Interessentenliste, plugin-managed Double-Opt-In (Brevo Phase 2)
 *
 * Ablauf:
 *   1. IL-Opt-in-Submit → register_interessent() feuert `m24fz_interessent_submitted`.
 *   2. Hier: KEIN sofortiger Brevo-Call. Stattdessen Pending-Record (E-Mail, fertiges
 *      Attribut-Array, Kundentyp, Token, created_at) speichern + DOI-Mail an den
 *      Interessenten senden. Token-Gültigkeit 7 Tage. Bereits-pending-E-Mail → Token
 *      erneuern + Mail erneut senden (kein Duplikat).
 *   3. Klick auf den Bestätigungslink (/anmeldung-bestaetigt/?m24il=TOKEN, Seite 34308):
 *      Token validieren → Kontakt an Brevo upserten (Liste 3, bestätigt) → Pending
 *      erledigt → freundliche Erfolgsseite. Upsert-Fehler → Lead per Fallback-Mail an
 *      service@ retten + Log „Fail", Nutzer trotzdem Erfolgsseite. Ungültiger/abgelaufener
 *      Token → neutrale „Link abgelaufen"-Meldung.
 *
 * Die Fallback-Mail aus register_interessent() bleibt als Sicherheitsnetz parallel aktiv.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Brevo_IL {

	const PENDING_OPTION = 'm24_brevo_il_pending';
	const CONFIRM_PAGE   = 34308; // WP-Seite /anmeldung-bestaetigt/
	const TTL            = 1209600; // 14 Tage in Sekunden
	const QUERY_VAR      = 'm24il';
	const CRON_HOOK      = 'm24_il_reminder_tick';

	/** Ergebnis des Confirm-Vorgangs für die_content: 'ok' | 'invalid' | null. */
	private static $confirm_state = null;

	public static function init() {
		// DOI-Pipeline an den bestehenden generischen Hook hängen.
		add_action( 'm24fz_interessent_submitted', array( __CLASS__, 'on_submitted' ), 10, 2 );

		// Confirm-Handling auf der Bestätigungsseite.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_confirm' ) );
		add_filter( 'the_content', array( __CLASS__, 'confirm_notice' ), 9999 );
		add_action( 'wp_head', array( __CLASS__, 'confirm_page_styles' ), 99 );

		// DOI-Erinnerung: stündlicher Cron-Tick + QA-Test-Trigger (manage_options).
		add_action( self::CRON_HOOK, array( __CLASS__, 'reminder_tick' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
		add_action( 'admin_post_m24_il_reminder_test', array( __CLASS__, 'handle_reminder_test' ) );
	}

	/**
	 * Doppel-Titel vermeiden: Auf der Bestätigungsseite (Seite 34308) trägt das Theme bereits
	 * einen Seitentitel „Anmeldung bestätigt", der sich mit der Karten-Headline doppelt. Scoped
	 * CSS — ausschließlich body.page-id-{ID}, keine tagDiv-Templates angefasst. Selektorliste
	 * via Filter `m24_il_confirm_hide_title_selectors` anpassbar.
	 */
	public static function confirm_page_styles() {
		if ( is_admin() || ! is_page( self::CONFIRM_PAGE ) ) {
			return;
		}
		$selectors = apply_filters( 'm24_il_confirm_hide_title_selectors', array(
			'.td-page-header',
			'.td-page-title',
			'.entry-title',
			'.tdb-title-text',
			'.tdb_title',
		) );
		$scoped = array();
		foreach ( (array) $selectors as $sel ) {
			$scoped[] = 'body.page-id-' . (int) self::CONFIRM_PAGE . ' ' . $sel;
		}
		// Verlaufs-CTA per Klasse — inline-Gradient wird beim the_content-Rendering gestrippt,
		// daher hier; das inline background:#1f74c4 am CTA bleibt als Solid-Fallback.
		$css = '.m24-il-cta{background-image:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%)!important;}';

		// Sidebar ausblenden + Content auf volle Breite. ZWINGEND auf body.page-id-{ID} gescoped:
		// .td-pb-span4 kommt 7× vor (Footer!), `.td-pb-span8 + .td-pb-span4` trifft nur die Sidebar
		// als direkten Nachbarn der Content-Spalte (live verifiziert: Content-Row = genau 2 Spalten).
		$pid  = (int) self::CONFIRM_PAGE;
		$css .= 'body.page-id-' . $pid . ' .td-pb-span8 + .td-pb-span4{display:none!important;}';
		$css .= 'body.page-id-' . $pid . ' .td-pb-span8{width:100%!important;}';
		$css .= 'body.page-id-' . $pid . ' .m24-il-confirm{margin-left:auto!important;margin-right:auto!important;}';

		if ( ! empty( $scoped ) ) {
			$css .= implode( ',', $scoped ) . '{display:none!important;}';
		}
		echo '<style id="m24-il-confirm-css">' . $css . '</style>' . "\n";
	}

	/* =====================================================================
	 * 1) Opt-in-Submit → Pending + DOI-Mail
	 * ================================================================== */

	/**
	 * Hook-Callback für `m24fz_interessent_submitted`.
	 * $contact: name, email, kundentyp, tel, modelle[], kategorien[].
	 */
	public static function on_submitted( $context_id, $contact ) {
		$email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $contact['name'] ?? '' ) );
		if ( ! is_email( $email ) || '' === $name ) {
			return;
		}

		// Ohne konfigurierten Key: kein DOI-Versand (nur die Fallback-Mail greift) — kein toter Link.
		if ( ! M24_Brevo_Client::is_configured() ) {
			M24_Logger::warning( 'brevo', 'IL-Opt-in ohne API-Key — nur Fallback-Mail (' . M24_Brevo_Client::mask_email( $email ) . ')', array(
				'email' => M24_Brevo_Client::mask_email( $email ),
			) );
			return;
		}

		$attributes = self::attributes_for( $contact );

		// Bereits-pending-E-Mail → vorhandenen Record wiederverwenden, Token erneuern, Daten auffrischen.
		$store = self::load();
		$token = self::find_token_by_email( $store, $email );
		if ( null === $token ) {
			$token = self::new_token();
		}

		// Fahrzeug-Alert-Tags aus dem auslösenden Kontext ableiten (Leaf + marke-alle + art + global).
		$tags = class_exists( 'M24_Alert_Taxonomie' ) ? M24_Alert_Taxonomie::tags_for_context( $context_id, $contact ) : array();

		// Bestehenden reminded_at-Status erhalten (Re-Submit erneuert den Token, nicht den Reminder-Stand).
		$reminded_at = isset( $store[ $token ]['reminded_at'] ) ? (int) $store[ $token ]['reminded_at'] : 0;

		$offmarket = ! empty( $contact['offmarket'] );
		$parked    = ! empty( $contact['parked'] );
		$variant   = $offmarket ? 'offmarket' : ( $parked ? 'parked' : '' );
		$vorname   = sanitize_text_field( (string) ( $contact['vorname'] ?? '' ) );
		$nachname  = sanitize_text_field( (string) ( $contact['nachname'] ?? '' ) );
		$lang      = ( 'en' === strtolower( (string) ( $contact['lang'] ?? '' ) ) ) ? 'en' : 'de';

		$store[ $token ] = array(
			'email'       => $email,
			'name'        => $name,
			'vorname'     => $vorname,
			'nachname'    => $nachname,
			'lang'        => $lang,
			'kundentyp'   => sanitize_text_field( (string) ( $contact['kundentyp'] ?? '' ) ),
			'lieferland'  => sanitize_text_field( (string) ( $contact['lieferland'] ?? '' ) ),
			'attributes'  => $attributes,
			'tags'        => $tags,
			'offmarket'   => $offmarket,
			'parked'      => $parked,
			'source_id'   => (int) $context_id,
			'created'     => time(),
			'reminded_at' => $reminded_at,
		);
		self::save( $store );

		self::send_doi_mail( $email, $name, $token, $variant, $lang, $vorname );

		M24_Logger::info( 'brevo', 'DOI-Mail gesendet (' . M24_Brevo_Client::mask_email( $email ) . ')', array(
			'email'    => M24_Brevo_Client::mask_email( $email ),
			'attrKeys' => array_keys( $attributes ),
		) );
	}

	/**
	 * Brevo-Attribute aus dem generischen Kontakt ableiten. Einheitliche Quelle der Wahrheit.
	 * NAME, KUNDENTYP, MODELLE, KATEGORIEN (Text) + Segment-Flags ALLE / ALLE_OLDTIMER / ALLE_SPORT.
	 * Filterbar via `m24_brevo_il_attributes`.
	 */
	public static function attributes_for( $contact ) {
		$modelle    = array_values( array_filter( array_map( 'trim', (array) ( $contact['modelle'] ?? array() ) ) ) );
		$kategorien = array_values( array_filter( array_map( 'trim', (array) ( $contact['kategorien'] ?? array() ) ) ) );

		$vorname  = sanitize_text_field( (string) ( $contact['vorname'] ?? '' ) );
		$nachname = sanitize_text_field( (string) ( $contact['nachname'] ?? '' ) );
		$attr = array(
			// Brevo-Standard (DE): VORNAME = Vorname, NAME = Nachname. Fallback NAME = voller Name (IL ohne Split).
			'VORNAME'    => $vorname,
			'NAME'       => '' !== $nachname ? $nachname : sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
			'KUNDENTYP'  => sanitize_text_field( (string) ( $contact['kundentyp'] ?? '' ) ),
			'MODELLE'    => implode( ', ', $modelle ),
			'KATEGORIEN' => implode( ', ', $kategorien ),
			'ALLE'       => true, // jeder Listen-Kontakt
		);
		if ( ! empty( $contact['lang'] ) ) {
			$attr['SPRACHE'] = ( 'en' === strtolower( (string) $contact['lang'] ) ) ? 'EN' : 'DE';
		}

		// Kategorie-abgeleitete Segment-Flags. Oldtimer/Straße → ALLE_OLDTIMER, Sport → ALLE_SPORT.
		$katz_lower = array_map( 'mb_strtolower', $kategorien );
		if ( in_array( 'oldtimer', $katz_lower, true ) || in_array( 'straße', $katz_lower, true ) || in_array( 'strasse', $katz_lower, true ) ) {
			$attr['ALLE_OLDTIMER'] = true;
		}
		if ( in_array( 'sport', $katz_lower, true ) ) {
			$attr['ALLE_SPORT'] = true;
		}

		// Off-Market-Quelle markieren (Segment-Flag wie ALLE_*; filterbar, falls Brevo-Attr abweicht).
		if ( ! empty( $contact['offmarket'] ) ) {
			$attr['OFFMARKET'] = true;
		}

		// „Fahrzeug parken": PARKED-Flag + konkretes Fahrzeug (Titel) — filterbar.
		if ( ! empty( $contact['parked'] ) ) {
			$attr['PARKED'] = true;
			$pt = sanitize_text_field( (string) ( $contact['parked_title'] ?? '' ) );
			if ( '' !== $pt ) { $attr['PARKED_FAHRZEUG'] = $pt; }
		}

		return apply_filters( 'm24_brevo_il_attributes', $attr, $contact );
	}

	/* =====================================================================
	 * 2) Confirm-Handler (Seite 34308 / ?m24il=TOKEN)
	 * ================================================================== */

	/** Token früh (vor Render) verarbeiten, damit der Brevo-Upsert vor der Ausgabe steht. */
	public static function maybe_confirm() {
		if ( is_admin() || ! is_page( self::CONFIRM_PAGE ) ) {
			return;
		}
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $token ) {
			return; // Seite normal aufgerufen, ohne Token — nichts tun.
		}
		self::$confirm_state = self::confirm_token( $token );
	}

	/**
	 * Seiteninhalt auf der Bestätigungsseite (34308) komplett DURCH die Karte ERSETZEN — nicht
	 * anhängen. So bleibt kein statischer WP-Seitentext darunter stehen (kein Doppel-/Widerspruchs-
	 * text). Mit Token: render_box('ok'|'invalid'). Ohne Token (Direktaufruf): neutrale Variante.
	 * Andere Inhalte/Seiten bleiben unberührt (is_page + Main-Query-Guards).
	 */
	public static function confirm_notice( $content ) {
		if ( ! is_page( self::CONFIRM_PAGE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$state = ( null !== self::$confirm_state ) ? self::$confirm_state : 'neutral';
		return self::render_box( $state );
	}

	/**
	 * Token validieren und — falls gültig — Kontakt bestätigt an Brevo upserten.
	 * @return string 'ok' (Erfolg ODER soft-fail mit gerettetem Lead) | 'invalid' (ungültig/abgelaufen)
	 */
	private static function confirm_token( $token ) {
		$store = self::load();
		if ( ! isset( $store[ $token ] ) ) {
			return 'invalid';
		}
		$rec = $store[ $token ];

		// Abgelaufen?
		if ( ( time() - (int) ( $rec['created'] ?? 0 ) ) > self::TTL ) {
			unset( $store[ $token ] );
			self::save( $store );
			return 'invalid';
		}

		$email      = (string) $rec['email'];
		$attributes = (array) ( $rec['attributes'] ?? array() );
		$tags       = (array) ( $rec['tags'] ?? array() );

		// Spiegel-Tabelle zuerst (eigene Quelle der Wahrheit, unabhängig vom Brevo-Call).
		self::record_confirmed( $rec, $tags );

		// Listen: Master (Liste 3) + passende granulare Alert-Listen aus m24_alert_list_ids.
		$list_ids = array_values( array_unique( array_merge(
			array( M24_Brevo_Client::LIST_ID ),
			M24_Brevo_Client::alert_list_ids_for_tags( $tags )
		) ) );

		// Off-Market-Anmeldung → zusätzlich in die Off-Market-Liste (sofern konfiguriert).
		if ( ! empty( $rec['offmarket'] ) ) {
			$om = M24_Brevo_Client::offmarket_list_id();
			if ( $om > 0 ) { $list_ids[] = $om; }
			$list_ids = array_values( array_unique( array_filter( $list_ids ) ) );
		}

		$res = M24_Brevo_Client::upsert_contact( $email, $attributes, $list_ids );

		// Pending in jedem Fall erledigt (kein Re-Processing). Lead bei Fehler per Fallback-Mail gerettet.
		unset( $store[ $token ] );
		self::save( $store );

		if ( ! $res['ok'] ) {
			self::send_fail_fallback( $rec, $res );
			// Nutzer sieht trotzdem die Erfolgsseite — Lead ist gesichert (Spiegel-Datensatz steht).
		}

		return 'ok';
	}

	/* =====================================================================
	 * Spiegel-Tabelle (Datenzugriff) — Brevo bleibt Master für den Versand
	 * ================================================================== */

	/**
	 * DOI-bestätigten Interessenten upserten (per E-Mail) + Tags in die Relationstabelle
	 * schreiben (vollständig ersetzen). status=aktiv. Idempotent.
	 */
	public static function record_confirmed( $rec, $tags ) {
		global $wpdb;
		$email = sanitize_email( (string) ( $rec['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		$main = M24_Database::table( 'il_interessenten' );
		$rel  = M24_Database::table( 'il_interessenten_tags' );
		$now  = current_time( 'mysql' );

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $main WHERE email = %s", $email ) );

		$fields = array(
			'kundentyp'         => sanitize_text_field( (string) ( $rec['kundentyp'] ?? '' ) ),
			'name'              => sanitize_text_field( (string) ( $rec['name'] ?? '' ) ),
			'vorname'           => sanitize_text_field( (string) ( $rec['vorname'] ?? '' ) ),
			'nachname'          => sanitize_text_field( (string) ( $rec['nachname'] ?? '' ) ),
			'sprache'           => ( 'en' === strtolower( (string) ( $rec['lang'] ?? '' ) ) ) ? 'en' : 'de',
			'consent_at'        => $now,
			'source_inserat_id' => (int) ( $rec['source_id'] ?? 0 ),
			'status'            => 'aktiv',
			'updated_at'        => $now,
		);

		if ( $existing_id ) {
			$wpdb->update( $main, $fields, array( 'id' => $existing_id ) );
		} else {
			$fields['email']      = $email;
			$fields['created_at'] = $now;
			$wpdb->insert( $main, $fields );
		}

		// Tags vollständig neu setzen (dedupliziert, nur gültige Slugs).
		$wpdb->delete( $rel, array( 'email' => $email ) );
		$seen = array();
		foreach ( (array) $tags as $tag ) {
			$tag = sanitize_key( $tag );
			if ( '' === $tag || isset( $seen[ $tag ] ) ) {
				continue;
			}
			if ( class_exists( 'M24_Alert_Taxonomie' ) && ! M24_Alert_Taxonomie::is_valid( $tag ) ) {
				continue;
			}
			$seen[ $tag ] = true;
			$wpdb->insert( $rel, array( 'email' => $email, 'tag' => $tag, 'created_at' => $now ) );
		}

		if ( $wpdb->last_error ) {
			M24_Logger::error( 'brevo', 'Spiegel-Tabelle Schreibfehler (' . M24_Brevo_Client::mask_email( $email ) . ')', array( 'err' => $wpdb->last_error ) );
		}
	}

	/**
	 * Abmelde-Sync: status=abgemeldet (lokal) + aus allen Brevo-Listen entfernen (best-effort).
	 * Tags bleiben für die Historie erhalten (kein harter Verlust). Brevo-Fehler werden geloggt,
	 * stoppen aber nicht die lokale Abmeldung.
	 */
	public static function mark_unsubscribed( $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return;
		}
		$main = M24_Database::table( 'il_interessenten' );
		$wpdb->update( $main, array( 'status' => 'abgemeldet', 'updated_at' => current_time( 'mysql' ) ), array( 'email' => $email ) );

		if ( M24_Brevo_Client::is_configured() ) {
			$ids = M24_Brevo_Client::all_known_list_ids();
			$res = M24_Brevo_Client::update_contact( $email, array( 'unlinkListIds' => $ids ) );
			if ( $res['ok'] ) {
				M24_Logger::info( 'brevo', 'Abgemeldet: aus Listen entfernt (' . M24_Brevo_Client::mask_email( $email ) . ')', array( 'unlink' => $ids ) );
			} else {
				M24_Logger::warning( 'brevo', 'Abmelden: Brevo-Unlink fehlgeschlagen (' . M24_Brevo_Client::mask_email( $email ) . ')', array( 'code' => $res['code'], 'msg' => $res['msg'] ) );
			}
		}
	}

	/** Lösch-Sync (DSGVO Art. 17): Brevo-Kontakt löschen (best-effort) + Datensatz/Tags hart entfernen. */
	public static function hard_delete( $email ) {
		global $wpdb;
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return;
		}
		if ( M24_Brevo_Client::is_configured() ) {
			$res = M24_Brevo_Client::delete_contact( $email );
			if ( $res['ok'] ) {
				M24_Logger::info( 'brevo', 'Gelöscht: Brevo-Kontakt entfernt (' . M24_Brevo_Client::mask_email( $email ) . ')', null );
			} else {
				M24_Logger::warning( 'brevo', 'Löschen: Brevo-Delete fehlgeschlagen (' . M24_Brevo_Client::mask_email( $email ) . ')', array( 'code' => $res['code'], 'msg' => $res['msg'] ) );
			}
		}
		$wpdb->delete( M24_Database::table( 'il_interessenten_tags' ), array( 'email' => $email ) );
		$wpdb->delete( M24_Database::table( 'il_interessenten' ), array( 'email' => $email ) );

		// Evtl. noch offenen Pending-Record (DOI nicht bestätigt) ebenfalls entfernen.
		$store   = self::load();
		$changed = false;
		foreach ( $store as $tok => $rec ) {
			if ( isset( $rec['email'] ) && strtolower( (string) $rec['email'] ) === strtolower( $email ) ) {
				unset( $store[ $tok ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			self::save( $store );
		}
	}

	/**
	 * Offene DOI-Pending-Einträge (noch nicht bestätigt) für die Admin-Übersicht.
	 * @return array Liste [ email, name, kundentyp, tags[], source_id, created(ts) ], abgelaufene gefiltert.
	 */
	public static function pending_list() {
		$out = array();
		$now = time();
		foreach ( self::load() as $rec ) {
			$created = (int) ( $rec['created'] ?? 0 );
			if ( ( $now - $created ) > self::TTL ) {
				continue; // abgelaufen → nicht anzeigen
			}
			$out[] = array(
				'email'     => (string) ( $rec['email'] ?? '' ),
				'name'      => (string) ( $rec['name'] ?? '' ),
				'lang'      => (string) ( $rec['lang'] ?? '' ),
				'kundentyp' => (string) ( $rec['kundentyp'] ?? '' ),
				'tags'      => (array) ( $rec['tags'] ?? array() ),
				'source_id' => (int) ( $rec['source_id'] ?? 0 ),
				'created'   => $created,
			);
		}
		return $out;
	}

	/* =====================================================================
	 * Mails
	 * ================================================================== */

	/** DOI-Bestätigungsmail an den Interessenten (CI-konform, Bestätigungs-Button). Sprache DE|EN. */
	private static function send_doi_mail( $email, $name, $token, $variant = '', $lang = 'de', $vorname = '' ) {
		$confirm_url = add_query_arg( self::QUERY_VAR, $token, self::confirm_page_url() );
		$en          = ( 'en' === strtolower( (string) $lang ) );
		// Anrede: Vorname (Off-Market/Parken). Fallback voller Name (IL hat ein Namensfeld), sonst neutral.
		$greet = trim( (string) $vorname );
		if ( '' === $greet && '' === $variant ) { $greet = trim( (string) $name ); }

		if ( $en ) {
			if ( 'offmarket' === $variant ) {
				$subject = 'Confirm your off-market sign-up — MOTORSPORT24';
				$intro   = 'thank you for your interest in our off-market vehicles. Please confirm with one click that we may inform you '
					. 'about vehicles before they are officially marketed:';
			} elseif ( 'parked' === $variant ) {
				$subject = 'Confirm your parked vehicle — MOTORSPORT24';
				$intro   = 'you parked a vehicle. Please confirm with one click that we may inform you '
					. 'about this and similar vehicles by email:';
			} else {
				$subject = 'Please confirm your sign-up — MOTORSPORT24';
				$intro   = 'thank you for your interest. Please confirm with one click that we may inform you '
					. 'about matching vehicles and offers:';
			}
			$headline = 'Almost done!';
			$cta      = 'Confirm sign-up';
			$hint     = 'If the button does not work, copy this link into your browser:';
			$ignore   = 'If you did not sign up, simply ignore this email — nothing happens.';
			$hallo    = ( '' !== $greet ) ? 'Hi ' . esc_html( $greet ) . ',' : 'Hi,';
		} else {
			if ( 'offmarket' === $variant ) {
				$subject = 'Bestätige deine Off-Market-Anmeldung — MOTORSPORT24';
				$intro   = 'vielen Dank für dein Interesse an unseren Off-Market-Fahrzeugen. Bitte bestätige mit einem Klick, dass wir dich '
					. 'vorab über Fahrzeuge informieren dürfen, bevor sie offiziell vermarktet werden:';
			} elseif ( 'parked' === $variant ) {
				$subject = 'Bestätige dein geparktes Fahrzeug — MOTORSPORT24';
				$intro   = 'du hast ein Fahrzeug geparkt. Bitte bestätige mit einem Klick, dass wir dich '
					. 'zu diesem und ähnlichen Fahrzeugen per E-Mail informieren dürfen:';
			} else {
				$subject = 'Bitte bestätige deine Anmeldung — MOTORSPORT24';
				$intro   = 'vielen Dank für dein Interesse. Bitte bestätige mit einem Klick, dass wir dich '
					. 'über passende Fahrzeuge und Angebote informieren dürfen:';
			}
			$headline = 'Fast geschafft!';
			$cta      = 'Anmeldung bestätigen';
			$hint     = 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:';
			$ignore   = 'Wenn du dich nicht angemeldet hast, ignoriere diese E-Mail einfach — es passiert nichts.';
			$hallo    = ( '' !== $greet ) ? 'Hallo ' . esc_html( $greet ) . ',' : 'Hallo,';
		}

		$body = self::mail_html(
			$headline,
			'<p style="margin:0 0 14px;">' . $hallo . '</p>'
			. '<p style="margin:0 0 14px;">' . esc_html( $intro ) . '</p>'
			. '<p style="margin:24px 0;text-align:center;">'
			. '<a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;background:#1f74c4;color:#ffffff;'
			. 'text-decoration:none;font-weight:600;padding:13px 28px;border-radius:6px;font-size:15px;">' . esc_html( $cta ) . '</a>'
			. '</p>'
			. '<p style="margin:0 0 8px;color:#5a6474;font-size:13px;">' . esc_html( $hint ) . '</p>'
			. '<p style="margin:0 0 14px;font-size:12px;word-break:break-all;"><a href="' . esc_url( $confirm_url ) . '" style="color:#1f74c4;">' . esc_html( $confirm_url ) . '</a></p>'
			. '<p style="margin:0;color:#9aa3b0;font-size:12px;">' . esc_html( $ignore ) . '</p>',
			$en ? 'en' : 'de'
		);

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::from_header(),
			'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
		);

		wp_mail( $email, $subject, $body, $headers );
	}

	/** Fallback-Mail an service@, falls der Brevo-Upsert nach Bestätigung scheitert (Lead-Rettung). */
	private static function send_fail_fallback( $rec, $res ) {
		$to   = apply_filters( 'm24fz_interessent_to', apply_filters( 'm24fz_anfrage_to', get_option( 'admin_email' ) ) );
		$attr = (array) ( $rec['attributes'] ?? array() );

		$body  = "Brevo-Upsert nach DOI-Bestätigung FEHLGESCHLAGEN — Lead bitte manuell in Liste 3 eintragen.\n\n";
		$body .= 'Name: ' . ( $rec['name'] ?? '' ) . "\n";
		$body .= 'E-Mail: ' . ( $rec['email'] ?? '' ) . "\n";
		$body .= 'Kundentyp: ' . ( $rec['kundentyp'] ?? '' ) . "\n";
		$body .= 'MODELLE: ' . ( $attr['MODELLE'] ?? '—' ) . "\n";
		$body .= 'KATEGORIEN: ' . ( $attr['KATEGORIEN'] ?? '—' ) . "\n\n";
		$body .= 'Brevo-Antwort: HTTP ' . (int) $res['code'] . ' — ' . (string) $res['msg'] . "\n";

		wp_mail( $to, 'IL-Bestätigung: Brevo-Eintrag fehlgeschlagen', $body, array( 'From: ' . self::from_header() ) );

		M24_Logger::error( 'brevo', 'DOI bestätigt, aber Brevo-Upsert fehlgeschlagen — Fallback-Mail an Daniel', array(
			'email' => M24_Brevo_Client::mask_email( (string) ( $rec['email'] ?? '' ) ),
			'code'  => (int) $res['code'],
			'msg'   => (string) $res['msg'],
		) );
	}

	/* =====================================================================
	 * DOI-Erinnerung (Cron, einmalig je Pending, Sonntag-Slot in der Lieferland-TZ)
	 * ================================================================== */

	/**
	 * Stündlicher Cron-Tick: noch unbestätigte Pendings, deren Erinnerungstermin erreicht
	 * und die noch nicht abgelaufen sind, einmalig erinnern. Bestätigte sind nicht mehr im Store.
	 */
	public static function reminder_tick() {
		$store   = self::load();
		$now     = time();
		$changed = false;
		foreach ( $store as $token => $rec ) {
			if ( 0 !== (int) ( $rec['reminded_at'] ?? 0 ) ) { continue; }            // schon erinnert
			$created = (int) ( $rec['created'] ?? 0 );
			if ( $now > $created + self::TTL ) { continue; }                          // abgelaufen
			if ( $now < self::reminder_due_ts( $rec ) ) { continue; }                 // Termin noch nicht erreicht
			self::send_reminder_mail( $rec, $token );
			$store[ $token ]['reminded_at'] = $now;
			$changed = true;
		}
		if ( $changed ) { self::save( $store ); }
	}

	/**
	 * Erinnerungstermin (UTC-Timestamp): nächster Sonntag {hour}:00 in der Lieferland-TZ,
	 * mindestens 1 Tag nach Anmeldung.
	 * Beispiele: Sa 23:55 → So in 8 Tagen (nicht morgen, weil So-11:00 < Sa23:55+24h);
	 *            Mo 09:00 → kommender So 11:00.
	 */
	public static function reminder_due_ts( $rec ) {
		$created  = (int) ( $rec['created'] ?? 0 );
		$hour     = (int) apply_filters( 'm24_il_reminder_hour', 11 );
		$tz       = new DateTimeZone( self::tz_for_lieferland( (string) ( $rec['lieferland'] ?? '' ) ) );
		$schwelle = $created + DAY_IN_SECONDS; // >= 1 Tag Abstand

		$d   = ( new DateTime( '@' . $created ) )->setTimezone( $tz );
		$add = ( 7 - (int) $d->format( 'w' ) ) % 7; // Tage bis Sonntag (0 = heute Sonntag)
		if ( $add > 0 ) { $d->modify( '+' . $add . ' days' ); }
		$d->setTime( $hour, 0, 0 );
		while ( $d->getTimestamp() < $schwelle ) { $d->modify( '+7 days' ); }
		return $d->getTimestamp();
	}

	/** Zeitzone aus dem Lieferland-Namen (filterbar). Default Europe/Berlin. */
	public static function tz_for_lieferland( $land ) {
		$map = apply_filters( 'm24_il_reminder_tz_map', array(
			'Deutschland'            => 'Europe/Berlin',
			'Österreich'             => 'Europe/Berlin',
			'Schweiz'                => 'Europe/Berlin',
			'Niederlande'            => 'Europe/Berlin',
			'Belgien'                => 'Europe/Berlin',
			'Frankreich'             => 'Europe/Berlin',
			'Italien'                => 'Europe/Berlin',
			'Spanien'                => 'Europe/Berlin',
			'Vereinigtes Königreich' => 'Europe/London',
			'Großbritannien'         => 'Europe/London',
			'UK'                     => 'Europe/London',
			'USA'                    => 'America/New_York',
			'Vereinigte Staaten'     => 'America/New_York',
			'Kanada'                 => 'America/Toronto',
		) );
		$land = trim( (string) $land );
		return isset( $map[ $land ] ) ? $map[ $land ] : 'Europe/Berlin';
	}

	/**
	 * Erinnerungsmail: Hülle identisch zur DOI-Mail, mit Fahrzeugbildern + DEMSELBEN Confirm-Link.
	 * Fehlt Fahrzeug/Bild → Block weglassen, Mail trotzdem senden.
	 */
	public static function send_reminder_mail( $rec, $token ) {
		$email = sanitize_email( (string) ( $rec['email'] ?? '' ) );
		if ( ! is_email( $email ) ) { return; }
		$name        = (string) ( $rec['name'] ?? '' );
		$source      = (int) ( $rec['source_id'] ?? 0 );
		$confirm_url = add_query_arg( self::QUERY_VAR, $token, self::confirm_page_url() );
		$subject     = 'Erinnerung: deine Anmeldung bei MOTORSPORT24 – noch ein Klick';

		$veh_title = $source ? get_the_title( $source ) : '';
		$bezug     = '' !== $veh_title
			? 'Du hattest dich für <strong>' . esc_html( $veh_title ) . '</strong> auf unsere Interessentenliste eingetragen — deine Bestätigung steht aber noch aus.'
			: 'Deine Anmeldung auf unsere Interessentenliste steht noch aus.';

		$inner  = '<p style="margin:0 0 14px;">Hallo ' . esc_html( $name ) . ',</p>';
		$inner .= '<p style="margin:0 0 14px;">' . $bezug . ' Mit einem Klick bist du dabei und verpasst kein passendes Fahrzeug mehr:</p>';
		$inner .= self::vehicle_image_block( $source );
		$inner .= '<p style="margin:24px 0;text-align:center;">'
			. '<a href="' . esc_url( $confirm_url ) . '" style="display:inline-block;background:#1f74c4;color:#ffffff;'
			. 'text-decoration:none;font-weight:600;padding:13px 28px;border-radius:6px;font-size:15px;">Anmeldung jetzt bestätigen</a>'
			. '</p>';
		$inner .= '<p style="margin:0 0 8px;color:#5a6474;font-size:13px;">Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:</p>';
		$inner .= '<p style="margin:0 0 14px;font-size:12px;word-break:break-all;"><a href="' . esc_url( $confirm_url ) . '" style="color:#1f74c4;">' . esc_html( $confirm_url ) . '</a></p>';
		$inner .= '<p style="margin:0;color:#9aa3b0;font-size:12px;">Wenn du dich nicht angemeldet hast, ignoriere diese E-Mail einfach — es passiert nichts.</p>';

		$body    = self::mail_html( 'Noch ein Klick zur Bestätigung', $inner );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::from_header(),
			'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
		);

		wp_mail( $email, $subject, $body, $headers );
		M24_Logger::info( 'brevo', 'DOI-Erinnerung gesendet (' . M24_Brevo_Client::mask_email( $email ) . ')', array(
			'source_id' => $source,
		) );
	}

	/**
	 * E-Mail-taugliches Bild-Markup aus dem Fahrzeug: Titelbild (large) + bis zu 3 Außenbilder (medium).
	 * Absolute URLs, feste width-Attribute, Tabellen-Layout. Fehlt alles → leerer String.
	 */
	private static function vehicle_image_block( $source ) {
		if ( ! $source || ! get_post( $source ) ) { return ''; }

		$main_id = (int) get_post_thumbnail_id( $source );
		// Titelbild: Origin (full) über Photon klein+komprimiert; Fallback medium_large (768).
		$main = '';
		if ( $main_id ) {
			$orig = wp_get_attachment_image_url( $main_id, 'full' );
			$main = function_exists( 'jetpack_photon_url' ) && $orig
				? jetpack_photon_url( $orig, array( 'w' => 600, 'quality' => 72 ) )
				: wp_get_attachment_image_url( $main_id, 'medium_large' );
		}

		$gal = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $source, '_m24fz_gal_aussen', true ) ) ) );
		$gal = array_slice( array_diff( $gal, array( $main_id ) ), 0, 3 );

		$html = '';
		if ( $main ) {
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px;"><tr>'
				. '<td style="padding:0;"><img src="' . esc_url( $main ) . '" width="560" alt="' . esc_attr( get_the_title( $source ) ) . '" style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:6px;"></td>'
				. '</tr></table>';
		}
		if ( ! empty( $gal ) ) {
			$cells = '';
			foreach ( $gal as $gid ) {
				// Außen-Thumbs: einheitlicher 4:3-Crop über Photon; Fallback thumbnail (150).
				$orig = wp_get_attachment_image_url( $gid, 'full' );
				$u    = function_exists( 'jetpack_photon_url' ) && $orig
					? jetpack_photon_url( $orig, array( 'resize' => '200,150', 'quality' => 72 ) )
					: wp_get_attachment_image_url( $gid, 'thumbnail' );
				if ( ! $u ) { continue; }
				$cells .= '<td style="padding:0 4px;" width="180"><img src="' . esc_url( $u ) . '" width="180" height="135" alt="" style="display:block;width:100%;max-width:180px;height:auto;border:0;border-radius:4px;"></td>';
			}
			if ( '' !== $cells ) {
				$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 6px;"><tr>' . $cells . '</tr></table>';
			}
		}
		return $html;
	}

	/**
	 * QA-Trigger (manage_options, nonce): baut die Erinnerungsmail für ein Pending und sendet sie
	 * sofort an m24_alert_test_recipient (sonst Record-Adresse) — Datumsgate übersprungen, Log „TEST".
	 */
	public static function handle_reminder_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'm24-plattform' ) );
		}
		$email = sanitize_email( wp_unslash( $_GET['email'] ?? '' ) );
		check_admin_referer( 'm24_il_reminder_test_' . $email );

		$store = self::load();
		$token = self::find_token_by_email( $store, $email );
		$ok    = false;
		if ( null !== $token ) {
			$rec  = $store[ $token ];
			$dest = sanitize_email( (string) get_option( 'm24_alert_test_recipient', '' ) );
			if ( ! is_email( $dest ) ) { $dest = (string) $rec['email']; }
			$rec['email'] = $dest; // Versand-Ziel überschreiben, Inhalt/Link unverändert
			self::send_reminder_mail( $rec, $token );
			$ok = true;
			M24_Logger::info( 'brevo', 'DOI-Erinnerung [TEST] gesendet an ' . M24_Brevo_Client::mask_email( $dest ), array( 'mode' => 'TEST', 'source_id' => (int) ( $rec['source_id'] ?? 0 ) ) );
		}

		$back = wp_get_referer() ?: admin_url( 'admin.php?page=m24-interessenten' );
		wp_safe_redirect( add_query_arg( 'm24il_done', $ok ? 'remindtest' : 'remindfail', remove_query_arg( array( 'm24il_done', '_wpnonce', 'action', 'email' ), $back ) ) );
		exit;
	}

	/* =====================================================================
	 * Render & Helfer
	 * ================================================================== */

	/** Status-Box für die Bestätigungsseite. $state: 'ok' | 'invalid' | 'neutral'. */
	private static function render_box( $state ) {
		if ( 'ok' === $state ) {
			$ring  = '#e7f4ec';
			$icon  = '<svg width="40" height="40" viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="30" fill="#1a7a3c"/><path d="M19 33l9 9 17-19" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			$title = 'Anmeldung bestätigt';
			$text  = 'Vielen Dank! Deine Anmeldung ist bestätigt. Sobald wir passende Fahrzeuge für dich anbieten, erfährst du als Erster die Details.';
		} elseif ( 'neutral' === $state ) {
			$ring  = '#e8f1fa';
			$icon  = '<svg width="40" height="40" viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="30" fill="#1f74c4"/><path d="M32 28v17" stroke="#fff" stroke-width="6" stroke-linecap="round"/><circle cx="32" cy="20" r="3.6" fill="#fff"/></svg>';
			$title = 'Interessentenliste';
			$text  = 'Diese Seite bestätigt Anmeldungen zur Interessentenliste. Stöber gern in unseren aktuellen Fahrzeugen.';
		} else {
			$ring  = '#fdf1e0';
			$icon  = '<svg width="40" height="40" viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="30" fill="#b87000"/><path d="M32 17v23" stroke="#fff" stroke-width="6" stroke-linecap="round"/><circle cx="32" cy="49" r="3.6" fill="#fff"/></svg>';
			$title = 'Link abgelaufen';
			$text  = 'Dieser Bestätigungslink ist ungültig oder abgelaufen. Bitte melde dich einfach erneut an.';
		}
		$cta  = esc_url( home_url( '/fahrzeuge/' ) );
		$home = esc_url( home_url( '/' ) );
		return '<div class="m24-il-confirm" style="max-width:620px;margin:32px auto;padding:48px 24px 52px;text-align:center;background:#fafafa;border:1px solid #e6e9ee;border-radius:14px;">'
			. '<div style="margin:0 auto 22px;width:72px;height:72px;border-radius:50%;background:' . $ring . ';display:flex;align-items:center;justify-content:center;">' . $icon . '</div>'
			. '<h2 style="margin:0 0 12px;font-size:28px;color:#10243a;">' . esc_html( $title ) . '</h2>'
			. '<p style="margin:0 auto 28px;max-width:430px;font-size:16px;line-height:1.6;color:#3a414c;">' . esc_html( $text ) . '</p>'
			. '<a href="' . $cta . '" class="m24-il-cta" style="display:inline-block;background:#1f74c4;color:#fff;text-decoration:none;font-weight:600;padding:14px 30px;border-radius:8px;font-size:15px;">Fahrzeuge ansehen</a>'
			. '<div style="margin-top:18px;"><a href="' . $home . '" style="color:#1f74c4;text-decoration:none;font-size:14px;">Zur Startseite</a></div>'
			. '</div>';
	}

	/**
	 * Schmales CI-konformes HTML-Mail-Gerüst (Verlaufs-Header-Band, MOTORSPORT24-Logo).
	 * CI-Font Saira self-hosted (kein Google, DSGVO-sauber): Apple Mail rendert Saira via
	 * @font-face, Gmail/Outlook fallen sauber auf Arial zurück (Fallback im Font-Stack).
	 */
	private static function mail_html( $headline, $inner, $lang = '' ) {
		$font_url = plugins_url( 'assets/fonts/saira-latin.woff2', M24_PLATTFORM_FILE );
		$stack    = "font-family:'Saira', Arial, Helvetica, sans-serif;";
		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<style>@font-face{font-family:\'Saira\';src:url(\'' . esc_url( $font_url ) . '\') format(\'woff2\');font-weight:100 900;font-style:normal;font-display:swap;}'
			. 'body,table,td,h1,div,a,p{' . $stack . '}'
			. 'a[x-apple-data-detectors]{color:inherit!important;text-decoration:none!important;font-size:inherit!important;font-weight:inherit!important;}</style></head>'
			. '<body style="margin:0;padding:0;background:#f2f4f7;' . $stack . '">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:0;"><tr><td align="center" style="padding:24px 16px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;">'
			. '<tr><td style="background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);padding:16px 28px;text-align:right;">'
			. '<img src="' . esc_url( apply_filters( 'm24fz_mail_logo_url', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2023/09/Logo-MOTORSPORT24.de_.gif' ) ) . '" alt="MOTORSPORT24" height="30" style="display:inline-block;height:30px;width:auto;border:0;outline:none;vertical-align:middle;">'
			. '</td></tr>'
			. '<tr><td style="padding:8px 28px 24px;' . $stack . 'color:#10243a;">'
			. '<h1 style="margin:8px 0 16px;font-size:21px;color:#10243a;' . $stack . '">' . esc_html( $headline ) . '</h1>'
			. '<div style="font-size:15px;line-height:1.55;color:#3a414c;' . $stack . '">' . $inner . '</div>'
			. '</td></tr>'
			. '<tr><td style="padding:18px 28px;border-top:1px solid #e6e9ee;text-align:center;' . $stack . 'font-size:11px;line-height:1.6;color:#9aa3b0;">'
			. '<div style="color:#7e8794;font-size:11.5px;">Classic &amp; Race Cars and Parts Sales since 2006</div>'
			. '<div style="margin-top:10px;">Unsere Postanschrift lautet:</div>'
			. '<div>MOTORSPORT24 GmbH, Scharfe Lanke 109-131, Haus 113a, 13595 Berlin, Deutschland</div>'
			. '<div style="margin-top:10px;">'
			. '<a href="https://www.motorsport24.de/impressum/" style="color:#1f74c4;text-decoration:none;' . $stack . '">Impressum</a> · '
			. '<a href="https://www.motorsport24.de/datenschutz/" style="color:#1f74c4;text-decoration:none;' . $stack . '">Datenschutz</a> · '
			. '<a href="https://www.motorsport24.de" style="color:#1f74c4;text-decoration:none;' . $stack . '">www.motorsport24.de</a>'
			. '</div>'
			. ( class_exists( 'M24_I18n' ) ? M24_I18n::mail_lang_footer( (string) $lang ) : '' )
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/** Absender-Header: From-Name MOTORSPORT24, Domain-Adresse noreply@<domain> (SPF/DKIM, Logik wie 0.11.20). */
	private static function from_header() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./i', '', $host );
		if ( '' === $host ) {
			$host = 'motorsport24.de';
		}
		$email = apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host );
		$name  = apply_filters( 'm24_brevo_doi_from_name', 'MOTORSPORT24' );
		return $name . ' <' . $email . '>';
	}

	/** URL der Bestätigungsseite (Seite 34308, mit Domain-Fallback). */
	private static function confirm_page_url() {
		$url = get_permalink( self::CONFIRM_PAGE );
		if ( ! $url ) {
			$url = home_url( '/anmeldung-bestaetigt/' );
		}
		return $url;
	}

	/* =====================================================================
	 * Pending-Store (Option, autoload aus)
	 * ================================================================== */

	private static function load() {
		$store = get_option( self::PENDING_OPTION, array() );
		return is_array( $store ) ? $store : array();
	}

	/** Speichern + abgelaufene Records beim Schreiben aufräumen. */
	private static function save( $store ) {
		$now = time();
		foreach ( $store as $tok => $rec ) {
			if ( ( $now - (int) ( $rec['created'] ?? 0 ) ) > self::TTL ) {
				unset( $store[ $tok ] );
			}
		}
		update_option( self::PENDING_OPTION, $store, false );
	}

	private static function find_token_by_email( $store, $email ) {
		foreach ( $store as $tok => $rec ) {
			if ( isset( $rec['email'] ) && strtolower( (string) $rec['email'] ) === strtolower( $email ) ) {
				return $tok;
			}
		}
		return null;
	}

	private static function new_token() {
		if ( function_exists( 'random_bytes' ) ) {
			return bin2hex( random_bytes( 16 ) );
		}
		return bin2hex( wp_generate_password( 16, false, false ) ); // Fallback
	}
}
