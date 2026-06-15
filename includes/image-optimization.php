<?php
/**
 * M24 Plattform — Bild-Optimierung (WebP + Qualität + Full-Size-Schwelle)
 * Modul: includes/image-optimization.php
 *
 * - Erzeugte JPEG-Sub-Größen werden als WebP ausgegeben (image_editor_output_format).
 * - Qualität 90 für WebP/JPEG (wp_editor_set_quality + jpeg_quality).
 * - „Big Image"-Schwelle auf 4K (3840px) angehoben.
 *
 * Ersetzt das frühere includes/image-quality.php (dort nur Qualität/Schwelle, kein WebP).
 * Bestand einmalig konvertieren: `wp media regenerate --yes` (Sub-Größen → WebP),
 * danach WP-Rocket-Cache leeren.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'image_editor_output_format', function ( $formats ) {
	$formats['image/jpeg'] = 'image/webp';
	$formats['image/jpg']  = 'image/webp';
	return $formats;
} );
add_filter( 'wp_editor_set_quality', function () { return 90; } );
add_filter( 'jpeg_quality', function () { return 90; } );
add_filter( 'big_image_size_threshold', function () { return 3840; } );
