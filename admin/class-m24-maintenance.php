<?php
/**
 * M24 — Wartung: die CLI-Kommandos als Oberfläche.
 * Modul: admin/class-m24-maintenance.php
 *
 * Auf dem Live-Hosting (Plesk/nginx) gibt es keinen SSH-Zugang — die WP-CLI-Kommandos sind dort
 * praktisch nicht ausführbar. Diese Seite ruft GENAU DIESELBEN Kerne wie die CLI-Kommandos; die
 * bleiben unverändert bestehen. Zwei Implementierungen würden im Zweifel unterschiedlich falsch
 * laufen, und bei Kommandos, die Angebote und Anfragen anfassen, wäre das der teuerste Fehler.
 *
 * Regeln: Trockenlauf immer zuerst (sonst ist „Ausführen" gesperrt), Ausgabe vollständig und
 * kopierbar, Bestätigung zweistufig in der Seite — kein confirm().
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Maintenance {

	const SLUG   = 'm24-wartung';
	const ACTION = 'm24_maint';
	/** Wie lange ein Trockenlauf „Ausführen" freischaltet. */
	const TTL    = 1800;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function admin_menu() {
		add_submenu_page( 'm24-plattform', 'Wartung', 'Wartung', 'manage_options', self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	/**
	 * Die drei Kommandos. 'core' zeigt auf denselben Einstiegspunkt, den auch WP-CLI ruft —
	 * fehlt er, meldet der Block das, statt einen Fatal zu riskieren.
	 */
	private static function jobs(): array {
		return array(
			'inquiry-recover' => array(
				'titel' => 'Hängende Anfragen erneut an den Desk pushen',
				'text'  => 'Anfragen mit Status synced_via_mail oder sync_failed, die keine Desk-Auftragsnummer tragen. Setzt Versuchszähler und Status zurück und pusht erneut. Versendet keine Mail.',
				'cli'   => 'wp m24 inquiry-recover',
				'core'  => array( 'M24_Inquiries_Recover', 'run' ),
				'ids'   => false,
			),
			'offer-consolidate' => array(
				'titel' => 'Altbestand der Nachfolger-Automatik einsammeln',
				'text'  => 'Holt abgelöste Angebote zurück, legt nie versendete Nachfolger in den Papierkorb (Nummer bleibt verbraucht) und übernimmt nummernlose Entwürfe als nächste Fassung. Versendet nichts.',
				'cli'   => 'wp m24 offer-consolidate',
				'core'  => array( 'M24_Offer_Consolidate', 'run' ),
				'ids'   => false,
			),
			'supersede-undo' => array(
				'titel' => 'Supersede rückgängig machen',
				'text'  => 'Löst einzelne, grundlos entstandene Ersatz-Angebote auf. Nummern der ERSATZ-Angebote angeben, z. B. 2026-1052,2026-1053.',
				'cli'   => 'wp m24 supersede-undo --ids=…',
				'core'  => array( 'M24_Sync_Supersede', 'undo' ),
				'ids'   => true,
			),
		);
	}

	private static function available( array $job ): bool {
		return is_callable( $job['core'] );
	}

	/**
	 * Ruft den Kern und normalisiert die Rückgabe. Die Kerne haben unterschiedliche Signaturen —
	 * deshalb hier ein Verteiler und KEIN nachgebauter Ablauf.
	 *
	 * @return array{zeilen:array,anzahl:int,summe:array}
	 */
	private static function run_core( string $key, bool $go, array $ids ): array {
		$out = array( 'zeilen' => array(), 'anzahl' => 0, 'summe' => array() );
		if ( 'inquiry-recover' === $key ) {
			$r = M24_Inquiries_Recover::run( $ids, $go, 200 );
			return array( 'zeilen' => (array) $r['zeilen'], 'anzahl' => (int) $r['geprueft'], 'summe' => (array) ( $r['summe'] ?? array() ) );
		}
		if ( 'offer-consolidate' === $key ) {
			$r = M24_Offer_Consolidate::run( $go );
			return array( 'zeilen' => (array) $r['zeilen'], 'anzahl' => count( (array) $r['zeilen'] ), 'summe' => (array) ( $r['summe'] ?? array() ) );
		}
		if ( 'supersede-undo' === $key ) {
			$r = M24_Sync_Supersede::undo( $ids, $go );
			return array( 'zeilen' => (array) ( $r['zeilen'] ?? array() ), 'anzahl' => count( (array) ( $r['zeilen'] ?? array() ) ), 'summe' => array() );
		}
		return $out;
	}

	/** Ausgabe wie auf der Konsole — vollständig, unverkürzt, kopierbar. */
	private static function format( string $key, bool $go, array $res ): string {
		$jobs = self::jobs();
		$head = ( $go ? 'AUSGEFÜHRT' : 'TROCKENLAUF — nichts geändert' ) . ' · ' . $jobs[ $key ]['cli'] . ( $go ? ' --go' : '' );
		$txt  = $head . "\n" . str_repeat( '─', 72 ) . "\n";
		$txt .= $res['anzahl'] . ' Treffer' . ( empty( $res['zeilen'] ) ? '' : ':' ) . "\n";
		foreach ( $res['zeilen'] as $z ) { $txt .= '  ' . $z . "\n"; }
		if ( ! empty( $res['summe'] ) ) {
			$teile = array();
			foreach ( $res['summe'] as $k => $v ) { $teile[] = $v . ' ' . $k; }
			$txt .= str_repeat( '─', 72 ) . "\n" . implode( ', ', $teile ) . "\n";
		}
		if ( ! $go ) { $txt .= "\nTrockenlauf. „Ausführen\" ist jetzt für diesen Block freigeschaltet.\n"; }
		return $txt;
	}

	private static function key_dry( string $job ): string { return 'm24_maint_dry_' . get_current_user_id() . '_' . md5( $job ); }
	private static function key_out(): string { return 'm24_maint_out_' . get_current_user_id(); }
	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Kein Zugriff.' ); }
		check_admin_referer( self::ACTION );

		$jobs = self::jobs();
		$job  = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : '';
		$go   = isset( $_POST['do'] ) && 'go' === $_POST['do'];
		$raw  = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		$ids  = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

		if ( ! isset( $jobs[ $job ] ) || ! self::available( $jobs[ $job ] ) ) {
			set_transient( self::key_out(), array( 'job' => $job, 'go' => false, 'text' => 'Dieses Kommando ist auf diesem Stand nicht verfügbar.' ), self::TTL );
			wp_safe_redirect( self::url() ); exit;
		}
		// Trockenlauf ist Pflicht. Ohne ihn wird nicht ausgeführt — auch nicht über einen alten Tab.
		if ( $go && ! get_transient( self::key_dry( $job ) ) ) {
			set_transient( self::key_out(), array( 'job' => $job, 'go' => false, 'text' => 'Erst den Trockenlauf ansehen — „Ausführen" ist gesperrt.' ), self::TTL );
			wp_safe_redirect( self::url() ); exit;
		}
		if ( $jobs[ $job ]['ids'] && empty( $ids ) ) {
			set_transient( self::key_out(), array( 'job' => $job, 'go' => false, 'text' => 'Bitte mindestens eine ID angeben.' ), self::TTL );
			wp_safe_redirect( self::url() ); exit;
		}

		$res = self::run_core( $job, $go, $ids );
		set_transient( self::key_out(), array( 'job' => $job, 'go' => $go, 'text' => self::format( $job, $go, $res ) ), self::TTL );
		if ( $go ) { delete_transient( self::key_dry( $job ) ); } // nach dem Lauf wieder sperren
		else { set_transient( self::key_dry( $job ), (int) $res['anzahl'] + 1, self::TTL ); }

		if ( class_exists( 'M24_Error_Log' ) ) {
			M24_Error_Log::capture( 'maintenance', 'info', 'Wartungslauf ' . $job, array(
				'kommando'    => $job,
				'trockenlauf' => $go ? 'nein' : 'ja',
				'treffer'     => (int) $res['anzahl'],
				'ids'         => implode( ',', $ids ),
			) );
		}
		wp_safe_redirect( self::url() ); exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$confirm = isset( $_GET['confirm'] ) ? sanitize_text_field( wp_unslash( $_GET['confirm'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$out     = get_transient( self::key_out() );
		if ( is_array( $out ) ) { delete_transient( self::key_out() ); }

		echo '<div class="wrap"><h1>Wartung</h1>';
		echo '<p style="max-width:820px;color:#646970;">Dieselben Kommandos wie in WP-CLI, nur ohne Konsole. '
			. 'Der Trockenlauf läuft immer zuerst; „Ausführen" bleibt bis dahin gesperrt und wird nach jedem Lauf wieder gesperrt.</p>';

		if ( is_array( $out ) && ! empty( $out['text'] ) ) {
			echo '<h2>Ausgabe</h2><pre style="background:#fff;border:1px solid #dcdcde;padding:14px;overflow:auto;'
				. 'max-height:460px;font-size:12px;line-height:1.5;white-space:pre;user-select:text;">'
				. esc_html( (string) $out['text'] ) . '</pre>';
		}

		foreach ( self::jobs() as $key => $job ) { self::render_block( $key, $job, $confirm ); }
		echo '</div>';
	}

	private static function render_block( string $key, array $job, string $confirm ): void {
		$ok    = self::available( $job );
		$armed = (bool) get_transient( self::key_dry( $key ) );

		echo '<div class="card" style="max-width:820px;padding:14px 16px;margin:0 0 16px;">';
		echo '<h2 style="margin-top:0;">' . esc_html( $job['titel'] ) . '</h2>';
		echo '<p style="color:#646970;margin:0 0 6px;">' . esc_html( $job['text'] ) . '</p>';
		echo '<p style="margin:0 0 12px;"><code>' . esc_html( $job['cli'] ) . '</code></p>';

		if ( ! $ok ) {
			echo '<div class="notice notice-error inline" style="margin:0;"><p><strong>Nicht verfügbar.</strong> '
				. 'Der Kern <code>' . esc_html( $job['core'][0] . '::' . $job['core'][1] . '()' ) . '</code> existiert auf diesem Stand nicht. '
				. 'Der Knopf bleibt gesperrt — hier wird nichts nachgebaut.</p></div></div>';
			return;
		}

		if ( $confirm === $key ) {
			echo '<div class="notice notice-warning" style="margin:0 0 12px;padding:12px;"><p><strong>Wirklich ausführen?</strong> '
				. 'Der Lauf ändert Daten. Der Trockenlauf oben zeigt, was passiert.</p>';
			self::form( $key, $job, 'go', 'Ja, ausführen', 'button button-primary' );
			echo ' <a class="button" href="' . esc_url( self::url() ) . '">Abbrechen</a></div>';
		}

		echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
		self::form( $key, $job, 'dry', 'Trockenlauf anzeigen', 'button' );
		if ( $armed ) {
			echo ' <a class="button button-primary" href="' . esc_url( self::url( array( 'confirm' => $key ) ) ) . '">Ausführen</a>';
		} else {
			echo ' <button class="button" disabled title="Erst den Trockenlauf ansehen.">Ausführen</button>'
				. ' <span style="color:#8a6d00;font-size:12px;">gesperrt bis zum Trockenlauf</span>';
		}
		echo '</div></div>';
	}

	/** Ein Formular je Knopf — das ID-Feld wandert mit, damit es bei beiden Läufen gilt. */
	private static function form( string $key, array $job, string $do, string $label, string $class ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;gap:8px;align-items:center;">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="job" value="' . esc_attr( $key ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		if ( ! empty( $job['ids'] ) ) {
			echo '<input type="text" name="ids" value="" placeholder="2026-1052,2026-1053" style="width:230px;">';
		}
		echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}
}
