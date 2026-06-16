<?php
/**
 * M24 Plattform — Katalog: zentrales CI-Stylesheet laden
 * Modul: modules/katalog/catalog-assets.php  ·  Datei: assets/css/m24-ci.css
 *
 * Laedt die zentralen Design-Tokens + die geteilte Karten-Komponente NUR dort,
 * wo Katalog-Inhalte gerendert werden: Gebrauchtteile-/Rennsport-Archiv,
 * Modell-Hubs und Teile-Detailseiten (inkl. „verwandte Teile"-Karten).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Assets {

	const HANDLE = 'm24-ci';
	const FILE   = 'assets/css/m24-ci.css';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( ! self::needed() ) { return; }
		$base = plugin_dir_url( M24_PLATTFORM_FILE );
		$dir  = M24_PLATTFORM_DIR . self::FILE;
		wp_enqueue_style(
			self::HANDLE,
			$base . self::FILE,
			array(),
			file_exists( $dir ) ? (string) filemtime( $dir ) : M24_PLATTFORM_VERSION
		);

		// Hub-Frontend-JS (View-Umschalter + AJAX-Sortierung/Suche + Effekt) nur auf Hubs.
		if ( class_exists( 'M24_Catalog_Hub' ) && M24_Catalog_Hub::is_hub() ) {
			$js  = 'assets/js/m24-hub.js';
			$jsd = M24_PLATTFORM_DIR . $js;
			wp_enqueue_script(
				'm24-hub', $base . $js, array(),
				file_exists( $jsd ) ? (string) filemtime( $jsd ) : M24_PLATTFORM_VERSION,
				true
			);
			$hub = M24_Catalog_Hub::current();
			wp_localize_script( 'm24-hub', 'M24Hub', array(
				'restUrl' => esc_url_raw( rest_url( 'm24/v1/hub-parts' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'hub'     => $hub,
				'hubUrl'  => M24_Catalog_Hub::url( $hub ),
			) );
		}
	}

	private static function needed() {
		if ( is_singular( 'm24_teil' ) ) { return true; }
		if ( class_exists( 'M24_Catalog_Archive' ) && M24_Catalog_Archive::is_archive() ) { return true; }
		if ( class_exists( 'M24_Catalog_Hub' ) && M24_Catalog_Hub::is_hub() ) { return true; }
		return false;
	}
}
