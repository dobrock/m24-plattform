<?php
/**
 * M24 Plattform – Bild-Optimierung (lokal, kostenlos, DSGVO-sauber)
 * --------------------------------------------------------------------------
 * - Erzeugte Bildgrößen (Sub-Sizes / srcset) als WebP ausgeben.
 *   Nutzt WordPress' eingebautes image_editor_output_format (GD/Imagick),
 *   beide auf diesem Server mit WebP-Support. Kein Dritt-CDN, keine Credits.
 * - Qualität der erzeugten Größen: 82 → 90 (gilt auch für WebP).
 * - Full-Size-Schwelle: 3840 px (4K) für mehr Detail in Lightbox/Zoom.
 *
 * Neue Uploads werden automatisch als WebP-Größen erzeugt.
 * Bestand einmalig konvertieren:  wp media regenerate --yes
 *
 * Hinweis: Das Original (full) bleibt im Quellformat erhalten; nur die im
 * Seitenfluss ausgespielten Größen werden WebP. PNG bleibt unangetastet
 * (Logos/Transparenz).
 *
 * @package m24-plattform
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1) Erzeugte Größen aus JPEG-Quellen als WebP ausgeben.
 */
add_filter(
	'image_editor_output_format',
	function ( $formats ) {
		$formats['image/jpeg'] = 'image/webp';
		return $formats;
	}
);

/**
 * 2) Qualität der erzeugten Größen anheben (Default 82 → 90).
 *    wp_editor_set_quality gilt formatübergreifend, also auch für WebP.
 */
add_filter( 'jpeg_quality', function () { return 90; } );
add_filter( 'wp_editor_set_quality', function () { return 90; } );

/**
 * 3) Full-Size-Schwelle auf 4K. Der Seitenfluss nutzt weiter die kleinen
 *    srcset-Größen → kein Ladezeit-Nachteil, nur mehr Detail beim Vollbild.
 */
add_filter( 'big_image_size_threshold', function () { return 3840; } );
