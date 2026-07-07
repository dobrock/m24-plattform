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
	function eur(v) { return (Number(v) || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; }
	// Deutsches Dezimalkomma robust parsen: „77,50" · „1.234,56" (Tausenderpunkt) · „77.50" · „77" → Number.
	// Ungültige Eingabe → NaN (Aufrufer behält den letzten gültigen Wert statt auf 0 zu fallen).
	function parseNum(s) { s = String(s == null ? '' : s).trim(); if (!s) { return NaN; } if (s.indexOf(',') > -1) { s = s.replace(/\./g, '').replace(',', '.'); } var n = parseFloat(s); return isNaN(n) ? NaN : n; }
	function numIn(n) { return (n == null || isNaN(n)) ? '' : String(n).replace('.', ','); } // roh (zum Editieren)
	function numFmt(n) { return (n == null || isNaN(n)) ? '' : (Number(n)).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } // B3: Anzeige mit Tausenderpunkt
	var priceMode = 'netto'; // B2: Netto/Brutto-Eingabemodus (global)
	function setPriceMode(pm) { priceMode = ('brutto' === pm) ? 'brutto' : 'netto'; $$('[data-pricemode] [data-pm]').forEach(function (s) { s.classList.toggle('on', s.getAttribute('data-pm') === priceMode); }); renderItems(); }

	/* ── State ── */
	var items  = [];  // {teil_id,title,art_nr,qty,unit_price,tax25a,custom,free,variant,thumb}
	var extras = (cfg.presets || []).map(function (p) {
		var e = { key: p.key || '', label: p.label, amount: parseFloat(p.amount) || 0, on: false };
		if ('versand' === e.key) { e.incoterm = 'DAP'; e.method = 'sea'; e.land = ''; } // #8: Incoterm + Versandweg + Land-Override (leer = Empfängerland)
		return e;
	});
	var shipOpen = false; // Inline-Editor der Versand-Position offen?
	var taxMode = '', taxRate = 0, offerLang = 'de';
	var modell = (cfg.src && cfg.src.src_modell) || '';
	var customer = cfg.customer || { name: '', email: '', kundentyp: 'b2c', land: '' };
	var LANDS = cfg.lands || {};
	// GB → „England" (Daniels kanonisches Label; die Länderliste liefert sonst „Großbritannien"). Gilt DE + EN.
	// Kanonischer Ländername in ORIGINAL-Schreibweise (Title-Case aus der Mapping-Tabelle). Nie den Eingabewert
	// uppercasen — sonst würde ein voller Name („England") als „ENGLAND" durchschlagen. Normalisiert Aliase
	// (England/UK/Schweiz …) erst auf ISO, dann Lookup; unbekannt → Original-Schreibweise unverändert.
	function landName(raw) {
		var iso = ('function' === typeof cxLandToIso) ? cxLandToIso(raw) : String(raw || '').toUpperCase().slice(0, 2);
		if ('GB' === iso) { return 'England'; } // Daniels kanonisches Label
		return LANDS[iso] || (raw ? String(raw).trim() : '');
	}

	/* ── EN-Wörterbuch für Standard-Positionen (KEINE Maschinenübersetzung von Katalogtiteln) ── */
	var STD_DE = { verpackung: 'Transportsicher verpacken', zoll: 'Zollabwicklung Deutschland' };
	var STD_EN = { verpackung: 'Secure transport packaging', zoll: 'Customs handling Germany' };
	var LANDS_EN = cfg.landsEn || {};
	function landNameEn(raw) {
		var iso = ('function' === typeof cxLandToIso) ? cxLandToIso(raw) : String(raw || '').toUpperCase().slice(0, 2);
		if ('GB' === iso) { return 'England'; }
		return LANDS_EN[iso] || landName(raw);
	}
	function chipLabel(ex) {
		// #8: „Insured Shipping — {INCOTERM}[, {Ortslabel}] · {Land}" — Incoterm (DAP/CIF/CIP) + Versandweg + Land (verbatim).
		if ('versand' === ex.key) {
			var en   = ('en' === offerLang);
			var inco = ex.incoterm || 'DAP';
			var land = (ex.land || customer.land || '');
			var ort  = ''; // Seefracht → Zielhafen, Luftfracht → Zielflughafen, Landweg → kein Zusatz
			if ('sea' === ex.method) { ort = en ? 'port of destination' : 'Zielhafen'; }
			else if ('air' === ex.method) { ort = en ? 'airport of destination' : 'Zielflughafen'; }
			var base = (en ? 'Insured Shipping — ' : 'Versicherter Versand — ') + inco + (ort ? ', ' + ort : '') + ' · ' + land;
			return base.replace(/\s{2,}/g, ' ').replace(/\s·\s$/, '').trim();
		}
		var b = ('en' === offerLang) ? (STD_EN[ex.key] || ex.label) : (STD_DE[ex.key] || ex.label);
		return b.replace(/\s{2,}/g, ' ').trim();
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
				titleHtml = '<div class="m24off-pt">' + esc(it.title || '') + '</div>';
				// #7: Katalog-Position im EN-Angebot → editierbares EN-Feld; on-blur dauerhaft in den Artikel (Override).
				if ('en' === offerLang) {
					titleHtml += '<input type="text" class="m24off-pt-in m24off-pt-en" value="' + esc(it.title_en || '') + '" data-i="' + i + '" data-title-en-cat placeholder="EN-Titel — wird dauerhaft im Artikel gespeichert">';
					titleHtml += '<span class="m24off-ensaved" data-ensaved="' + i + '"></span>';
				}
			}
			var metaHtml = it.art_nr ? '<div class="m24off-pa">Art.-Nr. ' + esc(it.art_nr) + '</div>' : '';
			var varHtml = it.variant ? '<div class="m24off-pa m24off-pvar">Variante: ' + esc(it.variant) + '</div>' : '';
			// B2: Netto/Brutto-Modus — Feld zeigt den aktiven Wert, der andere steht INLINE davor (eine Zeile).
			var net = it.unit_price || 0, brutto = Math.round(net * 1.19 * 100) / 100;
			var isNet = ('netto' === priceMode);
			var fieldVal = isNet ? net : brutto, calcVal = isNet ? brutto : net, calcLbl = isNet ? 'brutto' : 'netto';
			row.innerHTML = '<span class="m24off-drag" data-drag title="Ziehen zum Sortieren" aria-label="Sortieren">⠿</span>'
				+ (it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="m24off-pos-ph"></span>')
				+ '<div class="m24off-pos-main">' + titleHtml + metaHtml + varHtml + '</div>'
				+ '<div class="m24off-qty2"><input type="number" min="1" value="' + it.qty + '" data-i="' + i + '" data-qty inputmode="numeric"></div>' // B1: native Spinner, keine −/+
				+ '<div class="m24off-pprice"><span class="m24off-pcalc" data-pcalc="' + i + '">' + eur(calcVal) + ' <em>' + calcLbl + '</em></span>'
				+ '<input type="text" inputmode="decimal" value="' + numFmt(fieldVal) + '" data-i="' + i + '" data-price autocomplete="off"></div>'
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
			var isShip = ('versand' === ex.key);
			var sub = isShip
				? '<div class="m24off-pa m24off-shiptog" data-ship-toggle>Land / Versandweg ändern ▾</div>'
				: '<div class="m24off-pa">Standard-Position</div>';
			var row = document.createElement('div');
			row.className = 'm24off-pos m24off-pos-std';
			row.innerHTML = '<span class="m24off-drag is-static" aria-hidden="true">⠿</span>'
				+ '<span class="m24off-pos-ph m24off-ph-std">€</span>'
				+ '<div class="m24off-pos-main"><div class="m24off-pt" data-ship-label="' + i + '">' + esc(chipLabel(ex)) + '</div>' + sub + '</div>'
				+ '<div class="m24off-qty2"></div>'
				+ '<div class="m24off-pprice"><input type="text" inputmode="decimal" value="' + numFmt(ex.amount) + '" data-extra-price="' + i + '" autocomplete="off"><div class="m24off-bru2">netto</div></div>'
				+ '<button type="button" class="m24off-posx" data-extra-toggle="' + i + '" aria-label="Position entfernen">✕</button>';
			box.appendChild(row);
			// Versand: Inline-Editor (Incoterm + Versandweg + Land) unter der Zeile, wenn aufgeklappt.
			if (isShip && shipOpen) {
				var ed = document.createElement('div');
				ed.className = 'm24off-shipedit';
				var inco = ex.incoterm || 'DAP';
				var cif  = ('CIF' === inco); // CIF nur Seefracht → Luft/Landweg deaktivieren
				ed.innerHTML = '<label>Incoterm<select data-ship-incoterm="' + i + '">'
					+ '<option value="DAP"' + ('DAP' === inco ? ' selected' : '') + '>DAP</option>'
					+ '<option value="CIF"' + ('CIF' === inco ? ' selected' : '') + '>CIF</option>'
					+ '<option value="CIP"' + ('CIP' === inco ? ' selected' : '') + '>CIP</option>'
					+ '</select></label>'
					+ '<label>Versandweg<select data-ship-method="' + i + '">'
					+ '<option value="sea"' + ('sea' === ex.method ? ' selected' : '') + '>Seefracht / Sea freight</option>'
					+ '<option value="air"' + ('air' === ex.method ? ' selected' : '') + (cif ? ' disabled' : '') + '>Luftfracht / Air freight</option>'
					+ '<option value=""' + ('' === ex.method ? ' selected' : '') + (cif ? ' disabled' : '') + '>Landweg (ohne Zusatz)</option>'
					+ '</select></label>'
					+ '<label>Land<input type="text" data-ship-land="' + i + '" value="' + esc(ex.land || customer.land || '') + '" placeholder="' + esc(customer.land || 'Deutschland') + '" autocomplete="off"></label>';
				box.appendChild(ed);
			}
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
		syncDeliveryLang(); // #4: Lieferzeit-Dropdown DE/EN
		renderItems(); renderExtras(); // #2: EN-Titel/Freitext-EN-Felder + EN-Chip-Labels
		ensureEnTitles(); // #2: fehlende EN-Katalogtitel live per DeepL nachziehen
	}

	/* #2: EN-Titel fehlender Katalog-Positionen on-demand ziehen (Batch-DeepL, gecacht). Graceful: DE-Fallback. */
	var enTitlesFetching = false;
	function ensureEnTitles() {
		if ('en' !== offerLang) { return; }
		var ids = [];
		items.forEach(function (it) { var tid = parseInt(it.teil_id, 10) || 0; if (tid > 0 && !(it.title_en && String(it.title_en).trim())) { ids.push(tid); } });
		if (!ids.length || enTitlesFetching) { return; }
		enTitlesFetching = true;
		fetch(cfg.rest + '/en-titles', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify({ ids: ids }) })
			.then(function (r) { return r.json(); }).then(function (d) {
				enTitlesFetching = false;
				if (d && d.ok && d.titles) {
					items.forEach(function (it) { var k = String(parseInt(it.teil_id, 10) || 0); if (d.titles[k] && !(it.title_en && String(it.title_en).trim())) { it.title_en = d.titles[k]; } });
					if ('en' === offerLang) { renderItems(); }
				}
			}).catch(function () { enTitlesFetching = false; });
	}

	/* #7: EN-Titel einer Katalog-Position dauerhaft in den Artikel schreiben (_m24_titel_en_manual, DeepL-fest). */
	function saveEnTitle(i) {
		var it = items[i]; if (!it) { return; }
		var tid = parseInt(it.teil_id, 10) || 0, val = String(it.title_en || '').trim(), fb = $('[data-ensaved="' + i + '"]');
		if (!tid || !val) { if (fb) { fb.textContent = ''; } return; } // freie Position → nur Offer-Snapshot
		if (fb) { fb.textContent = '…'; fb.className = 'm24off-ensaved'; }
		fetch(cfg.rest + '/save-en-title', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify({ teil_id: tid, title_en: val }) })
			.then(function (r) { return r.json(); }).then(function (d) {
				if (!fb) { return; }
				if (d && d.ok) { fb.textContent = '✓ dauerhaft gespeichert'; fb.className = 'm24off-ensaved is-ok'; }
				else { fb.textContent = 'nicht gespeichert'; fb.className = 'm24off-ensaved is-err'; }
			}).catch(function () { if (fb) { fb.textContent = 'nicht gespeichert'; fb.className = 'm24off-ensaved is-err'; } });
	}
	document.addEventListener('focusout', function (e) {
		var t = e.target; if (!t || !t.matches) { return; }
		if (t.matches('[data-title-en-cat]')) { saveEnTitle(+t.getAttribute('data-i')); }
		if (t.matches('[data-price],[data-extra-price],[data-palette-stdprice]')) { var n = parseNum(t.value); t.value = isNaN(n) ? '' : numFmt(n); } // B3: beim Blur mit Tausenderpunkt formatieren
	});
	document.addEventListener('focusin', function (e) { var t = e.target; if (t && t.matches && t.matches('[data-price],[data-extra-price],[data-palette-stdprice]')) { var n = parseNum(t.value); t.value = isNaN(n) ? '' : numIn(n); } }); // B3: beim Fokus roh editierbar

	/* #4: Lieferzeit-Dropdown auf die Angebotssprache umstellen (Werte bleiben DE = kanonisch; nur Anzeige). */
	function syncDeliveryLang() {
		var sel = $('[data-delivery]'); if (!sel) { return; }
		$$('option', sel).forEach(function (o) {
			var en = o.getAttribute('data-en'), de = o.getAttribute('data-de');
			if (null !== en && null !== de) { o.textContent = ('en' === offerLang) ? en : de; }
		});
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
				card.className = 'm24off-dit' + (done ? ' done' : '');
				// Vollständige data-Attribute → Add ist self-contained (unabhängig von paletteResults-Lookup).
				card.setAttribute('data-cat-add', it.id != null ? it.id : '');
				card.setAttribute('data-title', it.title || '');
				card.setAttribute('data-title-en', it.title_en || '');
				card.setAttribute('data-art', it.art_nr || '');
				card.setAttribute('data-thumb', it.thumb || '');
				card.setAttribute('data-price', (it.price != null && '' !== it.price) ? it.price : '');
				card.setAttribute('data-25a', it.tax25a ? '1' : '0');
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
					+ '<div class="row"><span class="pp"><input type="text" inputmode="decimal" value="' + numFmt(ex.amount) + '" data-palette-stdprice="' + i + '" autocomplete="off"></span>'
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
	function addCatalog(el) {
		if (!el) { return; }
		var id = parseInt(el.getAttribute('data-cat-add'), 10) || 0;
		if (id > 0 && isAdded(id)) { return; } // ✓ = bereits übernommen → kein Duplikat
		var priceRaw = parseFloat(el.getAttribute('data-price')); if (isNaN(priceRaw)) { priceRaw = 0; }
		var is25a = ('1' === el.getAttribute('data-25a'));
		// §25a: Preis ist die differenzbesteuerte Brutto-Basis → NICHT durch 1,19 teilen (keine ausweisbare
		// MwSt). Regelbesteuert: Artikelpreis ist Brutto inkl. 19 % → Netto-Basis (÷1,19).
		var unit = is25a ? Math.round(priceRaw * 100) / 100 : Math.round((priceRaw / 1.19) * 100) / 100;
		items.push({
			teil_id: id,
			title: el.getAttribute('data-title') || '',
			title_en: el.getAttribute('data-title-en') || '',
			art_nr: el.getAttribute('data-art') || '',
			thumb: el.getAttribute('data-thumb') || '',
			qty: 1, unit_price: unit, tax25a: is25a, custom: false
		});
		renderItems(); renderPalette(); flashRow(items.length - 1);
		ensureEnTitles(); // #2: EN-Titel der neuen Katalog-Position live nachziehen (falls Angebotssprache EN)
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
	var currentDraftId = (cfg.draftId | 0) || 0; // >0 → Operator bearbeitet einen Entwurf (Senden aktualisiert ihn)
	function busy(b) { $$('[data-action="send"],[data-action="draft"]').forEach(function (x) { x.disabled = b; }); }
	function backLinkHtml() { return cfg.listUrl ? ' <a class="m24off-backlink" href="' + esc(cfg.listUrl) + '">← Zurück zur Übersicht</a>' : ''; } // #4
	function openPreview(title, html) { var m = $('[data-pvmodal]'), fr = $('[data-pvframe]'), tt = $('[data-pvtitle]'); if (!m || !fr) { return; } if (tt) { tt.textContent = title; } fr.srcdoc = html || ''; m.hidden = false; } // C2
	function closePreview() { var m = $('[data-pvmodal]'); if (m) { m.hidden = true; } var fr = $('[data-pvframe]'); if (fr) { fr.srcdoc = ''; } }
	function offerPayload() {
		return {
			customer: collectCustomer(), items: items,
			extras: extras.map(function (e) { return { key: e.key, label: chipLabel(e), amount: e.amount, on: e.on, incoterm: e.incoterm || '', method: e.method || '', land: e.land || '' }; }), // Label eingefroren + #8 Incoterm/Weg/Land im Snapshot
			tax_mode: taxMode, tax_rate: taxRate, lang: offerLang,
			delivery_time: ($('[data-delivery]') || {}).value || '',
			salutation: ($('[data-salutation]') || {}).value || '', note: ($('[data-note]') || {}).value || '',
			inquiry_id: (cfg.prefill && cfg.prefill.inquiry_id) || 0,
			draft_id: currentDraftId,
			src: cfg.src || {}
		};
	}
	function doAction(action) {
		var st = $('[data-status]'), cust = collectCustomer();
		if ('text' === action) {
			window.location.href = 'mailto:' + encodeURIComponent(cust.email) + '?subject=' + encodeURIComponent('Ihre Anfrage bei MOTORSPORT24');
			return;
		}
		if ('preview-mail' === action || 'preview-view' === action) { // #11/C2: Vorschau in Lightbox (kein DB-Write, kein neuer Tab)
			var wantMail = ('preview-mail' === action);
			busy(true); st.textContent = 'Vorschau wird erzeugt …'; st.className = 'm24off-status';
			fetch(cfg.previewUrl || (cfg.rest + '/preview'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify(offerPayload()) })
				.then(function (r) { return r.json(); }).then(function (d) {
					busy(false);
					if (d && d.ok) {
						st.textContent = ''; st.className = 'm24off-status';
						openPreview(wantMail ? 'E-Mail-Vorschau' : 'Angebots-Link-Vorschau', wantMail ? d.mail_html : d.customer_html);
					} else { st.textContent = (d && (d.message || d.error)) || 'Vorschau fehlgeschlagen.'; st.className = 'm24off-status is-error'; }
				}).catch(function () { busy(false); st.textContent = 'Vorschau fehlgeschlagen.'; st.className = 'm24off-status is-error'; });
			return;
		}
		if ('draft' === action) {
			// Entwurf: nur E-Mail Pflicht (Positionen/Steuer optional → Weiterarbeiten möglich). Keine Mail.
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cust.email)) { st.textContent = 'Für einen Entwurf bitte eine gültige Kunden-E-Mail angeben.'; st.className = 'm24off-status is-error'; return; }
			busy(true); st.textContent = 'Entwurf wird gespeichert …'; st.className = 'm24off-status';
			fetch(cfg.rest + '/save-draft', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(offerPayload())
			}).then(function (r) { return r.json(); }).then(function (d) {
				busy(false);
				if (d && d.ok) { currentDraftId = d.draft_id || currentDraftId; st.innerHTML = esc((d.message || 'Entwurf gespeichert.') + ' Nummer wird erst beim Senden vergeben.') + backLinkHtml(); st.className = 'm24off-status is-ok'; }
				else { st.textContent = (d && (d.message || d.error)) || 'Entwurf konnte nicht gespeichert werden.'; st.className = 'm24off-status is-error'; }
			}).catch(function () { busy(false); st.textContent = 'Entwurf konnte nicht gespeichert werden.'; st.className = 'm24off-status is-error'; });
			return;
		}
		if (!items.length) { st.textContent = 'Bitte mindestens eine Position hinzufügen.'; st.className = 'm24off-status is-error'; return; }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cust.email)) { st.textContent = 'Bitte eine gültige Kunden-E-Mail angeben.'; st.className = 'm24off-status is-error'; return; }
		if (!taxMode) { st.textContent = 'Bitte den Steuerfall manuell wählen.'; st.className = 'm24off-status is-error'; return; }
		if ('b2c_eu_oss' === taxMode) {
			var rEl = $('[data-tax-rate]'), rv = rEl && rEl.value.trim();
			if (rv === '' || !(taxRate >= 0 && taxRate <= 27)) { st.textContent = 'Bitte einen USt-Satz (0–27 %) angeben.'; st.className = 'm24off-status is-error'; return; }
		}
		busy(true);
		st.textContent = 'Wird gesendet …'; st.className = 'm24off-status';
		fetch(cfg.rest + '/send', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify(offerPayload())
		}).then(function (r) { return r.json(); }).then(function (d) {
			busy(false);
			if (d && d.ok) {
				currentDraftId = 0; // Entwurf wurde zum verbindlichen Angebot → keine weitere Entwurf-Bindung
				st.innerHTML = esc(d.message + (d.register_link ? ' Konto-Link an den Gast verschickt.' : '')) + backLinkHtml();
				st.className = 'm24off-status is-ok';
			} else {
				st.textContent = (d && (d.message || d.error)) || 'Senden fehlgeschlagen.';
				st.className = 'm24off-status is-error';
			}
		}).catch(function () { busy(false); st.textContent = 'Senden fehlgeschlagen.'; st.className = 'm24off-status is-error'; });
	}

	/* ── Delegierte Events ── */
	document.addEventListener('input', function (e) {
		var t = e.target;
		if (t.matches('[data-qty]')) { items[+t.getAttribute('data-i')].qty = Math.max(1, parseInt(t.value, 10) || 1); renderSummary(); }
		else if (t.matches('[data-price]')) { var pi = +t.getAttribute('data-i'); var pn = parseNum(t.value); if (!isNaN(pn)) { var netv = ('brutto' === priceMode) ? Math.round((pn / 1.19) * 100) / 100 : pn; items[pi].unit_price = netv; var pc = $('[data-pcalc="' + pi + '"]'); if (pc) { var cv = ('netto' === priceMode) ? netv * 1.19 : netv; pc.innerHTML = esc(eur(cv)) + ' <em>' + ('netto' === priceMode ? 'brutto' : 'netto') + '</em>'; } renderSummary(); } }
		else if (t.matches('[data-title]')) { var ti = +t.getAttribute('data-i'); items[ti].title_de = t.value; items[ti].title = t.value; }
			else if (t.matches('[data-title-en]')) { items[+t.getAttribute('data-i')].title_en = t.value; }
			else if (t.matches('[data-title-en-cat]')) { items[+t.getAttribute('data-i')].title_en = t.value; } // #7: Katalog-EN live
			else if (t.matches('[data-extra-price]')) { var en = parseNum(t.value); if (!isNaN(en)) { extras[+t.getAttribute('data-extra-price')].amount = en; renderSummary(); } }
		else if (t.matches('[data-c="land"]')) { customer.land = t.value; cfg.custIsDrittland = cxIsDrittland(customer.land); if (cfg.custIsDrittland) { autoSuggestZoll(); } renderExtras(); renderSummary(); } // #6: Land VERBATIM übernehmen (ISO/Flagge nur intern via M24Country)
		else if (t.matches('[data-ship-land]')) { var si = +t.getAttribute('data-ship-land'); extras[si].land = cxLandToIso(t.value || ''); var lb = $('[data-ship-label="' + si + '"]'); if (lb) { lb.textContent = chipLabel(extras[si]); } renderSummary(); } // ohne Re-Render → Fokus bleibt
		else if (t.matches('[data-tax-rate]')) { taxRate = parseFloat(t.value) || 0; renderSummary(); }
		else if (t.matches('[data-salutation]')) { salTouched = true; }
			else if (t.matches('[data-cx-q]')) { clearTimeout(cxT); cxT = setTimeout(cxSearch, 250); }
			else if (t.matches('[data-palette-q]')) { clearTimeout(paletteT); paletteT = setTimeout(searchPalette, 250); }
			else if (t.matches('[data-palette-stdprice]')) { var spn = parseNum(t.value); if (!isNaN(spn)) { extras[+t.getAttribute('data-palette-stdprice')].amount = spn; renderSummary(); } }
	});
	document.addEventListener('change', function (e) {
		if (e.target.matches('[data-tax-mode]')) { setTaxMode(e.target.value); return; }
		if (e.target.matches('[data-ship-method]')) { extras[+e.target.getAttribute('data-ship-method')].method = e.target.value; renderExtras(); renderSummary(); return; }
		if (e.target.matches('[data-ship-incoterm]')) { var xi = +e.target.getAttribute('data-ship-incoterm'); extras[xi].incoterm = e.target.value; if ('CIF' === extras[xi].incoterm) { extras[xi].method = 'sea'; } renderExtras(); renderSummary(); return; } // #8: CIF nur Seefracht
	});
	document.addEventListener('click', function (e) {
		var t = e.target, el;
		if ((el = t.closest('[data-qdec]'))) { var a = +el.getAttribute('data-i'); items[a].qty = Math.max(1, (items[a].qty || 1) - 1); renderItems(); return; }
		if ((el = t.closest('[data-qinc]'))) { var b = +el.getAttribute('data-i'); items[b].qty = (items[b].qty || 1) + 1; renderItems(); return; }
		if ((el = t.closest('[data-rm]'))) { items.splice(+el.getAttribute('data-i'), 1); renderItems(); renderPalette(); return; }
		if ((el = t.closest('[data-ship-toggle]'))) { shipOpen = !shipOpen; renderExtras(); return; }
		if ((el = t.closest('[data-extra-toggle]'))) { var i3 = +el.getAttribute('data-extra-toggle'); extras[i3].on = !extras[i3].on; if (extras[i3].on && 'zoll' !== extras[i3].key) {} renderExtras(); renderSummary(); return; }
		if ((el = t.closest('[data-ptab]'))) { setPTab(el.getAttribute('data-ptab')); return; }
		if ((el = t.closest('[data-cat-add]'))) { addCatalog(el); return; }
		if ((el = t.closest('[data-std-add]'))) { addStandard(+el.getAttribute('data-std-add')); return; }
		if ((el = t.closest('[data-palette-freeadd]'))) { addFree(); return; }
		if ((el = t.closest('[data-dock-collapse]'))) { var pc = $('[data-poscard]'); dockCollapse(!(pc && pc.classList.contains('dock-collapsed'))); return; }
		if ((el = t.closest('[data-dock-open]'))) { dockOpen(true); return; }
		if ((el = t.closest('[data-dock-close]'))) { dockOpen(false); return; }
		if ((el = t.closest('[data-pvclose]')) || t.matches('[data-pvmodal]')) { closePreview(); return; } // C2: Vorschau schließen
		if ((el = t.closest('[data-pm]'))) { setPriceMode(el.getAttribute('data-pm')); return; } // B2: Netto/Brutto-Modus
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
		customer = { id: c.id || 0, name: c.name || '', email: c.email || '', kundentyp: ('b2b' === c.kundentyp ? 'b2b' : 'b2c'), land: (c.land || ''), firma: (c.firma || c.firmenname || '') }; // #6: Land verbatim · #9: Firma mitführen
		// A2: Kundenkarte zeigt {Firmenname bzw. Name} {Flagge} (Fallback Name → E-Mail), konsistent mit der Übersicht.
		var dispName = customer.firma || customer.name || customer.email || '—';
		var flag = (window.M24Country && customer.land) ? M24Country.getFlag(customer.land) : '';
		var nm = $('[data-cust-chip-name]'); if (nm) { nm.textContent = dispName + (flag ? ' ' + flag : ''); }
		var sub = $('[data-cust-chip-sub]'); if (sub) { sub.textContent = (customer.email || '') + ' · ' + ('b2b' === customer.kundentyp ? 'Geschäftskunde (B2B)' : 'Privat (B2C)') + (customer.land ? ' · ' + customer.land : ''); }
		var av = $('[data-cust-chip-av]'); if (av) { var pp = String(dispName).trim().split(/\s+/).slice(0, 2); av.textContent = pp.map(function (w) { return (w[0] || '').toUpperCase(); }).join('') || 'K'; }
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
					// A3: Treffer mit Pepp — Firmenname bzw. Name + Flagge, Avatar, klare Trennung.
					var title = (c.firma || c.name || c.email || '');
					var flag = (window.M24Country && c.land) ? M24Country.getFlag(c.land) : '';
					var ini = (title.trim().split(/\s+/).slice(0, 2).map(function (w) { return (w[0] || '').toUpperCase(); }).join('') || 'K');
					var sub = [c.email, ('b2b' === c.kundentyp ? 'Geschäftskunde (B2B)' : 'Privat (B2C)')].filter(Boolean).join(' · ');
					var row = document.createElement('button'); row.type = 'button'; row.className = 'm24off-cxres';
					row.innerHTML = '<span class="m24off-cxres-av">' + esc(ini) + '</span>'
						+ '<span class="m24off-cxres-main"><b>' + esc(title) + (flag ? ' ' + flag : '') + '</b><small>' + esc(sub) + '</small></span>';
					row.addEventListener('click', function () { cxLoadForEdit(c); });
					r.appendChild(row);
				});
			}).catch(function () {});
	}
	function cxCreate() {
		var st = $('[data-cx-status]');
		var g = function (k) { var el = $('[data-cx="' + k + '"]'); return el ? el.value.trim() : ''; };
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(g('email'))) { if (st) { st.textContent = 'Bitte eine gültige E-Mail angeben (Pflicht).'; st.className = 'm24off-cxstatus is-error'; } return; }
		var payload = { id: cxEditId || 0, kundentyp: cxKt, firmenname: g('firmenname'), vorname: g('vorname'), nachname: g('nachname'), strasse: g('strasse'), adresszusatz: g('adresszusatz'), plz: g('plz'), ort: g('ort'), land: g('land'), telefon: g('telefon'), email: g('email'), ustid: g('ustid'), eori: g('eori') }; // A1: Land VERBATIM
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
