<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * „Original BMW-Teil"-Badge. NUR bei echten Original-BMW-Teilen ausgeben (Markenrecht).
 *
 * Ersetzt das frühere BMW-Rundel (bmw-logo.png), das aus Markenrechtsgründen
 * (BMW-Abmahnung 2023) entfernt wurde. Gate: Postmeta _m24_original_teil === '1'.
 * Default (Flag ungesetzt) → '' → kein Badge. „Original BMW-Teil" darf NIEMALS auf
 * Nicht-BMW-/Nachbau-Teilen erscheinen.
 */
function m24_render_original_badge( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();

	// Gate: nur echte Originalteile (Postmeta-Flag, explizit gesetzt).
	if ( get_post_meta( $post_id, '_m24_original_teil', true ) !== '1' ) {
		return '';
	}

	return '<span class="m24-original-badge" aria-label="Original BMW-Teil">'
		. '<svg class="m24-original-badge__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
		. '<path d="M12 3l7 4v5c0 4.5 -3 7 -7 9c-4 -2 -7 -4.5 -7 -9v-5z"/><path d="M9 12l2 2l4 -4"/>'
		. '</svg>'
		. '<span class="m24-original-badge__text">Original BMW-Teil</span>'
		. '</span>';
}
