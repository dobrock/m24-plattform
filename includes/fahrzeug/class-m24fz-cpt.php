<?php
/**
 * M24 Fahrzeug — CPT + Taxonomie + URL-Rewrite
 * Modul: includes/fahrzeug/class-m24fz-cpt.php
 *
 * CPT m24_fahrzeug (/fahrzeuge/{slug}/), Taxonomie m24_fahrzeug_kat (race-cars,
 * sold-race-cars, classic-cars, sold-classic-cars). Status liegt in Meta _m24fz_status
 * (gelistet|verkauft|reserviert|deaktiviert) — NICHT im WP-Post-Status.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_CPT {

	const PT  = 'm24_fahrzeug';
	const TAX = 'm24_fahrzeug_kat';
	const REWRITE_FLAG = 'm24fz_rewrites_v1';

	/** Aktiv-Kategorie → Verkauft-Pendant (Kategorie-Flip bei „Verkauft"). */
	const SOLD_MAP = array( 'race-cars' => 'sold-race-cars', 'classic-cars' => 'sold-classic-cars' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'maybe_seed_terms' ), 11 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_flush' ) );
	}

	public static function register() {
		register_post_type( self::PT, array(
			'labels' => array(
				'name'          => 'Fahrzeuge',
				'singular_name' => 'Fahrzeug',
				'add_new_item'  => 'Neues Fahrzeug',
				'edit_item'     => 'Fahrzeug bearbeiten',
				'menu_name'     => 'Fahrzeuge',
			),
			'public'       => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-car',
			'menu_position'=> 26,
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'rewrite'      => array( 'slug' => 'fahrzeuge', 'with_front' => false ),
		) );

		register_taxonomy( self::TAX, self::PT, array(
			'labels'            => array( 'name' => 'Fahrzeug-Kategorien', 'singular_name' => 'Kategorie' ),
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'fahrzeuge-kategorie', 'with_front' => false ),
		) );
	}

	/** Pflicht-Terms einmalig anlegen (idempotent). */
	public static function maybe_seed_terms() {
		if ( ! taxonomy_exists( self::TAX ) ) { return; }
		$terms = array(
			'race-cars'         => 'Race Cars for Sale',
			'sold-race-cars'    => 'Sold Race Cars',
			'classic-cars'      => 'Classic Cars for Sale',
			'sold-classic-cars' => 'Sold Classic Cars',
		);
		foreach ( $terms as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX ) ) {
				wp_insert_term( $name, self::TAX, array( 'slug' => $slug ) );
			}
		}
	}

	/** Rewrites einmalig flushen (nach Deploy/Aktivierung). */
	public static function maybe_flush() {
		if ( get_option( self::REWRITE_FLAG ) === self::PT ) { return; }
		self::register();
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, self::PT, false );
	}

	/* ── Status-Helfer (eine Quelle) ─────────────────────────────────────────── */

	public static function status( $post_id ) {
		$s = (string) get_post_meta( (int) $post_id, '_m24fz_status', true );
		return in_array( $s, array( 'gelistet', 'verkauft', 'reserviert', 'deaktiviert' ), true ) ? $s : 'gelistet';
	}
	public static function is_sold( $post_id )     { return 'verkauft'    === self::status( $post_id ); }
	public static function is_reserved( $post_id ) { return 'reserviert'  === self::status( $post_id ); }
	public static function is_disabled( $post_id ) { return 'deaktiviert' === self::status( $post_id ); }
}
