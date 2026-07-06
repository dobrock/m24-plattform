/**
 * M24 Garage-Einstieg (Paket G, Entwurf 1) — Slide-Tab + Schnellansicht-Panel. Ersetzt den roten Kreis.
 * Funktioniert für GAST (localStorage-Garage m24_guest_garage) UND eingeloggt (Server-Garage /garage/cart).
 * Zähler = Fahrzeuge + Teile gesamt. Beim Hinzufügen (♡) pulst der Tab + Zähler zählt hoch (ersetzt Toast).
 */
(function () {
	'use strict';
	var cfg = window.M24GarageCart || {};
	var root = document.getElementById('m24gt');
	if (!root || !cfg.rest) { return; }
	var $ = function (s) { return root.querySelector(s); };
	var tab = $('[data-m24gt-open]'), panel = $('[data-m24gt-panel]'), ov = $('[data-m24gt-overlay]');
	var cntEl = $('[data-m24gt-cnt]'), subEl = $('[data-m24gt-sub]'), itemsEl = $('[data-m24gt-items]'), sumEl = $('[data-m24gt-sum]');
	var prev = -1;

	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	function guestItems() {
		try {
			var r = localStorage.getItem(cfg.guestKey || 'm24_guest_garage'); var a = r ? JSON.parse(r) : [];
			if (!Array.isArray(a)) { return []; }
			return a.map(function (x) { return ('object' === typeof x && x) ? x : { id: parseInt(x, 10) || 0, q: 1 }; }).filter(function (o) { return o.id > 0; });
		} catch (e) { return []; }
	}

	function load() {
		if (cfg.loggedIn) {
			fetch(cfg.rest, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
				.then(function (r) { return r.json(); }).then(render).catch(function () {});
		} else {
			var g = guestItems();
			if (!g.length) { render({ items: [], grand_fmt: '0,00 €' }); return; }
			var ids = g.map(function (o) { return o.id; });
			fetch(cfg.resolve + '?ids=' + ids.join(','), { credentials: 'same-origin' })
				.then(function (r) { return r.json(); }).then(function (d) {
					// Basis-Details aus dem Katalog + Gast-Variante/Menge je Position überlagern (eine Zeile je Gast-Item).
					var base = {}; ((d && d.items) || []).forEach(function (it) { base[it.post_id] = it; });
					var items = g.map(function (o) {
						var b = base[o.id]; if (!b) { return null; }
						var it = {}; for (var k in b) { if (Object.prototype.hasOwnProperty.call(b, k)) { it[k] = b[k]; } }
						if (o.vl) { it.variant = o.vl; if (o.va) { it.artnr = o.va; } }
						it.qty = o.q || 1;
						return it;
					}).filter(Boolean);
					render({ items: items, grand_fmt: (d && d.grand_fmt) || '0,00 €' });
				}).catch(function () {});
		}
	}

	function render(d) {
		var items = (d && d.items) || [], count = items.length;
		if (cntEl) { cntEl.textContent = count; }
		var gno = cfg.garageNo ? ' · ' + cfg.garageNo : '';
		if (subEl) { subEl.textContent = count + ' Position' + (1 === count ? '' : 'en') + gno; }
		if (sumEl) { sumEl.innerHTML = (d && d.grand_fmt) ? esc(d.grand_fmt) : '0,00&nbsp;€'; }
		if (itemsEl) {
			itemsEl.innerHTML = count ? '' : '<p class="m24gt-empty">Deine Garage ist noch leer.</p>';
			items.forEach(function (it) {
				var row = document.createElement('a');
				row.className = 'm24gt-it'; row.href = it.url || '#';
				row.innerHTML = (it.thumb ? '<img src="' + esc(it.thumb) + '" alt="">' : '<span class="m24gt-thumb-ph"></span>')
					+ '<div class="m24gt-it-main"><div class="t">' + esc(it.title) + '</div>'
					+ '<div class="m24gt-it-meta">' + (it.artnr ? 'Art.-Nr. ' + esc(it.artnr) + ' · ' : '') + '×' + (it.qty || 1) + (it.variant ? ' · ' + esc(it.variant) : '') + '</div></div>'
					+ '<div class="p">' + esc(it.line_fmt || it.unit_fmt || 'auf Anfrage') + '</div>';
				itemsEl.appendChild(row);
			});
		}
		root.hidden = count <= 0;              // leere Garage → Tab weg
		if (prev >= 0 && count > prev) { pulse(); } // Zuwachs → Tab pulst
		prev = count;
	}
	function pulse() { if (!tab) { return; } tab.classList.remove('is-pulse'); void tab.offsetWidth; tab.classList.add('is-pulse'); }

	function open() { if (!panel) { return; } panel.hidden = false; if (ov) { ov.hidden = false; } requestAnimationFrame(function () { root.classList.add('is-open'); }); document.body.classList.add('m24gt-lock'); document.addEventListener('keydown', onKey); }
	function close() { root.classList.remove('is-open'); document.body.classList.remove('m24gt-lock'); document.removeEventListener('keydown', onKey); setTimeout(function () { if (!root.classList.contains('is-open')) { panel.hidden = true; if (ov) { ov.hidden = true; } } }, 320); }
	function onKey(e) { if ('Escape' === e.key) { close(); } }

	// Gast-Modus: Nudge einblenden + Login/Registrieren-Links setzen; CTAs auf Login umbiegen (Angebot/Garage
	// brauchen ein Konto — die Gast-Garage wird nach Login automatisch übernommen).
	if (!cfg.loggedIn) {
		var lg = cfg.loginUrl || '/haendler-login/';
		var nudge = $('[data-m24gt-nudge]'), loginA = $('[data-m24gt-login]');
		var cg = $('[data-m24gt-cta-garage]'), ci = $('[data-m24gt-cta-inquire]');
		if (nudge) { nudge.hidden = false; }
		if (loginA) { loginA.href = lg; }
		if (cg) { cg.href = lg; }
		if (ci) { ci.href = lg; }
	}

	if (tab) { tab.addEventListener('click', function () { load(); open(); }); }
	root.addEventListener('click', function (e) { if (e.target.closest('[data-m24gt-close]') || e.target === ov) { close(); } });
	document.addEventListener('m24garage:changed', function () { load(); });

	load();
})();
