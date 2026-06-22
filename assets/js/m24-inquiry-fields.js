/* M24 — Gemeinsames Anfrage-Feld-Verhalten: Segmented-Toggle (Kundentyp) + Validator.
   Beide Modals (Teile „Frage stellen" + Fahrzeug „Jetzt anfragen") nutzen window.M24IqFields. */
(function () {
	'use strict';

	function setKundentyp(seg, val) {
		if (!seg) { return; }
		var btns = seg.querySelectorAll('.m24-iqf-seg-btn');
		var hidden = seg.querySelector('input[name="kundentyp"]');
		[].forEach.call(btns, function (b) {
			var on = b.getAttribute('data-val') === val;
			b.setAttribute('aria-checked', on ? 'true' : 'false');
			b.tabIndex = on ? 0 : -1;
		});
		if (hidden) { hidden.value = val; }
		seg.classList.remove('m24-iqf-invalid');
	}

	// Klick auf Segment-Button (Delegation).
	document.addEventListener('click', function (e) {
		var btn = e.target.closest && e.target.closest('.m24-iqf-seg-btn');
		if (!btn) { return; }
		var seg = btn.closest('[data-m24-kundentyp]');
		setKundentyp(seg, btn.getAttribute('data-val'));
	});

	// Tastatur: Pfeile wählen + bewegen, Space/Enter wählt.
	document.addEventListener('keydown', function (e) {
		var btn = e.target.closest && e.target.closest('.m24-iqf-seg-btn');
		if (!btn) { return; }
		var seg = btn.closest('[data-m24-kundentyp]');
		if (!seg) { return; }
		var btns = [].slice.call(seg.querySelectorAll('.m24-iqf-seg-btn'));
		var i = btns.indexOf(btn);
		if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); var n = btns[(i + 1) % btns.length]; setKundentyp(seg, n.getAttribute('data-val')); n.focus(); }
		else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); var p = btns[(i - 1 + btns.length) % btns.length]; setKundentyp(seg, p.getAttribute('data-val')); p.focus(); }
		else if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); setKundentyp(seg, btn.getAttribute('data-val')); }
	});

	function emailOk(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
	function field(root, name) { return root.querySelector('[name="' + name + '"]'); }
	function mark(el, bad) { if (el) { el.classList.toggle('m24-iqf-invalid', !!bad); } }

	/**
	 * Validiert die gemeinsamen Felder. scope = das <form> (oder ein Container).
	 * @return {{ok:boolean, msg?:string}}
	 */
	function validate(scope) {
		var root = scope || document;
		var name = field(root, 'name'), email = field(root, 'email'), kund = field(root, 'kundentyp'),
			land = field(root, 'lieferland'), consent = field(root, 'consent');
		var errEl = root.querySelector('[data-m24-iqf-error]');
		var seg = root.querySelector('[data-m24-kundentyp]');
		[name, email, land].forEach(function (f) { mark(f, false); });
		if (seg) { seg.classList.remove('m24-iqf-invalid'); }
		function fail(el, segBad, msg) {
			mark(el, true);
			if (segBad && seg) { seg.classList.add('m24-iqf-invalid'); }
			if (errEl) { errEl.textContent = msg; errEl.hidden = false; }
			if (el && el.focus) { try { el.focus(); } catch (x) {} }
			return { ok: false, msg: msg };
		}
		if (!name || name.value.trim().length < 2) { return fail(name, false, 'Bitte Ihren Namen angeben (mind. 2 Zeichen).'); }
		if (!email || !emailOk(email.value.trim())) { return fail(email, false, 'Bitte eine gültige E-Mail-Adresse angeben.'); }
		if (!kund || !kund.value) { return fail(null, true, 'Bitte Privat oder Geschäftskunde wählen.'); }
		if (!land || !land.value) { return fail(land, false, 'Bitte ein Lieferland wählen.'); }
		if (!consent || !consent.checked) { return fail(null, false, 'Bitte der Datenschutzerklärung zustimmen.'); }
		if (errEl) { errEl.hidden = true; errEl.textContent = ''; }
		return { ok: true };
	}

	window.M24IqFields = { validate: validate, setKundentyp: setKundentyp };
})();
