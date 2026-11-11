<?php
/**
 * M24_Catalog_Desk_Teile — Teile aus dem M24 Desk auf motorsport24.de (Etappe 4, 11.11.2026, Daniel).
 *
 * KK Automobile legt Gebrauchtteile im Desk an (Teile-Modul). Der Desk schickt jedes Stück hierher:
 *   POST m24/v1/desk-teil   (Feed-Token wie Teile-Feed/Kleinanzeigen-Warteschlange, oder Admin)
 *   {desk_id, m24_id?, aktion: speichern|verkauft|ausblenden|loeschen, titel, beschreibung, preis_brutto,
 *    typ, lagernr, teilenummer, baureihe, standort, s14, ka, bilder_neu: [{name, mime, data(base64)}],
 *    bilder_weg: [attachment-id …]}
 * Antwort: {ok, m24_id, url, artikelnummer, status, bilder: {name: attachment-id}, s14_url, ka_url}
 *
 * m24 bleibt die Drehscheibe: Ist der Haken s14 gesetzt, geht das Teil über die bestehende Brücke
 * (M24_Catalog_Plattformen::verteilen → an_s14) weiter; Kleinanzeigen landet in der Warteschlange
 * (_m24_ka_offen = 1), die die Browser-Erweiterung abarbeitet. Verkauft/Ausblenden setzt den m24-Status,
 * der Statuswechsel läuft wie gewohnt an s14 weiter. Löschen = Papierkorb (reversibel, nie Hard-Delete).
 * Metas: _m24_desk_id (Desk-Stück-ID), _m24_desk_bilder (Desk-Bildname → Attachment-ID).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Desk_Teile {

	const NS   = 'm24/v1';
	const PT   = 'm24_teil';
	const BILD_MAX_BYTES = 12582912; // 12 MB je Bild
	const GEWERBE_DE = 'Verkauf dieses Teils ausschließlich an Gewerbetreibende, nicht an Privatpersonen.';
	const GEWERBE_EN = 'This part is sold to businesses only, not to private individuals.';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_footer', array( __CLASS__, 'ping_js' ) );
	}

	/* ── Statistik (Daniel, 11.11.2026): Aufrufe je Teil auf m24, cache-sicher per Beacon wie bei Fahrzeugen ── */
	public static function ping_js() {
		if ( ! is_singular( self::PT ) || ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) ) { return; }
		$url = esc_url_raw( rest_url( self::NS . '/teil-ping' ) );
		$id  = (int) get_queried_object_id();
		echo '<script>(function(){try{if(sessionStorage.getItem("m24tp' . $id . '"))return;sessionStorage.setItem("m24tp' . $id . '","1");}catch(e){}var d=JSON.stringify({post_id:' . $id . '});if(navigator.sendBeacon){navigator.sendBeacon(' . wp_json_encode( $url ) . ',new Blob([d],{type:"application/json"}));}else{fetch(' . wp_json_encode( $url ) . ',{method:"POST",headers:{"Content-Type":"application/json"},body:d,keepalive:true});}})();</script>';
	}

	public static function rest_ping( WP_REST_Request $r ) {
		$pid = absint( $r['post_id'] ?? 0 );
		if ( ! $pid || self::PT !== get_post_type( $pid ) ) { return new WP_REST_Response( array( 'counted' => false ), 400 ); }
		$ua = strtolower( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|bing|google|facebookexternalhit|embedly|preview|monitor|pingdom|lighthouse/i', $ua ) ) {
			return new WP_REST_Response( array( 'counted' => false, 'reason' => 'bot' ), 200 );
		}
		$ck = 'm24tp_' . $pid;
		if ( isset( $_COOKIE[ $ck ] ) ) { return new WP_REST_Response( array( 'counted' => false, 'reason' => 'dup' ), 200 ); }
		setcookie( $ck, '1', time() + 6 * HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/' );
		$n = (int) get_post_meta( $pid, '_m24_views', true ) + 1;
		update_post_meta( $pid, '_m24_views', $n );
		$tag = (array) get_post_meta( $pid, '_m24_views_tage', true ); // letzte 60 Tage: {Y-m-d: n}
		$heute = current_time( 'Y-m-d' );
		$tag[ $heute ] = (int) ( $tag[ $heute ] ?? 0 ) + 1;
		ksort( $tag );
		update_post_meta( $pid, '_m24_views_tage', array_slice( $tag, -60, null, true ) );
		return new WP_REST_Response( array( 'counted' => true ), 200 );
	}

	/** Desk ← m24: Aufrufe und Online-Daten je Teil. ?ids=1,2,3 */
	public static function rest_statistik( WP_REST_Request $r ) {
		$ids = array_slice( array_filter( array_map( 'absint', explode( ',', (string) $r->get_param( 'ids' ) ) ) ), 0, 200 );
		$out = array();
		foreach ( $ids as $id ) {
			if ( self::PT !== get_post_type( $id ) ) { continue; }
			$out[ $id ] = array(
				'aufrufe'     => (int) get_post_meta( $id, '_m24_views', true ),
				'online_seit' => get_post_time( 'c', true, $id ),
				'status'      => (string) get_post_meta( $id, '_m24_status', true ) ?: 'aktiv',
				's14_id'      => (int) get_post_meta( $id, '_m24_s14_id', true ),
				'ka_id'       => (string) get_post_meta( $id, '_m24_ka_id', true ),
			);
		}
		return new WP_REST_Response( array( 'ok' => true, 'teile' => $out ), 200 );
	}

	public static function routes() {
		register_rest_route( self::NS, '/desk-teil', array(
			'methods'             => 'POST',
			'permission_callback' => array( 'M24_Catalog_Plattformen', 'feed_token_ok' ),
			'callback'            => array( __CLASS__, 'rest' ),
		) );
		register_rest_route( self::NS, '/desk-teil-statistik', array(
			'methods'             => 'GET',
			'permission_callback' => array( 'M24_Catalog_Plattformen', 'feed_token_ok' ),
			'callback'            => array( __CLASS__, 'rest_statistik' ),
		) );
		register_rest_route( self::NS, '/teil-ping', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'rest_ping' ),
		) );
	}

	/** Teil zur Desk-ID finden (auch im Entwurf/Papierkorb, damit nichts doppelt entsteht). */
	private static function finden( int $desk_id, int $m24_id ): int {
		if ( $m24_id && self::PT === get_post_type( $m24_id ) ) { return $m24_id; }
		if ( ! $desk_id ) { return 0; }
		$p = get_posts( array( 'post_type' => self::PT, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'trash' ), 'fields' => 'ids', 'numberposts' => 1,
			'meta_key' => '_m24_desk_id', 'meta_value' => (string) $desk_id ) );
		return $p ? (int) $p[0] : 0;
	}

	/** Als Admin handeln: die Speicher-Hooks (Artikelnummer, SEO, Slug) prüfen edit_post. */
	private static function als_admin(): void {
		if ( current_user_can( 'edit_posts' ) ) { return; }
		$a = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
		if ( $a ) { wp_set_current_user( (int) $a[0] ); }
	}

	public static function rest( WP_REST_Request $r ) {
		$b       = (array) $r->get_json_params();
		$desk_id = absint( $b['desk_id'] ?? 0 );
		$aktion  = in_array( (string) ( $b['aktion'] ?? '' ), array( 'speichern', 'verkauft', 'ausblenden', 'loeschen' ), true ) ? (string) $b['aktion'] : 'speichern';
		if ( ! $desk_id && empty( $b['m24_id'] ) ) { return new WP_REST_Response( array( 'ok' => false, 'meldung' => 'desk_id fehlt' ), 400 ); }
		self::als_admin();
		$id = self::finden( $desk_id, absint( $b['m24_id'] ?? 0 ) );

		// Stücke aus dem m24-Import: nur Preis, Plattformen und Sichtbarkeit zurückschreiben – Texte/Bilder pflegt der Editor
		if ( 'speichern' === $aktion && ! empty( $b['nur_preis_plattformen'] ) ) {
			if ( ! $id ) { return new WP_REST_Response( array( 'ok' => false, 'meldung' => 'Teil nicht gefunden' ), 404 ); }
			self::modus_setzen( $id, $b['mwst'] ?? null );
			if ( isset( $b['nur_gewerbe'] ) ) { update_post_meta( $id, '_m24_nur_gewerbe', ! empty( $b['nur_gewerbe'] ) ? '1' : '0' ); }
			if ( isset( $b['reserviert'] ) ) { if ( ! empty( $b['reserviert'] ) ) { update_post_meta( $id, '_m24_reserviert', '1' ); } else { delete_post_meta( $id, '_m24_reserviert' ); } }
			if ( (float) ( $b['preis_brutto'] ?? 0 ) > 0 ) { M24_Catalog_Pricing::set_brutto( $id, (float) $b['preis_brutto'] ); }
			$s14 = ! empty( $b['s14'] ) ? '1' : '0';
			if ( (string) get_post_meta( $id, '_m24_pub_s14', true ) !== $s14 ) { update_post_meta( $id, '_m24_pub_s14', $s14 ); delete_post_meta( $id, '_m24_sync_hash' ); }
			$ka = ! empty( $b['ka'] ) ? '1' : '0';
			if ( '1' === $ka && '1' !== (string) get_post_meta( $id, '_m24_kleinanzeigen', true ) ) { update_post_meta( $id, '_m24_ka_offen', '1' ); }
			update_post_meta( $id, '_m24_kleinanzeigen', $ka );
			if ( 'trash' === get_post_status( $id ) ) { wp_untrash_post( $id ); }
			M24_Catalog_Admin_List::set_status( $id, empty( $b['m24'] ) ? 'ausgeblendet' : 'aktiv' );
			M24_Catalog_Plattformen::verteilen( $id, 'desk' );
			M24_Catalog_Plattformen::log( $id, 'Desk: Preis/Plattformen aktualisiert' );
			return new WP_REST_Response( self::antwort( $id ), 200 );
		}

		// Status-Aktionen an einem vorhandenen Teil
		if ( 'speichern' !== $aktion ) {
			if ( ! $id ) { return new WP_REST_Response( array( 'ok' => true, 'm24_id' => 0, 'meldung' => 'nicht auf m24 – nichts zu tun' ), 200 ); }
			if ( 'loeschen' === $aktion ) {
				// erst ausblenden (geht an s14 weiter), dann Papierkorb
				M24_Catalog_Admin_List::set_status( $id, 'ausgeblendet' );
				wp_trash_post( $id );
			} else {
				if ( 'trash' === get_post_status( $id ) ) { wp_untrash_post( $id ); }
				M24_Catalog_Admin_List::set_status( $id, 'verkauft' === $aktion ? 'verkauft' : 'ausgeblendet' );
			}
			M24_Catalog_Plattformen::log( $id, 'Desk: ' . $aktion );
			return new WP_REST_Response( self::antwort( $id ), 200 );
		}

		$titel = trim( sanitize_text_field( (string) ( $b['titel'] ?? '' ) ) );
		$preis = round( (float) ( $b['preis_brutto'] ?? 0 ), 2 );
		if ( '' === $titel ) { return new WP_REST_Response( array( 'ok' => false, 'meldung' => 'Titel fehlt' ), 400 ); }
		if ( $preis <= 0 ) { return new WP_REST_Response( array( 'ok' => false, 'meldung' => 'Preis fehlt' ), 400 ); }

		$neu = ! $id;
		if ( $neu ) {
			$id = wp_insert_post( array( 'post_type' => self::PT, 'post_status' => 'draft', 'post_title' => $titel ), true );
			if ( is_wp_error( $id ) ) { return new WP_REST_Response( array( 'ok' => false, 'meldung' => $id->get_error_message() ), 500 ); }
			update_post_meta( $id, '_m24_desk_id', (string) $desk_id );
			update_post_meta( $id, '_m24_standort', 'berlin' === ( $b['standort'] ?? '' ) ? 'berlin' : 'duederode' );
		} elseif ( 'trash' === get_post_status( $id ) ) {
			wp_untrash_post( $id );
		}

		// Inhalte
		update_post_meta( $id, '_m24_typ', 'neu' === ( $b['typ'] ?? '' ) ? 'neu' : 'gebraucht' );
		$beschr = (string) ( $b['beschreibung'] ?? '' );
		// Verkauf nur an Gewerbetreibende (Daniel, 11.11.2026): Zeile im Kleinanzeigen-Block (Feed)
		$gewerbe = ! empty( $b['nur_gewerbe'] );
		update_post_meta( $id, '_m24_nur_gewerbe', $gewerbe ? '1' : '0' );
		if ( ! empty( $b['reserviert'] ) ) { update_post_meta( $id, '_m24_reserviert', '1' ); } else { delete_post_meta( $id, '_m24_reserviert' ); }
		// Daniel 11.11.2026 abends: Gewerbe-Satz vorerst nicht in die Beschreibung (m24/s14), nur Kleinanzeigen-Zeile VERKAUF
		update_post_meta( $id, '_m24_beschreibung_de', wp_kses_post( wpautop( esc_html( $beschr ) ) ) );
		if ( isset( $b['teilenummer'] ) ) { update_post_meta( $id, '_m24_bmw_teilenummer', sanitize_text_field( (string) $b['teilenummer'] ) ); }
		if ( ! empty( $b['lagernr'] ) ) { update_post_meta( $id, '_m24_desk_lagernr', sanitize_text_field( (string) $b['lagernr'] ) ); }
		self::modus_setzen( $id, $b['mwst'] ?? null );
		M24_Catalog_Pricing::set_brutto( $id, $preis );
		if ( ! empty( $b['baureihe'] ) ) {
			$terms = array();
			foreach ( preg_split( '/\s*,\s*/', (string) $b['baureihe'] ) as $n ) {
				$t = get_term_by( 'name', $n, M24_Catalog_CPT::TAXONOMY );
				if ( $t ) { $terms[] = (int) $t->term_id; }
			}
			if ( $terms ) { wp_set_object_terms( $id, $terms, M24_Catalog_CPT::TAXONOMY, false ); }
		}

		// Bilder: neue hochladen, entfernte aus der Galerie nehmen
		$map     = (array) get_post_meta( $id, '_m24_desk_bilder', true );
		$galerie = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $id, '_m24_galerie', true ) ) ) );
		$fehler  = array();
		foreach ( (array) ( $b['bilder_weg'] ?? array() ) as $name ) {
			$name = (string) $name;
			if ( isset( $map[ $name ] ) ) { $galerie = array_values( array_diff( $galerie, array( (int) $map[ $name ] ) ) ); unset( $map[ $name ] ); }
		}
		if ( ! empty( $b['bilder_neu'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			foreach ( (array) $b['bilder_neu'] as $bild ) {
				$name = preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) ( $bild['name'] ?? '' ) );
				if ( ! $name || isset( $map[ $name ] ) ) { continue; }
				$roh = base64_decode( (string) ( $bild['data'] ?? '' ), true );
				if ( false === $roh || strlen( $roh ) > self::BILD_MAX_BYTES ) { $fehler[] = $name . ': ungültig/zu groß'; continue; }
				$tmp = wp_tempnam( $name );
				file_put_contents( $tmp, $roh );
				$ext = preg_match( '/\.(png|webp)$/i', $name, $m ) ? strtolower( $m[1] ) : 'jpg';
				$att = media_handle_sideload( array( 'name' => sanitize_title( $titel ) . '-' . substr( $name, 0, 8 ) . '.' . $ext, 'tmp_name' => $tmp ), $id, $titel );
				if ( is_wp_error( $att ) ) { @unlink( $tmp ); $fehler[] = $name . ': ' . $att->get_error_message(); continue; }
				update_post_meta( $att, '_wp_attachment_image_alt', $titel );
				$map[ $name ] = (int) $att;
				$galerie[]    = (int) $att;
			}
		}
		// Reihenfolge wie im Desk, falls mitgegeben
		if ( ! empty( $b['bilder_reihenfolge'] ) && is_array( $b['bilder_reihenfolge'] ) ) {
			$sortiert = array();
			foreach ( $b['bilder_reihenfolge'] as $n ) { if ( isset( $map[ (string) $n ] ) ) { $sortiert[] = (int) $map[ (string) $n ]; } }
			$galerie = array_values( array_unique( array_merge( $sortiert, $galerie ) ) );
		}
		update_post_meta( $id, '_m24_desk_bilder', $map );
		update_post_meta( $id, '_m24_galerie', implode( ',', array_unique( $galerie ) ) );
		if ( method_exists( 'M24_Catalog_Gallery', 'thumb_aus_galerie' ) ) { M24_Catalog_Gallery::thumb_aus_galerie( $id ); }

		// Plattformen
		$s14 = ! empty( $b['s14'] ) ? '1' : '0';
		$ka  = ! empty( $b['ka'] ) ? '1' : '0';
		$s14_vorher = (string) get_post_meta( $id, '_m24_pub_s14', true );
		update_post_meta( $id, '_m24_pub_s14', $s14 );
		if ( $s14_vorher !== $s14 ) { delete_post_meta( $id, '_m24_sync_hash' ); }
		update_post_meta( $id, '_m24_kleinanzeigen', $ka );
		if ( '1' === $ka ) { update_post_meta( $id, '_m24_ka_offen', '1' ); }

		// Titel + Veröffentlichen: der Speicher-Hook (Artikelnummer, Slug, SEO, verteilen → s14) läuft hier
		$m24_an = ! isset( $b['m24'] ) || ! empty( $b['m24'] );
		wp_update_post( array( 'ID' => $id, 'post_title' => $titel, 'post_status' => 'publish' ) );
		M24_Catalog_Admin_List::set_status( $id, $m24_an ? 'aktiv' : 'ausgeblendet' );
		M24_Catalog_Plattformen::verteilen( $id, 'desk' );
		M24_Catalog_Plattformen::log( $id, 'Desk: ' . ( $neu ? 'angelegt' : 'aktualisiert' ) . ( $fehler ? ' – Bildfehler: ' . implode( '; ', $fehler ) : '' ) );

		$out = self::antwort( $id );
		$out['bilder'] = $map;
		if ( $fehler ) { $out['bildfehler'] = $fehler; }
		return new WP_REST_Response( $out, 200 );
	}

	/** Besteuerung aus dem Desk: '25a' → paragraf25a, sonst regel (inkl. 19 % MwSt.). Netto der Preisoption nachziehen. */
	private static function modus_setzen( int $id, $mwst ): void {
		if ( null === $mwst || '' === (string) $mwst ) { return; }
		$neu = '25a' === (string) $mwst ? 'paragraf25a' : 'regel';
		if ( (string) get_post_meta( $id, '_m24_mwst_modus', true ) === $neu ) { return; }
		update_post_meta( $id, '_m24_mwst_modus', $neu );
		$opts = json_decode( (string) get_post_meta( $id, '_m24_preisoptionen', true ), true );
		if ( is_array( $opts ) && $opts ) {
			foreach ( $opts as &$o ) { $b = (float) ( $o['brutto'] ?? 0 ); $o['netto'] = 'paragraf25a' === $neu ? null : ( $b > 0 ? round( $b / 1.19, 2 ) : null ); }
			unset( $o );
			update_post_meta( $id, '_m24_preisoptionen', wp_json_encode( $opts, JSON_UNESCAPED_UNICODE ) );
		}
	}

	private static function antwort( int $id ): array {
		return array(
			'ok'            => true,
			'm24_id'        => $id,
			'url'           => 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '',
			'artikelnummer' => (string) get_post_meta( $id, '_m24_artikelnummer', true ),
			'status'        => (string) get_post_meta( $id, '_m24_status', true ),
			's14_url'       => (string) get_post_meta( $id, '_m24_s14_url', true ),
			'ka_url'        => (string) get_post_meta( $id, '_m24_ka_url', true ),
		);
	}
}

M24_Catalog_Desk_Teile::init();
