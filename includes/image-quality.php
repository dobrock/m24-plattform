<?php
/**
 * M24 Plattform — Bild-Qualität & Full-Size-Schwelle
 * Modul: includes/image-quality.php
 *
 * Hebt die JPEG-/Editor-Qualität auf 90 % und die „Big Image"-Schwelle auf 4K (3840px)
 * an. srcset liefert weiterhin die kleinen Zwischengrößen aus → kein Tempo-Nachteil im
 * Frontend, aber schärfere Originale/Detailbilder.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'jpeg_quality', function () { return 90; } );
add_filter( 'wp_editor_set_quality', function () { return 90; } );
add_filter( 'big_image_size_threshold', function () { return 3840; } );
