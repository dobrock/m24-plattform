/**
 * M24 Plattform — Mega-Suche (Frontend)
 *
 * Haengt sich an die Theme-Suchfelder (input[name="s"]) und befuellt das Such-Overlay
 * des Themes (tagDiv: .tdb-drop-down-search-inner bzw. .td-drop-down-search) mit einem
 * breiten, 2-spaltigen Mega-Panel — KEIN zweites Overlay daruebergelegt. Das theme-eigene
 * Result-Feld (.tdb-aj-search) wird per CSS ausgeblendet, unser Panel uebernimmt dessen Platz.
 * Fuer Suchfelder ausserhalb eines Overlays gibt es einen positionierten Float-Fallback.
 *
 * Layout: links Fahrzeuge (oben) + Verschiedenes (unten), rechts Teile. Vanilla-JS,
 * Titel via textContent (kein XSS).
 */
(function () {
	'use strict';
	var CFG = window.M24Search || {};
	if (!CFG.restUrl) { return; }

	var DEBOUNCE = 220;
	var MIN = CFG.minChars || 2;
	var timer = null, controller = null, activeInput = null, floatBox = null;

	function el(t, c) { var e = document.createElement(t); if (c) { e.className = c; } return e; }

	// ── Rendering ──────────────────────────────────────────────────────────

	function renderItem(it) {
		var a = el('a', 'm24-sr-item');
		a.href = it.url || '#';
		if (it.thumb) {
			var im = el('span', 'm24-sr-thumb');
			var img = el('img'); img.src = it.thumb; img.alt = ''; img.loading = 'lazy';
			im.appendChild(img); a.appendChild(im);
		} else {
			a.appendChild(el('span', 'm24-sr-thumb m24-sr-thumb--empty'));
		}
		var b = el('span', 'm24-sr-body');
		var t = el('span', 'm24-sr-title'); t.textContent = it.title || ''; b.appendChild(t);
		var sub = el('span', 'm24-sr-sub');
		if (it.sold) { sub.textContent = CFG.i18n.sold; sub.className += ' m24-sr-sold'; }
		else if (it.price) { sub.textContent = it.price; sub.className += ' m24-sr-price'; }
		else if (it.meta) { sub.textContent = it.meta; }
		if (sub.textContent) { b.appendChild(sub); }
		a.appendChild(b);
		return a;
	}

	function section(key, g) {
		var sec = el('div', 'm24-sr-group m24-sr-group--' + key);
		var h = el('div', 'm24-sr-head'); h.textContent = g.label || key; sec.appendChild(h);
		g.items.forEach(function (it) { sec.appendChild(renderItem(it)); });
		var all = el('a', 'm24-sr-all');
		all.href = g.all_url || '#';
		all.textContent = (g.total && g.total > g.items.length)
			? CFG.i18n.allCount.replace('%d', g.total)
			: CFG.i18n.all;
		sec.appendChild(all);
		return sec;
	}

	function buildPanel(data) {
		var groups = (data && data.groups) ? data.groups : {};
		var wrap = el('div', 'm24-mega-wrap');
		// Linke Spalte: Fahrzeuge (oben) + Verschiedenes (unten)
		var left = el('div', 'm24-mega-col');
		['fahrzeuge', 'verschiedenes'].forEach(function (k) {
			if (groups[k] && groups[k].items && groups[k].items.length) { left.appendChild(section(k, groups[k])); }
		});
		// Rechte Spalte: Teile
		var right = el('div', 'm24-mega-col');
		if (groups.teile && groups.teile.items && groups.teile.items.length) { right.appendChild(section('teile', groups.teile)); }

		if (left.children.length) { wrap.appendChild(left); }
		if (right.children.length) { wrap.appendChild(right); }
		if (!wrap.children.length) {
			var none = el('div', 'm24-sr-none'); none.textContent = CFG.i18n.noResults; wrap.appendChild(none);
		}
		return wrap;
	}

	// ── Host: Theme-Overlay vs. Float-Fallback ──────────────────────────────

	function overlayHost(input) {
		if (!input.closest) { return null; }
		return input.closest('.tdb-drop-down-search-inner') || input.closest('.td-drop-down-search');
	}

	function showPanel(input, data) {
		var host = overlayHost(input);
		var mega;
		if (host) {
			mega = host.querySelector('.m24-mega');
			if (!mega) { mega = el('div', 'm24-mega'); host.appendChild(mega); }
		} else {
			if (!floatBox) {
				floatBox = el('div', 'm24-mega m24-mega--float');
				document.body.appendChild(floatBox);
				floatBox.addEventListener('mousedown', function (e) { e.preventDefault(); });
			}
			mega = floatBox;
		}
		mega.innerHTML = '';
		mega.appendChild(buildPanel(data));
		mega.classList.add('m24-mega--on');
		if (mega === floatBox) { positionFloat(input); }
	}

	function hidePanels() {
		var open = document.querySelectorAll('.m24-mega--on');
		for (var i = 0; i < open.length; i++) { open[i].classList.remove('m24-mega--on'); open[i].innerHTML = ''; }
	}

	function positionFloat(input) {
		if (!floatBox) { return; }
		var r = input.getBoundingClientRect();
		var w = Math.min(880, Math.max(320, window.innerWidth - 32));
		floatBox.style.position = 'fixed';
		floatBox.style.top = Math.round(r.bottom + 6) + 'px';
		floatBox.style.width = w + 'px';
		floatBox.style.left = Math.round(Math.min(r.left, window.innerWidth - w - 16)) + 'px';
	}

	// ── Fetch ───────────────────────────────────────────────────────────────

	function query(q, input) {
		if (controller) { controller.abort(); }
		controller = ('AbortController' in window) ? new AbortController() : null;
		fetch(CFG.restUrl + '?q=' + encodeURIComponent(q), {
			signal: controller ? controller.signal : undefined,
			headers: { 'Accept': 'application/json' }
		})
			.then(function (r) { return r.json(); })
			.then(function (data) { if (input === activeInput && input.value.trim().length >= MIN) { showPanel(input, data); } })
			.catch(function () { /* abgebrochen/Netz — still */ });
	}

	function onInput(e) {
		activeInput = e.target;
		var q = activeInput.value.trim();
		if (timer) { clearTimeout(timer); }
		if (q.length < MIN) { hidePanels(); return; }
		timer = setTimeout(function () { query(q, activeInput); }, DEBOUNCE);
	}

	function bind(input) {
		if (input.__m24) { return; }
		input.__m24 = true;
		input.setAttribute('autocomplete', 'off');
		input.addEventListener('input', onInput);
		input.addEventListener('focus', function (e) { activeInput = e.target; });
	}

	function bindAll() {
		var inputs = document.querySelectorAll('input[name="s"]');
		for (var i = 0; i < inputs.length; i++) { bind(inputs[i]); }
	}

	document.addEventListener('DOMContentLoaded', bindAll);
	bindAll();
	if ('MutationObserver' in window) {
		new MutationObserver(bindAll).observe(document.documentElement, { childList: true, subtree: true });
	}

	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hidePanels(); } });
	window.addEventListener('resize', function () {
		if (floatBox && floatBox.classList.contains('m24-mega--on') && activeInput) { positionFloat(activeInput); }
	});
	// Outside-Click schliesst nur den Float-Fallback (das Overlay schliesst das Theme selbst).
	document.addEventListener('click', function (e) {
		if (floatBox && floatBox.classList.contains('m24-mega--on') && !floatBox.contains(e.target) && e.target !== activeInput) {
			floatBox.classList.remove('m24-mega--on'); floatBox.innerHTML = '';
		}
	});
})();
