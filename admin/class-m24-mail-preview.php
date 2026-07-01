<?php
/**
 * M24 Plattform — Admin-Tool „Mail- & PDF-Vorschau / Test-Versand".
 *
 * Enumeriert ALLE transaktionalen Mails + PDFs des Plugins, zeigt je Eintrag das genutzte Template
 * (kanonische Shell / Dompdf), und verschickt sie mit realistischen Dummy-Daten über
 * das ECHTE Template + den bestehenden Versandweg (wp_mail → Brevo). Rein Admin-gated, nonce-geschützt.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Mail_Preview {

	const OPTION_TO = 'm24_mail_preview_to';
	const ACTION    = 'm24_mail_preview_send';
	const CTX       = 'mail_preview';
	const CAP       = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'm24-plattform',
			__( 'Mail- & PDF-Vorschau', 'm24-plattform' ),
			__( 'Mail- & PDF-Vorschau', 'm24-plattform' ),
			self::CAP,
			'm24-mail-preview',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Registry aller versendbaren Artefakte.
	 * @return array<string,array{label:string,template:string,kind:string,cb:callable}>
	 */
	private static function registry(): array {
		$reg = array();

		$reg['garage_mail'] = array(
			'label'    => 'Garage-Mail (Teile-Auswahl an Kunden)',
			'template' => 'Kanonische Shell (IL-Design)',
			'kind'     => 'Mail + PDF',
			'cb'       => function ( $to ) { return class_exists( 'M24_Garage_Cart' ) && M24_Garage_Cart::preview_send_mail( $to ); },
		);
		$reg['alert_price'] = array(
			'label'    => 'Fahrzeug-Alert: Preisänderung',
			'template' => 'Kanonische Shell (IL-Design) + Bild-Mosaik',
			'kind'     => 'Mail',
			'cb'       => function ( $to ) { return class_exists( 'M24_Garage_Alerts' ) && M24_Garage_Alerts::preview_send( $to, 'price' ); },
		);
		$reg['alert_sold'] = array(
			'label'    => 'Fahrzeug-Alert: Verkauft / reserviert',
			'template' => 'Kanonische Shell (IL-Design) + Bild-Mosaik',
			'kind'     => 'Mail',
			'cb'       => function ( $to ) { return class_exists( 'M24_Garage_Alerts' ) && M24_Garage_Alerts::preview_send( $to, 'sold' ); },
		);
		$reg['neue_anfrage'] = array(
			'label'    => 'Neue Anfrage (Betreiber-Benachrichtigung)',
			'template' => 'Kanonische Shell (IL-Design)',
			'kind'     => 'Mail',
			'cb'       => function ( $to ) { return class_exists( 'M24_Inquiries_Mail_Fallback' ) && M24_Inquiries_Mail_Fallback::preview_notification( $to ); },
		);
		$reg['haendler_doi'] = array(
			'label'    => 'Händler-Registrierung (DOI-Bestätigung)',
			'template' => 'Kanonische Shell (IL-Design)',
			'kind'     => 'Mail',
			'cb'       => function ( $to ) {
				if ( ! class_exists( 'M24_B2B_Auth' ) ) { return false; }
				M24_B2B_Auth::send_magic_mail( $to, str_repeat( 'a', 40 ), 'verify', 'de', 0 );
				return true;
			},
		);
		$reg['il_doi'] = array(
			'label'    => 'Interessentenliste (DOI-Bestätigung)',
			'template' => 'Kanonische Shell (IL-Design)',
			'kind'     => 'Mail',
			'cb'       => function ( $to ) { return class_exists( 'M24_Brevo_IL' ) && M24_Brevo_IL::preview_doi_mail( $to, '' ); },
		);
		$reg['offmarket_doi'] = array(
			'label'    => 'Off-Market-Anmeldung (DOI-Bestätigung)',
			'template' => 'Kanonische Shell (IL-Design)',
			'kind'     => 'Mail',
			'cb'       => function ( $to ) { return class_exists( 'M24_Brevo_IL' ) && M24_Brevo_IL::preview_doi_mail( $to, 'offmarket' ); },
		);
		$reg['parked_doi'] = array(
			'label'    => 'Fahrzeug parken (DOI-Bestätigung)',
			'template' => 'Kanonische Shell (IL-Design)',
			'kind'     => 'Mail',
			'cb'       => function ( $to ) { return class_exists( 'M24_Brevo_IL' ) && M24_Brevo_IL::preview_doi_mail( $to, 'parked' ); },
		);
		$reg['garage_pdf'] = array(
			'label'    => 'Garage-Exposé (PDF)',
			'template' => 'Dompdf (Exposé-Layout)',
			'kind'     => 'PDF (als Anhang)',
			'cb'       => array( __CLASS__, 'send_pdf_only' ),
		);

		return apply_filters( 'm24_mail_preview_registry', $reg );
	}

	/** Reines PDF: Dummy-Exposé generieren + als Anhang einer schlichten Mail schicken. */
	public static function send_pdf_only( $to ): bool {
		if ( ! is_email( $to ) || ! class_exists( 'M24_Garage_PDF' ) ) { return false; }
		$pdf = M24_Garage_PDF::preview_pdf_string();
		if ( '' === $pdf ) { return false; }
		$tmp_dir = trailingslashit( get_temp_dir() ) . 'm24mp-' . wp_generate_password( 10, false, false ) . '/';
		if ( ! wp_mkdir_p( $tmp_dir ) ) { return false; }
		$file = $tmp_dir . 'MOTORSPORT24-Expose.pdf';
		if ( false === file_put_contents( $file, $pdf ) ) { return false; } // phpcs:ignore WordPress.WP.AlternativeFunctions
		$inner = '<p style="margin:0 0 14px;">Testversand des Garage-Exposés — siehe PDF-Anhang.</p>';
		$html  = function_exists( 'm24_mail_shell' ) ? m24_mail_shell( 'Exposé-Test', $inner ) : '<h1>Exposé-Test</h1>' . $inner;
		$ok    = (bool) wp_mail( $to, '[TEST] Garage-Exposé (PDF)', $html, array( 'Content-Type: text/html; charset=UTF-8' ), array( $file ) );
		if ( is_file( $file ) ) { @unlink( $file ); } // phpcs:ignore
		if ( is_dir( $tmp_dir ) ) { @rmdir( $tmp_dir ); } // phpcs:ignore
		return $ok;
	}

	private static function recipient(): string {
		$to = (string) get_option( self::OPTION_TO, '' );
		return '' !== $to ? $to : 'info@danielschwab.net';
	}

	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Keine Berechtigung.', '', array( 'response' => 403 ) ); }
		check_admin_referer( self::ACTION );

		$to = sanitize_email( (string) ( $_POST['m24_to'] ?? '' ) );
		if ( ! is_email( $to ) ) {
			self::redirect( array( 'sent' => 0, 'err' => 'bademail' ) );
		}
		update_option( self::OPTION_TO, $to );

		$which = sanitize_text_field( (string) ( $_POST['which'] ?? '' ) );
		$reg   = self::registry();
		$todo  = ( 'all' === $which ) ? array_keys( $reg ) : ( isset( $reg[ $which ] ) ? array( $which ) : array() );
		if ( empty( $todo ) ) { self::redirect( array( 'sent' => 0, 'err' => 'noentry' ) ); }

		$ok_n = 0; $fail_n = 0;
		foreach ( $todo as $id ) {
			$ok = false;
			try { $ok = (bool) call_user_func( $reg[ $id ]['cb'], $to ); }
			catch ( \Throwable $t ) { $ok = false; }
			if ( $ok ) { $ok_n++; } else { $fail_n++; }
			if ( class_exists( 'M24_Logger' ) ) {
				$payload = array( 'entry' => $id, 'to' => self::redact( $to ), 'result' => $ok ? 'sent' : 'failed' );
				if ( $ok ) { M24_Logger::info( self::CTX, 'Test-Mail gesendet: ' . $id, $payload ); }
				else { M24_Logger::error( self::CTX, 'Test-Mail fehlgeschlagen: ' . $id, $payload ); }
			}
		}
		self::redirect( array( 'sent' => $ok_n, 'fail' => $fail_n ) );
	}

	private static function redirect( array $args ) {
		$url = add_query_arg( array_merge( array( 'page' => 'm24-mail-preview' ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private static function redact( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at ) { return '***'; }
		return substr( $email, 0, min( 2, $at ) ) . '***' . substr( $email, $at );
	}

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$to  = self::recipient();
		$reg = self::registry();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Mail- & PDF-Vorschau / Test-Versand', 'm24-plattform' ); ?></h1>
			<?php
			if ( isset( $_GET['err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$msg = ( 'bademail' === $_GET['err'] ) ? 'Bitte eine gültige Empfänger-Adresse angeben.' : 'Kein gültiger Eintrag gewählt.';
				echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
			} elseif ( isset( $_GET['sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$s = (int) $_GET['sent']; $f = (int) ( $_GET['fail'] ?? 0 );
				echo '<div class="notice notice-' . ( $f > 0 ? 'warning' : 'success' ) . '"><p>' . esc_html( sprintf( '%d gesendet, %d fehlgeschlagen.', $s, $f ) ) . '</p></div>';
			}
			?>
			<p><?php echo esc_html__( 'Verschickt jede Mail mit realistischen Dummy-Daten über ihr echtes Template und den bestehenden Versandweg (wp_mail → Brevo). PDFs werden generiert und als Anhang mitgeschickt.', 'm24-plattform' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="m24_to"><?php echo esc_html__( 'Empfänger', 'm24-plattform' ); ?></label></th>
						<td><input type="email" id="m24_to" name="m24_to" value="<?php echo esc_attr( $to ); ?>" class="regular-text" required></td>
					</tr>
				</table>

				<p><button type="submit" name="which" value="all" class="button button-primary"><?php echo esc_html__( 'Alle als Test senden', 'm24-plattform' ); ?></button></p>

				<table class="widefat striped" style="max-width:900px;">
					<thead><tr>
						<th><?php echo esc_html__( 'E-Mail / PDF', 'm24-plattform' ); ?></th>
						<th><?php echo esc_html__( 'Typ', 'm24-plattform' ); ?></th>
						<th><?php echo esc_html__( 'Template', 'm24-plattform' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $reg as $id => $e ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $e['label'] ); ?></strong><br><code style="font-size:11px;"><?php echo esc_html( $id ); ?></code></td>
							<td><?php echo esc_html( $e['kind'] ); ?></td>
							<td><?php echo esc_html( $e['template'] ); ?></td>
							<td><button type="submit" name="which" value="<?php echo esc_attr( $id ); ?>" class="button"><?php echo esc_html__( 'Senden', 'm24-plattform' ); ?></button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
	}
}
