<?php
/**
 * M24 Fahrzeug — Komfort-Eingabemaske (Elferspot-Stil), full-width Admin-Screen
 * Modul: includes/fahrzeug/class-m24fz-editor-screen.php
 *
 * Große, sektionierte Maske zum Anlegen/Bearbeiten eines m24_fahrzeug — Default statt
 * Classic/Gutenberg (per ?classic=1 umschaltbar). Schreibt DIESELBEN _m24fz_-Felder +
 * post_title + Beitragsbild: das Formular nutzt die gleichen Feldnamen + den m24fz_meta-Nonce,
 * daher übernimmt M24FZ_Meta::save() (Hook save_post_m24_fahrzeug) die komplette Speicher-Logik.
 * Dieser Screen ergänzt nur Titel, Beitragsbild und Redirect.
 *
 * Schritt 1: Layout + alle bestehenden Felder (sofort nutzbar).
 * Schritt 2 (Folge): neue optionale Felder (Interne ID, Zustand[], Ausstattung[], …).
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
		// Komfort-Maske als Default: Standard-Editor-Aufrufe auf den Screen umleiten (außer ?classic=1).
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
		if ( 'm24_fahrzeug_page_' . self::PAGE !== $hook && false === strpos( (string) $hook, self::PAGE ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_style( 'm24fz-saira', 'https://fonts.googleapis.com/css2?family=Saira:wght@400;500;600;700&display=swap', array(), null );
	}

	/* ── Render ──────────────────────────────────────────────────────────────── */

	private static function g( $id, $k, $d = '' ) { $v = $id ? get_post_meta( $id, $k, true ) : ''; return '' === $v || null === $v ? $d : $v; }

	/** Ein Standard-Textfeld. */
	private static function field( $id, $key, $label, $opts = array() ) {
		$type = $opts['type'] ?? 'text';
		$req  = ! empty( $opts['req'] ) ? ' <span class="req">*</span>' : '';
		$ph   = $opts['ph'] ?? '';
		$help = $opts['help'] ?? '';
		$list = ! empty( $opts['list'] ) ? ' list="' . esc_attr( $opts['list'] ) . '"' : '';
		echo '<div class="fz-f">';
		printf( '<label for="%1$s">%2$s%3$s</label><input type="%4$s" id="%1$s" name="%1$s" value="%5$s" placeholder="%6$s"%7$s>',
			esc_attr( $key ), esc_html( $label ), $req, esc_attr( $type ), esc_attr( self::g( $id, $key ) ), esc_attr( $ph ), $list ); // phpcs:ignore
		if ( $help ) { printf( '<span class="fz-help">%s</span>', esc_html( $help ) ); }
		echo '</div>';
	}

	public static function render() {
		$id  = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( $id && M24FZ_CPT::PT !== get_post_type( $id ) ) { $id = 0; }
		$post   = $id ? get_post( $id ) : null;
		$title  = $post ? $post->post_title : '';
		$typ    = self::g( $id, '_m24fz_template_typ', 'strasse' );
		$kat    = self::g( $id, '_m24fz_kat', 'race-cars' );
		$status = $id ? M24FZ_CPT::status( $id ) : 'gelistet';
		$paf    = (int) self::g( $id, '_m24fz_preis_auf_anfrage' );
		$thumb  = $id ? (int) get_post_thumbnail_id( $id ) : 0;
		$thumbU = $thumb ? wp_get_attachment_image_url( $thumb, 'medium' ) : '';
		$models = self::bmw_baureihen();
		$updated = isset( $_GET['updated'] );
		?>
		<style><?php echo self::css(); // phpcs:ignore ?></style>
		<div class="fz-screen">
			<?php if ( $updated ) : ?><div class="fz-note ok">Gespeichert.</div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fz-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="post_id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
				<?php wp_nonce_field( M24FZ_Meta::NONCE, M24FZ_Meta::NONCE ); // triggert M24FZ_Meta::save() ?>

				<header class="fz-head">
					<div>
						<h1><?php echo $id ? 'Fahrzeug bearbeiten' : 'Neues Fahrzeug anlegen'; ?></h1>
						<p class="fz-sub">Komfort-Maske · schreibt dieselben Felder wie der klassische Editor.</p>
					</div>
					<div class="fz-head-actions">
						<a class="fz-link" href="<?php echo esc_url( $id ? admin_url( 'post.php?post=' . $id . '&action=edit&classic=1' ) : admin_url( 'post-new.php?post_type=' . M24FZ_CPT::PT . '&classic=1' ) ); ?>">Klassisch bearbeiten</a>
						<?php if ( $id ) : ?><a class="fz-link" href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank">Inserat ansehen ↗</a><?php endif; ?>
						<button type="submit" class="fz-save">Speichern &amp; Veröffentlichen</button>
					</div>
				</header>

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
							<label>Template-Typ</label>
							<div class="fz-radios">
								<label class="fz-pill-r"><input type="radio" name="_m24fz_template_typ" value="strasse" <?php checked( $typ, 'strasse' ); ?>> Straßenfahrzeug</label>
								<label class="fz-pill-r"><input type="radio" name="_m24fz_template_typ" value="renn" <?php checked( $typ, 'renn' ); ?>> Rennfahrzeug</label>
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
						<?php
						self::field( $id, '_m24fz_baujahr', 'Baujahr', array( 'ph' => 'z. B. 1989' ) );
						self::field( $id, '_m24fz_erstzulassung', 'Erstzulassung', array( 'ph' => 'TT.MM.JJJJ' ) );
						self::field( $id, '_m24fz_marke', 'Marke', array( 'req' => true, 'ph' => 'BMW', 'help' => 'Steuert „Ähnliche Fahrzeuge".' ) );
						?>
					</div>
					<div class="fz-row">
						<?php
						self::field( $id, '_m24fz_baureihe', 'Baureihe', array( 'list' => 'fz-baureihen', 'ph' => 'z. B. 3er E30' ) );
						self::field( $id, '_m24fz_modell', 'Modell', array( 'ph' => 'z. B. M3 EVO II' ) );
						self::field( $id, '_m24fz_fin', 'FIN' );
						?>
					</div>
					<datalist id="fz-baureihen"><?php foreach ( $models as $m ) { echo '<option value="' . esc_attr( $m ) . '">'; } ?></datalist>
				</section>

				<!-- 2. HISTORIE & ZUSTAND -->
				<section class="fz-sec">
					<h2><span class="n">2</span> Historie &amp; Zustand</h2>
					<div class="fz-row">
						<?php self::field( $id, '_m24fz_laufleistung', 'Laufleistung (km)', array( 'req' => true, 'ph' => 'z. B. 45000', 'help' => 'Nur für Straßenfahrzeuge.' ) ); ?>
						<?php self::country_field( $id, '_m24fz_land_erstauslieferung', 'Land der Erstauslieferung' ); ?>
						<?php self::country_field( $id, '_m24fz_standort', 'Fahrzeugstandort (Land)' ); ?>
					</div>
					<div class="fz-row">
						<?php
						self::field( $id, '_m24fz_standort_ort', 'Standort (Ort)', array( 'ph' => 'z. B. Stuttgart' ) );
						self::field( $id, '_m24fz_neu_gebraucht', 'Neu / Gebraucht', array( 'req' => true, 'ph' => 'Gebraucht' ) );
						?>
					</div>
				</section>

				<!-- 3. TECHNISCHE DATEN -->
				<section class="fz-sec">
					<h2><span class="n">3</span> Technische Daten</h2>
					<div class="fz-row">
						<?php
						self::field( $id, '_m24fz_hubraum', 'Hubraum', array( 'ph' => 'z. B. 2,3 l' ) );
						self::field( $id, '_m24fz_leistung_ps', 'Leistung (PS)', array( 'ph' => 'z. B. 238', 'help' => 'Eingabe in PS — kW wird automatisch berechnet.' ) );
						self::field( $id, '_m24fz_karosserie', 'Karosserie', array( 'ph' => 'z. B. Coupé' ) );
						?>
					</div>
					<div class="fz-row">
						<?php
						self::field( $id, '_m24fz_aussenfarbe', 'Außenfarbe', array( 'req' => true ) );
						self::field( $id, '_m24fz_farbbez_hersteller', 'Hersteller-Farbbezeichnung' );
						self::field( $id, '_m24fz_innenfarbe', 'Innenfarbe', array( 'req' => true ) );
						?>
					</div>
					<div class="fz-row">
						<?php
						self::field( $id, '_m24fz_innenmaterial', 'Innenmaterial' );
						self::field( $id, '_m24fz_lenkung', 'Lenkung', array( 'ph' => 'Links / Rechts' ) );
						?>
						<div class="fz-f">
							<label for="_m24fz_getriebe">Getriebe</label>
							<select id="_m24fz_getriebe" name="_m24fz_getriebe"><option value="">—</option>
								<?php foreach ( M24FZ_Telemetry::getriebe_options() as $v => $l ) { printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( self::g( $id, '_m24fz_getriebe' ), $v, false ), esc_html( $l ) ); } ?>
							</select>
						</div>
					</div>
					<div class="fz-row">
						<?php
						self::field( $id, '_m24fz_antrieb', 'Antrieb', array( 'ph' => 'Heck / Allrad' ) );
						self::field( $id, '_m24fz_kraftstoff', 'Kraftstoff', array( 'ph' => 'Benzin' ) );
						?>
					</div>

					<div class="fz-renn" data-renn>
						<h3>Renn-spezifisch</h3>
						<div class="fz-row">
							<label class="fz-toggle"><input type="checkbox" name="_m24fz_wagenpass" <?php checked( 1, (int) self::g( $id, '_m24fz_wagenpass' ) ); ?>><span></span> Wagenpass</label>
							<label class="fz-toggle"><input type="checkbox" name="_m24fz_rennhistorie" <?php checked( 1, (int) self::g( $id, '_m24fz_rennhistorie' ) ); ?>><span></span> Rennhistorie</label>
						</div>
						<div class="fz-row"><?php for ( $i = 1; $i <= 3; $i++ ) {
							self::field( $id, "_m24fz_race_opt{$i}_label", "Option $i — Label" );
							self::field( $id, "_m24fz_race_opt{$i}_value", "Option $i — Wert" );
						} ?></div>
					</div>
				</section>

				<!-- 4. INSERAT & MEDIEN -->
				<section class="fz-sec">
					<h2><span class="n">4</span> Inserat &amp; Medien</h2>

					<div class="fz-f">
						<label>Highlights <span class="fz-help">Was macht Ihr Fahrzeug besonders? (3–5)</span></label>
						<div id="fz-keyfacts"><?php foreach ( array_pad( (array) get_post_meta( $id, '_m24fz_keyfacts', true ), 3, '' ) as $kf ) : ?>
							<p><input type="text" name="_m24fz_keyfacts[]" value="<?php echo esc_attr( $kf ); ?>" placeholder="z. B. Matching Numbers"></p>
						<?php endforeach; ?></div>
						<button type="button" class="fz-add" id="fz-kf-add">+ Highlight hinzufügen</button>
					</div>

					<div class="fz-f">
						<label for="_m24fz_zusammenfassung">Zusammenfassung <span class="req">*</span> <span class="fz-help">Kurzer Intro-Text (auch Meta-Description).</span></label>
						<textarea id="_m24fz_zusammenfassung" name="_m24fz_zusammenfassung" rows="3"><?php echo esc_textarea( self::g( $id, '_m24fz_zusammenfassung' ) ); ?></textarea>
					</div>

					<div class="fz-f">
						<label>Detailbeschreibung</label>
						<?php wp_editor( self::g( $id, '_m24fz_beschreibung' ), 'm24fzbeschr', array( 'textarea_name' => '_m24fz_beschreibung', 'textarea_rows' => 8, 'media_buttons' => false, 'teeny' => true ) ); ?>
					</div>

					<div class="fz-row fz-pricebox">
						<?php self::field( $id, '_m24fz_preis', 'Preis (EUR)', array( 'ph' => 'z. B. 189000' ) ); ?>
						<label class="fz-toggle"><input type="checkbox" name="_m24fz_preis_auf_anfrage" <?php checked( 1, $paf ); ?>><span></span> Preis nur auf Anfrage</label>
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
							<div class="fz-thumb-prev<?php echo $thumbU ? '' : ' empty'; ?>"><?php if ( $thumbU ) : ?><img src="<?php echo esc_url( $thumbU ); ?>" alt=""><?php else : ?><span>Kein Titelbild</span><?php endif; ?></div>
							<input type="hidden" name="_thumbnail_id" value="<?php echo (int) $thumb; ?>">
							<div class="fz-thumb-btns">
								<button type="button" class="fz-out" id="fz-thumb-pick">Titelbild wählen</button>
								<button type="button" class="fz-out ghost" id="fz-thumb-clear">Entfernen</button>
							</div>
						</div>
					</div>

					<div class="fz-f">
						<label>Bildergalerie (je Kategorie · ziehen zum Sortieren)</label>
						<?php foreach ( array( '_m24fz_gal_aussen' => 'Außen', '_m24fz_gal_innen' => 'Innen', '_m24fz_gal_motor' => 'Motor', '_m24fz_gal_unterboden' => 'Unterboden' ) as $key => $label ) :
							$ids = (array) get_post_meta( $id, $key, true ); ?>
							<div class="fz-galbox" data-galkey="<?php echo esc_attr( $key ); ?>">
								<strong><?php echo esc_html( $label ); ?></strong>
								<div class="fz-gal"><?php foreach ( $ids as $aid ) { $u = wp_get_attachment_image_url( $aid, 'thumbnail' ); if ( ! $u ) { continue; } printf( '<span data-id="%d"><img src="%s" alt=""><i class="rm">×</i></span>', (int) $aid, esc_url( $u ) ); } ?></div>
								<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $ids ) ) ); ?>">
								<button type="button" class="fz-out fz-gal-add">Bilder hinzufügen</button>
							</div>
						<?php endforeach; ?>
					</div>
				</section>

				<!-- 5. STATUS & VERÖFFENTLICHEN -->
				<section class="fz-sec">
					<h2><span class="n">5</span> Status &amp; Veröffentlichen</h2>
					<div class="fz-row">
						<label class="fz-toggle"><input type="checkbox" name="m24fz_verkauft" <?php checked( 'verkauft', $status ); ?>><span></span> Verkauft</label>
						<label class="fz-toggle"><input type="checkbox" name="m24fz_reserviert" <?php checked( 'reserviert', $status ); ?>><span></span> Reserviert</label>
						<label class="fz-toggle danger"><input type="checkbox" name="m24fz_deaktiviert" <?php checked( 'deaktiviert', $status ); ?>><span></span> Deaktivieren (Frontend weg)</label>
					</div>
					<div class="fz-foot">
						<button type="submit" class="fz-save">Speichern &amp; Veröffentlichen</button>
						<button type="submit" name="fz_draft" value="1" class="fz-out">Als Entwurf speichern</button>
					</div>
				</section>
			</form>
		</div>
		<script><?php echo self::js(); // phpcs:ignore ?></script>
		<?php
	}

	private static function country_field( $id, $key, $label ) {
		echo '<div class="fz-f"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label>';
		echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '"><option value="">—</option>';
		foreach ( M24FZ_Telemetry::countries() as $cc => $cn ) {
			printf( '<option value="%s"%s>%s %s</option>', esc_attr( $cc ), selected( self::g( $id, $key ), $cc, false ), esc_html( M24FZ_Telemetry::flag( $cc ) ), esc_html( $cn ) );
		}
		echo '</select></div>';
	}

	/** Baureihen-Vorschläge aus data/bmw-models.json (Komfort-Datalist). */
	private static function bmw_baureihen() {
		$f = M24_PLATTFORM_DIR . 'data/bmw-models.json';
		if ( ! is_readable( $f ) ) { return array(); }
		$j = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore
		$out = array();
		foreach ( (array) ( $j['models'] ?? array() ) as $m ) { if ( ! empty( $m['display'] ) ) { $out[] = $m['display']; } }
		return $out;
	}

	/* ── Save ────────────────────────────────────────────────────────────────── */

	public static function handle_save() {
		if ( ! current_user_can( 'edit_posts' ) ) { wp_die( 'Keine Berechtigung.' ); }
		check_admin_referer( self::NONCE, self::NONCE );

		$id     = (int) ( $_POST['post_id'] ?? 0 );
		$title  = sanitize_text_field( wp_unslash( $_POST['post_title'] ?? '' ) );
		if ( '' === $title ) { $title = 'Fahrzeug (Entwurf)'; }
		$pstat  = ! empty( $_POST['fz_draft'] ) ? 'draft' : 'publish';

		$arr = array( 'post_type' => M24FZ_CPT::PT, 'post_title' => $title, 'post_status' => $pstat );
		if ( $id && M24FZ_CPT::PT === get_post_type( $id ) ) {
			$arr['ID'] = $id;
			wp_update_post( $arr ); // → save_post_m24_fahrzeug → M24FZ_Meta::save() speichert alle Metas aus $_POST.
		} else {
			$id = (int) wp_insert_post( $arr ); // save_post feuert ebenfalls → Metas werden gespeichert.
		}
		if ( ! $id || is_wp_error( $id ) ) { wp_die( 'Speichern fehlgeschlagen.' ); }

		// Beitragsbild (Titelbild).
		$tid = (int) ( $_POST['_thumbnail_id'] ?? 0 );
		if ( $tid ) { set_post_thumbnail( $id, $tid ); } else { delete_post_thumbnail( $id ); }

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . M24FZ_CPT::PT . '&page=' . self::PAGE . '&post=' . $id . '&updated=1' ) );
		exit;
	}

	/* ── Inline CSS / JS ─────────────────────────────────────────────────────── */

	private static function css() {
		return <<<CSS
.fz-screen{font-family:'Saira',-apple-system,Segoe UI,sans-serif;max-width:1080px;margin:18px 20px 60px;color:#14161a}
.fz-screen *{box-sizing:border-box}
.fz-note.ok{background:#e6f4ea;color:#1a7f37;border:1px solid #b6e0c2;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-weight:600}
.fz-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;margin-bottom:8px}
.fz-head h1{font-size:24px;font-weight:700;margin:0}
.fz-sub{color:#6b7077;margin:4px 0 0;font-size:13px}
.fz-head-actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.fz-link{color:#1763ad;text-decoration:none;font-size:13px;font-weight:600}
.fz-save{font:inherit;font-weight:700;border:0;border-radius:8px;padding:12px 22px;color:#fff;cursor:pointer;background:linear-gradient(135deg,#1f74c4,#0e447e);font-size:14px}
.fz-out{font:inherit;font-weight:600;background:#fff;color:#9a6b25;border:1.5px solid #9a6b25;border-radius:8px;padding:9px 16px;cursor:pointer;font-size:13px}
.fz-out.ghost{color:#6b7077;border-color:#d4d4d0}
.fz-add{font:inherit;font-weight:600;background:#fff;color:#9a6b25;border:1.5px dashed #9a6b25;border-radius:8px;padding:8px 14px;cursor:pointer;font-size:13px;margin-top:6px}
.fz-titlebar{background:#fff;border:1px solid #e6e6e3;border-radius:12px;padding:16px 18px;margin:14px 0 18px}
.fz-titlebar label{display:block;font-weight:600;font-size:12px;color:#50575e;margin-bottom:6px}
.fz-titlebar input{width:100%;font:inherit;font-size:20px;font-weight:600;padding:10px 12px;border:1px solid #d9d9d6;border-radius:8px}
.fz-sec{background:#fff;border:1px solid #e6e6e3;border-radius:12px;padding:22px 24px;margin-bottom:18px}
.fz-sec h2{display:flex;align-items:center;gap:12px;font-size:19px;font-weight:700;color:#14161a;margin:0 0 4px}
.fz-sec h2 .n{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#14161a;color:#fff;font-size:15px;font-weight:700}
.fz-sec h3{font-size:14px;font-weight:700;margin:18px 0 6px;color:#9a6b25;text-transform:uppercase;letter-spacing:.04em}
.fz-intro{color:#6b7077;font-size:13px;margin:0 0 14px}
.fz-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 18px;margin-bottom:6px}
.fz-f{display:flex;flex-direction:column;margin-bottom:14px}
.fz-f label{font-weight:600;font-size:12px;color:#50575e;margin-bottom:6px}
.fz-f .req{color:#c0392b}
.fz-f input,.fz-f select,.fz-f textarea{font:inherit;font-size:14px;padding:10px 12px;border:1px solid #d9d9d6;border-radius:8px;background:#fff;width:100%}
.fz-f input:focus,.fz-f select:focus,.fz-f textarea:focus{outline:0;border-color:#1f74c4;box-shadow:0 0 0 3px rgba(31,116,196,.12)}
.fz-help{font-weight:400;color:#8a9099;font-size:12px}
.fz-radios{display:flex;gap:10px}
.fz-pill-r{display:inline-flex;align-items:center;gap:6px;border:1px solid #d9d9d6;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:500;cursor:pointer}
.fz-toggle{display:inline-flex;align-items:center;gap:8px;font-size:14px;font-weight:600;cursor:pointer;color:#14161a}
.fz-toggle input{position:absolute;opacity:0}
.fz-toggle span{width:42px;height:24px;border-radius:999px;background:#cfd2d6;position:relative;transition:.2s;flex:0 0 auto}
.fz-toggle span:before{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s}
.fz-toggle input:checked + span{background:#1a7f37}
.fz-toggle.danger input:checked + span{background:#c0392b}
.fz-toggle input:checked + span:before{transform:translateX(18px)}
.fz-renn{margin-top:10px;padding-top:6px;border-top:1px dashed #e0e0dc}
.fz-pricebox{grid-template-columns:1fr 1fr;align-items:center}
.fz-pricebox .fz-toggle{margin-top:18px}
.fz-thumb{display:flex;gap:16px;align-items:center}
.fz-thumb-prev{width:200px;height:130px;border-radius:8px;border:1px solid #e0e0dc;overflow:hidden;background:#f3f3f1;display:flex;align-items:center;justify-content:center}
.fz-thumb-prev.empty span{color:#9aa0a6;font-size:13px}
.fz-thumb-prev img{width:100%;height:100%;object-fit:cover}
.fz-thumb-btns{display:flex;flex-direction:column;gap:8px}
.fz-galbox{margin:10px 0;padding:10px 0;border-top:1px solid #f0f0ee}
.fz-galbox strong{display:block;font-size:13px;margin-bottom:6px}
.fz-gal{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;min-height:8px}
.fz-gal span{position:relative;display:inline-block}
.fz-gal img{width:78px;height:52px;object-fit:cover;border-radius:6px;border:1px solid #d9d9d6;cursor:move}
.fz-gal .rm{position:absolute;top:-7px;right:-7px;background:#c0392b;color:#fff;border-radius:50%;width:18px;height:18px;line-height:16px;text-align:center;font-size:12px;cursor:pointer}
#fz-keyfacts p,#fz-videos p{margin:0 0 8px}
#fz-keyfacts input,#fz-videos input{width:100%;font:inherit;font-size:14px;padding:9px 12px;border:1px solid #d9d9d6;border-radius:8px}
.fz-foot{display:flex;gap:12px;align-items:center;margin-top:12px}
@media(max-width:900px){.fz-row{grid-template-columns:1fr 1fr}.fz-pricebox{grid-template-columns:1fr}}
@media(max-width:600px){.fz-row{grid-template-columns:1fr}}
CSS;
	}

	private static function js() {
		return <<<JS
jQuery(function($){
	// Renn-Block nur bei Typ=Renn zeigen.
	function toggleRenn(){ var renn=$('input[name=_m24fz_template_typ]:checked').val()==='renn'; $('[data-renn]').toggle(renn); }
	$('input[name=_m24fz_template_typ]').on('change',toggleRenn); toggleRenn();
	// Repeater.
	$('#fz-kf-add').on('click',function(){ $('#fz-keyfacts').append('<p><input type="text" name="_m24fz_keyfacts[]" placeholder="Highlight"></p>'); });
	$('#fz-vid-add').on('click',function(){ $('#fz-videos').append('<p><input type="url" name="_m24fz_videos[]" placeholder="https://youtu.be/…"></p>'); });
	// Beitragsbild.
	$('#fz-thumb-pick').on('click',function(e){ e.preventDefault();
		var fr=wp.media({title:'Titelbild wählen',multiple:false,library:{type:'image'}});
		fr.on('select',function(){ var a=fr.state().get('selection').first().toJSON(); var u=(a.sizes&&a.sizes.medium)?a.sizes.medium.url:a.url;
			$('input[name=_thumbnail_id]').val(a.id); $('.fz-thumb-prev').removeClass('empty').html('<img src="'+u+'" alt="">'); });
		fr.open();
	});
	$('#fz-thumb-clear').on('click',function(e){ e.preventDefault(); $('input[name=_thumbnail_id]').val(''); $('.fz-thumb-prev').addClass('empty').html('<span>Kein Titelbild</span>'); });
	// Galerien.
	function syncGal(box){ var ids=[]; box.find('.fz-gal span').each(function(){ ids.push($(this).data('id')); }); box.find('input[type=hidden]').val(ids.join(',')); }
	$('.fz-gal').sortable({ update:function(){ syncGal($(this).closest('[data-galkey]')); } });
	$(document).on('click','.fz-gal .rm',function(){ var box=$(this).closest('[data-galkey]'); $(this).closest('span').remove(); syncGal(box); });
	$('.fz-gal-add').on('click',function(e){ e.preventDefault();
		var box=$(this).closest('[data-galkey]'), gal=box.find('.fz-gal');
		var fr=wp.media({title:'Bilder hinzufügen',multiple:true,library:{type:'image'}});
		fr.on('select',function(){ fr.state().get('selection').each(function(a){ a=a.toJSON(); var u=(a.sizes&&a.sizes.thumbnail)?a.sizes.thumbnail.url:a.url; gal.append('<span data-id="'+a.id+'"><img src="'+u+'" alt=""><i class="rm">×</i></span>'); }); syncGal(box); });
		fr.open();
	});
});
JS;
	}
}
