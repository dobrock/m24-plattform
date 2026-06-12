<?php
/**
 * M24 Plattform — Gruppierte Suche: Frontend-Anbindung (Dropdown)
 * Modul: modules/search/search-frontend.php
 *
 * Laedt JS + CSS, das sich an die bestehenden Theme-Suchfelder (name="s") haengt,
 * beim Tippen den REST-Endpoint abfragt und ein eigenes, gruppiertes Dropdown rendert.
 * Das theme-eigene tagDiv-AJAX-Dropdown wird per CSS unterdrueckt (Eigenbau ist sauberer
 * als der nicht gruppierbare tagDiv-Output).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Search_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		$base = plugin_dir_url( M24_PLATTFORM_FILE );
		$dir  = M24_PLATTFORM_DIR;

		$css = 'assets/css/m24-search.css';
		$js  = 'assets/js/m24-search.js';

		wp_enqueue_style(
			'm24-search',
			$base . $css,
			array(),
			file_exists( $dir . $css ) ? (string) filemtime( $dir . $css ) : M24_PLATTFORM_VERSION
		);
		wp_enqueue_script(
			'm24-search',
			$base . $js,
			array(),
			file_exists( $dir . $js ) ? (string) filemtime( $dir . $js ) : M24_PLATTFORM_VERSION,
			true
		);

		wp_localize_script( 'm24-search', 'M24Search', array(
			'restUrl'  => esc_url_raw( rest_url( M24_Search_REST::NS . '/search' ) ),
			'minChars' => M24_Search_REST::MIN_CHARS,
			'i18n'     => array(
				'all'        => __( 'Alle Ergebnisse anzeigen', 'm24-plattform' ),
				'allCount'   => __( 'Alle %d anzeigen', 'm24-plattform' ),
				'noResults'  => __( 'Keine Treffer', 'm24-plattform' ),
				'searching'  => __( 'Suche …', 'm24-plattform' ),
				'sold'       => __( 'Verkauft', 'm24-plattform' ),
			),
		) );
	}
}
