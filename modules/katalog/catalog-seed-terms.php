<?php
/**
 * M24 Plattform — Katalog: Kategorie-Seeder
 * Modul: catalog-seed-terms.php
 *
 * Legt die hierarchische „Passend für"-Modellstruktur EINMALIG an (flag-guarded).
 * Begriffe sind die reinen Modell-Bezeichnungen (Fitment); die Wörter
 * „Gebraucht/Rennsport" stecken im Typ bzw. in den Seiten-Überschriften, nicht
 * im Begriff. Erweitern/Umbenennen jederzeit unter „Passend für" möglich.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Seed_Terms {

	const FLAG = 'm24_catalog_terms_seeded_v1';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ) );
	}

	public static function maybe_seed() {
		if ( get_option( self::FLAG ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tax  = M24_Catalog_CPT::TAXONOMY;
		$tree = array(
			'BMW 3er'        => array( 'BMW M3 E30', 'BMW M3 E36', 'BMW M3 E46', 'BMW M3 E9x' ),
			'BMW F-Modelle'  => array(),
			'BMW M Sonstige' => array(),
		);
		foreach ( $tree as $parent => $children ) {
			$p   = term_exists( $parent, $tax );
			if ( ! $p ) {
				$p = wp_insert_term( $parent, $tax );
			}
			$pid = ( is_array( $p ) && ! is_wp_error( $p ) ) ? (int) $p['term_id'] : 0;
			foreach ( $children as $child ) {
				if ( ! term_exists( $child, $tax ) ) {
					wp_insert_term( $child, $tax, array( 'parent' => $pid ) );
				}
			}
		}
		update_option( self::FLAG, 1 );
	}
}
