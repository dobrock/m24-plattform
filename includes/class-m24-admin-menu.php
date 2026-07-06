<?php
/**
 * M24 — Admin-Menü-Konsolidierung: ein Dach „MOTORSPORT24".
 * Modul: includes/class-m24-admin-menu.php
 *
 * Der Top-Level „M24 Plattform" (Slug m24-plattform) ist das Dach. CPTs (m24_fahrzeug, m24_teil,
 * m24_modellhub) + Anfragen hängen via show_in_menu darunter; weitere Seiten via add_submenu_page.
 * Hier zentral: Umbenennung/Icon, feste Untermenü-Reihenfolge (5 Sektionen), Entrümpelung und
 * die Sektions-Trenner. KEINE URL-Änderung der Ziele (Slugs bleiben).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Admin_Menu {

	const DACH = 'm24-plattform';

	/** Feste Reihenfolge der Untermenü-Slugs (exakt). Unbekanntes wandert ans Ende. */
	private static function order(): array {
		return array(
			// ── Tagesgeschäft ──
			'm24-anfragen', 'm24-offers', 'm24-interessenten', 'm24-garagen',
			// ── Katalog ──
			'm24fz-verwaltung', 'edit.php?post_type=m24_teil', 'edit.php?post_type=m24_modellhub',
			// ── Kunden ──
			'm24-haendler', 'm24-plattform-reviews',
			// ── System ──  (Einstellungen liegt auf dem Dach-Slug m24-plattform)
			'm24-plattform', 'm24-sitemap',
			// ── Diagnose ──
			'm24-error-log', 'm24-mail-preview',
		);
	}

	/** Sektions-Trenner: eingefügt VOR dem jeweiligen ersten Slug der Sektion. */
	private static function sections(): array {
		return array(
			'm24-anfragen'     => 'Tagesgeschäft',
			'm24fz-verwaltung' => 'Katalog',
			'm24-haendler'     => 'Kunden',
			'm24-plattform'    => 'System',
			'm24-error-log'    => 'Diagnose',
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'reorganize' ), 9999 );
		add_action( 'admin_head', array( __CLASS__, 'separator_css' ) );
	}

	public static function reorganize() {
		global $menu, $submenu;
		if ( ! is_array( $menu ) ) { return; }

		// 1) „M24 Plattform" → „MOTORSPORT24" + Auto-Icon.
		foreach ( $menu as $i => $m ) {
			if ( isset( $m[2] ) && self::DACH === $m[2] ) {
				$menu[ $i ][0] = 'MOTORSPORT24';
				$menu[ $i ][6] = 'dashicons-car';
				break;
			}
		}

		// 2) Native „Fahrzeuge"-Liste/Neu + Komfort-Maske ausblenden (Inserat-Verwaltung ist das Cockpit).
		remove_submenu_page( self::DACH, 'edit.php?post_type=m24_fahrzeug' );
		remove_submenu_page( self::DACH, 'post-new.php?post_type=m24_fahrzeug' );
		remove_submenu_page( 'edit.php?post_type=m24_fahrzeug', 'm24fz-editor' );

		// 3) Entrümpeln — Seiten/CPTs bleiben registriert (per URL erreichbar), nur der Menüeintrag geht weg.
		remove_submenu_page( self::DACH, 'edit.php?post_type=m24_inquiry' ); // „Alle Anfragen" → durch Inbox ersetzt
		remove_submenu_page( self::DACH, 'm24-plattform-import' );           // „Shopware-Import" — alles importiert
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			remove_submenu_page( self::DACH, 'm24-plattform-mock-log' );     // „Mock-Log" nur im Dev-Modus
		}
		if ( ! ( defined( 'M24_DESK_API_TOKEN' ) && '' !== (string) M24_DESK_API_TOKEN ) ) {
			remove_submenu_page( self::DACH, 'm24-plattform-log' );          // „Sync-Log" nur mit Desk-Anbindung
		}

		if ( empty( $submenu[ self::DACH ] ) ) { return; }

		// 4) „Händler" → „Kunden" (Slug m24-haendler unverändert).
		foreach ( $submenu[ self::DACH ] as &$it ) {
			if ( isset( $it[2] ) && 'm24-haendler' === $it[2] ) {
				$it[0] = 'Kunden';
				if ( isset( $it[3] ) ) { $it[3] = 'Kunden'; }
			}
		}
		unset( $it );

		// 5) Feste Reihenfolge.
		$order = self::order();
		$items = $submenu[ self::DACH ];
		usort( $items, static function ( $a, $b ) use ( $order ) {
			return self::rank( $a[2] ?? '', $order ) <=> self::rank( $b[2] ?? '', $order );
		} );

		// 6) Sektions-Trenner vor dem ersten Slug jeder Sektion einschieben (nicht-klickbare Pseudo-Items).
		$sections = self::sections();
		$final    = array();
		$sep      = 0;
		foreach ( $items as $it ) {
			$slug = $it[2] ?? '';
			if ( isset( $sections[ $slug ] ) ) {
				$final[] = array( '<span class="m24-mnsep">' . esc_html( $sections[ $slug ] ) . '</span>', 'manage_options', 'm24-sep-' . ( ++$sep ), '' );
			}
			$final[] = $it;
		}
		$submenu[ self::DACH ] = array_values( $final );
	}

	private static function rank( $slug, array $order ) {
		$i = array_search( $slug, $order, true );
		return false === $i ? count( $order ) + 1 : $i;
	}

	/** Trenner-Optik: Großbuchstaben, grau, nicht klickbar. */
	public static function separator_css() {
		if ( ! is_admin() ) { return; }
		echo '<style id="m24-menu-sep-css">'
			. '#adminmenu .wp-submenu a[href*="page=m24-sep-"]{pointer-events:none;cursor:default}'
			. '#adminmenu .wp-submenu a[href*="page=m24-sep-"] .m24-mnsep{display:block;text-transform:uppercase;font-size:10px;letter-spacing:.09em;font-weight:700;color:#8a92a6;opacity:.85;margin-top:6px}'
			. '#adminmenu .wp-submenu li:first-child a[href*="page=m24-sep-"] .m24-mnsep{margin-top:0}'
			. '</style>';
	}
}
