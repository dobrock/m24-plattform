<?php
/**
 * M24 Fahrzeug-Alert — Status-Box im Fahrzeug-Editor + Versand (Welle 1, keine Endkunden-Garage).
 *
 * Box (Editor-Seitenleiste auf m24_fahrzeug + Komfort-Maske via Hook m24fz_editor_after_body):
 *   - Zielgruppe = M24_Alert_Taxonomie::tags_for_context() aus den Post-Meta (rollup).
 *   - Zähler + Aufschlüsselung AUS DER SPIEGEL-TABELLE (kein Brevo-Call beim Laden, 5-Min-Transient).
 *   - Versand-Button: bei Entwurf gesperrt; bei publish aktiv → REST.
 *
 * Versand (REST, serverseitig neu berechnet):
 *   - QA-Sicherung: Option m24_alert_test_recipient gesetzt → Kampagne NUR an diese Adresse
 *     (sendTest), Log „TEST". Leer → echter Versand an die Listen (sendNow).
 *   - Re-Send-Schutz via Post-Meta + Transient-Lock. Liste 3 ist nicht das Ziel hier — gesendet
 *     wird an die granularen Alert-Listen aus m24_alert_list_ids (Union, Brevo dedupliziert).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24FZ_Alert_Box {

	const NS           = 'm24/v1';
	const META_AT      = '_m24_alert_gesendet_at';
	const META_COUNT   = '_m24_alert_gesendet_count';
	const META_CAMP    = '_m24_alert_campaign_id';
	const TEST_OPTION  = 'm24_alert_test_recipient';

	public static function init() {
		add_action( 'm24fz_editor_top', array( __CLASS__, 'render_cockpit' ) ); // Komfort-Maske, ganz oben
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function render_cockpit( $id ) {
		echo self::render( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function assets( $hook ) {
		// NUR auf der Komfort-Editor-Seite (m24fz-editor) — nicht global ins Admin bluten.
		if ( ! class_exists( 'M24FZ_Editor_Screen' ) || ! is_string( $hook ) || false === strpos( $hook, M24FZ_Editor_Screen::PAGE ) ) {
			return;
		}
		wp_enqueue_script( 'm24fz-alert', plugins_url( 'assets/fz-alert.js', M24_PLATTFORM_FILE ), array(), M24_PLATTFORM_VERSION, true );
		wp_enqueue_style( 'm24fz-saira', plugins_url( 'assets/fonts/saira.css', M24_PLATTFORM_FILE ), array(), null );
	}

	/**
	 * Fahrzeug-Cockpit (50/50): Gradient-Header + Interessenten-Alert (links, bestehende Logik
	 * 1:1) + Statistik (rechts). Rendert NUR wenn das Fahrzeug online ist (publish & nicht
	 * deaktiviert) — sonst leerer String (kein Modul).
	 */
	public static function render( $id ) {
		if ( ! $id || M24FZ_CPT::PT !== get_post_type( $id ) ) {
			return '';
		}
		// Sichtbarkeit: nur online. Entwurf/auto-draft → publish-Check; deaktiviert → is_disabled.
		if ( 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		if ( class_exists( 'M24FZ_CPT' ) && M24FZ_CPT::is_disabled( $id ) ) {
			return '';
		}

		// ── Interessenten-Alert (bestehende Logik) ──
		$data     = self::recipients( $id );
		$z        = (int) $data['total'];
		$sent_at  = (int) get_post_meta( $id, self::META_AT, true );
		$sent_cnt = (int) get_post_meta( $id, self::META_COUNT, true );
		$test     = trim( (string) get_option( self::TEST_OPTION, '' ) );
		$test_on  = is_email( $test );

		if ( $sent_at ) {
			$btn_label = 'Erneut senden';
		} elseif ( $test_on ) {
			$btn_label = 'Test-Versand an ' . $test;
		} else {
			$btn_label = 'Jetzt an ' . $z . ' Interessenten senden';
		}
		$confirm  = $test_on ? ( 'Test-Versand an ' . $test . '?' ) : ( 'An ' . $z . ' Interessenten senden?' );
		$can_send = ( $test_on || $z > 0 ); // online ist hier garantiert
		$rest     = esc_url_raw( rest_url( self::NS . '/fahrzeug-alert-send' ) );
		$nonce    = wp_create_nonce( 'wp_rest' );

		// ── Statistik ── (alle Quellen identisch zur Inserat-Verwaltung)
		// Tage online + „seit"-Datum: kanonische §3-Quelle (Erstveröffentlichung, NICHT post_date).
		$days_n   = class_exists( 'M24FZ_CPT' ) ? M24FZ_CPT::days_online( $id ) : null;
		$days     = ( null !== $days_n ) ? (int) $days_n : 0;
		$first    = class_exists( 'M24FZ_CPT' ) ? (string) get_post_meta( $id, M24FZ_CPT::FIRST_PUB, true ) : '';
		$first_ts = $first ? strtotime( $first ) : (int) get_post_time( 'U', true, $id );
		$date     = wp_date( 'd.m.Y', $first_ts ?: time() );
		$anfragen = (int) apply_filters( 'm24_cockpit_anfragen_count', (int) get_post_meta( $id, '_m24fz_anfragen_count', true ), $id );
		$aufrufe  = (int) apply_filters( 'm24_cockpit_aufrufe', (int) get_post_meta( $id, '_m24fz_views', true ), $id );
		$show_gar = (bool) apply_filters( 'm24_cockpit_show_garage', true, $id ); // Default sichtbar (Daniel will sie sehen)

		$pill = sprintf(
			/* translators: 1: days, 2: date */
			_n( 'Online seit %1$d Tag · seit %2$s', 'Online seit %1$d Tagen · seit %2$s', $days, 'm24-plattform' ),
			$days,
			$date
		);

		ob_start();
		?>
		<div class="m24-cockpit">
			<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>

			<div class="m24cp-head">
				<div class="m24cp-h-l"><span class="m24cp-dot" aria-hidden="true"></span> Fahrzeug-Cockpit</div>
				<div class="m24cp-pill"><?php echo esc_html( $pill ); ?></div>
			</div>

			<div class="m24cp-body">
				<!-- LINKS: Interessenten-Alert -->
				<div class="m24cp-col m24cp-alert">
					<div class="m24cp-coltitle">Interessenten-Alert</div>
					<div class="m24fz-alertbox" data-post="<?php echo (int) $id; ?>" data-rest="<?php echo esc_attr( $rest ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-confirm="<?php echo esc_attr( $confirm ); ?>">
						<div class="m24cp-num"><?php echo (int) $z; ?></div>
						<div class="m24cp-numlbl">Interessenten</div>

						<?php if ( ! empty( $data['breakdown'] ) ) : ?>
							<div class="m24fz-ab-card">
								<?php foreach ( $data['breakdown'] as $row ) : ?>
									<div class="m24fz-ab-line"><span class="t"><?php echo esc_html( $row['label'] ); ?></span><span class="c"><?php echo (int) $row['count']; ?></span></div>
								<?php endforeach; ?>
							</div>
							<p class="m24fz-ab-foot">
								<?php printf( esc_html__( '%1$d Einträge, %2$d doppelt = %3$d Empfänger', 'm24-plattform' ), (int) $data['entries'], (int) $data['dupes'], (int) $z ); ?>
							</p>
						<?php else : ?>
							<p class="m24fz-ab-foot"><?php echo esc_html__( 'Noch keine passenden Interessenten.', 'm24-plattform' ); ?></p>
						<?php endif; ?>

						<?php if ( $sent_at ) : ?>
							<div class="m24fz-ab-sent"><?php printf( esc_html__( '✓ Bereits am %1$s an %2$d gesendet', 'm24-plattform' ), esc_html( wp_date( 'd.m.Y H:i', $sent_at ) ), (int) $sent_cnt ); ?></div>
						<?php endif; ?>
						<?php if ( $test_on ) : ?>
							<p class="m24fz-ab-test"><?php echo esc_html( sprintf( __( 'QA-Modus: Versand geht NUR an %s (Test).', 'm24-plattform' ), $test ) ); ?></p>
						<?php endif; ?>

						<button type="button" class="m24fz-ab-btn" <?php disabled( ! $can_send ); ?>><?php echo esc_html( $btn_label ); ?></button>
						<p class="m24fz-ab-msg" role="status"></p>
					</div>
				</div>

				<!-- RECHTS: Statistik -->
				<div class="m24cp-col m24cp-stats">
					<div class="m24cp-coltitle">Statistik</div>
					<div class="m24cp-grid">
						<div class="m24cp-tile">
							<span class="m24cp-badge live">LIVE</span>
							<div class="v"><?php echo (int) $anfragen; ?></div>
							<div class="k"><?php esc_html_e( 'Anfragen', 'm24-plattform' ); ?></div>
						</div>
						<div class="m24cp-tile">
							<span class="m24cp-badge live">LIVE</span>
							<div class="v"><?php echo (int) $days; ?></div>
							<div class="k"><?php esc_html_e( 'Tage online', 'm24-plattform' ); ?></div>
							<div class="sub"><?php echo esc_html( sprintf( __( 'seit %s', 'm24-plattform' ), $date ) ); ?></div>
						</div>
						<div class="m24cp-tile">
							<span class="m24cp-badge live">LIVE</span>
							<div class="v"><?php echo (int) $aufrufe; ?></div>
							<div class="k"><?php esc_html_e( 'Aufrufe', 'm24-plattform' ); ?></div>
						</div>
						<?php if ( $show_gar ) : ?>
						<div class="m24cp-tile">
							<span class="m24cp-badge soon"><?php esc_html_e( 'bald', 'm24-plattform' ); ?></span>
							<div class="v">–</div>
							<div class="k"><?php esc_html_e( 'In Garage', 'm24-plattform' ); ?></div>
						</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function css() {
		return ".m24-cockpit{font-family:'Saira',Arial,sans-serif;color:#14161a;max-width:1040px;margin:0 auto 22px;border:1px solid #e6e9ee;border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(20,22,26,.06)}"
			. ".m24-cockpit *{box-sizing:border-box}"
			. ".m24-cockpit .m24cp-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);color:#fff}"
			. ".m24-cockpit .m24cp-h-l{display:flex;align-items:center;gap:9px;font-size:17px;font-weight:700;letter-spacing:.3px}"
			. ".m24-cockpit .m24cp-dot{width:9px;height:9px;border-radius:50%;background:#3fd07a;box-shadow:0 0 0 3px rgba(63,208,122,.25)}"
			. ".m24-cockpit .m24cp-pill{font-size:12px;font-weight:600;background:rgba(255,255,255,.16);padding:5px 12px;border-radius:999px;white-space:nowrap}"
			. ".m24-cockpit .m24cp-body{display:flex;flex-wrap:wrap}"
			. ".m24-cockpit .m24cp-col{flex:1 1 50%;min-width:0;padding:20px 22px}"
			. ".m24-cockpit .m24cp-alert{border-right:1px solid #eef0f3}"
			. ".m24-cockpit .m24cp-coltitle{font-size:12px;text-transform:uppercase;letter-spacing:.6px;color:#5a6474;margin:0 0 12px;font-weight:700}"
			. ".m24-cockpit .m24cp-num{font-size:48px;font-weight:800;line-height:1;color:#9a6b25}"
			. ".m24-cockpit .m24cp-numlbl{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#5a6474;margin:2px 0 12px}"
			. ".m24-cockpit .m24fz-ab-card{background:#f7f8fa;border:1px solid #e6e9ee;border-radius:8px;padding:8px 12px;margin:0 0 8px}"
			. ".m24-cockpit .m24fz-ab-line{display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px solid #eef0f3}"
			. ".m24-cockpit .m24fz-ab-line:last-child{border-bottom:0}"
			. ".m24-cockpit .m24fz-ab-line .c{font-weight:700;color:#14161a}"
			. ".m24-cockpit .m24fz-ab-foot{font-size:12px;color:#5a6474;margin:0 0 12px}"
			. ".m24-cockpit .m24fz-ab-sent{background:#edf7f1;border:1px solid #1a7a3c;color:#1a7a3c;border-radius:6px;padding:7px 10px;font-size:12px;font-weight:600;margin:0 0 10px}"
			. ".m24-cockpit .m24fz-ab-test{background:#fdf5e6;border:1px solid #b87000;color:#8a5a00;border-radius:6px;padding:7px 10px;font-size:12px;margin:0 0 10px}"
			. ".m24-cockpit .m24fz-ab-btn{width:100%;background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);color:#fff;border:0;border-radius:8px;padding:12px 14px;font-size:14px;font-weight:700;cursor:pointer}"
			. ".m24-cockpit .m24fz-ab-btn:disabled{background:#c8ccd2;cursor:not-allowed}"
			. ".m24-cockpit .m24fz-ab-msg{font-size:13px;margin:10px 0 0}"
			. ".m24-cockpit .m24fz-ab-msg.ok{color:#1a7a3c;font-weight:600}.m24-cockpit .m24fz-ab-msg.fail{color:#c8102e;font-weight:600}"
			. ".m24-cockpit .m24cp-stats{background:#f4f6f8}"
			. ".m24-cockpit .m24cp-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}"
			. ".m24-cockpit .m24cp-tile{position:relative;background:#fff;border:1px solid #e6e9ee;border-radius:10px;padding:16px 14px 14px;text-align:center;box-shadow:0 1px 2px rgba(20,22,26,.05)}"
			. ".m24-cockpit .m24cp-tile .v{font-size:30px;font-weight:800;line-height:1;color:#9a6b25}"
			. ".m24-cockpit .m24cp-tile .k{font-size:12px;text-transform:uppercase;letter-spacing:.4px;color:#5a6474;margin-top:6px}"
			. ".m24-cockpit .m24cp-tile .sub{font-size:11px;color:#9aa3b0;margin-top:3px}"
			. ".m24-cockpit .m24cp-badge{position:absolute;top:8px;right:8px;font-size:9px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:2px 6px;border-radius:6px}"
			. ".m24-cockpit .m24cp-badge.live{background:#e7f4ec;color:#1a7a3c}"
			. ".m24-cockpit .m24cp-badge.soon{background:#eef0f3;color:#9aa3b0}"
			. "@media(max-width:640px){.m24-cockpit .m24cp-col{flex:1 1 100%}.m24-cockpit .m24cp-alert{border-right:0;border-bottom:1px solid #eef0f3}.m24-cockpit .m24cp-pill{white-space:normal}}";
	}

	/* ── Zielgruppe / Zähler (Spiegel-Tabelle) ───────────────────────────── */

	/**
	 * Zielgruppe + dedup. @return array tags[], total, breakdown[{tag,label,count}], entries, dupes
	 */
	public static function recipients( $id ) {
		$tags = class_exists( 'M24_Alert_Taxonomie' ) ? M24_Alert_Taxonomie::tags_for_context( (int) $id ) : array();
		$out  = array( 'tags' => $tags, 'total' => 0, 'breakdown' => array(), 'entries' => 0, 'dupes' => 0 );
		if ( empty( $tags ) ) {
			return $out;
		}

		$ckey   = 'm24_alert_cnt_' . (int) $id;
		$cached = get_transient( $ckey );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$main = M24_Database::table( 'il_interessenten' );
		$rel  = M24_Database::table( 'il_interessenten_tags' );
		$ph   = implode( ',', array_fill( 0, count( $tags ), '%s' ) );

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT t.email) FROM $rel t JOIN $main i ON i.email = t.email WHERE i.status = 'aktiv' AND t.tag IN ($ph)",
			$tags
		) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.tag AS tag, COUNT(DISTINCT t.email) AS c FROM $rel t JOIN $main i ON i.email = t.email WHERE i.status = 'aktiv' AND t.tag IN ($ph) GROUP BY t.tag",
			$tags
		), ARRAY_A );

		$by_tag = array();
		foreach ( (array) $rows as $r ) {
			$by_tag[ $r['tag'] ] = (int) $r['c'];
		}

		$tax     = class_exists( 'M24_Alert_Taxonomie' ) ? M24_Alert_Taxonomie::tags() : array();
		$entries = 0;
		$bd      = array();
		foreach ( $tags as $slug ) {
			$c        = isset( $by_tag[ $slug ] ) ? (int) $by_tag[ $slug ] : 0;
			$entries += $c;
			$bd[]     = array( 'tag' => $slug, 'label' => isset( $tax[ $slug ] ) ? $tax[ $slug ]['label'] : $slug, 'count' => $c );
		}

		$out['total']     = $total;
		$out['breakdown'] = $bd;
		$out['entries']   = $entries;
		$out['dupes']     = max( 0, $entries - $total );

		set_transient( $ckey, $out, 5 * MINUTE_IN_SECONDS );
		return $out;
	}

	/* ── Versand (REST) ──────────────────────────────────────────────────── */

	public static function register_rest() {
		register_rest_route( self::NS, '/fahrzeug-alert-send', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'perm' ),
			'callback'            => array( __CLASS__, 'handle_send' ),
		) );
		/*
		 * QA-Modus schalten (Daniel, 14.11.2026: „Bitte sende diese Interessenten-Mail vorerst nur an die
		 * service@motorsport24.de, damit ich mir das Ergebnis einmal angucken kann."). Bisher ließ sich die Option
		 * m24_alert_test_recipient nur in der Datenbank setzen. POST { "adresse": "…" } schaltet den Testmodus ein,
		 * eine leere Adresse schaltet ihn aus. Nur Administratoren.
		 */
		// Sprache vorhandener Interessenten setzen: { "zuordnung": { "mail@…": "en", … } }. Nur Administratoren.
		register_rest_route( self::NS, '/il-sprache', array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) {
				$n = $req->get_header( 'X-WP-Nonce' );
				return is_string( $n ) && wp_verify_nonce( $n, 'wp_rest' ) && current_user_can( 'manage_options' );
			},
			'callback'            => function ( WP_REST_Request $req ) {
				$erg = array();
				foreach ( (array) $req->get_param( 'zuordnung' ) as $mail => $sp ) { $erg[] = M24_Brevo_IL::set_language( (string) $mail, (string) $sp ); }
				return rest_ensure_response( array( 'ok' => true, 'listen' => M24_Brevo_Client::lang_list_ids( false ), 'ergebnis' => $erg ) );
			},
		) );
		// Absender des Alerts: Liste der Brevo-Absender lesen und einen aktiven festlegen. Nur Administratoren.
		register_rest_route( self::NS, '/alert-absender', array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) {
				$n = $req->get_header( 'X-WP-Nonce' );
				return is_string( $n ) && wp_verify_nonce( $n, 'wp_rest' ) && current_user_can( 'manage_options' );
			},
			'callback'            => function ( WP_REST_Request $req ) {
				$res   = M24_Brevo_Client::senders();
				$liste = array();
				foreach ( (array) ( $res['data']['senders'] ?? array() ) as $s ) {
					$liste[] = array( 'name' => (string) ( $s['name'] ?? '' ), 'email' => (string) ( $s['email'] ?? '' ), 'aktiv' => ! empty( $s['active'] ) );
				}
				$adr = sanitize_email( (string) $req->get_param( 'adresse' ) );
				$gesetzt = false;
				if ( '' !== $adr ) {
					foreach ( $liste as $s ) { if ( $s['aktiv'] && 0 === strcasecmp( $s['email'], $adr ) ) { update_option( 'm24_alert_sender_email', $adr, false ); $gesetzt = true; } }
				}
				return rest_ensure_response( array( 'ok' => ! empty( $res['ok'] ), 'meldung' => (string) ( $res['msg'] ?? '' ), 'absender' => $liste, 'gesetzt' => $gesetzt, 'aktuell' => (string) get_option( 'm24_alert_sender_email', '' ) ) );
			},
		) );
		register_rest_route( self::NS, '/alert-testempfaenger', array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) {
				$n = $req->get_header( 'X-WP-Nonce' );
				return is_string( $n ) && wp_verify_nonce( $n, 'wp_rest' ) && current_user_can( 'manage_options' );
			},
			'callback'            => function ( WP_REST_Request $req ) {
				$adr = sanitize_email( (string) $req->get_param( 'adresse' ) );
				if ( '' === $adr ) { delete_option( self::TEST_OPTION ); } else { update_option( self::TEST_OPTION, $adr, false ); }
				return rest_ensure_response( array( 'ok' => true, 'testmodus' => '' !== $adr, 'adresse' => $adr ) );
			},
		) );
	}

	public static function perm( $req ) {
		$n = $req->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $n ) || ! wp_verify_nonce( $n, 'wp_rest' ) ) {
			return new WP_Error( 'm24_nonce', 'Sicherheitsprüfung fehlgeschlagen.', array( 'status' => 403 ) );
		}
		return current_user_can( 'edit_posts' );
	}

	public static function handle_send( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'post_id' );
		if ( ! $id || M24FZ_CPT::PT !== get_post_type( $id ) ) {
			return new WP_Error( 'm24_bad', 'Fahrzeug unbekannt.', array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'm24_cap', 'Keine Berechtigung.', array( 'status' => 403 ) );
		}
		// Status serverseitig (Client nicht trauen).
		if ( 'publish' !== get_post_status( $id ) ) {
			return new WP_Error( 'm24_draft', 'Erst veröffentlichen, dann senden.', array( 'status' => 409 ) );
		}
		if ( ! M24_Brevo_Client::is_configured() ) {
			return new WP_Error( 'm24_key', 'Brevo API-Key nicht gesetzt.', array( 'status' => 409 ) );
		}

		// Idempotenz: kurzer Lock gegen Doppel-Fire.
		$lock = 'm24_alert_lock_' . $id;
		if ( get_transient( $lock ) ) {
			return new WP_Error( 'm24_lock', 'Versand läuft bereits. Bitte einen Moment warten.', array( 'status' => 429 ) );
		}
		set_transient( $lock, 1, 60 );

		// Empfänger/Listen serverseitig neu berechnen.
		$data     = self::recipients( $id );
		$tags     = (array) $data['tags'];
		$list_ids = M24_Brevo_Client::alert_list_ids_for_tags( $tags );

		$sender = self::sender( $id );
		if ( '' === (string) $sender['email'] ) {
			delete_transient( $lock );
			return new WP_Error( 'm24_sender', 'Kein Absender konfiguriert.', array( 'status' => 409 ) );
		}

		$test    = trim( (string) get_option( self::TEST_OPTION, '' ) );
		$test_on = is_email( $test );

		/*
		 * NACH SPRACHE GETRENNT (Daniel, 14.11.2026: „damit die Leute … ihre Alerts in der richtigen Sprache bekommen").
		 * Es gibt zwei Sprachlisten in Brevo; jeder Interessent steht in genau einer. Die deutsche Kampagne geht an
		 * die Ziel-Listen und schließt die EN-Liste aus, die englische schließt die DE-Liste aus. Fehlen die
		 * Sprachlisten, bleibt es beim bisherigen einen (deutschen) Versand.
		 */
		$sprachlisten = M24_Brevo_Client::lang_list_ids( true );
		$sprachen     = $sprachlisten ? array( 'de', 'en' ) : array( 'de' );
		$je_sprache   = self::lang_counts( $tags );

		// TESTMODUS: beide Fassungen über den Mailversand der Seite an die Testadresse — keine Kampagne in Brevo,
		// kein Re-Send-Vermerk. (Brevo verschickt Testmails nur an Adressen, die dort Kontakt einer Liste sind.)
		if ( $test_on ) {
			delete_transient( $lock );
			$ok = 0;
			foreach ( $sprachen as $sp ) {
				$t = self::build_teaser( $id, $tags, $sp );
				if ( wp_mail( $test, '[TEST ' . strtoupper( $sp ) . '] ' . (string) $t['subject'], (string) $t['html'], array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $sender['name'] . ' <' . $sender['email'] . '>' ) ) ) { $ok++; }
			}
			if ( ! $ok ) { return new WP_Error( 'm24_test', 'Test-Versand fehlgeschlagen.', array( 'status' => 502 ) ); }
			M24_Logger::warning( 'brevo', 'Alert gesendet [TEST]: Inserat #' . $id . ', nur an ' . M24_Brevo_Client::mask_email( $test ), array( 'mode' => 'TEST', 'sprachen' => $sprachen, 'tags' => $tags ) );
			return rest_ensure_response( array( 'ok' => true, 'test' => true, 'weg' => 'seite', 'sprachen' => $sprachen, 'message' => 'Test-Versand an ' . $test . ' (' . implode( ' + ', array_map( 'strtoupper', $sprachen ) ) . ').' ) );
		}

		// Echter Versand.
		if ( empty( $list_ids ) ) {
			delete_transient( $lock );
			return new WP_Error( 'm24_nolists', 'Keine Ziel-Listen vorhanden — bitte erst „Alert-Listen anlegen/prüfen".', array( 'status' => 409 ) );
		}
		$gesendet = array();
		$fehler   = array();
		foreach ( $sprachen as $sp ) {
			// Eine Sprache ohne Empfänger überspringen (Brevo lehnt den Versand an null Kontakte ab).
			if ( $sprachlisten && empty( $je_sprache[ $sp ] ) ) { continue; }
			$t    = self::build_teaser( $id, $tags, $sp );
			$excl = $sprachlisten ? array( (int) $sprachlisten[ 'en' === $sp ? 'de' : 'en' ] ) : array();
			$name = 'M24 Alert #' . $id . ' ' . strtoupper( $sp ) . ' — ' . current_time( 'Y-m-d H:i' );
			$camp = M24_Brevo_Client::create_campaign( $name, $t['subject'], $t['html'], $list_ids, $sender, $excl );
			if ( ! $camp['ok'] ) {
				$fehler[] = strtoupper( $sp ) . ': ' . $camp['msg'];
				M24_Logger::error( 'brevo', 'Alert: Kampagne anlegen fehlgeschlagen (Inserat #' . $id . ', ' . $sp . ')', array( 'msg' => $camp['msg'] ) );
				continue;
			}
			$send = M24_Brevo_Client::send_campaign_now( $camp['id'] );
			if ( ! $send['ok'] ) {
				$fehler[] = strtoupper( $sp ) . ': ' . $send['msg'];
				M24_Logger::error( 'brevo', 'Alert sendNow fehlgeschlagen (Inserat #' . $id . ', ' . $sp . ')', array( 'campaign' => $camp['id'], 'msg' => $send['msg'] ) );
				continue;
			}
			$gesendet[ $sp ] = array( 'campaign' => (int) $camp['id'], 'count' => $sprachlisten ? (int) $je_sprache[ $sp ] : (int) $data['total'] );
		}
		delete_transient( $lock );
		if ( ! $gesendet ) {
			return new WP_Error( 'm24_send', 'Versand fehlgeschlagen: ' . implode( ' · ', $fehler ), array( 'status' => 502 ) );
		}

		$now   = time();
		$summe = (int) array_sum( wp_list_pluck( $gesendet, 'count' ) );
		$erste = reset( $gesendet );
		update_post_meta( $id, self::META_AT, $now );
		update_post_meta( $id, self::META_COUNT, (int) get_post_meta( $id, self::META_COUNT, true ) + $summe );
		update_post_meta( $id, self::META_CAMP, (int) $erste['campaign'] );

		M24_Logger::info( 'brevo', 'Alert gesendet: Inserat #' . $id . ', ' . $summe . ' Empfänger', array(
			'recipients' => $summe,
			'listIds'    => array_values( $list_ids ),
			'tags'       => $tags,
			'sprachen'   => $gesendet,
			'fehler'     => $fehler,
		) );

		return rest_ensure_response( array(
			'ok'       => true,
			'test'     => false,
			'sent_at'  => wp_date( 'd.m.Y H:i', $now ),
			'count'    => $summe,
			'sprachen' => $gesendet,
			'fehler'   => $fehler,
			'message'  => 'An ' . $summe . ' Interessenten gesendet.' . ( $fehler ? ' Fehler: ' . implode( ' · ', $fehler ) : '' ),
		) );
	}

	/** Aktive Interessenten der Ziel-Tags je Sprache (Spiegel-Tabelle). @return array ['de'=>int,'en'=>int] */
	public static function lang_counts( $tags ) {
		$out = array( 'de' => 0, 'en' => 0 );
		if ( empty( $tags ) ) { return $out; }
		global $wpdb;
		$main = M24_Database::table( 'il_interessenten' );
		$rel  = M24_Database::table( 'il_interessenten_tags' );
		$ph   = implode( ',', array_fill( 0, count( $tags ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT i.sprache AS s, COUNT(DISTINCT t.email) AS c FROM $rel t JOIN $main i ON i.email = t.email WHERE i.status = 'aktiv' AND t.tag IN ($ph) GROUP BY i.sprache",
			$tags
		), ARRAY_A );
		foreach ( (array) $rows as $r ) { $out[ 'en' === strtolower( (string) $r['s'] ) ? 'en' : 'de' ] += (int) $r['c']; }
		return $out;
	}

	/* ── Absender & Teaser ───────────────────────────────────────────────── */

	private static function sender( $id ) {
		$host = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) {
			$host = 'motorsport24.de';
		}
		// Fester Alert-Absender (Option m24_alert_sender_email), falls gesetzt: Brevo nimmt nur Absender an, die dort
		// angelegt und bestätigt sind — noreply@… war es am 14.11.2026 nicht („Sender is invalid / inactive").
		$fest = sanitize_email( (string) get_option( 'm24_alert_sender_email', '' ) );
		return apply_filters( 'm24_alert_sender', array(
			'name'  => 'MOTORSPORT24',
			'email' => is_email( $fest ) ? $fest : apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host ),
		), $id );
	}

	/**
	 * Mail-Teaser (HTML, CI). Auto-befüllt aus dem Fahrzeug.
	 * @return array ['subject'=>string,'html'=>string]
	 */
	public static function build_teaser( $id, $tags, $lang = 'de' ) {
		$en = 'en' === $lang;
		// Beschriftungen je Sprache. Titel, Kurztext und Punkte der englischen Fassung: siehe Übersetzung weiter unten.
		$L = $en
			? array( 'neu' => 'New at MOTORSPORT24: ', 'kicker' => 'New in stock', 'Baujahr' => 'Year', 'Leistung' => 'Power', 'Laufleistung' => 'Mileage', 'Hubraum' => 'Displacement', 'Getriebe' => 'Transmission', 'Laufleistung Motor' => 'Engine running time', 'anfrage' => 'Price on request', 'knopf' => 'View vehicle', 'weil' => 'You are receiving this e-mail because you subscribed to “%s”.', 'ab1' => 'No longer interested? ', 'ab2' => 'Unsubscribe here', 'std' => 'our vehicle alerts' )
			: array( 'neu' => 'Neu bei MOTORSPORT24: ', 'kicker' => 'Neu im Bestand', 'Baujahr' => 'Baujahr', 'Leistung' => 'Leistung', 'Laufleistung' => 'Laufleistung', 'Hubraum' => 'Hubraum', 'Getriebe' => 'Getriebe', 'Laufleistung Motor' => 'Laufleistung Motor', 'anfrage' => 'Preis auf Anfrage', 'knopf' => 'Fahrzeug ansehen', 'weil' => 'Sie erhalten diese E-Mail, weil Sie „%s" abonniert haben.', 'ab1' => 'Kein Interesse mehr? ', 'ab2' => 'Hier abmelden', 'std' => 'unsere Fahrzeug-Benachrichtigungen' );
		$getr_en = array( 'Sequentiell' => 'Sequential', 'Manuell' => 'Manual', 'Automatik' => 'Automatic' );
		$title   = get_the_title( $id );
		$url     = get_permalink( $id );
		$img     = get_the_post_thumbnail_url( $id, 'large' );
		$subject = $L['neu'] . $title;

		// Key-Facts.
		$facts = array();
		$bj    = (int) get_post_meta( $id, '_m24fz_baujahr', true );
		if ( $bj > 0 ) { $facts[ $L['Baujahr'] ] = (string) $bj; }
		$ps = trim( (string) get_post_meta( $id, '_m24fz_leistung_ps', true ) );
		if ( '' !== $ps ) { $facts[ $L['Leistung'] ] = $ps . ' PS'; }
		$km = (int) get_post_meta( $id, '_m24fz_laufleistung', true );
		if ( $km > 0 ) { $facts[ $L['Laufleistung'] ] = number_format( $km, 0, $en ? '.' : ',', $en ? ',' : '.' ) . ' km'; }

		/*
		 * MEHR INHALT (Daniel, 14.11.2026: „Die Mail ist etwas mager … ein paar Eckdaten … zwei Zeilen aus dem Text",
		 * gewählt: Vorschlag A). Zusätzlich Hubraum, Getriebe und die Laufzeit des Motors als Eckdaten (höchstens fünf
		 * Zeilen), darüber der Anfang der Zusammenfassung, darunter bis zu drei Punkte aus „Auf einen Blick".
		 * Es steht nur, was am Fahrzeug gepflegt ist — leere Felder entfallen.
		 */
		$hub = trim( (string) get_post_meta( $id, '_m24fz_hubraum', true ) );
		if ( '' !== $hub ) { $facts[ $L['Hubraum'] ] = $en ? str_replace( ',', '.', $hub ) : $hub; }
		$getr = trim( (string) get_post_meta( $id, '_m24fz_getriebe', true ) );
		if ( '' !== $getr ) { $facts[ $L['Getriebe'] ] = $en ? ( $getr_en[ $getr ] ?? $getr ) : $getr; }
		$lm = trim( (string) get_post_meta( $id, '_m24fz_lauf_motor', true ) );
		if ( '' !== $lm && class_exists( 'M24FZ_Telemetry' ) ) {
			$lv = M24FZ_Telemetry::lauf_value( $lm, (string) get_post_meta( $id, '_m24fz_lauf_motor_unit', true ) );
			if ( '' !== $lv ) { $facts[ $L['Laufleistung Motor'] ] = $lv; }
		}
		$facts = array_slice( $facts, 0, 5, true );

		$intro = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) get_post_meta( $id, '_m24fz_zusammenfassung', true ) ) ) );
		if ( mb_strlen( $intro ) > 190 ) {
			$kurz  = mb_substr( $intro, 0, 190 );
			$punkt = max( (int) mb_strrpos( $kurz, '. ' ), (int) mb_strrpos( $kurz, ': ' ) );
			$intro = $punkt > 60 ? mb_substr( $kurz, 0, $punkt + 1 ) : rtrim( mb_substr( $kurz, 0, (int) mb_strrpos( $kurz, ' ' ) ), ' ,;:' ) . ' …';
			$intro = rtrim( $intro, ':' );
		}
		$punkte = array_slice( array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) get_post_meta( $id, '_m24fz_keyfacts', true ) ) ) ) ), 0, 3 );
		/*
		 * ENGLISCHE FASSUNG GLEICH AUSFÜHRLICH (Daniel, 14.11.2026: „Bei der englischen Version ist die Beschreibung
		 * nicht so ausführlich wie bei der deutschen"). Titel, Kurztext und Punkte werden per DeepL übersetzt und am
		 * Fahrzeug gemerkt (_m24fz_alert_en, Hash der deutschen Vorlage) — neu übersetzt wird nur, wenn sich der
		 * deutsche Text ändert. Ohne DeepL-Schlüssel oder bei Fehler: wie bisher deutscher Titel, ohne Kurztext/Punkte.
		 */
		if ( $en ) {
			$quelle = array_merge( array( $title, $intro ), $punkte );
			$hash   = md5( wp_json_encode( $quelle ) );
			$merk   = get_post_meta( $id, '_m24fz_alert_en', true );
			$uebers = ( is_array( $merk ) && ( $merk['src'] ?? '' ) === $hash && is_array( $merk['t'] ?? null ) ) ? $merk['t'] : null;
			if ( null === $uebers && class_exists( 'M24_DeepL' ) && M24_DeepL::is_configured() ) {
				$idx = array_keys( array_filter( $quelle, 'strlen' ) );
				$res = M24_DeepL::translate_batch( array_values( array_intersect_key( $quelle, array_flip( $idx ) ) ) );
				if ( is_array( $res ) ) {
					$uebers = array_fill( 0, count( $quelle ), '' );
					foreach ( $idx as $n => $i ) { $uebers[ $i ] = trim( (string) $res[ $n ] ); }
					update_post_meta( $id, '_m24fz_alert_en', array( 'src' => $hash, 't' => $uebers ) );
				}
			}
			if ( is_array( $uebers ) && count( $uebers ) === count( $quelle ) && '' !== $uebers[0] ) {
				$title   = $uebers[0];
				$intro   = $uebers[1];
				$punkte  = array_values( array_filter( array_slice( $uebers, 2 ), 'strlen' ) );
				$subject = $L['neu'] . $title;
			} else {
				$intro  = '';
				$punkte = array();
			}
		}

		// Preis.
		if ( (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true ) ) {
			$preis = $L['anfrage'];
		} else {
			$p = (int) get_post_meta( $id, '_m24fz_preis', true );
			if ( $p > 0 ) {
				$cur   = class_exists( 'M24FZ_Telemetry' ) ? M24FZ_Telemetry::currency_symbol( get_post_meta( $id, '_m24fz_waehrung', true ) ) : '€';
				$preis = $en ? $cur . ' ' . number_format( $p, 0, '.', ',' ) : number_format( $p, 0, ',', '.' ) . ' ' . $cur;
			} else {
				$preis = $L['anfrage'];
			}
		}

		// Tag-Label für die Fußzeile (spezifischster = erster Tag).
		$tax       = class_exists( 'M24_Alert_Taxonomie' ) ? M24_Alert_Taxonomie::tags() : array();
		$first     = ! empty( $tags ) ? reset( $tags ) : '';
		$tag_label = ( $first && isset( $tax[ $first ] ) ) ? $tax[ $first ]['label'] : $L['std'];

		$font_url = plugins_url( 'assets/fonts/saira-latin.woff2', M24_PLATTFORM_FILE );
		$stack    = "font-family:'Saira', Arial, Helvetica, sans-serif;";

		$facts_html = '';
		foreach ( $facts as $k => $v ) {
			$facts_html .= '<tr><td style="padding:4px 0;color:#5a6474;font-size:13px;">' . esc_html( $k ) . '</td>'
				. '<td style="padding:4px 0;text-align:right;font-weight:600;font-size:13px;color:#14161a;">' . esc_html( $v ) . '</td></tr>';
		}

		$punkte_html = '';
		foreach ( $punkte as $pk ) {
			$punkte_html .= '<p style="margin:0 0 6px;font-size:14px;line-height:1.45;color:#3d4a60;"><span style="color:#9a7b3f;font-weight:700;">&#10003;</span>&nbsp; ' . esc_html( $pk ) . '</p>';
		}
		if ( '' !== $punkte_html ) { $punkte_html .= '<div style="height:10px;line-height:10px;font-size:0;">&nbsp;</div>'; }

		$html = '<!DOCTYPE html><html lang="' . ( $en ? 'en' : 'de' ) . '"><head><meta charset="utf-8">'
			. '<style>@font-face{font-family:\'Saira\';src:url(\'' . esc_url( $font_url ) . '\') format(\'woff2\');font-weight:100 900;font-style:normal;font-display:swap;}'
			. 'body,table,td,h1,div,a,p{' . $stack . '}</style></head>'
			. '<body style="margin:0;padding:0;background:#f2f4f7;' . $stack . '">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:0;"><tr><td align="center" style="padding:24px 16px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;">'
			. '<tr><td style="background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);padding:16px 24px;text-align:right;">'
			. '<span style="color:#fff;font-weight:700;letter-spacing:1px;font-size:14px;">MOTORSPORT24</span></td></tr>';

		if ( $img ) {
			$html .= '<tr><td style="padding:0;"><a href="' . esc_url( $url ) . '"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( $title ) . '" width="600" style="display:block;width:100%;height:auto;border:0;"></a></td></tr>';
		}

		$html .= '<tr><td style="padding:22px 24px 8px;">'
			. '<p style="margin:0 0 4px;color:#9a7b3f;font-size:12px;letter-spacing:.5px;text-transform:uppercase;">' . esc_html( $L['kicker'] ) . '</p>'
			. '<h1 style="margin:0 0 14px;font-size:22px;line-height:1.25;color:#10243a;">' . esc_html( $title ) . '</h1>'
			. ( '' !== $intro ? '<p style="margin:0 0 14px;font-size:14px;line-height:1.55;color:#3d4a60;">' . esc_html( $intro ) . '</p>' : '' )
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #eef0f3;border-bottom:1px solid #eef0f3;margin:0 0 14px;">' . $facts_html . '</table>'
			. $punkte_html
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 6px;"><tr>'
			. '<td style="font-size:22px;font-weight:800;color:#9a7b3f;white-space:nowrap;">' . esc_html( $preis ) . '</td>'
			. '<td style="text-align:right;">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);color:#fff;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:8px;font-size:15px;white-space:nowrap;">' . esc_html( $L['knopf'] ) . '</a>'
			. '</td></tr></table>'
			. '</td></tr>'
			. '<tr><td style="padding:14px 24px;border-top:1px solid #e6e9ee;font-size:11px;color:#9aa3b0;line-height:1.6;">'
			. esc_html( sprintf( $L['weil'], $tag_label ) ) . '<br>'
			. esc_html( $L['ab1'] ) . '<a href="{{ unsubscribe }}" style="color:#1f74c4;">' . esc_html( $L['ab2'] ) . '</a>. · MOTORSPORT24 GmbH'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		return array( 'subject' => $subject, 'html' => $html );
	}
}
