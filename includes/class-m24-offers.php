<?php
/**
 * M24 Angebots-Workflow v1 (Phase 1) — Angebot-Objekt + Operator-Modal A1 + Teile-Picker + manuelle
 * Steuer (Brutto/Netto/§25a) + Zusatz-Presets + Kunden-Ansicht + 5-Tage-Ablauf + Angebots-Mail (+ Konto-Link).
 *
 * FLAG m24_offers_enabled (Default AUS): solange aus, ist die gesamte Strecke inaktiv (Modal, REST, Cron,
 * Kunden-View, Mail-Link). Steuer wird NIE auto-erkannt — der Operator wählt Modus + Satz MANUELL.
 * Desk-Push (POST /api/orders, Service-Token M24_DESK_TOKEN) folgt in Phase 2; hier nur die no-op-Schnittstelle.
 *
 * Rechtlich (§145 BGB): verbindliches Angebot, Vertrag mit fristgerechtem Zahlungseingang; B2C-Widerruf nur
 * bei Privatkunden. Beträge feingranular als JSON + DECIMAL. Ausgaben esc_*, Queries $wpdb->prepare.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Offers {

	const FLAG        = 'm24_offers_enabled';
	const NS          = 'm24/v1';
	const QV_NEW      = 'm24_offer_new';   // Operator-Modal (Admin) ?m24_offer_new=1&…context
	const QV_VIEW     = 'm24_angebot';     // Kunden-Ansicht ?m24_angebot={token}
	const CRON        = 'm24_offers_expire';
	const VALID_DAYS  = 5;

	public static function enabled(): bool {
		return (bool) (int) get_option( self::FLAG, 0 );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'm24_offers';
	}

	public static function init() {
		// Cron-Registrierung + Ablauf immer harmlos (no-op ohne Angebote); der Rest ist flag-gated.
		add_action( self::CRON, array( __CLASS__, 'expire_due' ) );
		if ( ! wp_next_scheduled( self::CRON ) && self::enabled() ) {
			wp_schedule_event( time() + 3600, 'daily', self::CRON );
		}
		if ( ! self::enabled() ) { return; }

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_operator' ), 6 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_customer' ), 6 );
		// Operator-Link in die interne „Neue Anfrage"-Mail einhängen.
		add_filter( 'm24_inquiry_operator_links', array( __CLASS__, 'operator_mail_link' ), 10, 2 );
	}

	/* ── Nummernkreis 2026-0042 ─────────────────────────────────────────── */

	private static function next_number(): string {
		$year = (int) ( function_exists( 'wp_date' ) ? wp_date( 'Y' ) : gmdate( 'Y' ) );
		$key  = 'm24_offer_seq_' . $year;
		$n    = (int) get_option( $key, 0 ) + 1;
		update_option( $key, $n, false );
		return sprintf( '%d-%04d', $year, $n );
	}

	/* ── Steuer (MANUELL) — Modi als Vorlage, nicht auto-detektiert ─────── */

	public static function tax_modes(): array {
		return array(
			'b2b_eu_net'    => array( 'label' => 'B2B EU → netto (Reverse Charge, keine USt)', 'rate' => 0.0, 'note' => 'Innergemeinschaftliche Lieferung – Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge), keine deutsche USt.' ),
			'drittland_net' => array( 'label' => 'Drittland (B2B/B2C) → netto + Export/Zoll', 'rate' => 0.0, 'note' => 'Ausfuhrlieferung in ein Drittland – netto; Einfuhrumsatzsteuer/Zoll trägt der Empfänger (Zollabwicklung separat ausgewiesen).' ),
			'b2b_de_19'     => array( 'label' => 'B2B Deutschland → + 19 % MwSt (brutto)', 'rate' => 19.0, 'note' => 'zzgl. 19 % gesetzlicher MwSt.' ),
			'b2c_eu_oss'    => array( 'label' => 'Privat B2C EU → OSS-Satz Zielland (manuell)', 'rate' => null, 'note' => 'One-Stop-Shop: USt-Satz des Bestimmungslandes.' ),
		);
	}

	/**
	 * Summen berechnen. §25a-Positionen (differenzbesteuert) sind final ohne ausweisbare USt und aus der
	 * Steuerbasis ausgenommen; reguläre Positionen + aktive Zusatzpositionen bilden die Netto-Basis.
	 * @return array{net:float,st25a:float,tax:float,total:float}
	 */
	public static function compute_totals( array $items, array $extras, string $tax_mode, float $tax_rate ): array {
		$net = 0.0; $st25a = 0.0;
		foreach ( $items as $it ) {
			$line = (float) ( $it['unit_price'] ?? 0 ) * max( 1, (int) ( $it['qty'] ?? 1 ) );
			if ( ! empty( $it['st25a'] ) ) { $st25a += $line; } else { $net += $line; }
		}
		foreach ( $extras as $ex ) {
			if ( ! empty( $ex['on'] ) ) { $net += (float) ( $ex['amount'] ?? 0 ); }
		}
		$rate = self::rate_for( $tax_mode, $tax_rate );
		$tax  = round( $net * $rate / 100, 2 );
		return array(
			'net'   => round( $net, 2 ),
			'st25a' => round( $st25a, 2 ),
			'tax'   => $tax,
			'total' => round( $net + $tax + $st25a, 2 ),
		);
	}

	private static function rate_for( string $mode, float $manual ): float {
		$modes = self::tax_modes();
		if ( ! isset( $modes[ $mode ] ) ) { return 0.0; }
		$r = $modes[ $mode ]['rate'];
		return ( null === $r ) ? max( 0.0, $manual ) : (float) $r; // OSS: manueller Satz
	}

	/* ── REST ───────────────────────────────────────────────────────────── */

	public static function register_routes() {
		$admin = function () { return current_user_can( 'manage_options' ); };
		register_rest_route( self::NS, '/offers/parts', array(
			'methods' => 'GET', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_parts_search' ),
		) );
		register_rest_route( self::NS, '/offers/send', array(
			'methods' => 'POST', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_send' ),
		) );
	}

	/** Teile-Picker: nach Modell (m24_fahrzeugkat) + Kategorie + Freitext (Titel + Art.-Nr.). */
	public static function handle_parts_search( WP_REST_Request $req ) {
		$modell = sanitize_title( (string) $req->get_param( 'modell' ) );
		$cat    = sanitize_text_field( (string) $req->get_param( 'cat' ) ); // '', 'neu', 'gebraucht'
		$q      = sanitize_text_field( (string) $req->get_param( 'q' ) );

		$args = array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'publish',
			'posts_per_page' => 24,
			'no_found_rows'  => true,
			's'              => $q,
		);
		if ( '' !== $modell ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'm24_fahrzeugkat', 'field' => 'slug', 'terms' => $modell ) );
		}
		if ( 'neu' === $cat || 'gebraucht' === $cat ) {
			$args['meta_query'] = array( array( 'key' => '_m24_typ', 'value' => $cat ) );
		}
		$out = array();
		$posts = get_posts( $args );
		foreach ( $posts as $p ) {
			$price = self::teil_price( (int) $p->ID );
			$out[] = array(
				'id'     => (int) $p->ID,
				'title'  => get_the_title( $p ),
				'art_nr' => (string) get_post_meta( $p->ID, '_m24_artikelnummer', true ),
				'price'  => ( null !== $price ) ? $price : null,
				'st25a'  => self::is_st25a( (int) $p->ID ),
				'thumb'  => (string) get_the_post_thumbnail_url( $p->ID, 'thumbnail' ),
			);
		}
		return rest_ensure_response( array( 'ok' => true, 'items' => $out ) );
	}

	private static function teil_price( int $pid ): ?float {
		if ( get_post_meta( $pid, '_m24_preis_auf_anfrage', true ) ) { return null; }
		if ( class_exists( 'M24_Catalog_Pricing' ) ) {
			$p = M24_Catalog_Pricing::get( $pid );
			return ( $p && ! empty( $p['brutto'] ) && (float) $p['brutto'] > 0 ) ? (float) $p['brutto'] : null;
		}
		return null;
	}

	/** §25a differenzbesteuert? Filterbar; Default aus Teil-Meta _m24_differenzbesteuert. */
	private static function is_st25a( int $pid ): bool {
		return (bool) apply_filters( 'm24_offer_teil_st25a', (bool) get_post_meta( $pid, '_m24_differenzbesteuert', true ), $pid );
	}

	/** Angebot anlegen + versenden. */
	public static function handle_send( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$p        = $req->get_json_params();
		$customer = self::clean_customer( (array) ( $p['customer'] ?? array() ) );
		$items    = self::clean_items( (array) ( $p['items'] ?? array() ) );
		$extras   = self::clean_extras( (array) ( $p['extras'] ?? array() ) );
		if ( empty( $items ) || ! is_email( $customer['email'] ) ) {
			return new WP_Error( 'm24off_bad', 'Mindestens eine Position und eine gültige Kunden-E-Mail nötig.', array( 'status' => 400 ) );
		}
		$tax_mode = (string) ( $p['tax_mode'] ?? '' );
		$tax_rate = (float) ( $p['tax_rate'] ?? 0 );
		$delivery = sanitize_text_field( (string) ( $p['delivery_time'] ?? '' ) );
		$src      = self::clean_src( (array) ( $p['src'] ?? array() ) );
		$totals   = self::compute_totals( $items, $extras, $tax_mode, $tax_rate );
		$modes    = self::tax_modes();
		$tax_note = isset( $modes[ $tax_mode ] ) ? $modes[ $tax_mode ]['note'] : '';

		$account_id = self::account_for_email( $customer['email'] );
		$token      = bin2hex( random_bytes( 16 ) );
		$offer_no   = self::next_number();
		$valid_dt   = gmdate( 'Y-m-d', time() + self::VALID_DAYS * DAY_IN_SECONDS );

		$wpdb->insert( self::table(), array(
			'offer_no'     => $offer_no,
			'token'        => $token,
			'account_id'   => $account_id,
			'status'       => 'offen',
			'customer_json'=> wp_json_encode( $customer ),
			'items_json'   => wp_json_encode( $items ),
			'extras_json'  => wp_json_encode( $extras ),
			'delivery_time'=> $delivery,
			'tax_mode'     => $tax_mode,
			'tax_rate'     => self::rate_for( $tax_mode, $tax_rate ),
			'tax_note'     => $tax_note,
			'subtotal_net' => $totals['net'] + $totals['st25a'],
			'tax_amount'   => $totals['tax'],
			'total_gross'  => $totals['total'],
			'currency'     => 'EUR',
			'valid_until'  => $valid_dt,
			'src_json'     => wp_json_encode( $src ),
			'sent_at'      => current_time( 'mysql', true ),
		) );
		$offer_id = (int) $wpdb->insert_id;
		self::log( 'sent', $offer_id, $offer_no );

		// Gast ohne Konto → Konto-Anlage-Bestätigungslink an die Register→Magic-Link-Strecke andocken.
		$register_link = false;
		if ( $account_id <= 0 && class_exists( 'M24_Login' ) ) {
			M24_Login::create_account_and_send_link( $customer['email'], $customer['name'] );
			$register_link = true;
		}
		self::send_offer_mail( $offer_id );

		// Desk-Push folgt in Phase 2 (interface-only, no-op ohne M24_DESK_TOKEN).
		do_action( 'm24_offer_sent', $offer_id );

		return rest_ensure_response( array(
			'ok' => true, 'offer_no' => $offer_no, 'token' => $token,
			'view_url' => self::view_url( $token ),
			'register_link' => $register_link,
			'message' => 'Angebot ' . $offer_no . ' gesendet.',
		) );
	}

	/* ── Sanitizer ──────────────────────────────────────────────────────── */

	private static function clean_customer( array $c ): array {
		return array(
			'name'      => sanitize_text_field( (string) ( $c['name'] ?? '' ) ),
			'email'     => strtolower( sanitize_email( (string) ( $c['email'] ?? '' ) ) ),
			'kundentyp' => in_array( ( $c['kundentyp'] ?? '' ), array( 'b2b', 'b2c' ), true ) ? $c['kundentyp'] : 'b2c',
			'firma'     => sanitize_text_field( (string) ( $c['firma'] ?? '' ) ),
			'land'      => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $c['land'] ?? '' ) ), 0, 2 ) ),
		);
	}
	private static function clean_items( array $items ): array {
		$out = array();
		foreach ( $items as $it ) {
			$title = sanitize_text_field( (string) ( $it['title'] ?? '' ) );
			if ( '' === $title ) { continue; }
			$out[] = array(
				'teil_id'    => (int) ( $it['teil_id'] ?? 0 ),
				'title'      => $title,
				'art_nr'     => sanitize_text_field( (string) ( $it['art_nr'] ?? '' ) ),
				'qty'        => max( 1, (int) ( $it['qty'] ?? 1 ) ),
				'unit_price' => round( (float) ( $it['unit_price'] ?? 0 ), 2 ),
				'st25a'      => ! empty( $it['st25a'] ),
			);
		}
		return $out;
	}
	private static function clean_extras( array $extras ): array {
		$out = array();
		foreach ( $extras as $ex ) {
			$label = sanitize_text_field( (string) ( $ex['label'] ?? '' ) );
			if ( '' === $label ) { continue; }
			$out[] = array( 'label' => $label, 'amount' => round( (float) ( $ex['amount'] ?? 0 ), 2 ), 'on' => ! empty( $ex['on'] ) );
		}
		return $out;
	}
	private static function clean_src( array $s ): array {
		return array(
			'src_url'    => esc_url_raw( (string) ( $s['src_url'] ?? '' ) ),
			'src_pillar' => sanitize_text_field( (string) ( $s['src_pillar'] ?? '' ) ),
			'src_modell' => sanitize_text_field( (string) ( $s['src_modell'] ?? '' ) ),
			'src_pid'    => sanitize_text_field( (string) ( $s['src_pid'] ?? '' ) ),
			'src_lang'   => sanitize_text_field( (string) ( $s['src_lang'] ?? '' ) ),
		);
	}
	private static function account_for_email( string $email ): int {
		$u = get_user_by( 'email', $email );
		return $u ? (int) $u->ID : 0;
	}

	/* ── Presets / Bank (Settings) ──────────────────────────────────────── */

	public static function extra_presets(): array {
		$def = array(
			array( 'key' => 'verpackung', 'label' => 'Verpackung', 'amount' => (float) get_option( 'm24_offer_preset_verpackung', 25 ) ),
			array( 'key' => 'versand',    'label' => 'Versand',    'amount' => (float) get_option( 'm24_offer_preset_versand', 49 ) ),
			array( 'key' => 'zoll',       'label' => 'Zollabwicklung (DE)', 'amount' => (float) get_option( 'm24_offer_preset_zoll', 75 ) ),
		);
		return apply_filters( 'm24_offer_extra_presets', $def );
	}
	private static function bank(): array {
		return apply_filters( 'm24_offer_bank', array(
			'inhaber' => 'MOTORSPORT24 GmbH', 'bank' => 'Commerzbank AG',
			'iban' => 'DE81 1204 0000 0133 3905 00', 'bic' => 'COBADEFFXXX',
		) );
	}

	/* ── Model-Zugriff ──────────────────────────────────────────────────── */

	public static function get_by_token( string $token ) {
		global $wpdb;
		$token = preg_replace( '/[^a-f0-9]/', '', $token );
		if ( '' === $token ) { return null; }
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s LIMIT 1', $token ) );
	}
	public static function get_by_id( int $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id ) );
	}

	/* ── 5-Tage-Ablauf (Cron, ohne Stunden) ─────────────────────────────── */

	public static function expire_due() {
		global $wpdb;
		$cut = gmdate( 'Y-m-d', time() - 1 ); // valid_until < heute → abgelaufen
		$wpdb->query( $wpdb->prepare(
			"UPDATE " . self::table() . " SET status = 'abgelaufen' WHERE status = 'offen' AND valid_until IS NOT NULL AND valid_until < %s",
			$cut
		) );
	}

	private static function log( string $step, int $id = 0, string $no = '' ) {
		if ( class_exists( 'M24_Logger' ) ) { M24_Logger::info( 'offers', $step, array( 'id' => $id, 'no' => $no ) ); }
	}

	/* ── URLs ───────────────────────────────────────────────────────────── */

	public static function view_url( string $token ): string {
		return add_query_arg( self::QV_VIEW, rawurlencode( $token ), home_url( '/' ) );
	}
	/** Operator-Link (für die interne Anfrage-Mail): öffnet das Modal mit vorbefülltem Kontext. */
	public static function operator_mail_link( array $links, array $data ): array {
		$args = array( self::QV_NEW => 1 );
		foreach ( array( 'email' => 'email', 'name' => 'name', 'kundentyp' => 'kundentyp', 'land' => 'land',
			'modell' => 'src_modell', 'pid' => 'src_pid', 'pillar' => 'src_pillar', 'lang' => 'src_lang' ) as $qk => $dk ) {
			if ( ! empty( $data[ $dk ] ) ) { $args[ $qk ] = (string) $data[ $dk ]; }
		}
		$links[] = array( 'label' => 'Angebot erstellen →', 'url' => add_query_arg( $args, home_url( '/' ) ) );
		return $links;
	}

	// Operator-Modal + Kunden-Ansicht + Angebots-Mail: in Teildatei ausgelagert, um diese Klasse schlank
	// zu halten (render + Mail sind reine Ausgabe). Eingebunden per require in init-Kontext.
	public static function maybe_render_operator() { M24_Offers_Render::operator(); }
	public static function maybe_render_customer() { M24_Offers_Render::customer(); }
	public static function send_offer_mail( int $offer_id ) { M24_Offers_Render::mail( $offer_id ); }
}
