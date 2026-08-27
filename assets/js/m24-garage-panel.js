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
	var selfReload = true; // false, während das Panel selbst ein m24garage:changed auslöst
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

	/* ── Schnell-Entfernen (✕) ──────────────────────────────────────────────
	   Die ganze Zeile ist ein <a>. Der Knopf muss den Klick deshalb vollständig kappen, sonst navigiert
	   der Browser zum Artikel, statt zu entfernen. Kein confirm(): ein Bestätigungsdialog für eine
	   Aktion, die einen Klick kostet und rückgängig zu machen ist, hält nur auf. Stattdessen ersetzt ein
	   Rückgängig-Streifen die Zeile — sichtbar an derselben Stelle, 6 s lang. */
	// Kein Timer: eine löschende Aktion darf ihre Rückversicherung nicht von selbst wegnehmen. Der
	// Streifen bleibt stehen, bis der Nutzer etwas anderes tut — Panel schließen, andere Zeile anfassen
	// oder neu laden. (6 s waren zu kurz: beim Testen ist dabei ein echtes Teil verlorengegangen.)
	var openUndos = [];

	function rmBtn(it) {
		var label = 'aus der Garage entfernen';
		return '<button type="button" class="m24gt-rm" data-m24gt-rm aria-label="'
			+ esc((it.title || 'Position') + ' ' + label) + '" title="Entfernen">'
			+ '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">'
			+ '<path d="M18 6 6 18M6 6l12 12"/></svg></button>';
	}

	/** Zeile durch den Rückgängig-Streifen ersetzen; nach UNDO_MS ist die Entfernung endgültig. */
	function showUndo(row, it) {
		var strip = document.createElement('div');
		strip.className = 'm24gt-undo';
		strip.innerHTML = '<span>Entfernt</span><button type="button" class="m24gt-undo-btn">Rückgängig</button>';
		row.parentNode.replaceChild(strip, row);
		openUndos.push(strip);
		strip.querySelector('.m24gt-undo-btn').addEventListener('click', function () {
			dropUndos(strip); // nur die anderen aufräumen, dieser hier wird ohnehin gleich neu gezeichnet
			// Wieder anlegen und neu zeichnen — die Position landet dadurch an ihrer alten Stelle,
			// weil der Server die Reihenfolge führt.
			post('/add', { post_id: it.post_id || it.id, post_type: it.post_type || '' }).then(function () {
				load();
			});
		});
	}

	/** Offene Rückgängig-Streifen entfernen (die Entfernung ist damit endgültig). $keep bleibt stehen. */
	function dropUndos(keep) {
		openUndos.forEach(function (el) {
			if (el !== keep && el.parentNode) { el.parentNode.removeChild(el); }
		});
		openUndos = [];
	}

	/** POST an einen Garage-Endpunkt (dieselbe Basis wie der Rest des Panels). */
	function post(path, body) {
		return fetch(cfg.rest + path, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify(body || {})
		}).then(function (r) { return r.json(); }).catch(function () { return null; });
	}

	if (itemsEl) {
		itemsEl.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('[data-m24gt-rm]') : null;
			if (!btn) { return; }
			e.preventDefault();
			e.stopPropagation();
			var row = btn.closest('.m24gt-it');
			if (!row || row.dataset.busy === '1') { return; }
			dropUndos(); // andere Zeile angefasst → vorherige Entfernungen sind endgültig
			row.dataset.busy = '1';
			var pid = parseInt(row.getAttribute('data-post-id') || '0', 10);
			var it = { post_id: pid, post_type: row.getAttribute('data-post-type') || '' };
			post('/remove', { post_id: pid }).then(function (d) {
				row.dataset.busy = '';
				if (!d || !d.ok) { return; }
				// Kopfzähler, Fußsumme und Reiter-Badge nachziehen …
				applyTotals(d);
				// … und die Kachel-Chips derselben ID, falls im Hintergrund sichtbar.
				document.querySelectorAll('.m24-card__garage[data-garage-id="' + pid + '"]').forEach(function (chip) {
					chip.classList.remove('is-in', 'is-ingarage');
					chip.setAttribute('aria-pressed', 'false');
					chip.setAttribute('aria-label', 'In die Garage');
					chip.setAttribute('title', 'In die Garage');
				});
				if (typeof d.count === 'number' && d.count <= 0) { load(); return; } // Leer-Zustand serverseitig
				showUndo(row, it);
			});
		});
	}

	/** Zähler/Summe aus einer Server-Antwort übernehmen und das gemeinsame Event feuern. */
	function applyTotals(d) {
		var count = (typeof d.count === 'number') ? d.count : null;
		if (null !== count) {
			if (cntEl) { cntEl.textContent = count; }
			var gno = cfg.garageNo ? ' · ' + cfg.garageNo : '';
			if (subEl) { subEl.textContent = count + ' Position' + (1 === count ? '' : 'en') + gno; }
		}
		if (sumEl && d.grand_fmt) { sumEl.innerHTML = esc(d.grand_fmt); }
		if (null !== count) { root.hidden = count <= 0; } // Reiter verschwindet bei leerer Garage
		try {
			var detail = { count: count, total: (typeof d.total === 'number' ? d.total : count) };
			document.dispatchEvent(new CustomEvent('m24:garage:changed', { detail: detail }));
			// Zusätzlich das ALTE Event: daran hängen die Bestandshörer (Garage-Seite, Operator-Relabel)
			// — ohne das aktualisiert außerhalb des Panels nichts. selfReload verhindert dabei, dass
			// unser eigener Listener load() auslöst und den Rückgängig-Streifen wegrendert.
			selfReload = false;
			document.dispatchEvent(new CustomEvent('m24garage:changed', { detail: detail }));
			selfReload = true;
		} catch (e) { selfReload = true; }
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
					+ '<div class="p">' + esc(it.line_fmt || it.unit_fmt || 'auf Anfrage') + '</div>'
					// Schnell-Entfernen. Hinter demselben Schalter wie der Kachel-Chip (quick_controls_visible).
					+ (cfg.quickControls ? rmBtn(it) : '');
				row.setAttribute('data-post-id', it.post_id || it.id || '');
				row.setAttribute('data-post-type', it.post_type || '');
				itemsEl.appendChild(row);
			});
		}
		root.hidden = count <= 0;              // leere Garage → Tab weg
		if (prev >= 0 && count > prev) { pulse(); } // Zuwachs → Tab pulst
		prev = count;
	}
	function pulse() { if (!tab) { return; } tab.classList.remove('is-pulse'); void tab.offsetWidth; tab.classList.add('is-pulse'); }

	function open() { if (!panel) { return; } dropUndos(); load(); panel.hidden = false; if (ov) { ov.hidden = false; } requestAnimationFrame(function () { root.classList.add('is-open'); }); document.body.classList.add('m24gt-lock'); document.addEventListener('keydown', onKey); }
	function close() { dropUndos(); root.classList.remove('is-open'); document.body.classList.remove('m24gt-lock'); document.removeEventListener('keydown', onKey); setTimeout(function () { if (!root.classList.contains('is-open')) { panel.hidden = true; if (ov) { ov.hidden = true; } } }, 320); }
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
	document.addEventListener('m24garage:changed', function () { if (selfReload) { load(); } });

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
