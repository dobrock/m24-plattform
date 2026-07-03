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
		// Nicht eingeloggt (z. B. iPhone-Safari) → auf die Login-Seite mit redirect_to zurück zur Modal-URL,
		// damit das Modal NACH dem Login öffnet — statt still auf der Startseite zu landen.
		if ( ! is_user_logged_in() ) {
			$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
			$current = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
			nocache_headers();
			wp_safe_redirect( wp_login_url( esc_url_raw( $current ) ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) { return; } // eingeloggte Nicht-Admins: kein Zugriff
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
				<label class="m24off-f" data-oss hidden><span>USt-Satz (%) — Pflicht bei OSS, 0–27</span><input type="number" step="0.1" min="0" max="27" data-tax-rate placeholder="z. B. 20"></label>
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
		<?php
		$logo     = esc_url( apply_filters( 'm24fz_mail_logo_url', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2023/09/Logo-MOTORSPORT24.de_.gif' ) );
		$has_used = self::has_used( $items );
		$stamp    = 'offen' === $status ? 'Gültig' : ( 'bezahlt' === $status ? 'Bezahlt ✓' : 'Abgelaufen' );
		$rate_str = rtrim( rtrim( number_format( (float) $o->tax_rate, 2, ',', '.' ), '0' ), ',' );
		// Timeline-Stufenklassen.
		$s2 = 'bezahlt' === $status ? 'is-done' : ( 'abgelaufen' === $status ? 'is-exp' : 'is-active' );
		$s3 = 'bezahlt' === $status ? 'is-done' : '';
		?>
		<div class="m24off-wrap">
			<!-- A: Verlaufs-Header + Messing-Siegel -->
			<header class="m24off-hero">
				<img class="m24off-hero-logo" src="<?php echo $logo; ?>" alt="MOTORSPORT24">
				<div class="m24off-seal">
					<div class="m24off-seal-no">Angebot <?php echo esc_html( $o->offer_no ); ?></div>
					<?php if ( 'offen' === $status ) : ?><div class="m24off-seal-days">noch <?php echo (int) $days; ?> Tag<?php echo 1 === $days ? '' : 'e'; ?> · bis <?php echo esc_html( self::date_de( $vu ) ); ?></div><?php endif; ?>
					<div class="m24off-stamp is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $stamp ); ?></div>
				</div>
			</header>

			<!-- Segmentierte Timeline -->
			<div class="m24off-tl">
				<span class="m24off-tl-seg is-done">Angebot</span>
				<span class="m24off-tl-seg <?php echo esc_attr( $s2 ); ?>">Zahlung offen</span>
				<span class="m24off-tl-seg <?php echo esc_attr( $s3 ); ?>">Bezahlt</span>
				<span class="m24off-tl-seg">Versand</span>
			</div>

			<!-- Positionen als Karten -->
			<section class="m24off-card">
				<h2>Positionen</h2>
				<?php foreach ( $items as $it ) :
					$line = (float) $it['unit_price'] * max( 1, (int) $it['qty'] );
					$url  = ! empty( $it['url'] ) ? (string) $it['url'] : '';
					$tag  = '' !== $url ? 'a' : 'div';
					$att  = '' !== $url ? ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener"' : '';
					?>
					<<?php echo $tag; ?> class="m24off-pos<?php echo '' !== $url ? ' is-link' : ''; ?>"<?php echo $att; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
						<div class="m24off-pos-main">
							<span class="m24off-pos-title"><?php echo esc_html( $it['title'] ); ?></span>
							<?php if ( ! empty( $it['art_nr'] ) ) : ?><span class="m24off-cart">Art.-Nr.: <?php echo esc_html( $it['art_nr'] ); ?></span><?php endif; ?>
							<?php if ( ! empty( $it['race'] ) && ! empty( $it['race_note'] ) ) : ?><span class="m24off-pos-race"><span class="m24off-flag" aria-hidden="true"></span><?php echo esc_html( $it['race_note'] ); ?></span><?php endif; ?>
							<?php if ( ! empty( $it['st25a'] ) ) : ?><span class="m24off-c25a"><?php echo esc_html( self::st25a_line() ); ?></span><?php endif; ?>
							<?php if ( ! empty( $it['custom'] ) ) : ?><span class="m24off-c25a">Sonderanfertigung – kein Widerruf (§ 312g Abs. 2 BGB)</span><?php endif; ?>
						</div>
						<div class="m24off-pos-qty">× <?php echo (int) $it['qty']; ?></div>
						<div class="m24off-pos-line"><?php echo esc_html( self::fmt( $line ) ); ?></div>
					</<?php echo $tag; ?>>
				<?php endforeach; ?>
				<?php foreach ( $extras as $ex ) : if ( empty( $ex['on'] ) ) { continue; } ?>
					<div class="m24off-pos m24off-cextra"><div class="m24off-pos-main"><span class="m24off-pos-title"><?php echo esc_html( $ex['label'] ); ?></span></div><div class="m24off-pos-qty"></div><div class="m24off-pos-line"><?php echo esc_html( self::fmt( (float) $ex['amount'] ) ); ?></div></div>
				<?php endforeach; ?>
				<?php if ( $o->delivery_time ) : ?><p class="m24off-note">Lieferzeit: <?php echo esc_html( $o->delivery_time ); ?></p><?php endif; ?>
				<div class="m24off-sumline"><span>Zwischensumme (netto)</span><strong><?php echo esc_html( self::fmt( (float) $o->subtotal_net ) ); ?></strong></div>
				<?php if ( (float) $o->tax_amount > 0 ) : ?><div class="m24off-sumline"><span>USt <?php echo esc_html( $rate_str ); ?> %</span><strong><?php echo esc_html( self::fmt( (float) $o->tax_amount ) ); ?></strong></div><?php endif; ?>
				<div class="m24off-sumline m24off-total"><span>Gesamt</span><strong><?php echo esc_html( self::fmt( (float) $o->total_gross ) ); ?></strong></div>
				<?php if ( $o->tax_note && (float) $o->tax_amount <= 0 ) : ?><p class="m24off-note"><?php echo esc_html( $o->tax_note ); ?></p><?php endif; ?>
			</section>

			<!-- E: Bindungssatz -->
			<p class="m24off-binding"><?php echo esc_html( self::bindungssatz() ); ?></p>

			<!-- C: Rechts-Accordions -->
			<?php if ( $is_b2c ) : ?>
			<details class="m24off-acc"><summary>Widerrufsrecht (Verbraucher)</summary><div class="m24off-acc-body"><?php echo self::widerruf_accordion( $items ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></details>
			<?php endif; ?>
			<details class="m24off-acc"><summary>Gewährleistung &amp; Steuer</summary><div class="m24off-acc-body"><?php echo self::gewaehr_accordion( $is_b2c, $has_used ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></details>

			<?php if ( 'offen' === $status ) : ?>
			<!-- B/D: Checkbox-Gate → Zahlungs-Box (Bankdaten erst nach Klick im DOM) -->
			<section class="m24off-card m24off-gate">
				<label class="m24off-check"><input type="checkbox" data-gate> <span><?php echo esc_html( self::checkbox_text( $is_b2c, $has_used ) ); ?></span></label>
				<button type="button" class="m24off-btn m24off-btn-blue" data-pay disabled>Angebot bezahlen</button>
				<div class="m24off-paybox" data-paybox hidden></div>
			</section>
			<?php endif; ?>

			<?php if ( current_user_can( 'manage_options' ) && 'offen' === $status ) : ?>
			<div class="m24off-card" style="text-align:center;">
				<button type="button" class="m24off-btn m24off-btn-ghost" data-mark-paid>Als bezahlt markieren (manuell)</button>
				<p class="m24off-status" data-paid-status role="status"></p>
			</div>
			<?php endif; ?>

			<footer class="m24off-cfoot"><?php echo esc_html( self::company_line() ); ?> · <a href="https://www.motorsport24.de">www.motorsport24.de</a></footer>
		</div>
		<script>
		(function(){
			// B: Checkbox-Gate + Reveal (Bankdaten erst nach Klick in den DOM injizieren).
			var chk=document.querySelector('[data-gate]'), pay=document.querySelector('[data-pay]'), box=document.querySelector('[data-paybox]');
			var BANK=<?php echo wp_json_encode( array(
				'inhaber' => $bank['inhaber'], 'iban' => $bank['iban'], 'bic' => $bank['bic'],
				'zweck'   => (string) $o->offer_no, 'betrag' => self::fmt( (float) $o->total_gross ),
			) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>;
			function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
			if(chk&&pay){ chk.addEventListener('change',function(){ pay.disabled=!chk.checked; }); }
			if(pay&&box){ pay.addEventListener('click',function(){
				if(pay.disabled) return;
				box.innerHTML='<h3>Zahlung per Überweisung</h3>'
					+row('Betrag',BANK.betrag,false)
					+row('Empfänger',BANK.inhaber,false)
					+row('IBAN',BANK.iban,true)
					+row('BIC',BANK.bic,false)
					+row('Verwendungszweck',BANK.zweck,true);
				box.hidden=false; pay.style.display='none';
				box.scrollIntoView({behavior:'smooth',block:'nearest'});
			}); }
			function row(label,val,copy){
				return '<div class="m24off-payrow"><span>'+esc(label)+'</span><strong'+(copy?' class="m24off-copy" data-copy="'+esc(val)+'" role="button" tabindex="0" title="Antippen zum Kopieren"':'')+'>'+esc(val)+(copy?' <em class="m24off-copyhint">kopieren</em>':'')+'</strong></div>';
			}
			document.addEventListener('click',function(e){
				var c=e.target.closest?e.target.closest('[data-copy]'):null; if(!c) return;
				var v=c.getAttribute('data-copy');
				var done=function(){ var h=c.querySelector('.m24off-copyhint'); if(h){h.textContent='Kopiert ✓';} c.classList.add('is-copied'); };
				if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(v).then(done).catch(done); }
				else { var t=document.createElement('textarea'); t.value=v; document.body.appendChild(t); t.select(); try{document.execCommand('copy');}catch(x){} document.body.removeChild(t); done(); }
			});
			// Operator-Fallback „bezahlt markieren".
			var mb=document.querySelector('[data-mark-paid]');
			if(mb){ var mst=document.querySelector('[data-paid-status]');
				mb.addEventListener('click',function(){ mb.disabled=true;
					fetch('<?php echo esc_url_raw( rest_url( M24_Offers::NS . '/offers/mark-paid' ) ); ?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},body:JSON.stringify({token:'<?php echo esc_js( $token ); ?>'})})
					.then(function(r){return r.json();}).then(function(d){ if(d&&d.ok){ mst.textContent='Als bezahlt markiert — Seite neu laden.'; mst.className='m24off-status is-ok'; setTimeout(function(){location.reload();},900); } else { mb.disabled=false; mst.textContent=(d&&d.message)||'Fehler.'; mst.className='m24off-status is-error'; } })
					.catch(function(){ mb.disabled=false; mst.textContent='Fehler.'; mst.className='m24off-status is-error'; });
				});
			}
		})();
		</script>
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

	/* ── Rechtstexte (geteilt zwischen Kunden-Ansicht + Mail) ───────────── */

	private static function company_line(): string {
		return apply_filters( 'm24_offer_company_line', 'MOTORSPORT24 GmbH · Scharfe Lanke 109–131 · Haus 113a · 13595 Berlin' );
	}
	private static function legal_links(): array {
		// Live verifizierte Slugs (200): /widerruf/ (NICHT /widerrufsrecht/ — 404), /agb/, /impressum/, /datenschutz/.
		return apply_filters( 'm24_offer_legal_links', array(
			'Impressum'   => 'https://www.motorsport24.de/impressum/',
			'AGB'         => 'https://www.motorsport24.de/agb/',
			'Datenschutz' => 'https://www.motorsport24.de/datenschutz/',
			'Widerruf'    => 'https://www.motorsport24.de/widerruf/',
		) );
	}
	private static function widerruf_url(): string {
		$l = self::legal_links();
		return isset( $l['Widerruf'] ) ? (string) $l['Widerruf'] : 'https://www.motorsport24.de/widerruf/';
	}
	/** §145/§146-Vertragsklausel (alle Angebote). $vu = Gültig-bis (formatiert). */
	private static function contract_clause( string $vu ): string {
		return 'Dieses Angebot ist verbindlich (§ 145 BGB) und gültig bis ' . $vu . '. Ein Kaufvertrag kommt zustande, wenn der vollständige Rechnungsbetrag innerhalb dieser Frist auf unserem Geschäftskonto eingeht (Annahme durch Zahlung). Geht die Zahlung nicht fristgerecht ein, erlischt das Angebot (§ 146 BGB).';
	}
	private static function st25a_line(): string {
		return 'Differenzbesteuerung gem. § 25a UStG – Umsatzsteuer wird nicht gesondert ausgewiesen.';
	}
	/** Vollständige B2C-Widerrufsbelehrung (Art. 246a EGBGB) + Muster-Widerrufsformular + § 312g Abs. 2. */
	private static function widerruf_html( array $items ): string {
		$custom = array();
		foreach ( $items as $it ) { if ( ! empty( $it['custom'] ) ) { $custom[] = (string) $it['title']; } }
		$h  = '<h3 style="font-size:14px;margin:14px 0 6px;">Widerrufsbelehrung</h3>';
		$h .= '<p><strong>Widerrufsrecht.</strong> Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag, an dem Sie oder ein von Ihnen benannter Dritter, der nicht der Beförderer ist, die Waren in Besitz genommen haben bzw. hat. Um Ihr Widerrufsrecht auszuüben, müssen Sie uns (MOTORSPORT24 GmbH, Scharfe Lanke 109–131, 13595 Berlin, E-Mail service@motorsport24.de) mittels einer eindeutigen Erklärung (z. B. ein mit der Post versandter Brief oder eine E-Mail) über Ihren Entschluss, diesen Vertrag zu widerrufen, informieren. Sie können dafür das beigefügte Muster-Widerrufsformular verwenden, das jedoch nicht vorgeschrieben ist. Zur Wahrung der Widerrufsfrist reicht es aus, dass Sie die Mitteilung über die Ausübung des Widerrufsrechts vor Ablauf der Widerrufsfrist absenden.</p>';
		$h .= '<p><strong>Folgen des Widerrufs.</strong> Wenn Sie diesen Vertrag widerrufen, haben wir Ihnen alle Zahlungen, die wir von Ihnen erhalten haben, einschließlich der Lieferkosten (mit Ausnahme der zusätzlichen Kosten, die sich daraus ergeben, dass Sie eine andere Art der Lieferung als die von uns angebotene, günstigste Standardlieferung gewählt haben), unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag zurückzuzahlen, an dem die Mitteilung über Ihren Widerruf dieses Vertrags bei uns eingegangen ist. Für diese Rückzahlung verwenden wir dasselbe Zahlungsmittel, das Sie bei der ursprünglichen Transaktion eingesetzt haben, es sei denn, mit Ihnen wurde ausdrücklich etwas anderes vereinbart. Wir können die Rückzahlung verweigern, bis wir die Waren wieder zurückerhalten haben oder bis Sie den Nachweis erbracht haben, dass Sie die Waren zurückgesandt haben, je nachdem, welches der frühere Zeitpunkt ist. Sie haben die Waren unverzüglich und in jedem Fall spätestens binnen vierzehn Tagen ab dem Tag, an dem Sie uns über den Widerruf dieses Vertrags unterrichten, an uns zurückzusenden. Die Frist ist gewahrt, wenn Sie die Waren vor Ablauf der Frist von vierzehn Tagen absenden. Sie tragen die unmittelbaren Kosten der Rücksendung der Waren. Sie müssen für einen etwaigen Wertverlust der Waren nur aufkommen, wenn dieser Wertverlust auf einen zur Prüfung der Beschaffenheit, Eigenschaften und Funktionsweise der Waren nicht notwendigen Umgang mit ihnen zurückzuführen ist.</p>';
		$h .= '<h4 style="font-size:13px;margin:12px 0 4px;">Muster-Widerrufsformular</h4>';
		$h .= '<p>(Wenn Sie den Vertrag widerrufen wollen, füllen Sie bitte dieses Formular aus und senden Sie es zurück.)</p>';
		$h .= '<p>An MOTORSPORT24 GmbH, Scharfe Lanke 109–131, 13595 Berlin, E-Mail service@motorsport24.de:<br>'
			. '— Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über den Kauf der folgenden Waren (*):<br>'
			. '— Bestellt am (*)/erhalten am (*):<br>— Name des/der Verbraucher(s):<br>— Anschrift des/der Verbraucher(s):<br>'
			. '— Unterschrift des/der Verbraucher(s) (nur bei Mitteilung auf Papier):<br>— Datum:<br>(*) Unzutreffendes streichen.</p>';
		if ( ! empty( $custom ) ) {
			$h .= '<p><strong>Ausschluss des Widerrufsrechts (§ 312g Abs. 2 BGB).</strong> Das Widerrufsrecht besteht nicht bei Verträgen zur Lieferung von Waren, die nicht vorgefertigt sind und für deren Herstellung eine individuelle Auswahl oder Bestimmung durch den Verbraucher maßgeblich ist oder die eindeutig auf die persönlichen Bedürfnisse des Verbrauchers zugeschnitten sind. Dies betrifft folgende Position(en): ' . esc_html( implode( ', ', $custom ) ) . '.</p>';
		} else {
			$h .= '<p><strong>Ausschluss des Widerrufsrechts (§ 312g Abs. 2 BGB).</strong> Bei nach Kundenspezifikation angefertigten oder eindeutig auf persönliche Bedürfnisse zugeschnittenen Waren besteht kein Widerrufsrecht.</p>';
		}
		return $h;
	}
	private static function has_custom( array $items ): bool {
		foreach ( $items as $it ) { if ( ! empty( $it['custom'] ) ) { return true; } }
		return false;
	}
	private static function has_used( array $items ): bool {
		foreach ( $items as $it ) { if ( ! empty( $it['used'] ) ) { return true; } }
		return false;
	}
	/** Bindungssatz (alle, ohne Paragraphen — die stehen in den Accordions/Belehrung). */
	private static function bindungssatz(): string {
		return 'Verbindliches Angebot – mit fristgerechtem Zahlungseingang gilt es als angenommen und der Kaufvertrag kommt zustande.';
	}
	/** Accordion „Widerrufsrecht (Verbraucher)" — nur B2C. Link → /widerruf/ + Muster-Widerrufsformular. */
	private static function widerruf_accordion( array $items ): string {
		$custom = array();
		foreach ( $items as $it ) { if ( ! empty( $it['custom'] ) ) { $custom[] = (string) $it['title']; } }
		$h  = '<p>Als Verbraucher haben Sie das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die Frist beginnt mit dem Tag, an dem Sie oder ein von Ihnen benannter Dritter, der nicht der Beförderer ist, die Waren in Besitz genommen haben.</p>';
		$h .= '<p>Vollständige <a href="' . esc_url( self::widerruf_url() ) . '" target="_blank" rel="noopener">Widerrufsbelehrung &amp; Muster-Widerrufsformular</a>.</p>';
		if ( ! empty( $custom ) ) {
			$h .= '<p><strong>Ausschluss (§ 312g Abs. 2 BGB):</strong> Kein Widerrufsrecht bei nach Kundenspezifikation angefertigten oder eindeutig auf persönliche Bedürfnisse zugeschnittenen Waren – betrifft: ' . esc_html( implode( ', ', $custom ) ) . '.</p>';
		}
		return $h;
	}
	/** Accordion „Gewährleistung & Steuer" — modusabhängig. */
	private static function gewaehr_accordion( bool $is_b2c, bool $has_used ): string {
		$h = '';
		if ( ! $is_b2c ) {
			$h .= '<p><strong>Gewährleistung.</strong> Verkauf im Rahmen eines Handelsgeschäfts unter Ausschluss der Sachmängelhaftung. Ausgenommen sind Arglist, ausdrücklich übernommene Garantien sowie Schäden aus Vorsatz, grober Fahrlässigkeit oder der Verletzung von Leben, Körper und Gesundheit.</p>';
		} elseif ( $has_used ) {
			// Mixed-safe: Klausel nur für die als gebraucht gekennzeichneten Artikel (nicht „Es handelt sich um
			// gebrauchte Ware"). Bei reinen Neuware-Angeboten wird dieser Zweig gar nicht erreicht (has_used=false).
			$h .= '<p><strong>Gewährleistung.</strong> Für als gebraucht gekennzeichnete Artikel wird die Verjährungsfrist für Mängelansprüche auf ein Jahr ab Ablieferung verkürzt. Dies gilt nicht für Arglist, ausdrücklich übernommene Garantien sowie Schäden aus Vorsatz, grober Fahrlässigkeit oder der Verletzung von Leben, Körper und Gesundheit.</p>';
		}
		$h .= '<p>' . esc_html( self::st25a_line() ) . ' (bei entsprechend gekennzeichneten Positionen).</p>';
		$h .= '<p><strong>Anbieter:</strong> ' . esc_html( self::company_line() ) . '</p>';
		$links = array(); $ll = self::legal_links();
		foreach ( array( 'Impressum', 'AGB', 'Datenschutz' ) as $k ) {
			if ( isset( $ll[ $k ] ) ) { $links[] = '<a href="' . esc_url( $ll[ $k ] ) . '" target="_blank" rel="noopener">' . esc_html( $k ) . '</a>'; }
		}
		$h .= '<p style="text-align:center;">' . implode( ' · ', $links ) . '</p>';
		return $h;
	}
	/** Gate-Checkbox-Text (trägt die gesonderte Vereinbarung bei B2C + Gebrauchtware). */
	private static function checkbox_text( bool $is_b2c, bool $has_used ): string {
		if ( $is_b2c && $has_used ) {
			return 'Ich habe die Widerrufsbelehrung sowie die Zahlungs- und Gewährleistungsbedingungen gelesen. Bei gebrauchten Artikeln stimme ich der Verkürzung der Verjährung für Mängelansprüche auf ein Jahr ausdrücklich und gesondert zu.';
		}
		if ( $is_b2c ) {
			return 'Ich habe die Widerrufsbelehrung sowie die Zahlungs- und Gewährleistungsbedingungen gelesen.';
		}
		return 'Ich habe die Zahlungs- und Gewährleistungsbedingungen gelesen.';
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
			$line  = (float) $it['unit_price'] * max( 1, (int) $it['qty'] );
			$url   = ! empty( $it['url'] ) ? (string) $it['url'] : '';
			$title = '' !== $url
				? '<a href="' . esc_url( $url ) . '" target="_blank" style="color:#14161a;font-weight:600;text-decoration:none;">' . esc_html( $it['title'] ) . '</a>'
				: '<span style="font-weight:600;">' . esc_html( $it['title'] ) . '</span>';
			$rows .= '<tr><td style="padding:6px 12px 6px 0;">' . $title // phpcs:ignore WordPress.Security.EscapeOutput — Titel escaped
				. ( ! empty( $it['art_nr'] ) ? '<br><span style="color:#8a929c;font-size:12px;">Art.-Nr.: ' . esc_html( $it['art_nr'] ) . '</span>' : '' )
				. ( ! empty( $it['race'] ) && ! empty( $it['race_note'] ) ? '<br><span style="color:#93762f;font-size:11.5px;">🇩🇪 ' . esc_html( $it['race_note'] ) . '</span>' : '' )
				. ( ! empty( $it['st25a'] ) ? '<br><span style="color:#8a929c;font-size:11px;">' . esc_html( self::st25a_line() ) . '</span>' : '' )
				. ( ! empty( $it['custom'] ) ? '<br><span style="color:#9a6b25;font-size:11px;">Sonderanfertigung – kein Widerruf (§ 312g Abs. 2 BGB)</span>' : '' )
				. '</td><td style="text-align:center;padding:6px 14px;white-space:nowrap;color:#5a6474;">× ' . (int) $it['qty'] . '</td><td style="text-align:right;white-space:nowrap;">' . esc_html( self::fmt( $line ) ) . '</td></tr>';
		}
		foreach ( $extras as $ex ) {
			if ( empty( $ex['on'] ) ) { continue; }
			$rows .= '<tr><td style="padding:6px 0;color:#5a6474;">' . esc_html( $ex['label'] ) . '</td><td></td><td style="text-align:right;">' . esc_html( self::fmt( (float) $ex['amount'] ) ) . '</td></tr>';
		}

		$inner  = '<p style="margin:0 0 14px;">Guten Tag' . ( ! empty( $cust['name'] ) ? ' ' . esc_html( $cust['name'] ) : '' ) . ',</p>';
		$inner .= '<p style="margin:0 0 14px;">anbei Ihr verbindliches Angebot <strong>' . esc_html( $o->offer_no ) . '</strong>' . ( $vu ? ', gültig bis <strong>' . esc_html( $vu ) . '</strong>' : '' ) . '.</p>';
		$inner .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . $rows // phpcs:ignore WordPress.Security.EscapeOutput — Teile bereits escaped
			. '<tr><td colspan="2" style="padding-top:10px;border-top:1px solid #e6e9ee;">Zwischensumme (netto)</td><td style="text-align:right;padding-top:10px;border-top:1px solid #e6e9ee;">' . esc_html( self::fmt( (float) $o->subtotal_net ) ) . '</td></tr>';
		$rate_str = rtrim( rtrim( number_format( (float) $o->tax_rate, 2, ',', '.' ), '0' ), ',' );
		if ( (float) $o->tax_amount > 0 ) {
			$inner .= '<tr><td colspan="2">USt ' . esc_html( $rate_str ) . ' %</td><td style="text-align:right;">' . esc_html( self::fmt( (float) $o->tax_amount ) ) . '</td></tr>';
		}
		$inner .= '<tr><td colspan="2" style="font-weight:700;padding-top:6px;">Gesamt</td><td style="text-align:right;font-weight:700;padding-top:6px;">' . esc_html( self::fmt( (float) $o->total_gross ) ) . '</td></tr></table>';
		if ( $o->delivery_time ) { $inner .= '<p style="margin:14px 0 0;color:#5a6474;">Lieferzeit: ' . esc_html( $o->delivery_time ) . '</p>'; }
		// Nur bei Netto-Modi die erklärende Steuer-Note zeigen (keine „zzgl. … MwSt."-Zeile).
		if ( $o->tax_note && (float) $o->tax_amount <= 0 ) { $inner .= '<p style="margin:6px 0 0;color:#8a929c;font-size:12px;">' . esc_html( $o->tax_note ) . '</p>'; }
		$inner .= '<p style="margin:22px 0;text-align:center;"><a href="' . esc_url( M24_Offers::view_url( (string) $o->token ) ) . '" style="display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;">Angebot ansehen &amp; bezahlen</a></p>';
		// E: Bindungssatz (ohne Paragraphen), präzise Paragraphen bleiben in der Ansicht/Belehrung.
		$inner .= '<p style="margin:16px 0 4px;font-size:12.5px;color:#5a6474;line-height:1.6;">' . esc_html( self::bindungssatz() ) . '</p>';
		// Pflichtangaben.
		$inner .= '<p style="margin:8px 0 0;font-size:12px;color:#8a929c;line-height:1.6;"><strong>Anbieter:</strong> ' . esc_html( self::company_line() )
			. '<br><strong>Gesamtpreis:</strong> ' . esc_html( self::fmt( (float) $o->total_gross ) ) . ' (inkl. etwaiger Steuern und ausgewiesener Nebenkosten)'
			. ( $o->delivery_time ? '<br><strong>Lieferzeit:</strong> ' . esc_html( $o->delivery_time ) : '' ) . '</p>';
		// B2C: kurzer Widerruf-Hinweis + Link auf /widerruf/ (vollständige Belehrung dort).
		if ( 'b2c' === ( $cust['kundentyp'] ?? 'b2c' ) ) {
			$inner .= '<p style="margin:12px 0 0;font-size:12px;color:#8a929c;line-height:1.6;">Als Verbraucher steht Ihnen ein 14-tägiges Widerrufsrecht (Fristbeginn mit Warenerhalt) zu — '
				. '<a href="' . esc_url( self::widerruf_url() ) . '" target="_blank" style="color:#1f74c4;">Widerrufsbelehrung &amp; Muster-Widerrufsformular</a>.'
				. ( self::has_custom( $items ) ? ' Für Sonderanfertigungen besteht kein Widerrufsrecht (§ 312g Abs. 2 BGB).' : '' ) . '</p>';
		}
		// Pflicht-Links.
		$links = array();
		foreach ( self::legal_links() as $lbl => $lurl ) { $links[] = '<a href="' . esc_url( $lurl ) . '" style="color:#1f74c4;">' . esc_html( $lbl ) . '</a>'; }
		$inner .= '<p style="margin:12px 0 0;font-size:12px;color:#8a929c;text-align:center;">' . implode( ' &middot; ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput — Links escaped
		if ( (int) $o->account_id <= 0 ) {
			$inner .= '<p style="margin:14px 0 0;font-size:13px;">Wir haben Ihnen zusätzlich einen Link zur <strong>Konto-Anlage</strong> geschickt — nach Bestätigung liegt dieses Angebot jederzeit in Ihrer MOTORSPORT24-Garage bereit.</p>';
		}

		$html = function_exists( 'm24_mail_shell' ) ? m24_mail_shell( 'Ihr Angebot ' . $o->offer_no, $inner, array( 'lang' => 'de' ) ) : $inner;
		wp_mail( $email, 'Ihr Angebot ' . $o->offer_no . ' — MOTORSPORT24', $html, array( 'Content-Type: text/html; charset=UTF-8', 'From: MOTORSPORT24 <service@motorsport24.de>' ) );
	}
}
