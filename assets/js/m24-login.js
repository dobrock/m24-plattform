/**
 * M24 Passwordless-Login „D": state-aware Konto-Element im tagDiv-Header + Magic-Link-Modal.
 * Nur In-Page-Patterns (kein natives dialog/confirm/alert). Config aus window.M24Login (wp_localize).
 */
(function () {
	'use strict';
	var cfg = window.M24Login || {};
	if (!cfg.request) { return; }

	var modal = document.getElementById('m24-login-modal');

	/* ── Header-Trigger (Chip bzw. Konto-Button) bauen + in die Header-Actions platzieren ── */
	function buildTrigger() {
		var wrap = document.createElement('div');
		wrap.className = 'm24lg-acct' + (cfg.loggedIn ? ' is-in' : '');
		if (!cfg.loggedIn) {
			wrap.innerHTML = '<button type="button" class="m24lg-chip" data-m24lg-open aria-label="Anmelden">'
				+ '<span class="m24lg-chip-i" aria-hidden="true">●</span><span class="m24lg-chip-t">Anmelden</span></button>';
		} else {
			var items = ''
				+ '<a class="m24lg-item" href="' + (cfg.garageUrl || '#') + '">Meine Garage</a>'
				+ '<a class="m24lg-item" href="' + (cfg.settingsUrl || '#') + '">E-Mail-Einstellungen</a>'
				+ (cfg.isAdmin ? '<a class="m24lg-item" href="' + (cfg.adminUrl || '#') + '">WP-Admin</a>' : '')
				+ '<a class="m24lg-item m24lg-item-logout" href="' + (cfg.logoutUrl || '#') + '">Abmelden</a>';
			wrap.innerHTML = '<button type="button" class="m24lg-accbtn" data-m24lg-menu aria-haspopup="true" aria-expanded="false">'
				+ '<span class="m24lg-avatar" aria-hidden="true">' + (cfg.initial || '•') + '</span>'
				+ '<span class="m24lg-acclabel">Mein Konto</span><span class="m24lg-caret" aria-hidden="true">▾</span></button>'
				+ '<div class="m24lg-menu" data-m24lg-dropdown hidden>' + items + '</div>';
		}
		return wrap;
	}

	/* ── Sichtbarkeits-Gating (analog m24-langswitch.js): mobil ⇒ NUR Icon, desktop ⇒ Chip; kein Float im mobilen Balken. ── */
	function isVisible(el) { return !!el && (el.offsetParent !== null || (el.getClientRects && el.getClientRects().length > 0)); }
	function mobileHeaderActive() {
		try { if (window.matchMedia && window.matchMedia('(max-width:1018px)').matches) { return true; } } catch (e) {}
		return !!(isVisible(document.querySelector('.td-header-mobile-wrap')) || isVisible(document.querySelector('.tdb_mobile_search')));
	}
	// Desktop-Host: ERSTER SICHTBARER Treffer gewinnt (nicht der erste existierende).
	var DESKTOP_HOSTS = ['.td-header-menu-social', '.tdb-header-align .tdb-block-inner', '.td-header-sp-top-menu', '.top-header-menu'];
	function firstVisibleHost() {
		for (var i = 0; i < DESKTOP_HOSTS.length; i++) {
			var nodes = document.querySelectorAll(DESKTOP_HOSTS[i]);
			for (var j = 0; j < nodes.length; j++) { if (isVisible(nodes[j])) { return nodes[j]; } }
		}
		return null;
	}
	function removeNode(el) { if (el && el.parentNode) { el.parentNode.removeChild(el); } }

	// Personen-Icon (mobil, BEIDE Zustände, identische 24×24-Box → kein Header-Sprung beim Login/Logout).
	var PERSON_SVG = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.6"></circle><path d="M5 20c0-3.6 3.4-5.6 7-5.6s7 2 7 5.6"></path></svg>';
	function buildMobileIcon() {
		var el;
		if (cfg.loggedIn) {
			// Eingeloggt: gefülltes Icon + Statuspunkt, direkter Link in die Garage (kein Modal).
			el = document.createElement('a');
			el.href = cfg.garageUrl || '/meine-garage/';
			el.className = 'm24lg-micon m24lg-micon--in';
			el.setAttribute('aria-label', 'Meine Garage');
		} else {
			// Ausgeloggt: Umriss-Icon, öffnet das Login-Modal (delegierter data-m24lg-open-Klick).
			el = document.createElement('button');
			el.type = 'button';
			el.className = 'm24lg-micon';
			el.setAttribute('data-m24lg-open', '');
			el.setAttribute('aria-label', 'Anmelden');
		}
		el.innerHTML = PERSON_SVG;
		return el;
	}
	// Ziel = die INLINE Lupe (Button/Span), NICHT der äußere .tdb_mobile_search-Block (sonst Block-Umbruch).
	function visibleLupe() {
		var n, nodes, i, j;
		nodes = document.querySelectorAll('.tdb-header-search-button-mob');
		for (i = 0; i < nodes.length; i++) { n = nodes[i]; if (isVisible(n)) { return n; } }
		nodes = document.querySelectorAll('.tdb-mobile-search-icon, .td-header-mobile-wrap .td-icon-search, .td-mobile-content .td-icon-search');
		for (j = 0; j < nodes.length; j++) { n = nodes[j]; if (isVisible(n)) { return n.closest('.tdb-header-search-button-mob') || n.parentNode || n; } }
		return null;
	}

	var desktopEl = buildTrigger(); // Desktop-Chip/Konto-Button
	var mobileEl  = buildMobileIcon(); // Mobil-Icon (beide Zustände)

	// Autoritative Entscheidung — jederzeit erneut aufrufbar (Race-fest). tagDiv baut den Header async nach.
	function evaluate() {
		var host = 'none';
		if (mobileHeaderActive()) {
			// Mobil: NUR das Icon neben der Lupe. Desktop-Chip nie im mobilen Header, nie Float.
			removeNode(desktopEl);
			if (!mobileEl.parentNode) {
				var lupe = visibleLupe();
				if (lupe && lupe.parentNode && !lupe.parentNode.querySelector('.m24lg-micon')) {
					lupe.parentNode.insertBefore(mobileEl, lupe);
				}
			}
			host = mobileEl.parentNode ? 'mobile-icon' : 'none';
		} else {
			// Desktop: Chip in den ersten SICHTBAREN Host; Mobil-Icon raus; Float NUR ohne sichtbaren Host.
			removeNode(mobileEl);
			if (!desktopEl.parentNode) {
				var h = firstVisibleHost();
				if (h) { desktopEl.classList.remove('m24lg-acct--float'); desktopEl.classList.add('m24lg-acct--inhdr'); h.appendChild(desktopEl); host = 'inhdr'; }
				else { desktopEl.classList.remove('m24lg-acct--inhdr'); desktopEl.classList.add('m24lg-acct--float'); document.body.appendChild(desktopEl); host = 'float'; }
			} else {
				host = desktopEl.classList.contains('m24lg-acct--float') ? 'float' : 'inhdr';
			}
		}
		loginDbgBadge(host);
	}

	// Debug-Badge nur bei ?m24dbg=1 (eigenes Feld rechts-unten, überlagert den langswitch-Badge nicht).
	function loginDbgBadge(host) {
		var on = false; try { on = new URLSearchParams(location.search).get('m24dbg') === '1'; } catch (e) {}
		var box = document.getElementById('m24dbg-login');
		if (!on) { removeNode(box); return; }
		if (!box) {
			box = document.createElement('div');
			box.id = 'm24dbg-login';
			box.style.cssText = 'position:fixed;right:8px;bottom:8px;z-index:2147483647;background:rgba(0,0,0,.82);color:#fff;font:11px/1.4 monospace;padding:6px 8px;border-radius:6px;white-space:pre;pointer-events:none';
			(document.body || document.documentElement).appendChild(box);
		}
		function vcount(sel) { var n = document.querySelectorAll(sel), c = 0; for (var i = 0; i < n.length; i++) { if (isVisible(n[i])) { c++; } } return c; }
		box.textContent = [
			'loggedIn=' + !!cfg.loggedIn,
			'host=' + host,
			'chip=' + vcount('.m24lg-chip'),
			'float=' + vcount('.m24lg-acct--float'),
			'micon=' + vcount('.m24lg-micon'),
			'lgAcct=' + vcount('.m24lg-acct'),
			'hlAcct=' + vcount('.m24hl-acct'),
			'themeLogin=' + vcount('.td-menu-login, .tdb-header-login')
		].join('\n');
	}

	evaluate();
	var tries = 0;
	var iv = setInterval(function () { tries++; evaluate(); if (tries >= 8) { clearInterval(iv); } }, 300);
	window.addEventListener('load', evaluate);
	var rz;
	window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(evaluate, 150); });

	/* ── Modal öffnen/schließen (Focus-Trap, ESC, Overlay-Klick) ── */
	var lastFocus = null;
	function openModal() {
		if (!modal) { return; }
		lastFocus = document.activeElement;
		modal.hidden = false;
		document.body.classList.add('m24lg-noscroll');
		// Formular aus einem vorherigen „gesendet"-Zustand zurücksetzen (Felder/Button/Intro wieder zeigen).
		var _fld = modal.querySelector('.m24lg-field'), _sub = modal.querySelector('.m24lg-sub'),
			_sb = modal.querySelector('[data-m24lg-submit]'), _st = modal.querySelector('[data-m24lg-status]');
		if (_fld) { _fld.style.display = ''; }
		if (_sub) { _sub.style.display = ''; }
		if (_sb) { _sb.style.display = ''; }
		if (_st) { _st.textContent = ''; _st.className = 'm24lg-status'; }
		var f = modal.querySelector('[data-m24lg-email]');
		if (f) { setTimeout(function () { f.focus(); }, 30); }
		document.addEventListener('keydown', onKey);
	}
	function closeModal() {
		if (!modal) { return; }
		modal.hidden = true;
		document.body.classList.remove('m24lg-noscroll');
		document.removeEventListener('keydown', onKey);
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}
	function onKey(e) {
		if (e.key === 'Escape') { closeModal(); return; }
		if (e.key === 'Tab' && modal && !modal.hidden) {
			var f = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
			f = Array.prototype.filter.call(f, function (n) { return n.offsetParent !== null; });
			if (!f.length) { return; }
			var first = f[0], last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		}
	}

	/* ── Dropdown (eingeloggt) ── */
	function closeMenu() {
		var dd = trigger.querySelector('[data-m24lg-dropdown]');
		var b = trigger.querySelector('[data-m24lg-menu]');
		if (dd) { dd.hidden = true; }
		if (b) { b.setAttribute('aria-expanded', 'false'); }
		document.removeEventListener('click', onDocClick);
	}
	function onDocClick(e) { if (!trigger.contains(e.target)) { closeMenu(); } }

	document.addEventListener('click', function (e) {
		if (e.target.closest && e.target.closest('[data-m24lg-open]')) { e.preventDefault(); openModal(); return; }
		if (e.target.closest && e.target.closest('[data-m24lg-close]')) { e.preventDefault(); closeModal(); return; }
		var menuBtn = e.target.closest && e.target.closest('[data-m24lg-menu]');
		if (menuBtn) {
			e.preventDefault();
			var dd = trigger.querySelector('[data-m24lg-dropdown]');
			if (!dd) { return; }
			var open = dd.hidden;
			dd.hidden = !open;
			menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) { document.addEventListener('click', onDocClick); } else { document.removeEventListener('click', onDocClick); }
		}
	});

	/* ── Request: Magic-Link senden (immer neutrale Erfolgsmeldung) ── */
	var form = modal && modal.querySelector('[data-m24lg-form]');
	if (form) {
		var statusEl = modal.querySelector('[data-m24lg-status]');
		var submit = modal.querySelector('[data-m24lg-submit]');
		function setStatus(t, tone) {
			if (!statusEl) { return; }
			statusEl.textContent = t || '';
			statusEl.className = 'm24lg-status' + (tone ? ' is-' + tone : '');
		}
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var emailEl = modal.querySelector('[data-m24lg-email]');
			var email = (emailEl && emailEl.value || '').trim();
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setStatus('Bitte eine gültige E-Mail-Adresse eingeben.', 'error'); return; }
			if (submit) { submit.disabled = true; }
			setStatus('Wird gesendet …', '');
			fetch(cfg.request, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
				body: JSON.stringify({ email: email })
			}).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
			.then(function (res) {
				if (submit) { submit.disabled = false; }
				if (res.ok && res.d && res.d.ok) {
					// Nur die Bestätigung zeigen — E-Mail-Feld + Button + Intro ausblenden (keine erneute Eingabe).
					var field = modal.querySelector('.m24lg-field'), sub = modal.querySelector('.m24lg-sub');
					if (field) { field.style.display = 'none'; }
					if (sub) { sub.style.display = 'none'; }
					if (submit) { submit.style.display = 'none'; }
					if (emailEl) { emailEl.value = ''; }
					setStatus('Wir haben dir einen Login-Link geschickt. Öffne ihn, um dich einzuloggen.', 'ok');
				} else {
					setStatus((res.d && res.d.message) || 'Aktion fehlgeschlagen. Bitte später erneut.', 'error');
				}
			}).catch(function () {
				if (submit) { submit.disabled = false; }
				setStatus('Aktion fehlgeschlagen. Bitte später erneut.', 'error');
			});
		});
	}

	// Deep-Link aus wp-login-Umleitung: ?m24_login=1 öffnet das Modal beim Laden.
	if (cfg.autoOpen && !cfg.loggedIn) { openModal(); }
})();
