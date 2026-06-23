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
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_box' ) );
		add_action( 'm24fz_editor_after_body', array( __CLASS__, 'render_in_comfort' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ── Box-Registrierung & Render ──────────────────────────────────────── */

	public static function register_box() {
		add_meta_box( 'm24fz-alert', 'M24 Fahrzeug-Alert', array( __CLASS__, 'box_cb' ), M24FZ_CPT::PT, 'side', 'high' );
	}

	public static function box_cb( $post ) {
		echo self::render( (int) $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function render_in_comfort( $id ) {
		echo self::render( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function assets( $hook ) {
		// Klassisch/Gutenberg: aktueller Screen gehört zum CPT.
		$on_cpt = false;
		if ( function_exists( 'get_current_screen' ) ) {
			$scr    = get_current_screen();
			$on_cpt = $scr && isset( $scr->post_type ) && M24FZ_CPT::PT === $scr->post_type;
		}
		// Komfort-Maske: Seiten-Hook enthält den Editor-Slug.
		$on_comfort = class_exists( 'M24FZ_Editor_Screen' ) && is_string( $hook ) && false !== strpos( $hook, M24FZ_Editor_Screen::PAGE );

		if ( ! $on_cpt && ! $on_comfort ) {
			return;
		}
		wp_enqueue_script( 'm24fz-alert', plugins_url( 'assets/fz-alert.js', M24_PLATTFORM_FILE ), array(), M24_PLATTFORM_VERSION, true );
		wp_enqueue_style( 'm24fz-saira', plugins_url( 'assets/fonts/saira.css', M24_PLATTFORM_FILE ), array(), null );
	}

	/**
	 * Box-HTML. Daten aus der Spiegel-Tabelle; rest/nonce als data-Attribute → fz-alert.js liest sie.
	 */
	public static function render( $id ) {
		if ( ! $id || M24FZ_CPT::PT !== get_post_type( $id ) ) {
			return '';
		}
		$data      = self::recipients( $id );
		$z         = (int) $data['total'];
		$published = ( 'publish' === get_post_status( $id ) );
		$sent_at   = (int) get_post_meta( $id, self::META_AT, true );
		$sent_cnt  = (int) get_post_meta( $id, self::META_COUNT, true );
		$test      = trim( (string) get_option( self::TEST_OPTION, '' ) );
		$test_on   = is_email( $test );

		// Button-Beschriftung & Confirm-Text.
		if ( $sent_at ) {
			$btn_label = 'Erneut senden';
		} elseif ( $test_on ) {
			$btn_label = 'Test-Versand an ' . $test;
		} else {
			$btn_label = 'Jetzt an ' . $z . ' Interessenten senden';
		}
		$confirm = $test_on ? ( 'Test-Versand an ' . $test . '?' ) : ( 'An ' . $z . ' Interessenten senden?' );
		$can_send = $published && ( $test_on || $z > 0 );

		$rest  = esc_url_raw( rest_url( self::NS . '/fahrzeug-alert-send' ) );
		$nonce = wp_create_nonce( 'wp_rest' );

		ob_start();
		?>
		<div class="m24fz-alertbox" data-post="<?php echo (int) $id; ?>" data-rest="<?php echo esc_attr( $rest ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-confirm="<?php echo esc_attr( $confirm ); ?>">
			<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>

			<div class="m24fz-ab-head">
				<div class="m24fz-ab-num"><?php echo (int) $z; ?></div>
				<div class="m24fz-ab-lbl">Interessenten</div>
			</div>

			<?php if ( ! empty( $data['breakdown'] ) ) : ?>
				<div class="m24fz-ab-card">
					<?php foreach ( $data['breakdown'] as $row ) : ?>
						<div class="m24fz-ab-line">
							<span class="t"><?php echo esc_html( $row['label'] ); ?></span>
							<span class="c"><?php echo (int) $row['count']; ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="m24fz-ab-foot">
					<?php
					printf(
						/* translators: 1: entries, 2: duplicates, 3: deduped recipients */
						esc_html__( '%1$d Einträge, %2$d doppelt = %3$d Empfänger', 'm24-plattform' ),
						(int) $data['entries'],
						(int) $data['dupes'],
						(int) $z
					);
					?>
				</p>
			<?php else : ?>
				<p class="m24fz-ab-foot"><?php echo esc_html__( 'Noch keine passenden Interessenten.', 'm24-plattform' ); ?></p>
			<?php endif; ?>

			<?php if ( $sent_at ) : ?>
				<div class="m24fz-ab-sent">
					<?php
					printf(
						/* translators: 1: date, 2: count */
						esc_html__( '✓ Bereits am %1$s an %2$d gesendet', 'm24-plattform' ),
						esc_html( wp_date( 'd.m.Y H:i', $sent_at ) ),
						(int) $sent_cnt
					);
					?>
				</div>
			<?php endif; ?>

			<?php if ( $test_on ) : ?>
				<p class="m24fz-ab-test"><?php echo esc_html( sprintf( __( 'QA-Modus: Versand geht NUR an %s (Test).', 'm24-plattform' ), $test ) ); ?></p>
			<?php endif; ?>

			<button type="button" class="m24fz-ab-btn" <?php disabled( ! $can_send ); ?>>
				<?php echo esc_html( $btn_label ); ?>
			</button>
			<?php if ( ! $published ) : ?>
				<p class="m24fz-ab-hint"><?php echo esc_html__( 'Erst veröffentlichen, dann senden.', 'm24-plattform' ); ?></p>
			<?php endif; ?>
			<p class="m24fz-ab-msg" role="status"></p>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function css() {
		return ".m24fz-alertbox{font-family:'Saira',Arial,sans-serif;color:#14161a}"
			. ".m24fz-ab-head{text-align:center;margin:6px 0 14px}"
			. ".m24fz-ab-num{font-size:46px;font-weight:800;line-height:1;color:#9a7b3f}"
			. ".m24fz-ab-lbl{font-size:13px;letter-spacing:.5px;color:#5a6474;text-transform:uppercase}"
			. ".m24fz-ab-card{background:#f7f8fa;border:1px solid #e6e9ee;border-radius:8px;padding:8px 12px;margin:0 0 8px}"
			. ".m24fz-ab-line{display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px solid #eef0f3}"
			. ".m24fz-ab-line:last-child{border-bottom:0}"
			. ".m24fz-ab-line .c{font-weight:700;color:#14161a}"
			. ".m24fz-ab-foot{font-size:12px;color:#5a6474;margin:0 0 12px;text-align:center}"
			. ".m24fz-ab-sent{background:#edf7f1;border:1px solid #1a7a3c;color:#1a7a3c;border-radius:6px;padding:7px 10px;font-size:12px;font-weight:600;margin:0 0 10px}"
			. ".m24fz-ab-test{background:#fdf5e6;border:1px solid #b87000;color:#8a5a00;border-radius:6px;padding:7px 10px;font-size:12px;margin:0 0 10px}"
			. ".m24fz-ab-btn{width:100%;background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);color:#fff;border:0;border-radius:8px;padding:12px 14px;font-size:14px;font-weight:700;cursor:pointer}"
			. ".m24fz-ab-btn:disabled{background:#c8ccd2;cursor:not-allowed}"
			. ".m24fz-ab-hint{font-size:12px;color:#b87000;margin:8px 0 0;text-align:center}"
			. ".m24fz-ab-msg{font-size:13px;margin:10px 0 0;text-align:center}"
			. ".m24fz-ab-msg.ok{color:#1a7a3c;font-weight:600}.m24fz-ab-msg.fail{color:#c8102e;font-weight:600}";
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

		$teaser = self::build_teaser( $id, $tags );
		$sender = self::sender( $id );
		if ( '' === (string) $sender['email'] ) {
			delete_transient( $lock );
			return new WP_Error( 'm24_sender', 'Kein Absender konfiguriert.', array( 'status' => 409 ) );
		}

		$test    = trim( (string) get_option( self::TEST_OPTION, '' ) );
		$test_on = is_email( $test );

		// Kampagne anlegen. Für den Test brauchen wir gültige recipients.listIds — Fallback Liste 3.
		$camp_lists = ! empty( $list_ids ) ? $list_ids : array( M24_Brevo_Client::LIST_ID );
		$name       = 'M24 Alert #' . $id . ' — ' . current_time( 'Y-m-d H:i' ) . ( $test_on ? ' [TEST]' : '' );
		$camp       = M24_Brevo_Client::create_campaign( $name, $teaser['subject'], $teaser['html'], $camp_lists, $sender );

		if ( ! $camp['ok'] ) {
			delete_transient( $lock );
			M24_Logger::error( 'brevo', 'Alert: Kampagne anlegen fehlgeschlagen (Inserat #' . $id . ')', array( 'msg' => $camp['msg'] ) );
			return new WP_Error( 'm24_camp', 'Kampagne konnte nicht angelegt werden: ' . $camp['msg'], array( 'status' => 502 ) );
		}

		if ( $test_on ) {
			$send = M24_Brevo_Client::send_campaign_test( $camp['id'], array( $test ) );
			delete_transient( $lock );
			if ( ! $send['ok'] ) {
				M24_Logger::error( 'brevo', 'Alert TEST-Versand fehlgeschlagen (Inserat #' . $id . ')', array( 'campaign' => $camp['id'], 'msg' => $send['msg'] ) );
				return new WP_Error( 'm24_test', 'Test-Versand fehlgeschlagen: ' . $send['msg'], array( 'status' => 502 ) );
			}
			M24_Logger::warning( 'brevo', 'Alert gesendet [TEST]: Inserat #' . $id . ', nur an ' . M24_Brevo_Client::mask_email( $test ) . ', campaign #' . $camp['id'], array(
				'mode'     => 'TEST',
				'campaign' => $camp['id'],
				'tags'     => $tags,
			) );
			// TEST zählt NICHT als echter Versand (kein Re-Send-Meta).
			return rest_ensure_response( array( 'ok' => true, 'test' => true, 'message' => 'Test-Versand an ' . $test . ' ausgelöst.' ) );
		}

		// Echter Versand.
		if ( empty( $list_ids ) ) {
			delete_transient( $lock );
			return new WP_Error( 'm24_nolists', 'Keine Ziel-Listen vorhanden — bitte erst „Alert-Listen anlegen/prüfen".', array( 'status' => 409 ) );
		}
		$send = M24_Brevo_Client::send_campaign_now( $camp['id'] );
		delete_transient( $lock );
		if ( ! $send['ok'] ) {
			M24_Logger::error( 'brevo', 'Alert sendNow fehlgeschlagen (Inserat #' . $id . ')', array( 'campaign' => $camp['id'], 'msg' => $send['msg'] ) );
			return new WP_Error( 'm24_send', 'Versand fehlgeschlagen: ' . $send['msg'], array( 'status' => 502 ) );
		}

		$now = time();
		update_post_meta( $id, self::META_AT, $now );
		update_post_meta( $id, self::META_COUNT, (int) get_post_meta( $id, self::META_COUNT, true ) + (int) $data['total'] );
		update_post_meta( $id, self::META_CAMP, (int) $camp['id'] );

		M24_Logger::info( 'brevo', 'Alert gesendet: Inserat #' . $id . ', ' . (int) $data['total'] . ' Empfänger, campaign #' . $camp['id'], array(
			'recipients' => (int) $data['total'],
			'listIds'    => array_values( $list_ids ),
			'tags'       => $tags,
			'campaign'   => $camp['id'],
		) );

		return rest_ensure_response( array(
			'ok'      => true,
			'test'    => false,
			'sent_at' => wp_date( 'd.m.Y H:i', $now ),
			'count'   => (int) $data['total'],
			'message' => 'An ' . (int) $data['total'] . ' Interessenten gesendet.',
		) );
	}

	/* ── Absender & Teaser ───────────────────────────────────────────────── */

	private static function sender( $id ) {
		$host = preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) {
			$host = 'motorsport24.de';
		}
		return apply_filters( 'm24_alert_sender', array(
			'name'  => 'MOTORSPORT24',
			'email' => apply_filters( 'm24fz_mail_from_email', 'noreply@' . $host ),
		), $id );
	}

	/**
	 * Mail-Teaser (HTML, CI). Auto-befüllt aus dem Fahrzeug.
	 * @return array ['subject'=>string,'html'=>string]
	 */
	public static function build_teaser( $id, $tags ) {
		$title   = get_the_title( $id );
		$url     = get_permalink( $id );
		$img     = get_the_post_thumbnail_url( $id, 'large' );
		$subject = 'Neu bei MOTORSPORT24: ' . $title;

		// Key-Facts.
		$facts = array();
		$bj    = (int) get_post_meta( $id, '_m24fz_baujahr', true );
		if ( $bj > 0 ) { $facts['Baujahr'] = (string) $bj; }
		$ps = trim( (string) get_post_meta( $id, '_m24fz_leistung_ps', true ) );
		if ( '' !== $ps ) { $facts['Leistung'] = $ps . ' PS'; }
		$km = (int) get_post_meta( $id, '_m24fz_laufleistung', true );
		if ( $km > 0 ) { $facts['Laufleistung'] = number_format( $km, 0, ',', '.' ) . ' km'; }

		// Preis.
		if ( (int) get_post_meta( $id, '_m24fz_preis_auf_anfrage', true ) ) {
			$preis = 'Preis auf Anfrage';
		} else {
			$p = (int) get_post_meta( $id, '_m24fz_preis', true );
			if ( $p > 0 ) {
				$cur   = class_exists( 'M24FZ_Telemetry' ) ? M24FZ_Telemetry::currency_symbol( get_post_meta( $id, '_m24fz_waehrung', true ) ) : '€';
				$preis = number_format( $p, 0, ',', '.' ) . ' ' . $cur;
			} else {
				$preis = 'Preis auf Anfrage';
			}
		}

		// Tag-Label für die Fußzeile (spezifischster = erster Tag).
		$tax       = class_exists( 'M24_Alert_Taxonomie' ) ? M24_Alert_Taxonomie::tags() : array();
		$first     = ! empty( $tags ) ? reset( $tags ) : '';
		$tag_label = ( $first && isset( $tax[ $first ] ) ) ? $tax[ $first ]['label'] : 'unsere Fahrzeug-Benachrichtigungen';

		$font_url = plugins_url( 'assets/fonts/saira-latin.woff2', M24_PLATTFORM_FILE );
		$stack    = "font-family:'Saira', Arial, Helvetica, sans-serif;";

		$facts_html = '';
		foreach ( $facts as $k => $v ) {
			$facts_html .= '<tr><td style="padding:4px 0;color:#5a6474;font-size:13px;">' . esc_html( $k ) . '</td>'
				. '<td style="padding:4px 0;text-align:right;font-weight:600;font-size:13px;color:#14161a;">' . esc_html( $v ) . '</td></tr>';
		}

		$html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<style>@font-face{font-family:\'Saira\';src:url(\'' . esc_url( $font_url ) . '\') format(\'woff2\');font-weight:100 900;font-style:normal;font-display:swap;}'
			. 'body,table,td,h1,div,a,p{' . $stack . '}</style></head>'
			. '<body style="margin:0;padding:0;background:#f2f4f7;' . $stack . '">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:0;"><tr><td align="center" style="padding:24px 16px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:460px;background:#ffffff;border-radius:10px;overflow:hidden;">'
			. '<tr><td style="background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);padding:16px 24px;text-align:right;">'
			. '<span style="color:#fff;font-weight:700;letter-spacing:1px;font-size:14px;">MOTORSPORT24</span></td></tr>';

		if ( $img ) {
			$html .= '<tr><td style="padding:0;"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( $title ) . '" width="460" style="display:block;width:100%;height:auto;border:0;"></td></tr>';
		}

		$html .= '<tr><td style="padding:22px 24px 8px;">'
			. '<p style="margin:0 0 4px;color:#9a7b3f;font-size:12px;letter-spacing:.5px;text-transform:uppercase;">Neu im Bestand</p>'
			. '<h1 style="margin:0 0 14px;font-size:22px;line-height:1.25;color:#10243a;">' . esc_html( $title ) . '</h1>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #eef0f3;border-bottom:1px solid #eef0f3;margin:0 0 14px;">' . $facts_html . '</table>'
			. '<p style="margin:0 0 18px;font-size:20px;font-weight:800;color:#9a7b3f;">' . esc_html( $preis ) . '</p>'
			. '<p style="margin:0 0 6px;text-align:center;">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4 0%,#0e447e 100%);color:#fff;text-decoration:none;font-weight:700;padding:14px 30px;border-radius:8px;font-size:15px;">Fahrzeug ansehen</a>'
			. '</p>'
			. '</td></tr>'
			. '<tr><td style="padding:14px 24px;border-top:1px solid #e6e9ee;font-size:11px;color:#9aa3b0;line-height:1.6;">'
			. 'Sie erhalten diese E-Mail, weil Sie „' . esc_html( $tag_label ) . '" abonniert haben.<br>'
			. 'Kein Interesse mehr? <a href="{{ unsubscribe }}" style="color:#1f74c4;">Hier abmelden</a>. · MOTORSPORT24 GmbH'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';

		return array( 'subject' => $subject, 'html' => $html );
	}
}
