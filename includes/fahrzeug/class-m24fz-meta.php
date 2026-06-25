<?php
/**
 * M24 Fahrzeug — Meta-Box-Registrierung + Save (Nonce/Cap, Status-Logik)
 * Modul: includes/fahrzeug/class-m24fz-meta.php
 *
 * Speichert alle _m24fz_-Felder. Status (gelistet|verkauft|reserviert|deaktiviert) kommt
 * aus den Switches; „Verkauft" kippt zusätzlich die Aktiv-Kategorie auf das sold-Pendant.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Meta {

	const NONCE = 'm24fz_meta';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . M24FZ_CPT::PT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_normalize_enums' ) );
	}

	/**
	 * Einmalige Normalisierung bestehender Enum-Altwerte (case-insensitive + Alias) → kanonisch.
	 * Behebt z. B. „links" → „Links", „grau" → „Grau", „Gebrauchte" → „Gebraucht" (FIX 2 Bestandsfix).
	 * Läuft genau einmal (Option-Flag). Echte Individualwerte ohne Treffer bleiben unangetastet.
	 */
	public static function maybe_normalize_enums() {
		if ( get_option( 'm24fz_enum_norm_v1' ) || ! current_user_can( 'edit_posts' ) ) { return; }
		$fields = array(
			'_m24fz_neu_gebraucht' => M24FZ_Telemetry::neu_gebraucht_options(),
			'_m24fz_antrieb'       => M24FZ_Telemetry::antrieb_options(),
			'_m24fz_kraftstoff'    => M24FZ_Telemetry::kraftstoff_options(),
			'_m24fz_lenkung'       => M24FZ_Telemetry::lenkung_options(),
			'_m24fz_innenmaterial' => M24FZ_Telemetry::innenmaterial_options(),
			'_m24fz_innenfarbe'    => M24FZ_Telemetry::innenfarbe_options(),
			'_m24fz_karosserie'    => M24FZ_Telemetry::karosserie_options(),
		);
		$ids = get_posts( array( 'post_type' => M24FZ_CPT::PT, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $pid ) {
			foreach ( $fields as $key => $opts ) {
				$cur = (string) get_post_meta( $pid, $key, true );
				if ( '' === $cur ) { continue; }
				$canon = M24FZ_Telemetry::match_enum( $cur, $opts, M24FZ_Telemetry::enum_aliases( $key ) );
				if ( '' !== $canon && $canon !== $cur ) { update_post_meta( $pid, $key, $canon ); }
			}
		}
		update_option( 'm24fz_enum_norm_v1', 1 );
	}

	public static function boxes() {
		add_meta_box( 'm24fz-box', 'Fahrzeug', array( 'M24FZ_Meta_Render', 'box' ), M24FZ_CPT::PT, 'normal', 'high' );
	}

	/** Einfache Text-/Int-Metas. */
	private static function text_keys() {
		return array(
			'_m24fz_template_typ', '_m24fz_baujahr', '_m24fz_leistung_ps',
			'_m24fz_getriebe', '_m24fz_farbe', '_m24fz_tel_opt_label', '_m24fz_tel_opt_value',
			'_m24fz_race_opt1_label', '_m24fz_race_opt1_value', '_m24fz_race_opt2_label', '_m24fz_race_opt2_value',
			'_m24fz_race_opt3_label', '_m24fz_race_opt3_value',
			'_m24fz_erstzulassung', '_m24fz_modell', '_m24fz_fin', '_m24fz_karosserie', '_m24fz_baureihe',
			'_m24fz_hubraum', '_m24fz_lenkung', '_m24fz_antrieb', '_m24fz_kraftstoff', '_m24fz_innenmaterial',
			'_m24fz_innenfarbe', '_m24fz_aussenfarbe', '_m24fz_farbbez_hersteller', '_m24fz_neu_gebraucht',
			'_m24fz_land_erstauslieferung', '_m24fz_standort', '_m24fz_standort_ort', '_m24fz_marke',
		);
	}
	private static function int_keys()  { return array( '_m24fz_preis', '_m24fz_gewicht', '_m24fz_views', '_m24fz_merkliste_count', '_m24fz_anfragen_count', '_m24fz_tel_klicks' ); }
	private static function bool_keys() { return array( '_m24fz_preis_auf_anfrage', '_m24fz_wagenpass', '_m24fz_rennhistorie', '_m24fz_original_design', '_m24_featured' ); }
	private static function gal_keys()  { return array( '_m24fz_gal_aussen', '_m24fz_gal_innen', '_m24fz_gal_motor', '_m24fz_gal_unterboden' ); }

	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( M24FZ_CPT::is_busy() ) { return; } // set_status() löst wp_update_post → save_post aus: Rekursion stoppen
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), self::NONCE ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		foreach ( self::text_keys() as $k ) {
			if ( isset( $_POST[ $k ] ) ) { update_post_meta( $post_id, $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ); }
		}
		foreach ( self::int_keys() as $k ) {
			if ( isset( $_POST[ $k ] ) ) { update_post_meta( $post_id, $k, (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST[ $k ] ) ) ); }
		}
		foreach ( self::bool_keys() as $k ) { update_post_meta( $post_id, $k, isset( $_POST[ $k ] ) ? 1 : 0 ); }

		// Laufleistung: nur ganze Zahl (Punkte/Leerzeichen strippen) + plausibles Maximum (≤ 9.999.999).
		if ( isset( $_POST['_m24fz_laufleistung'] ) ) {
			$km = (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST['_m24fz_laufleistung'] ) );
			if ( $km > 9999999 ) { $km = 9999999; }
			update_post_meta( $post_id, '_m24fz_laufleistung', $km > 0 ? (string) $km : '' );
		}

		// Lange Freitexte.
		update_post_meta( $post_id, '_m24fz_zusammenfassung', wp_kses_post( wp_unslash( $_POST['_m24fz_zusammenfassung'] ?? '' ) ) );
		update_post_meta( $post_id, '_m24fz_beschreibung', wp_kses_post( wp_unslash( $_POST['_m24fz_beschreibung'] ?? '' ) ) );

		// Keyfacts (3–5 Freitexte) + Videos (YouTube-URLs) — Repeater.
		$keyfacts = array();
		foreach ( (array) ( $_POST['_m24fz_keyfacts'] ?? array() ) as $kf ) { $kf = sanitize_text_field( wp_unslash( $kf ) ); if ( '' !== $kf ) { $keyfacts[] = $kf; } }
		update_post_meta( $post_id, '_m24fz_keyfacts', array_slice( $keyfacts, 0, 5 ) );

		$videos = array();
		foreach ( (array) ( $_POST['_m24fz_videos'] ?? array() ) as $v ) { $v = esc_url_raw( wp_unslash( $v ) ); if ( '' !== $v ) { $videos[] = $v; } }
		update_post_meta( $post_id, '_m24fz_videos', $videos );

		// Galerien je Kategorie (CSV von Attachment-IDs, sortiert).
		foreach ( self::gal_keys() as $k ) {
			$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) wp_unslash( $_POST[ $k ] ?? '' ) ) ) ) );
			update_post_meta( $post_id, $k, $ids );
		}

		// Aktiv-Kategorie(n) als Array speichern (Doppel-Rubrik möglich, z. B. M3 CSL). Mind. 1, Default race-cars.
		$kraw = isset( $_POST['_m24fz_kat'] ) ? (array) wp_unslash( $_POST['_m24fz_kat'] ) : array();
		$kats = array_values( array_intersect( array_map( 'sanitize_key', $kraw ), array( 'race-cars', 'classic-cars' ) ) );
		if ( empty( $kats ) ) { $kats = array( 'race-cars' ); }
		update_post_meta( $post_id, '_m24fz_kat', $kats );

		// Status aus Switches → neues Modell (§2). „Gelistet" (keine Switch) lässt Entwurf/Publish
		// in Ruhe, setzt nur die Inserat-Meta; verkauft/reserviert/deaktiviert via set_status.
		if ( isset( $_POST['m24fz_deaktiviert'] ) ) { M24FZ_CPT::set_status( $post_id, 'deaktiviert' ); }
		elseif ( isset( $_POST['m24fz_verkauft'] ) ) { M24FZ_CPT::set_status( $post_id, 'verkauft' ); }
		elseif ( isset( $_POST['m24fz_reserviert'] ) ) { M24FZ_CPT::set_status( $post_id, 'reserviert' ); }
		else {
			delete_post_meta( $post_id, M24FZ_CPT::INSERAT_META );
			update_post_meta( $post_id, '_m24fz_status', ( 'private' === get_post_status( $post_id ) ) ? 'deaktiviert' : ( in_array( get_post_status( $post_id ), array( 'draft', 'pending', 'auto-draft' ), true ) ? 'entwurf' : 'gelistet' ) );
			wp_set_object_terms( $post_id, $kat, M24FZ_CPT::TAX, false ); // Aktiv-Kategorie (kein Sold-Flip)
		}

		// Galerie-Alt-Text automatisch nachziehen (alle Galerie-Attachments).
		self::sync_gallery_alt( $post_id );
	}

	/** Alt-Text: „{post_title} – {Kategorie} Ansicht {n}" pro Galerie. */
	private static function sync_gallery_alt( $post_id ) {
		$title = get_the_title( $post_id );
		$map   = array( '_m24fz_gal_aussen' => 'Außen', '_m24fz_gal_innen' => 'Innen', '_m24fz_gal_motor' => 'Motor', '_m24fz_gal_unterboden' => 'Unterboden' );
		foreach ( $map as $key => $label ) {
			$ids = (array) get_post_meta( $post_id, $key, true );
			$n = 0;
			foreach ( $ids as $aid ) {
				$n++;
				update_post_meta( (int) $aid, '_wp_attachment_image_alt', sprintf( '%s – %s Ansicht %d', $title, $label, $n ) );
			}
		}
	}
}
