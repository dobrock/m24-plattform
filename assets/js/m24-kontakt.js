/* M24 — Kontaktformular [m24_kontakt]: Themen-Chips, Validierung, Versand (REST m24-plattform/v1/kontakt).
   „Ich bin"-Segment + EU-Privatkunden-Hinweis kommen aus m24-inquiry-fields.js (window.M24IqFields). */
(function () {
	'use strict';

	var C = (typeof window.M24KontaktConfig === 'object' && window.M24KontaktConfig) || {};

	function isEn() {
		try {
			if (/^\/en(\/|$)/.test(location.pathname)) { return true; }
			var h = (document.documentElement.getAttribute('lang') || '').toLowerCase();
			if (0 === h.indexOf('en')) { return true; }
			if (/(?:^|;)\s*googtrans=\/[a-z]{2}\/en\b/.test(document.cookie)) { return true; }
		} catch (e) {}
		return false;
	}
	var T = (isEn() ? C.i18nEn : C.i18nDe) || {};

	// ── Themen-Chips (Einfachauswahl, Pfeiltasten wie Radiogruppe) ──
	function setChip(group, btn) {
		[].forEach.call(group.querySelectorAll('.m24kf-chip'), function (b) {
			var on = b === btn;
			b.setAttribute('aria-checked', on ? 'true' : 'false');
			b.tabIndex = on ? 0 : -1;
		});
		var h = group.querySelector('input[name="thema"]');
		if (h) { h.value = btn.getAttribute('data-val') || ''; }
	}
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.m24kf-chip');
		if (!btn) { return; }
		var g = btn.closest('[data-m24kf-chips]');
		if (g) { setChip(g, btn); }
	});
	document.addEventListener('keydown', function (e) {
		var btn = e.target.closest && e.target.closest('.m24kf-chip');
		if (!btn) { return; }
		var g = btn.closest('[data-m24kf-chips]');
		var all = [].slice.call(g.querySelectorAll('.m24kf-chip'));
		var i = all.indexOf(btn), n = null;
		if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { n = all[(i + 1) % all.length]; }
		else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { n = all[(i - 1 + all.length) % all.length]; }
		else if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); setChip(g, btn); return; }
		if (n) { e.preventDefault(); setChip(g, n); n.focus(); }
	});

	// ── Validierung (inline) ──
	function val(form, n) { var f = form.querySelector('[name="' + n + '"]'); return f ? String(f.value || '').trim() : ''; }
	function validate(form) {
		var err = form.querySelector('[data-m24kf-error]');
		var seg = form.querySelector('[data-m24-kundentyp]');
		[].forEach.call(form.querySelectorAll('.m24kf-invalid'), function (el) { el.classList.remove('m24kf-invalid'); });
		function fail(el, msg) {
			if (el) { el.classList.add('m24kf-invalid'); }
			if (err) { err.textContent = msg; err.hidden = false; }
			var focusEl = el && el.matches && el.matches('[data-m24-kundentyp]') ? el.querySelector('button') : el;
			if (focusEl && focusEl.focus) { try { focusEl.focus(); } catch (x) {} }
			return false;
		}
		if (val(form, 'name').length < 2) { return fail(form.querySelector('[name="name"]'), T.name || 'Name?'); }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val(form, 'email'))) { return fail(form.querySelector('[name="email"]'), T.email || 'E-Mail?'); }
		if (!val(form, 'kundentyp')) { return fail(seg, T.kund || '?'); }
		if (!val(form, 'lieferland')) { return fail(form.querySelector('[name="lieferland"]'), T.land || '?'); }
		var c = form.querySelector('[name="consent"]');
		if (!c || !c.checked) { return fail(null, T.consent || '?'); }
		if (err) { err.hidden = true; err.textContent = ''; }
		return true;
	}

	// ── Versand ──
	document.addEventListener('submit', function (e) {
		var form = e.target && e.target.closest ? e.target.closest('[data-m24kf-form]') : null;
		if (!form) { return; }
		e.preventDefault();
		if (!validate(form)) { return; }
		var box = form.closest('[data-m24kf]');
		var err = form.querySelector('[data-m24kf-error]');
		var btn = form.querySelector('[data-m24kf-submit]');
		var label = btn ? btn.innerHTML : '';
		var c = form.querySelector('[name="consent"]');
		var payload = {
			thema: val(form, 'thema'), name: val(form, 'name'), email: val(form, 'email'),
			kundentyp: val(form, 'kundentyp'), lieferland: val(form, 'lieferland'),
			teilenummer: val(form, 'teilenummer'), nachricht: val(form, 'nachricht'),
			consent: c && c.checked ? '1' : '', website_confirm: val(form, 'website_confirm'),
			form_url: location.href.split('#')[0]
		};
		function showErr(msg) {
			if (err) { err.textContent = msg || T.err || 'Fehler'; err.hidden = false; }
			if (btn) { btn.disabled = false; btn.innerHTML = label; }
		}
		if (btn) { btn.disabled = true; btn.textContent = T.sending || '…'; }
		fetch(C.restUrl || '', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce || '' },
			body: JSON.stringify(payload)
		}).then(function (r) {
			return r.json().then(function (j) { return { s: r.status, b: j }; }, function () { return { s: r.status, b: {} }; });
		}).then(function (res) {
			if (res.s === 200 && res.b && res.b.ok) {
				var ok = box ? box.querySelector('[data-m24kf-success]') : null;
				form.hidden = true;
				if (ok) {
					ok.textContent = T.ok || 'Danke!';
					ok.hidden = false;
					if (ok.scrollIntoView) { try { ok.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (x) {} }
				}
			} else {
				showErr(res.b && (res.b.error || res.b.message));
			}
		}).catch(function () { showErr(T.err); });
	});
})();
