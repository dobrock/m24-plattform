/**
 * M24 Angebots-Tool v2 — Operator (nur Admin). Config: window.M24Offers.
 * Layout/Bedienung nach Mockup angebots-tool-v2.html; Logik (Steuer-Modi, Summen, REST-Send, Teile-Picker,
 * Prefill) aus der v1 wiederverwendet. Positionen als Karten mit Stepper; Nebenkosten + Freitext + Katalog
 * als Chips (Beträge inline editierbar); Summen-Karte rechts (Desktop sticky) + mobile Sticky-Bar.
 */
(function () {
	'use strict';
	var cfg = window.M24Offers || {};
	if (!cfg.rest) { return; }
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var $$ = function (s, r) { return [].slice.call((r || document).querySelectorAll(s)); };
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	function eur(v) { return (Math.round(v * 100) / 100).toFixed(2).replace('.', ',') + ' €'; }
	// Deutsches Dezimalkomma robust parsen: „77,50" · „1.234,56" (Tausenderpunkt) · „77.50" · „77" → Number.
	// Ungültige Eingabe → NaN (Aufrufer behält den letzten gültigen Wert statt auf 0 zu fallen).
	function parseNum(s) { s = String(s == null ? '' : s).trim(); if (!s) { return NaN; } if (s.indexOf(',') > -1) { s = s.replace(/\./g, '').replace(',', '.'); } var n = parseFloat(s); return isNaN(n) ? NaN : n; }
	function numIn(n) { return (n == null || isNaN(n)) ? '' : String(n).replace('.', ','); } // Anzeige im Feld mit Komma

	/* ── State ── */
	var items  = [];  // {teil_id,title,art_nr,qty,unit_price,tax25a,custom,free,variant,thumb}
	var extras = (cfg.presets || []).map(function (p) { return { key: p.key || '', label: p.label, amount: parseFloat(p.amount) || 0, on: false }; });
	var taxMode = '', taxRate = 0, offerLang = 'de';
	var modell = (cfg.src && cfg.src.src_modell) || '';
	var customer = cfg.customer || { name: '', email: '', kundentyp: 'b2c', land: '' };
	var LANDS = cfg.lands || {};
	// GB → „England" (Daniels kanonisches Label; die Länderliste liefert sonst „Großbritannien"). Gilt DE + EN.
	function landName(iso) { iso = (iso || '').toUpperCase(); if ('GB' === iso) { return 'England'; } return LANDS[iso] || iso || ''; }

	/* ── EN-Wörterbuch für Standard-Positionen (KEINE Maschinenübersetzung von Katalogtiteln) ── */
	var STD_DE = { verpackung: 'Transportsicher verpacken', versand_air: 'Versicherter Versand DAP {L} Luftfracht', versand_sea: 'Versicherter Versand DAP {L} Seefracht', zoll: 'Zollabwicklung Deutschland' };
	var STD_EN = { verpackung: 'Secure transport packaging', versand_air: 'Insured shipping DAP {C} air freight', versand_sea: 'Insured shipping DAP {C} sea freight', zoll: 'Customs handling Germany' };
	var LANDS_EN = cfg.landsEn || {};
	function landNameEn(iso) { iso = (iso || '').toUpperCase(); if ('GB' === iso) { return 'England'; } return LANDS_EN[iso] || landName(iso); }
	function chipLabel(ex) {
		var base = ('en' === offerLang) ? (STD_EN[ex.key] || ex.label) : (STD_DE[ex.key] || ex.label);
		base = base.replace('{L}', landName(customer.land) || '').replace('{C}', landNameEn(customer.land) || ''); // {Empfängerland} live
		return base.replace(/\s{2,}/g, ' ').trim();
	}
	/** Anzeige-Titel je Sprache: Katalog = title_en falls vorhanden, sonst DE; Freitext = title_en/title_de. */
	function itemTitle(it) {
		if ('en' === offerLang) { return (it.title_en && String(it.title_en).trim()) ? it.title_en : (it.free ? (it.title_de != null ? it.title_de : (it.title || '')) : (it.title || '')); }
		return it.free ? (it.title_de != null ? it.title_de : (it.title || '')) : (it.title || '');
	}

	/* ── Positionen (Karten + Stepper + Drag-Handle + EN) ── */
	function renderItems() {
		var box = $('[data-items]'); if (!box) { return; }
		box.innerHTML = '';
		if (!items.length) { box.innerHTML = '<p class="m24off-empty2">Noch keine Positionen — über die Chips unten hinzufügen.</p>'; }
		items.forEach(function (it, i) {
			var row = document.createElement('div');
			row.className = 'm24off-pos'; row.setAttribute('data-i', i);
			var titleHtml;
			if (it.free) {
				titleHtml = '<input type="text" class="m24off-pt-in" value="' + esc(it.title_de != null ? it.title_de : (it.title || '')) + '" data-i="' + i + '" data-title placeholder="Bezeichnung (DE)">';
				if ('en' === offerLang) { titleHtml += '<input type="text" class="m24off-pt-in m24off-pt-en" value="' + esc(it.title_en || '') + '" data-i="' + i + '" data-title-en placeholder="Bezeichnung (EN) — selbst übersetzen">'; }
			} else {
				titleHtml = '<div class="m24off-pt">' + esc(itemTitle(it)) + '</div>';
				if ('en' === offerLang && !(it.title_en && String(it.title_en).trim())) { titleHtml += '<div class="m24off-pa m24off-enmiss">EN-Titel fehlt</div>'; }
			}
			var metaHtml = it.art_nr ? '<div class="m24off-pa">Art.-Nr. ' + esc(it.art_nr) + '</div>' : '';
			var varHtml = it.variant ? '<div class="m24off-pa m24off-pvar">Variante: ' + esc(it.variant) + '</div>' : '';
			row.innerHTML = '<span class="m24off-drag" data-drag title="Ziehen zum Sortieren" aria-label="Sortieren">⠿</span>'
				+ (it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="m24off-pos-ph"></span>')
				+ '<div class="m24off-pos-main">' + titleHtml + metaHtml + varHtml + '</div>'
				+ '<div class="m24off-qty2"><button type="button" data-i="' + i + '" data-qdec aria-label="weniger">−</button>'
				+ '<input type="number" min="1" value="' + it.qty + '" data-i="' + i + '" data-qty inputmode="numeric">'
				+ '<button type="button" data-i="' + i + '" data-qinc aria-label="mehr">+</button></div>'
				+ '<div class="m24off-pprice"><input type="text" inputmode="decimal" value="' + numIn(it.unit_price) + '" data-i="' + i + '" data-price autocomplete="off">'
				+ '<div class="m24off-bru2" data-brutto>= ' + eur((it.unit_price || 0) * 1.19) + ' brutto</div></div>'
				+ '<button type="button" class="m24off-posx" data-i="' + i + '" data-rm aria-label="Position entfernen">✕</button>';
			box.appendChild(row);
		});
		renderExtraRows();
		renderSummary();
	}

	/* ── Aktive Standard-Positionen als Zeilen mit editierbarem Preis (#3) ── */
	function renderExtraRows() {
		var box = $('[data-extra-rows]'); if (!box) { return; }
		box.innerHTML = '';
		extras.forEach(function (ex, i) {
			if (!ex.on) { return; }
			var row = document.createElement('div');
			row.className = 'm24off-pos m24off-pos-std';
			row.innerHTML = '<span class="m24off-drag is-static" aria-hidden="true">⠿</span>'
				+ '<span class="m24off-pos-ph m24off-ph-std">€</span>'
				+ '<div class="m24off-pos-main"><div class="m24off-pt">' + esc(chipLabel(ex)) + '</div><div class="m24off-pa">Standard-Position</div></div>'
				+ '<div class="m24off-qty2"></div>'
				+ '<div class="m24off-pprice"><input type="text" inputmode="decimal" value="' + numIn(ex.amount) + '" data-extra-price="' + i + '" autocomplete="off"><div class="m24off-bru2">netto</div></div>'
				+ '<button type="button" class="m24off-posx" data-extra-toggle="' + i + '" aria-label="Position entfernen">✕</button>';
			box.appendChild(row);
		});
	}

		/* ── Standard-/Freitext-Chips entfallen — „Hinzufügen" läuft über die angedockte Palette C3 (rechts). ── */
	function renderExtras() { renderExtraRows(); renderPalette(); }

	/* ── Drag & Drop der Positionen (vanilla, pointer-basiert → Maus + Touch) ── */
	var dragFrom = -1, dropLine = null;
	function dropLineEl() { if (!dropLine) { dropLine = document.createElement('div'); dropLine.className = 'm24off-dropline'; } return dropLine; }
	function onDragMove(e) {
		if (dragFrom < 0) { return; }
		var box = $('[data-items]'); if (!box) { return; }
		var rows = $$('.m24off-pos', box), target = rows.length, k;
		for (k = 0; k < rows.length; k++) { var r = rows[k].getBoundingClientRect(); if (e.clientY < r.top + r.height / 2) { target = k; break; } }
		var line = dropLineEl();
		if (target >= rows.length) { box.appendChild(line); } else { box.insertBefore(line, rows[target]); }
		box._dropTarget = target;
		e.preventDefault();
	}
	function onDragEnd() {
		document.removeEventListener('pointermove', onDragMove);
		document.removeEventListener('pointerup', onDragEnd);
		if (dropLine && dropLine.parentNode) { dropLine.parentNode.removeChild(dropLine); }
		var box = $('[data-items]');
		if (dragFrom >= 0 && box && typeof box._dropTarget === 'number') {
			var to = box._dropTarget; if (to > dragFrom) { to--; }
			if (to !== dragFrom && to >= 0 && to <= items.length) { var moved = items.splice(dragFrom, 1)[0]; items.splice(to, 0, moved); }
		}
		dragFrom = -1; if (box) { box._dropTarget = null; }
		renderItems();
	}
	document.addEventListener('pointerdown', function (e) {
		var h = e.target.closest ? e.target.closest('[data-drag]') : null;
		if (!h || h.classList.contains('is-static')) { return; }
		var row = h.closest('.m24off-pos'); if (!row) { return; }
		dragFrom = +row.getAttribute('data-i');
		if (isNaN(dragFrom)) { dragFrom = -1; return; }
		row.classList.add('is-dragging');
		document.addEventListener('pointermove', onDragMove);
		document.addEventListener('pointerup', onDragEnd);
		e.preventDefault();
	});

	/* ── Zoll-Auto-Vorschlag (#2): bei Drittland-Kunde ODER Steuer-Modus drittland_net den Zoll-Chip aktivieren. ── */
	function autoSuggestZoll() { for (var i = 0; i < extras.length; i++) { if ('zoll' === extras[i].key && !extras[i].on) { extras[i].on = true; return true; } } return false; }

	/* ── Steuer ── */
	function setTaxMode(mode) {
		taxMode = mode;
		var m = cfg.taxModes[mode];
		var oss = $('[data-oss]'); if (oss) { oss.hidden = !(m && m.rate === null); }
		var tn = $('[data-tax-note]'); if (tn) { tn.textContent = m ? m.note : ''; }
		var sel = $('[data-tax-mode]'); if (sel && sel.value !== mode) { sel.value = mode; } // Dropdown synchron halten (Prefill/Init)
		if ('drittland_net' === mode && autoSuggestZoll()) { renderExtras(); }
		renderSummary();
	}
	function rate() { var m = cfg.taxModes[taxMode]; if (!m) { return 0; } return m.rate === null ? Math.max(0, taxRate) : m.rate; }

	/* ── Summen ── */
	function calc() {
		var net = 0, st25a = 0, posNet = 0;
		items.forEach(function (it) { var l = (it.unit_price || 0) * Math.max(1, it.qty || 1); if (it.tax25a) { st25a += l; } else { net += l; posNet += l; } });
		extras.forEach(function (ex) { if (ex.on) { net += ex.amount || 0; } });
		var r = rate(), tax = Math.round(net * r) / 100;
		return { net: net, st25a: st25a, posNet: posNet, tax: tax, rate: r, total: net + tax + st25a };
	}
	function renderSummary() {
		var c = calc(), rows = $('[data-sum-rows]');
		if (rows) {
			var np = items.length, html = '';
			html += '<div class="row"><span>' + np + ' Position' + (1 === np ? '' : 'en') + '</span><span>' + eur(c.posNet + c.st25a) + '</span></div>';
			extras.forEach(function (ex) { if (ex.on) { html += '<div class="row"><span>' + esc(chipLabel(ex)) + '</span><span>' + eur(ex.amount) + '</span></div>'; } });
			if (c.st25a > 0) { html += '<div class="row mut"><span>§25a differenzbesteuert</span><span>' + eur(c.st25a) + '</span></div>'; }
			html += '<div class="row mut"><span>Zwischensumme netto</span><span>' + eur(c.net + c.st25a) + '</span></div>';
			if (c.tax > 0) { html += '<div class="row mut"><span>USt ' + (c.rate % 1 ? c.rate.toFixed(1) : c.rate) + ' %</span><span>' + eur(c.tax) + '</span></div>'; }
			rows.innerHTML = html;
		}
		var tot = eur(c.total);
		var t1 = $('[data-sum-total]'); if (t1) { t1.textContent = tot; }
		var t2 = $('[data-sum-total-bar]'); if (t2) { t2.textContent = tot; }
	}

	var salTouched = false, salAuto = '';
	function salSuggestFor(lang) { var vn = (customer.name || '').trim().split(/\s+/)[0] || ''; return ('en' === lang ? 'Hello' : 'Hallo') + (vn ? ' ' + vn : '') + ','; } // Fallback ohne Vorname: „Hallo," / „Hello,"
	function salSuggest() { return salSuggestFor(offerLang); }
	function salApply(force) {
		var el = $('[data-salutation]'); if (!el) { return; }
		var v = (el.value || '').trim();
		// Auto-Vorschlag folgt dem Sprach-/Kundenwechsel — aber NUR, solange er nicht manuell geändert wurde.
		if (force || '' === v || v === salAuto || v === salSuggestFor('de') || v === salSuggestFor('en')) { el.value = salSuggest(); salAuto = el.value; }
	}

	/* ── Angebotssprache (Kopf + Konditionen synchron) ── */
	function setLang(l) {
		offerLang = ('en' === l) ? 'en' : 'de';
		$$('[data-langsw] [data-lang]').forEach(function (s) { s.classList.toggle('on', s.getAttribute('data-lang') === offerLang); });
		$$('[data-langseg] [data-olang]').forEach(function (s) { s.classList.toggle('on', s.getAttribute('data-olang') === offerLang); });
		salApply(false);
		renderItems(); renderExtras(); // #2: EN-Titel/Freitext-EN-Felder + EN-Chip-Labels
	}

	/* ── C3: Angedockte Palette (ersetzt das alte Vollbild-Overlay komplett) ── */
	var paletteResults = [], paletteTab = 'katalog', paletteT;
	function isAdded(id) { id = parseInt(id, 10) || 0; return id > 0 && items.some(function (it) { return it.teil_id === id; }); }
	function flashRow(idx) {
		var box = $('[data-items]'); if (!box) { return; }
		var r = $$('.m24off-pos', box)[idx]; if (!r) { return; }
		r.classList.add('m24off-neu'); try { r.scrollIntoView({ block: 'nearest' }); } catch (e) {}
		setTimeout(function () { r.classList.remove('m24off-neu'); }, 1200);
	}
	function setPTab(tab) { paletteTab = tab; $$('[data-palette-tabs] [data-ptab]').forEach(function (s) { s.classList.toggle('on', s.getAttribute('data-ptab') === tab); }); renderPalette(); }
	function searchPalette() {
		var q = (($('[data-palette-q]') || {}).value || '').trim();
		if (paletteTab !== 'katalog') { setPTab('katalog'); }
		if (q.length < 2) { paletteResults = []; renderPalette(); return; }
		fetch(cfg.rest + '/parts?modell=' + encodeURIComponent(modell) + '&cat=&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
			.then(function (r) { return r.json(); })
			.then(function (d) { paletteResults = Array.isArray(d) ? d : ((d && (d.items || (d.data && d.data.items))) || []); renderPalette(); })
			.catch(function () {});
	}
	function renderPalette() {
		var list = $('[data-palette-list]'); if (!list) { return; }
		list.innerHTML = '';
		if ('katalog' === paletteTab) {
			var q = (($('[data-palette-q]') || {}).value || '').trim();
			if (!paletteResults.length) { list.innerHTML = '<p class="m24off-dnote">' + (q.length >= 2 ? 'Keine Teile gefunden.' : 'Suchbegriff eingeben — Name, Art.-Nr. oder BMW-Teilenummer.') + '</p>'; return; }
			paletteResults.forEach(function (it) {
				var done = isAdded(it.id);
				var sub = ('partnum' === it.match && it.bmw) ? ('BMW <b>' + esc(it.bmw) + '</b>' + (it.art_nr ? ' · ' + esc(it.art_nr) : '')) : (it.art_nr ? 'Art.-Nr. ' + esc(it.art_nr) : '');
				var card = document.createElement('div');
				card.className = 'm24off-dit' + (done ? ' done' : ''); card.setAttribute('data-cat-add', it.id);
				card.innerHTML = '<div class="tt">' + esc(it.title) + '</div>'
					+ (sub ? '<div class="ss">' + sub + (it.tax25a ? ' · §25a' : '') + '</div>' : '')
					+ '<div class="row"><span class="pp">' + (it.price != null ? eur(it.price) : 'auf Anfrage') + '</span><span class="add">' + (done ? '✓' : '+') + '</span></div>';
				list.appendChild(card);
			});
		} else if ('standard' === paletteTab) {
			extras.forEach(function (ex, i) {
				var card = document.createElement('div');
				card.className = 'm24off-dit m24off-dit-std' + (ex.on ? ' done' : '');
				card.innerHTML = '<div class="tt">' + esc(chipLabel(ex)) + '</div>'
					+ '<div class="ss">Standard-Position' + ('zoll' === ex.key ? ' · Vorschlag bei Drittland' : '') + '</div>'
					+ '<div class="row"><span class="pp"><input type="text" inputmode="decimal" value="' + numIn(ex.amount) + '" data-palette-stdprice="' + i + '" autocomplete="off"></span>'
					+ '<span class="add" data-std-add="' + i + '">' + (ex.on ? '✓' : '+') + '</span></div>';
				list.appendChild(card);
			});
		} else {
			var wrap = document.createElement('div'); wrap.className = 'm24off-dfree';
			wrap.innerHTML = '<input type="text" data-palette-freetitle placeholder="Bezeichnung (DE)" autocomplete="off">'
				+ ('en' === offerLang ? '<input type="text" data-palette-freetitleen placeholder="Bezeichnung (EN)" autocomplete="off">' : '')
				+ '<input type="text" inputmode="decimal" data-palette-freeprice placeholder="Preis netto, z. B. 250,00" autocomplete="off">'
				+ '<button type="button" class="m24off-btn m24off-btn-blue" data-palette-freeadd>Freie Position übernehmen</button>';
			list.appendChild(wrap);
		}
	}
	function addCatalog(id) {
		id = parseInt(id, 10) || 0; if (isAdded(id)) { return; } // ✓ = bereits übernommen → kein Duplikat
		var it = null;
		for (var k = 0; k < paletteResults.length; k++) { if ((parseInt(paletteResults[k].id, 10) || 0) === id) { it = paletteResults[k]; break; } }
		if (!it) { return; }
		items.push({
			teil_id: id, title: it.title || '', title_en: it.title_en || '', art_nr: it.art_nr || '', thumb: it.thumb || '',
			qty: 1, unit_price: Math.round(((it.price != null ? parseFloat(it.price) : 0) / 1.19) * 100) / 100, // Artikelpreis ist BRUTTO → Netto-Basis
			tax25a: !!it.tax25a, custom: false
		});
		renderItems(); renderPalette(); flashRow(items.length - 1);
	}
	function addStandard(i) { if (!extras[i]) { return; } extras[i].on = true; renderExtras(); renderSummary(); flashLastExtra(); }
	function flashLastExtra() { var box = $('[data-extra-rows]'); if (!box) { return; } var rows = $$('.m24off-pos', box), r = rows[rows.length - 1]; if (r) { r.classList.add('m24off-neu'); setTimeout(function () { r.classList.remove('m24off-neu'); }, 1200); } }
	function addFree() {
		var t = (($('[data-palette-freetitle]') || {}).value || '').trim();
		var ten = (($('[data-palette-freetitleen]') || {}).value || '').trim();
		var p = parseNum((($('[data-palette-freeprice]') || {}).value || ''));
		if (!t) { var fi = $('[data-palette-freetitle]'); if (fi) { fi.focus(); } return; }
		items.push({ teil_id: 0, title: t, title_de: t, title_en: ten, art_nr: '', qty: 1, unit_price: isNaN(p) ? 0 : p, tax25a: false, custom: false, free: true });
		renderItems(); renderPalette(); flashRow(items.length - 1);
	}
	function dockCollapse(collapsed) {
		var card = $('[data-poscard]'); if (!card) { return; }
		card.classList.toggle('dock-collapsed', !!collapsed);
		var btn = $('[data-dock-collapse]'); if (btn) { btn.textContent = collapsed ? '⇤' : '⇥ Palette einklappen'; btn.title = collapsed ? 'Palette ausklappen' : 'Palette einklappen'; }
		try { localStorage.setItem('m24off_dock_collapsed', collapsed ? '1' : '0'); } catch (e) {}
	}
	function dockOpen(open) { var d = $('[data-dock]'); if (d) { d.classList.toggle('is-open', !!open); } }

	/* ── Kunde (read-only Chip; „ändern" blendet Edit-Felder ein) ── */
	function segKT(btn) {
		var box = btn.closest('[data-c-kundentyp]'); if (!box) { return; }
		$$('.m24off-segbtn', box).forEach(function (b) { b.classList.toggle('is-on', b === btn); });
	}
	function collectCustomer() {
		var edit = $('[data-kunde-edit]');
		if (edit && !edit.hidden) {
			var kt = $('[data-kunde-edit] [data-c-kundentyp] .is-on');
			customer = {
				name: ($('[data-c="name"]') || {}).value || customer.name,
				email: ($('[data-c="email"]') || {}).value || customer.email,
				kundentyp: kt ? kt.getAttribute('data-kt') : customer.kundentyp,
				land: ($('[data-c="land"]') || {}).value || customer.land
			};
		}
		return customer;
	}

	/* ── Senden ── */
	function doAction(action) {
		var st = $('[data-status]'), cust = collectCustomer();
		if ('text' === action) {
			window.location.href = 'mailto:' + encodeURIComponent(cust.email) + '?subject=' + encodeURIComponent('Ihre Anfrage bei MOTORSPORT24');
			return;
		}
		if (!items.length) { st.textContent = 'Bitte mindestens eine Position hinzufügen.'; st.className = 'm24off-status is-error'; return; }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cust.email)) { st.textContent = 'Bitte eine gültige Kunden-E-Mail angeben.'; st.className = 'm24off-status is-error'; return; }
		if (!taxMode) { st.textContent = 'Bitte den Steuerfall manuell wählen.'; st.className = 'm24off-status is-error'; return; }
		if ('b2c_eu_oss' === taxMode) {
			var rEl = $('[data-tax-rate]'), rv = rEl && rEl.value.trim();
			if (rv === '' || !(taxRate >= 0 && taxRate <= 27)) { st.textContent = 'Bitte einen USt-Satz (0–27 %) angeben.'; st.className = 'm24off-status is-error'; return; }
		}
		$$('[data-action="send"]').forEach(function (b) { b.disabled = true; });
		st.textContent = 'Wird gesendet …'; st.className = 'm24off-status';
		fetch(cfg.rest + '/send', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify({
				customer: cust, items: items,
				extras: extras.map(function (e) { return { key: e.key, label: chipLabel(e), amount: e.amount, on: e.on }; }), // Label in Angebotssprache einfrieren
				tax_mode: taxMode, tax_rate: taxRate, lang: offerLang,
				delivery_time: ($('[data-delivery]') || {}).value || '',
				salutation: ($('[data-salutation]') || {}).value || '', note: ($('[data-note]') || {}).value || '',
				inquiry_id: (cfg.prefill && cfg.prefill.inquiry_id) || 0,
				src: cfg.src || {}
			})
		}).then(function (r) { return r.json(); }).then(function (d) {
			$$('[data-action="send"]').forEach(function (b) { b.disabled = false; });
			if (d && d.ok) {
				st.textContent = d.message + (d.register_link ? ' Konto-Link an den Gast verschickt.' : '');
				st.className = 'm24off-status is-ok';
			} else {
				st.textContent = (d && (d.message || d.error)) || 'Senden fehlgeschlagen.';
				st.className = 'm24off-status is-error';
			}
		}).catch(function () { $$('[data-action="send"]').forEach(function (b) { b.disabled = false; }); st.textContent = 'Senden fehlgeschlagen.'; st.className = 'm24off-status is-error'; });
	}

	/* ── Delegierte Events ── */
	document.addEventListener('input', function (e) {
		var t = e.target;
		if (t.matches('[data-qty]')) { items[+t.getAttribute('data-i')].qty = Math.max(1, parseInt(t.value, 10) || 1); renderSummary(); }
		else if (t.matches('[data-price]')) { var pi = +t.getAttribute('data-i'); var pn = parseNum(t.value); if (!isNaN(pn)) { items[pi].unit_price = pn; var bw = t.parentNode.querySelector('[data-brutto]'); if (bw) { bw.textContent = '= ' + eur(pn * 1.19) + ' brutto'; } renderSummary(); } }
		else if (t.matches('[data-title]')) { var ti = +t.getAttribute('data-i'); items[ti].title_de = t.value; items[ti].title = t.value; }
			else if (t.matches('[data-title-en]')) { items[+t.getAttribute('data-i')].title_en = t.value; }
			else if (t.matches('[data-extra-price]')) { var en = parseNum(t.value); if (!isNaN(en)) { extras[+t.getAttribute('data-extra-price')].amount = en; renderSummary(); } }
		else if (t.matches('[data-c="land"]')) { customer.land = cxLandToIso(t.value || ''); cfg.custIsDrittland = cxIsDrittland(customer.land); if (cfg.custIsDrittland) { autoSuggestZoll(); } renderExtras(); renderSummary(); } // {Empfängerland}: auf ISO normalisieren (England→GB → Label „England")
		else if (t.matches('[data-tax-rate]')) { taxRate = parseFloat(t.value) || 0; renderSummary(); }
		else if (t.matches('[data-salutation]')) { salTouched = true; }
			else if (t.matches('[data-cx-q]')) { clearTimeout(cxT); cxT = setTimeout(cxSearch, 250); }
			else if (t.matches('[data-palette-q]')) { clearTimeout(paletteT); paletteT = setTimeout(searchPalette, 250); }
			else if (t.matches('[data-palette-stdprice]')) { var spn = parseNum(t.value); if (!isNaN(spn)) { extras[+t.getAttribute('data-palette-stdprice')].amount = spn; renderSummary(); } }
	});
	document.addEventListener('change', function (e) { if (e.target.matches('[data-tax-mode]')) { setTaxMode(e.target.value); } });
	document.addEventListener('click', function (e) {
		var t = e.target, el;
		if ((el = t.closest('[data-qdec]'))) { var a = +el.getAttribute('data-i'); items[a].qty = Math.max(1, (items[a].qty || 1) - 1); renderItems(); return; }
		if ((el = t.closest('[data-qinc]'))) { var b = +el.getAttribute('data-i'); items[b].qty = (items[b].qty || 1) + 1; renderItems(); return; }
		if ((el = t.closest('[data-rm]'))) { items.splice(+el.getAttribute('data-i'), 1); renderItems(); renderPalette(); return; }
		if ((el = t.closest('[data-extra-toggle]'))) { var i3 = +el.getAttribute('data-extra-toggle'); extras[i3].on = !extras[i3].on; if (extras[i3].on && 'zoll' !== extras[i3].key) {} renderExtras(); renderSummary(); return; }
		if ((el = t.closest('[data-ptab]'))) { setPTab(el.getAttribute('data-ptab')); return; }
		if ((el = t.closest('[data-cat-add]'))) { addCatalog(el.getAttribute('data-cat-add')); return; }
		if ((el = t.closest('[data-std-add]'))) { addStandard(+el.getAttribute('data-std-add')); return; }
		if ((el = t.closest('[data-palette-freeadd]'))) { addFree(); return; }
		if ((el = t.closest('[data-dock-collapse]'))) { var pc = $('[data-poscard]'); dockCollapse(!(pc && pc.classList.contains('dock-collapsed'))); return; }
		if ((el = t.closest('[data-dock-open]'))) { dockOpen(true); return; }
		if ((el = t.closest('[data-dock-close]'))) { dockOpen(false); return; }
		if ((el = t.closest('[data-salutation-reset]'))) { e.preventDefault(); salApply(true); return; }
		if ((el = t.closest('[data-lang]'))) { setLang(el.getAttribute('data-lang')); return; }
		if ((el = t.closest('[data-olang]'))) { setLang(el.getAttribute('data-olang')); return; }
		if ((el = t.closest('[data-cust-edit]'))) { e.preventDefault(); cxOpen({ id: customer.id || 0, name: customer.name, email: customer.email, kundentyp: customer.kundentyp, land: customer.land }); return; }
		if ((el = t.closest('[data-kt]'))) { segKT(el); return; }
		if ((el = t.closest('[data-cust-search]'))) { e.preventDefault(); cxOpen(); return; }
		if ((el = t.closest('[data-cx-close]')) || t.matches('[data-cxmodal]')) { cxClose(); return; }
		if ((el = t.closest('[data-cxkt]'))) { cxSetKt(el.getAttribute('data-cxkt')); return; }
		if ((el = t.closest('[data-cx-vatcheck]'))) { cxVatCheck(); return; }
		if ((el = t.closest('[data-cx-create]'))) { cxCreate(); return; }
		if ((el = t.closest('[data-action]'))) { doAction(el.getAttribute('data-action')); return; }
	});

	/* ── Prefill (aus Anfrage/Garage) ── */
	if (cfg.prefill && cfg.prefill.items && cfg.prefill.items.length) {
		items = cfg.prefill.items.map(function (it) {
			return {
				teil_id: parseInt(it.teil_id, 10) || 0,
				title: it.title || '', title_de: (it.title_de != null ? it.title_de : (it.title || '')), title_en: it.title_en || '', art_nr: it.art_nr || '', thumb: it.thumb || '',
				variant: it.variant || '',
				qty: parseInt(it.qty, 10) || 1,
				unit_price: parseFloat(it.unit_price) || 0,
				tax25a: !!it.tax25a, custom: !!it.custom
			};
		});
		var dEl = $('[data-delivery]'); if (dEl && cfg.prefill.delivery) { dEl.value = cfg.prefill.delivery; }
		if (cfg.prefill.tax_mode) { setTaxMode(cfg.prefill.tax_mode); }
		if (cfg.prefill.tax_rate) { taxRate = parseFloat(cfg.prefill.tax_rate) || 0; var rr = $('[data-tax-rate]'); if (rr) { rr.value = cfg.prefill.tax_rate; } }
		if (cfg.prefill.salutation) { var se = $('[data-salutation]'); if (se) { se.value = cfg.prefill.salutation; salTouched = true; } }
		if (cfg.prefill.note) { var ne = $('[data-note]'); if (ne) { ne.value = cfg.prefill.note; } }
	}

	/* ── B: Kunden-Schnellanlage / -Bearbeitung (Modal) ── */
	var CX_EU = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
	var CX_LAND = { 'UK':'GB','GB':'GB','GROSSBRITANNIEN':'GB','GROẞBRITANNIEN':'GB','VEREINIGTES KÖNIGREICH':'GB','VEREINIGTES KOENIGREICH':'GB','UNITED KINGDOM':'GB','ENGLAND':'GB','GREAT BRITAIN':'GB','BRITAIN':'GB','DEUTSCHLAND':'DE','GERMANY':'DE','ÖSTERREICH':'AT','OESTERREICH':'AT','AUSTRIA':'AT','SCHWEIZ':'CH','SWITZERLAND':'CH','FRANKREICH':'FR','FRANCE':'FR','ITALIEN':'IT','ITALY':'IT','SPANIEN':'ES','SPAIN':'ES','NIEDERLANDE':'NL','NETHERLANDS':'NL','BELGIEN':'BE','BELGIUM':'BE','LUXEMBURG':'LU','POLEN':'PL','POLAND':'PL','TSCHECHIEN':'CZ','DÄNEMARK':'DK','DAENEMARK':'DK','SCHWEDEN':'SE','USA':'US','UNITED STATES':'US','VEREINIGTE STAATEN':'US' };
	function cxLandToIso(v) { v = (v || '').trim().toUpperCase().replace(/\s+/g, ' '); if (!v) { return ''; } if (CX_LAND[v]) { return CX_LAND[v]; } return v.replace(/[^A-Z]/g, '').slice(0, 2); }
	function cxIsDrittland(land) { var iso = cxLandToIso(land); return '' !== iso && CX_EU.indexOf(iso) < 0; } // GB/England seit Brexit = Drittland
	var cxKt = 'b2c', cxT, cxEditId = 0;
	function applyCustomer(c) {
		customer = { id: c.id || 0, name: c.name || '', email: c.email || '', kundentyp: ('b2b' === c.kundentyp ? 'b2b' : 'b2c'), land: cxLandToIso(c.land) };
		var nm = $('[data-cust-chip-name]'); if (nm) { nm.textContent = customer.name || '—'; }
		var sub = $('[data-cust-chip-sub]'); if (sub) { sub.textContent = (customer.email || '') + ' · ' + ('b2b' === customer.kundentyp ? 'Geschäftskunde (B2B)' : 'Privat (B2C)') + ' · ' + (customer.land || '—'); }
		var av = $('[data-cust-chip-av]'); if (av) { var pp = (customer.name || '').trim().split(/\s+/).slice(0, 2); av.textContent = pp.map(function (w) { return (w[0] || '').toUpperCase(); }).join('') || 'K'; }
		var fn = $('[data-c="name"]'); if (fn) { fn.value = customer.name; }
		var fe = $('[data-c="email"]'); if (fe) { fe.value = customer.email; }
		var fl = $('[data-c="land"]'); if (fl) { fl.value = customer.land; }
		salApply(false);
		cfg.custIsDrittland = cxIsDrittland(customer.land);
		if (cfg.custIsDrittland) { autoSuggestZoll(); } // England/Drittland → Zoll-Chip anspringen lassen
		// {Empfängerland} live: Chips UND bereits aktivierte Versand-Positionen neu labeln (Preis unangetastet).
		renderExtras(); renderSummary();
	}
	function cxTitle(t, b) { var e = $('[data-cx-title]'); if (e) { e.textContent = t; } var bt = $('[data-cx-create]'); if (bt) { bt.textContent = b; } }
	function cxSetKt(kt) {
		cxKt = ('b2b' === kt) ? 'b2b' : 'b2c';
		$$('[data-cx-kt] .m24off-segbtn').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-cxkt') === cxKt); });
		var grid = $('[data-cx-grid]'); if (grid) { grid.classList.toggle('is-b2b', 'b2b' === cxKt); }
		var show = ('b2b' === cxKt);
		// Sichtbarkeit KOMPLETT inline (RUCSS-immun) — inkl. display. Ohne display würde eine verbliebene
		// display:none-Regel (RUCSS-Rest/Cache) trotz opacity:1 gewinnen → offsetParent bleibt null. Inline-
		// display schlägt jede CSS-Regel; CSS liefert nur noch die Transition.
		$$('.m24off-cx-b2b').forEach(function (el) {
			el.style.display = show ? 'block' : 'none';
			el.style.opacity = show ? '1' : '0';
			el.style.maxHeight = show ? '260px' : '0';
			el.style.overflow = show ? 'visible' : 'hidden';
			el.style.pointerEvents = show ? 'auto' : 'none';
		});
		// B2C: Firmenname/USt-ID/EORI leeren + input-Event feuern (interne States/Validierung ziehen nach).
		if (!show) { ['firmenname', 'ustid', 'eori'].forEach(function (k) { var el = $('[data-cx="' + k + '"]'); if (el) { el.value = ''; el.dispatchEvent(new Event('input', { bubbles: true })); } }); }
	}
	function cxReset() { $$('[data-cx]').forEach(function (el) { el.value = ''; }); cxEditId = 0; cxSetKt('b2c'); var st = $('[data-cx-status]'); if (st) { st.textContent = ''; st.className = 'm24off-cxstatus'; } }
	function cxLoadForEdit(c) {
		cxReset();
		cxEditId = c.id || 0;
		var set = function (k, v) { var el = $('[data-cx="' + k + '"]'); if (el) { el.value = v || ''; } };
		var vn = c.vorname || '', nn = c.nachname || '';
		if ('' === vn && '' === nn && c.name) { var pp = String(c.name).trim().split(/\s+/); vn = pp.shift() || ''; nn = pp.join(' '); }
		set('firmenname', c.firmenname || c.firma); set('vorname', vn); set('nachname', nn);
		set('strasse', c.strasse); set('adresszusatz', c.adresszusatz); set('plz', c.plz); set('ort', c.ort);
		set('land', c.land); set('telefon', c.telefon); set('email', c.email); set('ustid', c.ustid); set('eori', c.eori);
		cxSetKt(c.kundentyp);
		cxTitle(cxEditId ? 'Kunde bearbeiten' : 'Kunde suchen oder anlegen', cxEditId ? 'Aktualisieren & übernehmen' : 'Kunde anlegen & übernehmen');
	}
	function cxOpen(editC) {
		var m = $('[data-cxmodal]'); if (!m) { return; }
		if (editC) { cxLoadForEdit(editC); } else { cxReset(); cxTitle('Kunde suchen oder anlegen', 'Kunde anlegen & übernehmen'); }
		m.hidden = false;
		var q = $('[data-cx-q]'); if (q) { q.value = ''; if (!editC) { q.focus(); } }
		var r = $('[data-cx-results]'); if (r) { r.innerHTML = ''; }
	}
	function cxClose() { var m = $('[data-cxmodal]'); if (m) { m.hidden = true; } }
	function cxSearch() {
		var q = ($('[data-cx-q]') || {}).value || ''; var r = $('[data-cx-results]'); if (!r) { return; }
		if (q.trim().length < 2) { r.innerHTML = ''; return; }
		fetch(cfg.rest + '/customers?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
			.then(function (x) { return x.json(); }).then(function (d) {
				var items = (d && d.items) || []; r.innerHTML = '';
				if (!items.length) { r.innerHTML = '<div class="m24off-cxempty">Kein Treffer — unten neu anlegen.</div>'; return; }
				items.forEach(function (c) {
					var row = document.createElement('button'); row.type = 'button'; row.className = 'm24off-cxres';
					row.innerHTML = '<b>' + esc(c.name || c.email) + '</b><small>' + esc(c.email) + (c.firma ? ' · ' + esc(c.firma) : '') + ' · ' + ('b2b' === c.kundentyp ? 'B2B' : 'B2C') + (c.land ? ' · ' + esc(c.land) : '') + '</small>';
					row.addEventListener('click', function () { cxLoadForEdit(c); }); // #4: Treffer in die Felder laden (Edit-Modus)
					r.appendChild(row);
				});
			}).catch(function () {});
	}
	function cxCreate() {
		var st = $('[data-cx-status]');
		var g = function (k) { var el = $('[data-cx="' + k + '"]'); return el ? el.value.trim() : ''; };
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(g('email'))) { if (st) { st.textContent = 'Bitte eine gültige E-Mail angeben (Pflicht).'; st.className = 'm24off-cxstatus is-error'; } return; }
		var payload = { id: cxEditId || 0, kundentyp: cxKt, firmenname: g('firmenname'), vorname: g('vorname'), nachname: g('nachname'), strasse: g('strasse'), adresszusatz: g('adresszusatz'), plz: g('plz'), ort: g('ort'), land: cxLandToIso(g('land')), telefon: g('telefon'), email: g('email'), ustid: g('ustid'), eori: g('eori') };
		var btn = $('[data-cx-create]'); if (btn) { btn.disabled = true; } if (st) { st.textContent = cxEditId ? 'Wird aktualisiert …' : 'Wird angelegt …'; st.className = 'm24off-cxstatus'; }
		fetch(cfg.rest + '/customer-create', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify(payload) })
			.then(function (x) { return x.json(); }).then(function (d) {
				if (btn) { btn.disabled = false; }
				if (d && d.ok && d.customer) { applyCustomer(d.customer); if (st) { st.textContent = cxEditId ? 'Kunde aktualisiert & übernommen.' : (d.customer.existed ? 'Bestehender Kunde übernommen.' : 'Kunde angelegt & übernommen.'); st.className = 'm24off-cxstatus is-ok'; } setTimeout(cxClose, 600); }
				else if (st) { st.textContent = (d && (d.message || d.error)) || 'Speichern fehlgeschlagen.'; st.className = 'm24off-cxstatus is-error'; }
			}).catch(function () { if (btn) { btn.disabled = false; } if (st) { st.textContent = 'Speichern fehlgeschlagen.'; st.className = 'm24off-cxstatus is-error'; } });
	}
	function cxVatCheck() { var el = $('[data-cx="ustid"]'), st = $('[data-cx-status]'); if (!el || !st) { return; } var v = el.value.replace(/\s/g, '').toUpperCase(); var ok = /^[A-Z]{2}[0-9A-Z]{2,12}$/.test(v); st.textContent = ok ? ('USt-IdNr. ' + v + ' — Format gültig (Live-VIES-Prüfung folgt).') : 'USt-IdNr.-Format unplausibel (z. B. DE123456789).'; st.className = 'm24off-cxstatus ' + (ok ? 'is-ok' : 'is-error'); }

	setLang('de');
	salApply(false);
	if (cfg.custIsDrittland) { autoSuggestZoll(); } // Drittland-Kunde → Zoll-Chip vorab aktiv (manuell abwählbar)
	renderItems();
	renderExtras();
	renderSummary();
	setPTab('katalog');
	try { if ('1' === localStorage.getItem('m24off_dock_collapsed')) { dockCollapse(true); } } catch (e) {}
})();
