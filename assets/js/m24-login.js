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

	function place(el) {
		// tagDiv-Header-Actions (rechts, neben Suche). Mehrere Selektoren, erster Treffer gewinnt.
		var host = document.querySelector('.td-header-menu-social, .tdb-header-align .tdb-block-inner, .td-header-sp-top-menu, .top-header-menu');
		if (!host) {
			// Fallback: fixe Ecke oben rechts, damit das Element nie ganz fehlt.
			el.classList.add('m24lg-acct--float');
			document.body.appendChild(el);
			return;
		}
		el.classList.add('m24lg-acct--inhdr');
		host.appendChild(el);
	}

	var trigger = buildTrigger();
	place(trigger);

	/* ── Modal öffnen/schließen (Focus-Trap, ESC, Overlay-Klick) ── */
	var lastFocus = null;
	function openModal() {
		if (!modal) { return; }
		lastFocus = document.activeElement;
		modal.hidden = false;
		document.body.classList.add('m24lg-noscroll');
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
					setStatus(res.d.message || 'Wenn ein Konto zu dieser Adresse existiert, haben wir dir einen Login-Link geschickt. Prüfe dein Postfach.', 'ok');
					if (emailEl) { emailEl.value = ''; }
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
