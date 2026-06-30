/* M24 „Meine Garage" — kontogebundener Warenkorb (Etappe 1).
 * - Verdrahtet die bestehenden „In meine Garage"-Buttons (.m24-garage-open) für eingeloggte Accounts:
 *   fügt direkt zum Konto-Warenkorb hinzu (Menge++) und unterdrückt den Gast-DOI-Dialog (Capture-Phase).
 * - Garage-Seite: Menge ± / Entfernen → REST → Zeile, Gesamtsumme und Zähler live aktualisieren.
 * - Hält jeden [data-m24-garage-count] (Schwebe- und Header-Zähler) live auf der Positionsanzahl.
 */
(function () {
	'use strict';
	var cfg = window.M24GarageCart || {};
	if (!cfg.rest) { return; }

	function headers() { return { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }; }

	function post(path, body) {
		return fetch(cfg.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers(),
			body: JSON.stringify(body || {})
		}).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); });
	}

	/* ── Zähler (Schwebe-FAB + jeder Header-Slot mit [data-m24-garage-count]) ── */
	function updateCount(count) {
		if (typeof count !== 'number') { return; }
		document.querySelectorAll('[data-m24-garage-count]').forEach(function (el) {
			el.textContent = String(count);
		});
		var fab = document.querySelector('.m24gc-fab');
		if (fab) { fab.classList.toggle('is-empty', count <= 0); }
	}

	/* ── Toast ── */
	var toastEl = null, toastT = null;
	function toast(msg) {
		if (!toastEl) {
			toastEl = document.createElement('div');
			toastEl.className = 'm24gc-toast';
			document.body.appendChild(toastEl);
		}
		toastEl.textContent = msg;
		// reflow → transition
		void toastEl.offsetWidth;
		toastEl.classList.add('show');
		if (toastT) { clearTimeout(toastT); }
		toastT = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
	}

	/* ── Bestehende „In meine Garage"-Buttons verdrahten (nur eingeloggt) ── */
	if (cfg.loggedIn) {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.m24-garage-open') : null;
			if (!btn) { return; }
			// Gast-DOI-Dialog (M24_Garage::render_modal) NICHT öffnen: Bubble-Listener vorab kappen.
			e.preventDefault();
			e.stopImmediatePropagation();
			var pid = parseInt(btn.getAttribute('data-garage-id') || '0', 10);
			if (!pid) { return; }
			if (btn.dataset.m24gcBusy === '1') { return; }
			btn.dataset.m24gcBusy = '1';
			post('/add', { post_id: pid }).then(function (res) {
				btn.dataset.m24gcBusy = '';
				if (res.ok && res.data && res.data.ok) {
					updateCount(res.data.count);
					toast((cfg.i18n && cfg.i18n.added) || 'In deine Garage gelegt.');
				} else {
					toast((res.data && res.data.message) || (cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
				}
			}).catch(function () {
				btn.dataset.m24gcBusy = '';
				toast((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
			});
		}, true); // <-- Capture-Phase: läuft VOR dem Modal-Listener auf document
	}

	/* ── Garage-Seite: Menge ± / Entfernen ── */
	var page = document.querySelector('[data-m24gc-page]');
	if (page) {
		page.addEventListener('click', function (e) {
			var row = e.target.closest('[data-m24gc-row]');
			if (!row) { return; }
			var inc = e.target.closest('.m24gc-inc');
			var dec = e.target.closest('.m24gc-dec');
			var rem = e.target.closest('[data-m24gc-remove]');
			if (!inc && !dec && !rem) { return; }
			e.preventDefault();
			if (row.dataset.busy === '1') { return; }

			var pid = parseInt(row.getAttribute('data-post-id') || '0', 10);
			var pt = row.getAttribute('data-post-type') || '';
			var qtyEl = row.querySelector('[data-m24gc-qty]');
			var cur = parseInt((qtyEl && qtyEl.textContent) || '1', 10);
			var req, body;

			if (rem) {
				req = '/remove'; body = { post_id: pid };
			} else {
				var next = inc ? cur + 1 : cur - 1;
				req = '/qty'; body = { post_id: pid, qty: next };
			}

			row.dataset.busy = '1';
			post(req, body).then(function (res) {
				row.dataset.busy = '';
				if (!res.ok || !res.data || !res.data.ok) {
					toast((res.data && res.data.message) || (cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
					return;
				}
				var d = res.data;
				updateCount(d.count);
				if (d.removed) {
					row.parentNode.removeChild(row);
					if (d.count <= 0) { location.reload(); return; } // Leerzustand serverseitig rendern
				} else {
					if (qtyEl) { qtyEl.textContent = String(d.qty); }
					var lineEl = row.querySelector('[data-m24gc-line]');
					if (lineEl) { lineEl.textContent = d.line_fmt || '—'; }
				}
				var grand = document.querySelector('[data-m24gc-grand]');
				if (grand && d.grand_fmt) { grand.textContent = d.grand_fmt; }
			}).catch(function () {
				row.dataset.busy = '';
				toast((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
			});
		});
	}

	/* ── Garage-Link versenden (nur Eigentümer, Etappe 2) ── */
	var sharePanel = document.querySelector('[data-m24gc-share]');
	if (sharePanel && cfg.share) {
		var inEl = sharePanel.querySelector('[data-m24gc-share-input]');
		var copyBtn = sharePanel.querySelector('[data-m24gc-share-copy]');
		var mailBtn = sharePanel.querySelector('[data-m24gc-share-mail]');
		var genBtn = sharePanel.querySelector('[data-m24gc-share-generate]');
		var rotBtn = sharePanel.querySelector('[data-m24gc-share-rotate]');
		var msgEl = sharePanel.querySelector('[data-m24gc-share-msg]');

		function shareMsg(t) { if (msgEl) { msgEl.textContent = t || ''; } }

		function buildMailto(url) {
			var i18n = cfg.i18n || {};
			var subj = encodeURIComponent(i18n.mailSubject || 'Garage');
			var body = encodeURIComponent((i18n.mailBody || '') + '\n\n' + url);
			return 'mailto:?subject=' + subj + '&body=' + body;
		}

		function applyUrl(url) {
			if (inEl) { inEl.value = url || ''; }
			var has = !!url;
			if (copyBtn) { copyBtn.hidden = !has; }
			if (mailBtn) { mailBtn.hidden = !has; if (has) { mailBtn.setAttribute('href', buildMailto(url)); } }
			if (genBtn) { genBtn.hidden = has; }
			if (rotBtn) { rotBtn.hidden = !has; }
		}

		// mailto-href initial setzen, falls schon ein Link da ist.
		if (inEl && inEl.value) { applyUrl(inEl.value); }

		function shareReq(action, after) {
			return fetch(cfg.share, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers(),
				body: JSON.stringify({ action: action })
			}).then(function (r) { return r.json(); }).then(function (d) {
				if (d && d.ok) { applyUrl(d.url); if (after) { after(d); } }
				else { shareMsg((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.'); }
			}).catch(function () { shareMsg((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.'); });
		}

		if (genBtn) {
			genBtn.addEventListener('click', function () { shareMsg(''); shareReq('generate'); });
		}
		if (rotBtn) {
			rotBtn.addEventListener('click', function () {
				shareReq('rotate', function () { shareMsg((cfg.i18n && cfg.i18n.rotated) || ''); });
			});
		}
		if (copyBtn) {
			copyBtn.addEventListener('click', function () {
				var val = inEl ? inEl.value : '';
				if (!val) { return; }
				var done = function () { shareMsg((cfg.i18n && cfg.i18n.copied) || 'Kopiert.'); };
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(val).then(done).catch(function () {
						if (inEl) { inEl.select(); document.execCommand && document.execCommand('copy'); done(); }
					});
				} else if (inEl) {
					inEl.select(); document.execCommand && document.execCommand('copy'); done();
				}
			});
		}
		// mailBtn ist ein echter <a href="mailto:…"> — Klick öffnet das Mailprogramm; href wird in applyUrl gesetzt.
	}
})();
