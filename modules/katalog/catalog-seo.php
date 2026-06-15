<?php
/**
 * M24 Plattform — Katalog: SEO (Feld-Autofill mit Hash-Marker-Auto-Sync)
 *
 * Strategie (Re-Fix nach T6 — Werte SICHTBAR im wpSEO-Feld):
 *  - Title + Description werden ins wpSEO-Feld geschrieben
 *    (_wpseo_edit_title / _wpseo_edit_description) → sichtbar/editierbar.
 *  - Beim Schreiben wird ein Hash-Marker abgelegt (sha1 des Werts):
 *    _m24_seo_autofill_title_hash / _m24_seo_autofill_desc_hash.
 *  - Auto-Sync bei Titeländerung (Save / Inline-Rename / Import):
 *      sha1(Feldwert) === Marker → noch „auto"  → neu generieren + Marker updaten
 *      sha1(Feldwert) !== Marker → manuell editiert → unangetastet
 *      Feld leer                → auto befüllen + Marker
 *  - Render-Filter (wpseo_set_title) gibt den Feldwert verbatim aus und verhindert
 *    so den wpSEO-Blog-Channel-Append (Doppel-Suffix). wpseo_set_desc liefert
 *    zusätzlich die statische Archiv-Description.
 *
 * Bestand nachziehen:  wp m24 reseed-seo-felder [--dry-run] [--yes]
 * Felder leeren:       wp m24 cleanup-seo-meta   [--dry-run] [--yes]
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_SEO {

	const PT         = 'm24_teil';
	const TITLE_MAX  = 70;

	const FIELD_TITLE  = '_wpseo_edit_title';
	const FIELD_DESC   = '_wpseo_edit_description';
	const MARKER_TITLE = '_m24_seo_autofill_title_hash';
	const MARKER_DESC  = '_m24_seo_autofill_desc_hash';

	/** Re-Entrancy-Schutz (compact_bmw_in_title etc. loesen verschachtelte save_post aus). */
	private static $syncing = false;

	public static function init() {
		// Title/Description/OG dynamisch aus dem AKTUELLEN Titel ausgeben (kein veralteter
		// Snapshot, z.B. Auto-Draft) — manuelle Overrides bleiben verbatim.
		add_filter( 'wpseo_set_desc',         array( __CLASS__, 'filter_desc' ),        20, 1 );
		add_filter( 'wpseo_set_title',        array( __CLASS__, 'force_detail_title' ), 99, 1 );
		// OG/Twitter werden vollstaendig von M24_Catalog_OG ausgegeben (eine Quelle, inkl. og:image
		// + Doubletten-Strip). force_detail_title()/filter_og_desc() bleiben als Helfer dafuer public.
		// Feld-Autofill + Marker. Prio 30: nach fields::save(10)/compact_bmw_in_title(15)/
		// artnr(20)/auto_slug(25) — arbeitet mit dem finalen Post-Titel. Auto-Draft wird in
		// sync_post() uebersprungen; der Status-Uebergang in einen echten Status generiert neu.
		add_action( 'save_post_' . self::PT,    array( __CLASS__, 'on_save' ), 30, 1 );
		add_action( 'transition_post_status',   array( __CLASS__, 'on_transition' ), 20, 3 );
	}

	// ── Title-Kaskade ────────────────────────────────────────────────────
	public static function build_title( $titel, $typ ) {
		// HTML-Entities aus dem Titel dekodieren (get_the_title liefert via wptexturize z.B.
		// „10&#215;18"). Sonst landen sie doppelt-escaped in Title/Description/og.
		$titel = trim( wp_strip_all_tags( html_entity_decode( (string) $titel, ENT_QUOTES, 'UTF-8' ) ) );
		if ( 'neu' === $typ ) {
			$variants = array(
				$titel . ' | MOTORSPORT24 seit 2006',
				$titel . ' | MOTORSPORT24',
			);
		} else {
			$variants = array(
				$titel . ' Original gebraucht | MOTORSPORT24 seit 2006',
				$titel . ' Original gebraucht | MOTORSPORT24',
				$titel . ' | MOTORSPORT24',
			);
		}
		foreach ( $variants as $v ) {
			if ( mb_strlen( $v ) <= self::TITLE_MAX ) { return $v; }
		}
		return end( $variants );
	}

	// ── Description-Kaskade ─────────────────────────────────────────────
	public static function build_desc( $titel, $typ ) {
		$titel = trim( wp_strip_all_tags( html_entity_decode( (string) $titel, ENT_QUOTES, 'UTF-8' ) ) );
		$tail  = ( 'neu' === $typ )
			? ' ✓ Rennsportqualität ✓ Made in Germany ✓ jetzt anfragen bei MOTORSPORT24 seit 2006'
			: ' ✓ Original gebraucht & geprüft ✓ sofort verfügbar ✓ fair kaufen bei MOTORSPORT24 seit 2006';
		$max          = 160;
		$title_budget = $max - mb_strlen( $tail );
		if ( $title_budget < 12 ) { $title_budget = 12; }
		if ( mb_strlen( $titel ) > $title_budget ) {
			$titel = rtrim( mb_substr( $titel, 0, $title_budget - 1 ) ) . '…';
		}
		return $titel . $tail;
	}

	// ── Feld-Autofill + Marker-Auto-Sync ─────────────────────────────────
	/**
	 * Schreibt Feld + Marker, respektiert manuelle Edits.
	 *
	 * @param bool $adopt_unmarked true (Reseed): nicht-leeres Feld OHNE Marker als
	 *                             „auto" adoptieren (Bestands-Backfill importierter Teile).
	 * @return string filled|resynced|manual
	 */
	private static function sync_field( $post_id, $field_key, $marker_key, $new_value, $adopt_unmarked = false ) {
		$current = (string) get_post_meta( $post_id, $field_key, true );
		$marker  = (string) get_post_meta( $post_id, $marker_key, true );

		if ( '' === $current ) {
			update_post_meta( $post_id, $field_key, $new_value );
			update_post_meta( $post_id, $marker_key, sha1( $new_value ) );
			return 'filled';
		}
		$is_auto = ( '' !== $marker && sha1( $current ) === $marker )
				|| ( $adopt_unmarked && '' === $marker );
		if ( $is_auto ) {
			if ( $current !== $new_value ) {
				update_post_meta( $post_id, $field_key, $new_value );
			}
			update_post_meta( $post_id, $marker_key, sha1( $new_value ) );
			return 'resynced';
		}
		return 'manual';
	}

	/**
	 * Synct Title + Description eines Teils aus dem aktuellen Post-Titel + _m24_typ.
	 * Aufgerufen von: save_post (Save/Inline), Importer (explizit), Reseed-CLI, Detail-Render.
	 *
	 * @return array{title:string,desc:string}
	 */
	public static function sync_post( $post_id, $adopt_unmarked = false ) {
		$post_id = (int) $post_id;
		if ( get_post_type( $post_id ) !== self::PT ) { return array(); }
		// KEIN Autofill bei Auto-Draft / leerem / Platzhalter-Titel — sonst landet
		// „Auto Draft"/„Automatisch gespeicherter Entwurf" als Snapshot in der Yoast-Meta.
		if ( self::is_placeholder_state( $post_id ) ) { return array(); }
		$titel = get_the_title( $post_id );
		$typ   = ( 'neu' === get_post_meta( $post_id, '_m24_typ', true ) ) ? 'neu' : 'gebraucht';
		return array(
			'title' => self::sync_field( $post_id, self::FIELD_TITLE, self::MARKER_TITLE, self::build_title( $titel, $typ ), $adopt_unmarked ),
			'desc'  => self::sync_field( $post_id, self::FIELD_DESC,  self::MARKER_DESC,  self::build_desc( $titel, $typ ),  $adopt_unmarked ),
		);
	}

	public static function on_save( $post_id ) {
		if ( self::$syncing ) { return; }
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
		self::$syncing = true;
		self::sync_post( (int) $post_id, false ); // Auto-Draft/Platzhalter wird drin uebersprungen
		self::$syncing = false;
	}

	/**
	 * Status-Uebergang in einen echten Status (Entwurf/geplant/veroeffentlicht) → SEO-Meta
	 * mit dem ECHTEN Titel (neu) erzeugen. Faengt den auto-draft → publish/draft-Wechsel ab.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( ! $post || get_post_type( $post ) !== self::PT ) { return; }
		if ( $new_status === $old_status ) { return; }
		if ( ! in_array( $new_status, array( 'draft', 'pending', 'future', 'publish' ), true ) ) { return; }
		if ( self::$syncing ) { return; }
		self::$syncing = true;
		self::sync_post( (int) $post->ID, false );
		self::$syncing = false;
	}

	/** Zustand, in dem KEIN SEO-Autofill erfolgen darf (Auto-Draft / leerer / Platzhalter-Titel). */
	public static function is_placeholder_state( $post_id ) {
		$status = get_post_status( $post_id );
		if ( in_array( $status, array( 'auto-draft', 'trash' ), true ) ) { return true; }
		return self::is_placeholder_title( get_the_title( $post_id ) );
	}

	/** Leerer Titel oder WP-Auto-Draft-Platzhalter (EN + lokalisiert „Automatisch gespeicherter Entwurf"). */
	public static function is_placeholder_title( $titel ) {
		$titel = trim( wp_strip_all_tags( (string) $titel ) );
		if ( '' === $titel ) { return true; }
		$placeholders = array( 'Auto Draft', __( 'Auto Draft' ), 'Automatisch gespeicherter Entwurf' );
		return in_array( $titel, $placeholders, true );
	}

	/** Lazy-Backfill vom Detail-Template (vor get_header). Idempotent. */
	public static function fill_if_empty( $post_id ) {
		self::sync_post( (int) $post_id, false );
	}

	/**
	 * Detail-<title>/og:title: MANUELLER Override (Feld != Marker) verbatim, sonst DYNAMISCH
	 * aus dem aktuellen Post-Titel — so kann nie ein veralteter Auto-Snapshot (z.B. Auto-Draft)
	 * ausgegeben werden.
	 */
	public static function force_detail_title( $title ) {
		if ( ! is_singular( self::PT ) ) {
			return $title;
		}
		$id = get_queried_object_id();
		if ( self::is_manual_override( $id, self::FIELD_TITLE, self::MARKER_TITLE ) ) {
			return trim( (string) get_post_meta( $id, self::FIELD_TITLE, true ) );
		}
		$typ = ( 'neu' === get_post_meta( $id, '_m24_typ', true ) ) ? 'neu' : 'gebraucht';
		return self::build_title( get_the_title( $id ), $typ );
	}

	/** Feld nicht-leer UND != Marker-Hash → bewusst manuell editiert (verbatim ausgeben). */
	private static function is_manual_override( $post_id, $field_key, $marker_key ) {
		$field  = trim( (string) get_post_meta( $post_id, $field_key, true ) );
		$marker = (string) get_post_meta( $post_id, $marker_key, true );
		return ( '' !== $field && '' !== $marker && sha1( $field ) !== $marker );
	}

	/**
	 * Description-Filter:
	 *  - Archiv (/gebrauchtteile/, /rennsport-teile/): statische Texte.
	 *  - Detail-Single: gespeichertes Feld verbatim, sonst Live-Build aus Post-Titel.
	 */
	public static function filter_desc( $desc ) {
		if ( class_exists( 'M24_Catalog_Archive' ) && M24_Catalog_Archive::is_archive() ) {
			return ( 'neu' === M24_Catalog_Archive::current_typ() )
				? 'Rennsport-Teile ✓ Rennsportqualität ✓ Made in Germany ✓ eindeutig per Teilenummer — MOTORSPORT24 seit 2006'
				: 'Original gebrauchte BMW-Teile ✓ geprüft ✓ sofort lieferbar ✓ eindeutig per Teilenummer — MOTORSPORT24 seit 2006';
		}
		if ( is_singular( self::PT ) ) {
			$id = get_queried_object_id();
			if ( self::is_manual_override( $id, self::FIELD_DESC, self::MARKER_DESC ) ) {
				return trim( (string) get_post_meta( $id, self::FIELD_DESC, true ) );
			}
			$typ = ( 'neu' === get_post_meta( $id, '_m24_typ', true ) ) ? 'neu' : 'gebraucht';
			return self::build_desc( get_the_title( $id ), $typ );
		}
		return $desc;
	}

	/**
	 * og:description (FB/LinkedIn): bevorzugt den ECHTEN Beschreibungstext (_m24_beschreibung_de) —
	 * liest sich auf Social-Karten natuerlicher als die Checkmark-Kaskade. Reihenfolge:
	 *   manueller Meta-Override → echter Beschreibungstext → Checkmark-Kaskade (Fallback).
	 * Archiv + Nicht-Teile wie filter_desc. (Meta-Description fuer die Suche bleibt unveraendert.)
	 */
	public static function filter_og_desc( $desc ) {
		if ( ( class_exists( 'M24_Catalog_Archive' ) && M24_Catalog_Archive::is_archive() ) || ! is_singular( self::PT ) ) {
			return self::filter_desc( $desc );
		}
		$id = get_queried_object_id();
		if ( self::is_manual_override( $id, self::FIELD_DESC, self::MARKER_DESC ) ) {
			return trim( (string) get_post_meta( $id, self::FIELD_DESC, true ) );
		}
		$real = trim( wp_strip_all_tags( html_entity_decode( (string) get_post_meta( $id, '_m24_beschreibung_de', true ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( '' !== $real ) {
			$real = preg_replace( '/\s+/', ' ', $real );
			if ( mb_strlen( $real ) > 200 ) { $real = rtrim( mb_substr( $real, 0, 199 ) ) . '…'; }
			return $real;
		}
		$typ = ( 'neu' === get_post_meta( $id, '_m24_typ', true ) ) ? 'neu' : 'gebraucht';
		return self::build_desc( get_the_title( $id ), $typ );
	}
}

// ── CLI ────────────────────────────────────────────────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Cleanup: Teile, deren gespeicherte Yoast-Meta den Auto-Draft-Platzhalter enthaelt
	 * („Auto Draft" / „Automatisch gespeicherter Entwurf"), aus dem ECHTEN Titel neu erzeugen.
	 *
	 * Usage:
	 *   wp m24 fix-seo-autodraft --dry-run
	 *   wp m24 fix-seo-autodraft [--yes]
	 */
	WP_CLI::add_command( 'm24 fix-seo-autodraft', function ( $args, $assoc ) {
		$dry = ! empty( $assoc['dry-run'] );
		$yes = ! empty( $assoc['yes'] );
		$ids = get_posts( array(
			'post_type' => 'm24_teil', 'post_status' => 'any',
			'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
		) );
		$needles = array( 'Auto Draft', __( 'Auto Draft' ), 'Automatisch gespeicherter Entwurf' );
		$hits = array();
		foreach ( $ids as $id ) {
			$t = (string) get_post_meta( $id, M24_Catalog_SEO::FIELD_TITLE, true );
			$d = (string) get_post_meta( $id, M24_Catalog_SEO::FIELD_DESC, true );
			foreach ( $needles as $n ) {
				if ( '' !== $n && ( false !== stripos( $t, $n ) || false !== stripos( $d, $n ) ) ) { $hits[] = $id; break; }
			}
		}
		WP_CLI::log( sprintf( 'Teile mit Auto-Draft-Text in der Yoast-Meta: %d', count( $hits ) ) );
		if ( empty( $hits ) ) { WP_CLI::success( 'Nichts zu korrigieren.' ); return; }
		foreach ( $hits as $id ) { WP_CLI::log( sprintf( '  #%d %s', $id, get_the_title( $id ) ) ); }
		if ( $dry ) { WP_CLI::warning( 'Dry-run: nichts geaendert.' ); return; }
		if ( ! $yes ) { WP_CLI::confirm( sprintf( '%d Teile aus dem echten Titel neu generieren?', count( $hits ) ) ); }

		$fixed = 0; $skipped = 0;
		foreach ( $hits as $id ) {
			if ( M24_Catalog_SEO::is_placeholder_state( $id ) ) { $skipped++; continue; } // noch kein echter Titel
			delete_post_meta( $id, M24_Catalog_SEO::FIELD_TITLE );
			delete_post_meta( $id, M24_Catalog_SEO::FIELD_DESC );
			delete_post_meta( $id, M24_Catalog_SEO::MARKER_TITLE );
			delete_post_meta( $id, M24_Catalog_SEO::MARKER_DESC );
			M24_Catalog_SEO::sync_post( $id, false );
			$fixed++;
		}
		WP_CLI::success( sprintf( 'Korrigiert: %d · uebersprungen (noch Platzhalter-Titel): %d', $fixed, $skipped ) );
	} );

	/**
	 * Bestand nachziehen: Feld + Marker auf allen m24_teil-Posts schreiben/auto-syncen.
	 * Adoptiert nicht-leere Felder OHNE Marker als „auto" (Backfill importierter Teile).
	 * Manuelle Edits (Feld != Marker-Hash) bleiben unangetastet.
	 */
	WP_CLI::add_command( 'm24 reseed-seo-felder', function( $args, $assoc ) {
		$dry      = ! empty( $assoc['dry-run'] );
		$auto_yes = ! empty( $assoc['yes'] );

		$ids = get_posts( array(
			'post_type' => 'm24_teil', 'post_status' => 'any',
			'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
		) );
		if ( empty( $ids ) ) { WP_CLI::log( 'Keine m24_teil-Posts gefunden.' ); return; }

		if ( ! $dry && ! $auto_yes ) {
			WP_CLI::confirm( sprintf( 'SEO-Felder + Marker auf %d Posts schreiben/auto-syncen? Manuelle Edits bleiben unberuehrt.', count( $ids ) ) );
		}

		$stat = array( 'filled' => 0, 'resynced' => 0, 'manual' => 0 );
		foreach ( $ids as $pid ) {
			if ( $dry ) {
				// Read-only-Klassifikation (Title-Feld als Repraesentant).
				$cur = (string) get_post_meta( $pid, M24_Catalog_SEO::FIELD_TITLE, true );
				$mrk = (string) get_post_meta( $pid, M24_Catalog_SEO::MARKER_TITLE, true );
				if ( '' === $cur ) { $stat['filled']++; }
				elseif ( ( '' !== $mrk && sha1( $cur ) === $mrk ) || '' === $mrk ) { $stat['resynced']++; }
				else { $stat['manual']++; }
				continue;
			}
			$r = M24_Catalog_SEO::sync_post( $pid, true );
			$k = isset( $r['title'] ) ? $r['title'] : 'manual';
			if ( isset( $stat[ $k ] ) ) { $stat[ $k ]++; }
		}

		WP_CLI::log( sprintf( 'Posts gesamt: %d', count( $ids ) ) );
		WP_CLI::log( sprintf( '  befuellt (Feld war leer):        %d', $stat['filled'] ) );
		WP_CLI::log( sprintf( '  resynced (auto / adoptiert):     %d', $stat['resynced'] ) );
		WP_CLI::log( sprintf( '  manuell (unangetastet):          %d', $stat['manual'] ) );
		if ( $dry ) { WP_CLI::warning( 'Dry-run: nichts geschrieben.' ); }
		else { WP_CLI::success( 'Reseed abgeschlossen — Feld + Marker stehen, Auto-Sync aktiv.' ); }
	} );

	/**
	 * Felder leeren: _wpseo_edit_title/_description + Marker auf allen m24_teil-Posts.
	 * (Reset auf reines Live-Render; Gegenstueck zum Reseed.)
	 */
	WP_CLI::add_command( 'm24 cleanup-seo-meta', function( $args, $assoc ) {
		$dry      = ! empty( $assoc['dry-run'] );
		$auto_yes = ! empty( $assoc['yes'] );

		$ids = get_posts( array(
			'post_type' => 'm24_teil', 'post_status' => 'any',
			'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
		) );
		if ( empty( $ids ) ) { WP_CLI::log( 'Keine m24_teil-Posts gefunden.' ); return; }

		$with_title = $with_desc = 0;
		foreach ( $ids as $pid ) {
			if ( '' !== trim( (string) get_post_meta( $pid, M24_Catalog_SEO::FIELD_TITLE, true ) ) ) { $with_title++; }
			if ( '' !== trim( (string) get_post_meta( $pid, M24_Catalog_SEO::FIELD_DESC, true ) ) )  { $with_desc++; }
		}
		WP_CLI::log( sprintf( 'Posts gesamt: %d | mit Title: %d | mit Desc: %d', count( $ids ), $with_title, $with_desc ) );
		if ( $dry ) { WP_CLI::warning( 'Dry-run: nichts geloescht.' ); return; }
		if ( ! $auto_yes ) {
			WP_CLI::confirm( sprintf( 'SEO-Felder + Marker auf %d Posts wirklich leeren? Manuelle SEO-Edits gehen verloren.', count( $ids ) ) );
		}
		$cleared = 0;
		foreach ( $ids as $pid ) {
			delete_post_meta( $pid, M24_Catalog_SEO::FIELD_TITLE );
			delete_post_meta( $pid, M24_Catalog_SEO::FIELD_DESC );
			delete_post_meta( $pid, M24_Catalog_SEO::MARKER_TITLE );
			delete_post_meta( $pid, M24_Catalog_SEO::MARKER_DESC );
			$cleared++;
		}
		WP_CLI::success( sprintf( 'SEO-Felder + Marker auf %d Posts geleert.', $cleared ) );
	} );
}
