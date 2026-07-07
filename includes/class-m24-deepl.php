<?php
/**
 * M24 Plattform — DeepL-Anbindung für englische Angebots-Positionstitel (on-demand, Hash-Cache).
 *
 * Fehlende EN-Titel von Katalog-Positionen (m24_teil) werden bei Bedarf per DeepL übersetzt und pro Artikel
 * gecacht. Neu übersetzt wird automatisch, sobald sich der DE-Titel ändert (md5-Hash-Vergleich). DeepL hat kein
 * eigenes Caching — dieser Hash-Cache ist der Kern, damit nicht bei jedem Angebots-Versand erneut übersetzt
 * (und Quota verbraucht) wird.
 *
 * Meta pro Artikel:
 *   _m24_titel_en        — gecachter EN-Titel
 *   _m24_titel_en_src    — md5() des DE-Titels, aus dem der EN-Titel entstand
 *   _m24_titel_en_manual — manueller Override (falls gesetzt: IMMER verwenden, nie automatisch überschreiben)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_DeepL {

	const OPT_KEY     = 'm24_deepl_api_key';
	const META_EN     = '_m24_titel_en';
	const META_SRC    = '_m24_titel_en_src';
	const META_MANUAL = '_m24_titel_en_manual';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_m24_teil', array( __CLASS__, 'save_manual_override' ) );
		add_action( 'admin_post_m24_deepl_retranslate', array( __CLASS__, 'handle_retranslate' ) );
	}

	/* ── Konfiguration ──────────────────────────────────────────────────── */

	/** API-Key: wp-config-Konstante M24_DEEPL_API_KEY hat Vorrang, sonst WP-Option. Nur server-seitig. */
	public static function api_key(): string {
		$k = defined( 'M24_DEEPL_API_KEY' ) ? (string) M24_DEEPL_API_KEY : (string) get_option( self::OPT_KEY, '' );
		return trim( $k );
	}

	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	/** Zielsprache (Default EN-GB, filterbar). */
	public static function target_lang(): string {
		return (string) apply_filters( 'm24_deepl_target_lang', 'EN-GB' );
	}

	/** Free-Keys enden auf „:fx" → api-free.deepl.com, sonst Pro-Endpoint. */
	private static function endpoint(): string {
		$key  = strtolower( self::api_key() );
		$free = ( '' !== $key && str_ends_with( $key, ':fx' ) );
		return $free ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';
	}

	/* ── Übersetzung ────────────────────────────────────────────────────── */

	/**
	 * Batch-Übersetzung DE→EN in EINER Anfrage. @return array<int,string>|null (gleiche Reihenfolge; null bei Fehler).
	 */
	public static function translate_batch( array $texts ): ?array {
		$texts = array_values( array_map( 'strval', $texts ) );
		if ( empty( $texts ) ) { return array(); }
		if ( ! self::is_configured() ) { self::warn( 'DeepL-Key fehlt (Option m24_deepl_api_key)' ); return null; }

		$body = array(
			'source_lang' => 'DE',
			'target_lang' => self::target_lang(),
			'text'        => $texts, // DeepL erlaubt mehrere text-Parameter (Array)
		);
		$resp = wp_remote_post( self::endpoint(), array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'DeepL-Auth-Key ' . self::api_key(),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => self::encode_body( $body ),
		) );
		if ( is_wp_error( $resp ) ) { self::warn( 'DeepL-Netzwerkfehler: ' . $resp->get_error_message() ); return null; }
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) { self::warn( 'DeepL HTTP ' . $code . ' (' . substr( (string) wp_remote_retrieve_body( $resp ), 0, 200 ) . ')' ); return null; }

		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) || empty( $data['translations'] ) || ! is_array( $data['translations'] ) ) {
			self::warn( 'DeepL: leere/ungültige Antwort' );
			return null;
		}
		$out = array();
		foreach ( $data['translations'] as $t ) {
			$out[] = html_entity_decode( (string) ( $t['text'] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		if ( count( $out ) !== count( $texts ) ) { self::warn( 'DeepL: Anzahl Übersetzungen weicht ab' ); return null; }
		return $out;
	}

	/** Formularbody mit wiederholten text=…-Parametern (DeepL will text=a&text=b, NICHT text[0]=…). */
	private static function encode_body( array $body ): string {
		$parts = array();
		foreach ( $body as $k => $v ) {
			if ( is_array( $v ) ) {
				foreach ( $v as $item ) { $parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $item ); }
			} else {
				$parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
			}
		}
		return implode( '&', $parts );
	}

	/* ── Öffentliche Helfer ─────────────────────────────────────────────── */

	/**
	 * EN-Titel für mehrere Artikel — EINE Batch-DeepL-Anfrage für alle nicht frisch gecachten. Kern für Einzel-
	 * helfer, Angebots-Fill UND die Operator-Live-Vorschau. @return array<int,string> [post_id => EN-Titel].
	 * Manual-Override > frischer Hash-Cache > DeepL. Bei Fehler/leer → DE-Titel (Angebot nie blockieren).
	 */
	public static function en_titles_for( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		$result   = array();
		$need      = array(); // post_id => de-title
		foreach ( $post_ids as $pid ) {
			if ( $pid <= 0 ) { continue; }
			$manual = trim( (string) get_post_meta( $pid, self::META_MANUAL, true ) );
			if ( '' !== $manual ) { $result[ $pid ] = $manual; continue; }
			$de = (string) get_the_title( $pid );
			if ( '' === $de ) { $result[ $pid ] = ''; continue; }
			$cached = (string) get_post_meta( $pid, self::META_EN, true );
			$src    = (string) get_post_meta( $pid, self::META_SRC, true );
			if ( '' !== $cached && $src === md5( $de ) ) { $result[ $pid ] = $cached; continue; } // frisch
			$need[ $pid ] = $de;
		}
		if ( empty( $need ) ) { return $result; }

		$res = self::translate_batch( array_values( $need ) );
		if ( ! is_array( $res ) ) {
			self::warn_once( 'DeepL-Batch fehlgeschlagen — DE-Titel verwendet', (int) array_key_first( $need ) );
			foreach ( $need as $pid => $de ) { $result[ $pid ] = $de; } // Graceful Fallback: DE
			return $result;
		}
		$k = 0;
		foreach ( $need as $pid => $de ) {
			$en = isset( $res[ $k ] ) ? (string) $res[ $k ] : '';
			$k++;
			if ( '' !== $en ) {
				update_post_meta( $pid, self::META_EN, $en );
				update_post_meta( $pid, self::META_SRC, md5( $de ) );
				$result[ $pid ] = $en;
			} else {
				$result[ $pid ] = $de; // Fallback
			}
		}
		return $result;
	}

	/** Nur bereits vorhandener frischer EN-Titel (Manual/Cache) — RUFT NIE DeepL (für Suche/Anzeige ohne Quota).
	 * Leer, wenn (noch) kein frischer Cache existiert → Aufrufer zeigt „EN fehlt" oder holt on-demand nach. */
	public static function cached_en_title( int $post_id ): string {
		$manual = trim( (string) get_post_meta( $post_id, self::META_MANUAL, true ) );
		if ( '' !== $manual ) { return $manual; }
		$de     = (string) get_the_title( $post_id );
		$cached = (string) get_post_meta( $post_id, self::META_EN, true );
		$src    = (string) get_post_meta( $post_id, self::META_SRC, true );
		return ( '' !== $cached && $src === md5( $de ) ) ? $cached : '';
	}

	/** Frischer EN-Titel für EINEN Artikel (Spec-Helfer). DE-Fallback bei Fehler. */
	public static function en_title( int $post_id ): string {
		$post_id = (int) $post_id;
		$map     = self::en_titles_for( array( $post_id ) );
		$en      = (string) ( $map[ $post_id ] ?? '' );
		return '' !== $en ? $en : (string) get_the_title( $post_id );
	}

	/**
	 * Angebots-Positionen (Snapshot) für EN füllen: für ALLE Katalog-Positionen (teil_id>0) den frischen EN-Titel
	 * setzen (Batch) — unabhängig davon, was im Item stand (der Picker liefert nur frisch/leer). Freitext-
	 * Positionen (teil_id=0) behalten ihren vom Operator eingegebenen EN-Titel.
	 */
	public static function fill_item_en_titles( array $items ): array {
		$ids = array();
		foreach ( $items as $it ) { $tid = (int) ( $it['teil_id'] ?? 0 ); if ( $tid > 0 ) { $ids[] = $tid; } }
		if ( empty( $ids ) ) { return $items; }
		$map = self::en_titles_for( $ids );
		foreach ( $items as $i => $it ) {
			$tid = (int) ( $it['teil_id'] ?? 0 );
			if ( $tid > 0 && isset( $map[ $tid ] ) && '' !== $map[ $tid ] ) { $items[ $i ]['title_en'] = $map[ $tid ]; }
		}
		return $items;
	}

	/* ── Admin: Override-Feld + „EN neu übersetzen" (nice-to-have) ───────── */

	public static function add_metabox() {
		add_meta_box( 'm24-deepl-en', 'EN-Titel (DeepL)', array( __CLASS__, 'render_metabox' ), 'm24_teil', 'side', 'default' );
	}

	public static function render_metabox( $post ) {
		$pid    = (int) $post->ID;
		$de     = (string) get_the_title( $pid );
		$manual = (string) get_post_meta( $pid, self::META_MANUAL, true );
		$cached = (string) get_post_meta( $pid, self::META_EN, true );
		$src    = (string) get_post_meta( $pid, self::META_SRC, true );
		$fresh  = ( '' !== $cached && $src === md5( $de ) );
		wp_nonce_field( 'm24_deepl_meta_' . $pid, 'm24_deepl_nonce' );
		echo '<p style="margin:0 0 6px;color:#646970;font-size:12px;">Automatisch per DeepL, gecacht bis sich der DE-Titel ändert.</p>';
		if ( '' !== $manual ) {
			echo '<p style="margin:0 0 8px;"><strong>Override aktiv</strong> — DeepL wird ignoriert.</p>';
		} elseif ( $fresh ) {
			echo '<p style="margin:0 0 8px;color:#1a7a3c;">EN (Cache): <em>' . esc_html( $cached ) . '</em></p>';
		} else {
			echo '<p style="margin:0 0 8px;color:#8a6d3b;">Kein frischer EN-Titel — wird beim nächsten EN-Angebot gezogen.</p>';
		}
		echo '<label for="m24_titel_en_manual" style="display:block;font-weight:600;margin-bottom:3px;">Manueller Override</label>';
		echo '<input type="text" id="m24_titel_en_manual" name="m24_titel_en_manual" value="' . esc_attr( $manual ) . '" class="widefat" placeholder="leer = automatisch" autocomplete="off">';
		if ( self::is_configured() ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=m24_deepl_retranslate&post=' . $pid ), 'm24_deepl_retranslate_' . $pid );
			echo '<p style="margin:10px 0 0;"><a href="' . esc_url( $url ) . '" class="button button-secondary">EN neu übersetzen</a></p>';
		} else {
			echo '<p style="margin:10px 0 0;color:#8a6d3b;font-size:12px;">DeepL-Key in den Plugin-Einstellungen setzen, um automatisch zu übersetzen.</p>';
		}
	}

	public static function save_manual_override( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! isset( $_POST['m24_deepl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['m24_deepl_nonce'] ) ), 'm24_deepl_meta_' . (int) $post_id ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
		$manual = isset( $_POST['m24_titel_en_manual'] ) ? sanitize_text_field( wp_unslash( $_POST['m24_titel_en_manual'] ) ) : '';
		if ( '' === $manual ) { delete_post_meta( $post_id, self::META_MANUAL ); }
		else { update_post_meta( $post_id, self::META_MANUAL, $manual ); }
	}

	public static function handle_retranslate() {
		$pid = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $pid || ! current_user_can( 'edit_post', $pid ) ) { wp_die( 'Nicht erlaubt.' ); }
		check_admin_referer( 'm24_deepl_retranslate_' . $pid );
		// Cache invalidieren und frisch ziehen.
		delete_post_meta( $pid, self::META_EN );
		delete_post_meta( $pid, self::META_SRC );
		self::en_title( $pid );
		wp_safe_redirect( add_query_arg( array( 'm24_deepl' => 'done' ), get_edit_post_link( $pid, 'url' ) ) );
		exit;
	}

	/* ── Logging ────────────────────────────────────────────────────────── */

	private static function warn( string $msg, array $ctx = array() ): void {
		if ( class_exists( 'M24_Error_Log' ) ) { M24_Error_Log::capture( 'deepl', 'warning', $msg, $ctx ); }
		else { error_log( '[M24 DeepL] ' . $msg ); } // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/** Einmal pro Tag warnen (Anti-Spam) — inkl. Art.-Nr., damit die betroffene Position auffindbar ist. */
	private static function warn_once( string $msg, int $post_id ): void {
		if ( get_transient( 'm24_deepl_warned' ) ) { return; }
		set_transient( 'm24_deepl_warned', 1, DAY_IN_SECONDS );
		$art = $post_id > 0 ? (string) get_post_meta( $post_id, '_m24_artikelnummer', true ) : '';
		self::warn( $msg, array( 'post_id' => $post_id, 'art_nr' => $art ) );
	}
}

/** Globaler Helfer laut Spec: frischer EN-Titel eines Artikels (Manual/Cache/DeepL, DE-Fallback). */
if ( ! function_exists( 'm24_en_title' ) ) {
	function m24_en_title( $post_id ) {
		return M24_DeepL::en_title( (int) $post_id );
	}
}
