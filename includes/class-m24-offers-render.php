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
			. '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Saira:wght@400;500;600;700;800&family=Saira+Condensed:wght@600;700&display=swap" rel="stylesheet">'
			. '<link rel="stylesheet" href="' . esc_url( self::assets_url( 'assets/css/m24-offers.css' ) ) . '">';
	}
	private static function fmt( float $v ): string {
		return number_format( $v, 2, ',', '.' ) . ' €';
	}
	private static function date_de( string $ymd ): string {
		$t = $ymd ? strtotime( $ymd ) : 0;
		return $t ? ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y', $t ) : gmdate( 'd.m.Y', $t ) ) : '';
	}
	/** Kurz-Label je Steuer-Modus für die Segment-Auswahl (v2). */
	private static function tax_short( string $k ): string {
		$m = array( 'b2b_de_19' => 'DE · 19 %', 'b2b_eu_net' => 'EU B2B · netto', 'b2c_eu_oss' => 'EU B2C · OSS', 'drittland_net' => 'Drittland · netto' );
		return $m[ $k ] ?? $k;
	}
	/** v3: Klartext-Label je Steuer-Modus für das Dropdown (statt Segment-Bubbles). */
	private static function tax_dropdown_label( string $k ): string {
		$m = array(
			'b2b_de_19'     => 'Brutto — 19% MwSt. (DE)',
			'b2b_eu_net'    => 'B2B-EU — netto (Reverse Charge)',
			'b2c_eu_oss'    => 'B2C-EU — OSS',
			'drittland_net' => 'Drittland — netto + Ausfuhr',
		);
		return $m[ $k ] ?? $k;
	}
	/** v3: globale Lieferzeit-Optionen (ein Feld fürs ganze Angebot). */
	private static function delivery_options(): array {
		return array( '', 'Am Lager', '1–2 Wochen', '3–4 Wochen', '4–6 Wochen', '5–7 Wochen', '6–8 Wochen', '8–12 Wochen' );
	}
	/** Feste Reihenfolge der Steuer-Segmente (DE zuerst). */
	private static function tax_order(): array {
		return array( 'b2b_de_19', 'b2b_eu_net', 'b2c_eu_oss', 'drittland_net' );
	}
	/** Angebotssprache aus src_json.lang (de|en). */
	private static function offer_lang( $o ): string {
		$sj = json_decode( (string) $o->src_json, true );
		return ( is_array( $sj ) && 'en' === ( $sj['lang'] ?? 'de' ) ) ? 'en' : 'de';
	}
	/**
	 * Sichtbare Angebots-Labels (Chrome) DE/EN. #1: RECHTSTEXTE bleiben bewusst DE (Widerruf/Gewährleistung/§-
	 * Belehrung/Consent) bis zur anwaltlichen EN-Abnahme — hier NUR Bedien-/Struktur-Labels.
	 */
	private static function ol( string $lang ): array {
		$de = array(
			'hello' => 'Hallo', 'intro' => 'vielen Dank für deine Anfrage. Hier ist unser verbindliches Angebot:', 'valid' => 'gültig bis',
			'valid_line' => 'Gültig bis %1$s — noch %2$d Tag%3$s', 'cta_sub' => 'Online: Angebot prüfen → annehmen → Bankverbindung wird angezeigt',
			'subtotal' => 'Zwischensumme (netto)', 'vat' => 'USt', 'margin' => 'Differenzbesteuert (§ 25a)',
			'std_net' => 'Regelbesteuerte Artikel (netto)', 'total' => 'Gesamt', 'delivery' => 'Lieferzeit',
			'view_pay' => 'Angebot ansehen &amp; bezahlen', 'eyebrow' => 'Verbindliches Kaufangebot', 'offer' => 'Angebot',
			'items' => 'Positionen', 'accept' => 'Angebot annehmen', 'paid' => 'Bezahlt', 'expired' => 'Abgelaufen',
			'provider' => 'Anbieter', 'total_price' => 'Gesamtpreis', 'incl_taxes' => '(inkl. etwaiger Steuern und ausgewiesener Nebenkosten)',
			'variant' => 'Variante', 'artnr' => 'Art.-Nr.', 'used' => 'gebraucht', 'not_found' => 'Dieses Angebot wurde nicht gefunden.',
			'your_offer' => 'Dein Angebot',
		);
		$en = array(
			'hello' => 'Hello', 'intro' => 'thank you for your inquiry. Here is our binding offer:', 'valid' => 'valid until',
			'valid_line' => 'Valid until %1$s — %2$d day%3$s left', 'cta_sub' => 'Online: review the offer → accept → bank details are shown',
			'subtotal' => 'Subtotal (net)', 'vat' => 'VAT', 'margin' => 'Margin scheme (§ 25a)',
			'std_net' => 'Standard-rated items (net)', 'total' => 'Total', 'delivery' => 'Delivery time',
			'view_pay' => 'View &amp; pay offer', 'eyebrow' => 'Binding purchase offer', 'offer' => 'Offer',
			'items' => 'Items', 'accept' => 'Accept offer', 'paid' => 'Paid', 'expired' => 'Expired',
			'provider' => 'Provider', 'total_price' => 'Total price', 'incl_taxes' => '(incl. any taxes and itemised additional costs)',
			'variant' => 'Variant', 'artnr' => 'Part no.', 'used' => 'used', 'not_found' => 'This offer could not be found.',
			'your_offer' => 'Your offer',
		);
		return 'en' === $lang ? $en : $de;
	}
	/** Drittland = Land gesetzt und NICHT in der EU (für den Zoll-Auto-Vorschlag). */
	private static function is_drittland( string $land ): bool {
		$land = strtoupper( trim( $land ) );
		if ( '' === $land ) { return false; }
		$eu = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE' );
		return ! in_array( $land, $eu, true );
	}

	/* ── Operator-Modal A1 (Admin, ?m24_offer_new=1) ───────────────────── */

	public static function operator() {
		if ( empty( $_GET[ M24_Offers::QV_NEW ] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification
		// Ohne manage_options (ausgeloggt ODER eingeloggter Nicht-Admin): SOFORT auf die KLASSISCHE Login-Seite
		// (m24_classic=1 umgeht das schwere passwordless-Login-Rendering) mit redirect_to zurück zur Modal-URL.
		// KEIN Volltheme-Render, KEIN alter Anonym-Fallback (das war die Ursache der „unendlichen" Ladezeit).
		if ( ! current_user_can( 'manage_options' ) ) {
			$host    = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$uri     = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
			$current = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
			nocache_headers();
			wp_safe_redirect( add_query_arg( array( 'm24_classic' => 1, 'redirect_to' => esc_url_raw( $current ) ), wp_login_url() ) );
			exit;
		}
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

		// Paket 1E: ?from=<offer_id> → Positionen/Kunde/Lieferzeit/Steuer + Garagen-Nr. aus dem (Entwurfs-)Angebot laden.
		$prefill  = null;
		$garageNo = '';
		$from     = (int) $g( 'from' );
		if ( $from > 0 ) {
			$o = M24_Offers::get_by_id( $from );
			if ( $o ) {
				$its  = json_decode( (string) $o->items_json, true );
				$cj   = json_decode( (string) $o->customer_json, true );
				$sj   = json_decode( (string) $o->src_json, true );
				$cj   = is_array( $cj ) ? $cj : array();
				$sj   = is_array( $sj ) ? $sj : array();
				$customer = array(
					'name' => (string) ( $cj['name'] ?? $customer['name'] ),
					'email' => strtolower( (string) ( $cj['email'] ?? $customer['email'] ) ),
					'kundentyp' => in_array( ( $cj['kundentyp'] ?? '' ), array( 'b2b', 'b2c' ), true ) ? $cj['kundentyp'] : $customer['kundentyp'],
					'land' => strtoupper( substr( (string) ( $cj['land'] ?? $customer['land'] ), 0, 2 ) ), 'firma' => '',
				);
				$garageNo = (string) ( $sj['garage_no'] ?? '' );
				$prefill  = array(
					'items'      => is_array( $its ) ? array_values( $its ) : array(),
					'delivery'   => (string) $o->delivery_time,
					'tax_mode'   => (string) $o->tax_mode,
					'tax_rate'   => (float) $o->tax_rate,
					'salutation' => (string) ( $sj['salutation'] ?? '' ), // v3: Anschreiben aus src_json
					'note'       => (string) ( $sj['note'] ?? '' ),
					'offer_id'   => $from,
				);
			}
		}

		// ?from_inquiry=<id> → Positionen + Kunde aus einer Sammelanfrage (m24_inquiry) vorbefüllen.
		$from_inquiry = (int) $g( 'from_inquiry' );
		if ( null === $prefill && $from_inquiry > 0 && class_exists( 'M24_Inquiries_Storage' ) && M24_Inquiries_Storage::CPT_SLUG === get_post_type( $from_inquiry ) ) {
			$its      = get_post_meta( $from_inquiry, '_m24_items', true );
			$items_pf = array();
			if ( is_array( $its ) ) {
				foreach ( $its as $it ) {
					$title = sanitize_text_field( (string) ( $it['art'] ?? '' ) );
					if ( '' === $title ) { continue; }
					$raw = trim( (string) ( $it['price'] ?? '' ) );
					if ( false !== strpos( $raw, ',' ) && false !== strpos( $raw, '.' ) ) { $raw = str_replace( '.', '', $raw ); $raw = str_replace( ',', '.', $raw ); }
					elseif ( false !== strpos( $raw, ',' ) ) { $raw = str_replace( ',', '.', $raw ); }
					$raw = preg_replace( '/[^0-9.\-]/', '', $raw );
					$items_pf[] = array(
						'teil_id'    => 0,
						'title'      => $title,
						'art_nr'     => sanitize_text_field( (string) ( $it['src_art_nr'] ?? '' ) ),
						'variant'    => sanitize_text_field( (string) ( $it['src_variant'] ?? '' ) ), // #6: Variante in den Operator-Prefill
						'qty'        => max( 1, (int) ( $it['qty'] ?? 1 ) ),
						'unit_price' => is_numeric( $raw ) ? (float) $raw : 0.0,
						'tax25a'     => false,
						'custom'     => false,
					);
				}
			}
			$vor  = (string) get_post_meta( $from_inquiry, '_m24_vorname', true );
			$nach = (string) get_post_meta( $from_inquiry, '_m24_nachname', true );
			$biz  = (string) get_post_meta( $from_inquiry, '_m24_biz', true );
			$customer = array(
				'name'      => trim( $vor . ' ' . $nach ),
				'email'     => strtolower( (string) get_post_meta( $from_inquiry, '_m24_email', true ) ),
				'kundentyp' => ( '1' === $biz ) ? 'b2b' : 'b2c',
				'land'      => strtoupper( substr( (string) get_post_meta( $from_inquiry, '_m24_land', true ), 0, 2 ) ),
				'firma'     => (string) get_post_meta( $from_inquiry, '_m24_firma', true ),
			);
			$prefill = array( 'items' => $items_pf, 'delivery' => '', 'tax_mode' => '', 'tax_rate' => 0.0, 'inquiry_id' => $from_inquiry );
		}

		$cfg = array(
			'rest'     => esc_url_raw( rest_url( M24_Offers::NS . '/offers' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'presets'  => M24_Offers::extra_presets(),
			'taxModes' => M24_Offers::tax_modes(),
			'customer' => $customer,
			'src'      => $src,
			'validDays'=> M24_Offers::VALID_DAYS,
			'prefill'  => $prefill,
			'garageNo' => $garageNo,
			'lands'    => function_exists( 'm24_inquiry_countries' ) ? m24_inquiry_countries() : array( 'DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz' ),
			'nextNo'   => M24_Offers::peek_number(),
			// #2: Zoll-Chip automatisch vorschlagen, wenn Kunden-Land ≠ EU (Drittland). Manuell bleibt immer möglich.
			'custIsDrittland' => self::is_drittland( (string) $customer['land'] ),
		);

		while ( ob_get_level() > 0 ) { ob_end_clean(); }
		if ( ! headers_sent() ) { header( 'Content-Type: text/html; charset=utf-8' ); }
		echo self::head( 'Angebot erstellen' ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		</head><body class="m24off-op m24off-v2">
		<?php
		$c_kt_label = ( 'b2b' === $customer['kundentyp'] ) ? 'Geschäftskunde (B2B)' : 'Privat (B2C)';
		$c_land_nm  = function_exists( 'm24_inquiry_country_name' ) ? m24_inquiry_country_name( (string) $customer['land'] ) : (string) $customer['land'];
		$c_name     = trim( (string) $customer['name'] );
		$c_ini      = '';
		foreach ( array_slice( array_values( array_filter( explode( ' ', $c_name ) ) ), 0, 2 ) as $w ) { $c_ini .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) ); }
		if ( '' === $c_ini ) { $c_ini = 'K'; }
		$who = ( $prefill ? 'aus Anfrage' : 'Neues Angebot' ) . ( '' !== $c_name ? ' · ' . $c_name : '' ) . ( '' !== $garageNo ? ' · ' . $garageNo : '' );
		?>
		<div class="m24off-top"><div class="m24off-top-in">
			<h1>Angebot erstellen</h1>
			<span class="m24off-who"><?php echo esc_html( $who ); ?></span>
			<div class="m24off-langsw" data-langsw><span class="on" data-lang="de">DE</span><span data-lang="en">EN</span></div>
		</div></div>

		<div class="m24off-grid">
			<div class="m24off-col-main">
				<div class="m24off-card">
					<h2>Kunde <span class="m24off-hint2"><a href="#" data-cust-search>suchen/anlegen</a> · <a href="#" data-cust-edit>ändern</a></span></h2>
					<div class="m24off-kunde" data-kunde-view>
						<div class="m24off-av" data-cust-chip-av><?php echo esc_html( $c_ini ); ?></div>
						<div class="m24off-kunde-txt"><b data-cust-chip-name><?php echo esc_html( '' !== $c_name ? $c_name : '—' ); ?></b>
							<div class="kd" data-cust-chip-sub><?php echo esc_html( $customer['email'] ); ?> · <?php echo esc_html( $c_kt_label ); ?> · <?php echo esc_html( '' !== $c_land_nm ? $c_land_nm : '—' ); ?></div></div>
						<?php if ( '' !== $garageNo ) : ?><div class="m24off-kg"><?php echo esc_html( $garageNo ); ?></div><?php endif; ?>
					</div>
					<div class="m24off-kunde-edit" data-kunde-edit hidden>
						<label class="m24off-f"><span>Name</span><input type="text" data-c="name" value="<?php echo esc_attr( $customer['name'] ); ?>"></label>
						<label class="m24off-f"><span>E-Mail</span><input type="email" data-c="email" value="<?php echo esc_attr( $customer['email'] ); ?>"></label>
						<div class="m24off-seg" data-c-kundentyp>
							<button type="button" class="m24off-segbtn<?php echo 'b2b' === $customer['kundentyp'] ? ' is-on' : ''; ?>" data-kt="b2b">Geschäftskunde (B2B)</button>
							<button type="button" class="m24off-segbtn<?php echo 'b2c' === $customer['kundentyp'] ? ' is-on' : ''; ?>" data-kt="b2c">Privat (B2C)</button>
						</div>
						<label class="m24off-f"><span>Land (ISO)</span><input type="text" data-c="land" maxlength="2" value="<?php echo esc_attr( $customer['land'] ); ?>" placeholder="DE"></label>
					</div>
				</div>

				<div class="m24off-card">
					<h2>Positionen <span class="m24off-hint2">Preise aus den Artikeln — anpassbar</span></h2>
					<div data-items></div>
					<div class="m24off-stdrow" data-stdrow></div>
					<p class="m24off-stdnote">„Versicherter Versand" trägt automatisch das Lieferland des Kunden. „Zollabwicklung Deutschland" wird nur bei Drittland-Kunden vorgeschlagen (manuell immer hinzufügbar). Alle Beträge sind nach dem Hinzufügen per Klick editierbar.</p>
				</div>

				<div class="m24off-card">
					<h2>Konditionen</h2>
					<div class="m24off-two">
						<div class="m24off-fld"><label>Lieferzeit (gilt fürs ganze Angebot)</label>
							<select data-delivery><?php foreach ( self::delivery_options() as $opt ) : ?><option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( '' === $opt ? '—' : $opt ); ?></option><?php endforeach; ?></select>
						</div>
						<div class="m24off-fld"><label>Angebotssprache</label>
							<div class="m24off-seg2" data-langseg><span class="on" data-olang="de">Deutsch</span><span data-olang="en">English</span></div></div>
					</div>
					<div class="m24off-fld" style="margin-top:14px"><label>Steuer — manuell wählen</label>
						<select data-tax-mode>
							<option value="">— Steuerfall wählen —</option>
							<?php foreach ( self::tax_order() as $k ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( self::tax_dropdown_label( $k ) ); ?></option><?php endforeach; ?>
						</select>
						<div class="m24off-fld" data-oss hidden style="margin-top:10px"><label>USt-Satz (%) — Pflicht bei OSS, 0–27</label><input type="number" step="0.1" min="0" max="27" data-tax-rate placeholder="z. B. 20"></div>
						<p class="m24off-taxnote" data-tax-note></p>
					</div>
				</div>

				<div class="m24off-card">
					<h2>Anschreiben</h2>
					<div class="m24off-fld"><label>Anrede <a href="#" class="m24off-reset" data-salutation-reset>zurücksetzen</a></label><input type="text" data-salutation placeholder="Hallo {Vorname},"></div>
					<div class="m24off-fld" style="margin-top:12px"><label>Freitext (erscheint in der Mail unter der Summe)</label><textarea data-note rows="4" placeholder="Optionaler Freitext an den Kunden …"></textarea></div>
				</div>
			</div>

			<div class="m24off-col-side">
				<div class="m24off-card m24off-sum2 m24off-side">
					<h2>Angebot <?php echo esc_html( $cfg['nextNo'] ); ?> <span class="m24off-hint2">gültig <?php echo (int) M24_Offers::VALID_DAYS; ?> Tage</span></h2>
					<div data-sum-rows></div>
					<div class="m24off-tot"><span>Gesamt</span><strong data-sum-total>0,00 €</strong></div>
					<button type="button" class="m24off-send" data-action="send">Verbindliches Angebot senden<small>Mail an den Kunden · <?php echo (int) M24_Offers::VALID_DAYS; ?> Tage gültig · §145 BGB</small></button>
					<a href="#" class="m24off-alt" data-action="text">Stattdessen mit Text antworten</a>
					<p class="m24off-status" data-status role="status"></p>
				</div>
			</div>
		</div>

		<div class="m24off-mbar">
			<div><div class="mt">Gesamt brutto</div><div class="ms" data-sum-total-bar>0,00 €</div></div>
			<button type="button" data-action="send">Angebot senden</button>
		</div>

		<!-- B: Kunden-Schnellanlage (Modal) -->
		<div class="m24off-cxmodal" data-cxmodal hidden>
			<div class="m24off-cxbox">
				<div class="m24off-cxhead"><b>Kunde suchen oder anlegen</b><button type="button" class="m24off-cxx" data-cx-close aria-label="Schließen">✕</button></div>
				<div class="m24off-cxbody">
					<input type="search" data-cx-q placeholder="Name, E-Mail oder Firma …" class="m24off-cxsearch" autocomplete="off">
					<div class="m24off-cxresults" data-cx-results></div>
					<div class="m24off-cxsep">oder neu anlegen</div>
					<div class="m24off-seg" data-cx-kt>
						<button type="button" class="m24off-segbtn is-on" data-cxkt="b2c">Privat (B2C)</button>
						<button type="button" class="m24off-segbtn" data-cxkt="b2b">Geschäftskunde (B2B)</button>
					</div>
					<div class="m24off-cxgrid">
						<label class="m24off-f m24off-cx-wide m24off-cx-b2b" hidden><span>Firmenname</span><input type="text" data-cx="firmenname"></label>
						<label class="m24off-f"><span>Vorname *</span><input type="text" data-cx="vorname"></label>
						<label class="m24off-f"><span>Nachname *</span><input type="text" data-cx="nachname"></label>
						<label class="m24off-f m24off-cx-wide"><span>Straße &amp; Hausnummer *</span><input type="text" data-cx="strasse"></label>
						<label class="m24off-f"><span>Adresszusatz</span><input type="text" data-cx="adresszusatz"></label>
						<label class="m24off-f"><span>PLZ *</span><input type="text" data-cx="plz"></label>
						<label class="m24off-f"><span>Ort *</span><input type="text" data-cx="ort"></label>
						<label class="m24off-f"><span>Land *</span><input type="text" data-cx="land" maxlength="2" placeholder="DE"></label>
						<label class="m24off-f"><span>Telefon</span><input type="text" data-cx="telefon"></label>
						<label class="m24off-f"><span>E-Mail *</span><input type="email" data-cx="email"></label>
						<label class="m24off-f m24off-cx-b2b" hidden><span>USt-IdNr.</span><span class="m24off-cx-vatrow"><input type="text" data-cx="ustid"><button type="button" class="m24off-cx-vatbtn" data-cx-vatcheck>Prüfen</button></span></label>
						<label class="m24off-f m24off-cx-b2b" hidden><span>EORI</span><input type="text" data-cx="eori"></label>
					</div>
					<p class="m24off-cxstatus" data-cx-status role="status"></p>
					<button type="button" class="m24off-btn m24off-btn-blue" data-cx-create>Kunde anlegen &amp; übernehmen</button>
				</div>
			</div>
		</div>

		<!-- Mobile Sticky-Bar: Live-Summe + Senden immer sichtbar -->
		<div class="m24off-stickybar">
			<div class="m24off-stickybar-sum"><span>Gesamt (brutto)</span><strong data-sum-total-bar>0,00 &euro;</strong></div>
			<button type="button" class="m24off-btn m24off-btn-blue" data-action="send">Angebot senden</button>
		</div>

		<!-- Teile-Picker (Overlay) -->
		<div class="m24off-picker" data-picker hidden>
			<div class="m24off-picker-head">
				<input type="search" data-picker-q placeholder="Name / Art.-Nr. / BMW-Teilenr.">
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
			echo self::head( 'Angebot' ) . '</head><body class="m24off-cust"><div class="m24off-wrap"><div class="m24off-card"><p>Dieses Angebot wurde nicht gefunden. / This offer could not be found.</p></div></div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		$cust   = json_decode( (string) $o->customer_json, true ) ?: array();
		$items  = json_decode( (string) $o->items_json, true ) ?: array();
		$extras = json_decode( (string) $o->extras_json, true ) ?: array();
		$is_b2c = 'b2c' === ( $cust['kundentyp'] ?? 'b2c' );
		$bank   = self::bank();
		$L      = self::ol( self::offer_lang( $o ) ); // #1: Chrome-Labels DE/EN (Rechtstexte bleiben DE)

		$days = 0; $vu = (string) $o->valid_until;
		if ( $vu ) { $days = (int) floor( ( strtotime( $vu . ' 23:59:59' ) - time() ) / DAY_IN_SECONDS ); if ( $days < 0 ) { $days = 0; } }
		$status = (string) $o->status;

		echo self::head( 'Ihr Angebot ' . $o->offer_no ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		</head><body class="m24off-cust">
		<?php
		$logo     = esc_url( apply_filters( 'm24fz_mail_logo_url', 'https://www.motorsport24.de/wp-content/rennsport-teile-bilder/2023/09/Logo-MOTORSPORT24.de_.gif' ) );
		$has_used = self::has_used( $items );
		$rate_str = rtrim( rtrim( number_format( (float) $o->tax_rate, 2, ',', '.' ), '0' ), ',' );
		// Timeline-Stufenklassen.
		$s2 = 'bezahlt' === $status ? 'is-done' : ( 'abgelaufen' === $status ? 'is-exp' : 'is-active' );
		$s3 = 'bezahlt' === $status ? 'is-done' : '';
		?>
		<div class="m24off-wrap">
			<!-- A: Blau-Verlauf-Header (einheitlich mit Mail + Garage-Share); kein Messing -->
			<header class="m24off-hero">
				<img class="m24off-hero-logo" src="<?php echo $logo; ?>" alt="MOTORSPORT24">
				<div class="m24off-hero-eyebrow"><?php echo esc_html( $L['eyebrow'] ); ?></div>
				<div class="m24off-hero-titlerow">
					<h1 class="m24off-hero-title"><?php echo esc_html( $L['offer'] ); ?> <?php echo esc_html( $o->offer_no ); ?></h1>
					<?php if ( 'offen' === $status || 'angenommen' === $status ) : ?>
						<span class="m24off-chip"><svg class="m24off-chip-ico" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> <?php echo 'en' === self::offer_lang( $o ) ? esc_html( (int) $days . ' day' . ( 1 === $days ? '' : 's' ) . ' left · until ' ) : esc_html( 'noch ' . (int) $days . ' Tag' . ( 1 === $days ? '' : 'e' ) . ' · bis ' ); ?><?php echo esc_html( self::date_de( $vu ) ); ?></span>
					<?php elseif ( 'bezahlt' === $status ) : ?>
						<span class="m24off-chip"><?php echo esc_html( $L['paid'] ); ?> ✓</span>
					<?php else : ?>
						<span class="m24off-chip"><?php echo esc_html( $L['expired'] ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<!-- Segmentierte Timeline -->
			<div class="m24off-tl">
				<span class="m24off-tl-seg is-done">Erhalten</span>
				<span class="m24off-tl-seg <?php echo esc_attr( $s2 ); ?>">Zahlung offen</span>
				<span class="m24off-tl-seg <?php echo esc_attr( $s3 ); ?>">Bezahlt</span>
				<span class="m24off-tl-seg">Versand</span>
			</div>

			<!-- Positionen als Karten -->
			<section class="m24off-card">
				<h2><?php echo esc_html( $L['items'] ); ?></h2>
				<?php foreach ( $items as $it ) :
					$line = (float) $it['unit_price'] * max( 1, (int) $it['qty'] );
					$url  = ! empty( $it['url'] ) ? (string) $it['url'] : '';
					$tag  = '' !== $url ? 'a' : 'div';
					$att  = '' !== $url ? ' href="' . esc_url( $url ) . '" target="_blank" rel="noopener"' : '';
					?>
					<<?php echo $tag; ?> class="m24off-pos<?php echo '' !== $url ? ' is-link' : ''; ?>"<?php echo $att; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
						<div class="m24off-pos-main">
							<span class="m24off-pos-title"><?php echo esc_html( $it['title'] ); ?></span>
							<?php if ( ! empty( $it['variant'] ) ) : ?><span class="m24off-pos-variant">Variante: <?php echo esc_html( $it['variant'] ); ?></span><?php endif; ?>
							<?php if ( ! empty( $it['art_nr'] ) || ! empty( $it['used'] ) ) : ?><span class="m24off-cart"><?php if ( ! empty( $it['art_nr'] ) ) : ?>Art.-Nr.: <?php echo esc_html( $it['art_nr'] ); ?> <?php endif; ?><?php if ( ! empty( $it['used'] ) ) : ?><span class="m24off-usedchip">gebraucht</span><?php endif; ?></span><?php endif; ?>
							<?php if ( ! empty( $it['race'] ) && ! empty( $it['race_note'] ) ) : ?><span class="m24off-pos-race"><span class="m24off-flag" aria-hidden="true"></span><?php echo esc_html( $it['race_note'] ); ?></span><?php endif; ?>
							<?php if ( self::is_tax25a_item( $it ) ) : ?><span class="m24off-pos-25a"><span class="m24off-ico" aria-hidden="true">ⓘ</span> <?php echo esc_html( self::tax25a_pos_line() ); ?></span><?php endif; ?>
							<?php if ( ! empty( $it['custom'] ) ) : ?><span class="m24off-c25a">Sonderanfertigung – kein Widerruf (§ 312g Abs. 2 BGB)</span><?php endif; ?>
						</div>
						<div class="m24off-pos-qty">× <?php echo (int) $it['qty']; ?></div>
						<div class="m24off-pos-line"><?php echo esc_html( self::fmt( $line ) ); ?></div>
					</<?php echo $tag; ?>>
				<?php endforeach; ?>
				<?php foreach ( $extras as $ex ) : if ( empty( $ex['on'] ) ) { continue; } ?>
					<div class="m24off-pos m24off-cextra"><div class="m24off-pos-main"><span class="m24off-pos-title"><?php echo esc_html( $ex['label'] ); ?></span></div><div class="m24off-pos-qty"></div><div class="m24off-pos-line"><?php echo esc_html( self::fmt( (float) $ex['amount'] ) ); ?></div></div>
				<?php endforeach; ?>
				<?php if ( $o->delivery_time ) : ?><p class="m24off-note"><?php echo esc_html( $L['delivery'] ); ?>: <?php echo esc_html( $o->delivery_time ); ?></p><?php endif; ?>
				<?php
				// Summen-Aufteilung: regelbesteuert (X, netto) + USt (Y) vs. §25a-Brutto (Z). Konditional je Mix.
				$bd  = M24_Offers::compute_totals( $items, $extras, (string) $o->tax_mode, (float) $o->tax_rate );
				$X = $bd['net']; $Y = $bd['tax']; $Z = $bd['st25a'];
				$only_25a   = ( $Z > 0.001 && $X <= 0.001 );
				$only_regel = ( $Z <= 0.001 );
				?>
				<?php if ( $only_25a ) : ?>
					<div class="m24off-sumline"><span>Differenzbesteuert (§ 25a)</span><strong><?php echo esc_html( self::fmt( $Z ) ); ?></strong></div>
				<?php elseif ( $only_regel ) : ?>
					<div class="m24off-sumline"><span><?php echo esc_html( $L['subtotal'] ); ?></span><strong><?php echo esc_html( self::fmt( $X ) ); ?></strong></div>
					<?php if ( $Y > 0 ) : ?><div class="m24off-sumline"><span>USt <?php echo esc_html( $rate_str ); ?> %</span><strong><?php echo esc_html( self::fmt( $Y ) ); ?></strong></div><?php endif; ?>
				<?php else : ?>
					<div class="m24off-sumline"><span>Regelbesteuerte Artikel (netto)</span><strong><?php echo esc_html( self::fmt( $X ) ); ?></strong></div>
					<?php if ( $Y > 0 ) : ?><div class="m24off-sumline"><span>USt <?php echo esc_html( $rate_str ); ?> %</span><strong><?php echo esc_html( self::fmt( $Y ) ); ?></strong></div><?php endif; ?>
					<div class="m24off-sumline"><span>Differenzbesteuert (§ 25a)</span><strong><?php echo esc_html( self::fmt( $Z ) ); ?></strong></div>
				<?php endif; ?>
				<div class="m24off-sumline m24off-total"><span><?php echo esc_html( $L['total'] ); ?></span><strong><?php echo esc_html( self::fmt( (float) $o->total_gross ) ); ?></strong></div>
				<?php if ( self::has_tax25a( $items ) ) : ?><p class="m24off-note"><?php echo esc_html( self::tax25a_footnote() ); ?></p><?php endif; ?>
				<?php if ( $o->tax_note && (float) $o->tax_amount <= 0 ) : ?><p class="m24off-note"><?php echo esc_html( $o->tax_note ); ?></p><?php endif; ?>
			</section>

			<!-- E: Bindungssatz -->
			<p class="m24off-binding"><?php echo esc_html( self::bindungssatz() ); ?></p>

			<!-- C: Rechts-Accordions -->
			<?php if ( $is_b2c ) : ?>
			<details class="m24off-acc"><summary>Widerrufsrecht (Verbraucher)</summary><div class="m24off-acc-body"><?php echo self::widerruf_accordion( $items ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></details>
			<?php endif; ?>
			<details class="m24off-acc"><summary>Gewährleistung &amp; Steuer</summary><div class="m24off-acc-body"><?php echo self::gewaehr_accordion( $is_b2c, $has_used ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div></details>

			<?php if ( 'offen' === $status || 'angenommen' === $status ) : ?>
			<!-- B/D: „Angebot annehmen" → Status angenommen (DB) + Bankdaten (erst nach Klick im DOM) -->
			<section class="m24off-card m24off-gate">
				<?php if ( 'offen' === $status ) : ?>
					<label class="m24off-check"><input type="checkbox" data-gate> <span><?php echo esc_html( self::checkbox_text( $is_b2c, $has_used ) ); ?></span></label>
					<button type="button" class="m24off-btn m24off-btn-blue" data-accept disabled><?php echo esc_html( $L['accept'] ); ?></button>
				<?php else : ?>
					<p class="m24off-accepted">Angebot angenommen ✓ — bitte überweise den Betrag mit den folgenden Bankdaten.</p>
				<?php endif; ?>
				<div class="m24off-paybox" data-paybox<?php echo 'angenommen' === $status ? '' : ' hidden'; ?>></div>
			</section>
			<?php endif; ?>

			<footer class="m24off-cfoot"><?php echo esc_html( self::company_line() ); ?> · <a href="https://www.motorsport24.de">www.motorsport24.de</a></footer>
		</div>
		<script>
		(function(){
			// B: „Angebot annehmen" → Status angenommen (best-effort) + Bankdaten in den DOM injizieren.
			var chk=document.querySelector('[data-gate]'), acc=document.querySelector('[data-accept]'), box=document.querySelector('[data-paybox]');
			var BANK=<?php echo wp_json_encode( array(
				'inhaber' => $bank['inhaber'], 'iban' => $bank['iban'], 'bic' => $bank['bic'],
				'zweck'   => (string) $o->offer_no, 'betrag' => self::fmt( (float) $o->total_gross ),
			) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>;
			function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
			function renderBank(){
				box.innerHTML='<h3>Zahlung per Überweisung</h3>'
					+row('Betrag',BANK.betrag,false)
					+row('Empfänger',BANK.inhaber,false)
					+row('IBAN',BANK.iban,true)
					+row('BIC',BANK.bic,false)
					+row('Verwendungszweck',BANK.zweck,true);
				box.hidden=false; box.scrollIntoView({behavior:'smooth',block:'nearest'});
			}
			if(chk&&acc){ chk.addEventListener('change',function(){ acc.disabled=!chk.checked; }); }
			if(acc&&box){ acc.addEventListener('click',function(){
				if(acc.disabled) return;
				acc.disabled=true; acc.textContent='Angebot wird angenommen …';
				var finish=function(){ if(acc.parentNode){acc.style.display='none';} renderBank(); };
				fetch('<?php echo esc_url_raw( rest_url( M24_Offers::NS . '/offers/accept' ) ); ?>',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':'<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'},body:JSON.stringify({token:'<?php echo esc_js( $token ); ?>'})})
					.then(function(r){return r.json();}).then(finish).catch(finish); // Bankdaten auch bei Fehler zeigen (Annahme ist Signal für Daniel, kein Zahlungs-Gate)
			}); }
			if(box && !box.hidden){ renderBank(); } // bereits angenommenes Angebot: Bankdaten direkt anzeigen
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
	/** Dezente Positions-Zeile bei §25a (C3: gilt in JEDEM Steuer-Modus — §25a-Positionen sind immer
	 *  differenzbesteuert und werden nie Reverse-Charge/OSS/Export-besteuert; compute_totals nimmt sie aus). */
	private static function tax25a_pos_line(): string {
		return 'Differenzbesteuerung gem. § 25a UStG, MwSt. nicht ausweisbar.';
	}
	/** Einmalige Fußnote unter dem Summenblock, wenn ≥ 1 §25a-Position. */
	private static function tax25a_footnote(): string {
		return 'Differenzbesteuerung gem. § 25a UStG – Umsatzsteuer wird auf diese Positionen nicht gesondert ausgewiesen (unabhängig vom Steuermodus der übrigen Positionen).';
	}
	private static function has_tax25a( array $items ): bool {
		foreach ( $items as $it ) { if ( ! empty( $it['tax25a'] ) || ! empty( $it['st25a'] ) ) { return true; } }
		return false;
	}
	private static function is_tax25a_item( array $it ): bool {
		return ! empty( $it['tax25a'] ) || ! empty( $it['st25a'] ); // st25a = Abwärtskompat
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
		$L  = self::ol( self::offer_lang( $o ) ); // #1: Angebotssprache-Labels (Rechtstexte bleiben DE)
		$sj   = json_decode( (string) $o->src_json, true ) ?: array();
		$sal  = trim( (string) ( $sj['salutation'] ?? '' ) );
		$note = (string) ( $sj['note'] ?? '' );
		$mlang  = self::offer_lang( $o );
		$mdays  = $o->valid_until ? max( 0, (int) ceil( ( strtotime( (string) $o->valid_until . ' 23:59:59' ) - time() ) / DAY_IN_SECONDS ) ) : 0;
		$mplural = ( 1 === $mdays ) ? '' : ( 'de' === $mlang ? 'e' : 's' );

		$rows = '';
		foreach ( $items as $it ) {
			$line  = (float) $it['unit_price'] * max( 1, (int) $it['qty'] );
			$url   = ! empty( $it['url'] ) ? (string) $it['url'] : '';
			$title = '' !== $url
				? '<a href="' . esc_url( $url ) . '" target="_blank" style="color:#14161a;font-weight:600;text-decoration:none;">' . esc_html( $it['title'] ) . '</a>'
				: '<span style="font-weight:600;">' . esc_html( $it['title'] ) . '</span>';
			$rows .= '<tr><td style="padding:6px 12px 6px 0;">' . $title // phpcs:ignore WordPress.Security.EscapeOutput — Titel escaped
				. ( ! empty( $it['variant'] ) ? '<br><span style="color:#1f74c4;font-size:12px;font-weight:600;">' . esc_html( $L['variant'] ) . ': ' . esc_html( $it['variant'] ) . '</span>' : '' )
				. ( ( ! empty( $it['art_nr'] ) || ! empty( $it['used'] ) ) ? '<br><span style="color:#8a929c;font-size:12px;">'
					. ( ! empty( $it['art_nr'] ) ? esc_html( $L['artnr'] ) . ': ' . esc_html( $it['art_nr'] ) : '' )
					. ( ! empty( $it['used'] ) ? ( ! empty( $it['art_nr'] ) ? ' · ' : '' ) . esc_html( $L['used'] ) : '' ) . '</span>' : '' )
				. ( ! empty( $it['race'] ) && ! empty( $it['race_note'] ) ? '<br><span style="color:#93762f;font-size:11.5px;">🇩🇪 ' . esc_html( $it['race_note'] ) . '</span>' : '' )
				. ( self::is_tax25a_item( $it ) ? '<br><span style="color:#8a929c;font-size:11.5px;">ⓘ ' . esc_html( self::tax25a_pos_line() ) . '</span>' : '' )
				. ( ! empty( $it['custom'] ) ? '<br><span style="color:#9a6b25;font-size:11px;">Sonderanfertigung – kein Widerruf (§ 312g Abs. 2 BGB)</span>' : '' )
				. '</td><td style="text-align:center;padding:6px 14px;white-space:nowrap;color:#5a6474;">× ' . (int) $it['qty'] . '</td><td style="text-align:right;white-space:nowrap;">' . esc_html( self::fmt( $line ) ) . '</td></tr>';
		}
		foreach ( $extras as $ex ) {
			if ( empty( $ex['on'] ) ) { continue; }
			$rows .= '<tr><td style="padding:6px 0;color:#5a6474;">' . esc_html( $ex['label'] ) . '</td><td></td><td style="text-align:right;">' . esc_html( self::fmt( (float) $ex['amount'] ) ) . '</td></tr>';
		}

		$inner  = $vu ? '<p style="margin:0 0 14px;color:#9a6b25;font-weight:700;font-size:13.5px;">' . esc_html( sprintf( $L['valid_line'], $vu, $mdays, $mplural ) ) . '</p>' : '';
		$greet  = '' !== $sal ? $sal : ( $L['hello'] . ( ! empty( $cust['name'] ) ? ' ' . $cust['name'] : '' ) . ',' );
		$inner .= '<p style="margin:0 0 14px;">' . esc_html( $greet ) . '</p>';
		$inner .= '<p style="margin:0 0 14px;">' . esc_html( $L['intro'] ) . '</p>';
		// Summen-Aufteilung identisch zur Ansicht: regelbesteuert (X netto) + USt (Y) vs. §25a-Brutto (Z).
		$rate_str = rtrim( rtrim( number_format( (float) $o->tax_rate, 2, ',', '.' ), '0' ), ',' );
		$bd = M24_Offers::compute_totals( $items, $extras, (string) $o->tax_mode, (float) $o->tax_rate );
		$X = $bd['net']; $Y = $bd['tax']; $Z = $bd['st25a'];
		$top = ' style="padding-top:10px;border-top:1px solid #e6e9ee;"';
		$srow = static function ( $label, $amt, $first ) use ( $top ) {
			$t = $first ? $top : '';
			return '<tr><td colspan="2"' . $t . '>' . esc_html( $label ) . '</td><td style="text-align:right;' . ( $first ? 'padding-top:10px;border-top:1px solid #e6e9ee;' : '' ) . '">' . esc_html( self::fmt( (float) $amt ) ) . '</td></tr>';
		};
		$sum = '';
		if ( $Z > 0.001 && $X <= 0.001 ) {
			$sum .= $srow( $L['margin'], $Z, true );
		} elseif ( $Z <= 0.001 ) {
			$sum .= $srow( $L['subtotal'], $X, true );
			if ( $Y > 0 ) { $sum .= $srow( $L['vat'] . ' ' . $rate_str . ' %', $Y, false ); }
		} else {
			$sum .= $srow( $L['std_net'], $X, true );
			if ( $Y > 0 ) { $sum .= $srow( $L['vat'] . ' ' . $rate_str . ' %', $Y, false ); }
			$sum .= $srow( $L['margin'], $Z, false );
		}
		$inner .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . $rows . $sum // phpcs:ignore WordPress.Security.EscapeOutput — Teile bereits escaped
			. '<tr><td colspan="2" style="font-weight:700;padding-top:6px;">' . esc_html( $L['total'] ) . '</td><td style="text-align:right;font-weight:700;padding-top:6px;">' . esc_html( self::fmt( (float) $o->total_gross ) ) . '</td></tr></table>';
		if ( self::has_tax25a( $items ) ) { $inner .= '<p style="margin:6px 0 0;color:#8a929c;font-size:11.5px;">' . esc_html( self::tax25a_footnote() ) . '</p>'; }
		if ( $o->delivery_time ) { $inner .= '<p style="margin:14px 0 0;color:#5a6474;">' . esc_html( $L['delivery'] ) . ': ' . esc_html( $o->delivery_time ) . '</p>'; }
		// Nur bei Netto-Modi die erklärende Steuer-Note zeigen (keine „zzgl. … MwSt."-Zeile).
		if ( $o->tax_note && (float) $o->tax_amount <= 0 ) { $inner .= '<p style="margin:6px 0 0;color:#8a929c;font-size:12px;">' . esc_html( $o->tax_note ) . '</p>'; }
		if ( '' !== trim( $note ) ) { $inner .= '<div style="margin:16px 0;padding:14px 16px;background:#f7f8fa;border-radius:8px;font-size:14px;color:#3a414c;line-height:1.6;white-space:pre-wrap;">' . esc_html( $note ) . '</div>'; }
		$inner .= '<p style="margin:22px 0 4px;text-align:center;"><a href="' . esc_url( M24_Offers::view_url( (string) $o->token ) ) . '" style="display:inline-block;background:#1f74c4;background:linear-gradient(135deg,#1f74c4,#0e447e);color:#fff;text-decoration:none;font-weight:700;padding:13px 28px;border-radius:8px;">' . $L['view_pay'] . '</a></p>';
		$inner .= '<p style="margin:0 0 8px;text-align:center;font-size:12px;color:#8a929c;">' . esc_html( $L['cta_sub'] ) . '</p>';
		// E: Bindungssatz (ohne Paragraphen), präzise Paragraphen bleiben in der Ansicht/Belehrung.
		$inner .= '<p style="margin:16px 0 4px;font-size:12.5px;color:#5a6474;line-height:1.6;">' . esc_html( self::bindungssatz() ) . '</p>';
		// Pflichtangaben.
		$inner .= '<p style="margin:8px 0 0;font-size:12px;color:#8a929c;line-height:1.6;"><strong>' . esc_html( $L['provider'] ) . ':</strong> ' . esc_html( self::company_line() )
			. '<br><strong>' . esc_html( $L['total_price'] ) . ':</strong> ' . esc_html( self::fmt( (float) $o->total_gross ) ) . ' ' . esc_html( $L['incl_taxes'] )
			. ( $o->delivery_time ? '<br><strong>' . esc_html( $L['delivery'] ) . ':</strong> ' . esc_html( $o->delivery_time ) : '' ) . '</p>';
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

		$lang = self::offer_lang( $o );
		$html = function_exists( 'm24_mail_shell' ) ? m24_mail_shell( $L['your_offer'] . ' ' . $o->offer_no, $inner, array( 'lang' => $lang ) ) : $inner;
		$subj = $L['your_offer'] . ' ' . $o->offer_no . ( 'en' === $lang ? ' from MOTORSPORT24' : ' von MOTORSPORT24' );
		wp_mail( $email, $subj, $html, array( 'Content-Type: text/html; charset=UTF-8', 'From: MOTORSPORT24 <service@motorsport24.de>' ) );
	}
}
