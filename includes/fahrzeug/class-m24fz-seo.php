<?php
/**
 * M24 Fahrzeug — SEO: JSON-LD (Vehicle/Offer/Breadcrumb), Robots, Title/Meta, 404 für deaktiviert
 * Modul: includes/fahrzeug/class-m24fz-seo.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_SEO {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'gate_disabled' ), 1 );
		add_filter( 'wpseo_set_robots', array( __CLASS__, 'robots' ), 99 );
		add_filter( 'wpseo_set_title', array( __CLASS__, 'title' ), 99 );
		add_filter( 'wpseo_set_desc', array( __CLASS__, 'desc' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'json_ld' ), 20 );
	}

	/** „deaktiviert" → Frontend weg: 404 + noindex. */
	public static function gate_disabled() {
		if ( ! is_singular( M24FZ_CPT::PT ) ) { return; }
		$id = get_queried_object_id();
		if ( M24FZ_CPT::is_disabled( $id ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public static function robots( $robots ) {
		if ( ! is_singular( M24FZ_CPT::PT ) ) { return $robots; }
		return M24FZ_CPT::is_disabled( get_queried_object_id() ) ? 'noindex, follow' : 'index, follow';
	}

	/** Title/Desc über die bestehende M24-Pipeline (post_title, 75-Limit, Cascade). */
	public static function title( $title ) {
		if ( ! is_singular( M24FZ_CPT::PT ) || ! class_exists( 'M24_Catalog_SEO' ) ) { return $title; }
		return M24_Catalog_SEO::build_title( get_the_title( get_queried_object_id() ), 'neu' );
	}
	public static function desc( $desc ) {
		if ( ! is_singular( M24FZ_CPT::PT ) || ! class_exists( 'M24_Catalog_SEO' ) ) { return $desc; }
		$id  = get_queried_object_id();
		$sum = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_m24fz_zusammenfassung', true ) ) );
		if ( '' !== $sum ) { $sum = preg_replace( '/\s+/', ' ', $sum ); return mb_strlen( $sum ) > 155 ? rtrim( mb_substr( $sum, 0, 154 ) ) . '…' : $sum; }
		return M24_Catalog_SEO::build_desc( get_the_title( $id ), 'neu' );
	}

	/** JSON-LD Vehicle/Car + Offer + BreadcrumbList. */
	public static function json_ld() {
		if ( ! is_singular( M24FZ_CPT::PT ) ) { return; }
		$id = get_queried_object_id();
		if ( M24FZ_CPT::is_disabled( $id ) ) { return; }
		$g  = function ( $k ) use ( $id ) { return (string) get_post_meta( $id, $k, true ); };

		$car = array(
			'@context' => 'https://schema.org', '@type' => 'Car',
			'name'     => get_the_title( $id ),
			'url'      => get_permalink( $id ),
		);
		if ( $g( '_m24fz_marke' ) )      { $car['brand'] = array( '@type' => 'Brand', 'name' => $g( '_m24fz_marke' ) ); }
		if ( $g( '_m24fz_modell' ) )     { $car['model'] = $g( '_m24fz_modell' ); }
		if ( $g( '_m24fz_baujahr' ) )    { $car['productionDate'] = $g( '_m24fz_baujahr' ); $car['vehicleModelDate'] = $g( '_m24fz_baujahr' ); }
		if ( $g( '_m24fz_karosserie' ) ) { $car['bodyType'] = $g( '_m24fz_karosserie' ); }
		if ( $g( '_m24fz_aussenfarbe' ) ){ $car['color'] = $g( '_m24fz_aussenfarbe' ); }
		if ( $g( '_m24fz_getriebe' ) )   { $car['vehicleTransmission'] = $g( '_m24fz_getriebe' ); }
		if ( $g( '_m24fz_neu_gebraucht' ) ) { $car['itemCondition'] = ( false !== stripos( $g( '_m24fz_neu_gebraucht' ), 'neu' ) ) ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition'; }
		// Neue Enums → schema.org (F).
		if ( $g( '_m24fz_kraftstoff' ) )    { $car['fuelType'] = $g( '_m24fz_kraftstoff' ); }
		$drive = array( 'Heck' => 'RearWheelDriveConfiguration', 'Front' => 'FrontWheelDriveConfiguration', 'Allrad' => 'AllWheelDriveConfiguration' );
		if ( isset( $drive[ $g( '_m24fz_antrieb' ) ] ) ) { $car['driveWheelConfiguration'] = 'https://schema.org/' . $drive[ $g( '_m24fz_antrieb' ) ]; }
		$steer = array( 'Links' => 'LeftHandDriving', 'Rechts' => 'RightHandDriving' );
		if ( isset( $steer[ $g( '_m24fz_lenkung' ) ] ) ) { $car['steeringPosition'] = 'https://schema.org/' . $steer[ $g( '_m24fz_lenkung' ) ]; }
		if ( $g( '_m24fz_innenmaterial' ) ) { $car['vehicleInteriorType'] = $g( '_m24fz_innenmaterial' ); }
		if ( $g( '_m24fz_innenfarbe' ) )    { $car['vehicleInteriorColor'] = $g( '_m24fz_innenfarbe' ); }
		$lauf = (int) preg_replace( '/\D/', '', $g( '_m24fz_laufleistung' ) );
		if ( $lauf > 0 ) {
			$munit = ( 'mi' === strtolower( $g( '_m24fz_laufleistung_einheit' ) ) ) ? 'SMI' : 'KMT';
			$car['mileageFromOdometer'] = array( '@type' => 'QuantitativeValue', 'value' => $lauf, 'unitCode' => $munit );
		}
		$ps = (int) $g( '_m24fz_leistung_ps' );
		if ( $ps > 0 ) { $car['vehicleEngine'] = array( '@type' => 'EngineSpecification', 'enginePower' => array(
			array( '@type' => 'QuantitativeValue', 'value' => round( $ps * M24FZ_Telemetry::PS_TO_KW, 2 ), 'unitCode' => 'KWT' ),
			array( '@type' => 'QuantitativeValue', 'value' => $ps, 'unitCode' => 'BHP' ),
		) ); }
		if ( has_post_thumbnail( $id ) ) { $car['image'] = get_the_post_thumbnail_url( $id, 'large' ); }

		// Offer.
		$paf   = (int) $g( '_m24fz_preis_auf_anfrage' );
		$preis = (int) $g( '_m24fz_preis' );
		$red   = (int) $g( '_m24fz_preis_reduziert' );
		$eff   = ( $red > 0 && $red < $preis ) ? $red : $preis;
		$cur   = ( 'CHF' === strtoupper( $g( '_m24fz_waehrung' ) ) ) ? 'CHF' : 'EUR';
		$avail = M24FZ_CPT::is_sold( $id ) ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock';
		$offer = array( '@type' => 'Offer', 'priceCurrency' => $cur, 'availability' => $avail, 'url' => get_permalink( $id ),
			'seller' => array( '@type' => 'Organization', 'name' => 'MOTORSPORT24 GmbH' ) );
		if ( ! $paf && $eff > 0 ) {
			$offer['price'] = $eff;
			// JSON-LD-Steuersignal (Übergabe v29, von Daniel freigegeben): in BEIDEN Modi true —
			// der angezeigte Preis ist der All-in-Endpreis (auch bei §25a ist die Margensteuer
			// eingepreist). Rein maschinenlesbar; Frontend-Hinweis „§25a" bleibt davon unberührt.
			$offer['priceSpecification'] = array(
				'@type' => 'PriceSpecification', 'price' => $eff, 'priceCurrency' => $cur,
				'valueAddedTaxIncluded' => true,
			);
		}
		$car['offers'] = $offer;

		// BreadcrumbList kommt von wpSEO (Yoast/Newspaper) — KEIN zweites BreadcrumbList hier
		// ausgeben (Duplicate Structured Data vermeiden). Nur das Car-Schema ist M24-eigen.
		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $car ) . '</script>' . "\n";
	}
}
