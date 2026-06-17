<?php
/**
 * M24 Plattform — Shopware-Import: Varianten-Pre-Fill für _m24_preisoptionen
 * Modul: modules/importer/import-shopware-variants.php
 *
 * Zieht für Teile mit _m24_sw_id die Shopware-Varianten (children + options + price)
 * und füllt unsere Preisoptionen vor — damit Daniel Varianten nicht je Teil von Hand
 * pflegen muss.
 *
 * KOLLISIONSSCHUTZ:
 *  - Geschrieben wird mit EXAKT der 0.9.7-Serialisierung des Save-Pfads
 *    (M24_Catalog_Pricing::clean_label + wp_json_encode(JSON_UNESCAPED_UNICODE)) —
 *    gleiche Funktionen, gleiches Format → kein ß/ä-Bruch (u00df). Kein abweichender
 *    Roh-Writer. catalog-fields/catalog-pricing werden NICHT verändert.
 *  - NICHT-DESTRUKTIV: handgepflegte _m24_preisoptionen (Label gesetzt, kein
 *    Auto-Marker) werden übersprungen. Auto-Befüllungen (Marker _m24_preisopt_quelle=
 *    'sw-variants') gelten als resyncbar. Force überschreibt alles.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Shopware_Variants {

	const MARKER_META = '_m24_preisopt_quelle';
	const MARKER_VAL  = 'sw-variants';

	/** Worklist: alle Teile mit _m24_sw_id (deterministisch). Skip/Force entscheidet process_chunk. */
	public static function build_worklist() {
		$ids = get_posts( array(
			'post_type' => 'm24_teil', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array(
				'relation' => 'AND',
				array( 'key' => '_m24_sw_id', 'compare' => 'EXISTS' ),
				array( 'key' => '_m24_sw_id', 'value' => '', 'compare' => '!=' ),
			),
		) );
		$ids = array_map( 'intval', (array) $ids );
		sort( $ids );
		return $ids;
	}

	/**
	 * Handgepflegt = mind. eine Option mit nicht-leerem Label UND kein Auto-Marker.
	 * Auto-befüllte Teile (Marker) sind im Default-Modus resyncbar.
	 */
	public static function is_manual_maintained( $pid ) {
		if ( self::MARKER_VAL === (string) get_post_meta( (int) $pid, self::MARKER_META, true ) ) { return false; }
		if ( ! class_exists( 'M24_Catalog_Pricing' ) ) { return false; }
		foreach ( M24_Catalog_Pricing::raw_options( (int) $pid ) as $o ) {
			if ( '' !== trim( (string) ( $o['label'] ?? '' ) ) ) { return true; }
		}
		return false;
	}

	/** Brutto-Preis (Default-Währung/erste) aus einem Shopware-Produkt/-Variante. */
	private static function price_gross( $entity ) {
		$p = isset( $entity['price'] ) && is_array( $entity['price'] ) ? $entity['price'] : array();
		foreach ( $p as $row ) {
			if ( is_array( $row ) && isset( $row['gross'] ) ) { return (float) $row['gross']; }
		}
		if ( isset( $entity['calculatedPrice']['totalPrice'] ) ) { return (float) $entity['calculatedPrice']['totalPrice']; }
		return 0.0;
	}

	/**
	 * Aus einem Shopware-Eltern-Produkt die Varianten extrahieren.
	 * @return array Liste von { label, sku, brutto } (nach Preis aufsteigend).
	 */
	public static function extract_variants( $product ) {
		$children = isset( $product['children'] ) && is_array( $product['children'] ) ? $product['children'] : array();
		$parent_gross = self::price_gross( $product );
		$out = array();
		foreach ( $children as $c ) {
			if ( ! is_array( $c ) ) { continue; }
			$names = array();
			$opts  = isset( $c['options'] ) && is_array( $c['options'] ) ? $c['options'] : array();
			foreach ( $opts as $o ) {
				$n = is_array( $o ) && isset( $o['name'] ) ? trim( (string) $o['name'] ) : '';
				if ( '' !== $n ) { $names[] = $n; }
			}
			sort( $names );
			$label = implode( ' / ', $names );
			$gross = self::price_gross( $c );
			if ( $gross <= 0 ) { $gross = $parent_gross; } // Variante erbt Eltern-Preis
			$sku = isset( $c['productNumber'] ) ? (string) $c['productNumber'] : '';
			if ( '' === $label && $gross <= 0 ) { continue; }
			$out[] = array( 'label' => $label, 'sku' => $sku, 'brutto' => round( (float) $gross, 2 ) );
		}
		usort( $out, function ( $a, $b ) { return $a['brutto'] <=> $b['brutto']; } );
		return $out;
	}

	/**
	 * Optionen am Teil speichern — EXAKT wie der 0.9.7-Save-Pfad serialisiert.
	 * @return int Anzahl gespeicherter Optionen (0 = nichts geschrieben).
	 */
	public static function save_options( $pid, array $variants ) {
		$pid   = (int) $pid;
		$modus = ( 'paragraf25a' === get_post_meta( $pid, '_m24_mwst_modus', true ) ) ? 'paragraf25a' : 'regel';
		$satz  = class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::MWST_SATZ : 0.19;

		$options = array();
		foreach ( $variants as $v ) {
			$label  = class_exists( 'M24_Catalog_Pricing' )
				? M24_Catalog_Pricing::clean_label( sanitize_text_field( (string) $v['label'] ) )
				: sanitize_text_field( (string) $v['label'] );
			$brutto = round( (float) $v['brutto'], 2 );
			if ( $brutto <= 0 && '' === $label ) { continue; }
			$netto  = ( 'paragraf25a' === $modus ) ? null : round( $brutto / ( 1 + $satz ), 2 );
			$options[] = array(
				'label'  => $label,
				'art_nr' => sanitize_text_field( (string) ( $v['sku'] ?? '' ) ),
				'netto'  => $netto,
				'brutto' => $brutto,
			);
		}
		if ( empty( $options ) ) { return 0; }

		// 0.9.7-Serialisierung gespiegelt: echte UTF-8-Zeichen (kein ß-Bruch).
		update_post_meta( $pid, '_m24_preisoptionen', wp_json_encode( $options, JSON_UNESCAPED_UNICODE ) );
		// Legacy-Single-Preis synchron halten (wie catalog-fields::save) — Sort/Backward-Compat.
		$first  = $options[0];
		$legacy = ( 'paragraf25a' === $modus ) ? $first['brutto'] : ( $first['netto'] ?? 0 );
		update_post_meta( $pid, '_m24_preis_netto', (float) $legacy );
		// Marker: Quelle = Shopware-Auto → im Default resyncbar, schützt Daniels Handarbeit.
		update_post_meta( $pid, self::MARKER_META, self::MARKER_VAL );
		return count( $options );
	}

	/**
	 * Chunk verarbeiten: handgepflegte überspringen (ohne Fetch), Rest per Batch aus Shopware
	 * ziehen + vorausfüllen. Persist-first je Teil. Wirft NIE pro Teil.
	 *
	 * @return array { processed, new, skipped, errors, unresolved, img_pending }
	 */
	public static function process_chunk( array $post_ids, $force = false ) {
		$r = array( 'processed' => 0, 'new' => 0, 'skipped' => 0, 'errors' => 0, 'unresolved' => 0, 'img_pending' => 0 );
		if ( empty( $post_ids ) ) { return $r; }

		$todo = array(); $sw = array();
		foreach ( $post_ids as $pid ) {
			$pid = (int) $pid;
			$r['processed']++;
			if ( ! $force && self::is_manual_maintained( $pid ) ) {
				$r['skipped']++;
				M24_Import_Log::log( sprintf( 'Varianten #%d: übersprungen (bereits gepflegt)', $pid ) );
				continue;
			}
			$s = (string) get_post_meta( $pid, '_m24_sw_id', true );
			if ( '' === $s ) { $r['skipped']++; continue; }
			$todo[ $pid ] = $s; $sw[] = $s;
		}
		if ( empty( $todo ) ) { return $r; }

		if ( ! class_exists( 'M24_Shopware_Client' ) ) { $r['errors'] += count( $todo ); return $r; }
		try {
			$products = ( new M24_Shopware_Client() )->fetch_products_by_ids( array_values( array_unique( $sw ) ) );
		} catch ( Exception $e ) {
			$r['errors'] += count( $todo );
			M24_Import_Log::log( 'Varianten: Shopware-Fetch-Fehler — ' . $e->getMessage() );
			return $r;
		}
		$by = array();
		foreach ( (array) $products as $p ) { $by[ (string) ( $p['id'] ?? '' ) ] = $p; }

		foreach ( $todo as $pid => $s ) {
			$p = isset( $by[ $s ] ) ? $by[ $s ] : null;
			if ( null === $p ) {
				$r['errors']++;
				M24_Import_Log::log( sprintf( 'Varianten #%d: Shopware-Produkt nicht gefunden', $pid ) );
				continue;
			}
			$variants = self::extract_variants( $p );
			if ( count( $variants ) < 1 ) {
				$r['skipped']++;
				M24_Import_Log::log( sprintf( 'Varianten #%d: keine Shopware-Varianten — Basispreis bleibt', $pid ) );
				continue;
			}
			$n = self::save_options( $pid, $variants ); // persist-first je Teil
			if ( $n > 0 ) {
				$r['new']++;
				M24_Import_Log::log( sprintf( 'Varianten #%d: %d Optionen übernommen', $pid, $n ) );
			} else {
				$r['skipped']++;
			}
		}
		return $r;
	}
}
