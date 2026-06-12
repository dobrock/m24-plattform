<?php
/**
 * M24 Plattform — Verkauft-Ansicht: Assets (Inline-Block-Styling + Desktop-Lightbox)
 * Modul: modules/sold/sold-lightbox.php
 *
 * Laedt CSS (Verkauft-Badge, deaktivierter Merkzettel, Alternativen-Block, Lightbox)
 * und JS (Desktop-Lightbox mit Alternativen) NUR auf verkauften Teile-Detailseiten.
 * Mobil wird KEINE Auto-Lightbox getriggert (Google-Intrusive-Interstitial-Schutz) —
 * das entscheidet das JS per Breakpoint; geladen wird es trotzdem (Inline-Block-CSS).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Sold_Lightbox {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( ! is_singular( 'm24_teil' ) ) { return; }
		if ( 'verkauft' !== get_post_meta( get_queried_object_id(), '_m24_status', true ) ) { return; }

		$base = plugin_dir_url( M24_PLATTFORM_FILE );
		$dir  = M24_PLATTFORM_DIR;
		$css  = 'assets/css/m24-sold.css';
		$js   = 'assets/js/m24-sold.js';

		wp_enqueue_style(
			'm24-sold', $base . $css, array(),
			file_exists( $dir . $css ) ? (string) filemtime( $dir . $css ) : M24_PLATTFORM_VERSION
		);
		wp_enqueue_script(
			'm24-sold', $base . $js, array(),
			file_exists( $dir . $js ) ? (string) filemtime( $dir . $js ) : M24_PLATTFORM_VERSION,
			true
		);
		wp_localize_script( 'm24-sold', 'M24Sold', array(
			'delay'      => 5000,   // ms bis Auto-Lightbox (Desktop)
			'breakpoint' => 783,    // ab hier „Desktop" → Lightbox erlaubt
			'i18n'       => array(
				'title' => __( 'Das könnte Sie auch interessieren', 'm24-plattform' ),
				'close' => __( 'Schließen', 'm24-plattform' ),
			),
		) );
	}
}
