<?php
/**
 * M24 Plattform — Katalog: Eingabemaske (Admin Meta-Box)
 * Modul: catalog-fields.php
 *
 * Deutsch-only, feld-zuerst (kein Gutenberg). Titel (DE) = Beitragstitel oben.
 * Beschreibung (DE) als stabiles Textfeld -> Meta `_m24_beschreibung_de`
 * (bewusst kein TinyMCE in der Meta-Box; einfache HTML-Tags erlaubt).
 *
 * Seit Migration 004: Preise als Repeater `_m24_preisoptionen` (JSON-Array).
 * Pro Option: Label, art_nr, Brutto/Netto-Toggle, Preisfeld mit Live-Umrechnung.
 * Gewaehlter Eingabemodus pro Artikel in `_m24_preis_eingabe` ('brutto'|'netto').
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class M24_Catalog_Fields {

	const NONCE = 'm24_catalog_fields_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		// Einmalige Reparatur kaputter Varianten-Labels (u-Escapes ohne Backslash) im Bestand.
		add_action( 'admin_init', array( __CLASS__, 'maybe_repair_variant_labels' ) );
		// T22b: BMW-Teilenummer im Titel kompakten (prio 15, VOR auto_slug).
		add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'compact_bmw_in_title' ), 15, 2 );
		// T8: Auto-Slug bei Titel-Change (prio 25, NACH save() bei prio 10).
		add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'auto_slug_from_title' ), 25, 2 );
	}

	/** Einmalig (flag-geschuetzt): Bestands-Varianten-Labels mit u-Escapes reparieren. */
	public static function maybe_repair_variant_labels() {
		if ( get_option( 'm24_variant_labels_repaired_v1' ) ) { return; }
		update_option( 'm24_variant_labels_repaired_v1', gmdate( 'c' ) ); // zuerst sperren
		if ( class_exists( 'M24_Catalog_Pricing' ) ) {
			$r = M24_Catalog_Pricing::repair_labels();
			if ( $r['fixed'] > 0 && class_exists( 'M24_Logger' ) ) {
				M24_Logger::info( 'katalog', sprintf( 'Varianten-Labels repariert: %d/%d Posts.', $r['fixed'], $r['scanned'] ) );
			}
		}
	}

	public static function add_box() {
		add_meta_box( 'm24_teil_daten', 'Teile-Daten', array( __CLASS__, 'render' ), M24_Catalog_CPT::POST_TYPE, 'normal', 'high' );
	}

	/** Aktueller MwSt-Satz fuer Brutto/Netto-Umrechnung — Filter m24_tax_rate. */
	private static function tax_rate() {
		return (float) apply_filters( 'm24_tax_rate', 0.19 );
	}

	public static function render( $post ) {
		wp_nonce_field( 'm24_save_' . $post->ID, self::NONCE );
		$g = function ( $k ) use ( $post ) {
			return esc_attr( get_post_meta( $post->ID, $k, true ) );
		};
		$modus   = get_post_meta( $post->ID, '_m24_mwst_modus', true ) ?: 'regel';
		$typ     = get_post_meta( $post->ID, '_m24_typ', true ) ?: 'gebraucht';
		$status  = get_post_meta( $post->ID, '_m24_status', true ) ?: 'aktiv';
		// Logo-Anzeigen-Default: TRUE wenn Meta nie gesetzt; explizite 0 wird respektiert.
		$logo_raw     = get_post_meta( $post->ID, '_m24_logo_anzeigen', true );
		$logo_anzeigen = ( '' === $logo_raw ) ? true : (bool) (int) $logo_raw;
		$original_teil = '1' === get_post_meta( $post->ID, '_m24_original_teil', true );
		$leichtbau    = (bool) (int) get_post_meta( $post->ID, '_m24_leichtbau', true );
		$rennsport_hinweis = (bool) (int) get_post_meta( $post->ID, '_m24_rennsport_hinweis', true );
		$show_nachbau  = (bool) (int) get_post_meta( $post->ID, '_m24_show_nachbau_hinweis', true );
		// N2/T8: URL-Slug.
		//  - Input-Feld immer LEER (value=""): leer lassen = Auto-Slug aus Titel.
		//    Vorbefuellung wuerde jeden Save als manuell werten → Auto-Update bei Rename
		//    greift dann nicht mehr (T8-Bugfix).
		//  - Placeholder zeigt den aktuellen Slug (zur Info).
		$cur_slug         = (string) $post->post_name;
		$cur_permalink    = ( 'auto-draft' === $post->post_status || 'new' === $post->post_status ) ? '' : (string) get_permalink( $post->ID );
		$slug_is_manual   = 1 === (int) get_post_meta( $post->ID, '_m24_url_slug_manual', true );
		$slug_placeholder = '' !== $cur_slug ? $cur_slug : 'wird-aus-titel-generiert';
		$artnr   = get_post_meta( $post->ID, '_m24_artikelnummer', true );
		$ph_art  = ( '' === $artnr && class_exists( 'M24_Catalog_Artnr' ) ) ? M24_Catalog_Artnr::peek_next() . ' (automatisch)' : '';
		$desc_de = get_post_meta( $post->ID, '_m24_beschreibung_de', true );

		// Preisoptionen + Eingabemodus
		$opts             = M24_Catalog_Pricing::raw_options( $post->ID );
		$preis_mode       = get_post_meta( $post->ID, '_m24_preis_eingabe', true ) ?: 'brutto';
		$preis_mode       = in_array( $preis_mode, array( 'brutto', 'netto' ), true ) ? $preis_mode : 'brutto';
		$tax_rate         = self::tax_rate();
		$preis_auf_anfrage = (bool) get_post_meta( $post->ID, '_m24_preis_auf_anfrage', true );

		// Wenn noch keine Optionen vorhanden (z.B. ganz neuer Post): eine leere Default-Option.
		if ( empty( $opts ) ) {
			$opts = array( array( 'label' => '', 'art_nr' => '', 'netto' => null, 'brutto' => 0 ) );
		}
		?>
		<style>
			.m24f{display:grid;grid-template-columns:200px 1fr;gap:11px 16px;align-items:center;margin-top:6px}
			.m24f label{font-weight:600}
			.m24f .full{grid-column:1/3}
			.m24f input[type=text],.m24f input[type=number],.m24f select,.m24f textarea{width:100%}
			.m24f textarea{font-family:inherit;font-size:14px;padding:8px}
			.m24f .hint{color:#666;font-weight:400;font-size:11px}
			.m24po{border:1px solid #d6d8dd;border-radius:6px;padding:10px 12px;background:#fafbfc;margin-bottom:10px}
			.m24po-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
			.m24po-head .m24po-num{font-weight:600;font-size:13px;color:#1a1d23}
			.m24po-head .m24po-del{background:none;border:none;color:#b32d2e;cursor:pointer;font-size:18px;line-height:1;padding:0 4px}
			.m24po-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 12px}
			.m24po-grid .m24po-full{grid-column:1/3}
			.m24po-grid label.m24po-l{font-size:11px;color:#555;font-weight:500;display:block;margin-bottom:3px}
			.m24po-grid input{width:100%}
			.m24po-toggle{display:inline-flex;gap:0;border:1px solid #ccd0d4;border-radius:5px;overflow:hidden;font-size:12px;margin-bottom:4px}
			.m24po-toggle button{background:#fff;border:none;padding:5px 10px;cursor:pointer;color:#555;font-size:12px}
			.m24po-toggle button.act{background:#1763ad;color:#fff;font-weight:600}
			.m24po-shadow{font-size:11px;color:#666;margin-top:2px;font-family:monospace}
			.m24po-add{background:#fff;border:1px dashed #1763ad;color:#1763ad;padding:8px 14px;border-radius:6px;cursor:pointer;font-weight:600;font-size:12.5px}
			.m24po-add:hover{background:#e8f1fb}
			/* Veröffentlichen-Box robust gegen Float-/Overlap-Probleme (WP 7.0 + Cache-Plugin-
			   Buttons wie „Cache leeren"). Nur auf dieser Editor-Seite aktiv (Style liegt in der
			   Teile-Metabox). Stellt den Standard-Float wieder her. */
			#submitdiv #major-publishing-actions{display:flow-root;align-items:center}
			#submitdiv #delete-action{float:left;line-height:28px}
			#submitdiv #publishing-action{float:right;text-align:right}
			#submitdiv .misc-pub-section{overflow:hidden}
		</style>
		<div class="m24f">
			<label>Typ</label>
			<select name="m24_typ">
				<option value="gebraucht" <?php selected( $typ, 'gebraucht' ); ?>>Gebrauchtteil</option>
				<option value="neu" <?php selected( $typ, 'neu' ); ?>>Neuteil (Rennsport)</option>
			</select>

			<label>Artikelnummer (Parent)</label>
			<input type="text" name="m24_artikelnummer" value="<?php echo esc_attr( $artnr ); ?>" placeholder="<?php echo esc_attr( $ph_art ); ?>">

			<label>BMW-Teilenummer <span class="hint">(optional — nur sichtbar wenn befüllt)</span></label>
			<input type="text" name="m24_bmw_teilenummer" value="<?php echo $g( '_m24_bmw_teilenummer' ); ?>">

			<label>Hinweis <span class="hint">(optional, z. B. „Stand 2011")</span></label>
			<input type="text" name="m24_hinweis" value="<?php echo $g( '_m24_hinweis' ); ?>">

			<label>Preis</label>
			<div>
				<div id="m24po-list" data-mode="<?php echo esc_attr( $preis_mode ); ?>" data-rate="<?php echo esc_attr( (string) $tax_rate ); ?>">
					<?php self::render_option_row( 0, $opts[0], $preis_mode, $tax_rate ); // Einzelpreis (Brutto/Netto-Switch + Live-Netto) ?>
				</div>
				<label style="font-weight:400;cursor:pointer;display:inline-flex;align-items:center;gap:6px;margin-top:2px">
					<input type="checkbox" name="m24_preis_auf_anfrage" value="1" <?php checked( $preis_auf_anfrage, true ); ?>>
					<span>Preis auf Anfrage <span class="hint">(Frontend zeigt „Preis auf Anfrage" statt Betrag; Schema ohne Preis)</span></span>
				</label>
				<input type="hidden" name="m24_preis_eingabe" id="m24po-mode-hidden" value="<?php echo esc_attr( $preis_mode ); ?>">
			</div>

			<label>MwSt-Modus</label>
			<select name="m24_mwst_modus">
				<option value="regel" <?php selected( $modus, 'regel' ); ?>>Regelbesteuerung (19 %, netto + MwSt)</option>
				<option value="paragraf25a" <?php selected( $modus, 'paragraf25a' ); ?>>§25a Differenzbesteuerung</option>
			</select>

			<label>Status</label>
			<select name="m24_status">
				<option value="aktiv" <?php selected( $status, 'aktiv' ); ?>>Aktiv (sichtbar)</option>
				<option value="ausgeblendet" <?php selected( $status, 'ausgeblendet' ); ?>>Ausgeblendet</option>
				<option value="verkauft" <?php selected( $status, 'verkauft' ); ?>>Verkauft</option>
			</select>

			<label>Hauptrubrik (Breadcrumb &amp; SEO)</label>
			<?php
			$primary_sel = (int) get_post_meta( $post->ID, '_m24_primary_modell', true );
			$assigned    = wp_get_post_terms( $post->ID, M24_Catalog_CPT::TAXONOMY );
			if ( is_wp_error( $assigned ) ) { $assigned = array(); }
			?>
			<select name="m24_primary_modell" id="m24_primary_modell">
				<option value="0" <?php selected( $primary_sel, 0 ); ?>>— automatisch (erster Term) —</option>
				<?php foreach ( $assigned as $t ) : ?>
					<option value="<?php echo (int) $t->term_id; ?>" <?php selected( $primary_sel, (int) $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<script>
			/* Beim (Ent-)Haken der Modell-Terms die Hauptrubrik-Optionen live aus den angehakten Labels neu
			   aufbauen (aktuelle Auswahl erhalten) → kein Doppel-Speicher-Zyklus nötig. */
			(function () {
				function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
				function rebuild() {
					var sel = document.getElementById('m24_primary_modell'); if (!sel) { return; }
					var cur = sel.value, seen = {}, html = '<option value="0">— automatisch (erster Term) —</option>', found = false;
					var boxes = document.querySelectorAll('#m24_fahrzeugkatdiv input[type=checkbox]:checked');
					[].forEach.call(boxes, function (cb) {
						var id = String(cb.value || '').trim(); if (!id || '0' === id || seen[id]) { return; } seen[id] = 1;
						var lab = cb.closest('label'); var name = lab ? lab.textContent.trim() : id;
						if (id === cur) { found = true; }
						html += '<option value="' + esc(id) + '">' + esc(name) + '</option>';
					});
					sel.innerHTML = html; sel.value = found ? cur : '0';
				}
				document.addEventListener('change', function (e) { var t = e.target; if (t && t.matches && t.matches('#m24_fahrzeugkatdiv input[type=checkbox]')) { rebuild(); } });
			})();
			</script>

			<label>Logo anzeigen</label>
			<label style="font-weight:400;cursor:pointer">
				<input type="checkbox" name="m24_logo_anzeigen" value="1" <?php checked( $logo_anzeigen, true ); ?>>
				<span>MOTORSPORT24-Logo bei Neuteilen oben rechts im Detail-Header (Gebrauchtteile: kein BMW-Logo mehr — siehe „Original BMW-Teil")</span>
			</label>

			<label>Original BMW-Teil</label>
			<label style="font-weight:400;cursor:pointer">
				<input type="checkbox" name="m24_original_teil" value="1" <?php checked( $original_teil, true ); ?>>
				<span>Badge „Original BMW-Teil" im Detail-Header anzeigen — <strong style="color:#c8102e">nur bei echten Original-BMW-Teilen</strong> (Markenrecht). NICHT bei Nachbau/Zubehör/Nicht-BMW.</span>
			</label>

			<label>Nachbau-Hinweis</label>
			<label style="font-weight:400;cursor:pointer">
				<input type="checkbox" name="m24_show_nachbau_hinweis" value="1" <?php checked( $show_nachbau, true ); ?>>
				<span>„Nachbau / kein BMW-Originalteil"-Hinweis in der Preisbox anzeigen — nur bei Nachbau/Replika-Teilen (Markenrecht, gegen Herkunftstäuschung). Unabhängig vom Original-BMW-Badge.</span>
			</label>

			<label>Leichtbauteil</label>
			<label style="font-weight:400;cursor:pointer">
				<input type="checkbox" name="m24_leichtbau" value="1" <?php checked( $leichtbau, true ); ?>>
				<span>Reiter „Herstellungshinweise" im Detail-Tabbar aktivieren</span>
			</label>

			<label>Rennsport-Hinweis</label>
			<label style="font-weight:400;cursor:pointer">
				<input type="checkbox" name="m24_rennsport_hinweis" value="1" <?php checked( $rennsport_hinweis, true ); ?>>
				<span>„Verkauf rein für den Rennsport…" im Detail-Header anzeigen (sonst Standard-Logik: nur bei Neuteil)</span>
			</label>

			<label>URL-Slug <span class="hint">(leer lassen = Auto aus Titel · <?php echo $slug_is_manual ? '<strong style="color:#c8102e">aktuell manuell</strong>' : '<strong style="color:#2f7d52">aktuell automatisch</strong>'; ?>)</span></label>
			<div>
				<input type="text" name="m24_url_slug" value="" placeholder="<?php echo esc_attr( $slug_placeholder ); ?>">
				<?php if ( '' !== $cur_permalink ) : ?>
					<p class="hint" style="margin:4px 0 0;word-break:break-all">Permalink: <a href="<?php echo esc_url( $cur_permalink ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $cur_permalink ); ?></a></p>
				<?php endif; ?>
			</div>

			<p class="full" style="margin:14px 0 4px;font-weight:600">Beschreibung (DE)</p>
			<textarea class="full" name="m24_beschreibung_de" rows="7"><?php echo esc_textarea( $desc_de ); ?></textarea>
			<p class="full hint">Reiner Text; einfache HTML-Tags (z. B. &lt;ul&gt;&lt;li&gt;, &lt;b&gt;) sind erlaubt. Absätze entstehen automatisch.</p>

			<details class="full m24po-more" style="margin:10px 0 6px">
				<summary style="cursor:pointer;font-weight:600;color:#2271b1;margin:6px 0">Weitere Preis-Varianten<?php if ( count( $opts ) > 1 ) : ?> (<?php echo (int) ( count( $opts ) - 1 ); ?>)<?php endif; ?></summary>
				<div id="m24po-list-more" data-mode="<?php echo esc_attr( $preis_mode ); ?>" data-rate="<?php echo esc_attr( (string) $tax_rate ); ?>">
					<?php for ( $i = 1, $n = count( $opts ); $i < $n; $i++ ) : self::render_option_row( $i, $opts[ $i ], $preis_mode, $tax_rate ); endfor; ?>
				</div>
				<div style="margin-top:6px"><button type="button" class="m24po-add" id="m24po-add">+ Option hinzufügen</button></div>
			</details>

			<p class="full hint">Titel (DE) oben im Titelfeld. „Beitragsbild" (rechts) = Titelbild. Mehrere Bilder in der Box „Bilder &amp; Galerie". Fahrzeug-Zuordnung über „Passend für".</p>
		</div>

		<template id="m24po-tpl"><?php self::render_option_row( '__I__', array( 'label' => '', 'art_nr' => '', 'netto' => null, 'brutto' => 0 ), $preis_mode, $tax_rate ); ?></template>

		<script>
		(function(){
			var list = document.getElementById('m24po-list');
			if(!list) return;
			var more = document.getElementById('m24po-list-more');
			var rate = parseFloat(list.dataset.rate) || 0.19;
			var modeHidden = document.getElementById('m24po-mode-hidden');
			// Alle Options-Zeilen über beide Container (Option 1 + „Weitere Varianten") in DOM-Reihenfolge.
			function allRows(){ return Array.prototype.slice.call(document.querySelectorAll('#m24po-list .m24po, #m24po-list-more .m24po')); }

			function fmt(n){ if(isNaN(n)) return ''; return n.toFixed(2); }

			function recalcRow(row){
				var mode = row.dataset.mode || 'brutto';
				var inp  = row.querySelector('[data-po="price"]');
				var bH   = row.querySelector('[data-po="brutto"]');
				var nH   = row.querySelector('[data-po="netto"]');
				var sh   = row.querySelector('[data-po="shadow"]');
				var v    = parseFloat((inp.value||'').replace(',','.'));
				if(isNaN(v)){ bH.value=''; nH.value=''; sh.textContent=''; return; }
				var brutto, netto;
				if(mode === 'brutto'){ brutto = v; netto = Math.round((v/(1+rate))*100)/100; }
				else                  { netto  = v; brutto = Math.round((v*(1+rate))*100)/100; }
				bH.value = fmt(brutto);
				nH.value = fmt(netto);
				sh.textContent = (mode === 'brutto')
					? '= ' + netto.toFixed(2).replace('.',',') + ' € netto'
					: '= ' + brutto.toFixed(2).replace('.',',') + ' € brutto';
			}

			function setRowMode(row, newMode){
				var inp  = row.querySelector('[data-po="price"]');
				var bH   = row.querySelector('[data-po="brutto"]');
				var nH   = row.querySelector('[data-po="netto"]');
				row.dataset.mode = newMode;
				row.querySelectorAll('.m24po-toggle button').forEach(function(b){
					b.classList.toggle('act', b.dataset.mode === newMode);
				});
				// Bei Mode-Wechsel: das Feld auf den jeweils gespeicherten Wert setzen.
				if(newMode === 'brutto' && bH.value !== ''){ inp.value = bH.value.replace('.',','); }
				if(newMode === 'netto'  && nH.value !== ''){ inp.value = nH.value.replace('.',','); }
				recalcRow(row);
				// Globaler Eingabemodus = letzter gewaehlter (Convention)
				modeHidden.value = newMode;
			}

			function reindex(){
				var rows = allRows();
				rows.forEach(function(r, idx){
					r.querySelector('.m24po-num').textContent = 'Option ' + (idx+1);
					r.querySelectorAll('[name^="m24_preisopt["]').forEach(function(inp){
						inp.name = inp.name.replace(/m24_preisopt\[(?:\d+|__I__)\]/, 'm24_preisopt[' + idx + ']');
					});
				});
			}

			function wireRow(row){
				row.querySelector('[data-po="price"]').addEventListener('input', function(){ recalcRow(row); });
				row.querySelectorAll('.m24po-toggle button').forEach(function(b){
					b.addEventListener('click', function(e){ e.preventDefault(); setRowMode(row, b.dataset.mode); });
				});
				row.querySelector('.m24po-del').addEventListener('click', function(){
					if(allRows().length <= 1){
						// letzte Option leeren statt entfernen
						row.querySelectorAll('input[type=text],input[type=number]').forEach(function(i){ i.value=''; });
						recalcRow(row);
						return;
					}
					row.remove(); reindex();
				});
				recalcRow(row);
			}

			allRows().forEach(wireRow);

			document.getElementById('m24po-add').addEventListener('click', function(){
				var tpl = document.getElementById('m24po-tpl');
				var html = tpl.innerHTML;
				var wrap = document.createElement('div'); wrap.innerHTML = html.trim();
				var row  = wrap.firstChild;
				( more || list ).appendChild(row); // neue Varianten in die „Weitere"-Sektion
				var det = more && more.closest('details'); if(det){ det.open = true; }
				reindex();
				wireRow(row);
			});
		})();
		</script>
		<?php
	}

	/** Eine Repeater-Zeile rendern. $i kann '__I__' (Template) oder int sein. */
	private static function render_option_row( $i, $opt, $mode, $rate ) {
		// In welchem Modus wird das sichtbare Feld initial angezeigt?
		$row_mode  = ( 'netto' === $mode ) ? 'netto' : 'brutto';
		$brutto    = isset( $opt['brutto'] ) && '' !== $opt['brutto'] && null !== $opt['brutto'] ? (float) $opt['brutto'] : 0.0;
		$netto     = isset( $opt['netto'] )  && '' !== $opt['netto']  && null !== $opt['netto']  ? (float) $opt['netto']  : null;
		$visible_v = ( 'netto' === $row_mode ) ? $netto : $brutto;
		$visible_s = ( null === $visible_v ) ? '' : str_replace( '.', ',', (string) $visible_v );
		$shadow_v  = ( 'netto' === $row_mode )
			? ( $brutto > 0 ? '= ' . number_format( $brutto, 2, ',', '.' ) . ' € brutto' : '' )
			: ( null !== $netto ? '= ' . number_format( $netto, 2, ',', '.' ) . ' € netto' : '' );
		$label  = isset( $opt['label'] )  ? (string) $opt['label']  : '';
		$art_nr = isset( $opt['art_nr'] ) ? (string) $opt['art_nr'] : '';
		?>
		<div class="m24po" data-mode="<?php echo esc_attr( $row_mode ); ?>">
			<div class="m24po-head">
				<span class="m24po-num">Option <?php echo is_numeric( $i ) ? ( (int) $i + 1 ) : '#'; ?></span>
				<button type="button" class="m24po-del" title="Option entfernen">×</button>
			</div>
			<div class="m24po-grid">
				<div class="m24po-full">
					<label class="m24po-l">Label <span style="font-weight:400;color:#888">(z. B. „Bodykit 8-teilig"; bei nur 1 Option leer lassen)</span></label>
					<input type="text" name="m24_preisopt[<?php echo esc_attr( (string) $i ); ?>][label]" value="<?php echo esc_attr( $label ); ?>">
				</div>
				<div>
					<label class="m24po-l">Artikelnummer</label>
					<input type="text" name="m24_preisopt[<?php echo esc_attr( (string) $i ); ?>][art_nr]" value="<?php echo esc_attr( $art_nr ); ?>">
				</div>
				<div>
					<label class="m24po-l">Eingabe</label>
					<span class="m24po-toggle">
						<button type="button" data-mode="brutto" class="<?php echo 'brutto' === $row_mode ? 'act' : ''; ?>">Brutto</button>
						<button type="button" data-mode="netto"  class="<?php echo 'netto'  === $row_mode ? 'act' : ''; ?>">Netto</button>
					</span>
				</div>
				<div>
					<label class="m24po-l">Preis</label>
					<input type="text" data-po="price" value="<?php echo esc_attr( $visible_s ); ?>" placeholder="0,00">
				</div>
				<div>
					<label class="m24po-l">&nbsp;</label>
					<div class="m24po-shadow" data-po="shadow"><?php echo esc_html( $shadow_v ); ?></div>
				</div>
				<input type="hidden" name="m24_preisopt[<?php echo esc_attr( (string) $i ); ?>][brutto]" data-po="brutto" value="<?php echo esc_attr( null !== $opt['brutto'] ? (string) $opt['brutto'] : '' ); ?>">
				<input type="hidden" name="m24_preisopt[<?php echo esc_attr( (string) $i ); ?>][netto]"  data-po="netto"  value="<?php echo esc_attr( null !== $opt['netto']  ? (string) $opt['netto']  : '' ); ?>">
			</div>
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE ] ), 'm24_save_' . $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text_fields = array(
			'_m24_artikelnummer'   => 'm24_artikelnummer',
			'_m24_bmw_teilenummer' => 'm24_bmw_teilenummer',
			'_m24_hinweis'         => 'm24_hinweis',
		);
		foreach ( $text_fields as $meta => $field ) {
			$val = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			update_post_meta( $post_id, $meta, $val );
		}

		$typ    = ( isset( $_POST['m24_typ'] ) && in_array( $_POST['m24_typ'], array( 'neu', 'gebraucht' ), true ) ) ? $_POST['m24_typ'] : 'gebraucht';
		$modus  = ( isset( $_POST['m24_mwst_modus'] ) && in_array( $_POST['m24_mwst_modus'], array( 'regel', 'paragraf25a' ), true ) ) ? $_POST['m24_mwst_modus'] : 'regel';
		$status = ( isset( $_POST['m24_status'] ) && in_array( $_POST['m24_status'], array( 'aktiv', 'ausgeblendet', 'verkauft' ), true ) ) ? $_POST['m24_status'] : 'aktiv';

		update_post_meta( $post_id, '_m24_typ', $typ );
		update_post_meta( $post_id, '_m24_mwst_modus', $modus );
		update_post_meta( $post_id, '_m24_status', $status );

		// Hauptrubrik (Breadcrumb/SEO): nur speichern, wenn der Term dem Teil tatsächlich zugewiesen ist, sonst
		// löschen → Auto-Fallback auf den ersten Term. Terms sind hier (save_post, Prio 10) bereits gesetzt
		// (tax_input wird in wp_insert_post VOR save_post verarbeitet).
		$primary = isset( $_POST['m24_primary_modell'] ) ? (int) $_POST['m24_primary_modell'] : 0;
		$assigned_ids = array();
		if ( $primary > 0 ) {
			$pterms = wp_get_post_terms( $post_id, M24_Catalog_CPT::TAXONOMY, array( 'fields' => 'ids' ) );
			$assigned_ids = is_wp_error( $pterms ) ? array() : array_map( 'intval', (array) $pterms );
		}
		if ( $primary > 0 && in_array( $primary, $assigned_ids, true ) ) {
			update_post_meta( $post_id, '_m24_primary_modell', $primary );
		} else {
			delete_post_meta( $post_id, '_m24_primary_modell' );
		}
		update_post_meta( $post_id, '_m24_logo_anzeigen', isset( $_POST['m24_logo_anzeigen'] ) ? 1 : 0 );
		// Markenrecht: „Original BMW-Teil"-Badge nur bei explizit markierten Originalteilen ('1').
		update_post_meta( $post_id, '_m24_original_teil', isset( $_POST['m24_original_teil'] ) ? '1' : '0' );
		update_post_meta( $post_id, '_m24_leichtbau',         isset( $_POST['m24_leichtbau'] )         ? 1 : 0 );
		update_post_meta( $post_id, '_m24_rennsport_hinweis', isset( $_POST['m24_rennsport_hinweis'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_m24_show_nachbau_hinweis', isset( $_POST['m24_show_nachbau_hinweis'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_m24_preis_auf_anfrage', isset( $_POST['m24_preis_auf_anfrage'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_m24_beschreibung_de', wp_kses_post( wp_unslash( isset( $_POST['m24_beschreibung_de'] ) ? $_POST['m24_beschreibung_de'] : '' ) ) );

		// Preis-Eingabe-Modus persistieren (fuer das naechste Oeffnen).
		$preis_mode = isset( $_POST['m24_preis_eingabe'] ) ? wp_unslash( $_POST['m24_preis_eingabe'] ) : 'brutto';
		$preis_mode = in_array( $preis_mode, array( 'brutto', 'netto' ), true ) ? $preis_mode : 'brutto';
		update_post_meta( $post_id, '_m24_preis_eingabe', $preis_mode );

		// Preisoptionen-Array zusammenbauen.
		$raw_opts = isset( $_POST['m24_preisopt'] ) && is_array( $_POST['m24_preisopt'] ) ? wp_unslash( $_POST['m24_preisopt'] ) : array();
		$options  = array();
		foreach ( $raw_opts as $r ) {
			if ( ! is_array( $r ) ) { continue; }
			$label  = isset( $r['label'] )  ? M24_Catalog_Pricing::clean_label( sanitize_text_field( (string) $r['label'] ) )  : '';
			$art_nr = isset( $r['art_nr'] ) ? sanitize_text_field( (string) $r['art_nr'] ) : '';
			$brutto = isset( $r['brutto'] ) && '' !== $r['brutto'] ? (float) str_replace( ',', '.', (string) $r['brutto'] ) : 0.0;
			$netto_raw = isset( $r['netto'] ) && '' !== $r['netto'] ? (float) str_replace( ',', '.', (string) $r['netto'] ) : null;
			// §25a: kein Netto ausweisbar
			if ( 'paragraf25a' === $modus ) {
				$netto_raw = null;
			}
			// Leere Zeilen ohne Preis und ohne Label ueberspringen.
			if ( $brutto <= 0 && '' === $label && '' === $art_nr ) {
				continue;
			}
			$options[] = array(
				'label'  => $label,
				'art_nr' => $art_nr,
				'netto'  => $netto_raw,
				'brutto' => round( $brutto, 2 ),
			);
		}
		update_post_meta( $post_id, '_m24_preisoptionen', wp_json_encode( $options, JSON_UNESCAPED_UNICODE ) );

		// Single-Preis-Feld (legacy) synchron halten: erster Option-Preis als netto.
		// Bei §25a ist das die brutto-Basis (wie alte Pricing-Konvention).
		// WICHTIG: sanitize_price NICHT auf Floats anwenden — strippt Punkt als
		// Tausender-Trenner und multipliziert den Wert effektiv ×100 (Bug-Quelle
		// der Archive-Fehlanzeige 175.000,21 € statt 1.750,00 €).
		if ( ! empty( $options ) ) {
			$first = $options[0];
			$legacy_basis = ( 'paragraf25a' === $modus ) ? $first['brutto'] : ( $first['netto'] ?? 0 );
			update_post_meta( $post_id, '_m24_preis_netto', (float) $legacy_basis );
		} else {
			update_post_meta( $post_id, '_m24_preis_netto', 0 );
		}

		// N2/T8: URL-Slug — 3-Wege-Logik (T8-Bugfix):
		//  (a) POST-Feld LEER                          → Auto-Mode, manual_flag=0.
		//  (b) POST-Feld nicht-leer + == Auto-Slug aus Titel  → User bestaetigt Auto, manual_flag=0.
		//  (c) POST-Feld nicht-leer + abweichend       → Manueller Slug, manual_flag=1.
		//  (d) Kein POST-Feld                          → unangetastet (Inline-Rename, prog. Update).
		// Auto-Regenerate bei reinem Title-Change macht der save_post-Hook bei prio 25.
		if ( isset( $_POST['m24_url_slug'] ) ) {
			$new_slug  = sanitize_title( wp_unslash( $_POST['m24_url_slug'] ) );
			$cur_slug  = (string) $post->post_name;
			$auto_slug = sanitize_title( $post->post_title );

			if ( '' === $new_slug ) {
				// (a) Auto-Mode
				delete_post_meta( $post_id, '_m24_url_slug_manual' );
				// Slug-Regenerate erledigt auto_slug_from_title() (prio 25).
			} elseif ( $new_slug === $auto_slug ) {
				// (b) User hat denselben Auto-Slug getippt — kein manual-Flag.
				delete_post_meta( $post_id, '_m24_url_slug_manual' );
			} else {
				// (c) Manueller Slug — explizit gesetzt + flag.
				update_post_meta( $post_id, '_m24_url_slug_manual', 1 );
				if ( $new_slug !== $cur_slug ) {
					$unique = wp_unique_post_slug( $new_slug, $post_id, $post->post_status, $post->post_type, (int) $post->post_parent );
					if ( $unique !== $cur_slug ) {
						remove_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'save' ), 10 );
						wp_update_post( array( 'ID' => $post_id, 'post_name' => $unique ) );
						add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
					}
				}
			}
		}
	}

	/**
	 * T22b: Entfernt Leerzeichen aus BMW-Teilenummer-Patterns im Titel — die OEM-Nummer
	 * landet als zusammenhaengende Ziffernfolge im Titel + Slug (Google-Findbarkeit).
	 * Idempotent. Wird VOR auto_slug_from_title ausgefuehrt damit der neue Slug aus dem
	 * kompakten Titel generiert wird.
	 */
	public static function compact_bmw_in_title( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
		if ( get_post_type( $post_id ) !== M24_Catalog_CPT::POST_TYPE ) { return; }
		if ( 'auto-draft' === $post->post_status ) { return; }
		if ( ! class_exists( 'M24_BMW_Teilenummer_Extractor' ) ) { return; }

		$old = (string) $post->post_title;
		$new = M24_BMW_Teilenummer_Extractor::compact_in_title( $old );
		if ( $new === $old ) { return; }

		remove_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'compact_bmw_in_title' ), 15 );
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $new ) );
		add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'compact_bmw_in_title' ), 15, 2 );
	}

	/**
	 * T8: Auto-Slug bei Title-Change.
	 * Greift bei JEDEM save_post-Call (inkl. Inline-Rename via Adminliste-AJAX, programmatische
	 * Updates), nicht nur bei Metabox-Submit. Skip wenn manual_flag gesetzt — handgetunte Slugs
	 * bleiben unangetastet. WP schreibt _wp_old_slug automatisch beim post_name-Change und
	 * wp_old_slug_redirect() macht den 301 im Frontend.
	 */
	public static function auto_slug_from_title( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
		if ( get_post_type( $post_id ) !== M24_Catalog_CPT::POST_TYPE ) { return; }
		if ( 'auto-draft' === $post->post_status ) { return; }
		if ( 1 === (int) get_post_meta( $post_id, '_m24_url_slug_manual', true ) ) { return; }

		$title = trim( (string) $post->post_title );
		if ( '' === $title ) { return; }
		foreach ( array( 'Auto Draft', 'Automatisch gespeicherter Entwurf', '(no title)', '(kein Titel)' ) as $ph ) {
			if ( 0 === strcasecmp( $title, $ph ) ) { return; }
		}

		$auto = sanitize_title( $title );
		if ( '' === $auto || $auto === $post->post_name ) { return; }

		$unique = wp_unique_post_slug( $auto, $post_id, $post->post_status, $post->post_type, (int) $post->post_parent );
		if ( $unique === $post->post_name ) { return; }

		// Recursion-Schutz: Hook abhaengen, update, wieder einhaengen.
		remove_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'auto_slug_from_title' ), 25 );
		wp_update_post( array( 'ID' => $post_id, 'post_name' => $unique ) );
		add_action( 'save_post_' . M24_Catalog_CPT::POST_TYPE, array( __CLASS__, 'auto_slug_from_title' ), 25, 2 );
	}
}

// ── Cleanup-CLI: setzt _m24_url_slug_manual auf 0 fuer alle Bestands-m24_teil-Posts ──
// T8-Bugfix: bisheriges UI hat das Slug-Feld mit aktuellem post_name vorbefuellt →
// bei jedem Save wurde manual=1 gesetzt → Auto-Update bei Rename greift nicht. Da bisher
// keiner bewusst manuelle Slugs gesetzt hat, ist pauschaler Reset sicher.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'm24 reset-slug-manual', function( $args, $assoc ) {
		$dry      = ! empty( $assoc['dry-run'] );
		$auto_yes = ! empty( $assoc['yes'] );

		$ids = get_posts( array(
			'post_type'      => 'm24_teil',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		if ( empty( $ids ) ) { WP_CLI::log( 'Keine m24_teil-Posts gefunden.' ); return; }

		$with_flag = 0;
		foreach ( $ids as $pid ) {
			if ( 1 === (int) get_post_meta( $pid, '_m24_url_slug_manual', true ) ) { $with_flag++; }
		}
		WP_CLI::log( sprintf( 'Posts gesamt:                      %d', count( $ids ) ) );
		WP_CLI::log( sprintf( '  mit _m24_url_slug_manual=1:     %d', $with_flag ) );
		WP_CLI::log( '' );

		if ( $dry ) { WP_CLI::warning( 'Dry-run: nichts geaendert.' ); return; }
		if ( ! $auto_yes ) {
			WP_CLI::confirm( sprintf(
				'_m24_url_slug_manual auf %d Posts wirklich loeschen? Renames regenerieren danach den Slug + 301.',
				count( $ids )
			) );
		}
		$cleared = 0;
		foreach ( $ids as $pid ) {
			delete_post_meta( $pid, '_m24_url_slug_manual' );
			$cleared++;
		}
		WP_CLI::success( sprintf( 'Manual-Flag auf %d Posts geleert. Auto-Slug greift ab dem naechsten Save.', $cleared ) );
	} );
}
