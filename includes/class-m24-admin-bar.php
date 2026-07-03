<?php
/**
 * M24 Admin-Bar: Direktlink zum KORREKTEN Editor je CPT (Frontend, eingeloggt, edit-Rechte).
 *
 * - singular m24_fahrzeug → M24-Fahrzeug-Editor (edit.php?post_type=m24_fahrzeug&page=m24fz-editor&post=ID)
 * - singular m24_teil     → klassischer WP-Editor (get_edit_post_link; m24_teil nutzt die Classic-Maske)
 * - sonst (page/post)     → Standard-Editor (get_edit_post_link)
 * Der WP-Default-„Bearbeiten"-Knoten für diese Ansichten wird entfernt (kein Doppel-Link).
 * Bonus: auf /en/-Seiten (GTranslate) zusätzlich „🌐 Übersetzung bearbeiten" → aktuelle URL + ?language_edit=1.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Admin_Bar {

	public static function init() {
		// Priorität 90: nach dem Core-„edit"-Knoten (wp_admin_bar_edit_menu, prio 80) → wir können ihn ersetzen.
		add_action( 'admin_bar_menu', array( __CLASS__, 'nodes' ), 90 );
		add_action( 'wp_head', array( __CLASS__, 'style' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer_js' ) );
	}

	public static function nodes( $bar ) {
		if ( is_admin() || ! is_user_logged_in() ) { return; }

		// ── „🌐 Übersetzung bearbeiten": GTranslate liefert /en/ über einen Cloud-Proxy → das Origin-WP sieht
		// bei /en/-Aufrufen IMMER den DE-Pfad (REQUEST_URI ohne /en/). Serverseitige Pfad-Erkennung matcht daher
		// NIE. Deshalb den Knoten für Redakteure/Admins (edit_posts → NICHT B2B-Kunden) IMMER registrieren,
		// initial versteckt (m24-tr-hidden) + href="#"; Sichtbarkeit + Ziel-URL setzt footer_js() clientseitig.
		if ( current_user_can( 'edit_posts' ) ) {
			$bar->add_node( array(
				'id'    => 'm24-translate-edit',
				'title' => '<span class="notranslate">🌐 Übersetzung bearbeiten</span>', // notranslate: GTranslate übersetzt das Label nicht
				'href'  => '#',
				'meta'  => array( 'class' => 'm24-adminbar-edit m24-tr-hidden', 'target' => '_blank', 'rel' => 'noopener' ),
			) );
		}

		// ── „✎ M24 bearbeiten" — nur singular, mit edit_post-Rechten (bestehende Logik).
		if ( ! is_singular() ) { return; }
		$id = (int) get_queried_object_id();
		if ( $id <= 0 ) { return; }
		$pt = (string) get_post_type( $id );
		if ( ! in_array( $pt, array( 'm24_fahrzeug', 'm24_teil', 'page', 'post' ), true ) ) { return; }
		if ( ! current_user_can( 'edit_post', $id ) ) { return; }

		// Korrekte Editor-URL je CPT.
		if ( 'm24_fahrzeug' === $pt ) {
			$url = admin_url( 'edit.php?post_type=m24_fahrzeug&page=m24fz-editor&post=' . $id );
		} else {
			$url = (string) get_edit_post_link( $id, 'raw' );
		}
		if ( '' === $url ) { return; }

		// Core-„Bearbeiten"-Knoten entfernen → kein doppelter Bearbeiten-Link.
		$bar->remove_node( 'edit' );

		$bar->add_node( array(
			'id'    => 'm24-edit',
			// notranslate → auf /en/ bleibt „M24 bearbeiten" unübersetzt (sonst „Edit M24").
			'title' => '<span class="notranslate">✎ M24 bearbeiten</span>',
			'href'  => $url,
			'meta'  => array( 'class' => 'm24-adminbar-edit' ),
		) );
	}

	/**
	 * Clientseitige Sichtbarkeit/Ziel-URL des Übersetzungs-Knotens: im Browser ist location.pathname = /en/…
	 * (der Proxy-Pfad). Nur Front-End + Redakteure/Admins. Auf /en/ → einblenden + href auf ?language_edit=1;
	 * sonst (DE) → Knoten aus dem DOM entfernen. Kein Logging von URLs/Query-Strings.
	 */
	public static function footer_js() {
		if ( is_admin() || ! is_user_logged_in() || ! is_admin_bar_showing() || ! current_user_can( 'edit_posts' ) ) { return; }
		?>
<script id="m24-tr-adminbar-js">
(function(){
	// Admin-Bar rendert auf wp_footer Prio 1000 → dieses Inline-JS läuft davor. Erst nach DOMContentLoaded
	// ausführen, sonst ist getElementById(...) null und der Knoten wird nie eingeblendet.
	function run(){
		var li=document.getElementById('wp-admin-bar-m24-translate-edit'); if(!li) return;
		if(/^\/en(\/|$)/.test(location.pathname)){
			var u=new URL(location.href); u.searchParams.delete('language_edit'); u.searchParams.set('language_edit','1');
			var a=li.querySelector('a.ab-item'); if(a) a.href=u.toString();
			li.classList.remove('m24-tr-hidden'); li.style.display='';
		} else { li.parentNode && li.parentNode.removeChild(li); }
	}
	if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run);}else{run();}
})();
</script>
		<?php
	}

	/** Dezentes Highlight + initial verstecktes Übersetzungs-Node (bis JS auf /en/ einblendet). */
	public static function style() {
		if ( is_admin() || ! is_user_logged_in() || ! is_admin_bar_showing() ) { return; }
		echo '<style id="m24-adminbar-css">#wpadminbar li#wp-admin-bar-m24-edit>.ab-item,#wpadminbar li#wp-admin-bar-m24-translate-edit>.ab-item{background:#9a6b25!important;color:#fff!important;font-weight:600}#wpadminbar li#wp-admin-bar-m24-translate-edit.m24-tr-hidden{display:none}</style>' . "\n";
	}
}
