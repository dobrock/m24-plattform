<?php
/**
 * M24 Plattform — Admin-Aufräumer.
 *
 * Unterdrückt gezielt die Real-Cookie-Banner-„Website erneut scannen"-Notice, die bei jeder Plugin-
 * Aktivierung/Deaktivierung im GESAMTEN wp-admin erscheint und ständig weggeklickt werden muss.
 * Bevorzugt der RCB-eigene Filter (falls vorhanden); zusätzlich ein sehr gezieltes, textbasiertes Ausblenden
 * (nur Notices, die exakt diesen Rescan-Hinweis enthalten — andere Hinweise bleiben unberührt).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Admin_Cleanup {

	public static function init() {
		if ( ! is_admin() ) { return; }
		// 1) RCB-eigener Weg (falls das Plugin diesen Filter anbietet) — sauberste Lösung, kein DOM-Eingriff.
		add_filter( 'RCB/ScannerRerun/Notice', '__return_false' );
		add_filter( 'Devowl/Utils/AdminNotice/ScannerRerun', '__return_false' );
		// 2) Fallback: textbasiertes Ausblenden im Footer (nur die Rescan-Notice, an ihrer Formulierung erkannt).
		add_action( 'admin_footer', array( __CLASS__, 'hide_rcb_scan_notice' ), 99 );
	}

	/** Blendet ausschließlich Notices aus, die den RCB-Rescan-Hinweis enthalten (Text-Match, sprachrobust). */
	public static function hide_rcb_scan_notice() {
		$phrases = apply_filters( 'm24_rcb_hide_phrases', array(
			'erneut zu scannen', 'erneut scannen', 'website erneut', 'rescan', 'scan the website again',
			'scan your website again', 'website erneut zu scannen',
		) );
		?>
<script>
(function () {
	var PHRASES = <?php echo wp_json_encode( array_values( array_map( 'strtolower', (array) $phrases ) ) ); // phpcs:ignore ?>;
	function hit(t) { t = (t || '').toLowerCase(); for (var i = 0; i < PHRASES.length; i++) { if (t.indexOf(PHRASES[i]) > -1) { return true; } } return false; }
	function sweep() {
		// Nur Admin-Notices + RCB-Container prüfen (kein globaler *-Scan). Text-Match hält es notice-spezifisch.
		var sel = '#wpbody .notice, #wpbody [class*="notice"], #wpbody [class*="real-cookie"], #wpbody [id*="real-cookie"], #wpbody [class*="rcb"]';
		var nodes = document.querySelectorAll(sel), i, n;
		for (i = 0; i < nodes.length; i++) { n = nodes[i]; if (n.offsetParent !== null && hit(n.textContent)) { n.style.display = 'none'; } }
	}
	sweep();
	// RCB rendert die Notice teils spät (React) → ein paar verzögerte Durchläufe + Observer (gedrosselt).
	var t = 0, iv = setInterval(function () { sweep(); if (++t > 8) { clearInterval(iv); } }, 400);
	try { var mo = new MutationObserver(function () { sweep(); }); mo.observe(document.body, { childList: true, subtree: true }); } catch (e) {}
})();
</script>
		<?php
	}
}
