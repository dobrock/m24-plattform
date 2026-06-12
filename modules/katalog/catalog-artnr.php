<?php
/**
 * M24 Plattform — Katalog: Artikelnummern-Generator
 * Modul: catalog-artnr.php
 *
 * Vergibt beim Anlegen automatisch eine fortlaufende Artikelnummer (Default
 * "M24-00001"), sofern das Feld leer ist. Eigene Nummern bleiben unangetastet.
 * Format über Filter `m24_catalog_artnr_format` anpassbar; Startwert über die
 * Option `m24_catalog_artnr_counter` (z. B. um an Bestandsnummern anzuknüpfen).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Artnr {

	const OPTION = 'm24_catalog_artnr_counter';

	public static function init() {
		// Priorität 20: läuft nach dem Speichern der Felder (Prio 10).
		add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'maybe_assign' ), 20, 2 );
	}

	public static function format( $n ) {
		return apply_filters( 'm24_catalog_artnr_format', 'M24-' . str_pad( (string) $n, 5, '0', STR_PAD_LEFT ), $n );
	}

	/** Nächste Nummer nur ansehen (ohne zu verbrauchen) — für den Platzhalter. */
	public static function peek_next() {
		return self::format( (int) get_option( self::OPTION, 0 ) + 1 );
	}

	/** Nächste Nummer verbrauchen (Zähler erhöhen). */
	public static function consume_next() {
		$n = (int) get_option( self::OPTION, 0 ) + 1;
		update_option( self::OPTION, $n );
		return self::format( $n );
	}

	public static function maybe_assign( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}
		$current = get_post_meta( $post_id, '_m24_artikelnummer', true );
		if ( '' === trim( (string) $current ) ) {
			update_post_meta( $post_id, '_m24_artikelnummer', self::consume_next() );
		}
	}
}
