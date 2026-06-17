<?php
/**
 * M24 Plattform — Katalog: Varianten-Preis-Info (reiner ANZEIGE-Helfer)
 * Modul: catalog-variant-price.php
 *
 * Liefert für die DARSTELLUNG (Detail + Karten/Übersicht) eine kompakte Info über
 * die Preis-Varianten eines Teils: ob mehrere existieren, min/max-Brutto, ob alle
 * gleich sind. Liest AUSSCHLIESSLICH über den bestehenden Leser
 * M24_Catalog_Pricing::raw_options() — NICHT der Speicher-/ß-Decode-Pfad.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'm24_variant_price_info' ) ) {
	/**
	 * @return array {
	 *   hat_varianten:bool,  // mehr als eine Preis-Option vorhanden
	 *   min:float, max:float,
	 *   min_fmt:string, max_fmt:string,
	 *   alle_gleich:bool,    // min === max
	 *   anzahl:int
	 * }
	 */
	function m24_variant_price_info( $post_id ) {
		$out = array(
			'hat_varianten' => false, 'min' => 0.0, 'max' => 0.0,
			'min_fmt' => '', 'max_fmt' => '', 'alle_gleich' => true, 'anzahl' => 0,
		);
		if ( ! class_exists( 'M24_Catalog_Pricing' ) ) { return $out; }

		$bruttos = array();
		foreach ( M24_Catalog_Pricing::raw_options( (int) $post_id ) as $o ) {
			$b = isset( $o['brutto'] ) ? (float) $o['brutto'] : 0.0;
			if ( $b > 0 ) { $bruttos[] = $b; }
		}
		$n = count( $bruttos );
		$out['anzahl'] = $n;
		if ( 0 === $n ) { return $out; }

		$out['min']         = min( $bruttos );
		$out['max']         = max( $bruttos );
		$out['min_fmt']     = M24_Catalog_Pricing::format( $out['min'] );
		$out['max_fmt']     = M24_Catalog_Pricing::format( $out['max'] );
		$out['alle_gleich'] = ( $out['min'] === $out['max'] );
		$out['hat_varianten'] = ( $n > 1 );
		return $out;
	}
}
