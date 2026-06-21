<?php
/**
 * M24 Fahrzeug — Template-Steuerung + Render-Helfer
 * Modul: includes/fahrzeug/class-m24fz-template.php
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Template {

	public static function init() {
		add_filter( 'template_include', array( __CLASS__, 'route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_shortcode( 'm24_fahrzeuge_rubrik', array( __CLASS__, 'rubrik_shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'inject_rubrik' ), 9 );
	}

	/**
	 * [m24_fahrzeuge_rubrik kat="race-cars-for-sale"] — CPT-Fahrzeuge der Rubrik als M24-Karten.
	 * kat = Rubrik-Kategorie-Slug (race-cars-for-sale|classic-cars-for-sale) ODER direkt _m24fz_kat
	 * (race-cars|classic-cars). Reserviert/Verkauft werden mit Badge gezeigt (nicht ausgeblendet).
	 */
	public static function rubrik_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'kat' => '', 'limit' => 60 ), $atts, 'm24_fahrzeuge_rubrik' );
		$map  = array( 'race-cars-for-sale' => 'race-cars', 'classic-cars-for-sale' => 'classic-cars' );
		$kat  = isset( $map[ $atts['kat'] ] ) ? $map[ $atts['kat'] ] : $atts['kat'];
		return self::rubrik_grid_html( $kat, (int) $atts['limit'] );
	}

	/**
	 * Rubrik-Auto-Inject auf den tagDiv-Seiten (Slug racecars-for-sale / classic-cars-for-sale):
	 * Grid der CPT-Fahrzeuge OBERHALB der Alt-Liste — kein Shortcode nötig. Filtert auf _m24fz_kat.
	 */
	public static function inject_rubrik( $content ) {
		static $done = array();
		if ( ! is_page() || ! is_main_query() || ! in_the_loop() ) { return $content; }
		$pid = get_queried_object_id();
		if ( isset( $done[ $pid ] ) ) { return $content; }
		$slug = get_post_field( 'post_name', $pid );
		$map  = apply_filters( 'm24fz_rubrik_pages', array( 'racecars-for-sale' => 'race-cars', 'classic-cars-for-sale' => 'classic-cars' ) );
		if ( ! isset( $map[ $slug ] ) ) { return $content; }
		$grid = self::rubrik_grid_html( $map[ $slug ] );
		if ( '' === $grid ) { return $content; }
		$done[ $pid ] = true;
		return '<h2 class="m24fzr-h">Aktuelle Fahrzeuge</h2>' . $grid . $content;
	}

	/** Karten-Grid der CPT-Fahrzeuge einer _m24fz_kat (race-cars|classic-cars). Leer → ''. */
	public static function rubrik_grid_html( $kat, $limit = 60 ) {
		if ( ! in_array( $kat, array( 'race-cars', 'classic-cars' ), true ) ) { return ''; }
		wp_enqueue_style( 'm24fz-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;500;600;700;800&display=swap', array(), null );
		$ids = get_posts( array(
			'post_type'      => M24FZ_CPT::PT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'date', 'order' => 'DESC',
			'meta_query'     => array( array( 'key' => '_m24fz_kat', 'value' => $kat ) ),
		) );
		if ( empty( $ids ) ) { return ''; }

		ob_start();
		echo '<style>' . self::rubrik_css() . '</style>';
		echo '<div class="m24fzr-grid">';
		foreach ( $ids as $id ) {
			$st    = M24FZ_CPT::status( $id );
			$badge = ( 'verkauft' === $st ) ? 'Verkauft' : ( 'reserviert' === $st ? 'Reserviert' : '' );
			$keyf  = array_slice( (array) get_post_meta( $id, '_m24fz_keyfacts', true ), 0, 3 );
			$thumb = has_post_thumbnail( $id ) ? get_the_post_thumbnail( $id, 'medium_large', array( 'loading' => 'lazy' ) ) : '';
			?>
			<a class="m24fzr-card" href="<?php echo esc_url( get_permalink( $id ) ); ?>">
				<span class="m24fzr-img"><?php echo $thumb; // phpcs:ignore ?><?php if ( $badge ) : ?><span class="m24fzr-badge <?php echo esc_attr( $st ); ?>"><?php echo esc_html( $badge ); ?></span><?php endif; ?></span>
				<span class="m24fzr-body">
					<span class="m24fzr-title"><?php echo esc_html( get_the_title( $id ) ); ?></span>
					<?php if ( $keyf ) : ?><ul class="m24fzr-keyf"><?php foreach ( $keyf as $k ) : ?><li><?php echo esc_html( $k ); ?></li><?php endforeach; ?></ul><?php endif; ?>
					<span class="m24fzr-price"><?php echo self::rubrik_price( $id ); // phpcs:ignore ?></span>
				</span>
			</a>
			<?php
		}
		echo '</div>';
		return ob_get_clean();
	}

	/** Kurzer Preis-/Status-Text für die Rubrik-Karte. */
	private static function rubrik_price( $id ) {
		if ( M24FZ_CPT::is_sold( $id ) ) { return '<em>Verkauft</em>'; }
		if ( (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true ) ) { return 'Preis auf Anfrage'; }
		$p = (int) get_post_meta( $id, '_m24fz_preis', true );
		if ( $p <= 0 ) { return 'Preis auf Anfrage'; }
		$cur = M24FZ_Telemetry::currency_symbol( get_post_meta( $id, '_m24fz_waehrung', true ) );
		return esc_html( number_format( $p, 0, ',', '.' ) ) . '&nbsp;' . esc_html( $cur );
	}

	private static function rubrik_css() {
		return ".m24fzr-h{font-family:'Saira',sans-serif;font-size:24px;font-weight:800;margin:0 0 16px}"
			. ".m24fzr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin:0 0 28px;font-family:'Saira',sans-serif}"
			. ".m24fzr-card{display:flex;flex-direction:column;text-decoration:none;color:#14161a;background:#fff;border:1px solid #e6e6e3;border-radius:12px;overflow:hidden;transition:box-shadow .15s}"
			. ".m24fzr-card:hover{box-shadow:0 6px 18px rgba(0,0,0,.10)}"
			. ".m24fzr-img{position:relative;aspect-ratio:3/2;background:#ededea;display:block}.m24fzr-img img{width:100%;height:100%;object-fit:cover;display:block}"
			. ".m24fzr-badge{position:absolute;top:10px;left:10px;color:#fff;font-size:12px;font-weight:700;padding:3px 9px;border-radius:5px}.m24fzr-badge.verkauft{background:#9e2b2b}.m24fzr-badge.reserviert{background:#9a6b25}"
			. ".m24fzr-body{padding:14px 16px 16px;display:flex;flex-direction:column;gap:8px}"
			. ".m24fzr-title{font-size:16px;font-weight:700;line-height:1.25}"
			. ".m24fzr-keyf{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:4px}.m24fzr-keyf li{padding-left:18px;position:relative;font-size:13px;color:#3a3f47}.m24fzr-keyf li:before{content:'✓';position:absolute;left:0;color:#9a6b25;font-weight:700}"
			. ".m24fzr-price{font-size:17px;font-weight:700;color:#9a6b25;margin-top:auto}.m24fzr-price em{color:#9e2b2b;font-style:normal}";
	}

	public static function route( $template ) {
		if ( is_singular( M24FZ_CPT::PT ) ) {
			$f = M24_PLATTFORM_DIR . 'templates/single-m24_fahrzeug.php';
			if ( file_exists( $f ) ) { return $f; }
		}
		return $template;
	}

	public static function assets() {
		if ( ! is_singular( M24FZ_CPT::PT ) ) { return; }
		$css = 'assets/css/fahrzeug.css'; $js = 'assets/js/fahrzeug.js';
		// Asset-Version = Plugin-Version + filemtime → bustet pro Release UND pro Dateiänderung
		// (kein altes Stylesheet in Browser/WP-Rocket). filemtime fresh gelesen, OPcache-unabhängig.
		$cver = M24_PLATTFORM_VERSION . '.' . (int) filemtime( M24_PLATTFORM_DIR . $css );
		$jver = M24_PLATTFORM_VERSION . '.' . (int) filemtime( M24_PLATTFORM_DIR . $js );
		// Saira NUR auf diesem Single-Template (scoped), als Dependency vor dem Template-CSS.
		wp_enqueue_style( 'm24fz-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;500;600;700;800&display=swap', array(), null );
		wp_enqueue_style( 'm24fz', plugins_url( $css, M24_PLATTFORM_FILE ), array( 'm24fz-saira' ), $cver );
		wp_enqueue_script( 'm24fz', plugins_url( $js, M24_PLATTFORM_FILE ), array(), $jver, true );
		wp_localize_script( 'm24fz', 'M24FZ', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'viewping' => rest_url( 'm24/v1/view-ping' ), 'anfrage' => rest_url( 'm24/v1/fahrzeug-anfrage' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'pid' => get_queried_object_id() ) );
	}

	/* ── Render-Helfer (von der Template-Datei genutzt) ──────────────────────── */

	/** Preisblock: „Preis auf Anfrage" / Messing-Preis (+ ggf. reduziert) / bei verkauft kein Preis. */
	public static function preis_html( $id ) {
		if ( M24FZ_CPT::is_sold( $id ) ) { return ''; }
		if ( (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true ) ) { return '<span class="m24fz-preis">Preis auf Anfrage</span>'; }
		$p = (int) get_post_meta( $id, '_m24fz_preis', true );
		if ( $p <= 0 ) { return '<span class="m24fz-preis">Preis auf Anfrage</span>'; }
		$cur = M24FZ_Telemetry::currency_symbol( get_post_meta( $id, '_m24fz_waehrung', true ) );
		$red = (int) get_post_meta( $id, '_m24fz_preis_reduziert', true );
		$fmt = function ( $v ) use ( $cur ) { return esc_html( number_format( $v, 0, ',', '.' ) ) . '&nbsp;' . esc_html( $cur ); };
		// E) Steuerhinweis abhängig von „MwSt. ausweisbar".
		$note = (int) get_post_meta( $id, '_m24fz_mwst_ausweisbar', true ) ? 'Preis inkl. 19&nbsp;% MwSt.' : 'Differenzbesteuert nach §25a UStG';
		$alt  = ( $red > 0 && $red < $p ) ? '<span class="m24fz-preis-alt">' . $fmt( $p ) . '</span>' : '';
		$main = ( $red > 0 && $red < $p ) ? $red : $p;
		return $alt . '<span class="m24fz-preis">' . $fmt( $main ) . '</span><span class="m24fz-preis-note">' . $note . '</span>';
	}

	/**
	 * Jetpack Tiled Gallery (rectangular) — gepacktes „Mauerwerk"-Mosaik mit großen Feature-Kacheln
	 * + kleinen, volle Breite. Hebt die Tiled-Content-Breite auf die boxed Containerbreite (sonst
	 * rendert Classic-Tiled fix auf theme content_width = 696px → ~2/3 gestaucht). Reihenfolge =
	 * übergebene ID-Reihenfolge (ids ⇒ orderby post__in).
	 */
	public static function tiled_gallery( $csv ) {
		$cw = (int) apply_filters( 'm24fz_gallery_content_width', 1036 );
		$f  = static function () use ( $cw ) { return $cw; };
		add_filter( 'tiled_gallery_content_width', $f, 999 );
		$html = do_shortcode( '[gallery ids="' . esc_attr( $csv ) . '" type="rectangular" columns="3" link="file"]' );
		remove_filter( 'tiled_gallery_content_width', $f, 999 );
		return $html;
	}

	/**
	 * Galerie-Block je Kategorie: Jetpack-Tiled-Mosaik mit „9 + +X + Fly-out" OBENDRAUF.
	 * - > $initial Bilder: Vorschau = Tiled der ersten $initial (sichtbar) + Tiled aller (versteckt).
	 *   JS legt „+X · Alle Bilder" auf die letzte Vorschau-Kachel; Klick blendet das volle Mosaik
	 *   per Fly-out ein, „Weniger" klappt zurück.
	 * - ≤ $initial Bilder: nur das volle Mosaik, kein Overlay.
	 *
	 * @param int[]  $ids     Attachment-IDs in Reihenfolge.
	 * @param int    $initial Vorschau-Kachelzahl (Default 9).
	 * @return string Block-HTML.
	 */
	public static function tiled_block( $ids, $initial = 9 ) {
		$ids   = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		$total = count( $ids );
		if ( 0 === $total ) { return ''; }
		$rest = max( 0, $total - $initial );

		if ( $rest <= 0 ) {
			return '<div class="m24fz-tg-full">' . self::tiled_gallery( implode( ',', $ids ) ) . '</div>';
		}
		$preview_csv = implode( ',', array_slice( $ids, 0, $initial ) );
		$full_csv    = implode( ',', $ids );
		$out  = '<div class="m24fz-tg-preview" data-rest="' . (int) $rest . '">' . self::tiled_gallery( $preview_csv ) . '</div>';
		$out .= '<div class="m24fz-tg-full" hidden>' . self::tiled_gallery( $full_csv ) . '</div>';
		$out .= '<button type="button" class="m24fz-gal-less" hidden>Weniger anzeigen</button>';
		return $out;
	}

	/** YouTube-Video-ID aus diversen URL-Formen (youtu.be / watch?v= / embed/ / shorts/). */
	public static function yt_id( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) { return ''; }
		if ( preg_match( '~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|v/))([A-Za-z0-9_-]{11})~', $url, $m ) ) { return $m[1]; }
		if ( preg_match( '~^[A-Za-z0-9_-]{11}$~', $url ) ) { return $url; }
		return '';
	}

	/**
	 * Preisbox-Inhalt je Status (§4):
	 * - verfügbar  → Preis + MwSt + „Jetzt anfragen" + „♡ Fahrzeug parken"
	 * - reserviert → Interessentenliste-Block (Reserviert-Text) + „Auf die Interessentenliste"
	 * - verkauft   → Interessentenliste-Block (Verkauft-Text)   + „Auf die Interessentenliste"
	 */
	public static function pricebox_html( $id ) {
		$st = M24FZ_CPT::status( $id );
		ob_start();
		if ( in_array( $st, array( 'reserviert', 'verkauft' ), true ) ) {
			$txt = ( 'reserviert' === $st )
				? 'Dieses Fahrzeug ist aktuell reserviert. Tragen Sie sich ein und erfahren Sie, sobald dieses Fahrzeug nicht verkauft ist und erhalten Sie über zukünftige ähnliche Fahrzeuge als erster eine Nachricht.'
				: 'Dieses Fahrzeug ist leider schon verkauft. Tragen Sie sich auf die Liste ein, um ähnliche Fahrzeuge in Zukunft als erster zu sehen.';
			echo '<span class="m24fz-statebadge ' . esc_attr( $st ) . '">' . esc_html( 'reserviert' === $st ? 'Reserviert' : 'Verkauft' ) . '</span>';
			echo '<p class="m24fz-iltext">' . esc_html( $txt ) . '</p>';
			echo '<button class="m24fz-btn m24fz-il-open" type="button">Auf die Interessentenliste</button>';
		} else {
			echo self::preis_html( $id ); // phpcs:ignore
			echo '<button class="m24fz-btn m24fz-anfrage-open" type="button">Jetzt anfragen</button>';
			echo '<button class="m24fz-btn ghost m24fz-park" data-m24fz-track="merken" type="button">♡ Fahrzeug parken</button>';
		}
		echo '<div class="m24fz-seller"><strong>MOTORSPORT24 GmbH</strong><span>Internationaler Verkauf von Fahrzeugen seit 2006</span></div>';
		return ob_get_clean();
	}

	/** Ausgewählte Labels einer Mehrfach-Meta (Zustand/Ausstattung) — nur gültige Slugs. */
	public static function chips( $id, $key, $options ) {
		$out = array();
		foreach ( (array) get_post_meta( $id, $key, true ) as $s ) { if ( isset( $options[ $s ] ) ) { $out[] = $options[ $s ]; } }
		return $out;
	}

	/** Alle Galerie-Bilder gruppiert: ['aussen'=>[ids],…] (nur nicht-leere). */
	public static function galleries( $id ) {
		$map = array( 'aussen' => 'Außen', 'innen' => 'Innen', 'motor' => 'Motor', 'unterboden' => 'Unterboden' );
		$out = array();
		foreach ( $map as $k => $label ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_m24fz_gal_' . $k, true ) ) ) );
			if ( $ids ) { $out[ $k ] = array( 'label' => $label, 'ids' => $ids ); }
		}
		return $out;
	}

	/** 3er-Block: erste Außen-Bilder OHNE das Beitragsbild (Hero zeigt es bereits — keine Dublette). */
	public static function block_images( $id, $limit = 3 ) {
		$f      = (int) get_post_thumbnail_id( $id );
		$aussen = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_m24fz_gal_aussen', true ) ) ) );
		$out    = array();
		foreach ( $aussen as $a ) {
			if ( $a === $f ) { continue; }
			$out[] = $a;
			if ( count( $out ) >= $limit ) { break; }
		}
		return $out;
	}

	/** Flaches Bild-Array (Hero/Big-Bilder): Featured zuerst, dann Außen. */
	public static function hero_images( $id ) {
		$ids = array();
		$f   = get_post_thumbnail_id( $id );
		if ( $f ) { $ids[] = (int) $f; }
		foreach ( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_m24fz_gal_aussen', true ) ) ) as $a ) { if ( $a !== (int) $f ) { $ids[] = $a; } }
		return $ids;
	}

	/** Fahrzeugdaten-Zeilen (nur befüllte). */
	public static function daten_rows( $id ) {
		$fields = array(
			'_m24fz_erstzulassung' => 'Erstzulassung', '_m24fz_modell' => 'Modell', '_m24fz_baureihe' => 'Baureihe',
			'_m24fz_karosserie' => 'Karosserie', '_m24fz_hubraum' => 'Hubraum', '_m24fz_leistung_ps' => 'Leistung',
			'_m24fz_getriebe' => 'Getriebe', '_m24fz_antrieb' => 'Antrieb', '_m24fz_lenkung' => 'Lenkung',
			'_m24fz_kraftstoff' => 'Kraftstoff', '_m24fz_laufleistung' => 'Laufleistung', '_m24fz_aussenfarbe' => 'Außenfarbe',
			'_m24fz_farbbez_hersteller' => 'Farbbez. Hersteller', '_m24fz_innenfarbe' => 'Innenfarbe',
			'_m24fz_innenmaterial' => 'Innenmaterial', '_m24fz_fin' => 'FIN', '_m24fz_neu_gebraucht' => 'Zustand',
		);
		// Rennwagen: straßenspezifische Felder nicht ausgeben (Werte bleiben gespeichert).
		$is_renn = ( 'renn' === get_post_meta( $id, '_m24fz_template_typ', true ) );
		if ( $is_renn ) { unset( $fields['_m24fz_erstzulassung'], $fields['_m24fz_kraftstoff'], $fields['_m24fz_lenkung'] ); }
		$rows = array();
		foreach ( $fields as $k => $label ) {
			$v = trim( (string) get_post_meta( $id, $k, true ) );
			if ( '' === $v ) { continue; }
			if ( '_m24fz_leistung_ps' === $k )    { $v = M24FZ_Telemetry::leistung_label( $v ); }
			if ( '_m24fz_laufleistung' === $k )   { $v = M24FZ_Telemetry::laufleistung( $v, get_post_meta( $id, '_m24fz_laufleistung_einheit', true ) ); }
			$rows[] = array( 'label' => $label, 'value' => $v );
		}
		// Optionale Zusatzfelder (leer ⇒ ausgeblendet).
		$halter = (int) get_post_meta( $id, '_m24fz_anzahl_halter', true );
		if ( $halter > 0 ) { $rows[] = array( 'label' => 'Fahrzeughalter', 'value' => (string) $halter ); }
		$toggles = array( '_m24fz_matching_numbers' => 'Matching Numbers', '_m24fz_fahrbereit' => 'Fahrbereit', '_m24fz_zugelassen' => 'Zugelassen' );
		if ( $is_renn ) { unset( $toggles['_m24fz_zugelassen'] ); }
		foreach ( $toggles as $k => $label ) {
			if ( (int) get_post_meta( $id, $k, true ) ) { $rows[] = array( 'label' => $label, 'value' => 'Ja' ); }
		}
		// Land Erstauslieferung / Standort mit Flagge.
		foreach ( array( '_m24fz_land_erstauslieferung' => 'Erstauslieferung', '_m24fz_standort' => 'Standort' ) as $k => $label ) {
			$cc = trim( (string) get_post_meta( $id, $k, true ) );
			if ( '' === $cc ) { continue; }
			$txt = M24FZ_Telemetry::flag( $cc ) . ' ' . M24FZ_Telemetry::country_name( $cc );
			$rows[] = array( 'label' => $label, 'value' => $txt );
		}
		return $rows;
	}
}
