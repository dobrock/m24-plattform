<?php
/**
 * M24 „Meine Garage" — ETAPPE 3: per-Fahrzeug-Änderungs-Alerts.
 *
 * EIGENES Feature, getrennt von den modell-basierten Brevo-DOI-Fahrzeug-Alerts (Listen 3–24).
 * Quelle der Präferenz: usermeta _m24_garage_notify (Etappe 2), Toggle über REST /garage/notify.
 *
 * Trigger:
 *   1) Preisänderung  → Meta _m24fz_preis (alt≠neu) → Accounts mit pref price:true.
 *   2) Verkauft/Reserviert → Meta _m24_inserat_status Übergang → reserviert|verkauft → pref sold:true.
 *
 * SICHERHEIT: Sende-Flag m24_garage_alerts_enabled (Default AUS). Solange AUS: Pipeline läuft +
 * wertet aus + LOGGT „would-send" (Kontext „alerts"), sendet aber KEINE Mail. Erst nach §7-UWG-Opt-out
 * auf true. Jede Mail trägt einen „Benachrichtigungen verwalten"-Link (Opt-out). De-Dupe per Transient.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Garage_Alerts {

	const FLAG        = 'm24_garage_alerts_enabled';   // Sende-Flag, Default false
	const MASTER_META = '_m24_garage_notify_master';   // usermeta: '0' = Account hat alle Alerts aus
	const CTX         = 'alerts';                       // Logger-Kontext
	const DEDUPE_TTL  = 3600;                            // s — Doppelversand/Bulk-Sturm-Sperre

	/** pid => array( meta_key => alter Wert ) — vor dem Schreiben geschnappt. */
	private static $old = array();

	public static function init() {
		// Alte Werte VOR dem Schreiben schnappen (Filter läuft pre-update, gibt den Vorwert her).
		add_filter( 'update_post_metadata', array( __CLASS__, 'snapshot' ), 10, 4 );
		// Nach dem Schreiben auswerten.
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 4 );
	}

	public static function enabled(): bool {
		return (bool) get_option( self::FLAG, 0 );
	}

	/** pre-update: aktuellen (alten) Wert der beobachteten Keys für m24_fahrzeug merken. */
	public static function snapshot( $check, $object_id, $meta_key ) {
		if ( in_array( $meta_key, array( '_m24fz_preis', M24FZ_CPT::INSERAT_META ), true )
			&& 'm24_fahrzeug' === get_post_type( $object_id ) ) {
			self::$old[ (int) $object_id ][ $meta_key ] = get_post_meta( (int) $object_id, $meta_key, true );
		}
		return $check; // null → normaler Schreibpfad
	}

	public static function on_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		$pid = (int) $object_id;
		if ( 'm24_fahrzeug' !== get_post_type( $pid ) ) { return; }
		if ( '_m24fz_preis' === $meta_key ) {
			self::handle_price( $pid, $meta_value );
		} elseif ( M24FZ_CPT::INSERAT_META === $meta_key ) {
			self::handle_status( $pid, (string) $meta_value );
		}
	}

	private static function handle_price( int $pid, $new ) {
		if ( ! isset( self::$old[ $pid ]['_m24fz_preis'] ) ) { return; } // added (kein Vorwert) → keine Bestandssubs
		$old_n = (int) self::$old[ $pid ]['_m24fz_preis'];
		$new_n = (int) $new;
		if ( $old_n === $new_n || $new_n <= 0 ) { return; }
		self::dispatch( $pid, 'price', array( 'old' => $old_n, 'new' => $new_n ) );
	}

	private static function handle_status( int $pid, string $new ) {
		if ( ! isset( self::$old[ $pid ][ M24FZ_CPT::INSERAT_META ] ) ) { return; }
		$old = (string) self::$old[ $pid ][ M24FZ_CPT::INSERAT_META ];
		$new = in_array( $new, array( 'reserviert', 'verkauft' ), true ) ? $new : '';
		if ( $old === $new || '' === $new ) { return; } // nur Übergänge NACH reserviert/verkauft
		self::dispatch( $pid, 'sold', array( 'to' => $new ) );
	}

	/** Abonnenten ermitteln + je Account entscheiden (senden/would-send/skip) + loggen. */
	private static function dispatch( int $pid, string $type, array $data ) {
		$pref_key = ( 'price' === $type ) ? 'price' : 'sold';
		$users    = get_users( array( 'meta_key' => '_m24_garage_notify', 'fields' => array( 'ID', 'user_email' ) ) );
		$hash     = substr( md5( $type . '|' . wp_json_encode( $data ) ), 0, 12 );
		$sent = 0; $would = 0; $skip = 0;

		foreach ( $users as $u ) {
			$acc = (int) $u->ID;
			$pref = M24_Garage_Cart::notify_for( $acc, $pid );
			if ( empty( $pref[ $pref_key ] ) ) { continue; }
			$email = (string) $u->user_email;
			if ( ! is_email( $email ) ) { $skip++; continue; }

			// Master-Schalter des Accounts aus?
			if ( '0' === (string) get_user_meta( $acc, self::MASTER_META, true ) ) {
				self::log( 'skip:master_off', $pid, $acc, $type ); $skip++; continue;
			}

			// De-Dupe: gleiche Änderung nicht doppelt (Bulk-Edit-Sturm/Doppel-Fire).
			$dk = 'm24ga_' . $pid . '_' . $acc . '_' . $type . '_' . $hash;
			if ( get_transient( $dk ) ) { $skip++; continue; }
			set_transient( $dk, 1, self::DEDUPE_TTL );

			if ( ! self::enabled() ) {
				self::log( 'would-send', $pid, $acc, $type, $data );
				$would++;
				continue;
			}
			$ok = self::send( $email, $pid, $type, $data );
			self::log( $ok ? 'sent' : 'failed', $pid, $acc, $type, $data );
			if ( $ok ) { $sent++; } else { $skip++; }
		}
		self::log( 'dispatch:summary', $pid, 0, $type, array( 'sent' => $sent, 'would' => $would, 'skip' => $skip, 'flag' => self::enabled() ? 'on' : 'off' ) );
	}

	/** Baut [subject, html] der Alert-Mail (echtes Template) — geteilt von send() + Vorschau. */
	private static function build_mail( int $pid, string $type, array $data ): array {
		$title = get_the_title( $pid );
		$url   = (string) get_permalink( $pid );
		$manage = class_exists( 'M24_Garage_Cart' ) ? add_query_arg( 'm24tab', 'notify', M24_Garage_Cart::page_url() ) : home_url( '/' );

		if ( 'price' === $type ) {
			$old = self::fmt( (int) $data['old'] );
			$new = self::fmt( (int) $data['new'] );
			$subject = 'Preis geändert: ' . $title;
			$head    = 'Preis geändert';
			$body    = '<p style="margin:0 0 14px;">Der Preis für <strong>' . esc_html( $title ) . '</strong> hat sich geändert:</p>'
				. '<p style="margin:0 0 14px;font-size:17px;"><span style="color:#9aa3b0;text-decoration:line-through;">' . esc_html( $old ) . '</span> &nbsp;→&nbsp; <strong style="color:#14161a;">' . esc_html( $new ) . '</strong> <span style="color:#9aa3b0;font-size:13px;">(Brutto)</span></p>';
		} else {
			$label   = ( 'verkauft' === ( $data['to'] ?? '' ) ) ? 'verkauft' : 'reserviert';
			$subject = $title . ' ist jetzt ' . $label;
			$head    = 'Statusänderung';
			$body    = '<p style="margin:0 0 14px;"><strong>' . esc_html( $title ) . '</strong> ist jetzt <strong>' . esc_html( $label ) . '</strong>.</p>';
		}
		// Über dem Button: Mosaik der ersten 3 Galeriebilder (links groß, rechts 2 gestapelt).
		$body .= self::mosaic( $pid );
		$body .= '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1f74c4;color:#fff;text-decoration:none;font-weight:600;padding:12px 26px;border-radius:6px;font-size:15px;">Zum Fahrzeug</a></p>';

		// Kanonische Basis-Vorlage (blauer Header + weißes Logo); Opt-out-Link im Footer (§7 UWG).
		$manage_link = '<a href="' . esc_url( $manage ) . '" style="color:#1f74c4;text-decoration:underline;">Benachrichtigungen verwalten</a>';
		$html = function_exists( 'm24_mail_shell' )
			? m24_mail_shell( $head, $body, array( 'footer_extra' => $manage_link ) )
			: '<h1>' . esc_html( $head ) . '</h1>' . $body; // Fallback (Template nicht geladen)
		return array( $subject, $html );
	}

	/** @return bool wp_mail-Ergebnis (Brevo-Sender via m24fz_mail_from_email wie B2B/IL/Garage). */
	private static function send( string $email, int $pid, string $type, array $data ): bool {
		list( $subject, $html ) = self::build_mail( $pid, $type, $data );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::from_header(),
			'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
		);
		$err = '';
		$catch = function ( $e ) use ( &$err ) { if ( is_wp_error( $e ) ) { $err = $e->get_error_message(); } };
		add_action( 'wp_mail_failed', $catch );
		$ok = false;
		try { $ok = (bool) wp_mail( $email, $subject, $html, $headers ); }
		catch ( \Throwable $t ) { $err = 'exception: ' . $t->getMessage(); }
		remove_action( 'wp_mail_failed', $catch );
		return $ok && '' === $err;
	}

	/**
	 * Vorschau/Test-Versand einer Alert-Mail (Admin-Tool) — echtes build_mail() mit Dummy-Daten.
	 * $type: 'price' | 'sold'. Nutzt das neueste veröffentlichte Fahrzeug (echtes Mosaik), sonst 0.
	 */
	public static function preview_send( string $to, string $type ): bool {
		if ( ! is_email( $to ) ) { return false; }
		$q   = get_posts( array( 'post_type' => 'm24_fahrzeug', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
		$pid = ! empty( $q ) ? (int) $q[0] : 0;
		$data = ( 'price' === $type ) ? array( 'old' => 129000, 'new' => 119000 ) : array( 'to' => 'verkauft' );
		list( $subject, $html ) = self::build_mail( $pid, $type, $data );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::from_header(),
			'Reply-To: MOTORSPORT24 <service@motorsport24.de>',
		);
		return (bool) wp_mail( $to, '[TEST] ' . $subject, $html, $headers );
	}

	private static function fmt( int $v ): string {
		return class_exists( 'M24_Catalog_Pricing' ) ? M24_Catalog_Pricing::format( (float) $v ) : ( number_format( $v, 2, ',', '.' ) . ' €' );
	}

	private static function from_header(): string {
		$host = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) { $host = 'motorsport24.de'; }
		$email = apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host );
		return 'MOTORSPORT24 <' . $email . '>';
	}

	/**
	 * Bild-Mosaik der ersten 3 Galeriebilder (links groß, rechts 2 gestapelt) — E-Mail-tauglich
	 * (Tabellen, feste Breiten, absolute URLs). <3 Bilder → nur vorhandene; 0 → '' (kein Mosaik).
	 */
	private static function mosaic( int $pid ): string {
		$ids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $pid, '_m24fz_gal_aussen', true ) ) ) );
		if ( empty( $ids ) ) {
			$t = (int) get_post_thumbnail_id( $pid );
			if ( $t ) { $ids[] = $t; }
		}
		$ids = array_slice( $ids, 0, 3 );
		$u   = array();
		foreach ( $ids as $aid ) {
			$url = wp_get_attachment_image_url( $aid, 'large' );
			if ( $url ) { $u[] = $url; }
		}
		$n = count( $u );
		if ( 0 === $n ) { return ''; }
		$img = function ( $src, $w, $h ) {
			return '<img src="' . esc_url( $src ) . '" width="' . (int) $w . '" height="' . (int) $h . '" alt="" style="display:block;width:' . (int) $w . 'px;height:' . (int) $h . 'px;object-fit:cover;border-radius:6px;border:0;">';
		};
		if ( 1 === $n ) {
			return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;"><tr><td>' . $img( $u[0], 544, 300 ) . '</td></tr></table>';
		}
		$big   = $img( $u[0], 330, 246 );
		$right = ( $n >= 3 )
			? $img( $u[1], 198, 119 ) . '<div style="height:8px;line-height:8px;font-size:0;">&nbsp;</div>' . $img( $u[2], 198, 119 )
			: $img( $u[1], 198, 246 );
		return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;"><tr>'
			. '<td style="padding-right:8px;vertical-align:top;">' . $big . '</td>'
			. '<td style="vertical-align:top;">' . $right . '</td></tr></table>';
	}

	/** Sync-Log (Kontext „alerts"): Schritt + IDs + Änderungstyp, keine PII/Klartext-Mail. */
	private static function log( string $step, int $pid, int $acc, string $type, array $extra = array() ) {
		if ( ! class_exists( 'M24_Logger' ) ) { return; }
		$payload = array_merge( array( 'step' => $step, 'post_id' => $pid, 'account' => $acc, 'change' => $type ), $extra );
		if ( 'failed' === $step ) { M24_Logger::error( self::CTX, $step . ' (#' . $pid . ')', $payload ); }
		elseif ( 0 === strpos( $step, 'skip' ) ) { M24_Logger::info( self::CTX, $step . ' (#' . $pid . ')', $payload ); }
		else { M24_Logger::info( self::CTX, $step . ' (#' . $pid . ')', $payload ); }
	}
}
