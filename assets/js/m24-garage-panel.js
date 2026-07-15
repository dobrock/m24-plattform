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
					+ '<div class="m24gt-it-meta">' + (it.artnr ? 'Art.-Nr. ' + esc(it.artnr) + ' · ' : '') + '×' + (it.qty || 1) + (it.variant ? ' · Variante: ' + esc(it.variant) : '') + '</div></div>'
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
		// „Zur Garage" → Gast-Garage-Seite (rendert die localStorage-Items); „Angebot anfragen" → dieselbe Seite
		// mit direkt geöffnetem Kontaktformular. Nur der Nudge-Link führt zum Login/Registrieren.
		var pu = cfg.pageUrl || lg;
		if (cg) { cg.href = pu; }
		if (ci) { ci.href = pu + (pu.indexOf('?') > -1 ? '&' : '?') + 'angebot=start#m24-kontakt'; }

		// #4: anonymer 7-Tage-Share — Link ohne Konto erzeugen (nur IDs/Varianten/Mengen, keine PII).
		var shareBtn = $('[data-m24gt-share]'), shareBox = $('[data-m24gt-sharebox]'), shareUrl = $('[data-m24gt-shareurl]'), copyBtn = $('[data-m24gt-copy]');
		if (shareBtn && cfg.guestShare) {
			shareBtn.hidden = false;
			shareBtn.addEventListener('click', function () {
				var g = guestItems(); if (!g.length) { return; }
				shareBtn.disabled = true; shareBtn.textContent = 'Erstelle Link …';
				fetch(cfg.guestShare, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ items: g }) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						shareBtn.disabled = false; shareBtn.textContent = 'Garage teilen (7 Tage)';
						if (d && d.ok && d.url && shareUrl) { shareUrl.value = d.url; if (shareBox) { shareBox.hidden = false; } shareUrl.focus(); shareUrl.select(); }
					}).catch(function () { shareBtn.disabled = false; shareBtn.textContent = 'Garage teilen (7 Tage)'; });
			});
		}
		if (copyBtn && shareUrl) {
			copyBtn.addEventListener('click', function () {
				shareUrl.select();
				if (navigator.clipboard) { try { navigator.clipboard.writeText(shareUrl.value); } catch (e) {} } else { try { document.execCommand('copy'); } catch (e) {} }
				copyBtn.textContent = 'Kopiert ✓'; setTimeout(function () { copyBtn.textContent = 'Kopieren'; }, 1500);
			});
		}
	}

	if (tab) { tab.addEventListener('click', function () { load(); open(); }); }
	root.addEventListener('click', function (e) { if (e.target.closest('[data-m24gt-close]') || e.target === ov) { close(); } });
	document.addEventListener('m24garage:changed', function () { load(); });

	// Operator-Einstieg: „Angebot anfragen" → „Angebot erstellen" (nur bei serverseitigem isOperator). Klick legt
	// serverseitig einen Angebots-Entwurf an (Preise §25a-korrekt) und öffnet den Builder. Kunden sehen unverändert.
	if (cfg.isOperator && cfg.offerFromGarage) {
		var inq = $('[data-m24gt-cta-inquire]');
		if (inq) {
			inq.textContent = 'Angebot erstellen';
			inq.addEventListener('click', function (e) {
				e.preventDefault();
				if (inq.dataset.busy) { return; } // Doppelklick-Guard (Client) — Server dedupliziert zusätzlich
				var body = cfg.loggedIn ? {} : { items: guestItems() }; // eingeloggt = Konto-Garage (Server liest), sonst localStorage
				if (!cfg.loggedIn && !(body.items && body.items.length)) { return; } // leere Garage → nichts
				inq.dataset.busy = '1'; inq.textContent = 'Erstelle Angebot …';
				fetch(cfg.offerFromGarage, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (d && d.ok && d.edit_url) { window.location.href = d.edit_url; return; }
						inq.dataset.busy = ''; inq.textContent = 'Angebot erstellen';
					})
					.catch(function () { inq.dataset.busy = ''; inq.textContent = 'Angebot erstellen'; });
			}, true); // Capture: der bestehende Inquire-Href/-Flow darf nicht zusätzlich feuern
		}
	}

	load();
})();
