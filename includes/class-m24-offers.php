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
	const VALID_DAYS  = 7; // v3: Angebots-Gültigkeit 7 Tage (Countdown in Liste/Mail/Kunden-Ansicht zieht daraus)

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
		// Phase 2: Desk-Push beim Senden (no-op ohne M24_DESK_API_TOKEN-Konstante).
		add_action( 'm24_offer_sent', array( __CLASS__, 'push_to_desk' ) );
		// Admin-Angebotsliste (Übersicht + Reopen-Links).
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
	}

	/* ── Admin-Angebotsliste ────────────────────────────────────────────── */

	public static function admin_menu() {
		add_submenu_page( 'm24-plattform', 'Angebote', 'Angebote', 'manage_options', 'm24-offers', array( __CLASS__, 'render_admin_list' ) );
	}

	/** Operator-Modal mit dem Kontext eines bestehenden Angebots vorbefüllt wieder öffnen (Re-Quote). */
	private static function reopen_url( $o ): string {
		$cust = json_decode( (string) $o->customer_json, true ) ?: array();
		$src  = json_decode( (string) $o->src_json, true ) ?: array();
		$args = array_filter( array(
			self::QV_NEW => 1,
			'from' => (int) $o->id, // Paket 1E: lädt Positionen/Lieferzeit/Steuer + Garagen-Nr. aus dem Angebot
			'email' => (string) ( $cust['email'] ?? '' ), 'name' => (string) ( $cust['name'] ?? '' ),
			'kundentyp' => (string) ( $cust['kundentyp'] ?? '' ), 'land' => (string) ( $cust['land'] ?? '' ),
			'modell' => (string) ( $src['src_modell'] ?? '' ), 'pid' => (string) ( $src['src_pid'] ?? '' ),
			'pillar' => (string) ( $src['src_pillar'] ?? '' ), 'lang' => (string) ( $src['src_lang'] ?? '' ),
			'url' => (string) ( $src['src_url'] ?? '' ),
		), static function ( $v ) { return '' !== $v && null !== $v && 0 !== $v; } );
		return add_query_arg( $args, home_url( '/' ) ); // add_query_arg kodiert die Werte selbst
	}

	public static function render_admin_list() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		global $wpdb; $t = self::table();
		$badges = array(
			'entwurf'    => array( 'Entwurf', '#8a929c' ),
			'offen'      => array( 'Offen', '#1f74c4' ),
			'angenommen' => array( 'Angenommen', '#9a6b25' ),
			'bezahlt'    => array( 'Bezahlt/Bestätigt', '#1a7f37' ),
			'versandt'   => array( 'Versandt', '#1f74c4' ),
			'storniert'  => array( 'Storniert', '#6b7280' ),
			'abgelaufen' => array( 'Abgelaufen', '#c8102e' ),
		);
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Zeilen-Aktion (Stornieren/Löschen/Reaktivieren) — nur Admin, nonce-geschützt. Idempotent → Refresh unschädlich.
		$notice = '';
		if ( isset( $_GET['m24off_do'], $_GET['id'] ) ) {
			$do = sanitize_key( wp_unslash( $_GET['m24off_do'] ) );
			$id = (int) $_GET['id'];
			if ( $id > 0 && in_array( $do, array( 'storno', 'delete', 'reactivate', 'paid' ), true ) && check_admin_referer( 'm24off_do_' . $id ) ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT offer_no FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
				$no  = $row ? (string) $row->offer_no : (string) $id;
				if ( 'delete' === $do ) {
					$wpdb->delete( $t, array( 'id' => $id ) );
					self::log( 'deleted', $id, $no );
					$notice = 'Angebot ' . $no . ' gelöscht.';
				} elseif ( 'storno' === $do ) {
					$wpdb->update( $t, array( 'status' => 'storniert' ), array( 'id' => $id ) );
					self::log( 'cancelled', $id, $no );
					$notice = 'Angebot ' . $no . ' storniert (reversibel).';
				} elseif ( 'paid' === $do ) {
						self::mark_paid( $id, 'manual' );
						self::log( 'paid_manual', $id, $no );
						$notice = 'Angebot ' . $no . ' als bezahlt/bestätigt markiert.';
					} else {
					$wpdb->update( $t, array( 'status' => 'entwurf' ), array( 'id' => $id ) );
					self::log( 'reactivated', $id, $no );
					$notice = 'Angebot ' . $no . ' reaktiviert (Entwurf).';
				}
			}
		}
		$f_st = isset( $_GET['st'] ) ? sanitize_key( wp_unslash( $_GET['st'] ) ) : '';            // phpcs:ignore WordPress.Security.NonceVerification
		$f_s  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';        // phpcs:ignore WordPress.Security.NonceVerification

		$where = array( '1=1' ); $args = array();
		if ( isset( $badges[ $f_st ] ) ) { $where[] = 'status = %s'; $args[] = $f_st; }
		if ( '' !== $f_s ) { $like = '%' . $wpdb->esc_like( $f_s ) . '%'; $where[] = '( offer_no LIKE %s OR customer_json LIKE %s )'; $args[] = $like; $args[] = $like; }
		$q    = 'SELECT * FROM ' . $t . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 300';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $q, $args ) ) : $wpdb->get_results( $q ); // phpcs:ignore WordPress.DB.PreparedSQL

				echo '<div class="wrap m24offl"><h1 class="wp-heading-inline">Angebote</h1> <a href="' . esc_url( add_query_arg( array( self::QV_NEW => 1 ), home_url( '/' ) ) ) . '" target="_blank" rel="noopener" class="page-title-action" style="background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;border:0;">+ Neues Angebot</a><hr class="wp-header-end">';
		if ( '' !== $notice ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>'; }
		$tax_lbl = array( 'b2b_de_19' => 'DE · 19 %', 'b2b_eu_net' => 'EU B2B · netto', 'b2c_eu_oss' => 'EU B2C · OSS', 'drittland_net' => 'Drittland · netto' );
		echo '<style>.m24offl .flt{display:flex;gap:10px;margin:14px 0 18px;flex-wrap:wrap;align-items:center}.m24offl .chip{padding:7px 14px;border-radius:999px;border:1.5px solid #e5e7eb;background:#fff;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:#111417}.m24offl .chip.on{background:#0e447e;border-color:#0e447e;color:#fff}.m24offl .srch{margin-left:auto;display:flex;gap:6px}.m24offl .srch input{height:34px;border:1.5px solid #e5e7eb;border-radius:8px;padding:0 12px;min-width:220px}.m24offl .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:14px;max-width:1000px;padding:16px 18px}.m24offl .crow{display:flex;align-items:center;gap:16px;flex-wrap:wrap}.m24offl .av{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;display:grid;place-items:center;font-weight:800;font-size:15px;flex:0 0 auto}.m24offl .who b{font-size:15px}.m24offl .who div{color:#6b7280;font-size:12.5px}.m24offl .meta{margin-left:auto;display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:flex-end}.m24offl .no{font-family:Saira Condensed,sans-serif;font-weight:700;color:#9a6b25;font-size:15px}.m24offl .tx{color:#6b7280;font-size:12px}.m24offl .sum{font-weight:800;font-size:16px}.m24offl .badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px;color:#fff}.m24offl .foot{display:flex;gap:14px;margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb;font-size:13px;flex-wrap:wrap}.m24offl .foot a{text-decoration:none}@media(max-width:700px){.m24offl .meta{width:100%;margin-left:58px}}</style>';
		$base = admin_url( 'admin.php?page=' . $page );
		$chip = function ( $key, $label ) use ( $f_st, $base, $f_s ) { return '<a class="chip' . ( $f_st === $key ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'st' => $key, 's' => $f_s ), $base ) ) . '">' . esc_html( $label ) . '</a>'; };
		echo '<div class="flt">' . $chip( '', 'Alle' );
		foreach ( array( 'offen', 'angenommen', 'bezahlt', 'storniert' ) as $k ) { echo $chip( $k, $badges[ $k ][0] ); }
		echo '<form class="srch" method="get"><input type="hidden" name="page" value="' . esc_attr( $page ) . '"><input type="hidden" name="st" value="' . esc_attr( $f_st ) . '"><input type="search" name="s" value="' . esc_attr( $f_s ) . '" placeholder="Nr., Name oder E-Mail"><button class="button">Suchen</button></form></div>';
		if ( empty( $rows ) ) { echo '<p>Keine Angebote' . ( ( '' !== $f_st || '' !== $f_s ) ? ' zum Filter' : '' ) . '.</p></div>'; return; }
		foreach ( (array) $rows as $o ) {
			$cust = json_decode( (string) $o->customer_json, true ) ?: array();
			$name = trim( (string) ( $cust['name'] ?? '' ) ); if ( '' === $name ) { $name = (string) ( $cust['email'] ?? '—' ); }
			$ini  = ''; foreach ( array_slice( array_values( array_filter( explode( ' ', $name ) ) ), 0, 2 ) as $w ) { $ini .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) ); }
			if ( '' === $ini ) { $ini = 'K'; }
			$stb   = isset( $badges[ $o->status ] ) ? $badges[ $o->status ] : array( ucfirst( (string) $o->status ), '#8a929c' );
			$items = json_decode( (string) $o->items_json, true ); $items = is_array( $items ) ? $items : array();
			$vu_ts = $o->valid_until ? strtotime( (string) $o->valid_until . ' 23:59:59' ) : 0;
			$days  = $vu_ts ? (int) ceil( ( $vu_ts - time() ) / DAY_IN_SECONDS ) : 0;
			$badge = $stb[0];
			if ( 'offen' === $o->status && $days > 0 ) { $badge .= ' · noch ' . $days . ' Tag' . ( 1 === $days ? '' : 'e' ); }
			$txl   = $tax_lbl[ (string) $o->tax_mode ] ?? '';
			$u_storno = wp_nonce_url( add_query_arg( array( 'm24off_do' => 'storno', 'id' => (int) $o->id ), $base ), 'm24off_do_' . (int) $o->id );
			$u_react  = wp_nonce_url( add_query_arg( array( 'm24off_do' => 'reactivate', 'id' => (int) $o->id ), $base ), 'm24off_do_' . (int) $o->id );
			$u_del    = wp_nonce_url( add_query_arg( array( 'm24off_do' => 'delete', 'id' => (int) $o->id ), $base ), 'm24off_do_' . (int) $o->id );
			$u_paid   = wp_nonce_url( add_query_arg( array( 'm24off_do' => 'paid', 'id' => (int) $o->id ), $base ), 'm24off_do_' . (int) $o->id );
			$cnt = count( $items );
			echo '<div class="card"><div class="crow"><div class="av">' . esc_html( $ini ) . '</div><div class="who"><b>' . esc_html( $name ) . '</b><div>' . esc_html( (string) ( $cust['email'] ?? '' ) ) . ' · ' . $cnt . ' Position' . ( 1 === $cnt ? '' : 'en' ) . '</div></div><div class="meta"><span class="no">' . esc_html( (string) $o->offer_no ) . '</span>' . ( '' !== $txl ? '<span class="tx">' . esc_html( $txl ) . '</span>' : '' ) . '<span class="badge" style="background:' . esc_attr( $stb[1] ) . ';">' . esc_html( $badge ) . '</span><span class="sum">' . esc_html( number_format( (float) $o->total_gross, 2, ',', '.' ) ) . '&nbsp;€</span></div></div>';
			echo '<div class="foot"><a href="' . esc_url( self::view_url( (string) $o->token ) ) . '" target="_blank" rel="noopener">Kunden-Ansicht</a><a href="' . esc_url( self::reopen_url( $o ) ) . '" target="_blank" rel="noopener">Operator öffnen</a>';
			if ( 'angenommen' === (string) $o->status ) { echo '<a href="' . esc_url( $u_paid ) . '" style="color:#1a7f37;font-weight:700;">Zahlung erhalten ✓</a>'; }
			if ( 'storniert' === (string) $o->status ) { echo '<a href="' . esc_url( $u_react ) . '">Reaktivieren</a>'; } else { echo '<a href="' . esc_url( $u_storno ) . '" style="color:#b45309;">Stornieren</a>'; }
			echo '<a href="' . esc_url( $u_del ) . '" style="color:#a00;margin-left:auto;" onclick="return confirm(\'Angebot ' . esc_js( (string) $o->offer_no ) . ' unwiderruflich löschen?\');">Löschen</a></div></div>';
		}
		echo '</div>';
	}

	/* ── Nummernkreis 2026-0042 ─────────────────────────────────────────── */

	private static function next_number(): string {
		$year = (int) ( function_exists( 'wp_date' ) ? wp_date( 'Y' ) : gmdate( 'Y' ) );
		$key  = 'm24_offer_seq_' . $year;
		// Start bei {Jahr}-1000 (wie Garagen-Nr.): Zähler mindestens auf 999 → nächste Nummer = 1000.
		$n    = max( 999, (int) get_option( $key, 0 ) ) + 1;
		update_option( $key, $n, false );
		return sprintf( '%d-%04d', $year, $n );
	}

	/** Nächste Angebots-Nr. NUR anzeigen (ohne Zähler zu erhöhen) — für die Operator-Vorschau. */
	public static function peek_number(): string {
		$year = (int) ( function_exists( 'wp_date' ) ? wp_date( 'Y' ) : gmdate( 'Y' ) );
		$n    = max( 999, (int) get_option( 'm24_offer_seq_' . $year, 0 ) ) + 1;
		return sprintf( '%d-%04d', $year, $n );
	}

	/* ── Steuer (MANUELL) — Modi als Vorlage, nicht auto-detektiert ─────── */

	public static function tax_modes(): array {
		return array(
			'b2b_eu_net'    => array( 'label' => 'B2B EU → netto (Reverse Charge, keine USt)', 'rate' => 0.0, 'note' => 'Innergemeinschaftliche Lieferung – Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge), keine deutsche USt.' ),
			'drittland_net' => array( 'label' => 'Drittland (B2B/B2C) → netto + Export/Zoll', 'rate' => 0.0, 'note' => 'Nettopreis (Ausfuhrlieferung). Einfuhrumsatzsteuer, Zölle und Einfuhrabgaben im Bestimmungsland trägt der Käufer.' ),
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
			$is25 = ! empty( $it['tax25a'] ) || ! empty( $it['st25a'] ); // st25a = Abwärtskompat Alt-Angebote
			if ( $is25 ) { $st25a += $line; } else { $net += $line; }
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
		// Phase 2: „bezahlt"-Rücksync vom Desk (Auth via Service-Token-Header, konstantezeit-Vergleich).
		register_rest_route( self::NS, '/offers/desk/paid', array(
			'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'handle_desk_paid' ),
		) );
		// Manueller Fallback-Schalter (Operator markiert bezahlt, falls der Desk-Sync ausbleibt).
		register_rest_route( self::NS, '/offers/mark-paid', array(
			'methods' => 'POST', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_mark_paid' ),
		) );
		// „Angebot annehmen" (Kunde, token-basiert): setzt Status offen → angenommen. Public + Nonce + Token.
		register_rest_route( self::NS, '/offers/accept', array(
			'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'handle_accept' ),
		) );
	}

	public static function handle_accept( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		$o = self::get_by_token( (string) $req->get_param( 'token' ) );
		if ( ! $o ) { return new WP_Error( 'm24off_nf', 'Angebot nicht gefunden.', array( 'status' => 404 ) ); }
		// Nur ein offenes Angebot annehmen (idempotent, wenn bereits angenommen). Zahlung bestätigt Daniel im Desk.
		if ( 'offen' === (string) $o->status ) {
			global $wpdb;
			$wpdb->update( self::table(), array( 'status' => 'angenommen' ), array( 'id' => (int) $o->id ) );
			self::log( 'accepted', (int) $o->id, (string) $o->offer_no );
			if ( class_exists( 'M24_Error_Log' ) ) {
				M24_Error_Log::capture( 'offer_accept', 'info', 'Angebot vom Kunden angenommen', array( 'offer_no' => (string) $o->offer_no ) );
			}
			self::notify_accept( $o );
			do_action( 'm24_offer_accepted', (int) $o->id );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Interne Benachrichtigung an den Betrieb, wenn ein Kunde ein Angebot annimmt (m24_mail_shell). */
	private static function notify_accept( $o ): void {
		$to   = (string) apply_filters( 'm24_offer_accept_notify_to', 'service@motorsport24.de' );
		$cust = json_decode( (string) $o->customer_json, true ) ?: array();
		$src  = json_decode( (string) $o->src_json, true ) ?: array();
		$gno  = (string) ( $src['garage_no'] ?? '' );
		if ( '' === $gno && (int) $o->account_id > 0 && class_exists( 'M24_Garage_Cart' ) ) { $gno = M24_Garage_Cart::garage_no( (int) $o->account_id, false ); }
		$sum    = number_format( (float) $o->total_gross, 2, ',', '.' ) . ' €';
		$reopen = self::reopen_url( $o );
		$view   = self::view_url( (string) $o->token );
		$inner  = '<p style="margin:0 0 14px;">Ein Kunde hat ein Angebot <strong>angenommen</strong>.</p>'
			. '<ul style="margin:0 0 16px;padding-left:18px;line-height:1.7;">'
			. '<li>Angebot: <strong>' . esc_html( (string) $o->offer_no ) . '</strong></li>'
			. '<li>Kunde: ' . esc_html( (string) ( $cust['name'] ?? '' ) ) . ' &lt;' . esc_html( (string) ( $cust['email'] ?? '' ) ) . '&gt;</li>'
			. ( '' !== $gno ? '<li>Garagen-Nr.: <strong>' . esc_html( $gno ) . '</strong></li>' : '' )
			. '<li>Summe: <strong>' . esc_html( $sum ) . '</strong></li>'
			. '</ul>'
			. '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( $reopen ) . '" style="display:inline-block;background:#1f74c4;color:#fff;text-decoration:none;font-weight:600;padding:12px 26px;border-radius:6px;font-size:15px;">Im Operator öffnen</a></p>'
			. '<p style="margin:0;color:#5a6474;font-size:13px;">Kunden-Ansicht: <a href="' . esc_url( $view ) . '" style="color:#1f74c4;">' . esc_html( (string) $o->offer_no ) . '</a>. Den Zahlungseingang bestätigst du im M24 Desk (Status → bezahlt).</p>';
		$html = function_exists( 'm24_mail_shell' )
			? m24_mail_shell( 'Angebot ' . $o->offer_no . ' angenommen', $inner, array( 'lang' => 'de' ) )
			: '<h1>Angebot ' . esc_html( (string) $o->offer_no ) . ' angenommen</h1>' . $inner;
		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: MOTORSPORT24 <service@motorsport24.de>' );
		wp_mail( $to, 'Angebot ' . $o->offer_no . ' wurde angenommen', $html, $headers );
	}

	/** Teile-Picker: nach Modell (m24_fahrzeugkat) + Kategorie + Freitext (Titel + Art.-Nr.). */
	public static function handle_parts_search( WP_REST_Request $req ) {
		$modell = sanitize_title( (string) $req->get_param( 'modell' ) );
		$cat    = sanitize_text_field( (string) $req->get_param( 'cat' ) ); // '', 'neu', 'gebraucht'
		$q      = sanitize_text_field( (string) $req->get_param( 'q' ) );

		$qn  = preg_replace( '/\D/', '', $q ); // C1: normalisierte Ziffern-Query
		$tax = ( '' !== $modell ) ? array( array( 'taxonomy' => 'm24_fahrzeugkat', 'field' => 'slug', 'terms' => $modell ) ) : array();
		$mq  = ( 'neu' === $cat || 'gebraucht' === $cat ) ? array( array( 'key' => '_m24_typ', 'value' => $cat ) ) : array();

		$mk = function ( $p, $match ) {
			$price = self::teil_price( (int) $p->ID );
			return array(
				'id'     => (int) $p->ID,
				'title'  => get_the_title( $p ),
				'art_nr' => (string) get_post_meta( $p->ID, '_m24_artikelnummer', true ),
				'bmw'    => (string) get_post_meta( $p->ID, '_m24_bmw_teilenummer', true ),
				'price'  => ( null !== $price ) ? $price : null,
				'tax25a' => self::is_tax25a( (int) $p->ID ),
				'thumb'  => (string) get_the_post_thumbnail_url( $p->ID, 'thumbnail' ),
				'match'  => $match, // 'partnum' → „Treffer nach BMW-Teilenummer", 'name' → Name/Art-Nr.
			);
		};

		$out = array(); $seen = array();
		// 1) Teilenummern-Pfad: ab ≥6 Ziffern gegen _m24_partnums (Substring auf normalisierte Nummern).
		if ( strlen( $qn ) >= 6 ) {
			$pmq   = array_merge( $mq, array( array( 'key' => '_m24_partnums', 'value' => $qn, 'compare' => 'LIKE' ) ) );
			$pargs = array( 'post_type' => 'm24_teil', 'post_status' => 'publish', 'posts_per_page' => 24, 'no_found_rows' => true, 'meta_query' => $pmq );
			if ( $tax ) { $pargs['tax_query'] = $tax; }
			foreach ( get_posts( $pargs ) as $p ) { $seen[ (int) $p->ID ] = 1; $out[] = $mk( $p, 'partnum' ); }
		}
		// 2) Name-/Art-Nr-Pfad (WP-Volltext 's'), Teilenummern-Treffer nicht doppeln.
		$sargs = array( 'post_type' => 'm24_teil', 'post_status' => 'publish', 'posts_per_page' => 24, 'no_found_rows' => true, 's' => $q );
		if ( $tax ) { $sargs['tax_query'] = $tax; }
		if ( $mq ) { $sargs['meta_query'] = $mq; }
		foreach ( get_posts( $sargs ) as $p ) { if ( isset( $seen[ (int) $p->ID ] ) ) { continue; } $out[] = $mk( $p, 'name' ); }

		return rest_ensure_response( array( 'ok' => true, 'items' => $out, 'qnorm' => $qn ) );
	}

	private static function teil_price( int $pid ): ?float {
		if ( get_post_meta( $pid, '_m24_preis_auf_anfrage', true ) ) { return null; }
		if ( class_exists( 'M24_Catalog_Pricing' ) ) {
			$p = M24_Catalog_Pricing::get( $pid );
			return ( $p && ! empty( $p['brutto'] ) && (float) $p['brutto'] > 0 ) ? (float) $p['brutto'] : null;
		}
		return null;
	}

	/** §25a differenzbesteuert? Auto aus dem ECHTEN Steuermodus _m24_mwst_modus='paragraf25a' (nicht das
	 * veraltete _m24_differenzbesteuert). Filterbar. */
	private static function is_tax25a( int $pid ): bool {
		$is = ( 'paragraf25a' === (string) get_post_meta( $pid, '_m24_mwst_modus', true ) );
		return (bool) apply_filters( 'm24_offer_teil_tax25a', $is, $pid );
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
		$modes    = self::tax_modes();
		if ( ! isset( $modes[ $tax_mode ] ) ) {
			return new WP_Error( 'm24off_tax', 'Bitte einen gültigen Steuerfall wählen.', array( 'status' => 400 ) );
		}
		// OSS (B2C-EU): Satz ist Pflicht, 0–27 %. Andere Modi haben einen festen Satz (rate_for) → Eingabe ignoriert.
		$tax_rate = (float) ( $p['tax_rate'] ?? 0 );
		if ( 'b2c_eu_oss' === $tax_mode && ( $tax_rate < 0 || $tax_rate > 27 ) ) {
			return new WP_Error( 'm24off_oss', 'Bitte einen gültigen OSS-USt-Satz (0–27 %) angeben.', array( 'status' => 400 ) );
		}
		$delivery = sanitize_text_field( (string) ( $p['delivery_time'] ?? '' ) );
		$src      = self::clean_src( (array) ( $p['src'] ?? array() ) );
		$src['lang'] = ( isset( $p['lang'] ) && 'en' === $p['lang'] ) ? 'en' : 'de'; // Angebotssprache (Mail/Kunden-Ansicht/PDF)
		// v3: Anschreiben-Felder + globale Lieferzeit im src_json (Zeilenumbrüche im Freitext erhalten).
		$src['salutation'] = isset( $p['salutation'] ) ? sanitize_text_field( (string) $p['salutation'] ) : '';
		$src['note']       = isset( $p['note'] ) ? sanitize_textarea_field( (string) $p['note'] ) : '';
		$src['delivery']   = isset( $p['delivery_time'] ) ? sanitize_text_field( (string) $p['delivery_time'] ) : '';
		$totals   = self::compute_totals( $items, $extras, $tax_mode, $tax_rate );
		$tax_note = $modes[ $tax_mode ]['note'];

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

		// Paket H: stammt das Angebot aus einer Anfrage → diese als „Beantwortet → {Nr}" markieren (To-do-Liste).
		$inquiry_id = (int) ( $p['inquiry_id'] ?? 0 );
		if ( $inquiry_id > 0 && class_exists( 'M24_Inquiries_Storage' ) ) {
			M24_Inquiries_Storage::mark_answered( $inquiry_id, $offer_no, $token );
		}

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
			$teil_id = (int) ( $it['teil_id'] ?? 0 );
			$meta    = self::teil_offer_meta( $teil_id ); // url|null / race / race_note / used / tax25a (aus dem Teil geerbt)
			// §25a: Operator-Auswahl im Modal ist maßgeblich (kann die Auto-Erkennung übersteuern). Nur wenn der
			// Payload gar kein §25a-Feld trägt (Alt-Angebot/Re-Quote) → geerbte Auto-Erkennung.
			if ( array_key_exists( 'tax25a', $it ) )      { $tax25a = ! empty( $it['tax25a'] ); }
			elseif ( array_key_exists( 'st25a', $it ) )   { $tax25a = ! empty( $it['st25a'] ); } // Abwärtskompat
			else                                          { $tax25a = (bool) $meta['tax25a']; }
			$out[] = array(
				'teil_id'    => $teil_id,
				'title'      => $title,
				'art_nr'     => sanitize_text_field( (string) ( $it['art_nr'] ?? '' ) ),
				'variant'    => sanitize_text_field( (string) ( $it['variant'] ?? '' ) ), // #6: Varianten-Name im Angebot
				'qty'        => max( 1, (int) ( $it['qty'] ?? 1 ) ),
				'unit_price' => round( (float) ( $it['unit_price'] ?? 0 ), 2 ),
				'tax25a'     => $tax25a,            // Differenzbesteuerung (unabhängig von used)
				'custom'     => ! empty( $it['custom'] ), // Sonderanfertigung (§ 312g Abs. 2 – kein Widerruf)
				'url'        => $meta['url'],       // Artikel-Permalink (string) oder null (Freitext/gelöscht) → nicht klickbar
				'race'       => $meta['race'],      // Rennsport-Flag geerbt
				'race_note'  => $meta['race_note'], // exakter Wortlaut aus dem Artikel (1:1)
				'used'       => $meta['used'],      // Gebrauchtteil-Quelle
			);
		}
		return $out;
	}

	/**
	 * Item-Daten aus dem verknüpften Teil erben: Permalink, Rennsport-Hinweis (Flag + exakter Wortlaut wie
	 * das Detail-Template), Gebraucht-Erkennung. Ohne gültige Teil-ID → url=null, race/used=false.
	 * @return array{url:?string,race:bool,race_note:string,used:bool}
	 */
	private static function teil_offer_meta( int $pid ): array {
		$out = array( 'url' => null, 'race' => false, 'race_note' => '', 'used' => false, 'tax25a' => false );
		if ( $pid <= 0 || 'm24_teil' !== get_post_type( $pid ) ) { return $out; }

		if ( 'publish' === get_post_status( $pid ) ) {
			$url = (string) get_permalink( $pid );
			if ( '' !== $url ) { $out['url'] = $url; }
		}
		$typ = get_post_meta( $pid, '_m24_typ', true ) ?: 'gebraucht';
		$out['used']   = ( 'gebraucht' === $typ ); // Gebraucht-Quelle (unabhängig von §25a)
		$out['tax25a'] = self::is_tax25a( $pid );  // Differenzbesteuerung (unabhängig von used)

		// Rennsport-Hinweis 1:1 wie catalog-template-detail: Flag ODER typ='neu' → Standardtext, außer ein
		// eigener _m24_hinweis ist gesetzt (dann exakt dieser Wortlaut).
		$flag = (bool) (int) get_post_meta( $pid, '_m24_rennsport_hinweis', true );
		if ( $flag || 'neu' === $typ ) {
			$hinweis = trim( (string) get_post_meta( $pid, '_m24_hinweis', true ) );
			if ( '' === $hinweis && function_exists( 'm24_rennsport_hinweis' ) ) { $hinweis = (string) m24_rennsport_hinweis(); }
			if ( '' !== $hinweis ) { $out['race'] = true; $out['race_note'] = $hinweis; }
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
			array( 'key' => 'zoll',       'label' => 'Zollabwicklung Deutschland', 'amount' => (float) get_option( 'm24_offer_preset_zoll', 75 ) ),
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

	/**
	 * ALLE Angebote eines Kontos (jeder Status), neu→alt. Streng auf die eigene Konto-ID gefiltert; zusätzlich
	 * Gast-Angebote (account_id=0) mit exakt der eigenen E-Mail im customer_json (kein Fremdzugriff — es wird
	 * ausschließlich die E-Mail des eingeloggten Nutzers verwendet). @return array<int,array{...,token:string}>
	 */
	public static function all_for_account( int $account_id, string $email = '' ): array {
		if ( $account_id <= 0 ) { return array(); }
		global $wpdb;
		$t     = self::table();
		$email = strtolower( trim( $email ) );
		if ( '' !== $email && is_email( $email ) ) {
			$like = '%' . $wpdb->esc_like( '"email":"' . $email . '"' ) . '%';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT offer_no, token, total_gross, status, created_at, paid_at FROM $t WHERE account_id = %d OR ( account_id = 0 AND customer_json LIKE %s ) ORDER BY created_at DESC, id DESC LIMIT 200",
				$account_id, $like
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT offer_no, token, total_gross, status, created_at, paid_at FROM $t WHERE account_id = %d ORDER BY created_at DESC, id DESC LIMIT 200",
				$account_id
			) );
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'offer_no' => (string) $r->offer_no,
				'token'    => (string) $r->token,
				'total'    => (float) $r->total_gross,
				'status'   => (string) $r->status,
				'date'     => (string) ( $r->created_at ?: $r->paid_at ),
			);
		}
		return $out;
	}

	/**
	 * Bestellhistorie eines Kontos: bezahlte/versandte Angebote (rein WP-seitig, KEIN Desk-Call).
	 * @return array<int,array{offer_no:string,total:float,date:string,count:int,status:string}>
	 */
	public static function orders_for_account( int $account_id ): array {
		if ( $account_id <= 0 ) { return array(); }
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT offer_no, total_gross, status, created_at, paid_at, items_json FROM ' . self::table()
			. " WHERE account_id = %d AND status IN ('bezahlt','versandt') ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 100",
			$account_id
		) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$items = json_decode( (string) $r->items_json, true );
			$out[] = array(
				'offer_no' => (string) $r->offer_no,
				'total'    => (float) $r->total_gross,
				'date'     => (string) ( $r->paid_at ?: $r->created_at ),
				'count'    => is_array( $items ) ? count( $items ) : 0,
				'status'   => (string) $r->status,
			);
		}
		return $out;
	}

	/* ── 5-Tage-Ablauf (Cron, ohne Stunden) ─────────────────────────────── */

	/**
	 * Paket 1E: Angebots-ENTWURF aus einer geteilten Garage anlegen (Kunde fragt an; Daniel ergänzt Lieferzeit/
	 * Nebenkosten + sendet). $snap_items = Garage-Snapshot-Shape (article_id/title/art_nr/qty/price_gross).
	 * @return array{ok:bool,offer_no?:string,offer_id?:int,token?:string,error?:string}
	 */
	public static function create_draft( array $snap_items, array $customer, array $meta = array() ): array {
		global $wpdb;
		$mapped = array();
		foreach ( $snap_items as $it ) {
			$it    = (array) $it;
			$title = sanitize_text_field( (string) ( $it['title'] ?? '' ) );
			if ( '' === $title ) { continue; }
			$mapped[] = array(
				'teil_id'    => (int) ( $it['article_id'] ?? 0 ),
				'title'      => $title,
				'art_nr'     => (string) ( $it['art_nr'] ?? '' ),
				'qty'        => max( 1, (int) ( $it['qty'] ?? 1 ) ),
				'unit_price' => ( isset( $it['price_gross'] ) && null !== $it['price_gross'] ) ? round( (float) $it['price_gross'], 2 ) : 0.0,
			);
		}
		$items = self::clean_items( $mapped ); // erbt url/race/used/tax25a aus dem verknüpften Teil
		$cust  = self::clean_customer( $customer );
		if ( empty( $items ) || ! is_email( $cust['email'] ) ) {
			return array( 'ok' => false, 'error' => 'Ungültige Anfrage (Positionen/E-Mail).' );
		}
		$tax_mode = ( 'b2b' === $cust['kundentyp'] ) ? 'b2b_de_19' : 'b2c_eu_oss'; // Default; Daniel finalisiert beim Senden
		$totals   = self::compute_totals( $items, array(), $tax_mode, 0.0 );
		$token    = bin2hex( random_bytes( 16 ) );
		$offer_no = self::next_number();
		$account  = self::account_for_email( $cust['email'] );
		$src = array(
			'src_url'    => esc_url_raw( (string) ( $meta['garage_url'] ?? '' ) ),
			'src_pillar' => 'garage_share',
			'src_modell' => '', // echtes Modell unbekannt bei Garage-Anfrage (Picker-Filter nicht verfälschen)
			'src_pid'    => sanitize_text_field( (string) ( $meta['garage_token'] ?? '' ) ),
			'src_lang'   => '',
			'garage_no'  => sanitize_text_field( (string) ( $meta['garage_no'] ?? '' ) ), // referenzierbar im Operator
			'message'    => sanitize_textarea_field( (string) ( $meta['message'] ?? '' ) ),
		);
		$wpdb->insert( self::table(), array(
			'offer_no'     => $offer_no,
			'token'        => $token,
			'account_id'   => $account,
			'status'       => 'entwurf', // Entwurf — Daniel ergänzt + sendet (bestehender 5-Tage-Ablauf beim Senden)
			'customer_json'=> wp_json_encode( $cust ),
			'items_json'   => wp_json_encode( $items ),
			'extras_json'  => wp_json_encode( array() ),
			'delivery_time'=> '',
			'tax_mode'     => $tax_mode,
			'tax_rate'     => self::rate_for( $tax_mode, 0.0 ),
			'tax_note'     => self::tax_modes()[ $tax_mode ]['note'],
			'subtotal_net' => $totals['net'] + $totals['st25a'],
			'tax_amount'   => $totals['tax'],
			'total_gross'  => $totals['total'],
			'currency'     => 'EUR',
			'valid_until'  => null,
			'src_json'     => wp_json_encode( $src ),
			'sent_at'      => null,
		) );
		$offer_id = (int) $wpdb->insert_id;
		self::log( 'draft_request', $offer_id, $offer_no );
		return array( 'ok' => true, 'offer_no' => $offer_no, 'offer_id' => $offer_id, 'token' => $token );
	}

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

	/* ── Phase 2: Desk-Push (POST /api/orders) ──────────────────────────── */

	/** Auftrag beim Senden ans M24-Desk pushen. NUR wenn M24_DESK_API_TOKEN (wp-config) gesetzt — kein DB-
	 * Fallback; sonst no-op + Log (Kontext offers/desk_push). Reuse des bewährten M24_Rest_Client. */
	public static function push_to_desk( $offer_id ) {
		$offer_id = (int) $offer_id;
		$o = self::get_by_id( $offer_id );
		if ( ! $o ) { return; }
		$has_token = defined( 'M24_DESK_API_TOKEN' ) && '' !== (string) M24_DESK_API_TOKEN;
		if ( ! $has_token || ! class_exists( 'M24_Rest_Client' ) ) {
			self::log( 'desk_push:skipped_no_token', $offer_id, (string) $o->offer_no );
			return;
		}
		$res = M24_Rest_Client::push_order( self::build_desk_payload( $o ) );
		$ok  = is_array( $res ) && ! empty( $res['ok'] );
		$desk_id = '';
		if ( is_array( $res ) && isset( $res['body'] ) ) {
			$b = is_array( $res['body'] ) ? $res['body'] : json_decode( (string) $res['body'], true );
			if ( is_array( $b ) ) { $desk_id = (string) ( $b['order_id'] ?? $b['id'] ?? $b['desk_order_id'] ?? '' ); }
		}
		if ( '' !== $desk_id ) {
			global $wpdb;
			$wpdb->update( self::table(), array( 'desk_order_id' => $desk_id ), array( 'id' => $offer_id ) );
		}
		self::log( $ok ? 'desk_push:ok' : 'desk_push:failed', $offer_id, (string) $o->offer_no );
		if ( ! $ok && class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'desk_push', 'error', 'Desk-Push /api/orders fehlgeschlagen', array(
				'offer_no' => (string) $o->offer_no, 'status' => is_array( $res ) ? (int) ( $res['status'] ?? 0 ) : 0,
			) );
		}
	}

	/** Desk-Payload „Pfad B" (Schema wie inquiries-m24-push): customer + items (name/qty/vk/src_*) + offer. */
	private static function build_desk_payload( $o ): array {
		$cust   = json_decode( (string) $o->customer_json, true ) ?: array();
		$items  = json_decode( (string) $o->items_json, true ) ?: array();
		$extras = json_decode( (string) $o->extras_json, true ) ?: array();
		$src    = json_decode( (string) $o->src_json, true ) ?: array();
		$lang   = '' !== (string) ( $src['src_lang'] ?? '' ) ? (string) $src['src_lang'] : 'de';

		$mapped = array();
		foreach ( $items as $it ) {
			$mapped[] = array(
				'name' => (string) $it['title'], 'qty' => max( 1, (int) $it['qty'] ), 'ek' => 0,
				'vk' => (float) $it['unit_price'],
				'src_url' => (string) ( $src['src_url'] ?? '' ), 'src_pillar' => (string) ( $src['src_pillar'] ?? '' ),
				'src_modell' => (string) ( $src['src_modell'] ?? '' ), 'src_pid' => (string) ( $src['src_pid'] ?? '' ),
				'src_art_nr' => (string) ( $it['art_nr'] ?? '' ), 'src_variant' => '', 'src_lang' => $lang,
				'tax25a' => ( ! empty( $it['tax25a'] ) || ! empty( $it['st25a'] ) ),
				'permalink' => ( ! empty( $it['url'] ) ? (string) $it['url'] : null ),
				'race' => ! empty( $it['race'] ), 'used' => ! empty( $it['used'] ),
			);
		}
		foreach ( $extras as $ex ) {
			if ( empty( $ex['on'] ) ) { continue; }
			$mapped[] = array( 'name' => (string) $ex['label'], 'qty' => 1, 'ek' => 0, 'vk' => (float) $ex['amount'], 'src_pillar' => 'service', 'src_lang' => $lang );
		}
		return array(
			'source'              => 'wordpress_plugin',
			'inquiry_source'      => 'wordpress_plugin_offer',
			'subj'                => 'Angebot ' . (string) $o->offer_no,
			'sender_email'        => (string) ( $cust['email'] ?? '' ),
			'sender_lang'         => $lang,
			'country'             => (string) ( $cust['land'] ?? '' ),
			'inquiry_source_meta' => (object) array(
				'src_url' => (string) ( $src['src_url'] ?? '' ), 'src_pillar' => (string) ( $src['src_pillar'] ?? '' ),
				'src_modell' => (string) ( $src['src_modell'] ?? '' ), 'src_pid' => (string) ( $src['src_pid'] ?? '' ), 'src_lang' => $lang,
			),
			'customer'            => (object) array(
				'name' => (string) ( $cust['name'] ?? '' ), 'email' => (string) ( $cust['email'] ?? '' ),
				'kundentyp' => (string) ( $cust['kundentyp'] ?? 'b2c' ), 'firma' => (string) ( $cust['firma'] ?? '' ), 'land' => (string) ( $cust['land'] ?? '' ),
			),
			'items'               => $mapped,
			'offer'               => (object) array(
				'offer_no' => (string) $o->offer_no, 'token' => (string) $o->token,
				'subtotal_net' => (float) $o->subtotal_net, 'tax_amount' => (float) $o->tax_amount, 'total_gross' => (float) $o->total_gross,
				'tax_mode' => (string) $o->tax_mode, 'tax_rate' => (float) $o->tax_rate, 'tax_note' => (string) $o->tax_note,
				'currency' => (string) $o->currency, 'valid_until' => (string) $o->valid_until, 'delivery_time' => (string) $o->delivery_time,
			),
		);
	}

	/* ── Phase 2: „bezahlt"-Rücksync + manueller Fallback ───────────────── */

	public static function mark_paid( int $offer_id, string $source ): bool {
		global $wpdb;
		$o = self::get_by_id( $offer_id );
		if ( ! $o || 'bezahlt' === $o->status ) { return false; }
		$wpdb->update( self::table(), array( 'status' => 'bezahlt', 'paid_at' => current_time( 'mysql', true ) ), array( 'id' => $offer_id ) );
		self::log( 'paid:' . $source, $offer_id, (string) $o->offer_no );
		do_action( 'm24_offer_paid', $offer_id, $source );
		return true;
	}

	/** Inbound-Webhook vom Desk: Service-Token-Header konstantezeit gegen M24_DESK_API_TOKEN prüfen. */
	public static function handle_desk_paid( WP_REST_Request $req ) {
		$tok = defined( 'M24_DESK_API_TOKEN' ) ? (string) M24_DESK_API_TOKEN : '';
		$hdr = (string) ( $req->get_header( 'X-M24-Token' ) ?: $req->get_header( 'X-Service-Token' ) );
		if ( '' === $tok || '' === $hdr || ! hash_equals( $tok, $hdr ) ) {
			self::log( 'desk_paid:unauthorized' );
			return new WP_Error( 'm24off_auth', 'Nicht autorisiert.', array( 'status' => 401 ) );
		}
		$p = $req->get_json_params();
		global $wpdb;
		$o = null;
		if ( ! empty( $p['desk_order_id'] ) ) {
			$o = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE desk_order_id = %s LIMIT 1', sanitize_text_field( (string) $p['desk_order_id'] ) ) );
		}
		if ( ! $o && ! empty( $p['token'] ) ) { $o = self::get_by_token( (string) $p['token'] ); }
		if ( ! $o && ! empty( $p['offer_no'] ) ) {
			$o = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE offer_no = %s LIMIT 1', sanitize_text_field( (string) $p['offer_no'] ) ) );
		}
		if ( ! $o ) { return new WP_Error( 'm24off_nf', 'Angebot nicht gefunden.', array( 'status' => 404 ) ); }
		if ( ! array_key_exists( 'paid', $p ) || ! empty( $p['paid'] ) ) { self::mark_paid( (int) $o->id, 'desk' ); }
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Manueller Fallback-Schalter (Operator): markiert ein Angebot bezahlt. */
	public static function handle_mark_paid( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		$o = self::get_by_token( (string) $req->get_param( 'token' ) );
		if ( ! $o ) { return new WP_Error( 'm24off_nf', 'Angebot nicht gefunden.', array( 'status' => 404 ) ); }
		self::mark_paid( (int) $o->id, 'manual' );
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Als bezahlt markiert.' ) );
	}

	/* ── URLs ───────────────────────────────────────────────────────────── */

	public static function view_url( string $token ): string {
		return add_query_arg( self::QV_VIEW, rawurlencode( $token ), home_url( '/' ) );
	}
	/** Operator-Link (für die interne Anfrage-Mail): öffnet das Modal mit vorbefülltem Kontext. */
	public static function operator_mail_link( array $links, array $data ): array {
		$args = array( self::QV_NEW => 1 );
		// Liegt die Anfrage-ID vor → per ?from_inquiry laden (bringt die Positionen mit, wie die Inbox-Karte).
		// Sonst Fallback auf die Feld-Parameter (Operator ohne Positionen).
		if ( ! empty( $data['inquiry_id'] ) ) {
			$args['from_inquiry'] = (int) $data['inquiry_id'];
		} else {
			foreach ( array( 'email' => 'email', 'name' => 'name', 'kundentyp' => 'kundentyp', 'land' => 'land',
				'modell' => 'src_modell', 'pid' => 'src_pid', 'pillar' => 'src_pillar', 'lang' => 'src_lang' ) as $qk => $dk ) {
				if ( ! empty( $data[ $dk ] ) ) { $args[ $qk ] = (string) $data[ $dk ]; }
			}
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
