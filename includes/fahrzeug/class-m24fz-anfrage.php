<?php
/**
 * M24 Fahrzeug — „Jetzt anfragen" (Fahrzeug-Anfrageformular)
 * Modul: includes/fahrzeug/class-m24fz-anfrage.php
 *
 * Modal-Form auf der Detailseite. Submit → REST (Nonce wp_rest, Gast-tauglich) → Mail an
 * service@ + (optional) M24-Desk-Pipeline. Honeypot + Rate-Limit. Erst bei erfolgreichem
 * Submit wird _m24fz_anfragen_count erhöht (NICHT beim Button-Klick).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Anfrage {

	const NS = 'm24/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_reset_testdata' ) );
	}

	/** Einmalig: Anfragen-Zähler des Europameister (34294) auf 0 (Test-/Altdaten, §2). */
	public static function maybe_reset_testdata() {
		if ( get_option( 'm24fz_anfrage_reset_v1' ) || ! current_user_can( 'manage_options' ) ) { return; }
		if ( M24FZ_CPT::PT === get_post_type( 34294 ) ) { update_post_meta( 34294, '_m24fz_anfragen_count', 0 ); }
		update_option( 'm24fz_anfrage_reset_v1', 1 );
	}

	public static function register() {
		register_rest_route( self::NS, '/fahrzeug-anfrage', array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'check_nonce' ),
			'callback'            => array( __CLASS__, 'handle' ),
		) );
	}

	public static function check_nonce( $req ) {
		$n = $req->get_header( 'X-WP-Nonce' );
		return ( is_string( $n ) && wp_verify_nonce( $n, 'wp_rest' ) ) ? true : new WP_Error( 'm24fz_nonce', 'Sicherheitsprüfung fehlgeschlagen.', array( 'status' => 403 ) );
	}

	public static function handle( WP_REST_Request $req ) {
		$p = $req->get_params();

		// Honeypot (verstecktes Feld muss leer sein).
		if ( ! empty( $p['website'] ) ) { return rest_ensure_response( array( 'ok' => true ) ); } // still „ok", Bot merkt nichts

		$pid = (int) ( $p['post_id'] ?? 0 );
		if ( ! $pid || M24FZ_CPT::PT !== get_post_type( $pid ) ) { return new WP_Error( 'm24fz_bad', 'Fahrzeug unbekannt.', array( 'status' => 400 ) ); }

		// Rate-Limit: max 5 Anfragen / Stunde je IP.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-f:.]/i', '', (string) $_SERVER['REMOTE_ADDR'] ) : 'x';
		$rk  = 'm24fz_anf_' . md5( $ip );
		$cnt = (int) get_transient( $rk );
		if ( $cnt >= 5 ) { return new WP_Error( 'm24fz_rate', 'Zu viele Anfragen. Bitte später erneut.', array( 'status' => 429 ) ); }

		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$mail = sanitize_email( (string) ( $p['email'] ?? '' ) );
		$land = sanitize_text_field( (string) ( $p['land'] ?? '' ) );
		$tel  = sanitize_text_field( (string) ( $p['tel'] ?? '' ) );
		$typ  = ( 'gewerblich' === ( $p['anrede'] ?? '' ) ) ? 'gewerblich' : 'privat';
		$msg  = sanitize_textarea_field( (string) ( $p['nachricht'] ?? '' ) );
		$ilist = ! empty( $p['interessent'] );

		if ( '' === $name || ! is_email( $mail ) ) { return new WP_Error( 'm24fz_form', 'Bitte Name und gültige E-Mail angeben.', array( 'status' => 422 ) ); }

		$title = get_the_title( $pid );
		$url   = get_permalink( $pid );
		$to    = apply_filters( 'm24fz_anfrage_to', get_option( 'admin_email' ) );

		$body  = "Neue Fahrzeug-Anfrage\n\n";
		$body .= "Fahrzeug: {$title}\n{$url}\nInserat-ID: {$pid}\n\n";
		$body .= "Typ: {$typ}\nName: {$name}\nE-Mail: {$mail}\n";
		if ( '' !== $land ) { $body .= "Lieferland: {$land}\n"; }
		if ( '' !== $tel )  { $body .= "Telefon: {$tel}\n"; }
		if ( '' !== $msg )  { $body .= "\nNachricht:\n{$msg}\n"; }
		$body .= "\nInteressentenliste gewünscht: " . ( $ilist ? 'JA' : 'nein' ) . "\n";

		$headers = array( 'Reply-To: ' . $name . ' <' . $mail . '>' );
		$sent    = wp_mail( $to, 'Fahrzeug-Anfrage: ' . $title, $body, $headers );

		// Optionaler Desk-Push (wenn Pipeline vorhanden) — Mail ist der zuverlässige Fallback.
		do_action( 'm24fz_anfrage_submitted', $pid, array( 'name' => $name, 'email' => $mail, 'land' => $land, 'tel' => $tel, 'typ' => $typ, 'nachricht' => $msg, 'interessent' => $ilist ) );

		// Erst bei erfolgreichem Submit zählen (§2).
		if ( class_exists( 'M24FZ_Tracking' ) ) { M24FZ_Tracking::increment( $pid, 'anfrage' ); }

		set_transient( $rk, $cnt + 1, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'ok' => (bool) $sent, 'message' => $sent ? 'Danke! Ihre Anfrage ist eingegangen.' : 'Anfrage gespeichert, Mailversand verzögert.' ) );
	}

	/** Modal-Markup (einmal pro Detailseite ausgeben). */
	public static function modal_html( $post_id ) {
		$countries = M24FZ_Telemetry::countries();
		?>
		<div class="m24fz-anfrage-modal" hidden aria-hidden="true">
			<div class="m24fz-anfrage-box" role="dialog" aria-modal="true" aria-label="Fahrzeug anfragen">
				<button type="button" class="m24fz-anfrage-close" aria-label="Schließen">&times;</button>
				<h3>Fahrzeug anfragen</h3>
				<p class="m24fz-anfrage-veh"><?php echo esc_html( get_the_title( $post_id ) ); ?></p>
				<form class="m24fz-anfrage-form" data-pid="<?php echo (int) $post_id; ?>">
					<div class="m24fz-anf-seg">
						<label><input type="radio" name="anrede" value="privat" checked> Privat</label>
						<label><input type="radio" name="anrede" value="gewerblich"> Gewerblich</label>
					</div>
					<input type="text" name="name" placeholder="Name *" required>
					<input type="email" name="email" placeholder="E-Mail *" required>
					<select name="land"><option value="">Lieferland</option><?php foreach ( $countries as $cc => $cn ) { printf( '<option value="%s">%s</option>', esc_attr( $cn ), esc_html( $cn ) ); } ?></select>
					<input type="tel" name="tel" placeholder="Telefon (optional)">
					<textarea name="nachricht" rows="3" placeholder="Ihre Nachricht"></textarea>
					<label class="m24fz-anf-check"><input type="checkbox" name="interessent" value="1"> Zusätzlich auf die Interessentenliste (ähnliche Fahrzeuge zuerst erfahren)</label>
					<input type="text" name="website" class="m24fz-anf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
					<button type="submit" class="m24fz-btn">Anfrage senden</button>
					<p class="m24fz-anf-msg" role="status"></p>
				</form>
			</div>
		</div>
		<?php
	}
}
