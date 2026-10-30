<?php
/**
 * M24_Catalog_Plattformen — „Veröffentlicht auf" (Teile · 30.10.2026, Daniel).
 *
 * Ein Teil, drei Plattformen: m24 · s14.de · Kleinanzeigen. Diese Box im Teile-Editor
 * zeigt, wo das Teil online ist, lässt beim Anlegen die Zielplattformen wählen und
 * hält die Texte in Sync (Vorschlag A: eigene Seitenleisten-Box).
 *
 *  m24 → s14   : nach dem Speichern (Prio 45, nach Slug/SEO) geht Titel/Text/Preis/Status
 *                an s14 `POST s14/v1/m24/teil` (Basic Auth MOTORSPORT24:M24_S14_TOKEN, wie die
 *                Fahrzeug-Brücke). Nur wenn sich etwas geändert hat (sha1-Merker) und der Haken
 *                „s14.de" gesetzt ist. Statuswechsel (aktiv/ausgeblendet/verkauft) gehen sofort.
 *  s14 → m24   : s14 meldet Änderungen an `POST m24/v1/teil-sync` (Anwendungspasswort, edit_posts).
 *                Der Rückweg setzt eine Sperre, damit nichts im Kreis läuft.
 *  Kleinanzeigen: keine API. m24 führt eine Warteschlange (`GET m24/v1/ka-warteschlange`, Feed-Token);
 *                die M24-Browser-Erweiterung schreibt die Anzeige in Daniels Chrome und meldet
 *                Anzeigen-ID/-URL über `POST m24/v1/ka-rueckmeldung` zurück.
 *
 * Metas: _m24_pub_s14 (0/1) · _m24_kleinanzeigen (vorhanden, 0/1) · _m24_s14_id · _m24_s14_url ·
 *        _m24_s14_stand · _m24_ka_id · _m24_ka_url · _m24_ka_stand · _m24_ka_offen (1 = zu übertragen) ·
 *        _m24_sync_hash · _m24_sync_log (letzte 8 Einträge)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Plattformen {

	const NS = 'm24/v1';
	const PT = 'm24_teil';
	const LOG_MAX = 8;

	/** Sperre: während ein Fremd-Update (s14 → m24) läuft, nichts zurückschicken. */
	private static $sperre = false;

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . self::PT, array( __CLASS__, 'save' ), 45, 2 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_geaendert' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_geaendert' ), 10, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_ajax_m24_plattform_senden', array( __CLASS__, 'ajax_senden' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ── Konfiguration ─────────────────────────────────────────────────────── */

	public static function s14_basis(): string {
		return untrailingslashit( defined( 'M24_S14_BASIS' ) ? (string) M24_S14_BASIS : 'https://s14.de' );
	}
	public static function s14_token(): string {
		return defined( 'M24_S14_TOKEN' ) ? trim( (string) M24_S14_TOKEN ) : '';
	}
	private static function s14_auth(): string {
		return 'Basic ' . base64_encode( 'MOTORSPORT24:' . self::s14_token() );
	}

	/* ── Hilfen ────────────────────────────────────────────────────────────── */

	private static function m( int $id, string $k, $d = '' ) {
		$v = get_post_meta( $id, $k, true );
		return ( '' === $v || null === $v ) ? $d : $v;
	}

	public static function status( int $id ): string {
		if ( 'draft' === get_post_status( $id ) ) { return 'entwurf'; }
		$s = (string) self::m( $id, '_m24_status', 'aktiv' );
		return in_array( $s, array( 'aktiv', 'ausgeblendet', 'verkauft' ), true ) ? $s : 'aktiv';
	}

	/** Was synchronisiert wird – eine Quelle für Hash und Nutzlast. */
	public static function nutzlast( int $id ): array {
		$preis  = class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::get( $id ) : array( 'brutto' => 0, 'modus' => 'regel' );
		$brutto = (float) ( $preis['brutto'] ?? 0 );
		$modelle = wp_get_post_terms( $id, M24_Catalog_CPT::TAXONOMY, array( 'fields' => 'names' ) );
		$bilder = array();
		$thumb  = (int) get_post_thumbnail_id( $id );
		$ids    = array_filter( array_map( 'intval', explode( ',', (string) self::m( $id, '_m24_galerie' ) ) ) );
		if ( $thumb ) { array_unshift( $ids, $thumb ); }
		foreach ( array_unique( $ids ) as $aid ) {
			$u = wp_get_attachment_image_url( $aid, 'full' );
			if ( $u ) { $bilder[] = $u; }
			if ( count( $bilder ) >= 20 ) { break; }
		}
		return array(
			'm24_post_id'   => $id,
			'm24_url'       => (string) get_permalink( $id ),
			'titel'         => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'beschreibung'  => (string) self::m( $id, '_m24_beschreibung_de' ),     // HTML, 1:1 (Daniel: Text überall gleich)
			'beschreibung_en' => (string) self::m( $id, '_m24_beschreibung_en' ),
			'titel_en'      => (string) self::m( $id, '_m24_titel_en' ),
			'preis'         => $brutto > 0 ? (int) round( $brutto ) : 0,
			'preis_auf_anfrage' => $brutto <= 0,
			'steuer'        => 'paragraf25a' === ( $preis['modus'] ?? '' ) ? '25a' : 'regel',
			'status'        => self::status( $id ),                                   // aktiv | ausgeblendet | verkauft | entwurf
			'zustand'       => 'neu' === (string) self::m( $id, '_m24_typ', 'gebraucht' ) ? 'neu' : 'gebraucht',
			'artikelnummer' => (string) self::m( $id, '_m24_artikelnummer' ),
			'teilenummer'   => (string) self::m( $id, '_m24_bmw_teilenummer' ),
			'standort'      => 'berlin' === (string) self::m( $id, '_m24_standort' ) ? 'Berlin' : 'Kalefeld',
			'modelle'       => is_wp_error( $modelle ) ? array() : array_values( $modelle ),
			'bilder'        => $bilder,
		);
	}

	public static function hash( array $n ): string {
		return sha1( wp_json_encode( array( $n['titel'], $n['beschreibung'], $n['preis'], $n['status'], $n['zustand'], $n['steuer'] ) ) );
	}

	public static function log( int $id, string $text ): void {
		$l = get_post_meta( $id, '_m24_sync_log', true );
		$l = is_array( $l ) ? $l : array();
		array_unshift( $l, array( 't' => time(), 'x' => mb_substr( $text, 0, 200 ) ) );
		update_post_meta( $id, '_m24_sync_log', array_slice( $l, 0, self::LOG_MAX ) );
	}

	/* ── Box ───────────────────────────────────────────────────────────────── */

	public static function add_box() {
		add_meta_box( 'm24_teil_plattformen', 'Veröffentlicht auf', array( __CLASS__, 'render' ), self::PT, 'side', 'high' );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
		$screen = get_current_screen();
		if ( ! $screen || self::PT !== $screen->post_type ) { return; }
		wp_add_inline_style( 'wp-admin', '
#m24_teil_plattformen .pf-row{display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f0f0f1;font-size:13px}
#m24_teil_plattformen .pf-row:last-of-type{border-bottom:0}
#m24_teil_plattformen .pf-dot{width:9px;height:9px;border-radius:50%;background:#9aa0a8;flex:none}
#m24_teil_plattformen .pf-dot.ok{background:#2e8b57}#m24_teil_plattformen .pf-dot.warn{background:#c98a1b}#m24_teil_plattformen .pf-dot.bad{background:#b3121f}
#m24_teil_plattformen .pf-name{min-width:96px;font-weight:600}
#m24_teil_plattformen .pf-st{color:#646970;flex:1;min-width:0}
#m24_teil_plattformen .pf-row a{font-size:12px;white-space:nowrap}
#m24_teil_plattformen h4{margin:12px 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970}
#m24_teil_plattformen label.pf-cb{display:flex;align-items:center;gap:6px;margin:4px 0;font-size:13px}
#m24_teil_plattformen .pf-kaid{display:flex;align-items:center;gap:6px;margin-top:6px}
#m24_teil_plattformen .pf-kaid input{width:130px}
#m24_teil_plattformen .pf-log{font:11px/1.5 Menlo,Consolas,monospace;color:#646970;border-top:1px dashed #dcdcde;margin-top:8px;padding-top:6px;max-height:120px;overflow:auto}
#m24_teil_plattformen .pf-btns{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap}
#m24_teil_plattformen .pf-meld{font-size:12px;margin-top:6px;color:#646970}
' );
		wp_add_inline_script( 'jquery-core', "
jQuery(function($){
  $('#m24_teil_plattformen').on('click','[data-pf-send]',function(e){
    e.preventDefault(); var b=$(this), m=$('#m24_teil_plattformen .pf-meld');
    b.prop('disabled',true); m.text('sendet an s14.de …');
    $.post(ajaxurl,{action:'m24_plattform_senden',post_id:b.data('pf-send'),_wpnonce:b.data('nonce')}).done(function(r){
      m.text(r&&r.data&&r.data.meldung?r.data.meldung:(r.success?'übertragen':'Fehler'));
      if(r.success&&r.data&&r.data.html){ $('#m24_teil_plattformen .pf-status').replaceWith(r.data.html); }
    }).fail(function(){ m.text('Verbindung fehlgeschlagen'); }).always(function(){ b.prop('disabled',false); });
  });
});
" );
	}

	public static function status_html( int $id ): string {
		$st   = self::status( $id );
		$s14  = (int) self::m( $id, '_m24_s14_id', 0 );
		$s14u = (string) self::m( $id, '_m24_s14_url' );
		if ( ! $s14u && class_exists( 'M24_Catalog_Feed' ) ) { $s14u = M24_Catalog_Feed::s14_link( $id, (string) get_permalink( $id ) ); }
		$s14s = (string) self::m( $id, '_m24_s14_stand' );
		$kaid = (string) self::m( $id, '_m24_ka_id' );
		$kau  = (string) self::m( $id, '_m24_ka_url' ) ?: ( $kaid ? 'https://www.kleinanzeigen.de/s-anzeige/' . rawurlencode( $kaid ) : '' );
		$kas  = (string) self::m( $id, '_m24_ka_stand' );
		$kaoffen = '1' === (string) self::m( $id, '_m24_ka_offen', '0' );
		$pub_s14 = '1' === (string) self::m( $id, '_m24_pub_s14', '0' );
		$pub_ka  = '1' === (string) self::m( $id, '_m24_kleinanzeigen', '0' );
		$preis  = class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::get( $id ) : array( 'brutto_fmt' => '' );
		$pf     = (string) ( $preis['brutto_fmt'] ?? '' );
		$zeit   = function ( $s ) { return $s ? date_i18n( 'd.m. H:i', strtotime( $s ) ) : ''; };

		$row = function ( $dot, $name, $st, $link, $linktext = 'ansehen' ) {
			return '<div class="pf-row"><span class="pf-dot ' . esc_attr( $dot ) . '"></span><span class="pf-name">' . esc_html( $name ) . '</span><span class="pf-st">' . esc_html( $st ) . '</span>' . ( $link ? '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $linktext ) . '</a>' : '' ) . '</div>';
		};
		$m24dot = 'aktiv' === $st ? 'ok' : ( 'verkauft' === $st ? 'bad' : 'warn' );
		$h  = '<div class="pf-status">';
		$h .= $row( $m24dot, 'm24', ucfirst( $st ) . ( $pf ? ' · ' . $pf : '' ), 'publish' === get_post_status( $id ) ? get_permalink( $id ) : '' );
		if ( $s14 || $s14u ) {
			$h .= $row( 'ok', 's14', ( $s14s ? 'synchron ' . $zeit( $s14s ) : 'online (importiert)' ), $s14u );
		} else {
			$h .= $row( '', 's14', $pub_s14 ? 'wird beim Speichern angelegt' : 'nicht veröffentlicht', '' );
		}
		if ( $kaid ) {
			$h .= $row( $kaoffen ? 'warn' : 'ok', 'Kleinanzeigen', $kaoffen ? 'Text älter – wartet auf Erweiterung' : ( $kas ? 'synchron ' . $zeit( $kas ) : 'online' ), $kau );
		} else {
			$h .= $row( $pub_ka ? 'warn' : '', 'Kleinanzeigen', $pub_ka ? 'freigegeben – noch keine Anzeigen-ID' : 'nicht veröffentlicht', '' );
		}
		$h .= '</div>';
		return $h;
	}

	public static function render( WP_Post $post ) {
		$id = (int) $post->ID;
		wp_nonce_field( 'm24_plattformen_' . $id, 'm24_plattformen_nonce' );
		echo self::status_html( $id );

		$pub_s14 = '1' === (string) self::m( $id, '_m24_pub_s14', get_post_meta( $id, '_m24_s14_id', true ) ? '1' : '0' );
		$pub_ka  = '1' === (string) self::m( $id, '_m24_kleinanzeigen', '0' );
		echo '<h4>Auch veröffentlichen auf</h4>';
		echo '<label class="pf-cb"><input type="checkbox" name="m24_pub_s14" value="1" ' . checked( $pub_s14, true, false ) . '> s14.de <span style="color:#646970">(BMW E-Baureihen)</span></label>';
		echo '<label class="pf-cb"><input type="checkbox" name="m24_pub_ka" value="1" ' . checked( $pub_ka, true, false ) . '> Kleinanzeigen <span style="color:#646970">(Standardtext kommt dazu)</span></label>';
		echo '<div class="pf-kaid"><label for="m24_ka_id" style="font-size:12px">KA-Anzeige-ID</label><input type="text" id="m24_ka_id" name="m24_ka_id" value="' . esc_attr( (string) self::m( $id, '_m24_ka_id' ) ) . '" placeholder="z. B. 3555358742" inputmode="numeric"></div>';

		$ok = self::s14_token() !== '';
		echo '<div class="pf-btns"><button type="button" class="button" data-pf-send="' . $id . '" data-nonce="' . esc_attr( wp_create_nonce( 'm24_plattform_' . $id ) ) . '" ' . ( $ok ? '' : 'disabled title="M24_S14_TOKEN fehlt in der wp-config.php"' ) . '>Jetzt an s14.de senden</button></div>';
		echo '<div class="pf-meld">' . ( $ok ? 'Speichern überträgt Änderungen automatisch.' : 'M24_S14_TOKEN fehlt in der wp-config.php – Sync zu s14 inaktiv.' ) . '</div>';

		$log = get_post_meta( $id, '_m24_sync_log', true );
		if ( is_array( $log ) && $log ) {
			echo '<div class="pf-log">';
			foreach ( $log as $e ) { echo esc_html( date_i18n( 'd.m. H:i', (int) $e['t'] ) . ' · ' . $e['x'] ) . '<br>'; }
			echo '</div>';
		}
	}

	/* ── Speichern ─────────────────────────────────────────────────────────── */

	public static function save( int $post_id, WP_Post $post ) {
		if ( self::$sperre ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( wp_is_post_revision( $post_id ) || 'trash' === $post->post_status ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		// Formularwerte (nur wenn die Box auf dem Bildschirm war)
		if ( isset( $_POST['m24_plattformen_nonce'] ) && wp_verify_nonce( (string) $_POST['m24_plattformen_nonce'], 'm24_plattformen_' . $post_id ) ) {
			update_post_meta( $post_id, '_m24_pub_s14', empty( $_POST['m24_pub_s14'] ) ? '0' : '1' );
			update_post_meta( $post_id, '_m24_kleinanzeigen', empty( $_POST['m24_pub_ka'] ) ? '0' : '1' );
			$kaid = preg_replace( '/\D+/', '', (string) ( $_POST['m24_ka_id'] ?? '' ) );
			$alt  = (string) get_post_meta( $post_id, '_m24_ka_id', true );
			if ( $kaid !== $alt ) {
				update_post_meta( $post_id, '_m24_ka_id', $kaid );
				update_post_meta( $post_id, '_m24_ka_url', $kaid ? 'https://www.kleinanzeigen.de/s-anzeige/' . $kaid : '' );
				self::log( $post_id, $kaid ? 'Kleinanzeigen-ID ' . $kaid . ' eingetragen' : 'Kleinanzeigen-ID entfernt' );
			}
		}
		self::verteilen( $post_id, 'speichern' );
	}

	/** Statuswechsel über die Inline-Liste/Adminbar (set_status → update_post_meta) sofort weitergeben. */
	public static function meta_geaendert( $meta_id, $post_id, $key, $value ) {
		if ( '_m24_status' !== $key || self::$sperre ) { return; }
		if ( self::PT !== get_post_type( (int) $post_id ) ) { return; }
		if ( did_action( 'save_post_' . self::PT ) ) { return; }   // der Editor-Save verteilt selbst
		self::verteilen( (int) $post_id, 'status' );
	}

	/** Änderung erkannt? → an s14 schicken, Kleinanzeigen vormerken. */
	public static function verteilen( int $id, string $anlass ): void {
		if ( 'publish' !== get_post_status( $id ) && 'draft' !== get_post_status( $id ) ) { return; }
		$n = self::nutzlast( $id );
		$h = self::hash( $n );
		if ( $h === (string) get_post_meta( $id, '_m24_sync_hash', true ) && 'manuell' !== $anlass ) { return; }
		update_post_meta( $id, '_m24_sync_hash', $h );

		if ( '1' === (string) self::m( $id, '_m24_pub_s14', '0' ) || get_post_meta( $id, '_m24_s14_id', true ) ) {
			self::an_s14( $id, $n );
		}
		if ( '1' === (string) self::m( $id, '_m24_kleinanzeigen', '0' ) && get_post_meta( $id, '_m24_ka_id', true ) ) {
			update_post_meta( $id, '_m24_ka_offen', '1' );
			self::log( $id, 'Kleinanzeigen: Änderung vorgemerkt (' . $anlass . ')' );
		}
	}

	public static function an_s14( int $id, ?array $n = null ): array {
		if ( '' === self::s14_token() ) { return array( 'ok' => false, 'meldung' => 'M24_S14_TOKEN fehlt in der wp-config.php.' ); }
		$n = $n ?: self::nutzlast( $id );
		$n['s14_id'] = (int) self::m( $id, '_m24_s14_id', 0 );
		$res = wp_remote_post( self::s14_basis() . '/wp-json/s14/v1/m24/teil', array(
			'timeout' => 25,
			'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => self::s14_auth() ),
			'body'    => wp_json_encode( $n ),
		) );
		if ( is_wp_error( $res ) ) {
			self::log( $id, 's14 nicht erreichbar: ' . $res->get_error_message() );
			return array( 'ok' => false, 'meldung' => 'Verbindung zu s14 fehlgeschlagen: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['ok'] ) ) {
			$m = is_array( $body ) && ! empty( $body['meldung'] ) ? (string) $body['meldung'] : ( 401 === $code ? 'Token abgelehnt (HTTP 401)' : 's14 antwortete mit HTTP ' . $code );
			self::log( $id, 's14-Fehler: ' . $m );
			return array( 'ok' => false, 'meldung' => $m, 'code' => $code );
		}
		if ( ! empty( $body['s14_id'] ) ) {
			update_post_meta( $id, '_m24_s14_id', (int) $body['s14_id'] );
			update_post_meta( $id, '_m24_s14_url', esc_url_raw( (string) ( $body['url'] ?? '' ) ) );
			update_post_meta( $id, '_m24_pub_s14', '1' );
			if ( ! empty( $body['url'] ) ) { update_post_meta( $id, '_m24_s14_link', array( 't' => time(), 'u' => (string) $body['url'] ) ); }
		}
		update_post_meta( $id, '_m24_s14_stand', current_time( 'mysql' ) );
		self::log( $id, 's14: ' . (string) ( $body['aktion'] ?? 'aktualisiert' ) . ( ! empty( $body['hinweis'] ) ? ' – ' . $body['hinweis'] : '' ) );
		return array( 'ok' => true, 'meldung' => 'An s14.de übertragen (' . (string) ( $body['aktion'] ?? 'aktualisiert' ) . ').', 's14_id' => (int) ( $body['s14_id'] ?? 0 ), 'url' => (string) ( $body['url'] ?? '' ) );
	}

	public static function ajax_senden() {
		$id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $id || ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'm24_plattform_' . $id ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'meldung' => 'Keine Berechtigung.' ), 403 );
		}
		$r = self::an_s14( $id );
		$r['html'] = self::status_html( $id );
		$r['ok'] ? wp_send_json_success( $r ) : wp_send_json_error( $r );
	}

	/* ── REST: Rückwege ────────────────────────────────────────────────────── */

	public static function routes() {
		$darf = function () { return current_user_can( 'edit_posts' ); };
		register_rest_route( self::NS, '/teil-sync', array( 'methods' => 'POST', 'permission_callback' => $darf, 'callback' => array( __CLASS__, 'rest_teil_sync' ) ) );
		register_rest_route( self::NS, '/ka-rueckmeldung', array( 'methods' => 'POST', 'permission_callback' => array( __CLASS__, 'feed_token_ok' ), 'callback' => array( __CLASS__, 'rest_ka_rueckmeldung' ) ) );
		register_rest_route( self::NS, '/ka-warteschlange', array( 'methods' => 'GET', 'permission_callback' => array( __CLASS__, 'feed_token_ok' ), 'callback' => array( __CLASS__, 'rest_ka_warteschlange' ) ) );
	}

	/** Erweiterung/Tools: Feed-Token (wie der Teile-Feed) oder eingeloggter Admin. */
	public static function feed_token_ok( WP_REST_Request $r ) {
		if ( current_user_can( 'manage_options' ) ) { return true; }
		$t = (string) $r->get_param( 'token' );
		$soll = (string) get_option( 'm24_teile_feed_token', '' );
		return '' !== $soll && hash_equals( $soll, $t );
	}

	/** s14 → m24: {m24_post_id | s14_id, titel?, beschreibung?, preis?, status?} */
	public static function rest_teil_sync( WP_REST_Request $r ) {
		$id = absint( $r['m24_post_id'] ?? 0 );
		if ( ! $id && ! empty( $r['s14_id'] ) ) {
			$p = get_posts( array( 'post_type' => self::PT, 'post_status' => array( 'publish', 'draft' ), 'meta_key' => '_m24_s14_id', 'meta_value' => absint( $r['s14_id'] ), 'fields' => 'ids', 'numberposts' => 1 ) );
			$id = $p ? (int) $p[0] : 0;
		}
		if ( ! $id && ! empty( $r['m24_url'] ) ) { $id = (int) url_to_postid( esc_url_raw( (string) $r['m24_url'] ) ); }
		if ( ! $id || self::PT !== get_post_type( $id ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'meldung' => 'Teil nicht gefunden.' ), 404 );
		}
		self::$sperre = true;
		$geaendert = array();
		if ( ! empty( $r['s14_id'] ) && ! get_post_meta( $id, '_m24_s14_id', true ) ) { update_post_meta( $id, '_m24_s14_id', absint( $r['s14_id'] ) ); }
		if ( ! empty( $r['s14_url'] ) ) { update_post_meta( $id, '_m24_s14_url', esc_url_raw( (string) $r['s14_url'] ) ); }

		$upd = array( 'ID' => $id );
		if ( isset( $r['titel'] ) && '' !== trim( (string) $r['titel'] ) && (string) $r['titel'] !== get_the_title( $id ) ) { $upd['post_title'] = sanitize_text_field( (string) $r['titel'] ); $geaendert[] = 'Titel'; }
		if ( count( $upd ) > 1 ) { wp_update_post( $upd ); }
		if ( isset( $r['beschreibung'] ) && (string) $r['beschreibung'] !== (string) get_post_meta( $id, '_m24_beschreibung_de', true ) ) {
			update_post_meta( $id, '_m24_beschreibung_de', wp_kses_post( (string) $r['beschreibung'] ) ); $geaendert[] = 'Text';
		}
		if ( isset( $r['preis'] ) && class_exists( 'M24_Catalog_Pricing' ) && method_exists( 'M24_Catalog_Pricing', 'set_brutto' ) ) {
			if ( M24_Catalog_Pricing::set_brutto( $id, (float) $r['preis'] ) ) { $geaendert[] = 'Preis ' . (int) $r['preis'] . ' €'; }
		} elseif ( isset( $r['preis'] ) ) {
			$geaendert[] = 'Preis (nicht übernommen – Preisoptionen nur im Editor)';
		}
		if ( isset( $r['status'] ) ) {
			$map = array( 'live' => 'aktiv', 'aktiv' => 'aktiv', 'reserviert' => 'aktiv', 'verkauft' => 'verkauft', 'ausgeblendet' => 'ausgeblendet' );
			$neu = $map[ (string) $r['status'] ] ?? null;
			if ( $neu && $neu !== self::status( $id ) && class_exists( 'M24_Catalog_Admin_List' ) ) { M24_Catalog_Admin_List::set_status( $id, $neu ); $geaendert[] = 'Status ' . $neu; }
			if ( 'reserviert' === (string) $r['status'] ) { update_post_meta( $id, '_m24_reserviert', '1' ); $geaendert[] = 'reserviert (Merker)'; }
			elseif ( isset( $map[ (string) $r['status'] ] ) ) { delete_post_meta( $id, '_m24_reserviert' ); }
		}
		update_post_meta( $id, '_m24_s14_stand', current_time( 'mysql' ) );
		update_post_meta( $id, '_m24_sync_hash', self::hash( self::nutzlast( $id ) ) );
		self::log( $id, 'von s14 übernommen: ' . ( $geaendert ? implode( ', ', $geaendert ) : 'keine Änderung' ) );
		// Kleinanzeigen bekommt die s14-Änderung ebenfalls
		if ( $geaendert && '1' === (string) self::m( $id, '_m24_kleinanzeigen', '0' ) && get_post_meta( $id, '_m24_ka_id', true ) ) { update_post_meta( $id, '_m24_ka_offen', '1' ); }
		self::$sperre = false;
		return new WP_REST_Response( array( 'ok' => true, 'geaendert' => $geaendert ), 200 );
	}

	/** Erweiterung → m24: {m24_id, ka_id, ka_url?, text_stand?} */
	public static function rest_ka_rueckmeldung( WP_REST_Request $r ) {
		$id = absint( $r['m24_id'] ?? 0 );
		if ( ! $id || self::PT !== get_post_type( $id ) ) { return new WP_REST_Response( array( 'ok' => false, 'meldung' => 'Teil nicht gefunden.' ), 404 ); }
		$kaid = preg_replace( '/\D+/', '', (string) ( $r['ka_id'] ?? '' ) );
		if ( $kaid ) {
			update_post_meta( $id, '_m24_ka_id', $kaid );
			update_post_meta( $id, '_m24_ka_url', esc_url_raw( (string) ( $r['ka_url'] ?: 'https://www.kleinanzeigen.de/s-anzeige/' . $kaid ) ) );
			update_post_meta( $id, '_m24_kleinanzeigen', '1' );
		}
		update_post_meta( $id, '_m24_ka_stand', current_time( 'mysql' ) );
		update_post_meta( $id, '_m24_ka_offen', '0' );
		self::log( $id, 'Kleinanzeigen: ' . ( $kaid ? 'Anzeige ' . $kaid . ' ' : '' ) . (string) ( $r['aktion'] ?? 'aktualisiert' ) );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** Erweiterung ← m24: Teile mit Kleinanzeigen-Freigabe, deren Anzeige älter ist als der Text. */
	public static function rest_ka_warteschlange( WP_REST_Request $r ) {
		$ids = get_posts( array( 'post_type' => self::PT, 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 50, 'meta_query' => array(
			array( 'key' => '_m24_kleinanzeigen', 'value' => '1' ),
			array( 'key' => '_m24_ka_offen', 'value' => '1' ),
		) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			$item = class_exists( 'M24_Catalog_Feed' ) ? M24_Catalog_Feed::item( $p ) : null;
			if ( ! $item ) { continue; }
			$item['ka_id']  = (string) get_post_meta( $id, '_m24_ka_id', true );
			$item['ka_url'] = (string) get_post_meta( $id, '_m24_ka_url', true );
			$item['status'] = self::status( $id );
			$out[] = $item;
		}
		return new WP_REST_Response( array( 'ok' => true, 'anzahl' => count( $out ), 'teile' => $out ), 200 );
	}
}

M24_Catalog_Plattformen::init();
