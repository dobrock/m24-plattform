<?php
/**
 * M24 Plattform — Katalog: Teile-Feed für Kleinanzeigen & Co. (28.10.2026, Daniel: „Feed bauen“)
 * Modul: catalog-feed.php
 *
 * Kleinanzeigen hat keinen Import und keine API. Drittanbieter-Tools (Kleinanzeigen-Enhanced u. a.) lesen aber
 * Feeds (JSON/CSV) und legen daraus Anzeigen an. Dieser Feed liefert die aktiven Teile im Kleinanzeigen-Zuschnitt:
 * Titel ≤ 65 Zeichen, Klartext-Beschreibung mit Artikelnummer + Shop-Link, Brutto-Festpreis in ganzen Euro,
 * Bild-URLs (max. 20), Kategorie-Pfad, PLZ, Zustand. Eine Quelle für alle Marktplätze — Preis/Bestand kommen
 * immer aus dem Katalog, nichts wird doppelt gepflegt.
 *
 *   GET /wp-json/m24/v1/teile-feed?token=…            → JSON  { generated, count, items: [...] }
 *   GET /wp-json/m24/v1/teile-feed?token=…&format=csv → CSV (UTF-8 mit BOM, Semikolon; Bilder mit | getrennt)
 *   Parameter: typ=gebraucht|neu|alle (Standard gebraucht), seit=YYYY-MM-DD (nur seitdem geänderte), limit (Std. 500)
 *
 * Token: Option m24_teile_feed_token (beim ersten Aufruf der Admin-Seite erzeugt). Eingeloggte Admins brauchen keins.
 * Admin-Seite „Teile-Feed" (MOTORSPORT24-Menü) zeigt die URLs, Vorschau und den Knopf „Token erneuern".
 *
 * Markenrecht (Daniel): Titel übernehmen den Katalogtitel 1:1 – dort ist die BMW-Schreibweise schon kompakt
 * (T22b). Der Feed fügt keine Markennamen hinzu. Im Beschreibungstext steht der neutrale Hinweis „passend für".
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Feed {

	const NS      = 'm24/v1';
	const ROUTE   = '/teile-feed';
	const OPT_TOK = 'm24_teile_feed_token';
	const SLUG    = 'm24-teile-feed';
	const TITEL_MAX = 65;   // Kleinanzeigen: Titel max. 65 Zeichen
	const BILDER_MAX = 20;  // Kleinanzeigen: max. 20 Bilder je Anzeige

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_post_m24_feed_token', array( __CLASS__, 'token_erneuern' ) );
	}

	public static function routes() {
		register_rest_route( self::NS, self::ROUTE, array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'darf' ),
			'callback'            => array( __CLASS__, 'rest' ),
			'args'                => array(
				'token'  => array( 'default' => '' ),
				'format' => array( 'default' => 'json' ),
				'typ'    => array( 'default' => 'gebraucht' ),
				'seit'   => array( 'default' => '' ),
				'limit'  => array( 'default' => 500 ),
			),
		) );
	}

	/* ── Zugang ─────────────────────────────────────────────────────────────── */

	public static function token(): string {
		$t = (string) get_option( self::OPT_TOK, '' );
		if ( '' === $t ) { $t = wp_generate_password( 32, false, false ); update_option( self::OPT_TOK, $t, false ); }
		return $t;
	}

	public static function darf( WP_REST_Request $r ): bool {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		$t = (string) $r->get_param( 'token' );
		return '' !== $t && hash_equals( self::token(), $t );
	}

	public static function token_erneuern() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'm24_feed_token' ) ) { wp_die( 'Nicht erlaubt.' ); }
		update_option( self::OPT_TOK, wp_generate_password( 32, false, false ), false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&erneuert=1' ) );
		exit;
	}

	/* ── Daten ──────────────────────────────────────────────────────────────── */

	/** Aktive, veröffentlichte Teile als Feed-Datensätze. */
	public static function items( string $typ = 'gebraucht', string $seit = '', int $limit = 500 ): array {
		$mq = array( 'relation' => 'AND',
			array( 'relation' => 'OR',
				array( 'key' => '_m24_status', 'value' => 'aktiv' ),
				array( 'key' => '_m24_status', 'compare' => 'NOT EXISTS' ) ),
			array( 'relation' => 'OR',
				array( 'key' => '_m24_preis_auf_anfrage', 'value' => '1', 'compare' => '!=' ),
				array( 'key' => '_m24_preis_auf_anfrage', 'compare' => 'NOT EXISTS' ) ),
		);
		if ( 'alle' !== $typ ) {
			$mq[] = ( 'gebraucht' === $typ )
				? array( 'relation' => 'OR', array( 'key' => '_m24_typ', 'value' => 'gebraucht' ), array( 'key' => '_m24_typ', 'compare' => 'NOT EXISTS' ) )
				: array( 'key' => '_m24_typ', 'value' => $typ );
		}
		$args = array(
			'post_type' => M24_Catalog_CPT::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => max( 1, min( 2000, $limit ) ),
			'orderby' => 'modified', 'order' => 'DESC', 'meta_query' => $mq, 'no_found_rows' => true,
		);
		if ( $seit && preg_match( '/^\d{4}-\d{2}-\d{2}/', $seit ) ) { $args['date_query'] = array( array( 'column' => 'post_modified_gmt', 'after' => $seit ) ); }
		$out = array();
		foreach ( get_posts( $args ) as $p ) {
			$it = self::item( $p );
			if ( $it ) { $out[] = $it; }
		}
		return $out;
	}

	/** Ein Teil → Feed-Datensatz; null, wenn kein Preis oder kein Bild (Kleinanzeigen verlangt beides nicht, wir schon). */
	public static function item( WP_Post $p ): ?array {
		$id    = (int) $p->ID;
		$preis = class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::get( $id ) : array( 'brutto' => 0, 'modus' => 'regel' );
		$brutto = (float) ( $preis['brutto'] ?? 0 );
		if ( $brutto <= 0 ) { return null; }

		$g = function ( $k ) use ( $id ) { return trim( (string) get_post_meta( $id, $k, true ) ); };
		$bilder = array();
		$thumb  = (int) get_post_thumbnail_id( $id );
		$ids    = array_filter( array_map( 'intval', explode( ',', (string) $g( '_m24_galerie' ) ) ) );
		if ( $thumb ) { array_unshift( $ids, $thumb ); }
		foreach ( array_unique( $ids ) as $aid ) {
			$u = wp_get_attachment_image_url( $aid, 'full' );
			if ( $u ) { $bilder[] = $u; }
			if ( count( $bilder ) >= self::BILDER_MAX ) { break; }
		}
		$modelle   = wp_get_post_terms( $id, M24_Catalog_CPT::TAXONOMY, array( 'fields' => 'names' ) );
		$baugruppe = wp_get_post_terms( $id, M24_Catalog_CPT::TAXONOMY_BAUGRUPPE, array( 'fields' => 'names' ) );
		$modelle   = is_wp_error( $modelle ) ? array() : $modelle;
		$baugruppe = is_wp_error( $baugruppe ) ? array() : $baugruppe;
		$typ       = $g( '_m24_typ' ) ?: 'gebraucht';
		$titel     = html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' );
		$url       = (string) get_permalink( $p );
		$artnr     = $g( '_m24_artikelnummer' );
		$teilenr   = $g( '_m24_bmw_teilenummer' );
		$hinweis   = $g( '_m24_hinweis' );
		$beschr    = self::klartext( (string) get_post_meta( $id, '_m24_beschreibung_de', true ) );

		$zeilen = array_filter( array(
			$beschr,
			$modelle ? 'Passend für: ' . implode( ', ', $modelle ) : '',
			$teilenr ? 'Teilenummer: ' . $teilenr : '',
			$artnr ? 'Artikelnummer: ' . $artnr : '',
			$hinweis,
			'gebraucht' === $typ ? 'Gebrauchtteil, geprüft – Zustand siehe Fotos. Versand oder Abholung in Berlin möglich.' : 'Neuteil. Versand oder Abholung in Berlin möglich.',
			'paragraf25a' === ( $preis['modus'] ?? '' ) ? 'Differenzbesteuert nach § 25a UStG, keine MwSt. ausweisbar.' : 'Preis inkl. 19 % MwSt., Rechnung mit ausgewiesener MwSt.',
			'Alle Details und weitere Bilder: ' . $url,
		) );

		return array(
			'id'            => $id,
			'art_nr'        => $artnr,
			'teilenummer'   => $teilenr,
			'titel'         => self::kuerzen( $titel, self::TITEL_MAX ),
			'titel_voll'    => $titel,
			'beschreibung'  => implode( "\n\n", $zeilen ),
			'preis'         => (int) round( $brutto ),       // Kleinanzeigen: ganze Euro, Festpreis
			'preis_brutto'  => round( $brutto, 2 ),
			'preistyp'      => 'Festpreis',
			'mwst'          => 'paragraf25a' === ( $preis['modus'] ?? '' ) ? '25a' : '19',
			'zustand'       => 'gebraucht' === $typ ? 'Gebraucht' : 'Neu',
			'typ'           => $typ,
			'kategorie'     => 'Auto, Rad & Boot > Autoteile & Reifen',
			'versand'       => 'Versand möglich',
			'plz'           => (string) apply_filters( 'm24_feed_plz', '13595' ),
			'ort'           => (string) apply_filters( 'm24_feed_ort', 'Berlin' ),
			'modelle'       => array_values( $modelle ),
			'baugruppe'     => array_values( $baugruppe ),
			'bilder'        => $bilder,
			'url'           => $url,
			'geaendert'     => get_post_modified_time( 'c', true, $p ),
		);
	}

	/** HTML → Klartext (Absätze erhalten), für Anzeigentexte. */
	private static function klartext( string $html ): string {
		$t = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$t = preg_replace( '#</(p|div|li|h[1-6])>#i', "\n", $t );
		$t = wp_strip_all_tags( $t );
		$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
		$t = preg_replace( "/[ \t]+/", ' ', $t );
		$t = preg_replace( "/\n{3,}/", "\n\n", $t );
		return trim( $t );
	}

	/** Auf max. Zeichen kürzen, möglichst an der Wortgrenze, ohne „…“ (Kleinanzeigen zählt es mit). */
	private static function kuerzen( string $s, int $max ): string {
		$s = trim( preg_replace( '/\s+/', ' ', $s ) );
		if ( mb_strlen( $s ) <= $max ) { return $s; }
		$k = mb_substr( $s, 0, $max );
		$sp = mb_strrpos( $k, ' ' );
		if ( false !== $sp && $sp > $max * 0.6 ) { $k = mb_substr( $k, 0, $sp ); }
		return rtrim( $k, " ,-–/·" );
	}

	/* ── Ausgabe ────────────────────────────────────────────────────────────── */

	public static function rest( WP_REST_Request $r ) {
		$typ   = sanitize_key( (string) $r->get_param( 'typ' ) ) ?: 'gebraucht';
		$items = self::items( in_array( $typ, array( 'gebraucht', 'neu', 'alle' ), true ) ? $typ : 'gebraucht', sanitize_text_field( (string) $r->get_param( 'seit' ) ), (int) $r->get_param( 'limit' ) );
		if ( 'csv' === strtolower( (string) $r->get_param( 'format' ) ) ) {
			$cols = array( 'id', 'art_nr', 'teilenummer', 'titel', 'beschreibung', 'preis', 'preistyp', 'mwst', 'zustand', 'kategorie', 'versand', 'plz', 'ort', 'modelle', 'baugruppe', 'bilder', 'url', 'geaendert' );
			$fh = fopen( 'php://temp', 'w+' );
			fwrite( $fh, "\xEF\xBB\xBF" );
			fputcsv( $fh, $cols, ';' );
			foreach ( $items as $it ) {
				fputcsv( $fh, array_map( function ( $c ) use ( $it ) { $v = $it[ $c ] ?? ''; return is_array( $v ) ? implode( '|', $v ) : (string) $v; }, $cols ), ';' );
			}
			rewind( $fh );
			$csv = stream_get_contents( $fh );
			fclose( $fh );
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="m24-teile-feed.csv"' );
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		return rest_ensure_response( array( 'generated' => gmdate( 'c' ), 'typ' => $typ, 'count' => count( $items ), 'items' => $items ) );
	}

	/* ── Admin-Seite ────────────────────────────────────────────────────────── */

	public static function menu() {
		add_submenu_page( 'm24-plattform', 'Teile-Feed', 'Teile-Feed', 'manage_options', self::SLUG, array( __CLASS__, 'seite' ) );
	}

	public static function seite() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$tok  = self::token();
		$base = rest_url( self::NS . self::ROUTE );
		$json = add_query_arg( array( 'token' => $tok ), $base );
		$csv  = add_query_arg( array( 'token' => $tok, 'format' => 'csv' ), $base );
		$items = self::items( 'gebraucht', '', 5 );
		$n     = count( self::items( 'gebraucht', '', 2000 ) );
		echo '<div class="wrap"><h1>Teile-Feed</h1>';
		if ( ! empty( $_GET['erneuert'] ) ) { echo '<div class="notice notice-success"><p>Token erneuert – alte Feed-URLs sind ungültig.</p></div>'; } // phpcs:ignore
		echo '<p>Aktive Gebrauchtteile mit Preis und Bild im Kleinanzeigen-Zuschnitt (Titel ≤ 65 Zeichen, Klartext, Festpreis in ganzen Euro, max. 20 Bilder). Der Feed ist die eine Quelle für Kleinanzeigen-Tools, eBay & Co. – Preis und Bestand kommen immer aus dem Katalog.</p>';
		echo '<p><strong>' . (int) $n . '</strong> Gebrauchtteile im Feed. Varianten: <code>&amp;typ=neu</code>, <code>&amp;typ=alle</code>, <code>&amp;seit=2026-11-01</code> (nur seitdem geändert).</p>';
		echo '<table class="widefat striped" style="max-width:980px"><tbody>';
		echo '<tr><th style="width:120px">JSON</th><td><code style="user-select:all">' . esc_html( $json ) . '</code></td></tr>';
		echo '<tr><th>CSV</th><td><code style="user-select:all">' . esc_html( $csv ) . '</code> <a class="button button-small" href="' . esc_url( $csv ) . '">Herunterladen</a></td></tr>';
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0 24px" onsubmit="return confirm(\'Token wirklich erneuern? Eingetragene Feed-URLs in Fremd-Tools müssen danach ersetzt werden.\')"><input type="hidden" name="action" value="m24_feed_token">' . wp_nonce_field( 'm24_feed_token', '_wpnonce', true, false ) . '<button class="button">Token erneuern</button> <span class="description">Die URL enthält das Token – nicht öffentlich posten.</span></form>';
		echo '<h2>Vorschau (die 5 zuletzt geänderten)</h2>';
		foreach ( $items as $it ) {
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin:0 0 10px;max-width:980px;display:grid;grid-template-columns:96px 1fr;gap:14px">';
			echo '<div>' . ( $it['bilder'] ? '<img src="' . esc_url( $it['bilder'][0] ) . '" style="width:96px;height:72px;object-fit:cover;border-radius:4px">' : '<div style="width:96px;height:72px;background:#eee;border-radius:4px"></div>' ) . '<div style="font-size:11px;color:#666;margin-top:4px">' . count( $it['bilder'] ) . ' Bilder</div></div>';
			echo '<div><strong>' . esc_html( $it['titel'] ) . '</strong> <span style="color:#666">(' . mb_strlen( $it['titel'] ) . '/65)</span> · <b>' . (int) $it['preis'] . ' €</b> · ' . esc_html( $it['zustand'] ) . ' · ' . esc_html( $it['art_nr'] ) . '<pre style="white-space:pre-wrap;font:12px/1.5 inherit;margin:6px 0 0;color:#333">' . esc_html( $it['beschreibung'] ) . '</pre></div></div>';
		}
		echo '</div>';
	}
}
