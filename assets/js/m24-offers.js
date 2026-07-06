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

	/* ── State ── */
	var items  = [];  // {teil_id,title,art_nr,qty,unit_price,tax25a,custom,free,variant,thumb}
	var extras = (cfg.presets || []).map(function (p) { return { key: p.key || '', label: p.label, amount: parseFloat(p.amount) || 0, on: false }; });
	var taxMode = '', taxRate = 0, offerLang = 'de';
	var modell = (cfg.src && cfg.src.src_modell) || '';
	var customer = cfg.customer || { name: '', email: '', kundentyp: 'b2c', land: '' };
	var LANDS = cfg.lands || {};
	function landName(iso) { iso = (iso || '').toUpperCase(); return LANDS[iso] || iso || ''; }

	/* ── Positionen (Karten + Stepper) ── */
	function renderItems() {
		var box = $('[data-items]'); if (!box) { return; }
		box.innerHTML = '';
		if (!items.length) { box.innerHTML = '<p class="m24off-empty2">Noch keine Positionen — über die Chips unten hinzufügen.</p>'; }
		items.forEach(function (it, i) {
			var row = document.createElement('div');
			row.className = 'm24off-pos';
			var titleHtml = it.free
				? '<input type="text" class="m24off-pt-in" value="' + esc(it.title) + '" data-i="' + i + '" data-title placeholder="Bezeichnung der Position">'
				: '<div class="m24off-pt">' + esc(it.title) + '</div>';
			var metaHtml = it.art_nr ? '<div class="m24off-pa">Art.-Nr. ' + esc(it.art_nr) + '</div>' : '';
			var varHtml = it.variant ? '<div class="m24off-pa m24off-pvar">Variante: ' + esc(it.variant) + '</div>' : '';
			row.innerHTML =
				(it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="m24off-pos-ph"></span>')
				+ '<div class="m24off-pos-main">' + titleHtml + metaHtml + varHtml + '</div>'
				+ '<div class="m24off-qty2"><button type="button" data-i="' + i + '" data-qdec aria-label="weniger">−</button>'
				+ '<input type="number" min="1" value="' + it.qty + '" data-i="' + i + '" data-qty inputmode="numeric">'
				+ '<button type="button" data-i="' + i + '" data-qinc aria-label="mehr">+</button></div>'
				+ '<div class="m24off-pprice"><input type="number" step="0.01" value="' + it.unit_price + '" data-i="' + i + '" data-price inputmode="decimal">'
				+ '<div class="m24off-bru2" data-brutto>= ' + eur((it.unit_price || 0) * 1.19) + ' brutto</div></div>'
				+ '<button type="button" class="m24off-posx" data-i="' + i + '" data-rm aria-label="Position entfernen">✕</button>';
			box.appendChild(row);
		});
		renderSummary();
	}

	/* ── Standard-Positionen als Chips (Nebenkosten + Freitext + Katalog) ── */
	function chipLabel(ex) {
		if (ex.key === 'versand') { var ln = landName(customer.land); return 'Versicherter Versand' + (ln ? ' ' + ln : ''); }
		return ex.label;
	}
	function renderStdRow() {
		var box = $('[data-stdrow]'); if (!box) { return; }
		box.innerHTML = '';
		extras.forEach(function (ex, i) {
			var s = document.createElement('span');
			s.className = 'm24off-std' + (ex.on ? ' added' : '') + ('zoll' === ex.key ? ' zoll' : '');
			s.setAttribute('data-extra-toggle', i);
			s.innerHTML = ex.on
				? '✓ ' + esc(chipLabel(ex)) + ' <span class="m24off-std-amt" data-extra-amt="' + i + '" title="Betrag ändern">' + eur(ex.amount) + '</span>'
				: '+ ' + esc(chipLabel(ex)) + ' <span class="m24off-std-amt-pre">' + eur(ex.amount) + '</span>';
			box.appendChild(s);
		});
		var free = document.createElement('span'); free.className = 'm24off-std'; free.setAttribute('data-add-free', ''); free.textContent = '+ Freie Position …'; box.appendChild(free);
		var kat = document.createElement('span'); kat.className = 'm24off-std'; kat.setAttribute('data-add-pos', ''); kat.textContent = '+ Teil aus Katalog suchen'; box.appendChild(kat);
	}
	function editExtraAmount(i, spanEl) {
		var ex = extras[i]; if (!ex) { return; }
		var inp = document.createElement('input');
		inp.type = 'number'; inp.step = '0.01'; inp.value = ex.amount; inp.className = 'm24off-std-amtinput';
		spanEl.replaceWith(inp); inp.focus(); inp.select();
		function commit() { ex.amount = parseFloat(inp.value) || 0; renderStdRow(); renderSummary(); }
		inp.addEventListener('blur', commit);
		inp.addEventListener('keydown', function (e) { if ('Enter' === e.key) { e.preventDefault(); inp.blur(); } });
	}

	/* ── Steuer ── */
	function setTaxMode(mode) {
		taxMode = mode;
		var m = cfg.taxModes[mode];
		var oss = $('[data-oss]'); if (oss) { oss.hidden = !(m && m.rate === null); }
		var tn = $('[data-tax-note]'); if (tn) { tn.textContent = m ? m.note : ''; }
		$$('[data-tax-seg] [data-txm]').forEach(function (s) { s.classList.toggle('on', s.getAttribute('data-txm') === mode); });
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

	/* ── Angebotssprache (Kopf + Konditionen synchron) ── */
	function setLang(l) {
		offerLang = ('en' === l) ? 'en' : 'de';
		$$('[data-langsw] [data-lang]').forEach(function (s) { s.classList.toggle('on', s.getAttribute('data-lang') === offerLang); });
		$$('[data-langseg] [data-olang]').forEach(function (s) { s.classList.toggle('on', s.getAttribute('data-olang') === offerLang); });
	}

	/* ── Teile-Picker ── */
	var cat = '';
	function openPicker() { var p = $('[data-picker]'); if (p) { p.hidden = false; } searchParts(); }
	function setCat(btn) { $$('[data-cat]').forEach(function (b) { b.classList.toggle('is-on', b === btn); }); cat = btn.getAttribute('data-cat'); searchParts(); }
	var searchT;
	function searchParts() {
		var q = ($('[data-picker-q]') && $('[data-picker-q]').value || '').trim();
		var url = cfg.rest + '/parts?modell=' + encodeURIComponent(modell) + '&cat=' + encodeURIComponent(cat) + '&q=' + encodeURIComponent(q);
		fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				var list = $('[data-picker-list]'); if (!list) { return; }
				list.innerHTML = '';
				var arr = Array.isArray(d) ? d : ((d && (d.items || (d.data && d.data.items))) || []);
				arr.forEach(function (it) {
					var row = document.createElement('div');
					row.className = 'm24off-pick';
					row.innerHTML = (it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="m24off-pick-ph"></span>')
						+ '<div class="m24off-pick-info"><span>' + esc(it.title) + '</span>'
						+ (it.art_nr ? '<small>Art.-Nr.: ' + esc(it.art_nr) + '</small>' : '')
						+ '<small>' + (it.price != null ? eur(it.price) : 'Preis auf Anfrage') + (it.tax25a ? ' · §25a' : '') + '</small></div>'
						+ '<button type="button" class="m24off-pick-add" data-pick '
						+ 'data-id="' + it.id + '" data-title="' + esc(it.title) + '" data-art="' + esc(it.art_nr || '') + '" '
						+ 'data-thumb="' + esc(it.thumb || '') + '" '
						+ 'data-price="' + (it.price != null ? it.price : 0) + '" data-25a="' + (it.tax25a ? 1 : 0) + '">+</button>';
					list.appendChild(row);
				});
				if (!arr.length) { list.innerHTML = '<p class="m24off-note">Keine Teile gefunden.</p>'; }
			});
	}
	function addFromPick(btn) {
		items.push({
			teil_id: parseInt(btn.getAttribute('data-id'), 10) || 0,
			title: btn.getAttribute('data-title') || '',
			art_nr: btn.getAttribute('data-art') || '',
			thumb: btn.getAttribute('data-thumb') || '',
			qty: 1,
			// Artikelpreis ist BRUTTO (inkl. 19 %); unit_price ist die NETTO-Basis (Steuer je Modus oben drauf).
			unit_price: Math.round(((parseFloat(btn.getAttribute('data-price')) || 0) / 1.19) * 100) / 100,
			tax25a: btn.getAttribute('data-25a') === '1',
			custom: false
		});
		renderItems();
	}

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
				customer: cust, items: items, extras: extras,
				tax_mode: taxMode, tax_rate: taxRate, lang: offerLang,
				delivery_time: ($('[data-delivery]') || {}).value || '',
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
		else if (t.matches('[data-price]')) { var pi = +t.getAttribute('data-i'); items[pi].unit_price = parseFloat(t.value) || 0; var bw = t.parentNode.querySelector('[data-brutto]'); if (bw) { bw.textContent = '= ' + eur(items[pi].unit_price * 1.19) + ' brutto'; } renderSummary(); }
		else if (t.matches('[data-title]')) { items[+t.getAttribute('data-i')].title = t.value; }
		else if (t.matches('[data-tax-rate]')) { taxRate = parseFloat(t.value) || 0; renderSummary(); }
		else if (t.matches('[data-picker-q]')) { clearTimeout(searchT); searchT = setTimeout(searchParts, 250); }
	});
	document.addEventListener('click', function (e) {
		var t = e.target, el;
		if ((el = t.closest('[data-qdec]'))) { var a = +el.getAttribute('data-i'); items[a].qty = Math.max(1, (items[a].qty || 1) - 1); renderItems(); return; }
		if ((el = t.closest('[data-qinc]'))) { var b = +el.getAttribute('data-i'); items[b].qty = (items[b].qty || 1) + 1; renderItems(); return; }
		if ((el = t.closest('[data-rm]'))) { items.splice(+el.getAttribute('data-i'), 1); renderItems(); return; }
		if ((el = t.closest('[data-extra-amt]'))) { editExtraAmount(+el.getAttribute('data-extra-amt'), el); return; }
		if ((el = t.closest('[data-extra-toggle]'))) { var i3 = +el.getAttribute('data-extra-toggle'); extras[i3].on = !extras[i3].on; renderStdRow(); renderSummary(); return; }
		if ((el = t.closest('[data-add-free]'))) { items.push({ teil_id: 0, title: '', art_nr: '', qty: 1, unit_price: 0, tax25a: false, custom: false, free: true }); renderItems(); return; }
		if ((el = t.closest('[data-add-pos]'))) { openPicker(); return; }
		if ((el = t.closest('[data-pick]'))) { addFromPick(el); return; }
		if ((el = t.closest('[data-picker-close]'))) { var p = $('[data-picker]'); if (p) { p.hidden = true; } return; }
		if ((el = t.closest('[data-cat]'))) { setCat(el); return; }
		if ((el = t.closest('[data-picker-modellchg]'))) { var m = prompt('Modell-Slug (z. B. z4-gt3):', modell); if (m !== null) { modell = m.trim(); var pm = $('[data-picker-modell]'); if (pm) { pm.textContent = modell; } searchParts(); } return; }
		if ((el = t.closest('[data-txm]'))) { setTaxMode(el.getAttribute('data-txm')); return; }
		if ((el = t.closest('[data-lang]'))) { setLang(el.getAttribute('data-lang')); return; }
		if ((el = t.closest('[data-olang]'))) { setLang(el.getAttribute('data-olang')); return; }
		if ((el = t.closest('[data-cust-edit]'))) { e.preventDefault(); var ed = $('[data-kunde-edit]'); if (ed) { ed.hidden = !ed.hidden; } return; }
		if ((el = t.closest('[data-kt]'))) { segKT(el); return; }
		if ((el = t.closest('[data-action]'))) { doAction(el.getAttribute('data-action')); return; }
	});

	/* ── Prefill (aus Anfrage/Garage) ── */
	if (cfg.prefill && cfg.prefill.items && cfg.prefill.items.length) {
		items = cfg.prefill.items.map(function (it) {
			return {
				teil_id: parseInt(it.teil_id, 10) || 0,
				title: it.title || '', art_nr: it.art_nr || '', thumb: it.thumb || '',
				variant: it.variant || '',
				qty: parseInt(it.qty, 10) || 1,
				unit_price: parseFloat(it.unit_price) || 0,
				tax25a: !!it.tax25a, custom: !!it.custom
			};
		});
		var dEl = $('[data-delivery]'); if (dEl && cfg.prefill.delivery) { dEl.value = cfg.prefill.delivery; }
		if (cfg.prefill.tax_mode) { setTaxMode(cfg.prefill.tax_mode); }
		if (cfg.prefill.tax_rate) { taxRate = parseFloat(cfg.prefill.tax_rate) || 0; var rr = $('[data-tax-rate]'); if (rr) { rr.value = cfg.prefill.tax_rate; } }
	}

	setLang('de');
	renderItems();
	renderStdRow();
	renderSummary();
})();
