/**
 * M24 Plattform — Anfragen: Modal + Merken + PPWR-Client
 *
 * - .m24-frage   -> Modal, REST POST /inquiry (bestehende Pipeline)
 * - .m24-merken  -> window.M24Sidebar.addItem() (bestehende Sammelanfrage)
 * - "Per E-Mail an mich senden" wird ins bestehende Sidebar-Panel injiziert
 * - PPWR: Hinweis + Submit-Block an JEDEM select[name=land] (Modal + Sidebar-Formular)
 *
 * @package M24_Plattform
 */
(function () {
	'use strict';

	var Config = (typeof window.M24InquiryConfig === 'object' && window.M24InquiryConfig) || {};

	// Clientseitige Sprachwahl (GTranslate-Proxy verbirgt /en/ vor dem Server): echte Browser-URL/<html lang>/
	// googtrans-Cookie entscheidet. Beide Sprach-Sets (i18nDe/i18nEn) sind eingebettet; sonst Server-Fallback.
	function m24DisplayIsEn() {
		try {
			if (/^\/en(\/|$)/.test(location.pathname)) { return true; }
			var h = (document.documentElement.getAttribute('lang') || '').toLowerCase();
			if (0 === h.indexOf('en')) { return true; }
			if (/(?:^|;)\s*googtrans=\/[a-z]{2}\/en\b/.test(document.cookie)) { return true; }
		} catch (e) {}
		return false;
	}
	var T = (m24DisplayIsEn() ? Config.i18nEn : Config.i18nDe) || Config.i18n || {};
	var currentItem = null;

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function overlay() { return document.getElementById('m24iq-overlay'); }
	function field(name) { var o = overlay(); return o ? o.querySelector('[name="' + name + '"]') : null; }

	// ── REST ─────────────────────────────────────────────────────────────
	function api(path, payload) {
		return fetch((Config.restUrl || '') + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': Config.nonce || '' },
			body: JSON.stringify(payload)
		}).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }, function () { return { status: r.status, body: {} }; }); });
	}

	// ── PPWR-Client (Modal + Sidebar-Formular) ───────────────────────────
	function ppwrBlocked(iso) {
		iso = String(iso || '').toUpperCase();
		var p = Config.ppwr || {};
		var eu = p.euMember || [], al = p.allowedEu || [];
		return eu.indexOf(iso) > -1 && al.indexOf(iso) === -1;
	}
	// Eingabe "Deutschland (DE)" / "Deutschland" / "DE" -> ISO-Code (oder '').
	function resolveIso(str) {
		str = String(str || '').trim();
		if (!str) { return ''; }
		var lands = Config.lands || {};
		var m = str.match(/\(([A-Za-z]{2})\)\s*$/);
		if (m && lands[m[1].toUpperCase()]) { return m[1].toUpperCase(); }
		var low = str.toLowerCase();
		for (var k in lands) {
			if (lands.hasOwnProperty(k) && String(lands[k]).toLowerCase() === low) { return k; }
		}
		var up = str.toUpperCase();
		return lands[up] ? up : '';
	}

	function ppwrApplyForm(form, iso, anchor) {
		if (!form) { return; }
		var blocked = ppwrBlocked(iso);
		var notice = form.querySelector('[data-m24-ppwr]');
		if (blocked) {
			if (!notice) {
				notice = document.createElement('div');
				notice.setAttribute('data-m24-ppwr', '');
				notice.className = 'm24-ppwr-notice';
				(anchor || form).appendChild(notice);
			}
			notice.textContent = (Config.ppwr && Config.ppwr.notice) || '';
			notice.hidden = false;
		} else if (notice) {
			notice.hidden = true;
		}
		var btn = form.querySelector('[data-m24iq="submit"], .m24-form__submit, button[type="submit"]');
		if (btn) { btn.disabled = blocked; }
	}

	// Bestehendes Sammelanfrage-Formular (echtes <select name=land>).
	function ppwrFromSelect(sel) {
		ppwrApplyForm(sel.closest('form'), String(sel.value || '').toUpperCase(), sel.parentNode);
	}

	// Modal-Autocomplete: ISO in hidden 'land' aufloesen + PPWR anwenden.
	function onLandInput(input) {
		var iso = resolveIso(input.value);
		var form = input.closest('form');
		var hidden = form ? form.querySelector('[data-m24iq="land"]') : null;
		if (hidden) { hidden.value = iso; }
		ppwrApplyForm(form, iso, input.parentNode);
	}

	// ── Modal ────────────────────────────────────────────────────────────
	function openModal(data) {
		var o = overlay(); if (!o) { return; }
		// data-artnr + data-variant-label werden vom Detail-Template gepflegt
		// (initial = Default-Option, bei Varianten-Wechsel via change-Handler aktualisiert).
		var variantLabel = (data['variantLabel'] !== undefined ? data['variantLabel'] : '') || '';
		var pickedArtnr  = data.artnr || '';
		currentItem = {
			art: data.title || '', qty: '1', price: data.price || '',
			src_url: data.url || '', src_pillar: 'katalog',
			src_modell: data.modell || '', src_pid: pickedArtnr || data.id || '',
			src_art_nr: pickedArtnr || '',
			src_variant: variantLabel
		};
		var ref = o.querySelector('[data-m24iq="ref"]');
		if (ref) {
			ref.innerHTML = '<strong></strong><span></span>';
			ref.querySelector('strong').textContent = data.title || '';
			ref.querySelector('span').textContent = data.artnr ? ('Art.-Nr. ' + data.artnr) : '';
		}
		var form = o.querySelector('[data-m24iq="form"]');
		var success = o.querySelector('[data-m24iq="success"]');
		if (form) { form.reset(); form.hidden = false; }
		if (success) { success.hidden = true; }
		var notice = o.querySelector('[data-m24iq="notice"]'); if (notice) { notice.hidden = true; }
		toggleBiz();
		// Land startet leer (kein Default) -> PPWR-Hinweis erst nach Auswahl.
		var landInput = o.querySelector('[data-m24iq="land-input"]'); if (landInput) { landInput.value = ''; }
		var landHidden = o.querySelector('[data-m24iq="land"]'); if (landHidden) { landHidden.value = ''; }
		ppwrApplyForm(o.querySelector('[data-m24iq="form"]'), '');
		o.hidden = false;
		document.body.style.overflow = 'hidden';
		var first = field('name'); if (first) { setTimeout(function () { first.focus(); }, 30); }
	}
	function closeModal() {
		var o = overlay(); if (!o) { return; }
		o.hidden = true;
		document.body.style.overflow = '';
	}
	function toggleBiz() {
		var o = overlay(); if (!o) { return; }
		var on = field('biz') && field('biz').value === '1';
		o.querySelectorAll('.m24iq-biz').forEach(function (el) { el.hidden = !on; });
	}

	function submitModal(e) {
		e.preventDefault();
		var o = overlay(); if (!o || !currentItem) { return; }
		var form = o.querySelector('[data-m24iq="form"]');
		var btn = o.querySelector('[data-m24iq="submit"]');
		var notice = o.querySelector('[data-m24iq="notice"]');
		if (notice) { notice.hidden = true; }
		// Gemeinsame Client-Validierung (name, email, kundentyp, lieferland, consent).
		if (window.M24IqFields) { var v = M24IqFields.validate(form); if (!v.ok) { return; } }
		var val = function (n) { var f = field(n); return f ? f.value : ''; };
		var land = val('lieferland');
		if (ppwrBlocked(land)) { if (notice) { notice.hidden = false; notice.textContent = (Config.ppwr && Config.ppwr.notice) || ''; } return; }
		var payload = {
			name: val('name'), email: val('email'), kundentyp: val('kundentyp'),
			lieferland: land, nachricht: val('nachricht'),
			consent: field('consent') && field('consent').checked ? '1' : '',
			il_optin: field('il_optin') && field('il_optin').checked ? '1' : '',
			register: field('register') && field('register').checked ? '1' : '',
			website_confirm: val('website_confirm'),
			inquiry_source: 'product_inquiry',
			items_json: JSON.stringify([currentItem])
		};
		if (btn) { btn.disabled = true; }
		api('inquiry', payload).then(function (res) {
			if (res.status === 200 && res.body && res.body.ok) {
				var form = o.querySelector('[data-m24iq="form"]');
				var success = o.querySelector('[data-m24iq="success"]');
				if (form) { form.hidden = true; }
				if (success) { success.hidden = false; success.textContent = T.success || 'Danke!'; }
			} else {
				if (notice) { notice.hidden = false; notice.textContent = (res.body && res.body.error) || (T.genericErr || 'Fehler'); }
				if (btn) { btn.disabled = false; }
			}
		}).catch(function () {
			if (notice) { notice.hidden = false; notice.textContent = T.genericErr || 'Fehler'; }
			if (btn) { btn.disabled = false; }
		});
	}

	// ── Merken (bestehende Sidebar) ──────────────────────────────────────
	function merken(btn) {
		var box = btn.closest('.actions') || btn.parentNode;
		var frage = box ? box.querySelector('.m24-frage') : null;
		var d = frage ? frage.dataset : btn.dataset;
		if (!window.M24Sidebar || typeof window.M24Sidebar.addItem !== 'function') { return; }
		// Title fallt im Merken-Pfad auf den Frage-Button-Title zurueck (Merken-Btn hat keinen data-title).
		var title = (frage && frage.dataset.title) || d.title || '';
		var variantLabel = btn.dataset.variantLabel || (frage && frage.dataset.variantLabel) || '';
		var artnr        = btn.dataset.artnr        || d.artnr        || '';
		var price        = btn.dataset.price        || d.price        || '';
		window.M24Sidebar.addItem({
			art: title, qty: 1, price: price,
			src_url: d.url || '', src_pillar: 'katalog',
			src_modell: d.modell || '', src_pid: artnr || d.id || '',
			src_art_nr: artnr,
			src_variant: variantLabel
		});
	}

	// ── "Per E-Mail an mich senden" ins Sidebar-Panel injizieren ─────────
	function injectEmailAction() {
		var footer = document.querySelector('#m24-sidebar-root .m24-sidebar__footer');
		if (!footer || footer.querySelector('[data-m24-action="email-me"]')) { return; }
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'm24-sidebar__email-me';
		btn.setAttribute('data-m24-action', 'email-me');
		btn.textContent = T.emailToMe || 'Per E-Mail an mich senden';
		footer.appendChild(btn);
	}
	function emailMe() {
		if (!window.M24Sidebar || typeof window.M24Sidebar.getItems !== 'function') { return; }
		var items = window.M24Sidebar.getItems();
		if (!items.length) { return; }
		var email = window.prompt(T.emailPrompt || 'E-Mail:');
		if (email === null) { return; } // Abbruch; leere Eingabe ist erlaubt (Mail geht an service@)
		api('merkzettel-email', { email: email, items: items, website_confirm: '' }).then(function (res) {
			window.alert((res.body && res.body.ok) ? (T.sent || 'Gesendet') : ((res.body && res.body.error) || (T.genericErr || 'Fehler')));
		});
	}

	// ── Events ───────────────────────────────────────────────────────────
	function onClick(e) {
		var t = e.target;
		var frage = t.closest && t.closest('.m24-frage');
		if (frage) { e.preventDefault(); openModal(frage.dataset); return; }
		var merk = t.closest && t.closest('.m24-merken');
		if (merk) { e.preventDefault(); merken(merk); return; }
		if (t.closest && t.closest('[data-m24iq="close"]')) { closeModal(); return; }
		if (t.closest && t.closest('[data-m24-action="email-me"]')) { emailMe(); return; }
		var o = overlay();
		if (o && !o.hidden && t === o) { closeModal(); }
	}

	function init() {
		injectEmailAction();
		var form = document.querySelector('[data-m24iq="form"]');
		if (form) { form.addEventListener('submit', submitModal); }
		var bizCb = field('biz'); if (bizCb) { bizCb.addEventListener('change', toggleBiz); }
		document.addEventListener('click', onClick);
		document.addEventListener('change', function (e) {
			if (e.target && e.target.matches && e.target.matches('select[name="land"]')) { ppwrFromSelect(e.target); }
		});
		document.addEventListener('input', function (e) {
			if (e.target && e.target.matches && e.target.matches('[data-m24iq="land-input"]')) { onLandInput(e.target); }
		});
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });
		// PPWR initial auf vorhandene Land-Selects anwenden (Sammelanfrage-Formular).
		document.querySelectorAll('select[name="land"]').forEach(ppwrFromSelect);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else { init(); }
})();
