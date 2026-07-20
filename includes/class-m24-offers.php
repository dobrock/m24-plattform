<?php
/**
 * M24 Angebots-Workflow v1 (Phase 1) — Angebot-Objekt + Operator-Modal A1 + Teile-Picker + manuelle
 * Steuer (Brutto/Netto/§25a) + Zusatz-Presets + Kunden-Ansicht + 7-Tage-Ablauf (VALID_DAYS) + Angebots-Mail (+ Konto-Link).
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
		add_action( self::CRON, array( __CLASS__, 'remind_due' ) ); // 2-Tage-Ablauf-Reminder (VOR expire, solange noch „offen")
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
		// Desk-Push beim Senden übernimmt jetzt das Modul core/desk-sync (M24_Desk_Push, Vertrag v1.1, W1).
		// Der alte no-op-Stub push_to_desk()/build_desk_payload() bleibt nur als Legacy im Code, ist aber
		// NICHT mehr an m24_offer_sent gehängt (kein Doppel-Push).
		if ( ! class_exists( 'M24_Desk_Push' ) ) {
			add_action( 'm24_offer_sent', array( __CLASS__, 'push_to_desk' ) ); // Fallback, falls Modul fehlt
		}
		// Admin-Angebotsliste (Übersicht + Reopen-Links).
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
		// NUR auf der Angebote-Seite: plugin-fremde Admin-Notices abräumen (WPBakery-„Security release", Core-Nags …).
		add_action( 'in_admin_header', array( __CLASS__, 'suppress_foreign_admin_notices' ) );
	}

	/* ── Admin-Angebotsliste ────────────────────────────────────────────── */

	public static function admin_menu() {
		add_submenu_page( 'm24-plattform', 'Angebote', 'Angebote', 'manage_options', 'm24-offers', array( __CLASS__, 'render_admin_list' ) );
	}

	/**
	 * Fremde Admin-Notices AUSSCHLIESSLICH auf admin.php?page=m24-offers unterdrücken. M24-eigene Meldungen
	 * (z. B. „Angebot … gelöscht") laufen NICHT über diese Hooks — render_admin_list echot sie inline in den
	 * Seiteninhalt → sie bleiben erhalten. Läuft vor der Notice-Ausgabe (in_admin_header) und nur auf dieser Seite.
	 */
	public static function suppress_foreign_admin_notices() {
		if ( ! is_admin() || empty( $_GET['page'] ) || 'm24-offers' !== $_GET['page'] ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
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
		$f_nv = isset( $_GET['nv'] ) ? (int) $_GET['nv'] : 0;                                       // phpcs:ignore WordPress.Security.NonceVerification — „nicht angesehen"

		$where = array( '1=1' ); $args = array();
		if ( isset( $badges[ $f_st ] ) ) { $where[] = 'status = %s'; $args[] = $f_st; }
		if ( $f_nv ) { $where[] = "viewed_last_at IS NULL AND status <> 'entwurf'"; } // nur versendete, vom Kunden noch nicht geöffnete
		if ( '' !== $f_s ) { $like = '%' . $wpdb->esc_like( $f_s ) . '%'; $where[] = '( offer_no LIKE %s OR customer_json LIKE %s )'; $args[] = $like; $args[] = $like; }
		$q    = 'SELECT * FROM ' . $t . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 300';
		$rows = $args ? $wpdb->get_results( $wpdb->prepare( $q, $args ) ) : $wpdb->get_results( $q ); // phpcs:ignore WordPress.DB.PreparedSQL

				echo '<div class="wrap m24offl"><h1 class="wp-heading-inline">Angebote</h1> <a href="' . esc_url( add_query_arg( array( self::QV_NEW => 1 ), home_url( '/' ) ) ) . '" target="_blank" rel="noopener" class="page-title-action" style="background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;border:0;">+ Neues Angebot</a><hr class="wp-header-end">';
		if ( '' !== $notice ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>'; }
		$tax_lbl = array( 'b2b_de_19' => 'DE · 19 %', 'b2b_eu_net' => 'EU B2B · netto', 'b2c_eu_oss' => 'EU B2C · OSS' ); // #9: „Drittland · netto" raus
		echo '<style>.m24offl .flt{display:flex;gap:10px;margin:14px 0 18px;flex-wrap:wrap;align-items:center}.m24offl .chip{padding:7px 14px;border-radius:999px;border:1.5px solid #e5e7eb;background:#fff;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;color:#111417}.m24offl .chip.on{background:#0e447e;border-color:#0e447e;color:#fff}.m24offl .srch{margin-left:auto;display:flex;gap:6px}.m24offl .srch input{height:34px;border:1.5px solid #e5e7eb;border-radius:8px;padding:0 12px;min-width:220px}.m24offl .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:14px;max-width:1000px;padding:16px 18px}.m24offl .crow{display:flex;align-items:center;gap:16px;flex-wrap:wrap}.m24offl .av{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;display:grid;place-items:center;font-weight:800;font-size:15px;flex:0 0 auto}.m24offl .who b{font-size:15px}.m24offl .who div{color:#6b7280;font-size:12.5px}.m24offl .meta{margin-left:auto;display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:flex-end}.m24offl .no{font-family:Saira Condensed,sans-serif;font-weight:700;color:#9a6b25;font-size:15px}.m24offl .tx{color:#6b7280;font-size:12px}.m24offl .sum{font-weight:800;font-size:16px}.m24offl .badge{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px;color:#fff}.m24offl .foot{display:flex;gap:14px;margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb;font-size:13px;flex-wrap:wrap}.m24offl .foot a{text-decoration:none}@media(max-width:700px){.m24offl .meta{width:100%;margin-left:58px}}</style>';
		echo '<style>.m24offl .crow{cursor:pointer}.m24offl .who b{margin-right:2px}.m24offl .flagc{font-size:13px;color:#374151}.m24offl .sentat{color:#8a929c;font-size:12px;margin-top:2px}.m24offl .sumwrap{display:flex;flex-direction:column;align-items:flex-end;line-height:1.25}.m24offl .sum em{font-style:normal;font-weight:600;color:#6b7280;font-size:12px}.m24offl .sum2{font-size:12.5px;color:#6b7280;font-weight:600}.m24offl .m24offl-pos{border-top:1px dashed #e5e7eb;margin-top:12px;padding-top:10px;display:flex;flex-direction:column;gap:8px}.m24offl .crow[aria-expanded="false"] + .m24offl-pos{display:none}.m24offl .m24offl-pos .pl-row{display:flex;align-items:center;gap:10px;font-size:13px}.m24offl .m24offl-pos img,.m24offl .m24offl-pos .pl-ph{width:34px;height:34px;border-radius:6px;object-fit:cover;background:#eef0f2;flex:0 0 auto}.m24offl .m24offl-pos .pl-t{flex:1;min-width:0}.m24offl .m24offl-pos .pl-q{color:#6b7280;white-space:nowrap}.m24offl .m24offl-pos .pl-p{font-weight:700;color:#111;white-space:nowrap}</style>';
		$base = admin_url( 'admin.php?page=' . $page );
		$chip = function ( $key, $label ) use ( $f_st, $base, $f_s ) { return '<a class="chip' . ( $f_st === $key ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( array( 'st' => $key, 's' => $f_s ), $base ) ) . '">' . esc_html( $label ) . '</a>'; };
		echo '<div class="flt">' . $chip( '', 'Alle' );
		foreach ( array( 'entwurf', 'offen', 'angenommen', 'bezahlt', 'storniert' ) as $k ) { echo $chip( $k, $badges[ $k ][0] ); }
		// „Nicht angesehen"-Chip: toggelt nv (unabhängig vom Status-Filter), Suche bleibt erhalten.
		echo '<a class="chip' . ( $f_nv ? ' on' : '' ) . '" href="' . esc_url( add_query_arg( array_filter( array( 'page' => $page, 'nv' => ( $f_nv ? 0 : 1 ), 's' => $f_s ) ), admin_url( 'admin.php' ) ) ) . '">Nicht angesehen</a>';
		echo '<form class="srch" method="get"><input type="hidden" name="page" value="' . esc_attr( $page ) . '"><input type="hidden" name="st" value="' . esc_attr( $f_st ) . '"><input type="hidden" name="nv" value="' . esc_attr( (string) $f_nv ) . '"><input type="search" name="s" value="' . esc_attr( $f_s ) . '" placeholder="Nr., Name oder E-Mail"><button class="button">Suchen</button></form></div>';
		if ( class_exists( 'M24_Stats_Panel' ) ) { M24_Stats_Panel::open_layout(); } // Statistik-Panel rechts
		if ( empty( $rows ) ) {
			echo '<p>Keine Angebote' . ( ( '' !== $f_st || '' !== $f_s ) ? ' zum Filter' : '' ) . '.</p>';
			if ( class_exists( 'M24_Stats_Panel' ) ) { M24_Stats_Panel::close_layout( 'offers' ); }
			echo '</div>'; return;
		}
		foreach ( (array) $rows as $o ) {
			$cust = json_decode( (string) $o->customer_json, true ) ?: array();
			// #9: Firmenname bevorzugt (falls bekannt), sonst Personenname, sonst E-Mail.
			$company = trim( (string) ( $cust['firma'] ?? $cust['company'] ?? '' ) );
			$person  = trim( (string) ( $cust['name'] ?? '' ) );
			$disp    = '' !== $company ? $company : ( '' !== $person ? $person : (string) ( $cust['email'] ?? '—' ) );
			$ini  = ''; foreach ( array_slice( array_values( array_filter( explode( ' ', $disp ) ) ), 0, 2 ) as $w ) { $ini .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) ); }
			if ( '' === $ini ) { $ini = 'K'; }
			// #9: Flagge + Land (verbatim) hinter dem Namen.
			$land_raw = (string) ( $cust['land'] ?? '' );
			$flagc = ( '' !== $land_raw && class_exists( 'M24_Country_Flags' ) ) ? M24_Country_Flags::getFlagAndCountry( $land_raw ) : ''; // leer → nichts (getFlagAndCountry('') gäbe „—")
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
			$cnt      = count( $items );
			$is_draft = ( 'entwurf' === (string) $o->status );
			$no_disp  = $is_draft ? '—' : (string) $o->offer_no;
			// #9: Betrag — immer Netto; Brutto zusätzlich, wenn USt>0 bzw. brutto≠netto.
			$net_v   = (float) $o->subtotal_net;
			$gross_v = (float) $o->total_gross;
			$sum_html = '<span class="sum">' . esc_html( number_format( $net_v, 2, ',', '.' ) ) . '&nbsp;€ <em>netto</em></span>';
			if ( (float) $o->tax_amount > 0 || abs( $gross_v - $net_v ) >= 0.01 ) {
				$sum_html .= '<span class="sum2">' . esc_html( number_format( $gross_v, 2, ',', '.' ) ) . '&nbsp;€ brutto</span>';
			}
			// #9: „Gesendet am {Datum} · vor {N} Tagen" aus sent_at (nicht bei Entwürfen).
			$sent_html = '';
			if ( ! $is_draft && ! empty( $o->sent_at ) ) {
				$sts = strtotime( (string) $o->sent_at . ' UTC' );
				if ( $sts ) {
					$d_ago = max( 0, (int) floor( ( time() - $sts ) / DAY_IN_SECONDS ) );
					$ago   = 0 === $d_ago ? 'heute' : ( 1 === $d_ago ? 'vor 1 Tag' : 'vor ' . $d_ago . ' Tagen' );
					$sent_html = '<div class="sentat">Gesendet am ' . esc_html( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y', $sts ) : gmdate( 'd.m.Y', $sts ) ) . ' · ' . esc_html( $ago ) . '</div>';
				}
			}
			// „Angesehen"-Status (nicht bei Entwürfen): letzter Aufruf + Zähler, sonst „noch nicht angesehen".
			$viewed_html = '';
			if ( ! $is_draft ) {
				if ( ! empty( $o->viewed_last_at ) && ( $vts2 = strtotime( (string) $o->viewed_last_at . ' UTC' ) ) ) {
					$vc = max( 1, (int) $o->view_count );
					$viewed_html = '<div class="sentat" style="color:#1a7f37;">Angesehen am ' . esc_html( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y', $vts2 ) : gmdate( 'd.m.Y', $vts2 ) ) . ' (' . (int) $vc . '×)</div>';
				} else {
					$viewed_html = '<div class="sentat" style="color:#b45309;">Noch nicht angesehen</div>';
				}
			}
			// #10: eingeklappte Positionsliste (aus items_json).
			$pos_html = '';
			foreach ( $items as $it ) {
				$t = (string) ( $it['title'] ?? '' ); if ( '' === $t ) { continue; }
				$q  = max( 1, (int) ( $it['qty'] ?? 1 ) );
				$up = number_format( (float) ( $it['unit_price'] ?? 0 ), 2, ',', '.' ) . ' €';
				$th = self::item_thumb( (string) ( $it['thumb'] ?? '' ), (int) ( $it['teil_id'] ?? 0 ) ); // D2: Fallback aus teil_id
				$pos_html .= '<div class="pl-row">' . ( '' !== $th ? '<img src="' . esc_url( $th ) . '" alt="">' : '<span class="pl-ph"></span>' )
					. '<span class="pl-t">' . esc_html( $t ) . '</span><span class="pl-q">' . (int) $q . ' ×</span><span class="pl-p">' . esc_html( $up ) . '</span></div>';
			}
			echo '<div class="card">';
			echo '<div class="crow" data-offer-toggle aria-expanded="false" role="button" tabindex="0"><div class="av">' . esc_html( $ini ) . '</div><div class="who"><b>' . esc_html( $disp ) . '</b>' . ( '' !== $flagc ? ' <span class="flagc">' . esc_html( $flagc ) . '</span>' : '' ) . '<div>' . esc_html( (string) ( $cust['email'] ?? '' ) ) . ' · ' . (int) $cnt . ' Position' . ( 1 === $cnt ? '' : 'en' ) . '</div>' . $sent_html . $viewed_html . '</div><div class="meta"><span class="no">' . esc_html( $no_disp ) . '</span>' . ( '' !== $txl ? '<span class="tx">' . esc_html( $txl ) . '</span>' : '' ) . '<span class="badge" style="background:' . esc_attr( $stb[1] ) . ';">' . esc_html( $badge ) . '</span><span class="sumwrap">' . $sum_html . '</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( '' !== $pos_html ) { echo '<div class="m24offl-pos" hidden>' . $pos_html . '</div>'; } // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="foot">';
			if ( $is_draft ) {
				// Entwurf: kein Kunden-Ansicht-Link (inaktiv), stattdessen „Weiter bearbeiten" (?draft={id}).
				$edit = add_query_arg( array( self::QV_NEW => 1, 'draft' => (int) $o->id ), home_url( '/' ) );
				echo '<a href="' . esc_url( $edit ) . '" style="color:#0e447e;font-weight:700;">Weiter bearbeiten</a>'; // D3: gleiches Fenster
			} else {
				echo '<a href="' . esc_url( self::view_url( (string) $o->token ) ) . '" target="_blank" rel="noopener">Kunden-Ansicht</a><a href="' . esc_url( self::reopen_url( $o ) ) . '" target="_blank" rel="noopener">Operator öffnen</a>';
				if ( 'angenommen' === (string) $o->status ) { echo '<a href="' . esc_url( $u_paid ) . '" style="color:#1a7f37;font-weight:700;">Zahlung erhalten ✓</a>'; }
				if ( 'storniert' === (string) $o->status ) { echo '<a href="' . esc_url( $u_react ) . '">Reaktivieren</a>'; } else { echo '<a href="' . esc_url( $u_storno ) . '" style="color:#b45309;">Stornieren</a>'; }
			}
			echo '<a href="' . esc_url( $u_del ) . '" style="color:#a00;margin-left:auto;" onclick="return confirm(\'' . ( $is_draft ? 'Entwurf' : 'Angebot ' . esc_js( (string) $o->offer_no ) ) . ' unwiderruflich löschen?\');">Löschen</a></div></div>';
		}
		// #10: Karte anklickbar → Positionsliste ein-/ausklappen (Delegated-Toggle, aria-expanded).
		echo '<script>(function(){document.addEventListener("click",function(e){var h=e.target.closest?e.target.closest("[data-offer-toggle]"):null;if(!h)return;var pl=h.parentNode&&h.parentNode.querySelector(".m24offl-pos");if(!pl)return;var wasHidden=pl.hasAttribute("hidden");if(wasHidden){pl.removeAttribute("hidden");}else{pl.setAttribute("hidden","");}h.setAttribute("aria-expanded",wasHidden?"true":"false");});})();</script>';
		if ( class_exists( 'M24_Stats_Panel' ) ) { M24_Stats_Panel::close_layout( 'offers' ); }
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
			'b2b_eu_net'    => array( 'label' => 'B2B EU → netto (Reverse Charge, keine USt)', 'rate' => 0.0, 'note' => 'Innergemeinschaftliche Lieferung – Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge), keine deutsche USt.', 'note_en' => 'Intra-Community supply – reverse charge, the recipient is liable for VAT; no German VAT.',
				'paren' => '(netto · Reverse Charge; Steuerschuld beim Empfänger)', 'paren_en' => '(net · reverse charge; VAT owed by the recipient)' ),
			'drittland_net' => array( 'label' => 'Drittland (B2B/B2C) → netto + Export/Zoll', 'rate' => 0.0, 'note' => 'Nettopreis (Ausfuhrlieferung). Einfuhrumsatzsteuer, Zölle und Einfuhrabgaben im Bestimmungsland trägt der Käufer.', 'note_en' => 'Net price (export delivery). The buyer is responsible for any import VAT, customs duties and import charges in the destination country.',
				'paren' => '(netto · Ausfuhrlieferung; ausgewiesene Nebenkosten enthalten; Einfuhrumsatzsteuer, Zölle und Einfuhrabgaben trägt der Käufer im Bestimmungsland)', 'paren_en' => '(net · export delivery; itemised additional costs included; import VAT, customs duties and import charges are payable by the buyer in the destination country)' ),
			'b2b_de_19'     => array( 'label' => 'B2B Deutschland → + 19 % MwSt (brutto)', 'rate' => 19.0, 'note' => 'zzgl. 19 % gesetzlicher MwSt.', 'note_en' => 'plus 19% statutory VAT.',
				'paren' => '(inkl. 19 % MwSt. und ausgewiesener Nebenkosten)', 'paren_en' => '(incl. 19% VAT and itemised additional costs)' ),
			'b2c_eu_oss'    => array( 'label' => 'Privat B2C EU → OSS-Satz Zielland (manuell)', 'rate' => null, 'note' => 'One-Stop-Shop: USt-Satz des Bestimmungslandes.', 'note_en' => 'One-Stop-Shop: the VAT rate of the destination country applies.',
				'paren' => '(inkl. USt. via OSS und ausgewiesener Nebenkosten)', 'paren_en' => '(incl. VAT via OSS and itemised additional costs)' ),
		);
	}

	/** Steuer-Hinweis eines Modus in der gewünschten Sprache (EN → note_en, sonst note). Leerer Fallback = DE-note. */
	public static function tax_note_for( string $tax_mode, string $lang ): string {
		$m = self::tax_modes();
		if ( ! isset( $m[ $tax_mode ] ) ) { return ''; }
		if ( 'en' === $lang && ! empty( $m[ $tax_mode ]['note_en'] ) ) { return (string) $m[ $tax_mode ]['note_en']; }
		return (string) ( $m[ $tax_mode ]['note'] ?? '' );
	}

	/** #1: Steuerfall-abhängige Parenthese hinter „Gesamtpreis/Total price". Nur Brutto-Modi sagen „inkl. … Steuer". */
	public static function tax_total_paren( string $tax_mode, string $lang ): string {
		$m = self::tax_modes();
		if ( ! isset( $m[ $tax_mode ] ) ) { return 'en' === $lang ? '(incl. any taxes and itemised additional costs)' : '(inkl. etwaiger Steuern und ausgewiesener Nebenkosten)'; }
		if ( 'en' === $lang && ! empty( $m[ $tax_mode ]['paren_en'] ) ) { return (string) $m[ $tax_mode ]['paren_en']; }
		return (string) ( $m[ $tax_mode ]['paren'] ?? '' );
	}

	/**
	 * Summen berechnen. §25a-Positionen (differenzbesteuert) sind final ohne ausweisbare USt und aus der
	 * Steuerbasis ausgenommen; reguläre Positionen + aktive Zusatzpositionen bilden die Netto-Basis.
	 * @return array{net:float,st25a:float,tax:float,total:float}
	 */
	public static function compute_totals( array $items, array $extras, string $tax_mode, float $tax_rate, string $land = '' ): array {
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
		// Deutsche Kunden (B2B wie B2C): MwSt/Brutto IMMER zeigen. Fehlt ein positiver Satz (net-Modus/kein
		// gewählter Steuerfall), 19 % ansetzen (DE-Default). Nicht-DE bleibt unverändert; §25a bleibt separat
		// (nur der reguläre Netto-Anteil wird besteuert). 'rate' = effektiver Satz für die USt-Beschriftung.
		if ( self::is_de_land( $land ) && $rate <= 0.0 ) { $rate = 19.0; }
		$tax  = round( $net * $rate / 100, 2 );
		return array(
			'net'   => round( $net, 2 ),
			'st25a' => round( $st25a, 2 ),
			'tax'   => $tax,
			'total' => round( $net + $tax + $st25a, 2 ),
			'rate'  => $rate,
		);
	}

	private static function rate_for( string $mode, float $manual ): float {
		$modes = self::tax_modes();
		if ( ! isset( $modes[ $mode ] ) ) { return 0.0; }
		$r = $modes[ $mode ]['rate'];
		return ( null === $r ) ? max( 0.0, $manual ) : (float) $r; // OSS: manueller Satz
	}

	/** Deutscher Kunde? (Land verbatim: 'DE'/'Deutschland'/'Germany'). Steuert die „DE ⇒ 19 %"-Anzeige. */
	private static function is_de_land( string $land ): bool {
		$l = strtoupper( trim( $land ) );
		return in_array( $l, array( 'DE', 'D', 'DEU', 'DEUTSCHLAND', 'GERMANY' ), true );
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
		// „Als Entwurf speichern": Status entwurf, KEINE Mail, KEIN Nummernkreis-Verbrauch (Nummer erst beim Senden).
		register_rest_route( self::NS, '/offers/save-draft', array(
			'methods' => 'POST', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_save_draft' ),
		) );
		// EN-Titel live im Operator: on-demand DeepL (gecacht) für Katalog-Positionen ohne frischen EN-Titel.
		register_rest_route( self::NS, '/offers/en-titles', array(
			'methods' => 'POST', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_en_titles' ),
		) );
		// #7: EN-Titel-Korrektur dauerhaft in den Artikel schreiben (manueller Override, DeepL-fest).
		register_rest_route( self::NS, '/offers/save-en-title', array(
			'methods' => 'POST', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_save_en_title' ),
		) );
		// #11: Vorschau (Mail-HTML + Kunden-Ansicht) aus dem aktuellen, ungespeicherten Stand — kein DB-Write.
		register_rest_route( self::NS, '/offers/preview', array(
			'methods' => 'POST', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_preview' ),
		) );
		// B (v3): Kunden-Schnellanlage — Live-Suche + Neuanlage (Desk-kompatible Felder).
		register_rest_route( self::NS, '/offers/customers', array(
			'methods' => 'GET', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_customers_search' ),
		) );
		register_rest_route( self::NS, '/offers/customer-create', array(
			'methods' => 'POST', 'permission_callback' => $admin, 'callback' => array( __CLASS__, 'handle_customer_create' ),
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
		// Garage-Karte (Lösung A): Gast legt passwortlos ein Konto an (DOI). Zuordnung nur über die Offer-E-Mail + Token.
		register_rest_route( self::NS, '/offers/claim', array(
			'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( __CLASS__, 'handle_claim' ),
		) );
	}

	/**
	 * Garage-Übernahme: passwortloses Konto zur Offer-E-Mail anlegen (bestehende DOI-Strecke) + Bestätigungslink.
	 * Die E-Mail kommt AUSSCHLIESSLICH aus dem Offer-Snapshot (Token) — kein Client-Override, damit ein fremder
	 * Link-Besitzer das Angebot nicht auf eine andere Adresse ziehen kann.
	 */
	public static function handle_claim( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		if ( ! class_exists( 'M24_Login' ) || ! M24_Login::enabled() ) {
			return new WP_Error( 'm24off_off', 'Nicht verfügbar.', array( 'status' => 400 ) );
		}
		$o = self::get_by_token( (string) $req->get_param( 'token' ) );
		if ( ! $o || 'entwurf' === (string) $o->status ) {
			return new WP_Error( 'm24off_nf', 'Angebot nicht gefunden.', array( 'status' => 404 ) );
		}
		$cust  = json_decode( (string) $o->customer_json, true ) ?: array();
		$email = strtolower( trim( (string) ( $cust['email'] ?? '' ) ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'm24off_mail', 'Keine gültige E-Mail am Angebot.', array( 'status' => 400 ) );
		}
		// Wird durch diesen Aufruf ein NEUES Konto angelegt (E-Mail war unbekannt), ist es bis zur DOI-Bestätigung
		// nur ein Stub — als solcher markieren, damit die Angebotsansicht es NICHT als echtes Konto behandelt
		// (kein grüner Zustand, kein Auto-Claim). Bestand bereits ein echtes Konto, wird nichts markiert.
		$existed = (bool) get_user_by( 'email', $email );
		$ok      = M24_Login::create_account_and_send_link( $email, trim( (string) ( $cust['name'] ?? '' ) ), false );
		if ( ! $ok ) { return new WP_Error( 'm24off_send', 'Versand fehlgeschlagen.', array( 'status' => 429 ) ); }
		if ( ! $existed ) {
			$u = get_user_by( 'email', $email );
			if ( $u ) { update_user_meta( (int) $u->ID, '_m24_doi_pending', 1 ); } // erst nach DOI wird daraus ein echtes Konto
		}
		// KEIN Auto-Claim hier: das Angebot landet erst NACH der DOI-Bestätigung in der Garage (Render als echtes Konto).
		return array( 'ok' => true );
	}

	/**
	 * Angebot einem Konto zuordnen (idempotent) + leere Konto-Stammdaten aus dem Offer-Snapshot füllen
	 * (non-destruktiv — vorhandene Konto-Werte werden nie überschrieben). Wird beim Rendern von Zustand 2 aufgerufen.
	 */
	public static function claim_for_account( int $offer_id, int $uid, array $cust = array() ): void {
		if ( $offer_id <= 0 || $uid <= 0 ) { return; }
		global $wpdb; $t = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET account_id = %d WHERE id = %d AND account_id = 0 AND status <> 'entwurf'", $uid, $offer_id ) ); // phpcs:ignore WordPress.DB
		if ( empty( $cust ) ) { return; }
		$meta = array(
			'_m24_land'         => (string) ( $cust['land'] ?? '' ),
			'_m24_firmenname'   => (string) ( $cust['firma'] ?? ( $cust['firmenname'] ?? '' ) ),
			'_m24_telefon'      => (string) ( $cust['telefon'] ?? '' ),
			'_m24_strasse'      => (string) ( $cust['strasse'] ?? '' ),
			'_m24_adresszusatz' => (string) ( $cust['adresszusatz'] ?? '' ),
			'_m24_plz'          => (string) ( $cust['plz'] ?? '' ),
			'_m24_ort'          => (string) ( $cust['ort'] ?? '' ),
			'_m24_ustid'        => (string) ( $cust['ustid'] ?? '' ),
			'_m24_eori'         => (string) ( $cust['eori'] ?? '' ),
		);
		foreach ( $meta as $k => $v ) {
			if ( '' !== $v && '' === (string) get_user_meta( $uid, $k, true ) ) { update_user_meta( $uid, $k, $v ); }
		}
		if ( '' === (string) get_user_meta( $uid, '_m24_kundentyp', true ) ) {
			update_user_meta( $uid, '_m24_kundentyp', ( 'b2b' === ( $cust['kundentyp'] ?? '' ) ) ? 'b2b' : 'b2c' );
		}
		$vn = (string) ( $cust['vorname'] ?? '' ); $nn = (string) ( $cust['nachname'] ?? '' );
		if ( '' === $vn && '' === $nn && '' !== (string) ( $cust['name'] ?? '' ) ) {
			$parts = explode( ' ', trim( (string) $cust['name'] ), 2 ); $vn = (string) $parts[0]; $nn = (string) ( $parts[1] ?? '' );
		}
		if ( '' !== $vn && '' === (string) get_user_meta( $uid, 'first_name', true ) ) { update_user_meta( $uid, 'first_name', $vn ); }
		if ( '' !== $nn && '' === (string) get_user_meta( $uid, 'last_name', true ) ) { update_user_meta( $uid, 'last_name', $nn ); }
	}

	public static function handle_accept( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		$o = self::get_by_token( (string) $req->get_param( 'token' ) );
		if ( ! $o ) { return new WP_Error( 'm24off_nf', 'Angebot nicht gefunden.', array( 'status' => 404 ) ); }
		// Teil 1: KEINE Gastannahme mehr. Verbindliche Annahme (= Kaufvertrag) nur mit eingeloggtem Nutzer, dessen
		// E-Mail der Angebots-Kunden-E-Mail entspricht (schützt gegen Annahme über weitergeleitete Links).
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'm24off_login', 'Bitte zuerst einloggen, um das Angebot anzunehmen.', array( 'status' => 401 ) );
		}
		if ( class_exists( 'M24_Offer_Accept' ) && ! M24_Offer_Accept::may_accept( $o ) ) {
			return new WP_Error( 'm24off_mismatch', 'Dieses Angebot kann nur mit dem Konto der hinterlegten E-Mail angenommen werden.', array( 'status' => 403 ) );
		}
		// Teil 3/4: vollständige, gültige Rechnungsadresse ist Pflicht (serverseitig, nicht nur Client). Bei fehlenden
		// Pflichtfeldern → 422 mit Feldliste; die Checkbox („gelesen") prüft der Client wie gehabt vor dem Senden.
		$p    = (array) $req->get_json_params();
		$cust = json_decode( (string) $o->customer_json, true ); $cust = is_array( $cust ) ? $cust : array();
		$val  = class_exists( 'M24_Offer_Address' ) ? M24_Offer_Address::validate( $p, (string) ( $cust['kundentyp'] ?? 'b2c' ) ) : array( 'ok' => true );
		if ( empty( $val['ok'] ) ) {
			return new WP_Error( 'm24off_addr', 'Bitte die Rechnungsadresse vollständig ausfüllen.', array( 'status' => 422, 'fields' => $val['errors'] ) );
		}
		// Nur ein offenes Angebot annehmen (idempotent, wenn bereits angenommen). Zahlung bestätigt Daniel im Desk.
		if ( 'offen' === (string) $o->status ) {
			global $wpdb;
			$acc = get_current_user_id();
			$row = array( 'status' => 'angenommen' );
			if ( (int) $o->account_id <= 0 && $acc > 0 ) { $row['account_id'] = $acc; } // Auftrag ans annehmende Konto binden
			$wpdb->update( self::table(), $row, array( 'id' => (int) $o->id ) );
			// Adresse an den Auftrag (Spalten) + ans Konto (User-Meta) persistieren.
			if ( class_exists( 'M24_Offer_Address' ) && ! empty( $val['billing'] ) ) {
				M24_Offer_Address::persist( (int) $o->id, $acc, $val['billing'], $val['shipping'], ! empty( $val['ship_diff'] ) );
			}
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
			. '<p style="margin:0;color:#5a6474;font-size:13px;">Kunden-Ansicht: <a href="' . esc_url( $view ) . '" style="color:#1f74c4;">' . esc_html( (string) $o->offer_no ) . '</a>. Den Zahlungseingang bestätigst du in der Angebote-Liste über „Zahlung erhalten ✓“.</p>';
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
				'title_en' => class_exists( 'M24_DeepL' ) ? M24_DeepL::cached_en_title( (int) $p->ID ) : (string) get_post_meta( $p->ID, '_m24_titel_en', true ), // NUR frischer Cache (keine DeepL-Quota bei Suche); Operator holt fehlende live nach
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
		// 1b) Fallback-Härtung: Index leer/unvollständig (z. B. während Rebuild) → Live-Scan der Rohfelder,
		// normalisiert wie der Index, gedeckelt (200 Kandidaten / 24 Treffer). Nie „0 durch leeren Index".
		if ( strlen( $qn ) >= 6 && empty( $seen ) && class_exists( 'M24_Catalog_Partnums' ) ) {
			$fargs = array( 'post_type' => 'm24_teil', 'post_status' => 'publish', 'posts_per_page' => 200, 'no_found_rows' => true, 'fields' => 'ids' );
			if ( $tax ) { $fargs['tax_query'] = $tax; }
			if ( $mq ) { $fargs['meta_query'] = $mq; }
			$added = 0;
			foreach ( get_posts( $fargs ) as $pid ) {
				$pid  = (int) $pid;
				$nums = M24_Catalog_Partnums::extract_from(
					(string) get_post_field( 'post_content', $pid ),
					(string) get_post_meta( $pid, '_m24_beschreibung_de', true ),
					(string) get_post_meta( $pid, '_m24_beschreibung_en', true ),
					(string) get_post_meta( $pid, '_m24_hinweis', true ),
					(string) get_post_meta( $pid, '_m24_bmw_teilenummer', true )
				);
				foreach ( $nums as $n ) {
					if ( false !== strpos( $n, $qn ) ) { $seen[ $pid ] = 1; $out[] = $mk( get_post( $pid ), 'partnum' ); $added++; break; }
				}
				if ( $added >= 24 ) { break; }
			}
		}
		// 2) Name-/Art-Nr-Pfad (WP-Volltext 's'), Teilenummern-Treffer nicht doppeln.
		$sargs = array( 'post_type' => 'm24_teil', 'post_status' => 'publish', 'posts_per_page' => 24, 'no_found_rows' => true, 's' => $q );
		if ( $tax ) { $sargs['tax_query'] = $tax; }
		if ( $mq ) { $sargs['meta_query'] = $mq; }
		foreach ( get_posts( $sargs ) as $p ) { if ( isset( $seen[ (int) $p->ID ] ) ) { continue; } $out[] = $mk( $p, 'name' ); }

		return rest_ensure_response( array( 'ok' => true, 'items' => $out, 'qnorm' => $qn ) );
	}

	/* ── B (v3): Kunden-Schnellanlage ───────────────────────────────────── */

	/** Land-Eingabe (Name/ISO/Alias) auf ISO2 normalisieren. UK/England/GB → GB (Drittland seit Brexit!). */
	public static function normalize_land( string $in ): string {
		$s = strtoupper( trim( preg_replace( '/\s+/', ' ', $in ) ) );
		if ( '' === $s ) { return ''; }
		$alias = array(
			'UK' => 'GB', 'GB' => 'GB', 'GROSSBRITANNIEN' => 'GB', 'GROẞBRITANNIEN' => 'GB', 'VEREINIGTES KÖNIGREICH' => 'GB',
			'VEREINIGTES KOENIGREICH' => 'GB', 'UNITED KINGDOM' => 'GB', 'ENGLAND' => 'GB', 'GREAT BRITAIN' => 'GB', 'BRITAIN' => 'GB',
			'DEUTSCHLAND' => 'DE', 'GERMANY' => 'DE', 'ÖSTERREICH' => 'AT', 'OESTERREICH' => 'AT', 'AUSTRIA' => 'AT',
			'SCHWEIZ' => 'CH', 'SWITZERLAND' => 'CH', 'FRANKREICH' => 'FR', 'FRANCE' => 'FR', 'ITALIEN' => 'IT', 'ITALY' => 'IT',
			'SPANIEN' => 'ES', 'SPAIN' => 'ES', 'NIEDERLANDE' => 'NL', 'NETHERLANDS' => 'NL', 'BELGIEN' => 'BE', 'BELGIUM' => 'BE',
			'LUXEMBURG' => 'LU', 'POLEN' => 'PL', 'POLAND' => 'PL', 'TSCHECHIEN' => 'CZ', 'DÄNEMARK' => 'DK', 'DAENEMARK' => 'DK',
			'SCHWEDEN' => 'SE', 'USA' => 'US', 'UNITED STATES' => 'US', 'VEREINIGTE STAATEN' => 'US',
		);
		if ( isset( $alias[ $s ] ) ) { return $alias[ $s ]; }
		return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $s ), 0, 2 ) );
	}

	/** WP-User → Operator-Kunde (Desk-kompatible Meta, volles Feldset für den Edit-Modus). */
	/** #8: Aktueller (Live-)Kundendatensatz zu einer E-Mail — Basis für den from=/draft=-Reload-Merge. */
	public static function customer_by_email( string $email ): ?array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! is_email( $email ) ) { return null; }
		$u = get_user_by( 'email', $email );
		return $u ? self::user_to_customer( (int) $u->ID ) : null;
	}

	private static function user_to_customer( int $uid ): ?array {
		$u = get_userdata( $uid );
		if ( ! $u ) { return null; }
		$vn   = (string) get_user_meta( $uid, 'first_name', true );
		$nn   = (string) get_user_meta( $uid, 'last_name', true );
		$name = trim( $vn . ' ' . $nn ); if ( '' === $name ) { $name = (string) $u->display_name; }
		$kt   = ( 'b2b' === get_user_meta( $uid, '_m24_kundentyp', true ) ) ? 'b2b' : 'b2c';
		$firma = (string) get_user_meta( $uid, '_m24_firmenname', true );
		if ( class_exists( 'M24_B2B' ) && method_exists( 'M24_B2B', 'get_haendler_by_user' ) ) {
			$h = M24_B2B::get_haendler_by_user( $uid );
			if ( $h ) { if ( isset( $h->firma ) && '' !== (string) $h->firma ) { $firma = (string) $h->firma; } $kt = 'b2b'; }
		}
		return array(
			'id'    => $uid,
			'name'  => $name,
			'email' => (string) $u->user_email,
			'anrede' => (string) get_user_meta( $uid, '_m24_anrede', true ), // Herr|Frau|'' (Prefill Kunden-Edit + Sie-Begrüßung)
			'firma' => $firma,
			'firmenname' => $firma,
			'kundentyp' => $kt,
			'vorname' => $vn,
			'nachname' => $nn,
			'strasse'      => (string) get_user_meta( $uid, '_m24_strasse', true ),
			'adresszusatz' => (string) get_user_meta( $uid, '_m24_adresszusatz', true ),
			'plz'          => (string) get_user_meta( $uid, '_m24_plz', true ),
			'ort'          => (string) get_user_meta( $uid, '_m24_ort', true ),
			'telefon'      => (string) get_user_meta( $uid, '_m24_telefon', true ),
			'ustid'        => (string) get_user_meta( $uid, '_m24_ustid', true ),
			'eori'         => (string) get_user_meta( $uid, '_m24_eori', true ),
			'land'  => (string) get_user_meta( $uid, '_m24_land', true ), // A1: verbatim (nicht auf ISO2 kürzen)
		);
	}

	public static function handle_customers_search( WP_REST_Request $req ) {
		$q = trim( sanitize_text_field( (string) $req->get_param( 'q' ) ) );
		if ( strlen( $q ) < 2 ) { return rest_ensure_response( array( 'ok' => true, 'items' => array() ) ); }
		$ids = array();
		$uq  = new WP_User_Query( array(
			'search'         => '*' . $q . '*',
			'search_columns' => array( 'user_email', 'display_name', 'user_login', 'user_nicename' ),
			'number'         => 12,
			'fields'         => 'ID',
		) );
		foreach ( (array) $uq->get_results() as $id ) { $ids[] = (int) $id; }
		// Vor-/Nachname/Firma-Meta ergänzen (deckt Suchen ab, die display_name nicht trifft).
		$mq = new WP_User_Query( array(
			'number'     => 12,
			'fields'     => 'ID',
			'meta_query' => array( 'relation' => 'OR',
				array( 'key' => 'first_name', 'value' => $q, 'compare' => 'LIKE' ),
				array( 'key' => 'last_name', 'value' => $q, 'compare' => 'LIKE' ),
				array( 'key' => '_m24_firmenname', 'value' => $q, 'compare' => 'LIKE' ),
			),
		) );
		foreach ( (array) $mq->get_results() as $id ) { $ids[] = (int) $id; }
		$ids = array_slice( array_values( array_unique( $ids ) ), 0, 12 );
		$items = array();
		foreach ( $ids as $uid ) { $c = self::user_to_customer( $uid ); if ( $c ) { $items[] = $c; } }
		return rest_ensure_response( array( 'ok' => true, 'items' => $items ) );
	}

	public static function handle_customer_create( WP_REST_Request $req ) {
		$p     = (array) $req->get_json_params();
		$email = sanitize_email( (string) ( $p['email'] ?? '' ) );
		// #5: NUR E-Mail ist Pflicht (wird zum Versand des Angebots gebraucht). Name/Adresse optional.
		if ( ! is_email( $email ) ) { return new WP_Error( 'm24off_email', 'Bitte eine gültige E-Mail angeben.', array( 'status' => 422 ) ); }
		$vorname  = sanitize_text_field( (string) ( $p['vorname'] ?? '' ) );
		$nachname = sanitize_text_field( (string) ( $p['nachname'] ?? '' ) );
		$anrede_lc = strtolower( trim( (string) ( $p['anrede'] ?? '' ) ) );                     // Formular sendet Herr/Frau/'' oder herr/frau
		$anrede    = ( 'herr' === $anrede_lc ) ? 'Herr' : ( ( 'frau' === $anrede_lc ) ? 'Frau' : '' ); // intern kanonisch 'Herr'/'Frau'/''
		$kt       = ( 'b2b' === ( $p['kundentyp'] ?? '' ) ) ? 'b2b' : 'b2c';
		$land     = sanitize_text_field( trim( (string) ( $p['land'] ?? '' ) ) ); if ( '' === $land ) { $land = 'Deutschland'; } // A1: Land VERBATIM speichern (ISO/Flagge nur intern abgeleitet)
		$display  = trim( $vorname . ' ' . $nachname ); if ( '' === $display ) { $display = $email; }
		$edit_id  = (int) ( $p['id'] ?? 0 );
		$existed  = false;

		// #4: Edit-Modus (id gesetzt) → bestehenden Datensatz aktualisieren, kein Duplikat.
		if ( $edit_id > 0 && get_userdata( $edit_id ) ) {
			$uid = $edit_id;
			$cur = get_userdata( $uid );
			if ( strtolower( (string) $cur->user_email ) !== strtolower( $email ) ) {
				$other = get_user_by( 'email', $email );
				if ( $other && (int) $other->ID !== $uid ) { return new WP_Error( 'm24off_email_taken', 'Diese E-Mail gehört bereits zu einem anderen Kunden.', array( 'status' => 422 ) ); }
				wp_update_user( array( 'ID' => $uid, 'user_email' => $email ) );
			}
			wp_update_user( array( 'ID' => $uid, 'first_name' => $vorname, 'last_name' => $nachname, 'display_name' => $display ) );
			$existed = true;
		} else {
			// Anlage — bestehende E-Mail → verknüpfen statt doppeln.
			$ex = get_user_by( 'email', $email );
			if ( $ex ) {
				$uid = (int) $ex->ID;
				wp_update_user( array( 'ID' => $uid, 'first_name' => $vorname, 'last_name' => $nachname ) );
				$existed = true;
			} else {
				$uid = wp_insert_user( array(
					'user_login'   => $email,
					'user_email'   => $email,
					'user_pass'    => wp_generate_password( 24 ),
					'first_name'   => $vorname,
					'last_name'    => $nachname,
					'display_name' => $display,
					'role'         => 'subscriber',
				) );
				if ( is_wp_error( $uid ) ) { return new WP_Error( 'm24off_create', $uid->get_error_message(), array( 'status' => 422 ) ); }
				$uid = (int) $uid;
			}
		}
		// Desk-kompatible Felder als User-Meta (späteres Sync-Mapping; Desk bleibt gated). B2C leert USt-ID/EORI/Firma.
		$fields = array(
			'_m24_anrede'       => $anrede, // A1: Anrede am Konto (fließt in Sie-Begrüßung + Kunden-Sync)
			'_m24_kundentyp'    => $kt,
			'_m24_firmenname'   => 'b2b' === $kt ? sanitize_text_field( (string) ( $p['firmenname'] ?? '' ) ) : '',
			'_m24_strasse'      => sanitize_text_field( (string) ( $p['strasse'] ?? '' ) ),
			'_m24_adresszusatz' => sanitize_text_field( (string) ( $p['adresszusatz'] ?? '' ) ),
			'_m24_plz'          => sanitize_text_field( (string) ( $p['plz'] ?? '' ) ),
			'_m24_ort'          => sanitize_text_field( (string) ( $p['ort'] ?? '' ) ),
			'_m24_land'         => $land,
			'_m24_telefon'      => sanitize_text_field( (string) ( $p['telefon'] ?? '' ) ),
			'_m24_ustid'        => 'b2b' === $kt ? sanitize_text_field( (string) ( $p['ustid'] ?? '' ) ) : '',
			'_m24_eori'         => 'b2b' === $kt ? sanitize_text_field( (string) ( $p['eori'] ?? '' ) ) : '',
		);
		foreach ( $fields as $k => $v ) { update_user_meta( $uid, $k, $v ); }
		// W3: Kontodaten geändert → Desk-Kunde aktualisieren (still übersprungen, wenn noch nie gepusht).
		do_action( 'm24_customer_updated', (int) $uid );
		if ( class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'customer_create', 'info', $edit_id > 0 ? 'Kunde aktualisiert (Schnellanlage)' : 'Kunde angelegt (Schnellanlage)', array( 'email' => $email, 'kundentyp' => $kt ) );
		}
		$c = self::user_to_customer( $uid );
		if ( $existed ) { $c['existed'] = true; }
		return rest_ensure_response( array( 'ok' => true, 'customer' => $c ) );
	}

	public static function teil_price( int $pid ): ?float { // public: Prefill-Auflösung (render) baut Positionen über die stabile Teil-ID
		if ( get_post_meta( $pid, '_m24_preis_auf_anfrage', true ) ) { return null; }
		if ( class_exists( 'M24_Catalog_Pricing' ) ) {
			$p = M24_Catalog_Pricing::get( $pid );
			return ( $p && ! empty( $p['brutto'] ) && (float) $p['brutto'] > 0 ) ? (float) $p['brutto'] : null;
		}
		return null;
	}

	/**
	 * Positions-Preis 1:1 wie auf der Website — aus der Produkt-Preisquelle (cent-genau).
	 * Angebotspositionen sind NETTO (USt wird ergänzt), außer §25a (Brutto direkt, keine USt).
	 * Quelle der Wahrheit = M24_Catalog_Pricing::get() (Basiswert Netto bei 'regel'), NICHT ein
	 * Anzeige-String. So entsteht auch bei krummen Preisen keine Rundungsabweichung.
	 *
	 * @param int $pid  m24_teil-Post-ID
	 * @return array{0:float,1:bool}|null  [unit_price, is25a] oder null (Preis auf Anfrage / kein Preis)
	 */
	public static function teil_price_net( int $pid ): ?array {
		if ( $pid <= 0 || get_post_meta( $pid, '_m24_preis_auf_anfrage', true ) ) { return null; }
		if ( ! class_exists( 'M24_Catalog_Pricing' ) ) { return null; }
		$p = M24_Catalog_Pricing::get( $pid );
		if ( ! is_array( $p ) ) { return null; }
		// §25a: Basiswert ist Brutto, keine ausweisbare MwSt → Position trägt den Brutto direkt (tax25a=true).
		if ( true === M24_Catalog_Pricing::is_25a( $pid ) ) {
			$b = (float) ( $p['brutto'] ?? 0 );
			return ( $b > 0 ) ? array( round( $b, 2 ), true ) : null;
		}
		// Regelbesteuert: NETTO ist der hinterlegte Basiswert → exakt übernehmen.
		$n = ( isset( $p['netto'] ) && null !== $p['netto'] ) ? (float) $p['netto'] : 0.0;
		if ( $n <= 0.0 ) {
			// Kein Netto hinterlegt → aus Brutto zurückrechnen (DE-Satz), damit Brutto exakt matcht.
			$b = (float) ( $p['brutto'] ?? 0 );
			if ( $b <= 0 ) { return null; }
			$n = $b / ( 1 + M24_Catalog_Pricing::MWST_SATZ );
		}
		return array( round( $n, 2 ), false );
	}

	/**
	 * Löst die m24_teil-Post-ID aus einem Anfrage-Item auf — für den 1:1-Preis-Prefill.
	 * Reihenfolge nach Stabilität: numerische src_pid → Artikelnummer (_m24_artikelnummer) → URL.
	 * (src_pid kann ein Fremd-Code wie „P2024…" sein → dann greift die Art.-Nr.)
	 *
	 * @param array $it  Anfrage-Item (art, price, src_pid, src_art_nr, src_url …)
	 * @return int  Post-ID oder 0
	 */
	public static function resolve_teil_from_item( array $it ): int {
		$pid = (int) ( $it['src_pid'] ?? 0 );
		if ( $pid > 0 && 'm24_teil' === get_post_type( $pid ) ) { return $pid; }

		$artnr = trim( (string) ( $it['src_art_nr'] ?? '' ) );
		if ( '' !== $artnr ) {
			$found = get_posts( array(
				'post_type'      => 'm24_teil',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => '_m24_artikelnummer', 'value' => $artnr ) ),
			) );
			if ( ! empty( $found ) ) { return (int) $found[0]; }
		}

		$url = (string) ( $it['src_url'] ?? '' );
		if ( '' !== $url ) {
			$byurl = (int) url_to_postid( $url );
			if ( $byurl > 0 && 'm24_teil' === get_post_type( $byurl ) ) { return $byurl; }
		}
		return 0;
	}

	/** §25a differenzbesteuert? EINE Quelle: M24_Catalog_Pricing::is_25a (liest _m24_mwst_modus + veraltete
	 * _m24_differenzbesteuert-Flag). Unbestimmt → false (Operator kann im Modal übersteuern). Filterbar. */
	private static function is_tax25a( int $pid ): bool {
		$is = class_exists( 'M24_Catalog_Pricing' )
			? ( true === M24_Catalog_Pricing::is_25a( $pid ) )
			: ( 'paragraf25a' === (string) get_post_meta( $pid, '_m24_mwst_modus', true ) );
		return (bool) apply_filters( 'm24_offer_teil_tax25a', $is, $pid );
	}

	/** Angebot anlegen + versenden. */
	public static function handle_send( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$p        = $req->get_json_params();
		// Doppelklick/Doppel-POST-Guard (zeit-/draft-/payload-UNABHÄNGIG): beide Klicks tragen denselben idem_key.
		// Erster Request legt an und speichert key→offer_id; jeder weitere Request mit demselben Key bekommt idempotent
		// dasselbe Angebot zurück (bzw. 409 während der Anlage). next_number() zieht damit garantiert nur EINMAL.
		$idem = isset( $p['idem_key'] ) ? preg_replace( '/[^a-z0-9]/i', '', (string) $p['idem_key'] ) : '';
		$ikey = '' !== $idem ? 'm24off_idem_' . $idem : '';
		if ( '' !== $ikey ) {
			$prev = get_transient( $ikey );
			if ( is_array( $prev ) && ! empty( $prev['offer_id'] ) ) {
				$ex = self::get_by_id( (int) $prev['offer_id'] );
				if ( $ex ) {
					return rest_ensure_response( array( 'ok' => true, 'offer_no' => (string) $ex->offer_no, 'token' => (string) $ex->token, 'duplicate' => true, 'message' => 'Angebot ' . $ex->offer_no . ' wurde gesendet.' ) );
				}
			}
			if ( 'locked' === $prev ) {
				return new WP_Error( 'm24off_inflight', 'Das Angebot wird bereits gesendet — bitte einen Moment.', array( 'status' => 409 ) );
			}
			set_transient( $ikey, 'locked', 30 ); // Sperre während der Anlage; nach Anlage → offer_id (unten)
		}
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
		$src['anrede_form'] = ( isset( $p['anrede_form'] ) && 'du' === $p['anrede_form'] ) ? 'du' : 'sie'; // DE-Anredeform je Angebot (Default Sie)
		// EN-Angebot: fehlende EN-Titel der Katalog-Positionen per DeepL füllen (EINE Batch-Anfrage, gecacht).
		// Wirkt im gespeicherten Snapshot → Mail + Kunden-Ansicht + Druck nutzen die EN-Titel. Fehler → DE-Fallback.
		if ( 'en' === $src['lang'] && class_exists( 'M24_DeepL' ) ) {
			$items = M24_DeepL::fill_item_en_titles( $items );
		}
		// v3: Anschreiben-Felder + globale Lieferzeit im src_json (Zeilenumbrüche im Freitext erhalten).
		$src['salutation'] = isset( $p['salutation'] ) ? sanitize_text_field( (string) $p['salutation'] ) : '';
		$src['note']       = isset( $p['note'] ) ? sanitize_textarea_field( (string) $p['note'] ) : '';
		$src['delivery']   = isset( $p['delivery_time'] ) ? sanitize_text_field( (string) $p['delivery_time'] ) : '';
		$totals   = self::compute_totals( $items, $extras, $tax_mode, $tax_rate, (string) ( $customer['land'] ?? '' ) );
		$tax_note = $modes[ $tax_mode ]['note'];

		$account_id = self::account_for_email( $customer['email'] );
		$valid_dt   = gmdate( 'Y-m-d', time() + self::VALID_DAYS * DAY_IN_SECONDS ); // Gültigkeit AB SENDEDATUM

		$row = array(
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
		);

		// Entwurf → beim Senden AKTUALISIEREN (keine Dublette): jetzt erst die echte Nummer ziehen, Gültigkeit
		// ab heute. Sonst Neuanlage mit frischem Token + Nummer.
		$draft_id = (int) ( $p['draft_id'] ?? 0 );
		$draft    = $draft_id > 0 ? self::get_by_id( $draft_id ) : null;
		// Doppelklick/Doppel-POST-Guard: Ist der Entwurf bereits gesendet (Status ≠ entwurf), das BESTEHENDE Angebot
		// idempotent zurückgeben — KEINE zweite Anlage, next_number() zieht nicht erneut. (Behebt 2026-1014 + 2026-1015.)
		if ( $draft && 'entwurf' !== (string) $draft->status ) {
			if ( '' !== $ikey ) { set_transient( $ikey, array( 'offer_id' => (int) $draft->id ), 600 ); }
			return rest_ensure_response( array( 'ok' => true, 'offer_no' => (string) $draft->offer_no, 'token' => (string) $draft->token, 'duplicate' => true, 'message' => 'Angebot ' . $draft->offer_no . ' wurde gesendet.' ) );
		}
		if ( $draft && 'entwurf' === (string) $draft->status ) {
			$token           = (string) $draft->token ?: bin2hex( random_bytes( 16 ) );
			$offer_no        = self::next_number();
			$row['token']    = $token;
			$row['offer_no'] = $offer_no;
			$wpdb->update( self::table(), $row, array( 'id' => $draft_id ) );
			$offer_id = $draft_id;
		} else {
			$token           = bin2hex( random_bytes( 16 ) );
			$offer_no        = self::next_number();
			$row['token']    = $token;
			$row['offer_no'] = $offer_no;
			$wpdb->insert( self::table(), $row );
			$offer_id = (int) $wpdb->insert_id;
		}
		if ( '' !== $ikey ) { set_transient( $ikey, array( 'offer_id' => $offer_id ), 600 ); } // Anlage fertig -> Key->Angebot (10 min); Doppel-Sends bekommen dieses zurueck
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

	/**
	 * „Als Entwurf speichern": speichert das komplette Angebot (Kunde, Positionen inkl. Reihenfolge, Steuer,
	 * Lieferzeit, Sprache, Anrede, Freitext) mit Status „entwurf" — OHNE Mail. KEIN Nummernkreis-Verbrauch:
	 * offer_no bleibt ein eindeutiger Platzhalter (die UNIQUE-Spalte erlaubt kein doppeltes ''); die echte
	 * Nummer wird erst beim Senden via next_number() gezogen. Vorhandener Entwurf (draft_id) → Update.
	 */
	public static function handle_save_draft( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$p        = $req->get_json_params();
		$customer = self::clean_customer( (array) ( $p['customer'] ?? array() ) );
		$items    = self::clean_items( (array) ( $p['items'] ?? array() ) );
		// Bug 1: Ein Entwurf ist Arbeitsstand — die Kunden-E-Mail wird erst beim SENDEN erzwungen (handle_send),
		// NICHT beim Entwurf. Sonst könnten Garage-Entwürfe (noch ohne Kunde) nicht (auto-)gespeichert werden und
		// Positions-Edits gingen beim Reload verloren. Guard nur gegen komplett leere Neuanlage ohne Bezug.
		$draft_id_in = (int) ( $p['draft_id'] ?? 0 );
		if ( ! is_email( $customer['email'] ) && empty( $items ) && $draft_id_in <= 0 ) {
			return new WP_Error( 'm24off_bad', 'Ein Entwurf braucht mindestens eine Position oder eine Kunden-E-Mail.', array( 'status' => 400 ) );
		}
		$extras   = self::clean_extras( (array) ( $p['extras'] ?? array() ) );
		$tax_mode = (string) ( $p['tax_mode'] ?? '' );
		$modes    = self::tax_modes();
		$has_mode = isset( $modes[ $tax_mode ] );
		$tax_rate = (float) ( $p['tax_rate'] ?? 0 );
		$delivery = sanitize_text_field( (string) ( $p['delivery_time'] ?? '' ) );
		$src      = self::clean_src( (array) ( $p['src'] ?? array() ) );
		$src['lang']       = ( isset( $p['lang'] ) && 'en' === $p['lang'] ) ? 'en' : 'de';
			$src['anrede_form'] = ( isset( $p['anrede_form'] ) && 'du' === $p['anrede_form'] ) ? 'du' : 'sie';
		$src['salutation'] = isset( $p['salutation'] ) ? sanitize_text_field( (string) $p['salutation'] ) : '';
		$src['note']       = isset( $p['note'] ) ? sanitize_textarea_field( (string) $p['note'] ) : '';
		$src['delivery']   = $delivery;
		$totals   = $has_mode ? self::compute_totals( $items, $extras, $tax_mode, $tax_rate, (string) ( $customer['land'] ?? '' ) ) : array( 'net' => 0, 'st25a' => 0, 'tax' => 0, 'total' => 0, 'rate' => 0 );
		$tax_note = $has_mode ? $modes[ $tax_mode ]['note'] : '';

		$row = array(
			'account_id'   => self::account_for_email( $customer['email'] ),
			'status'       => 'entwurf',
			'customer_json'=> wp_json_encode( $customer ),
			'items_json'   => wp_json_encode( $items ),
			'extras_json'  => wp_json_encode( $extras ),
			'delivery_time'=> $delivery,
			'tax_mode'     => $tax_mode,
			'tax_rate'     => $has_mode ? self::rate_for( $tax_mode, $tax_rate ) : 0,
			'tax_note'     => $tax_note,
			'subtotal_net' => $totals['net'] + $totals['st25a'],
			'tax_amount'   => $totals['tax'],
			'total_gross'  => $totals['total'],
			'currency'     => 'EUR',
			'valid_until'  => null, // Entwurf hat keine Gültigkeit — läuft erst ab Sendedatum
			'src_json'     => wp_json_encode( $src ),
			'sent_at'      => null,
		);

		$draft_id = (int) ( $p['draft_id'] ?? 0 );
		$existing = $draft_id > 0 ? self::get_by_id( $draft_id ) : null;
		if ( $existing && 'entwurf' === (string) $existing->status ) {
			$wpdb->update( self::table(), $row, array( 'id' => $draft_id ) ); // offer_no/token unverändert
			$id = $draft_id;
		} else {
			// Eindeutiger Platzhalter statt '' (UNIQUE-Spalte) — KEIN next_number(), also kein Sequenz-Verbrauch.
			$row['offer_no'] = 'E-' . bin2hex( random_bytes( 8 ) );
			$row['token']    = bin2hex( random_bytes( 16 ) );
			$wpdb->insert( self::table(), $row );
			$id = (int) $wpdb->insert_id;
		}
		self::log( 'draft_saved', $id, '' );

		return rest_ensure_response( array(
			'ok'       => true,
			'draft_id' => $id,
			'edit_url' => add_query_arg( array( self::QV_NEW => 1, 'draft' => $id ), home_url( '/' ) ),
			'message'  => 'Entwurf gespeichert.',
		) );
	}

	/**
	 * Garage → Angebot: aus vorbereiteten Positionen einen Entwurf anlegen (ohne Kunde/Steuer — der Operator
	 * ergänzt beides im Builder). $items = Roh-Positionen (teil_id, title, qty, unit_price [NETTO bzw. §25a-Brutto],
	 * tax25a, variant, thumb). Preis-/§25a-Mapping macht der Aufrufer (Bridge); clean_items erbt tax25a zusätzlich
	 * aus dem Teil. Gibt draft_id + edit_url (?m24_offer_new=1&draft=…) zurück. Kein next_number(), keine Frist.
	 */
	public static function create_garage_draft( array $items ): array {
		global $wpdb;
		$clean = self::clean_items( $items );
		if ( empty( $clean ) ) { return array( 'ok' => false, 'error' => 'Keine gültigen Positionen.' ); }
		$src = self::clean_src( array( 'src_pillar' => 'garage' ) );
		$row = array(
			'account_id'   => 0,
			'status'       => 'entwurf',
			'customer_json'=> wp_json_encode( array() ), // Kunde ergänzt der Operator im Builder
			'items_json'   => wp_json_encode( $clean ),
			'extras_json'  => wp_json_encode( array() ),
			'delivery_time'=> '',
			'tax_mode'     => '', // Steuerfall wählt der Operator im Builder
			'tax_rate'     => 0,
			'tax_note'     => '',
			'subtotal_net' => 0,
			'tax_amount'   => 0,
			'total_gross'  => 0,
			'currency'     => 'EUR',
			'valid_until'  => null, // Entwurf → Frist erst ab Sendedatum (VALID_DAYS)
			'src_json'     => wp_json_encode( $src ),
			'sent_at'      => null,
			'offer_no'     => 'E-' . bin2hex( random_bytes( 8 ) ), // eindeutiger Platzhalter (UNIQUE), KEIN Sequenz-Verbrauch
			'token'        => bin2hex( random_bytes( 16 ) ),
		);
		$wpdb->insert( self::table(), $row );
		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) { return array( 'ok' => false, 'error' => 'Entwurf konnte nicht angelegt werden.' ); }
		self::log( 'draft_from_garage', $id, '' );
		return array( 'ok' => true, 'draft_id' => $id, 'edit_url' => add_query_arg( array( self::QV_NEW => 1, 'draft' => $id ), home_url( '/' ) ) );
	}

	/** Operator-Live-Vorschau: EN-Titel für Katalog-Positionen on-demand (Batch-DeepL, gecacht). {id: en}. */
	public static function handle_en_titles( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		$body = (array) $req->get_json_params();
		$ids  = array();
		foreach ( (array) ( $body['ids'] ?? array() ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 && 'm24_teil' === get_post_type( $id ) ) { $ids[] = $id; }
		}
		$map = ( ! empty( $ids ) && class_exists( 'M24_DeepL' ) ) ? M24_DeepL::en_titles_for( $ids ) : array();
		// Als string-Keys ausgeben (JSON-Objekt); leere Werte → DE-Titel als Fallback, damit die Vorschau nie „fehlt" zeigt.
		$out = array();
		foreach ( $ids as $id ) { $out[ (string) $id ] = (string) ( '' !== ( $map[ $id ] ?? '' ) ? $map[ $id ] : get_the_title( $id ) ); }
		return rest_ensure_response( array( 'ok' => true, 'titles' => $out ) );
	}

	/** #7: EN-Titel-Korrektur einer Katalog-Position dauerhaft in den Artikel schreiben (Override, DeepL-fest). */
	public static function handle_save_en_title( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		$p     = (array) $req->get_json_params();
		$tid   = (int) ( $p['teil_id'] ?? 0 );
		$title = sanitize_text_field( (string) ( $p['title_en'] ?? '' ) );
		if ( $tid <= 0 || 'm24_teil' !== get_post_type( $tid ) ) { return new WP_Error( 'm24off_bad', 'Ungültige Position.', array( 'status' => 400 ) ); }
		if ( '' === $title ) { delete_post_meta( $tid, '_m24_titel_en_manual' ); }
		else { update_post_meta( $tid, '_m24_titel_en_manual', $title ); } // Vorrang vor DeepL (M24_DeepL respektiert das Flag)
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** #11: Mail-HTML + Kunden-Ansicht-HTML aus dem aktuellen, ungespeicherten Stand rendern (kein DB-Write). */
	public static function handle_preview( WP_REST_Request $req ) {
		if ( ! wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'm24off_nonce', 'Sitzung abgelaufen.', array( 'status' => 403 ) );
		}
		$p        = (array) $req->get_json_params();
		$customer = self::clean_customer( (array) ( $p['customer'] ?? array() ) );
		$items    = self::clean_items( (array) ( $p['items'] ?? array() ) );
		$extras   = self::clean_extras( (array) ( $p['extras'] ?? array() ) );
		$tax_mode = (string) ( $p['tax_mode'] ?? '' );
		$modes    = self::tax_modes();
		$has_mode = isset( $modes[ $tax_mode ] );
		$tax_rate = (float) ( $p['tax_rate'] ?? 0 );
		$lang     = ( isset( $p['lang'] ) && 'en' === $p['lang'] ) ? 'en' : 'de';
		if ( 'en' === $lang && class_exists( 'M24_DeepL' ) ) { $items = M24_DeepL::fill_item_en_titles( $items ); }
		$src              = self::clean_src( (array) ( $p['src'] ?? array() ) );
		$src['lang']       = $lang;
		$src['anrede_form'] = ( isset( $p['anrede_form'] ) && 'du' === $p['anrede_form'] ) ? 'du' : 'sie'; // Vorschau: Anredeform mitführen → Einleitung/Betreff folgen der Wahl (nicht Default Sie)
		$src['salutation'] = isset( $p['salutation'] ) ? sanitize_text_field( (string) $p['salutation'] ) : '';
		$src['note']       = isset( $p['note'] ) ? sanitize_textarea_field( (string) $p['note'] ) : '';
		$src['delivery']   = sanitize_text_field( (string) ( $p['delivery_time'] ?? '' ) );
		$totals   = $has_mode ? self::compute_totals( $items, $extras, $tax_mode, $tax_rate, (string) ( $customer['land'] ?? '' ) ) : array( 'net' => 0, 'st25a' => 0, 'tax' => 0, 'total' => 0, 'rate' => 0 );
		$tax_note = $has_mode ? $modes[ $tax_mode ]['note'] : '';

		$o = (object) array(
			'id' => 0, 'offer_no' => self::peek_number(), 'token' => str_repeat( '0', 32 ), 'account_id' => 0, 'status' => 'offen',
			'customer_json' => wp_json_encode( $customer ), 'items_json' => wp_json_encode( $items ), 'extras_json' => wp_json_encode( $extras ),
			'delivery_time' => sanitize_text_field( (string) ( $p['delivery_time'] ?? '' ) ),
			'tax_mode' => $tax_mode, 'tax_rate' => $has_mode ? self::rate_for( $tax_mode, $tax_rate ) : 0, 'tax_note' => $tax_note,
			'subtotal_net' => $totals['net'] + $totals['st25a'], 'tax_amount' => $totals['tax'], 'total_gross' => $totals['total'],
			'currency' => 'EUR', 'valid_until' => gmdate( 'Y-m-d', time() + self::VALID_DAYS * DAY_IN_SECONDS ),
			'src_json' => wp_json_encode( $src ), 'sent_at' => current_time( 'mysql', true ), 'paid_at' => null, 'desk_order_id' => '',
		);

		$mail_html = (string) M24_Offers_Render::mail( $o, true );
		ob_start();
		M24_Offers_Render::customer( $o );
		$cust_html = (string) ob_get_clean();

		return rest_ensure_response( array( 'ok' => true, 'mail_html' => $mail_html, 'customer_html' => $cust_html ) );
	}

	/* ── Sanitizer ──────────────────────────────────────────────────────── */

	private static function clean_customer( array $c ): array {
		return array(
			'name'      => sanitize_text_field( (string) ( $c['name'] ?? '' ) ),
			'email'     => strtolower( sanitize_email( (string) ( $c['email'] ?? '' ) ) ),
			'kundentyp' => in_array( ( $c['kundentyp'] ?? '' ), array( 'b2b', 'b2c' ), true ) ? $c['kundentyp'] : 'b2c',
			'anrede'    => in_array( ( $c['anrede'] ?? '' ), array( 'Herr', 'Frau' ), true ) ? (string) $c['anrede'] : '', // für die Sie-Begrüßung
			'firma'     => sanitize_text_field( (string) ( $c['firma'] ?? '' ) ),
			'land'      => sanitize_text_field( trim( (string) ( $c['land'] ?? '' ) ) ), // #6: Land VERBATIM (ISO/Flagge nur intern abgeleitet)
			// #8: vollen Kontakt-Datensatz im Snapshot mitführen → Draft-Reload/Editor behält alle Felder.
			'vorname'      => sanitize_text_field( (string) ( $c['vorname'] ?? '' ) ),
			'nachname'     => sanitize_text_field( (string) ( $c['nachname'] ?? '' ) ),
			'strasse'      => sanitize_text_field( (string) ( $c['strasse'] ?? '' ) ),
			'adresszusatz' => sanitize_text_field( (string) ( $c['adresszusatz'] ?? '' ) ),
			'plz'          => sanitize_text_field( (string) ( $c['plz'] ?? '' ) ),
			'ort'          => sanitize_text_field( (string) ( $c['ort'] ?? '' ) ),
			'telefon'      => sanitize_text_field( (string) ( $c['telefon'] ?? '' ) ),
			'ustid'        => sanitize_text_field( (string) ( $c['ustid'] ?? '' ) ),
			'eori'         => sanitize_text_field( (string) ( $c['eori'] ?? '' ) ),
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
				'title_de'   => sanitize_text_field( (string) ( $it['title_de'] ?? $title ) ), // v3.1: DE-Titel (Freitext)
				'title_en'   => sanitize_text_field( (string) ( $it['title_en'] ?? '' ) ),      // v3.1: EN-Titel (Katalog/Freitext)
				'title_en_manual' => ! empty( $it['title_en_manual'] ),                        // #2: manuell gesetzt → DeepL überschreibt nie
				'art_nr'     => sanitize_text_field( (string) ( $it['art_nr'] ?? '' ) ),
				'variant'    => sanitize_text_field( (string) ( $it['variant'] ?? '' ) ), // #6: Varianten-Name im Angebot
				'thumb'      => self::item_thumb( (string) ( $it['thumb'] ?? '' ), $teil_id ), // #3: Thumb persistieren (für Entwurf-Reload/Ansicht)
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
	/** #3: Thumb-URL einer Position — gegebene URL, sonst aus dem verknüpften Teil (thumbnail). */
	public static function item_thumb( string $thumb, int $teil_id ): string {
		$thumb = esc_url_raw( trim( $thumb ) );
		if ( '' !== $thumb ) { return $thumb; }
		if ( $teil_id > 0 ) { $u = get_the_post_thumbnail_url( $teil_id, 'thumbnail' ); if ( $u ) { return (string) $u; } }
		return '';
	}

	private static function clean_extras( array $extras ): array {
		$out = array();
		foreach ( $extras as $ex ) {
			$label = sanitize_text_field( (string) ( $ex['label'] ?? '' ) );
			if ( '' === $label ) { continue; }
			$out[] = array(
				'key'      => sanitize_key( (string) ( $ex['key'] ?? '' ) ),
				'label'    => $label,
				'amount'   => round( (float) ( $ex['amount'] ?? 0 ), 2 ),
				'on'       => ! empty( $ex['on'] ),
				'incoterm' => in_array( (string) ( $ex['incoterm'] ?? '' ), array( 'DAP', 'CIF', 'CIP' ), true ) ? (string) $ex['incoterm'] : '', // #8: Snapshot
				'method'   => in_array( (string) ( $ex['method'] ?? '' ), array( 'sea', 'air' ), true ) ? (string) $ex['method'] : '',
				'ship_land' => sanitize_text_field( (string) ( $ex['land'] ?? '' ) ),
			);
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
			array( 'key' => 'verpackung', 'label' => 'Transportsicher verpacken', 'amount' => (float) get_option( 'm24_offer_preset_verpackung', 25 ) ),
			array( 'key' => 'versand',    'label' => 'Versicherter Versand DAP', 'amount' => (float) get_option( 'm24_offer_preset_versand', 49 ) ), // EINE Karte; Versandweg (Luft/See/Land) + Land in der Position
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
	 * „Angebot angesehen"-Tracking: erste Sicht einmalig, letzte Sicht + Zähler bei jedem echten Aufruf.
	 * Der Aufrufer (M24_Offers_Render::customer) schließt Operator/Admin-Preview aus, damit der eigene Blick
	 * die Daten nicht verfälscht. Ein atomares UPDATE (COALESCE + count+1) → kein Race, kein Extra-SELECT.
	 */
	public static function record_view( int $offer_id ): void {
		if ( $offer_id <= 0 ) { return; }
		global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare(
			"UPDATE $t SET viewed_first_at = COALESCE( viewed_first_at, %s ), viewed_last_at = %s, view_count = view_count + 1 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$now, $now, $offer_id
		) );
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
				"SELECT offer_no, token, total_gross, status, created_at, paid_at FROM $t WHERE status <> 'entwurf' AND ( account_id = %d OR ( account_id = 0 AND customer_json LIKE %s ) ) ORDER BY created_at DESC, id DESC LIMIT 200",
				$account_id, $like
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT offer_no, token, total_gross, status, created_at, paid_at FROM $t WHERE status <> 'entwurf' AND account_id = %d ORDER BY created_at DESC, id DESC LIMIT 200",
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

	/* ── 7-Tage-Ablauf (VALID_DAYS, Cron, ohne Stunden) ─────────────────── */

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
		$totals   = self::compute_totals( $items, array(), $tax_mode, 0.0, (string) ( $cust['land'] ?? '' ) );
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
			'status'       => 'entwurf', // Entwurf — Daniel ergänzt + sendet (7-Tage-Ablauf/VALID_DAYS greift beim Senden)
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

	/**
	 * Ablauf-Reminder: einmalige Erinnerungs-Mail ~2 Tage vor Fristende an offene, noch nicht angenommene Angebote.
	 * Robust gegen einen ausgefallenen Cron-Tag: „in 2 Tagen" als TAGESFENSTER (valid_until ∈ [heute+1, heute+2]),
	 * nicht sekundengenau. Einmalig via reminder_sent_at: das Flag wird ATOMAR VOR dem Versand gesetzt (nur wenn NULL)
	 * → kein Doppelversand bei überlappenden Läufen; §7 UWG: transaktional + einmalig, kein Nachfassen.
	 */
	public static function remind_due() {
		if ( ! self::enabled() ) { return; }
		if ( ! (bool) get_option( 'm24_offer_reminder_enabled', 1 ) ) { return; }
		global $wpdb;
		$t    = self::table();
		$from = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );      // heute + 1
		$to   = gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS );  // heute + 2
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM $t WHERE status = 'offen' AND reminder_sent_at IS NULL AND valid_until IS NOT NULL AND valid_until BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$from, $to
		) );
		$now = current_time( 'mysql', true );
		foreach ( (array) $rows as $r ) {
			$oid = (int) $r->id;
			// Flag ZUERST setzen (nur wenn noch NULL) → verhindert Doppelversand; bei Mail-Fehler bewusst kein Retry (§7).
			$claimed = $wpdb->query( $wpdb->prepare(
				"UPDATE $t SET reminder_sent_at = %s WHERE id = %d AND reminder_sent_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now, $oid
			) );
			if ( $claimed && class_exists( 'M24_Offers_Render' ) ) {
				M24_Offers_Render::reminder_mail( $oid );
				self::log( 'reminder_sent', $oid );
			}
		}
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
