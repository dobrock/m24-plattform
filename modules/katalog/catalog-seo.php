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

	const PT             = 'm24_teil';
	const TITLE_MAX      = 75;   // Soft-Cap: SERP-tauglich. Reicht der Platz nicht, wird zuerst das Boilerplate
	                            //   reduziert — zuletzt ganz weggelassen (nur {Titel}), nie die Teilenummern.
	const TITLE_HARD_MAX = 100;  // Hard-Cap: erst hier wird der Titel SELBST gekuerzt — an Wortgrenze, nie in einer Nummer

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
		add_filter( 'wpseo_set_title',        array( __CLASS__, 'front_title' ),        99, 1 ); // Startseite: fixer Title, keine Dopplung
		add_filter( 'wpseo_set_title',        array( __CLASS__, 'force_detail_title' ), 99, 1 );
		// SEO #2: Index-Schalter fuer Teile-Detailseiten (Default noindex,follow bis zum Flip).
		add_filter( 'wpseo_set_robots',       array( __CLASS__, 'filter_robots' ),      99, 1 );
		// OG/Twitter werden vollstaendig von M24_Catalog_OG ausgegeben (eine Quelle, inkl. og:image
		// + Doubletten-Strip). force_detail_title()/filter_og_desc() bleiben als Helfer dafuer public.
		// Feld-Autofill + Marker. Prio 30: nach fields::save(10)/compact_bmw_in_title(15)/
		// artnr(20)/auto_slug(25) — arbeitet mit dem finalen Post-Titel. Auto-Draft wird in
		// sync_post() uebersprungen; der Status-Uebergang in einen echten Status generiert neu.
		add_action( 'save_post_' . self::PT,    array( __CLASS__, 'on_save' ), 30, 1 );
		add_action( 'transition_post_status',   array( __CLASS__, 'on_transition' ), 20, 3 );
	}

	/** Startseite: exakter, einfacher Title (kein angehaengter Site-Name → keine Dopplung). */
	public static function front_title( $title ) {
		if ( is_front_page() ) {
			return (string) apply_filters( 'm24_front_title', 'MOTORSPORT24 seit 2006 - Hochwertige Fahrzeuge + Rennsport Teile' );
		}
		return $title;
	}

	// ── Title-Kaskade ────────────────────────────────────────────────────
	public static function build_title( $titel, $typ ) {
		// HTML-Entities aus dem Titel dekodieren (get_the_title liefert via wptexturize z.B.
		// „10&#215;18"). Sonst landen sie doppelt-escaped in Title/Description/og.
		$titel = trim( wp_strip_all_tags( html_entity_decode( (string) $titel, ENT_QUOTES, 'UTF-8' ) ) );
		// Teilenummern haben SEO-Vorrang: der volle Beitragstitel bleibt erhalten. Nur wenn er ALLEIN
		// den Hard-Cap reisst, an der naechsten Wortgrenze kuerzen (nie mitten in einer Nummer).
		$titel = self::hard_cap_title( $titel, self::TITLE_HARD_MAX );
		// 0.9.21: EINHEITLICHE Kaskade für beide Typen — „Original gebraucht" lebt in der
		// Description, nicht mehr im Titel. $typ bleibt im Signatur-Vertrag (Aufrufer), wird
		// hier aber nicht mehr verzweigt.
		$variants = array(
			$titel . ' | MOTORSPORT24 seit 2006',
			$titel . ' | MOTORSPORT24',
			$titel, // bar: Suffix weg, bevor der Titel/Teilenummern leiden
		);
		// Boilerplate zuerst opfern; Titel selbst wird hier NIE gekuerzt (steht ggf. ueber dem Soft-Cap).
		foreach ( $variants as $v ) {
			if ( mb_strlen( $v ) <= self::TITLE_MAX ) { return $v; }
		}
		return end( $variants ); // = bare {Titel} (ggf. hard-capped), ohne Suffix
	}

	/** Kuerzt NUR den Titel selbst, wenn er allein > $max ist — an der letzten Wortgrenze, mit „…". */
	private static function hard_cap_title( $titel, $max ) {
		if ( mb_strlen( $titel ) <= $max ) { return $titel; }
		$cut = mb_substr( $titel, 0, $max );
		$sp  = mb_strrpos( $cut, ' ' );
		if ( false !== $sp && $sp > 0 ) { $cut = mb_substr( $cut, 0, $sp ); }
		return rtrim( $cut ) . '…';
	}

	// ── Description-Kaskade ─────────────────────────────────────────────
	public static function build_desc( $titel, $typ ) {
		$titel = trim( wp_strip_all_tags( html_entity_decode( (string) $titel, ENT_QUOTES, 'UTF-8' ) ) );
		$max   = 155;
		// 0.9.21-Templates. „Seit 2006" ist PFLICHT und wird NIE weggekürzt.
		if ( 'neu' === $typ ) {
			$lead    = $titel;
			$bullets = array( 'Rennsportqualität', 'Made in Germany', 'Seit 2006', 'weltweiter Versand' );
		} else {
			$lead    = $titel . ' – Original gebraucht';
			$bullets = array( 'geprüft', 'Seit 2006', 'weltweiter Versand' );
		}
		$protected = 'Seit 2006';
		$compose   = function ( $lead, $bullets ) {
			return $bullets ? ( $lead . ' ✓ ' . implode( ' ✓ ', $bullets ) ) : $lead;
		};

		// 1) Bei Überlänge ✓-Bullets von HINTEN droppen — „Seit 2006" überspringen/behalten.
		while ( mb_strlen( $compose( $lead, $bullets ) ) > $max && count( $bullets ) > 1 ) {
			$removed = false;
			for ( $i = count( $bullets ) - 1; $i >= 0; $i-- ) {
				if ( $bullets[ $i ] !== $protected ) { array_splice( $bullets, $i, 1 ); $removed = true; break; }
			}
			if ( ! $removed ) { break; } // nur noch „Seit 2006" übrig
		}

		// 2) Reicht noch nicht → Lead (post_title) an Wortgrenze kürzen, „✓ Seit 2006" behalten.
		$full = $compose( $lead, $bullets );
		if ( mb_strlen( $full ) > $max ) {
			$suffix = in_array( $protected, $bullets, true ) ? ' ✓ ' . $protected : '';
			$budget = $max - mb_strlen( $suffix );
			if ( $budget < 12 ) { $budget = 12; }
			if ( mb_strlen( $lead ) > $budget ) {
				$cut = mb_substr( $lead, 0, $budget - 1 );
				$sp  = mb_strrpos( $cut, ' ' );
				if ( false !== $sp && $sp > 0 ) { $cut = mb_substr( $cut, 0, $sp ); }
				$lead = rtrim( $cut ) . '…';
			}
			$full = $lead . $suffix;
		}
		return $full;
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
		// ENTSCHEIDUNG 0.9.20: Single Source of Truth = post_title. Das wpSEO-Feld ist nur
		// ein sichtbarer Spiegel und wird IMMER bedingungslos aus post_title überschrieben
		// (keine „manuell editiert"-Schonung mehr — alte Import-Werte sind falsch). Plain-
		// Text-Meta → kein JSON-Escape, ß/ä bleiben intakt.
		update_post_meta( $post_id, $field_key, $new_value );
		update_post_meta( $post_id, $marker_key, sha1( $new_value ) ); // nur noch informativ
		return ( '' === (string) get_post_meta( $post_id, $field_key, true ) ) ? 'filled' : 'resynced';
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

	/** Alle m24_teil-IDs (deterministisch) für den Backfill. */
	public static function all_ids() {
		$ids = get_posts( array(
			'post_type' => self::PT, 'post_status' => 'any', 'posts_per_page' => -1,
			'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC',
		) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Backfill-Chunk: SEO-Title + Description je Teil bedingungslos aus post_title neu
	 * erzeugen (Single Source). Idempotent, persist je Teil. Für den Admin-Button + CLI.
	 *
	 * @return array { processed, new, skipped, errors, unresolved, img_pending }
	 */
	public static function resync_chunk( array $post_ids ) {
		$r = array( 'processed' => 0, 'new' => 0, 'skipped' => 0, 'errors' => 0, 'unresolved' => 0, 'img_pending' => 0 );
		foreach ( $post_ids as $pid ) {
			$pid = (int) $pid;
			$r['processed']++;
			if ( self::is_placeholder_state( $pid ) ) {
				$r['skipped']++;
				if ( class_exists( 'M24_Import_Log' ) ) { M24_Import_Log::log( sprintf( 'SEO #%d: übersprungen (Platzhalter-/Auto-Draft-Titel)', $pid ) ); }
				continue;
			}
			self::sync_post( $pid, false ); // bedingungsloses Overwrite (sync_field schreibt immer)
			$r['new']++;
			if ( class_exists( 'M24_Import_Log' ) ) { M24_Import_Log::log( sprintf( 'SEO #%d: Titel+Description neu generiert', $pid ) ); }
		}
		return $r;
	}

	/**
	 * Detail-<title>/og:title: MANUELLER Override (Feld != Marker) verbatim, sonst DYNAMISCH
	 * aus dem aktuellen Post-Titel — so kann nie ein veralteter Auto-Snapshot (z.B. Auto-Draft)
	 * ausgegeben werden.
	 */
	/**
	 * Robots fuer Teile-Detailseiten (wpSEO-Filter): per globalem Schalter index/noindex.
	 * Default = noindex,follow (bis zum Flip). Andere Seiten unangetastet.
	 */
	public static function filter_robots( $robots ) {
		if ( is_singular( self::PT ) ) {
			return function_exists( 'm24_teile_index_enabled' ) && m24_teile_index_enabled()
				? 'index, follow'
				: 'noindex, follow';
		}
		return $robots;
	}

	public static function force_detail_title( $title ) {
		if ( ! is_singular( self::PT ) ) {
			return $title;
		}
		// Single Source = post_title: IMMER frisch bauen (kein „manuell editiert"-Branch).
		// Dieser eine Filter unterdrückt zugleich den wpSEO-Blogname-Append (exakter Titel).
		$id  = get_queried_object_id();
		$typ = ( 'neu' === get_post_meta( $id, '_m24_typ', true ) ) ? 'neu' : 'gebraucht';
		return self::build_title( get_the_title( $id ), $typ );
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
			$id  = get_queried_object_id();
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
	/**
	 * Backfill: SEO-Title + Description ALLER Teile bedingungslos aus post_title neu
	 * erzeugen (Single Source of Truth). Gegenstück zum Admin-Button „SEO-Titel neu
	 * generieren". Usage: wp m24 resync-seo-titles --all [--yes]
	 */
	/**
	 * Backfill (Plesk-tauglich): wpSEO-Title + Description ALLER Teile aus post_title neu
	 * erzeugen — in Häppchen (25er-Chunks, ~20s-Elapsed-Guard), resümierbar (Offset-Option),
	 * idempotent. Bild-ALT (Galerie/Lightbox) wird render-seitig aus post_title gesetzt und
	 * braucht KEINEN DB-Backfill. Usage: wp m24 resync-seo-meta --all [--yes]
	 */
	WP_CLI::add_command( 'm24 resync-seo-meta', function ( $args, $assoc ) {
		if ( empty( $assoc['all'] ) ) { WP_CLI::error( '--all erforderlich.' ); }
		$ids   = M24_Catalog_SEO::all_ids();
		$total = count( $ids );
		if ( 0 === $total ) { WP_CLI::log( 'Keine m24_teil-Posts.' ); return; }
		if ( empty( $assoc['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'wpSEO-Title + Description auf %d Teilen aus dem Artikel-Titel neu erzeugen?', $total ) );
		}
		$opt    = 'm24_resync_seo_meta_offset';
		$offset = (int) get_option( $opt, 0 );
		if ( $offset >= $total ) { $offset = 0; } // vorheriger Lauf fertig → frisch
		$start = time(); $done = 0; $skip = 0;
		while ( $offset < $total && ( time() - $start ) < 20 ) {
			$slice  = array_slice( $ids, $offset, 25 );
			$r      = M24_Catalog_SEO::resync_chunk( $slice );
			$done  += (int) $r['new']; $skip += (int) $r['skipped'];
			$offset += count( $slice );
			update_option( $opt, $offset, false );
			WP_CLI::log( sprintf( '  %d/%d · ok %d · skip %d', $offset, $total, $done, $skip ) );
		}
		if ( $offset >= $total ) {
			delete_option( $opt );
			WP_CLI::success( sprintf( 'Fertig: %d Teile · %d generiert · %d übersprungen (Platzhalter). Bild-ALT ist render-seitig (kein Backfill nötig).', $total, $done, $skip ) );
		} else {
			WP_CLI::warning( sprintf( '20s-Budget erreicht bei %d/%d — erneut ausführen: wp m24 resync-seo-meta --all --yes', $offset, $total ) );
		}
	} );

	WP_CLI::add_command( 'm24 resync-seo-titles', function ( $args, $assoc ) {
		$ids = M24_Catalog_SEO::all_ids();
		if ( empty( $ids ) ) { WP_CLI::log( 'Keine m24_teil-Posts gefunden.' ); return; }
		if ( empty( $assoc['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'SEO-Title + Description auf %d Teilen bedingungslos aus dem Artikel-Titel neu erzeugen?', count( $ids ) ) );
		}
		$r = M24_Catalog_SEO::resync_chunk( $ids );
		WP_CLI::success( sprintf( 'Fertig: %d verarbeitet · %d neu generiert · %d übersprungen (Platzhalter).', $r['processed'], $r['new'], $r['skipped'] ) );
	} );

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
