<?php
/**
 * M24 Plattform — Katalog: Custom Post Type + Taxonomie + Meta
 * Modul: catalog-cpt.php
 *
 * CPT `m24_teil` (Neu- UND Gebrauchtteile), hierarchische Taxonomie
 * `m24_fahrzeugkat` ("passend für …") und alle Meta-Felder.
 *
 * Deutsch-only: Titel (DE) = Beitragstitel, Beschreibung (DE) = Meta
 * `_m24_beschreibung_de`. KEIN Gutenberg-Editor (supports ohne 'editor'),
 * damit die Eingabefelder oben stehen. Englisch folgt später per Übersetzungs-Plugin.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_CPT {

	const POST_TYPE         = 'm24_teil';
	const TAXONOMY          = 'm24_fahrzeugkat';   // Modell (3er, M3 E36, X5 F15, …)
	const TAXONOMY_BAUGRUPPE = 'm24_baugruppe';     // Baugruppe (Motor, Kuehlung, Fahrwerk, …) aus Shopware

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	public static function register_post_type() {
		$labels = array(
			'name'          => 'Teile',
			'singular_name' => 'Teil',
			'menu_name'     => 'Teile-Katalog',
			'add_new'       => 'Neues Teil',
			'add_new_item'  => 'Neues Teil anlegen',
			'edit_item'     => 'Teil bearbeiten',
			'new_item'      => 'Neues Teil',
			'view_item'     => 'Teil ansehen',
			'search_items'  => 'Teile suchen',
			'not_found'     => 'Keine Teile gefunden',
			'all_items'     => 'Alle Teile',
		);
		register_post_type( self::POST_TYPE, array(
			'labels'          => $labels,
			'public'          => true,
			'has_archive'     => false,
			'show_in_rest'    => false, // kein Block-Editor -> klassische Maske, Felder oben
			'show_in_menu'    => 'm24-plattform', // §1: unter dem Dach „MOTORSPORT24"
			'menu_icon'       => 'dashicons-screenoptions',
			'supports'        => array( 'title', 'thumbnail', 'revisions' ),
			'rewrite'         => false,
			'capability_type' => 'post',
		) );
	}

	public static function register_taxonomy() {
		register_taxonomy( self::TAXONOMY, self::POST_TYPE, array(
			'label'             => 'Passend für (Fahrzeugkategorie)',
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => false,
			'rewrite'           => array( 'slug' => 'passend-fuer', 'with_front' => false ),
		) );
		register_taxonomy( self::TAXONOMY_BAUGRUPPE, self::POST_TYPE, array(
			'labels'            => array(
				'name'          => 'Baugruppen',
				'singular_name' => 'Baugruppe',
				'menu_name'     => 'Baugruppen',
				'all_items'     => 'Alle Baugruppen',
				'edit_item'     => 'Baugruppe bearbeiten',
				'add_new_item'  => 'Neue Baugruppe',
				'search_items'  => 'Baugruppen suchen',
			),
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => false,
			'rewrite'           => array( 'slug' => 'baugruppe', 'with_front' => false ),
		) );
	}

	public static function register_meta() {
		$string_keys = array(
			'_m24_artikelnummer',
			'_m24_bmw_teilenummer',
			'_m24_hinweis',
			'_m24_mwst_modus',
			'_m24_typ',
			'_m24_status',
		);
		foreach ( $string_keys as $key ) {
			register_post_meta( self::POST_TYPE, $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			) );
		}

		register_post_meta( self::POST_TYPE, '_m24_beschreibung_de', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'wp_kses_post',
			'auth_callback'     => array( __CLASS__, 'can_edit' ),
		) );

		register_post_meta( self::POST_TYPE, '_m24_preis_netto', array(
			'type'              => 'number',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => array( __CLASS__, 'sanitize_price' ),
			'auth_callback'     => array( __CLASS__, 'can_edit' ),
		) );

		register_post_meta( self::POST_TYPE, '_m24_galerie', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => array( __CLASS__, 'sanitize_id_list' ),
			'auth_callback'     => array( __CLASS__, 'can_edit' ),
		) );
	}

	public static function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	public static function sanitize_price( $value ) {
		$value = str_replace( array( '.', ' ', '€' ), '', (string) $value );
		$value = str_replace( ',', '.', $value );
		return is_numeric( $value ) ? (float) $value : 0;
	}

	public static function sanitize_id_list( $value ) {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) $value ) ) );
		return implode( ',', $ids );
	}
}
