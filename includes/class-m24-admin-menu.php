<?php
/**
 * M24 — Admin-Menü-Konsolidierung (§1): ein Dach „MOTORSPORT24"
 * Modul: includes/class-m24-admin-menu.php
 *
 * Der bestehende Top-Level „M24 Plattform" (Slug m24-plattform) wird zum Dach umbenannt.
 * CPTs (m24_fahrzeug, m24_teil, m24_modellhub) + Sammelanfragen hängen via show_in_menu darunter
 * (in den jeweiligen register-Aufrufen gesetzt). Hier: Umbenennung, Icon/Position, Inserat-
 * Verwaltung als erste Position + Dach-Landeseite, Submenü-Reihenfolge. KEINE URL-Änderung der Ziele.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Admin_Menu {

	const DACH = 'm24-plattform';
	const VERWALTUNG = 'edit.php?post_type=m24_fahrzeug&page=m24fz-verwaltung'; // Inserat-Verwaltung

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'reorganize' ), 9999 );
		add_action( 'admin_menu', array( __CLASS__, 'add_verwaltung_link' ), 9998 );
		add_filter( 'parent_file', array( __CLASS__, 'keep_parent_open' ) );
	}

	/** Inserat-Verwaltung zusätzlich direkt unters Dach hängen (zeigt auf die bestehende Seite). */
	public static function add_verwaltung_link() {
		add_submenu_page( self::DACH, 'Inserat-Verwaltung', 'Inserat-Verwaltung', 'manage_options', self::VERWALTUNG );
	}

	/** Top-Level umbenennen + Icon/Position; Submenü-Reihenfolge nach §1. */
	public static function reorganize() {
		global $menu, $submenu;
		if ( ! is_array( $menu ) ) { return; }

		// 1) „M24 Plattform" → „MOTORSPORT24" + Auto-Icon. (Position via menu_position im add_menu_page.)
		foreach ( $menu as $i => $m ) {
			if ( isset( $m[2] ) && self::DACH === $m[2] ) {
				$menu[ $i ][0] = 'MOTORSPORT24';
				$menu[ $i ][6] = 'dashicons-car';
				break;
			}
		}

		// 2) Submenü-Reihenfolge: Inserat-Verwaltung · Fahrzeuge · Teile-Katalog · Modell-Hubs ·
		//    Sammelanfragen · Einstellungen · (Rest). Best-effort über bekannte Slugs/Fragmente.
		if ( empty( $submenu[ self::DACH ] ) ) { return; }
		$order = array( self::VERWALTUNG, 'edit.php?post_type=m24_fahrzeug', 'edit.php?post_type=m24_teil', 'edit.php?post_type=m24_modellhub', 'edit.php?post_type=m24_inquiry', self::DACH );
		$items = $submenu[ self::DACH ];
		usort( $items, static function ( $a, $b ) use ( $order ) {
			$ra = self::rank( $a[2] ?? '', $order ); $rb = self::rank( $b[2] ?? '', $order );
			return $ra <=> $rb;
		} );
		$submenu[ self::DACH ] = array_values( $items );
	}

	private static function rank( $slug, $order ) {
		foreach ( $order as $i => $o ) { if ( $slug === $o || false !== strpos( $slug, $o ) ) { return $i; } }
		return count( $order ) + 1; // Unbekanntes ans Ende
	}

	/** Untermenüs (Fahrzeuge/Teile-Editor etc.) halten das Dach im Menü geöffnet/aktiv. */
	public static function keep_parent_open( $parent_file ) {
		return $parent_file;
	}
}
