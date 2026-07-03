/**
 * M24 Angebots-Workflow v1 — Operator-Modal A1 + Teile-Picker (nur Admin). Config: window.M24Offers.
 * Positionen editierbar, Zusatz-Presets an/aus, MANUELLE Steuer (Preview), Picker (Suche/Kategorie/Modell),
 * Senden → REST. In-Page-Patterns.
 */
(function () {
	'use strict';
	var cfg = window.M24Offers || {};
	if (!cfg.rest) { return; }
	var $ = function (s, r) { return (r || document).querySelector(s); };
	var items = [];   // {teil_id,title,art_nr,qty,unit_price,tax25a}
	var extras = (cfg.presets || []).map(function (p) { return { label: p.label, amount: parseFloat(p.amount) || 0, on: false }; });
	var taxMode = '', taxRate = 0;
	var modell = (cfg.src && cfg.src.src_modell) || '';

	function eur(v) { return (Math.round(v * 100) / 100).toFixed(2).replace('.', ',') + ' €'; }

	/* ── Positionen ── */
	function renderItems() {
		var box = $('[data-items]'); box.innerHTML = '';
		items.forEach(function (it, i) {
			var row = document.createElement('div');
			row.className = 'm24off-item';
			row.innerHTML = '<div class="m24off-item-main"><span class="m24off-item-title">' + esc(it.title) + '</span>'
				+ (it.art_nr ? '<span class="m24off-item-art">Art.-Nr.: ' + esc(it.art_nr) + '</span>' : '')
				+ '<label class="m24off-item-25a"><input type="checkbox"' + (it.tax25a ? ' checked' : '') + ' data-i="' + i + '" data-25a> §25a</label>'
				+ '<label class="m24off-item-25a"><input type="checkbox"' + (it.custom ? ' checked' : '') + ' data-i="' + i + '" data-custom> Sonderanfertigung (kein Widerruf)</label></div>'
				+ '<div class="m24off-item-nums"><input type="number" min="1" value="' + it.qty + '" data-i="' + i + '" data-qty class="m24off-qty">'
				+ '<input type="number" step="0.01" value="' + it.unit_price + '" data-i="' + i + '" data-price class="m24off-price"> €'
				+ '<button type="button" class="m24off-item-x" data-i="' + i + '" data-rm aria-label="Entfernen">&times;</button></div>';
			box.appendChild(row);
		});
		recalc();
	}
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

	document.addEventListener('input', function (e) {
		var t = e.target;
		if (t.matches('[data-qty]')) { items[+t.getAttribute('data-i')].qty = Math.max(1, parseInt(t.value, 10) || 1); recalc(); }
		else if (t.matches('[data-price]')) { items[+t.getAttribute('data-i')].unit_price = parseFloat(t.value) || 0; recalc(); }
		else if (t.matches('[data-tax-rate]')) { taxRate = parseFloat(t.value) || 0; recalc(); }
	});
	document.addEventListener('change', function (e) {
		var t = e.target;
		if (t.matches('[data-25a]')) { items[+t.getAttribute('data-i')].tax25a = t.checked; recalc(); }
		else if (t.matches('[data-custom]')) { items[+t.getAttribute('data-i')].custom = t.checked; }
		else if (t.matches('[data-extra-on]')) { extras[+t.getAttribute('data-i')].on = t.checked; recalc(); }
		else if (t.matches('[data-extra-amt]')) { extras[+t.getAttribute('data-i')].amount = parseFloat(t.value) || 0; recalc(); }
		else if (t.matches('[data-tax-mode]')) { setTaxMode(t.value); }
	});
	document.addEventListener('click', function (e) {
		var t = e.target;
		if (t.matches('[data-rm]')) { items.splice(+t.getAttribute('data-i'), 1); renderItems(); }
		else if (t.matches('[data-add-pos]')) { openPicker(); }
		else if (t.matches('[data-kt]')) { segKT(t); }
		else if (t.matches('[data-picker-close]')) { $('[data-picker]').hidden = true; }
		else if (t.matches('[data-cat]')) { setCat(t); }
		else if (t.matches('[data-picker-modellchg]')) { var m = prompt('Modell-Slug (z. B. z4-gt3):', modell); if (m !== null) { modell = m.trim(); $('[data-picker-modell]').textContent = modell; searchParts(); } }
		else if (t.matches('[data-pick]')) { addFromPick(t); }
		else if (t.matches('[data-action]')) { doAction(t.getAttribute('data-action')); }
	});

	function segKT(btn) {
		var box = $('[data-c-kundentyp]');
		[].forEach.call(box.querySelectorAll('[data-kt]'), function (b) { b.classList.toggle('is-on', b === btn); });
	}

	/* ── Zusatz-Presets ── */
	function renderExtras() {
		var box = $('[data-extras]'); box.innerHTML = '';
		extras.forEach(function (ex, i) {
			var r = document.createElement('label');
			r.className = 'm24off-extra';
			r.innerHTML = '<input type="checkbox"' + (ex.on ? ' checked' : '') + ' data-i="' + i + '" data-extra-on>'
				+ '<span class="m24off-extra-l">' + esc(ex.label) + '</span>'
				+ '<input type="number" step="0.01" value="' + ex.amount + '" data-i="' + i + '" data-extra-amt class="m24off-price"> €';
			box.appendChild(r);
		});
	}

	/* ── Steuer ── */
	function setTaxMode(mode) {
		taxMode = mode;
		var m = cfg.taxModes[mode];
		var oss = $('[data-oss]');
		oss.hidden = !(m && m.rate === null);
		$('[data-tax-note]').textContent = m ? m.note : '';
		recalc();
	}
	function rate() {
		var m = cfg.taxModes[taxMode];
		if (!m) { return 0; }
		return m.rate === null ? Math.max(0, taxRate) : m.rate;
	}

	/* ── Summen ── */
	function recalc() {
		var net = 0, st25a = 0;
		items.forEach(function (it) { var l = (it.unit_price || 0) * Math.max(1, it.qty || 1); if (it.tax25a) { st25a += l; } else { net += l; } });
		extras.forEach(function (ex) { if (ex.on) { net += ex.amount || 0; } });
		var r = rate(), tax = Math.round(net * r) / 100;
		$('[data-sum-net]').textContent = eur(net + st25a);
		var w25 = $('[data-sum-25a-wrap]'); w25.hidden = st25a <= 0; $('[data-sum-25a]').textContent = eur(st25a);
		var wtax = $('[data-sum-tax-wrap]'); wtax.hidden = tax <= 0; $('[data-sum-tax]').textContent = eur(tax);
		$('[data-tax-label]').textContent = 'USt ' + (r % 1 ? r.toFixed(1) : r) + ' %';
		$('[data-sum-total]').textContent = eur(net + tax + st25a);
	}

	/* ── Teile-Picker ── */
	var cat = '';
	function openPicker() { $('[data-picker]').hidden = false; searchParts(); }
	function setCat(btn) { [].forEach.call(document.querySelectorAll('[data-cat]'), function (b) { b.classList.toggle('is-on', b === btn); }); cat = btn.getAttribute('data-cat'); searchParts(); }
	var searchT;
	document.addEventListener('input', function (e) { if (e.target.matches('[data-picker-q]')) { clearTimeout(searchT); searchT = setTimeout(searchParts, 250); } });
	function searchParts() {
		var q = ($('[data-picker-q]') && $('[data-picker-q]').value || '').trim();
		var url = cfg.rest + '/parts?modell=' + encodeURIComponent(modell) + '&cat=' + encodeURIComponent(cat) + '&q=' + encodeURIComponent(q);
		fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				var list = $('[data-picker-list]'); list.innerHTML = '';
				// Trefferliste robust aus response.items lesen (Fallback: bare Array bzw. data.items).
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
			qty: 1,
			unit_price: parseFloat(btn.getAttribute('data-price')) || 0,
			tax25a: btn.getAttribute('data-25a') === '1',
			custom: false
		});
		renderItems();
	}

	/* ── Senden ── */
	function collectCustomer() {
		var kt = $('[data-c-kundentyp] .is-on');
		return {
			name: ($('[data-c="name"]') || {}).value || '',
			email: ($('[data-c="email"]') || {}).value || '',
			kundentyp: kt ? kt.getAttribute('data-kt') : 'b2c',
			land: ($('[data-c="land"]') || {}).value || ''
		};
	}
	function doAction(action) {
		var st = $('[data-status]');
		var cust = collectCustomer();
		if (action === 'text') {
			// „Mit Text antworten": einfacher mailto-Fallback (Phase 1).
			window.location.href = 'mailto:' + encodeURIComponent(cust.email) + '?subject=' + encodeURIComponent('Ihre Anfrage bei MOTORSPORT24');
			return;
		}
		if (!items.length) { st.textContent = 'Bitte mindestens eine Position hinzufügen.'; st.className = 'm24off-status is-error'; return; }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cust.email)) { st.textContent = 'Bitte eine gültige Kunden-E-Mail angeben.'; st.className = 'm24off-status is-error'; return; }
		if (!taxMode) { st.textContent = 'Bitte den Steuerfall manuell wählen.'; st.className = 'm24off-status is-error'; return; }
		if (taxMode === 'b2c_eu_oss') {
			var rEl = $('[data-tax-rate]'); var rv = rEl && rEl.value.trim();
			if (rv === '' || !(taxRate >= 0 && taxRate <= 27)) { st.textContent = 'Bitte einen USt-Satz (0–27 %) angeben.'; st.className = 'm24off-status is-error'; return; }
		}
		var btn = $('[data-action="send"]'); btn.disabled = true;
		st.textContent = 'Wird gesendet …'; st.className = 'm24off-status';
		fetch(cfg.rest + '/send', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify({
				customer: cust, items: items, extras: extras,
				tax_mode: taxMode, tax_rate: taxRate,
				delivery_time: ($('[data-delivery]') || {}).value || '',
				src: cfg.src || {}
			})
		}).then(function (r) { return r.json(); }).then(function (d) {
			btn.disabled = false;
			if (d && d.ok) {
				st.textContent = d.message + (d.register_link ? ' Konto-Link an den Gast verschickt.' : '');
				st.className = 'm24off-status is-ok';
			} else {
				st.textContent = (d && (d.message || d.error)) || 'Senden fehlgeschlagen.';
				st.className = 'm24off-status is-error';
			}
		}).catch(function () { btn.disabled = false; st.textContent = 'Senden fehlgeschlagen.'; st.className = 'm24off-status is-error'; });
	}

	renderItems();
	renderExtras();
})();
