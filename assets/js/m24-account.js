/**
 * M24 Konto-/Einstellungsseite (Entwurf 1). Server-gerendert; hier nur Interaktion + REST-Saves.
 * Nur In-Page-Patterns (kein natives confirm/alert). Config: window.M24Account {base, nonce, danger}.
 * Benachrichtigungs-Pills (Section 5) laufen weiter über m24-garage.js (Event-Delegation).
 */
(function () {
	'use strict';
	var cfg = window.M24Account || {};
	var root = document.querySelector('[data-m24acc]');
	if (!cfg.base || !root) { return; }
	var garageBase = cfg.base.replace(/\/account$/, '/garage');

	function post(path, payload) {
		return fetch(cfg.base + path, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify(payload || {})
		}).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }, function () { return { ok: false, d: {} }; }); });
	}
	function postGarage(path, payload) {
		return fetch(garageBase + path, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: JSON.stringify(payload || {})
		}).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }, function () { return { ok: false, d: {} }; }); });
	}
	function status(el, msg, tone) {
		if (!el) { return; }
		el.textContent = msg || '';
		el.className = 'm24acc-status' + (tone ? ' is-' + tone : '');
	}
	function sectionStatus(node) { return node ? node.querySelector('[data-status]') : null; }
	function val(scope, sel) { var f = scope.querySelector(sel); return f ? f.value.trim() : ''; }

	/* ── Segmente (Kundentyp / Sprache) ── */
	root.addEventListener('click', function (e) {
		var kt = e.target.closest ? e.target.closest('[data-kt]') : null;
		if (kt) {
			var box = kt.closest('.m24acc-seg');
			box.querySelectorAll('[data-kt]').forEach(function (b) { b.classList.toggle('is-on', b === kt); });
			var b2b = root.querySelector('[data-m24acc-b2b]');
			if (b2b) { b2b.hidden = kt.getAttribute('data-kt') !== 'b2b'; }
			return;
		}
		var lang = e.target.closest ? e.target.closest('[data-lang]') : null;
		if (lang) {
			var lbox = lang.closest('.m24acc-seg');
			lbox.querySelectorAll('[data-lang]').forEach(function (b) { b.classList.toggle('is-on', b === lang); });
			var accSec = lang.closest('[data-m24acc-account]');
			post('/language', { lang: lang.getAttribute('data-lang') }).then(function (res) {
				status(sectionStatus(accSec), (res.d && res.d.message) || 'Gespeichert.', res.ok ? 'ok' : 'error');
			});
			return;
		}
		var chip = e.target.closest ? e.target.closest('[data-chip], [data-model]') : null;
		if (chip) { chip.classList.toggle('is-on'); return; }
	});

	/* ── Anschriften ein-/ausklappen ── */
	var addrToggle = root.querySelector('[data-m24acc-toggle="addr"]');
	if (addrToggle) {
		addrToggle.addEventListener('click', function () {
			var body = root.querySelector('[data-m24acc-addrbody]');
			if (!body) { return; }
			var open = body.hidden;
			body.hidden = !open;
			addrToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}

	/* ── Save-Buttons ── */
	root.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-m24acc-save]') : null;
		if (!btn) { return; }
		var what = btn.getAttribute('data-m24acc-save');
		var sec = btn.closest('.m24acc-card');
		var st = sectionStatus(sec);
		btn.disabled = true;
		var done = function (res) { btn.disabled = false; status(st, (res.d && res.d.message) || (res.ok ? 'Gespeichert.' : 'Fehler.'), res.ok ? 'ok' : 'error'); };

		if (what === 'profile') {
			var ktOn = sec.querySelector('[data-kt].is-on');
			post('/profile', {
				name: val(sec, '[data-f="name"]'),
				kundentyp: ktOn ? ktOn.getAttribute('data-kt') : 'b2c',
				firma: val(sec, '[data-f="firma"]'),
				ustid: val(sec, '[data-f="ustid"]')
			}).then(done);
		} else if (what === 'address') {
			var grp = function (name) {
				var g = sec.querySelector('.m24acc-addrgrp[data-grp="' + name + '"]');
				var read = function (a) { var f = g.querySelector('[data-a="' + a + '"]'); return f ? f.value.trim() : ''; };
				return { name: read('name'), strasse: read('strasse'), plz: read('plz'), ort: read('ort'), land: read('land') };
			};
			post('/address', { billing: grp('billing'), shipping: grp('shipping') }).then(done);
		} else if (what === 'alerts') {
			var chips = [].map.call(sec.querySelectorAll('[data-chip].is-on'), function (c) { return c.getAttribute('data-chip'); });
			var models = [].map.call(sec.querySelectorAll('[data-model].is-on'), function (c) { return c.getAttribute('data-model'); });
			post('/alerts', { chips: chips, modelle: models }).then(done);
		}
	});

	/* ── DSGVO-Export ── */
	var exportBtn = root.querySelector('[data-m24acc-export]');
	if (exportBtn && !exportBtn.hasAttribute('disabled')) {
		exportBtn.addEventListener('click', function () {
			var sec = exportBtn.closest('.m24acc-card');
			exportBtn.disabled = true;
			post('/export', {}).then(function (res) {
				exportBtn.disabled = false;
				if (res.ok && res.d && res.d.ok) {
					var blob = new Blob([JSON.stringify(res.d.data, null, 2)], { type: 'application/json' });
					var a = document.createElement('a');
					a.href = URL.createObjectURL(blob);
					a.download = res.d.filename || 'motorsport24-datenauskunft.json';
					document.body.appendChild(a); a.click(); document.body.removeChild(a);
					setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
					status(sectionStatus(sec), 'Export heruntergeladen.', 'ok');
				} else {
					status(sectionStatus(sec), (res.d && res.d.message) || 'Export nicht möglich.', 'error');
				}
			});
		});
	}

	/* ── Konto löschen (In-Page-Confirm → E-Mail-Bestätigung) ── */
	var delBtn = root.querySelector('[data-m24acc-delete]');
	var delBox = root.querySelector('[data-m24acc-delbox]');
	if (delBtn && delBox && !delBtn.hasAttribute('disabled')) {
		delBtn.addEventListener('click', function () { delBox.hidden = false; });
		var cancel = root.querySelector('[data-m24acc-delcancel]');
		if (cancel) { cancel.addEventListener('click', function () { delBox.hidden = true; }); }
		var confirm = root.querySelector('[data-m24acc-delconfirm]');
		if (confirm) {
			confirm.addEventListener('click', function () {
				confirm.disabled = true;
				var sec = delBtn.closest('.m24acc-card');
				post('/delete-request', {}).then(function (res) {
					delBox.hidden = true;
					status(sectionStatus(sec), (res.d && res.d.message) || (res.ok ? 'Bestätigungslink gesendet.' : 'Fehler.'), res.ok ? 'ok' : 'error');
				});
			});
		}
	}

	/* ── §7-Opt-out: alles abbestellen ── */
	var unsub = root.querySelector('[data-m24acc-unsub]');
	if (unsub) {
		unsub.addEventListener('click', function () {
			unsub.disabled = true;
			var foot = root.querySelector('[data-m24acc-foot]');
			post('/unsubscribe', {}).then(function (res) {
				unsub.disabled = false;
				status(sectionStatus(foot), (res.d && res.d.message) || (res.ok ? 'Abbestellt.' : 'Fehler.'), res.ok ? 'ok' : 'error');
				var master = root.querySelector('[data-m24gc-master]');
				if (master) { master.checked = false; }
			});
		});
	}

	/* ── Geteilten Link zurückziehen (reuse garage/share) ── */
	var revoke = root.querySelector('[data-m24acc-share-revoke]');
	if (revoke) {
		revoke.addEventListener('click', function () {
			if (revoke.dataset.confirm !== '1') {
				revoke.dataset.confirm = '1'; revoke.textContent = 'wirklich?';
				setTimeout(function () { revoke.dataset.confirm = ''; revoke.textContent = 'zurückziehen'; }, 4000);
				return;
			}
			revoke.disabled = true;
			postGarage('/share', { action: 'revoke' }).then(function () {
				var box = root.querySelector('[data-m24acc-share]');
				if (box) { box.innerHTML = '<div class="m24acc-share-meta">Link zurückgezogen.</div>'; }
			});
		});
	}

	/* ── Gemerktes Fahrzeug entfernen (reuse garage/cart/remove) ── */
	root.addEventListener('click', function (e) {
		var heart = e.target.closest ? e.target.closest('[data-m24acc-unfav]') : null;
		if (!heart) { return; }
		var row = heart.closest('[data-m24acc-vrow]');
		if (!row || heart.dataset.busy === '1') { return; }
		heart.dataset.busy = '1';
		var pid = parseInt(row.getAttribute('data-post-id') || '0', 10);
		postGarage('/cart/remove', { post_id: pid, post_type: 'm24_fahrzeug' }).then(function (res) {
			heart.dataset.busy = '';
			if (res.ok) { row.parentNode.removeChild(row); }
		});
	});
})();
