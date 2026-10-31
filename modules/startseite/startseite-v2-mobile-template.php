<?php
/**
 * Mobil-Template der Startseiten-Vorschau: Header + [m24_startseite_v2] + Footer,
 * ohne Seitentitel und ohne Composer-Inhalt (das tagDiv-Mobil-Theme rendert diesen nicht).
 *
 * @package m24-plattform
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
echo '<div class="m24s-mobile-wrap">' . M24_Startseite_V2::mobile_slider() . do_shortcode( '[m24_startseite_v2]' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
get_footer();
