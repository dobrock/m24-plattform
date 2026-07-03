<?php
/**
 * M24 Angebots-Workflow v1 — Rendering (Operator-Modal A1, Kunden-Ansicht, Angebots-Mail).
 * Reine Ausgabe/Views, ausgelagert aus M24_Offers. Standalone-HTML (theme-unabhängig), iPhone-single-scroll.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class M24_Offers_Render {

	private static function assets_url( string $rel ): string {
		return M24_PLATTFORM_URL . $rel . '?ver=' . ( file_exists( M24_PLATTFORM_DIR . $rel ) ? (string) filemtime( M24_PLATTFORM_DIR . $rel ) : M24_PLATTFORM_VERSION );
	}
	private static function head( string $title ): string {
		return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
			. '<title>' . esc_html( $title ) . '</title><meta name="robots" content="noindex,nofollow">'
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Saira:wght@400;600;700;800&display=swap" rel="stylesheet">'
			. '<link rel="stylesheet" href="' . esc_url( self::assets_url( 'assets/css/m24-offers.css' ) ) . '">';
	}
	private static function fmt( float $v ): string {
		return number_format( $v, 2, ',', '.' ) . ' €';
	}
	private static function date_de( string $ymd ): string {
		$t = $ymd ? strtotime( $ymd ) : 0;
		return $t ? ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y', $t ) : gmdate( 'd.m.Y', $t ) ) : '';
	}

	/* ── Operator-Modal A1 (Admin, ?m24_offer_new=1) ───────────────────── */

	public static function operator() {
		if ( empty( $_GET[ M24_Offers::QV_NEW ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( 'manage_options' ) ) { return; } // nur eingeloggte Operatoren
		nocache_headers();

		$g = function ( $k ) { return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; }; // phpcs:ignore WordPress.Security.NonceVerification
		$customer = array(
			'name' => $g( 'name' ), 'email' => strtolower( sanitize_email( $g( 'email' ) ) ),
			'kundentyp' => in_array( $g( 'kundentyp' ), array( 'b2b', 'b2c' ), true ) ? $g( 'kundentyp' ) : 'b2c',
			'land' => strtoupper( substr( $g( 'land' ), 0, 2 ) ), 'firma' => '',
		);
		$src = array(
			'src_modell' => $g( 'modell' ), 'src_pid' => $g( 'pid' ), 'src_pillar' => $g( 'pillar' ),
			'src_lang' => $g( 'lang' ), 'src_url' => esc_url_raw( $g( 'url' ) ),
		);

		$cfg = array(
			'rest'     => esc_url_raw( rest_url( M24_Offers::NS . '/offers' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'presets'  => M24_Offers::extra_presets(),
			'taxModes' => M24_Offers::tax_modes(),
			'customer' => $customer,
			'src'      => $src,
			'validDays'=> M24_Offers::VALID_DAYS,
		);

		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		if ( ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); }
		echo self::head( 'Angebot erstellen' ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		</head><body class="m24off-op">
		<div class="m24off-wrap">
			<header class="m24off-top"><span class="m24off-badge">Neues Angebot</span><span class="m24off-modell" data-modell><?php echo esc_html( $src['src_modell'] ); ?></span></header>

			<section class="m24off-card">
				<h2>Kunde</h2>
				<label class="m24off-f"><span>Name</span><input type="text" data-c="name" value="<?php echo esc_attr( $customer['name'] ); ?>"></label>
				<label class="m24off-f"><span>E-Mail</span><input type="email" data-c="email" value="<?php echo esc_attr( $customer['email'] ); ?>"></label>
				<div class="m24off-seg" data-c-kundentyp>
					<button type="button" class="m24off-segbtn<?php echo 'b2b' === $customer['kundentyp'] ? ' is-on' : ''; ?>" data-kt="b2b">Gewerblich (B2B)</button>
					<button type="button" class="m24off-segbtn<?php echo 'b2c' === $customer['kundentyp'] ? ' is-on' : ''; ?>" data-kt="b2c">Privat (B2C)</button>
				</div>
				<label class="m24off-f"><span>Land (ISO)</span><input type="text" data-c="land" maxlength="2" value="<?php echo esc_attr( $customer['land'] ); ?>" placeholder="DE"></label>
			</section>

			<section class="m24off-card">
				<h2>Positionen</h2>
				<div class="m24off-items" data-items></div>
				<button type="button" class="m24off-add" data-add-pos>+ Position hinzufügen</button>
			</section>

			<section class="m24off-card">
				<h2>Zusatzpositionen</h2>
				<div class="m24off-extras" data-extras></div>
			</section>

			<section class="m24off-card">
				<h2>Lieferzeit &amp; Gültigkeit</h2>
				<label class="m24off-f"><span>Lieferzeit</span><input type="text" data-delivery placeholder="z. B. 2–3 Wochen"></label>
				<p class="m24off-note">Angebot gültig <?php echo (int) M24_Offers::VALID_DAYS; ?> Tage (automatischer Ablauf).</p>
			</section>

			<section class="m24off-card">
				<h2>Steuer (manuell wählen)</h2>
				<select data-tax-mode class="m24off-select">
					<option value="">— Steuerfall wählen —</option>
					<?php foreach ( M24_Offers::tax_modes() as $k => $m ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $m['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="m24off-f" data-oss hidden><span>OSS-Satz % (Zielland)</span><input type="number" step="0.1" data-tax-rate placeholder="z. B. 20"></label>
				<p class="m24off-taxnote" data-tax-note></p>
			</section>

			<section class="m24off-card m24off-sum">
				<div class="m24off-sumline"><span>Zwischensumme (netto)</span><strong data-sum-net>0,00 €</strong></div>
				<div class="m24off-sumline" data-sum-25a-wrap hidden><span>§25a differenzbesteuert</span><strong data-sum-25a>0,00 €</strong></div>
				<div class="m24off-sumline" data-sum-tax-wrap hidden><span data-tax-label>MwSt</span><strong data-sum-tax>0,00 €</strong></div>
				<div class="m24off-sumline m24off-total"><span>Gesamt</span><strong data-sum-total>0,00 €</strong></div>
			</section>

			<div class="m24off-actions">
				<button type="button" class="m24off-btn m24off-btn-ghost" data-action="text">Mit Text antworten</button>
				<button type="button" class="m24off-btn m24off-btn-blue" data-action="send">Als verbindliches Angebot senden</button>
			</div>
			<p class="m24off-status" data-status role="status"></p>
		</div>

		<!-- Teile-Picker (Overlay) -->
		<div class="m24off-picker" data-picker hidden>
			<div class="m24off-picker-head">
				<input type="search" data-picker-q placeholder="Teil suchen (Name / Art.-Nr.)">
				<button type="button" class="m24off-picker-x" data-picker-close aria-label="Schließen">&times;</button>
			</div>
			<div class="m24off-chips" data-picker-cats>
				<button type="button" class="m24off-chip is-on" data-cat="">Alle</button>
				<button type="button" class="m24off-chip" data-cat="neu">Neu</button>
				<button type="button" class="m24off-chip" data-cat="gebraucht">Gebraucht</button>
			</div>
			<div class="m24off-modellrow">Modell: <strong data-picker-modell><?php echo esc_html( $src['src_modell'] ); ?></strong> <button type="button" class="m24off-linkbtn" data-picker-modellchg>ändern</button></div>
			<div class="m24off-picker-list" data-picker-list></div>
		</div>

		<script>window.M24Offers = <?php echo wp_json_encode( $cfg ); // phpcs:ignore WordPress.Security.EscapeOutput ?>;</script>
		<script src="<?php echo esc_url( self::assets_url( 'assets/js/m24-offers.js' ) ); ?>"></script>
		</body></html>
		<?php
		exit;
	}

	/* ── Kunden-Ansicht (?m24_angebot={token}) ─────────────────────────── */

	public static function customer() {
		if ( empty( $_GET[ M24_Offers::QV_VIEW ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		$token = preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET[ M24_Offers::QV_VIEW ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$o = M24_Offers::get_by_token( $token );
		nocache_headers();
		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		if ( ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); }
		if ( ! $o ) {
			echo self::head( 'Angebot' ) . '</head><body class="m24off-cust"><div class="m24off-wrap"><div class="m24off-card"><p>Dieses Angebot wurde nicht gefunden.</p></div></div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		$cust   = json_decode( (string) $o->customer_json, true ) ?: array();
		$items  = json_decode( (string) $o->items_json, true ) ?: array();
		$extras = json_decode( (string) $o->extras_json, true ) ?: array();
		$is_b2c = 'b2c' === ( $cust['kundentyp'] ?? 'b2c' );
		$bank   = self::bank();

		$days = 0; $vu = (string) $o->valid_until;
		if ( $vu ) { $days = (int) floor( ( strtotime( $vu . ' 23:59:59' ) - time() ) / DAY_IN_SECONDS ); if ( $days < 0 ) { $days = 0; } }
		$status = (string) $o->status;

		echo self::head( 'Ihr Angebot ' . $o->offer_no ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		</head><body class="m24off-cust">
		<div class="m24off-wrap">
			<header class="m24off-chead">
				<div class="m24off-cno">Angebot <?php echo esc_html( $o->offer_no ); ?></div>
				<?php if ( 'offen' === $status ) : ?>
					<div class="m24off-countdown">noch <?php echo (int) $days; ?> Tag<?php echo 1 === $days ? '' : 'e'; ?> · bis <?php echo esc_html( self::date_de( $vu ) ); ?></div>
				<?php elseif ( 'bezahlt' === $status ) : ?>
					<div class="m24off-countdown is-paid">Bezahlt ✓</div>
				<?php else : ?>
					<div class="m24off-countdown is-exp">Abgelaufen</div>
				<?php endif; ?>
			</header>

			<!-- Timeline -->
			<ol class="m24off-timeline" data-status="<?php echo esc_attr( $status ); ?>">
				<li class="is-done">Angebot erhalten</li>
				<li class="<?php echo 'bezahlt' === $status ? 'is-done' : ( 'abgelaufen' === $status ? 'is-exp' : 'is-current' ); ?>"><?php echo 'bezahlt' === $status ? 'Zahlung eingegangen' : 'Gültig · Zahlung offen'; ?></li>
				<li class="<?php echo 'bezahlt' === $status ? 'is-done' : ''; ?>">Bezahlt</li>
				<li>Versand</li>
			</ol>

			<section class="m24off-card">
				<h2>Positionen</h2>
				<?php foreach ( $items as $it ) : $line = (float) $it['unit_price'] * max( 1, (int) $it['qty'] ); ?>
					<div class="m24off-crow">
						<div class="m24off-cinfo"><span class="m24off-ctitle"><?php echo esc_html( $it['title'] ); ?></span><?php if ( ! empty( $it['art_nr'] ) ) : ?><span class="m24off-cart">Art.-Nr.: <?php echo esc_html( $it['art_nr'] ); ?></span><?php endif; ?><?php if ( ! empty( $it['st25a'] ) ) : ?><span class="m24off-c25a">§25a – differenzbesteuert, keine ausweisbare USt</span><?php endif; ?></div>
						<div class="m24off-cqty">×<?php echo (int) $it['qty']; ?></div>
						<div class="m24off-cline"><?php echo esc_html( self::fmt( $line ) ); ?></div>
					</div>
				<?php endforeach; ?>
				<?php foreach ( $extras as $ex ) : if ( empty( $ex['on'] ) ) { continue; } ?>
					<div class="m24off-crow m24off-cextra"><div class="m24off-cinfo"><span class="m24off-ctitle"><?php echo esc_html( $ex['label'] ); ?></span></div><div class="m24off-cqty"></div><div class="m24off-cline"><?php echo esc_html( self::fmt( (float) $ex['amount'] ) ); ?></div></div>
				<?php endforeach; ?>
				<?php if ( $o->delivery_time ) : ?><p class="m24off-note">Lieferzeit: <?php echo esc_html( $o->delivery_time ); ?></p><?php endif; ?>
				<div class="m24off-sumline"><span>Zwischensumme (netto)</span><strong><?php echo esc_html( self::fmt( (float) $o->subtotal_net ) ); ?></strong></div>
				<?php if ( (float) $o->tax_amount > 0 ) : ?><div class="m24off-sumline"><span><?php echo esc_html( 'USt ' . rtrim( rtrim( number_format( (float) $o->tax_rate, 2, ',', '.' ), '0' ), ',' ) . ' %' ); ?></span><strong><?php echo esc_html( self::fmt( (float) $o->tax_amount ) ); ?></strong></div><?php endif; ?>
				<div class="m24off-sumline m24off-total"><span>Gesamt</span><strong><?php echo esc_html( self::fmt( (float) $o->total_gross ) ); ?></strong></div>
				<?php if ( $o->tax_note ) : ?><p class="m24off-note"><?php echo esc_html( $o->tax_note ); ?></p><?php endif; ?>
			</section>

			<?php if ( 'offen' === $status ) : ?>
			<section class="m24off-card m24off-pay">
				<h2>Zahlung</h2>
				<div class="m24off-payrow"><span>Betrag</span><strong><?php echo esc_html( self::fmt( (float) $o->total_gross ) ); ?></strong></div>
				<div class="m24off-payrow"><span>Empfänger</span><strong><?php echo esc_html( $bank['inhaber'] ); ?></strong></div>
				<div class="m24off-payrow"><span>IBAN</span><strong><?php echo esc_html( $bank['iban'] ); ?></strong></div>
				<div class="m24off-payrow"><span>BIC</span><strong><?php echo esc_html( $bank['bic'] ); ?></strong></div>
				<div class="m24off-payrow"><span>Verwendungszweck</span><strong><?php echo esc_html( $o->offer_no ); ?></strong></div>
			</section>
			<?php endif; ?>

			<section class="m24off-legal">
				<p><strong>Verbindliches Angebot (§ 145 BGB)</strong>, gültig bis <?php echo esc_html( self::date_de( $vu ) ); ?>. Der Vertrag kommt mit fristgerechtem Zahlungseingang zustande.</p>
				<?php if ( $is_b2c ) : ?><p class="m24off-widerruf">Widerrufsbelehrung: Als Verbraucher haben Sie das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die Widerrufsfrist beginnt mit Vertragsschluss. Zur Ausübung senden Sie eine eindeutige Erklärung an MOTORSPORT24 GmbH, Scharfe Lanke 109–131, 13595 Berlin bzw. service@motorsport24.de.</p><?php endif; ?>
			</section>
			<?php if ( current_user_can( 'manage_options' ) && 'offen' === $status ) : ?>
			<!-- Manueller Fallback-Schalter (nur Operator sichtbar): bezahlt setzen, falls Desk-Sync ausbleibt. -->
			<div class="m24off-card" style="text-align:center;">
				<button type="button" class="m24off-btn m24off-btn-ghost" data-mark-paid>Als bezahlt markieren (manuell)</button>
				<p class="m24off-status" data-paid-status role="status"></p>
			</div>
			<script>
			(function(){
				var b=document.querySelector('[data-mark-paid]'); if(!b) return;
				var st=document.querySelector('[data-paid-status]');
				b.addEventListener('click',function(){
					b.disabled=true;
					fetch('<?php echo esc_url_raw( rest_url( M24_Offers::NS . '/offers/mark-paid' ) ); ?>',{
						method:'POST',credentials:'same-origin',
						headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},
						body:JSON.stringify({token:'<?php echo esc_js( $token ); ?>'})
					}).then(function(r){return r.json();}).then(function(d){
						if(d&&d.ok){ st.textContent='Als bezahlt markiert — Seite neu laden.'; st.className='m24off-status is-ok'; setTimeout(function(){location.reload();},900); }
						else { b.disabled=false; st.textContent=(d&&d.message)||'Fehler.'; st.className='m24off-status is-error'; }
					}).catch(function(){ b.disabled=false; st.textContent='Fehler.'; st.className='m24off-status is-error'; });
				});
			})();
			</script>
			<?php endif; ?>
			<footer class="m24off-cfoot">MOTORSPORT24 GmbH · <a href="https://www.motorsport24.de">www.motorsport24.de</a></footer>
		</div>
		</body></html>
		<?php
		exit;
	}

	private static function bank(): array {
		return apply_filters( 'm24_offer_bank', array(
			'inhaber' => 'MOTORSPORT24 GmbH', 'bank' => 'Commerzbank AG',
			'iban' => 'DE81 1204 0000 0133 3905 00', 'bic' => 'COBADEFFXXX',
		) );
	}

	/* ── Angebots-Mail (m24_mail_shell) ─────────────────────────────────── */

	public static function mail( int $offer_id ) {
		$o = M24_Offers::get_by_id( $offer_id );
		if ( ! $o ) { return; }
		$cust   = json_decode( (string) $o->customer_json, true ) ?: array();
		$items  = json_decode( (string) $o->items_json, true ) ?: array();
		$extras = json_decode( (string) $o->extras_json, true ) ?: array();
		$email  = (string) ( $cust['email'] ?? '' );
		if ( ! is_email( $email ) ) { return; }
		$vu = self::date_de( (string) $o->valid_until );

		$rows = '';
		foreach ( $items as $it ) {
			$line = (float) $it['unit_price'] * max( 1, (int) $it['qty'] );
			$rows .= '<tr><td style="padding:6px 0;">' . esc_html( $it['title'] ) . ( ! empty( $it['art_nr'] ) ? '<br><span style="color:#8a929c;font-size:12px;">Art.-Nr.: ' . esc_html( $it['art_nr'] ) . '</span>' : '' )
				. ( ! empty( $it['st25a'] ) ? '<br><span style="color:#8a929c;font-size:11px;">§25a differenzbesteuert</span>' : '' )
				. '</td><td style="text-align:center;">×' . (int) $it['qty'] . '</td><td style="text-align:right;white-space:nowrap;">' . esc_html( self::fmt( $line ) ) . '</td></tr>';
		}
		foreach ( $extras as $ex ) {
			if ( empty( $ex['on'] ) ) { continue; }
			$rows .= '<tr><td style="padding:6px 0;color:#5a6474;">' . esc_html( $ex['label'] ) . '</td><td></td><td style="text-align:right;">' . esc_html( self::fmt( (float) $ex['amount'] ) ) . '</td></tr>';
		}

		$inner  = '<p style="margin:0 0 14px;">Guten Tag' . ( ! empty( $cust['name'] ) ? ' ' . esc_html( $cust['name'] ) : '' ) . ',</p>';
		$inner .= '<p style="margin:0 0 14px;">anbei Ihr verbindliches Angebot <strong>' . esc_html( $o->offer_no ) . '</strong>' . ( $vu ? ', gültig bis <strong>' . esc_html( $vu ) . '</strong>' : '' ) . '.</p>';
		$inner .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . $rows // phpcs:ignore WordPress.Security.EscapeOutput — Teile bereits escaped
			. '<tr><td colspan="2" style="padding-top:10px;border-top:1px solid #e6e9ee;">Zwischensumme (netto)</td><td style="text-align:right;padding-top:10px;border-top:1px solid #e6e9ee;">' . esc_html( self::fmt( (float) $o->subtotal_net ) ) . '</td></tr>';
		if ( (float) $o->tax_amount > 0 ) {
			$inner .= '<tr><td colspan="2">USt</td><td style="text-align:right;">' . esc_html( self::fmt( (float) $o->tax_amount ) ) . '</td></tr>';
		}
		$inner .= '<tr><td colspan="2" style="font-weight:700;padding-top:6px;">Gesamt</td><td style="text-align:right;font-weight:700;padding-top:6px;">' . esc_html( self::fmt( (float) $o->total_gross ) ) . '</td></tr></table>';
		if ( $o->delivery_time ) { $inner .= '<p style="margin:14px 0 0;color:#5a6474;">Lieferzeit: ' . esc_html( $o->delivery_time ) . '</p>'; }
		if ( $o->tax_note ) { $inner .= '<p style="margin:6px 0 0;color:#8a929c;font-size:12px;">' . esc_html( $o->tax_note ) . '</p>'; }
		$inner .= '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( M24_Offers::view_url( (string) $o->token ) ) . '" style="display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;">Angebot ansehen &amp; bezahlen</a></p>';
		$inner .= '<p style="margin:0 0 4px;font-size:12px;color:#8a929c;">Verbindliches Angebot (§ 145 BGB). Der Vertrag kommt mit fristgerechtem Zahlungseingang zustande.</p>';
		if ( (int) $o->account_id <= 0 ) {
			$inner .= '<p style="margin:14px 0 0;font-size:13px;">Wir haben Ihnen zusätzlich einen Link zur <strong>Konto-Anlage</strong> geschickt — nach Bestätigung liegt dieses Angebot jederzeit in Ihrer MOTORSPORT24-Garage bereit.</p>';
		}

		$html = function_exists( 'm24_mail_shell' ) ? m24_mail_shell( 'Ihr Angebot ' . $o->offer_no, $inner, array( 'lang' => 'de' ) ) : $inner;
		wp_mail( $email, 'Ihr Angebot ' . $o->offer_no . ' — MOTORSPORT24', $html, array( 'Content-Type: text/html; charset=UTF-8', 'From: MOTORSPORT24 <service@motorsport24.de>' ) );
	}
}
