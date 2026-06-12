/**
 * M24 Plattform — Gruppierte Suche (Frontend-Dropdown)
 *
 * Haengt sich an die Theme-Suchfelder (input[name="s"]), fragt beim Tippen den
 * REST-Endpoint /wp-json/m24/v1/search ab und rendert ein eigenes, gruppiertes
 * Dropdown (Fahrzeuge / Teile / Verschiedenes) mit „Alle Ergebnisse anzeigen"-Links.
 * Reines Vanilla-JS, kein jQuery. Titel werden via textContent gesetzt (kein XSS).
 */
(function () {
	'use strict';
	var CFG = window.M24Search || {};
	if (!CFG.restUrl) { return; }

	var DEBOUNCE = 220;
	var box = null;        // Dropdown-Element
	var activeInput = null;
	var timer = null;
	var controller = null; // AbortController fuer laufende Fetches

	function el(tag, cls) { var e = document.createElement(tag); if (cls) { e.className = cls; } return e; }

	function ensureBox() {
		if (box) { return box; }
		box = el('div', 'm24-search-dd');
		box.setAttribute('role', 'listbox');
		box.style.display = 'none';
		document.body.appendChild(box);
		box.addEventListener('mousedown', function (e) { e.preventDefault(); }); // Blur vor Klick verhindern
		return box;
	}

	function position() {
		if (!box || !activeInput) { return; }
		var r = activeInput.getBoundingClientRect();
		box.style.position = 'fixed';
		box.style.top = Math.round(r.bottom + 6) + 'px';
		box.style.left = Math.round(r.left) + 'px';
		box.style.width = Math.max(280, Math.round(r.width)) + 'px';
	}

	function hide() { if (box) { box.style.display = 'none'; box.innerHTML = ''; } }

	function show() { ensureBox(); position(); box.style.display = 'block'; }

	function renderItem(group, it) {
		var a = el('a', 'm24-sr-item');
		a.href = it.url || '#';
		if (it.thumb) {
			var im = el('span', 'm24-sr-thumb');
			var img = el('img'); img.src = it.thumb; img.alt = ''; img.loading = 'lazy';
			im.appendChild(img); a.appendChild(im);
		} else {
			a.appendChild(el('span', 'm24-sr-thumb m24-sr-thumb--empty'));
		}
		var body = el('span', 'm24-sr-body');
		var t = el('span', 'm24-sr-title'); t.textContent = it.title || ''; body.appendChild(t);
		var sub = el('span', 'm24-sr-sub');
		if (it.sold) { sub.textContent = CFG.i18n.sold; sub.className += ' m24-sr-sold'; }
		else if (it.price) { sub.textContent = it.price; sub.className += ' m24-sr-price'; }
		else if (it.meta) { sub.textContent = it.meta; }
		if (sub.textContent) { body.appendChild(sub); }
		a.appendChild(body);
		return a;
	}

	function render(data) {
		ensureBox();
		box.innerHTML = '';
		var groups = data && data.groups ? data.groups : {};
		var keys = Object.keys(groups);
		if (!keys.length) {
			var none = el('div', 'm24-sr-none'); none.textContent = CFG.i18n.noResults;
			box.appendChild(none); show(); return;
		}
		keys.forEach(function (key) {
			var g = groups[key];
			if (!g.items || !g.items.length) { return; }
			var sec = el('div', 'm24-sr-group m24-sr-group--' + key);
			var h = el('div', 'm24-sr-head'); h.textContent = g.label || key; sec.appendChild(h);
			g.items.forEach(function (it) { sec.appendChild(renderItem(key, it)); });
			var all = el('a', 'm24-sr-all');
			all.href = g.all_url || '#';
			all.textContent = (g.total && g.total > g.items.length)
				? CFG.i18n.allCount.replace('%d', g.total)
				: CFG.i18n.all;
			sec.appendChild(all);
			box.appendChild(sec);
		});
		show();
	}

	function query(q) {
		if (controller) { controller.abort(); }
		controller = ('AbortController' in window) ? new AbortController() : null;
		fetch(CFG.restUrl + '?q=' + encodeURIComponent(q), {
			signal: controller ? controller.signal : undefined,
			headers: { 'Accept': 'application/json' }
		})
			.then(function (r) { return r.json(); })
			.then(function (data) { if (activeInput && activeInput.value.trim().length >= (CFG.minChars || 2)) { render(data); } })
			.catch(function () { /* abgebrochen/Netz — still */ });
	}

	function onInput(e) {
		activeInput = e.target;
		var q = activeInput.value.trim();
		if (timer) { clearTimeout(timer); }
		if (q.length < (CFG.minChars || 2)) { hide(); return; }
		timer = setTimeout(function () { query(q); }, DEBOUNCE);
	}

	function bind(input) {
		if (input.__m24bound) { return; }
		input.__m24bound = true;
		input.setAttribute('autocomplete', 'off');
		input.addEventListener('input', onInput);
		input.addEventListener('focus', function (e) {
			activeInput = e.target;
			if (e.target.value.trim().length >= (CFG.minChars || 2) && box && box.children.length) { show(); }
		});
	}

	function bindAll() {
		var inputs = document.querySelectorAll('input[name="s"]');
		for (var i = 0; i < inputs.length; i++) { bind(inputs[i]); }
	}

	// Auch spaeter (per JS) eingefuegte Suchfelder erfassen (tagDiv-Overlay).
	document.addEventListener('DOMContentLoaded', bindAll);
	bindAll();
	if ('MutationObserver' in window) {
		new MutationObserver(bindAll).observe(document.documentElement, { childList: true, subtree: true });
	}

	document.addEventListener('click', function (e) {
		if (box && box.style.display === 'block' && !box.contains(e.target) && e.target !== activeInput) { hide(); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hide(); } });
	window.addEventListener('resize', function () { if (box && box.style.display === 'block') { position(); } });
	window.addEventListener('scroll', function () { if (box && box.style.display === 'block') { position(); } }, true);
})();
