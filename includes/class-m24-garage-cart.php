<?php
/**
 * M24 Plattform — „Meine Garage", Etappe 1: kontogebundener Warenkorb.
 *
 * Baut die bestehende „Meine Garage" (M24_Garage = E-Mail/DOI-Merkliste, ohne Menge) NICHT um,
 * sondern ergänzt sie um einen echten, PRO ACCOUNT persistenten Warenkorb mit Menge:
 *
 *  - Account-Auflösung über den BESTEHENDEN Login (B2B-Magic-Link setzt wp_set_auth_cookie →
 *    jeder eingeloggte WP-User, inkl. Admin, ist der aktuelle Account). Helper: current_account_id().
 *  - Saubere, generische Tabelle m24_garage_cart (account_id + post_type + post_id + qty) → später
 *    können auch Fahrzeuge rein, und Etappe 2/3 (Versenden-Link, PDF) lesen Positionen pro Account.
 *  - REST (nonce + Login): add (Re-Add erhöht Menge), remove, qty (absolut setzen), get.
 *  - Garage-Seite via Shortcode [m24_garage]: Bild, Titel, Artikelnummer, Einzelpreis, Menge ±,
 *    Positionssumme, Gesamtsumme, Leerzustand. Preise = öffentliche Brutto-Logik der Teile-Detailseite.
 *  - Schwebe-/Header-Zähler: live die Positionsanzahl ([data-m24-garage-count]).
 *
 * NICHT in Etappe 1: Versenden-Link (Etappe 2) + PDF (Etappe 3) — Datenmodell dockt darüber sauber an.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Garage_Cart {

	const NS            = 'm24/v1';
	const SCHEMA_OPTION = 'm24_garage_cart_schema_v';
	const SCHEMA_VER    = '1';
	const PAGE_OPTION   = 'm24_garage_page_id';
	const PAGE_SLUG     = 'meine-garage';
	const MAX_QTY       = 99;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_ensure_table' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_page' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_counter' ) );
	}

	public static function table() { return M24_Database::table( 'garage_cart' ); }

	/** Generische, unterstützte Positionstypen (post_type). Fahrzeuge sind bewusst schon erlaubt. */
	public static function allowed_types() { return array( 'm24_teil', 'm24_fahrzeug' ); }

	/**
	 * Aktueller Account = eingeloggter WP-User (B2B-Login setzt die WP-Auth-Cookie, Admin zählt mit).
	 * 0 = nicht eingeloggt (Gast).
	 */
	public static function current_account_id(): int {
		return is_user_logged_in() ? (int) get_current_user_id() : 0;
	}

	/* ── Schema (idempotent) ─────────────────────────────────────────────── */

	public static function maybe_ensure_table() {
		if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VER ) {
			return;
		}
		self::ensure_table();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VER );
	}

	public static function ensure_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		$t  = self::table();
		dbDelta( "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id BIGINT UNSIGNED NOT NULL,
			post_type VARCHAR(20) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			qty INT UNSIGNED NOT NULL DEFAULT 1,
			added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_pos (account_id, post_type, post_id),
			KEY idx_account (account_id)
		) {$cc};" );
	}

	/* ── Garage-Seite automatisch bereitstellen (stabile URL für den Zähler) ── */

	public static function maybe_create_page() {
		if ( get_option( self::PAGE_OPTION ) ) {
			return;
		}
		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing ) {
			update_option( self::PAGE_OPTION, (int) $existing->ID );
			return;
		}
		$pid = wp_insert_post( array(
			'post_title'   => 'Meine Garage',
			'post_name'    => self::PAGE_SLUG,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[m24_garage]',
		) );
		if ( $pid && ! is_wp_error( $pid ) ) {
			update_option( self::PAGE_OPTION, (int) $pid );
		}
	}

	public static function page_url(): string {
		$pid = (int) get_option( self::PAGE_OPTION );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return (string) get_permalink( $pid );
		}
		$p = get_page_by_path( self::PAGE_SLUG );
		if ( $p ) {
			return (string) get_permalink( $p );
		}
		return home_url( '/' );
	}

	/* ── REST ────────────────────────────────────────────────────────────── */

	public static function register_routes() {
		register_rest_route( self::NS, '/garage/cart', array(
			'methods'             => 'GET',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_get' ),
		) );
		register_rest_route( self::NS, '/garage/cart/add', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_add' ),
		) );
		register_rest_route( self::NS, '/garage/cart/remove', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_remove' ),
		) );
		register_rest_route( self::NS, '/garage/cart/qty', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check' ),
			'callback'            => array( __CLASS__, 'handle_qty' ),
		) );
	}

	/** Nonce + Login. Ohne Account → 401 (Frontend fällt für Gäste auf die DOI-Merkliste zurück). */
	public static function check( $req ) {
		$n = $req->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $n ) || ! wp_verify_nonce( $n, 'wp_rest' ) ) {
			return new WP_Error( 'm24gc_nonce', 'Sicherheitsprüfung fehlgeschlagen.', array( 'status' => 403 ) );
		}
		if ( self::current_account_id() <= 0 ) {
			return new WP_Error( 'm24gc_auth', 'Bitte einloggen, um deine Garage zu nutzen.', array( 'status' => 401 ) );
		}
		return true;
	}

	/** Validierten (post_id, post_type) aus dem Request ziehen oder WP_Error. */
	private static function resolve_post( WP_REST_Request $req ) {
		$pid = (int) $req->get_param( 'post_id' );
		$pt  = $pid ? (string) get_post_type( $pid ) : '';
		if ( ! $pid || ! in_array( $pt, self::allowed_types(), true ) || 'publish' !== get_post_status( $pid ) ) {
			return new WP_Error( 'm24gc_bad', 'Position unbekannt.', array( 'status' => 400 ) );
		}
		return array( $pid, $pt );
	}

	public static function handle_add( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		$res = self::resolve_post( $req );
		if ( is_wp_error( $res ) ) { return $res; }
		list( $pid, $pt ) = $res;

		global $wpdb;
		$acc = self::current_account_id();
		$t   = self::table();
		$now = current_time( 'mysql' );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, qty FROM $t WHERE account_id = %d AND post_type = %s AND post_id = %d",
			$acc, $pt, $pid
		), ARRAY_A );

		if ( $row ) {
			$qty = min( self::MAX_QTY, (int) $row['qty'] + 1 );
			$wpdb->update( $t, array( 'qty' => $qty, 'updated_at' => $now ), array( 'id' => (int) $row['id'] ) );
		} else {
			$wpdb->insert( $t, array(
				'account_id' => $acc,
				'post_type'  => $pt,
				'post_id'    => $pid,
				'qty'        => 1,
				'added_at'   => $now,
			) );
		}
		return rest_ensure_response( self::state( $acc, $pid, $pt ) );
	}

	public static function handle_qty( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		$res = self::resolve_post( $req );
		if ( is_wp_error( $res ) ) { return $res; }
		list( $pid, $pt ) = $res;

		global $wpdb;
		$acc = self::current_account_id();
		$t   = self::table();
		$qty = (int) $req->get_param( 'qty' );

		if ( $qty <= 0 ) {
			$wpdb->delete( $t, array( 'account_id' => $acc, 'post_type' => $pt, 'post_id' => $pid ) );
			$st = self::state( $acc, $pid, $pt );
			$st['removed'] = true;
			return rest_ensure_response( $st );
		}
		$qty = min( self::MAX_QTY, $qty );
		$wpdb->query( $wpdb->prepare(
			"UPDATE $t SET qty = %d, updated_at = %s WHERE account_id = %d AND post_type = %s AND post_id = %d",
			$qty, current_time( 'mysql' ), $acc, $pt, $pid
		) );
		return rest_ensure_response( self::state( $acc, $pid, $pt ) );
	}

	public static function handle_remove( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		$res = self::resolve_post( $req );
		if ( is_wp_error( $res ) ) { return $res; }
		list( $pid, $pt ) = $res;

		global $wpdb;
		$acc = self::current_account_id();
		$wpdb->delete( self::table(), array( 'account_id' => $acc, 'post_type' => $pt, 'post_id' => $pid ) );
		$st = self::state( $acc, $pid, $pt );
		$st['removed'] = true;
		return rest_ensure_response( $st );
	}

	public static function handle_get( WP_REST_Request $req ) {
		self::maybe_ensure_table();
		$acc   = self::current_account_id();
		$items = self::items( $acc );
		list( , $grand_fmt, $has_unpriced ) = self::grand_total( $items );
		return rest_ensure_response( array(
			'ok'           => true,
			'count'        => count( $items ),
			'items'        => $items,
			'grand_fmt'    => $grand_fmt,
			'has_unpriced' => $has_unpriced,
		) );
	}

	/* ── Lese-/Preis-Helfer ──────────────────────────────────────────────── */

	/** Anzahl Positionen (Zeilen) im Account-Warenkorb. */
	public static function count_positions( int $acc ): int {
		if ( $acc <= 0 ) { return 0; }
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE account_id = %d", $acc ) );
	}

	/**
	 * Einzelpreis (Brutto, EUR) je Position oder null („Preis auf Anfrage" / kein Preis).
	 * Respektiert die bestehende, öffentliche Preis-Logik der Teile-Detailseite (Brutto, _m24_preis_auf_anfrage).
	 */
	private static function unit_price( string $pt, int $pid ): ?float {
		if ( 'm24_teil' === $pt ) {
			if ( get_post_meta( $pid, '_m24_preis_auf_anfrage', true ) ) { return null; }
			if ( class_exists( 'M24_Catalog_Pricing' ) ) {
				$p = M24_Catalog_Pricing::get( $pid );
				return ( $p && ! empty( $p['brutto'] ) && (float) $p['brutto'] > 0 ) ? (float) $p['brutto'] : null;
			}
			return null;
		}
		if ( 'm24_fahrzeug' === $pt ) {
			$v = (int) get_post_meta( $pid, '_m24fz_preis', true );
			return $v > 0 ? (float) $v : null;
		}
		return null;
	}

	private static function fmt( float $v ): string {
		if ( class_exists( 'M24_Catalog_Pricing' ) ) {
			return M24_Catalog_Pricing::format( $v );
		}
		return number_format( $v, 2, ',', '.' ) . ' €';
	}

	private static function artnr( string $pt, int $pid ): string {
		if ( 'm24_teil' === $pt ) {
			return (string) get_post_meta( $pid, '_m24_artikelnummer', true );
		}
		if ( 'm24_fahrzeug' === $pt ) {
			return (string) get_post_meta( $pid, '_m24fz_fin', true );
		}
		return '';
	}

	/** Detaillierte, anzeigefertige Positionsliste (verwaiste/unveröffentlichte Posts werden übersprungen). */
	public static function items( int $acc ): array {
		if ( $acc <= 0 ) { return array(); }
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_type, post_id, qty FROM $t WHERE account_id = %d ORDER BY added_at DESC, id DESC",
			$acc
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$pid = (int) $r['post_id'];
			$pt  = (string) $r['post_type'];
			if ( 'publish' !== get_post_status( $pid ) ) { continue; }
			$qty   = max( 1, (int) $r['qty'] );
			$unit  = self::unit_price( $pt, $pid );
			$line  = ( null !== $unit ) ? $unit * $qty : null;
			$thumb = get_the_post_thumbnail_url( $pid, 'medium' );
			$out[] = array(
				'post_id'       => $pid,
				'post_type'     => $pt,
				'qty'           => $qty,
				'title'         => get_the_title( $pid ),
				'url'           => (string) get_permalink( $pid ),
				'thumb'         => $thumb ? (string) $thumb : '',
				'artnr'         => self::artnr( $pt, $pid ),
				'unit'          => $unit,
				'unit_fmt'      => ( null !== $unit ) ? self::fmt( $unit ) : null,
				'line_total'    => $line,
				'line_fmt'      => ( null !== $line ) ? self::fmt( $line ) : null,
			);
		}
		return $out;
	}

	/** @return array(float $sum, string $sum_fmt, bool $has_unpriced) */
	public static function grand_total( array $items ): array {
		$sum          = 0.0;
		$has_unpriced = false;
		foreach ( $items as $it ) {
			if ( null === $it['line_total'] ) { $has_unpriced = true; continue; }
			$sum += (float) $it['line_total'];
		}
		return array( $sum, self::fmt( $sum ), $has_unpriced );
	}

	/** Antwort-Snapshot nach einer Mutation: Zähler + betroffene Zeile + Gesamtsumme. */
	private static function state( int $acc, int $pid, string $pt ): array {
		global $wpdb;
		$t   = self::table();
		$qty = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT qty FROM $t WHERE account_id = %d AND post_type = %s AND post_id = %d",
			$acc, $pt, $pid
		) );
		$unit     = self::unit_price( $pt, $pid );
		$line     = ( $qty > 0 && null !== $unit ) ? $unit * $qty : null;
		$items    = self::items( $acc );
		list( , $grand_fmt, $has_unpriced ) = self::grand_total( $items );
		return array(
			'ok'           => true,
			'count'        => count( $items ),
			'qty'          => $qty,
			'line_fmt'     => ( null !== $line ) ? self::fmt( $line ) : null,
			'grand_fmt'    => $grand_fmt,
			'has_unpriced' => $has_unpriced,
		);
	}

	/* ── Assets (Zähler-Update + Button-Verdrahtung + Garage-Seite) ───────── */

	public static function assets() {
		if ( is_admin() ) { return; }
		// Site-weit für eingeloggte Accounts (Zähler + Button-Add); für Gäste nur auf der Garage-Seite.
		$on_garage = is_page() && (int) get_queried_object_id() === (int) get_option( self::PAGE_OPTION );
		if ( self::current_account_id() <= 0 && ! $on_garage ) { return; }

		$css = 'assets/css/m24-garage.css';
		$js  = 'assets/js/m24-garage.js';
		$cp  = M24_PLATTFORM_DIR . $css;
		$jp  = M24_PLATTFORM_DIR . $js;
		$cv  = file_exists( $cp ) ? (string) filemtime( $cp ) : M24_PLATTFORM_VERSION;
		$jv  = file_exists( $jp ) ? (string) filemtime( $jp ) : M24_PLATTFORM_VERSION;

		wp_enqueue_style( 'm24-garage', M24_PLATTFORM_URL . $css, array(), $cv );
		wp_enqueue_script( 'm24-garage', M24_PLATTFORM_URL . $js, array(), $jv, true );
		wp_localize_script( 'm24-garage', 'M24GarageCart', array(
			'rest'     => esc_url_raw( rest_url( self::NS . '/garage/cart' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'loggedIn' => self::current_account_id() > 0,
			'pageUrl'  => self::page_url(),
			'i18n'     => array(
				'added'   => 'In deine Garage gelegt.',
				'failed'  => 'Aktion fehlgeschlagen. Bitte später erneut.',
				'login'   => 'Bitte einloggen, um deine Garage zu nutzen.',
			),
		) );
	}

	/* ── Schwebe-/Header-Zähler ──────────────────────────────────────────── */

	public static function render_counter() {
		if ( is_admin() || self::current_account_id() <= 0 ) { return; }
		$count = self::count_positions( self::current_account_id() );
		$url   = esc_url( self::page_url() );
		$empty = $count <= 0 ? ' is-empty' : '';
		?>
		<a class="m24gc-fab<?php echo esc_attr( $empty ); ?>" href="<?php echo $url; ?>" aria-label="Meine Garage">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-6 9 6v10a1 1 0 0 1-1 1h-4v-7H8v7H4a1 1 0 0 1-1-1z"/></svg>
			<span class="m24gc-fab-badge" data-m24-garage-count><?php echo (int) $count; ?></span>
		</a>
		<?php
	}

	/* ── Garage-Seite (Shortcode) ────────────────────────────────────────── */

	public static function register_shortcode() {
		add_shortcode( 'm24_garage', array( __CLASS__, 'shortcode' ) );
	}

	public static function shortcode( $atts = array() ) {
		$acc = self::current_account_id();
		ob_start();

		if ( $acc <= 0 ) {
			?>
			<div class="m24gc-page m24gc-guest">
				<h2 class="m24gc-h">Meine Garage</h2>
				<p class="m24gc-empty">Bitte logge dich ein, um deine Garage zu sehen. Du findest den Login-Link über den „In meine Garage"-Dialog auf jeder Fahrzeug- oder Teile-Seite.</p>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		$items = self::items( $acc );
		list( , $grand_fmt, $has_unpriced ) = self::grand_total( $items );
		?>
		<div class="m24gc-page" data-m24gc-page>
			<h2 class="m24gc-h">Meine Garage</h2>
			<?php if ( empty( $items ) ) : ?>
				<p class="m24gc-empty" data-m24gc-emptystate>Deine Garage ist noch leer. Lege Fahrzeuge oder Teile über „In meine Garage" hinein.</p>
			<?php else : ?>
				<div class="m24gc-list" data-m24gc-list>
					<?php foreach ( $items as $it ) : self::render_row( $it ); endforeach; ?>
				</div>
				<div class="m24gc-summary">
					<span class="m24gc-summary-label">Gesamtsumme</span>
					<span class="m24gc-grand" data-m24gc-grand><?php echo esc_html( $grand_fmt ); ?></span>
				</div>
				<?php if ( $has_unpriced ) : ?>
					<p class="m24gc-note" data-m24gc-note>Einzelne Positionen sind „Preis auf Anfrage" und nicht in der Summe enthalten.</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** Eine Warenkorb-Zeile (auch von JS nach Mutationen wiederverwendbar über die data-Attribute). */
	private static function render_row( array $it ) {
		$pid  = (int) $it['post_id'];
		$pt   = (string) $it['post_type'];
		?>
		<div class="m24gc-row" data-m24gc-row data-post-id="<?php echo esc_attr( $pid ); ?>" data-post-type="<?php echo esc_attr( $pt ); ?>">
			<a class="m24gc-thumb" href="<?php echo esc_url( $it['url'] ); ?>">
				<?php if ( $it['thumb'] ) : ?>
					<img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy">
				<?php else : ?>
					<span class="m24gc-thumb-ph" aria-hidden="true"></span>
				<?php endif; ?>
			</a>
			<div class="m24gc-info">
				<a class="m24gc-title" href="<?php echo esc_url( $it['url'] ); ?>"><?php echo esc_html( $it['title'] ); ?></a>
				<?php if ( '' !== $it['artnr'] ) : ?>
					<span class="m24gc-artnr">Art.-Nr.: <?php echo esc_html( $it['artnr'] ); ?></span>
				<?php endif; ?>
				<span class="m24gc-unit"><?php echo esc_html( null !== $it['unit_fmt'] ? $it['unit_fmt'] : 'Preis auf Anfrage' ); ?></span>
			</div>
			<div class="m24gc-qty" role="group" aria-label="Menge">
				<button type="button" class="m24gc-dec" aria-label="Menge verringern">−</button>
				<span class="m24gc-qty-val" data-m24gc-qty><?php echo (int) $it['qty']; ?></span>
				<button type="button" class="m24gc-inc" aria-label="Menge erhöhen">+</button>
			</div>
			<div class="m24gc-line" data-m24gc-line><?php echo esc_html( null !== $it['line_fmt'] ? $it['line_fmt'] : '—' ); ?></div>
			<button type="button" class="m24gc-remove" aria-label="Position entfernen" data-m24gc-remove>&times;</button>
		</div>
		<?php
	}
}
