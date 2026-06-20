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
	const VERWALTUNG = 'm24fz-verwaltung'; // Inserat-Verwaltung (eigene Submenu-Registrierung in M24FZ_Admin_List)

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'reorganize' ), 9999 );
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

		// 1b) Native „Fahrzeuge"-Liste + „Neues Fahrzeug" + Komfort-Maske als Submenü ausblenden —
		//     Inserat-Verwaltung ist das alleinige Fahrzeug-Cockpit (CPT bleibt voll registriert,
		//     Editor/Anlegen via Buttons/Links erreichbar). „Alle Teile"/Hubs/Anfragen bleiben.
		remove_submenu_page( self::DACH, 'edit.php?post_type=m24_fahrzeug' );
		remove_submenu_page( self::DACH, 'post-new.php?post_type=m24_fahrzeug' );
		remove_submenu_page( 'edit.php?post_type=m24_fahrzeug', 'm24fz-editor' ); // Komfort-Maske (öffnet via Neu/Bearbeiten)

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
}
