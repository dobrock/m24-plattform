<?php
/**
 * M24 Plattform — Anonymer 1-Wochen-Gast-Share (Paket G, Freigabe #4).
 *
 * Eine Gast-Garage (localStorage) lässt sich OHNE Konto als temporären Link teilen. Ablage in
 * m24_guest_share mit 7-Tage-TTL + Auto-Prune (Muster M24_Error_Log). Inhalt AUSSCHLIESSLICH
 * Item-IDs/Varianten/Mengen — KEINE personenbezogenen Daten. Share-Seite ist noindex und nach
 * Ablauf gelöscht (Zugriff auf abgelaufene/unbekannte Token → freundlicher Hinweis).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Guest_Share {

	const NS        = 'm24/v1';
	const QV        = 'm24_guest_share';
	const CRON      = 'm24_guest_share_prune';
	const TTL_DAYS  = 7;
	const MAX_ITEMS = 60;

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'm24_guest_share';
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 4 ); // vor der normalen Garage-Share-Ansicht
		add_action( self::CRON, array( __CLASS__, 'prune' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::CRON );
		}
	}

	public static function query_var( array $vars ): array {
		$vars[] = self::QV;
		return $vars;
	}

	public static function routes() {
		register_rest_route( self::NS, '/garage/guest-share', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // öffentlich: Gast ohne Konto; Inhalt ist reine Katalog-Referenz
			'callback'            => array( __CLASS__, 'handle_create' ),
		) );
	}

	public static function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		$t  = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token CHAR(32) NOT NULL,
			items_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY token (token),
			KEY expires_at (expires_at)
		) {$cc};" );
	}

	/** Abgelaufene Shares löschen (täglicher Cron + opportunistisch beim Anlegen). */
	public static function prune() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$t} WHERE expires_at < UTC_TIMESTAMP()" );
	}

	public static function share_url( string $token ): string {
		return add_query_arg( self::QV, $token, home_url( '/' ) );
	}

	/** POST /garage/guest-share — { items: [{id,vl,va,vb,q}] } → { ok, token, url, expires }. */
	public static function handle_create( WP_REST_Request $req ) {
		if ( ! class_exists( 'M24_Garage_Cart' ) ) {
			return new WP_Error( 'm24gs_unavailable', 'Garage nicht verfügbar.', array( 'status' => 500 ) );
		}
		self::prune(); // Gelegenheit nutzen, Altlasten wegräumen

		$raw = $req->get_param( 'items' );
		$raw = is_array( $raw ) ? $raw : array();
		$items = array();
		foreach ( $raw as $r ) {
			$r  = (array) $r;
			$id = (int) ( $r['id'] ?? 0 );
			if ( $id <= 0 || null === M24_Garage_Cart::item_display( $id, 1 ) ) { continue; } // nur veröffentlichte Katalog-Items
			$items[] = array(
				'id' => $id,
				'vl' => sanitize_text_field( (string) ( $r['vl'] ?? '' ) ),
				'va' => sanitize_text_field( (string) ( $r['va'] ?? '' ) ),
				'vb' => sanitize_text_field( (string) ( $r['vb'] ?? '' ) ),
				'q'  => max( 1, (int) ( $r['q'] ?? 1 ) ),
			);
			if ( count( $items ) >= self::MAX_ITEMS ) { break; }
		}
		if ( empty( $items ) ) {
			return new WP_Error( 'm24gs_empty', 'Keine gültigen Positionen zum Teilen.', array( 'status' => 400 ) );
		}

		self::ensure_table();
		global $wpdb;
		$token   = str_replace( '-', '', wp_generate_uuid4() ); // 32 hex-Zeichen, keine PII
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::TTL_DAYS * DAY_IN_SECONDS );
		$ok = $wpdb->insert( self::table(), array(
			'token'      => $token,
			'items_json' => wp_json_encode( $items ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
			'expires_at' => $expires,
		) );
		if ( ! $ok ) {
			return new WP_Error( 'm24gs_store', 'Teilen fehlgeschlagen.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'token' => $token, 'url' => self::share_url( $token ), 'expires_days' => self::TTL_DAYS ) );
	}

	/** template_redirect: ?m24_guest_share=TOKEN → eigenständige, noindex-Ansicht rendern (exit). */
	public static function maybe_render() {
		$token = get_query_var( self::QV );
		if ( '' === (string) $token && isset( $_GET[ self::QV ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$token = wp_unslash( $_GET[ self::QV ] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $token ) );
		if ( '' === $token ) { return; }

		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow', true );
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		while ( ob_get_level() > 0 ) { ob_end_clean(); }

		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT items_json, expires_at FROM {$t} WHERE token = %s", $token ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$expired = ( ! $row ) || ( strtotime( (string) $row['expires_at'] . ' UTC' ) < time() );

		echo self::render_html( $expired ? array() : (array) json_decode( (string) $row['items_json'], true ), $expired ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function render_html( array $items, bool $expired ): string {
		$rows = ''; $sum = 0.0; $unpriced = false; $n = 0;
		foreach ( $items as $it ) {
			$it = (array) $it;
			$d  = class_exists( 'M24_Garage_Cart' ) ? M24_Garage_Cart::item_display( (int) ( $it['id'] ?? 0 ), max( 1, (int) ( $it['q'] ?? 1 ) ) ) : null;
			if ( ! $d ) { continue; }
			$n++;
			$vl    = (string) ( $it['vl'] ?? '' );
			$artnr = ( '' !== (string) ( $it['va'] ?? '' ) ) ? (string) $it['va'] : (string) $d['artnr'];
			$vb    = ( '' !== (string) ( $it['vb'] ?? '' ) ) ? (float) str_replace( array( '.', ',' ), array( '', '.' ), (string) $it['vb'] ) : null;
			$unit  = ( null !== $vb && $vb > 0 ) ? $vb : ( null !== $d['unit'] ? (float) $d['unit'] : null );
			$qty   = max( 1, (int) ( $it['q'] ?? 1 ) );
			if ( null !== $unit ) { $sum += $unit * $qty; } else { $unpriced = true; }
			$price = ( null !== $unit ) ? number_format( $unit * $qty, 2, ',', '.' ) . ' €' : 'Preis auf Anfrage';
			$thumb = '' !== (string) $d['thumb'] ? '<img src="' . esc_url( (string) $d['thumb'] ) . '" alt="" loading="lazy">' : '<span class="ph"></span>';
			$meta  = array();
			if ( '' !== $vl ) { $meta[] = 'Variante: ' . esc_html( $vl ); }
			if ( '' !== $artnr ) { $meta[] = 'Art.-Nr.: ' . esc_html( $artnr ); }
			$rows .= '<a class="it" href="' . esc_url( (string) $d['url'] ) . '">' . $thumb
				. '<span class="ti"><span class="t">' . esc_html( (string) $d['title'] ) . '</span>'
				. ( $meta ? '<span class="m">' . implode( ' · ', $meta ) . '</span>' : '' ) . '</span>'
				. '<span class="q">×' . $qty . '</span><span class="p">' . esc_html( $price ) . '</span></a>';
		}
		$sum_fmt = number_format( $sum, 2, ',', '.' ) . ' €' . ( $unpriced ? ' *' : '' );
		$shop    = esc_url( home_url( '/' ) );
		$logo    = esc_url( apply_filters( 'm24fz_mail_logo_url', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2023/09/Logo-MOTORSPORT24.de_.gif' ) );

		$css = 'body{margin:0;background:#f2f4f7;font-family:Saira,Arial,sans-serif;color:#111417}'
			. '.wrap{max-width:640px;margin:0 auto;padding:0 16px 60px}'
			. '.hd{background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;padding:22px 20px;text-align:center;border-radius:0 0 14px 14px}'
			. '.hd img{height:34px;margin-bottom:8px}.hd h1{margin:0;font-size:20px;font-weight:800}.hd p{margin:4px 0 0;opacity:.9;font-size:13px}'
			. '.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;margin-top:18px;overflow:hidden}'
			. '.it{display:flex;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid #eef1f5;text-decoration:none;color:#111417}'
			. '.it img,.it .ph{width:60px;height:44px;object-fit:cover;border-radius:6px;background:#e5e7eb;flex:0 0 auto}'
			. '.it .ti{flex:1;min-width:0}.it .t{display:block;font-weight:600;font-size:14px;line-height:1.3}'
			. '.it .m{display:block;color:#6b7280;font-size:12px;margin-top:2px}.it .q{color:#5a6474;font-size:13px}'
			. '.it .p{font-weight:700;font-size:14px;white-space:nowrap}'
			. '.sum{display:flex;justify-content:space-between;padding:16px;font-size:15px}.sum b{font-size:18px}'
			. '.cta{display:block;margin:16px;text-align:center;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;text-decoration:none;font-weight:700;padding:13px;border-radius:10px}'
			. '.note{color:#8a929c;font-size:12px;text-align:center;margin:14px 0 0;line-height:1.5}'
			. '.empty{background:#fff;border:1px solid #e5e7eb;border-radius:14px;margin-top:18px;padding:28px 20px;text-align:center;color:#5a6474}';

		if ( $expired || 0 === $n ) {
			$body = '<div class="empty"><h2 style="margin:0 0 8px;font-size:18px;color:#111417;">Dieser Garage-Link ist abgelaufen</h2>'
				. '<p style="margin:0 0 16px;">Geteilte Gast-Garagen sind 7 Tage gültig. Stell deine Auswahl gern neu zusammen.</p>'
				. '<a class="cta" style="margin:0 auto;max-width:260px;" href="' . $shop . '">Zu MOTORSPORT24</a></div>';
		} else {
			$body = '<div class="card">' . $rows // phpcs:ignore WordPress.Security.EscapeOutput — Teile escaped
				. '<div class="sum"><span>Gesamt inkl. 19 % MwSt</span><b>' . esc_html( $sum_fmt ) . '</b></div>'
				. '<a class="cta" href="' . $shop . '">Zu MOTORSPORT24</a></div>'
				. '<p class="note">Temporär geteilte Gast-Garage · 7 Tage gültig · ohne Konto.' . ( $unpriced ? ' * Einzelne Positionen: Preis auf Anfrage.' : '' ) . '</p>';
		}

		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex, nofollow"><title>Geteilte Garage · MOTORSPORT24</title>'
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700;800&display=swap" rel="stylesheet">'
			. '<style>' . $css . '</style></head><body><div class="hd"><img src="' . $logo . '" alt="MOTORSPORT24"><h1>Geteilte Garage</h1><p>Eine Auswahl bei MOTORSPORT24</p></div>'
			. '<div class="wrap">' . $body . '</div></body></html>';
	}
}
