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
	}

	public static function boxes() {
		add_meta_box( 'm24fz-box', 'Fahrzeug', array( 'M24FZ_Meta_Render', 'box' ), M24FZ_CPT::PT, 'normal', 'high' );
	}

	/** Einfache Text-/Int-Metas. */
	private static function text_keys() {
		return array(
			'_m24fz_template_typ', '_m24fz_baujahr', '_m24fz_laufleistung', '_m24fz_leistung_ps',
			'_m24fz_getriebe', '_m24fz_farbe', '_m24fz_tel_opt_label', '_m24fz_tel_opt_value',
			'_m24fz_race_opt1_label', '_m24fz_race_opt1_value', '_m24fz_race_opt2_label', '_m24fz_race_opt2_value',
			'_m24fz_race_opt3_label', '_m24fz_race_opt3_value',
			'_m24fz_erstzulassung', '_m24fz_modell', '_m24fz_fin', '_m24fz_karosserie', '_m24fz_baureihe',
			'_m24fz_hubraum', '_m24fz_lenkung', '_m24fz_antrieb', '_m24fz_kraftstoff', '_m24fz_innenmaterial',
			'_m24fz_innenfarbe', '_m24fz_aussenfarbe', '_m24fz_farbbez_hersteller', '_m24fz_neu_gebraucht',
			'_m24fz_land_erstauslieferung', '_m24fz_standort', '_m24fz_standort_ort', '_m24fz_marke',
		);
	}
	private static function int_keys()  { return array( '_m24fz_preis', '_m24fz_views', '_m24fz_merkliste_count', '_m24fz_anfragen_count', '_m24fz_tel_klicks' ); }
	private static function bool_keys() { return array( '_m24fz_preis_auf_anfrage', '_m24fz_wagenpass', '_m24fz_rennhistorie' ); }
	private static function gal_keys()  { return array( '_m24fz_gal_aussen', '_m24fz_gal_innen', '_m24fz_gal_motor', '_m24fz_gal_unterboden' ); }

	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) ) { return; }
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), self::NONCE ) ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		foreach ( self::text_keys() as $k ) {
			if ( isset( $_POST[ $k ] ) ) { update_post_meta( $post_id, $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) ); }
		}
		foreach ( self::int_keys() as $k ) {
			if ( isset( $_POST[ $k ] ) ) { update_post_meta( $post_id, $k, (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST[ $k ] ) ) ); }
		}
		foreach ( self::bool_keys() as $k ) { update_post_meta( $post_id, $k, isset( $_POST[ $k ] ) ? 1 : 0 ); }

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

		// Status aus Switches (Deaktiviert > Verkauft > Reserviert > gelistet).
		$status = isset( $_POST['m24fz_deaktiviert'] ) ? 'deaktiviert'
			: ( isset( $_POST['m24fz_verkauft'] ) ? 'verkauft'
			: ( isset( $_POST['m24fz_reserviert'] ) ? 'reserviert' : 'gelistet' ) );
		update_post_meta( $post_id, '_m24fz_status', $status );

		// Aktiv-Kategorie + Verkauft-Flip.
		$kat = in_array( ( $_POST['_m24fz_kat'] ?? '' ), array( 'race-cars', 'classic-cars' ), true ) ? $_POST['_m24fz_kat'] : 'race-cars';
		$term = ( 'verkauft' === $status && isset( M24FZ_CPT::SOLD_MAP[ $kat ] ) ) ? M24FZ_CPT::SOLD_MAP[ $kat ] : $kat;
		update_post_meta( $post_id, '_m24fz_kat', $kat );
		wp_set_object_terms( $post_id, $term, M24FZ_CPT::TAX, false );

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
