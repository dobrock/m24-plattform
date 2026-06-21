<?php
/**
 * M24 Fahrzeug — Komfort-Eingabemaske (Elferspot-Stil), full-width Admin-Screen
 * Modul: includes/fahrzeug/class-m24fz-editor-screen.php
 *
 * Große, sektionierte Maske zum Anlegen/Bearbeiten eines m24_fahrzeug — Default statt
 * Classic/Gutenberg (per ?classic=1 / Segmented-Topbar umschaltbar). Schreibt DIESELBEN
 * _m24fz_-Felder + post_title + Beitragsbild: bestehende Felder via M24FZ_Meta::save()
 * (gleiche Feldnamen + m24fz_meta-Nonce), neue optionale Felder im eigenen Save-Handler.
 *
 * Sektionen: 1 Fahrzeug · 2 Historie&Zustand · 3 Technik · 4 Ausstattung · 5 Inserat&Medien.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24FZ_Editor_Screen {

	const PAGE   = 'm24fz-editor';
	const ACTION = 'm24fz_editor_save';
	const NONCE  = 'm24fz_editor';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// Komfort-Maske als Default: Standard-Editor-Aufrufe umleiten (außer ?classic=1).
		add_action( 'load-post-new.php', array( __CLASS__, 'redirect_new' ) );
		add_action( 'load-post.php', array( __CLASS__, 'redirect_edit' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . M24FZ_CPT::PT,
			'Fahrzeug — Komfort-Maske', 'Komfort-Maske', 'edit_posts', self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/* ── Default-Redirect (Komfort statt Classic) ────────────────────────────── */

	public static function redirect_new() {
		if ( '1' === ( $_GET['classic'] ?? '' ) ) { return; }
		$s = get_current_screen();
		if ( $s && M24FZ_CPT::PT === $s->post_type ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . M24FZ_CPT::PT . '&page=' . self::PAGE ) );
			exit;
		}
	}
	public static function redirect_edit() {
		if ( '1' === ( $_GET['classic'] ?? '' ) || 'edit' !== ( $_GET['action'] ?? '' ) ) { return; }
		$pid = (int) ( $_GET['post'] ?? 0 );
		if ( $pid && M24FZ_CPT::PT === get_post_type( $pid ) ) {
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . M24FZ_CPT::PT . '&page=' . self::PAGE . '&post=' . $pid ) );
			exit;
		}
	}

	/* ── Assets ──────────────────────────────────────────────────────────────── */

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_style( 'm24fz-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;500;600;700&display=swap', array(), null );
	}

	/* ── Render-Helfer ───────────────────────────────────────────────────────── */

	private static function g( $id, $k, $d = '' ) { $v = $id ? get_post_meta( $id, $k, true ) : ''; return '' === $v || null === $v ? $d : $v; }

	private static function field( $id, $key, $label, $opts = array() ) {
		$type = $opts['type'] ?? 'text';
		$req  = ! empty( $opts['req'] ) ? ' <span class="req">*</span>' : '';
		$ph   = $opts['ph'] ?? '';
		$help = $opts['help'] ?? '';
		$cls  = ! empty( $opts['cls'] ) ? ' ' . $opts['cls'] : '';
		echo '<div class="fz-f' . esc_attr( $cls ) . '">';
		printf( '<label for="%1$s">%2$s%3$s</label><input type="%4$s" id="%1$s" name="%1$s" value="%5$s" placeholder="%6$s">',
			esc_attr( $key ), esc_html( $label ), $req, esc_attr( $type ), esc_attr( self::g( $id, $key ) ), esc_attr( $ph ) ); // phpcs:ignore
		if ( $help ) { printf( '<span class="fz-help">%s</span>', esc_html( $help ) ); }
		echo '</div>';
	}

	private static function country_field( $id, $key, $label ) {
		echo '<div class="fz-f"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '"><option value="">—</option>';
		foreach ( M24FZ_Telemetry::countries() as $cc => $cn ) {
			printf( '<option value="%s"%s>%s %s</option>', esc_attr( $cc ), selected( self::g( $id, $key ), $cc, false ), esc_html( M24FZ_Telemetry::flag( $cc ) ), esc_html( $cn ) );
		}
		echo '</select></div>';
	}

	/** Toggle-Chip-Gruppe (Mehrfachauswahl) für Array-Metas. */
	private static function checks( $id, $key, $label, $options ) {
		$cur = (array) get_post_meta( $id, $key, true );
		echo '<div class="fz-f"><label>' . esc_html( $label ) . '</label><div class="fz-checks">';
		foreach ( $options as $slug => $l ) {
			$on = in_array( $slug, $cur, true );
			printf( '<label class="fz-chip%s"><input type="checkbox" name="%s[]" value="%s"%s><span class="dot"></span>%s</label>',
				$on ? ' on' : '', esc_attr( $key ), esc_attr( $slug ), $on ? ' checked' : '', esc_html( $l ) );
		}
		echo '</div></div>';
	}

	/**
	 * Auswahlfeld. $options als Liste (value==label) ODER assoc (value=>label).
	 * Default ($opts['default']) greift NUR bei Neuanlage (id=0). Bestehende, nicht in der
	 * Enum enthaltene Werte bleiben erhalten und werden als „… (individuell)" angezeigt (F-Migration).
	 */
	private static function select( $id, $key, $label, $options, $opts = array() ) {
		$cur = (string) self::g( $id, $key );
		if ( '' === $cur && 0 === (int) $id && ! empty( $opts['default'] ) ) { $cur = (string) $opts['default']; }
		$req = ! empty( $opts['req'] ) ? ' <span class="req">*</span>' : '';
		$ph  = $opts['ph'] ?? 'Bitte wählen';
		// Liste → assoc.
		$assoc = array(); foreach ( $options as $k => $v ) { if ( is_int( $k ) ) { $assoc[ $v ] = $v; } else { $assoc[ $k ] = $v; } }
		// Bestandswert case-insensitiv + Alias auf den kanonischen Options-Wert abbilden (FIX 2).
		$canon = '' !== $cur ? M24FZ_Telemetry::match_enum( $cur, array_keys( $assoc ), $opts['aliases'] ?? M24FZ_Telemetry::enum_aliases( $key ) ) : '';
		$sel   = '' !== $canon ? $canon : $cur;
		$cls   = ! empty( $opts['cls'] ) ? ' ' . $opts['cls'] : '';
		echo '<div class="fz-f' . esc_attr( $cls ) . '"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . $req . '</label>'; // phpcs:ignore
		echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
		echo '<option value="">' . esc_html( $ph ) . '</option>';
		foreach ( $assoc as $v => $l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $sel, $v, false ), esc_html( $l ) );
		}
		if ( '' !== $cur && '' === $canon ) {
			printf( '<option value="%s" selected>%s</option>', esc_attr( $cur ), esc_html( $cur . ' (individuell)' ) );
		}
		echo '</select></div>';
	}

	/** Toggle (Bool). */
	private static function toggle( $id, $key, $label, $danger = false, $cls = '' ) {
		printf( '<label class="fz-toggle%s%s"><input type="checkbox" name="%s"%s><span></span> %s</label>',
			$danger ? ' danger' : '', '' !== $cls ? ' ' . esc_attr( $cls ) : '', esc_attr( $key ), checked( 1, (int) self::g( $id, $key ), false ), esc_html( $label ) );
	}

	/* ── Render ──────────────────────────────────────────────────────────────── */

	public static function render() {
		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( $id && M24FZ_CPT::PT !== get_post_type( $id ) ) { $id = 0; }
		$post    = $id ? get_post( $id ) : null;
		$title   = $post ? $post->post_title : '';
		$typ     = self::g( $id, '_m24fz_template_typ', 'strasse' );
		$kat     = self::g( $id, '_m24fz_kat', 'race-cars' );
		$status  = $id ? M24FZ_CPT::status( $id ) : 'gelistet';
		$paf     = (int) self::g( $id, '_m24fz_preis_auf_anfrage' );
		$einheit = self::g( $id, '_m24fz_laufleistung_einheit', 'km' );
		$waehr   = self::g( $id, '_m24fz_waehrung', 'EUR' );
		$thumb   = $id ? (int) get_post_thumbnail_id( $id ) : 0;
		$thumbU  = $thumb ? wp_get_attachment_image_url( $thumb, 'medium' ) : '';
		$pdraft  = $post && 'draft' === $post->post_status;
		$pdate   = $post ? mysql2date( 'Y-m-d\TH:i', $post->post_date ) : current_time( 'Y-m-d\TH:i' );
		$classicU = $id ? admin_url( 'post.php?post=' . $id . '&action=edit&classic=1' ) : admin_url( 'post-new.php?post_type=' . M24FZ_CPT::PT . '&classic=1' );
		$yearNow = (int) gmdate( 'Y' );
		?>
		<style><?php echo self::css(); // phpcs:ignore ?></style>
		<div class="fz-screen">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fz-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="post_id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
				<?php wp_nonce_field( M24FZ_Meta::NONCE, M24FZ_Meta::NONCE ); // triggert M24FZ_Meta::save() ?>

				<!-- Sticky Topbar -->
				<header class="fz-topbar">
					<div class="fz-tb-left">
						<strong><?php echo $id ? esc_html( '' !== $title ? $title : 'Fahrzeug' ) : 'Neues Fahrzeug'; ?></strong>
						<span class="fz-tb-status <?php echo $pdraft ? 'draft' : 'pub'; ?>"><?php echo $pdraft ? 'Entwurf' : ( $id ? 'Veröffentlicht' : 'Neu' ); ?></span>
					</div>
					<div class="fz-tb-right">
						<span class="fz-seg"><span class="on">Komfort-Maske</span><a href="<?php echo esc_url( $classicU ); ?>">Klassisch</a></span>
						<?php if ( $id ) : ?><a class="fz-out ghost" href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank">Vorschau ↗</a><?php endif; ?>
						<button type="submit" class="fz-save">Speichern &amp; veröffentlichen</button>
					</div>
				</header>

				<div class="fz-body">
					<?php if ( $id && isset( $_GET['updated'] ) ) : ?>
						<div class="fz-note ok">
							<?php if ( $pdraft ) : ?>
								<span>✓ Als Entwurf gespeichert.</span>
								<a href="<?php echo esc_url( get_preview_post_link( $id ) ); ?>" target="_blank" rel="noopener">Vorschau öffnen ↗</a>
							<?php else : ?>
								<span>✓ Veröffentlicht.</span>
								<a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener">Inserat ansehen ↗</a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<div class="fz-titlebar">
						<label for="fz_title">Fahrzeug-Titel <span class="req">*</span></label>
						<input type="text" id="fz_title" name="post_title" value="<?php echo esc_attr( $title ); ?>" placeholder="z. B. BMW M3 E30 EVO II — Diamantschwarz" required>
					</div>

					<!-- 1. FAHRZEUG -->
					<section class="fz-sec">
						<h2><span class="n">1</span> Fahrzeug</h2>
						<p class="fz-intro">Grunddaten zur Identifikation des Fahrzeugs.</p>
						<div class="fz-row">
							<div class="fz-f">
								<label>Fahrzeugtyp</label>
								<div class="fz-seg fz-seg-typ">
									<label class="<?php echo 'renn' === $typ ? '' : 'on'; ?>"><input type="radio" name="_m24fz_template_typ" value="strasse" <?php checked( $typ, 'strasse' ); ?>> Straßenfahrzeug</label>
									<label class="<?php echo 'renn' === $typ ? 'on' : ''; ?>"><input type="radio" name="_m24fz_template_typ" value="renn" <?php checked( $typ, 'renn' ); ?>> Rennwagen</label>
								</div>
							</div>
							<div class="fz-f">
								<label for="_m24fz_kat">Aktiv-Kategorie</label>
								<select id="_m24fz_kat" name="_m24fz_kat">
									<option value="race-cars" <?php selected( $kat, 'race-cars' ); ?>>Race Cars</option>
									<option value="classic-cars" <?php selected( $kat, 'classic-cars' ); ?>>Classic Cars</option>
								</select>
							</div>
						</div>
						<div class="fz-row">
							<?php self::field( $id, '_m24fz_marke', 'Marke', array( 'req' => true, 'ph' => 'BMW', 'help' => 'Steuert „Ähnliche Fahrzeuge".' ) ); ?>
							<div class="fz-f">
								<label for="_m24fz_baujahr">Baujahr</label>
								<select id="_m24fz_baujahr" name="_m24fz_baujahr"><option value="">Bitte wählen</option>
									<?php
									$by = (int) self::g( $id, '_m24fz_baujahr' );
									if ( $by > 0 && $by > $yearNow ) { printf( '<option value="%1$d" selected>%1$d</option>', $by ); }
									for ( $y = $yearNow; $y >= 1970; $y-- ) { printf( '<option value="%1$d"%2$s>%1$d</option>', $y, selected( $by, $y, false ) ); }
									if ( $by > 0 && $by < 1970 ) { printf( '<option value="%1$d" selected>%1$d</option>', $by ); }
									?>
								</select>
							</div>
							<?php self::field( $id, '_m24fz_erstzulassung', 'Erstzulassung', array( 'ph' => 'MM/JJJJ', 'cls' => 'fz-strasse-only' ) ); ?>
						</div>
						<div class="fz-row">
							<?php
							self::field( $id, '_m24fz_baureihe', 'Baureihe', array( 'req' => true, 'ph' => 'z. B. 3er E30' ) );
							self::field( $id, '_m24fz_modell', 'Modell', array( 'req' => true, 'ph' => 'z. B. M3 EVO II' ) );
							self::field( $id, '_m24fz_fin', 'FIN' );
							?>
						</div>
						<div class="fz-row">
							<?php self::select( $id, '_m24fz_karosserie', 'Karosserie', M24FZ_Telemetry::karosserie_options() ); ?>
						</div>
					</section>

					<!-- 2. HISTORIE & ZUSTAND -->
					<section class="fz-sec">
						<h2><span class="n">2</span> Historie &amp; Zustand</h2>
						<div class="fz-row">
							<div class="fz-f fz-f-unit">
								<label for="_m24fz_laufleistung">Laufleistung <span class="req">*</span></label>
								<div class="fz-inline">
									<input type="text" id="_m24fz_laufleistung" name="_m24fz_laufleistung" value="<?php echo esc_attr( self::g( $id, '_m24fz_laufleistung' ) ); ?>" placeholder="z. B. 45000" inputmode="numeric" maxlength="7" autocomplete="off">
									<select name="_m24fz_laufleistung_einheit" class="fz-unit"><option value="km"<?php selected( $einheit, 'km' ); ?>>km</option><option value="mi"<?php selected( $einheit, 'mi' ); ?>>mi</option></select>
								</div>
								<span class="fz-help" id="fz-km-hint">Ganze Zahl, max. 9.999.999.</span>
							</div>
							<?php self::country_field( $id, '_m24fz_land_erstauslieferung', 'Land der Erstauslieferung' ); ?>
							<?php self::field( $id, '_m24fz_anzahl_halter', 'Anzahl Fahrzeughalter', array( 'ph' => 'z. B. 2' ) ); ?>
						</div>
						<div class="fz-row">
							<?php self::country_field( $id, '_m24fz_standort', 'Fahrzeugstandort (Land)' ); ?>
							<?php self::select( $id, '_m24fz_neu_gebraucht', 'Neu / Gebraucht', M24FZ_Telemetry::neu_gebraucht_options(), array( 'req' => true, 'default' => 'Gebraucht' ) ); ?>
						</div>
						<?php self::checks( $id, '_m24fz_zustand', 'Zustand', M24FZ_Telemetry::zustand_options() ); ?>
						<div class="fz-row fz-toggles">
							<?php self::toggle( $id, '_m24fz_fahrbereit', 'Fahrbereit' ); ?>
							<?php self::toggle( $id, '_m24fz_zugelassen', 'Zugelassen', false, 'fz-strasse-only' ); ?>
							<?php self::toggle( $id, '_m24fz_matching_numbers', 'Matching Numbers' ); ?>
						</div>
					</section>

					<!-- 3. TECHNISCHE DATEN -->
					<section class="fz-sec">
						<h2><span class="n">3</span> Technische Daten</h2>
						<div class="fz-row">
							<div class="fz-f">
								<label for="_m24fz_leistung_ps">Leistung (PS)</label>
								<input type="text" id="_m24fz_leistung_ps" name="_m24fz_leistung_ps" value="<?php echo esc_attr( self::g( $id, '_m24fz_leistung_ps' ) ); ?>" placeholder="z. B. 238">
								<span class="fz-help" id="fz-kw">In PS — kW automatisch.</span>
							</div>
							<?php self::field( $id, '_m24fz_hubraum', 'Hubraum', array( 'ph' => 'z. B. 2,3 l' ) ); ?>
							<div class="fz-f">
								<label for="_m24fz_getriebe">Getriebe</label>
								<select id="_m24fz_getriebe" name="_m24fz_getriebe">
									<?php foreach ( M24FZ_Telemetry::getriebe_options() as $v => $l ) { printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( self::g( $id, '_m24fz_getriebe' ), $v, false ), esc_html( $l ) ); } ?>
								</select>
							</div>
						</div>
						<div class="fz-row">
							<?php
							self::select( $id, '_m24fz_antrieb', 'Antrieb', M24FZ_Telemetry::antrieb_options(), array( 'default' => 'Heck' ) );
							self::select( $id, '_m24fz_kraftstoff', 'Kraftstoff', M24FZ_Telemetry::kraftstoff_options(), array( 'default' => 'Benzin', 'cls' => 'fz-strasse-only' ) );
							self::select( $id, '_m24fz_lenkung', 'Lenkung', M24FZ_Telemetry::lenkung_options(), array( 'default' => 'Links', 'cls' => 'fz-strasse-only' ) );
							?>
						</div>
						<div class="fz-row">
							<?php
							self::field( $id, '_m24fz_aussenfarbe', 'Außenfarbe', array( 'req' => true ) );
							self::field( $id, '_m24fz_farbbez_hersteller', 'Hersteller-Farbbez. (außen)' );
							?>
						</div>
						<div class="fz-row">
							<?php
							self::select( $id, '_m24fz_innenmaterial', 'Innenmaterial', M24FZ_Telemetry::innenmaterial_options() );
							self::select( $id, '_m24fz_innenfarbe', 'Innenfarbe', M24FZ_Telemetry::innenfarbe_options(), array( 'req' => true ) );
							?>
						</div>

						<div class="fz-renn" data-renn>
							<h3>Renn-spezifisch</h3>
							<div class="fz-row fz-toggles">
								<?php self::toggle( $id, '_m24fz_wagenpass', 'Wagenpass' ); ?>
								<?php self::toggle( $id, '_m24fz_rennhistorie', 'Rennhistorie' ); ?>
							</div>
							<div class="fz-row"><?php for ( $i = 1; $i <= 3; $i++ ) {
								self::field( $id, "_m24fz_race_opt{$i}_label", "Option $i — Label" );
								self::field( $id, "_m24fz_race_opt{$i}_value", "Option $i — Wert" );
							} ?></div>
						</div>
					</section>

					<!-- 4. AUSSTATTUNG -->
					<section class="fz-sec">
						<h2><span class="n">4</span> Ausstattung</h2>
						<?php self::checks( $id, '_m24fz_ausstattung', 'Ausstattungsmerkmale', M24FZ_Telemetry::ausstattung_options() ); ?>
					</section>

					<!-- 5. INSERAT & MEDIEN -->
					<section class="fz-sec">
						<h2><span class="n">5</span> Inserat &amp; Medien</h2>

						<div class="fz-f">
							<label>Highlights <span class="req">*</span> <span class="fz-help">Was macht das Fahrzeug besonders? (3–5)</span></label>
							<div id="fz-keyfacts"><?php foreach ( array_pad( (array) get_post_meta( $id, '_m24fz_keyfacts', true ), 3, '' ) as $kf ) : ?>
								<p><input type="text" name="_m24fz_keyfacts[]" value="<?php echo esc_attr( $kf ); ?>" placeholder="z. B. Matching Numbers"></p>
							<?php endforeach; ?></div>
							<button type="button" class="fz-add" id="fz-kf-add">+ Highlight hinzufügen</button>
						</div>

						<div class="fz-f">
							<label>Zusammenfassung <span class="req">*</span> <span class="fz-help">Kurzer Intro-Text (auch Meta-Description).</span></label>
							<?php wp_editor( self::g( $id, '_m24fz_zusammenfassung' ), 'm24fzzus', array( 'textarea_name' => '_m24fz_zusammenfassung', 'textarea_rows' => 3, 'media_buttons' => false, 'teeny' => true ) ); ?>
						</div>

						<div class="fz-f">
							<label>Detailbeschreibung</label>
							<?php wp_editor( self::g( $id, '_m24fz_beschreibung' ), 'm24fzbeschr', array( 'textarea_name' => '_m24fz_beschreibung', 'textarea_rows' => 8, 'media_buttons' => false, 'teeny' => true ) ); ?>
						</div>

						<div class="fz-row">
							<?php self::field( $id, '_m24fz_preis', 'Preis', array( 'ph' => 'z. B. 189000' ) ); ?>
							<div class="fz-f">
								<label for="_m24fz_waehrung">Währung</label>
								<select id="_m24fz_waehrung" name="_m24fz_waehrung"><option value="EUR"<?php selected( $waehr, 'EUR' ); ?>>EUR (€)</option><option value="CHF"<?php selected( $waehr, 'CHF' ); ?>>CHF</option></select>
							</div>
							<?php self::field( $id, '_m24fz_preis_reduziert', 'Reduzierter Preis (optional)', array( 'ph' => 'durchgestrichener Originalpreis' ) ); ?>
						</div>
						<div class="fz-row fz-toggles">
							<?php self::toggle( $id, '_m24fz_mwst_ausweisbar', 'MwSt. ausweisbar (inkl. 19 %)' ); ?>
							<label class="fz-toggle"><input type="checkbox" name="_m24fz_preis_auf_anfrage" <?php checked( 1, $paf ); ?>><span></span> Preis nur auf Anfrage</label>
						</div>

						<div class="fz-row">
							<div class="fz-f">
								<label for="fz_post_date">Veröffentlichungsdatum <span class="fz-help">Backdating für migrierte Inserate möglich.</span></label>
								<input type="datetime-local" id="fz_post_date" name="fz_post_date" value="<?php echo esc_attr( $pdate ); ?>">
							</div>
						</div>

						<div class="fz-f">
							<label>YouTube-Videos</label>
							<div id="fz-videos"><?php foreach ( array_pad( (array) get_post_meta( $id, '_m24fz_videos', true ), 1, '' ) as $v ) : ?>
								<p><input type="url" name="_m24fz_videos[]" value="<?php echo esc_attr( $v ); ?>" placeholder="https://youtu.be/…"></p>
							<?php endforeach; ?></div>
							<button type="button" class="fz-add" id="fz-vid-add">+ Video hinzufügen</button>
						</div>

						<div class="fz-f">
							<label>Beitragsbild (Titelbild) <span class="fz-help">Erscheint groß oben im Inserat (Hero).</span></label>
							<div class="fz-thumb">
								<div class="fz-thumb-prev<?php echo $thumbU ? '' : ' empty'; ?>"><span class="fz-titel-tag">TITEL</span><?php if ( $thumbU ) : ?><img src="<?php echo esc_url( $thumbU ); ?>" alt=""><?php else : ?><span class="ph">Kein Titelbild</span><?php endif; ?></div>
								<input type="hidden" name="_thumbnail_id" value="<?php echo (int) $thumb; ?>">
								<div class="fz-thumb-btns">
									<button type="button" class="fz-out" id="fz-thumb-pick">Titelbild wählen</button>
									<button type="button" class="fz-out ghost" id="fz-thumb-clear">Entfernen</button>
								</div>
							</div>
						</div>

						<div class="fz-f">
							<label>Bildergalerie (je Kategorie · ziehen zum Sortieren)</label>
							<span class="fz-help">Nummer ①–③ (Außen) = Teaser-3er-Block oben im Inserat. Die Mosaik-Kachelgrößen bestimmt die Galerie automatisch.</span>
							<?php foreach ( array( '_m24fz_gal_aussen' => 'Außen', '_m24fz_gal_innen' => 'Innen', '_m24fz_gal_motor' => 'Motor', '_m24fz_gal_unterboden' => 'Unterboden' ) as $key => $label ) :
								$ids = (array) get_post_meta( $id, $key, true ); ?>
								<div class="fz-galbox" data-galkey="<?php echo esc_attr( $key ); ?>">
									<strong><?php echo esc_html( $label ); ?></strong>
									<span class="fz-galhint">Ziehen zum Sortieren — Bild 1 steht im Inserat vorn. (Titelbild = Beitragsbild oben.)</span>
									<div class="fz-gal"><?php $gi = 0; foreach ( $ids as $aid ) {
										$u = wp_get_attachment_image_url( $aid, 'medium' ); if ( ! $u ) { continue; }
										$gi++;
										$teaser = ( '_m24fz_gal_aussen' === $key && $gi <= 3 );           // erste 3 Außen → Frontend-3er-Block
										printf( '<span data-id="%d" class="%s"><img src="%s" alt="" loading="lazy"><i class="rm">×</i>%s</span>',
											(int) $aid, $teaser ? 'is-teaser' : '', esc_url( $u ), $teaser ? '<i class="tnum">' . (int) $gi . '</i>' : '' );
									} ?></div>
									<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $ids ) ) ); ?>">
									<button type="button" class="fz-out fz-gal-add">Bilder hinzufügen</button>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="fz-f"><label>Statistik (manuell editierbar)</label></div>
						<div class="fz-row">
							<?php
							self::field( $id, '_m24fz_views', 'Aufrufe' );
							self::field( $id, '_m24fz_merkliste_count', 'Merkliste' );
							self::field( $id, '_m24fz_anfragen_count', 'Anfragen' );
							?>
						</div>

						<div class="fz-f"><label>Sichtbarkeit</label>
							<div class="fz-row fz-toggles"><?php self::toggle( $id, '_m24_featured', 'Auf Startseite (Slider)' ); ?></div>
						</div>

						<div class="fz-f"><label>Status</label></div>
						<div class="fz-row fz-toggles fz-status">
							<label class="fz-toggle"><input type="checkbox" name="m24fz_verkauft" <?php checked( 'verkauft', $status ); ?>><span></span> Verkauft</label>
							<label class="fz-toggle"><input type="checkbox" name="m24fz_reserviert" <?php checked( 'reserviert', $status ); ?>><span></span> Reserviert</label>
							<label class="fz-toggle danger"><input type="checkbox" name="m24fz_deaktiviert" <?php checked( 'deaktiviert', $status ); ?>><span></span> Deaktivieren (Frontend weg)</label>
						</div>

						<div class="fz-foot">
							<button type="submit" class="fz-save">Speichern &amp; veröffentlichen</button>
							<button type="submit" name="fz_draft" value="1" class="fz-out">Als Entwurf speichern</button>
						</div>
					</section>
				</div>
			</form>
		</div>
		<script><?php echo self::js(); // phpcs:ignore ?></script>
		<?php
	}

	/* ── Save ────────────────────────────────────────────────────────────────── */

	public static function handle_save() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( 'Keine Berechtigung.' ); }
		check_admin_referer( self::NONCE, self::NONCE );

		$id    = (int) ( $_POST['post_id'] ?? 0 );
		$title = sanitize_text_field( wp_unslash( $_POST['post_title'] ?? '' ) );
		if ( '' === $title ) { $title = 'Fahrzeug (Entwurf)'; }
		$pstat = ! empty( $_POST['fz_draft'] ) ? 'draft' : 'publish';

		$arr = array( 'post_type' => M24FZ_CPT::PT, 'post_title' => $title, 'post_status' => $pstat );
		// Veröffentlichungsdatum (Backdating/Vorausdatierung) → post_date.
		$pd = (string) wp_unslash( $_POST['fz_post_date'] ?? '' );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', $pd, $m ) ) {
			$mysql = $m[1] . ' ' . $m[2] . ':00';
			$arr['post_date']     = $mysql;
			$arr['post_date_gmt'] = get_gmt_from_date( $mysql );
			$arr['edit_date']     = true;
		}
		if ( $id && M24FZ_CPT::PT === get_post_type( $id ) ) {
			$arr['ID'] = $id;
			wp_update_post( $arr ); // → save_post_m24_fahrzeug → M24FZ_Meta::save() (bestehende Felder).
		} else {
			$id = (int) wp_insert_post( $arr );
		}
		if ( ! $id || is_wp_error( $id ) ) { wp_die( 'Speichern fehlgeschlagen.' ); }

		// Beitragsbild (Titelbild).
		$tid = (int) ( $_POST['_thumbnail_id'] ?? 0 );
		if ( $tid ) { set_post_thumbnail( $id, $tid ); } else { delete_post_thumbnail( $id ); }

		// Neue optionale Felder (nur Komfort-Maske → keine Kollision mit klassischer Box).
		$unit = ( 'mi' === ( $_POST['_m24fz_laufleistung_einheit'] ?? '' ) ) ? 'mi' : 'km';
		update_post_meta( $id, '_m24fz_laufleistung_einheit', $unit );
		update_post_meta( $id, '_m24fz_waehrung', ( 'CHF' === ( $_POST['_m24fz_waehrung'] ?? '' ) ) ? 'CHF' : 'EUR' );
		update_post_meta( $id, '_m24fz_anzahl_halter', (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST['_m24fz_anzahl_halter'] ?? '' ) ) );
		update_post_meta( $id, '_m24fz_preis_reduziert', (int) preg_replace( '/\D/', '', (string) wp_unslash( $_POST['_m24fz_preis_reduziert'] ?? '' ) ) );
		update_post_meta( $id, '_m24fz_fahrbereit', empty( $_POST['_m24fz_fahrbereit'] ) ? 0 : 1 );
		update_post_meta( $id, '_m24fz_zugelassen', empty( $_POST['_m24fz_zugelassen'] ) ? 0 : 1 );
		update_post_meta( $id, '_m24fz_matching_numbers', empty( $_POST['_m24fz_matching_numbers'] ) ? 0 : 1 );
		update_post_meta( $id, '_m24fz_mwst_ausweisbar', empty( $_POST['_m24fz_mwst_ausweisbar'] ) ? 0 : 1 );
		update_post_meta( $id, '_m24fz_zustand', self::clean_slugs( $_POST['_m24fz_zustand'] ?? array(), M24FZ_Telemetry::zustand_options() ) );
		update_post_meta( $id, '_m24fz_ausstattung', self::clean_slugs( $_POST['_m24fz_ausstattung'] ?? array(), M24FZ_Telemetry::ausstattung_options() ) );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . M24FZ_CPT::PT . '&page=' . self::PAGE . '&post=' . $id . '&updated=1' ) );
		exit;
	}

	/** Nur erlaubte Slugs (gegen die Optionsliste) durchlassen. */
	private static function clean_slugs( $raw, $allowed ) {
		$out = array();
		foreach ( (array) $raw as $s ) { $s = sanitize_key( wp_unslash( $s ) ); if ( isset( $allowed[ $s ] ) ) { $out[] = $s; } }
		return array_values( array_unique( $out ) );
	}

	/* ── Inline CSS / JS ─────────────────────────────────────────────────────── */

	private static function css() {
		return <<<CSS
.fz-screen{font-family:'Saira',-apple-system,Segoe UI,sans-serif;color:#14161a;margin:-10px -20px 0 -20px}
.fz-screen *{box-sizing:border-box}
.fz-topbar{position:sticky;top:32px;z-index:30;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;background:#fff;border-bottom:1px solid #e2e2de;padding:12px 24px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.fz-tb-left{display:flex;align-items:center;gap:10px}.fz-tb-left strong{font-size:16px;font-weight:700}
.fz-tb-status{font-size:11px;font-weight:700;padding:3px 9px;border-radius:999px}
.fz-tb-status.pub{background:#e6f4ea;color:#1a7f37}.fz-tb-status.draft{background:#fff4d6;color:#9a6b25}
.fz-tb-right{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.fz-seg{display:inline-flex;align-items:stretch;vertical-align:top;border:1px solid #d4d4d0;border-radius:8px;overflow:hidden;font-size:13px;font-weight:600;background:#fff;line-height:1}
.fz-seg>span,.fz-seg>a,.fz-seg>label{display:inline-flex;align-items:center;padding:10px 16px;margin:0;cursor:pointer;text-decoration:none;color:#50575e;background:#fff;border:0}
.fz-seg>span+span,.fz-seg>a+a,.fz-seg>label+label{border-left:1px solid #e2e2de}
.fz-seg>.on,.fz-seg>label.on{background:#14161a;color:#fff;border-left-color:#14161a}
/* Fahrzeugtyp: exakte 50:50-Teilung, eine Mittellinie, kein weißer Streifen */
.fz-seg-typ{display:grid;grid-template-columns:1fr 1fr;width:100%;align-items:stretch}
/* höhere Spezifität als „.fz-f label" (margin-bottom:6px) → Pill füllt volle Zellhöhe, kein weißer Streifen */
.fz-seg.fz-seg-typ>label{position:relative;height:100%;margin:0;box-sizing:border-box;display:flex;align-items:center;justify-content:center;text-align:center}
.fz-seg-typ input{position:absolute;opacity:0;width:0;height:0}
.fz-body{max-width:1040px;margin:0 auto;padding:20px 24px 80px}
.fz-note.ok{display:flex;align-items:center;gap:14px;background:#e6f4ea;border:1px solid #b6e0c2;color:#1a7f37;border-radius:10px;padding:12px 16px;margin-bottom:16px;font-weight:600}
.fz-note.ok a{color:#0e6b2e;font-weight:700;text-decoration:underline}
.fz-save{font:inherit;font-weight:700;border:0;border-radius:8px;padding:11px 20px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#1f74c4,#0e447e);font-size:14px}
.fz-out{font:inherit;font-weight:600;background:#fff;color:#9a6b25;border:1.5px solid #9a6b25;border-radius:8px;padding:9px 16px;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block}
.fz-out.ghost{color:#6b7077;border-color:#d4d4d0}
.fz-add{font:inherit;font-weight:600;background:#fff;color:#9a6b25;border:1.5px dashed #9a6b25;border-radius:8px;padding:8px 14px;cursor:pointer;font-size:13px;margin-top:6px}
.fz-titlebar{background:#fff;border:1px solid #e6e6e3;border-radius:12px;padding:16px 18px;margin-bottom:18px}
.fz-titlebar label{display:block;font-weight:600;font-size:12px;color:#50575e;margin-bottom:6px}
.fz-titlebar input{width:100%;font:inherit;font-size:20px;font-weight:600;padding:10px 12px;border:1px solid #d9d9d6;border-radius:8px}
.fz-sec{background:#fff;border:1px solid #e6e6e3;border-radius:12px;padding:22px 24px;margin-bottom:18px}
.fz-sec h2{display:flex;align-items:center;gap:12px;font-size:19px;font-weight:700;margin:0 0 4px}
.fz-sec h2 .n{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#14161a;color:#fff;font-size:15px;font-weight:700}
.fz-sec h3{font-size:13px;font-weight:700;margin:18px 0 8px;color:#9a6b25;text-transform:uppercase;letter-spacing:.04em}
.fz-intro{color:#6b7077;font-size:13px;margin:0 0 14px}
.fz-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 18px;margin-bottom:6px}
.fz-f{display:flex;flex-direction:column;margin-bottom:14px}
.fz-f label{font-weight:600;font-size:12px;color:#50575e;margin-bottom:6px}
.fz-f .req{color:#c0392b}
.fz-f input,.fz-f select,.fz-f textarea{font:inherit;font-size:14px;padding:10px 12px;border:1px solid #d9d9d6;border-radius:8px;background:#fff;width:100%}
.fz-f input:focus,.fz-f select:focus{outline:0;border-color:#1f74c4;box-shadow:0 0 0 3px rgba(31,116,196,.12)}
.fz-help{font-weight:400;color:#8a9099;font-size:12px}
.fz-inline{display:flex;gap:8px;align-items:stretch}.fz-inline input{flex:1 1 auto;min-width:0;width:100%}.fz-unit{width:60px;flex:0 0 60px;padding:10px 6px}
.fz-checks{display:flex;flex-wrap:wrap;gap:10px}
.fz-chip{display:inline-flex;align-items:center;gap:9px;border:1.5px solid #d9d9d6;border-radius:999px;padding:9px 16px;font-size:13px;font-weight:600;color:#50575e;background:#fff;cursor:pointer;user-select:none;transition:.15s}
.fz-chip input{position:absolute;opacity:0;width:0;height:0}
/* Checkmark-Box (eckig) signalisiert Mehrfachauswahl — nicht Radio */
.fz-chip .dot{position:relative;width:17px;height:17px;border-radius:5px;border:2px solid #c9c9c4;background:#fff;flex:0 0 auto;transition:.15s}
.fz-chip.on{background:#f6efe3;border-color:#9a6b25;color:#9a6b25}
.fz-chip.on .dot{border-color:#9a6b25;background:#9a6b25}
.fz-chip.on .dot:after{content:'✓';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700;line-height:1}
.fz-toggles{display:flex;gap:24px;flex-wrap:wrap;align-items:center}
.fz-toggle{display:inline-flex;align-items:center;gap:8px;font-size:14px;font-weight:600;cursor:pointer;color:#14161a}
.fz-toggle input{position:absolute;opacity:0}
.fz-toggle span{width:42px;height:24px;border-radius:999px;background:#cfd2d6;position:relative;transition:.2s;flex:0 0 auto}
.fz-toggle span:before{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s}
.fz-toggle input:checked + span{background:#1a7f37}
.fz-toggle.danger input:checked + span{background:#c0392b}
.fz-toggle input:checked + span:before{transform:translateX(18px)}
.fz-renn{margin-top:10px;padding-top:6px;border-top:1px dashed #e0e0dc}
.fz-thumb{display:flex;gap:16px;align-items:center}
.fz-thumb-prev{position:relative;width:210px;height:138px;border-radius:8px;border:2px solid #9a6b25;overflow:hidden;background:#f3f3f1;display:flex;align-items:center;justify-content:center}
.fz-thumb-prev.empty{border-style:dashed}
.fz-thumb-prev .ph{color:#9aa0a6;font-size:13px}
.fz-thumb-prev img{width:100%;height:100%;object-fit:cover}
.fz-titel-tag{position:absolute;top:8px;left:8px;background:#9a6b25;color:#fff;font-size:10px;font-weight:700;letter-spacing:.05em;padding:3px 8px;border-radius:4px;z-index:2}
.fz-thumb-btns{display:flex;flex-direction:column;gap:8px}
.fz-galbox{margin:10px 0;padding:10px 0;border-top:1px solid #f0f0ee}
.fz-galbox strong{display:block;font-size:13px;margin-bottom:2px}
.fz-galhint{display:block;color:#8a9099;font-size:11px;margin-bottom:8px}
.fz-gal{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px;min-height:12px;align-items:flex-end}
.fz-gal span{position:relative;display:block;cursor:grab;transition:transform .1s,box-shadow .1s}
.fz-gal span:active{cursor:grabbing}
.fz-gal span:before{content:'⠿⠿';position:absolute;top:5px;left:6px;color:#fff;font-size:12px;letter-spacing:-2px;text-shadow:0 1px 3px rgba(0,0,0,.7);opacity:0;transition:.12s;z-index:1;pointer-events:none}
.fz-gal span:hover:before{opacity:.95}
.fz-gal img{height:115px;width:auto;max-width:240px;object-fit:contain;border-radius:8px;border:1px solid #d9d9d6;display:block;pointer-events:none;background:#f3f3f1}
.fz-gal span:hover img{box-shadow:0 3px 10px rgba(0,0,0,.14)}
/* Markierung: erste 3 Außen = Frontend-3er-Block (Nummer + Messing-Kontur) */
.fz-gal span.is-teaser img{box-shadow:0 0 0 2px #9a6b25}
.fz-gal .tnum{position:absolute;top:-7px;left:-7px;background:#9a6b25;color:#fff;border-radius:50%;width:20px;height:20px;line-height:18px;text-align:center;font-size:12px;font-weight:700;z-index:2;border:2px solid #fff}
.fz-gal .ui-sortable-helper{box-shadow:0 8px 20px rgba(0,0,0,.22);transform:scale(1.04)}
.fz-gal-ph{visibility:visible!important;height:115px;width:170px;border-radius:8px;background:#f6efe3;border:2px dashed #9a6b25}
.fz-gal .rm{position:absolute;top:-7px;right:-7px;background:#c0392b;color:#fff;border-radius:50%;width:20px;height:20px;line-height:18px;text-align:center;font-size:13px;cursor:pointer;z-index:2}
#fz-keyfacts p,#fz-videos p{margin:0 0 8px}
#fz-keyfacts input,#fz-videos input{width:100%;font:inherit;font-size:14px;padding:9px 12px;border:1px solid #d9d9d6;border-radius:8px}
.fz-foot{display:flex;gap:12px;align-items:center;margin-top:16px;padding-top:14px;border-top:1px solid #f0f0ee}
@media(max-width:900px){.fz-row,.fz-checks{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.fz-row,.fz-checks{grid-template-columns:1fr}}
CSS;
	}

	private static function js() {
		return <<<JS
jQuery(function($){
	// Live PS → kW.
	function kw(){ var ps=parseInt(($('#_m24fz_leistung_ps').val()||'').replace(/\\D/g,''),10);
		if(ps>0){ var v=(Math.round(ps*0.73549875*100)/100).toFixed(2).replace('.',','); $('#fz-kw').text(v+' kW ('+ps+' PS)'); }
		else { $('#fz-kw').text('In PS — kW automatisch.'); } }
	$('#_m24fz_leistung_ps').on('input',kw); kw();
	// Renn-Block + Segmented-Typ + Hide straßenspezifischer Felder bei Rennwagen.
	function toggleRenn(){ var renn=$('input[name=_m24fz_template_typ]:checked').val()==='renn';
		$('[data-renn]').toggle(renn); $('.fz-strasse-only').toggle(!renn);
		$('.fz-seg-typ label').removeClass('on'); $('input[name=_m24fz_template_typ]:checked').closest('label').addClass('on'); }
	$('input[name=_m24fz_template_typ]').on('change',toggleRenn); toggleRenn();
	// Zustand/Ausstattung Toggle-Chips.
	$(document).on('change','.fz-chip input',function(){ $(this).closest('.fz-chip').toggleClass('on',this.checked); });
	// Laufleistung: nur Ziffern, max 7 (≤ 9.999.999).
	$('#_m24fz_laufleistung').on('input',function(){ var c=this.value.replace(/\\D/g,'').slice(0,7); if(c!==this.value){ this.value=c; } });
	// Repeater.
	$('#fz-kf-add').on('click',function(){ $('#fz-keyfacts').append('<p><input type="text" name="_m24fz_keyfacts[]" placeholder="Highlight"></p>'); });
	$('#fz-vid-add').on('click',function(){ $('#fz-videos').append('<p><input type="url" name="_m24fz_videos[]" placeholder="https://youtu.be/…"></p>'); });
	// Beitragsbild.
	$('#fz-thumb-pick').on('click',function(e){ e.preventDefault();
		var fr=wp.media({title:'Titelbild wählen',multiple:false,library:{type:'image'}});
		fr.on('select',function(){ var a=fr.state().get('selection').first().toJSON(); var u=(a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url;
			$('input[name=_thumbnail_id]').val(a.id); $('.fz-thumb-prev').removeClass('empty').html('<span class="fz-titel-tag">TITEL</span><img src="'+u+'" alt="">'); });
		fr.open();
	});
	$('#fz-thumb-clear').on('click',function(e){ e.preventDefault(); $('input[name=_thumbnail_id]').val(''); $('.fz-thumb-prev').addClass('empty').html('<span class="fz-titel-tag">TITEL</span><span class="ph">Kein Titelbild</span>'); });
	// Galerien.
	function syncGal(box){ var ids=[]; box.find('.fz-gal span').each(function(){ ids.push($(this).data('id')); }); box.find('input[type=hidden]').val(ids.join(',')); }
	// Erste 3 Außen-Thumbs nummerieren (Teaser-3er-Block); nur die Außen-Box.
	function reTeaser(box){ if(box.data('galkey')!=='_m24fz_gal_aussen'){ return; }
		box.find('.fz-gal span').each(function(i){ var s=$(this);
			if(i<3){ s.addClass('is-teaser'); if(!s.find('.tnum').length){ s.append('<i class="tnum"></i>'); } s.find('.tnum').text(i+1); }
			else { s.removeClass('is-teaser'); s.find('.tnum').remove(); } }); }
	function afterChange(box){ syncGal(box); reTeaser(box); }
	$('.fz-gal').sortable({ placeholder:'fz-gal-ph', forcePlaceholderSize:true, cursor:'grabbing', tolerance:'pointer', opacity:.85, update:function(){ afterChange($(this).closest('[data-galkey]')); } });
	$(document).on('click','.fz-gal .rm',function(){ var box=$(this).closest('[data-galkey]'); $(this).closest('span').remove(); afterChange(box); });
	$('.fz-gal-add').on('click',function(e){ e.preventDefault();
		var box=$(this).closest('[data-galkey]'), gal=box.find('.fz-gal');
		var fr=wp.media({title:'Bilder hinzufügen',multiple:true,library:{type:'image'}});
		fr.on('select',function(){ fr.state().get('selection').each(function(a){ a=a.toJSON(); var s=a.sizes||{}; var u=(s.medium&&s.medium.url)||(s.thumbnail&&s.thumbnail.url)||a.url;
			gal.append('<span data-id="'+a.id+'"><img src="'+u+'" alt="" loading="lazy"><i class="rm">×</i></span>'); }); afterChange(box); });
		fr.open();
	});
});
JS;
	}
}
