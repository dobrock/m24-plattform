/**
 * M24 G2a Header-Login im „D"-Look: platziert das server-gerenderte #m24-b2b-login-Element in die
 * sichtbaren Header-Actions (neben Switch/Suche) + verdrahtet das Konto-Dropdown. Nur In-Page (kein Alert).
 * Ausgeloggt = Link auf /haendler-login/ (bestehende sichere Magic-Link-Strecke); eingeloggt = Dropdown.
 */
(function () {
	'use strict';
	var tpl = document.getElementById('m24-b2b-login');
	if (!tpl) { return; }
	var acct = tpl.querySelector('.m24hl-acct');
	if (!acct) { return; }

	function isVisible(el) {
		return !!el && (el.offsetParent !== null || (el.getClientRects && el.getClientRects().length > 0));
	}
	function inMobile(el) {
		return !!(el && el.closest && el.closest('.tdb_mobile_search, .tdb-header-search-button-mob, .tdb-mobile-search-icon, .td-header-mobile-wrap, #td-mobile-nav, .td-mobile-content'));
	}
	function visibleSearchIcon() {
		var sels = ['.tdb-head-search-btn', '.tdb-search-icon', '.td-icon-search', '.tdb_header_search a', '.td-search-opener'];
		for (var i = 0; i < sels.length; i++) {
			var nodes = document.querySelectorAll(sels[i]);
			for (var j = 0; j < nodes.length; j++) { if (isVisible(nodes[j]) && !inMobile(nodes[j])) { return nodes[j]; } }
		}
		return null;
	}
	// Sichtbares MOBILES Such-Element (die Lupe im oberen mobilen Balken).
	function visibleMobileSearch() {
		var sels = ['.tdb-header-search-button-mob', '.tdb-mobile-search-icon', '.tdb_mobile_search'];
		for (var i = 0; i < sels.length; i++) {
			var nodes = document.querySelectorAll(sels[i]);
			for (var j = 0; j < nodes.length; j++) { if (isVisible(nodes[j])) { return nodes[j]; } }
		}
		return null;
	}
	// Mobiler tagDiv-Header aktiv? Dann nie floaten (überlappt sonst den Balken).
	function mobileHeaderActive() {
		try { if (window.matchMedia && window.matchMedia('(max-width:1018px)').matches) { return true; } } catch (e) {}
		return !!(isVisible(document.querySelector('.td-header-mobile-wrap')) || visibleMobileSearch());
	}
	function firstVisible(sels) {
		for (var i = 0; i < sels.length; i++) {
			var nodes = document.querySelectorAll(sels[i]);
			for (var j = 0; j < nodes.length; j++) { if (isVisible(nodes[j]) && !inMobile(nodes[j])) { return nodes[j]; } }
		}
		return null;
	}

	function wireDropdown(root) {
		if (root.dataset.m24hlWired) { return; }
		root.dataset.m24hlWired = '1';
		var btn = root.querySelector('[data-m24hl-menu]');
		var dd = root.querySelector('[data-m24hl-dropdown]');
		if (!btn || !dd) { return; }
		function close() { dd.hidden = true; btn.setAttribute('aria-expanded', 'false'); document.removeEventListener('click', onDoc); }
		function onDoc(e) { if (!root.contains(e.target)) { close(); } }
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			var open = dd.hidden;
			dd.hidden = !open;
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) { document.addEventListener('click', onDoc); } else { document.removeEventListener('click', onDoc); }
		});
	}

	var placedMode = null; // 'desktop' | 'desktop-float' | 'mobile-none'
	function detach(el) {
		if (el && el.parentNode) { el.parentNode.removeChild(el); }
		el.classList.remove('m24hl-acct--inhdr', 'm24hl-acct--float', 'm24hl-acct--mob');
	}
	function placeDesktop(el) {
		// SIBLING VOR den sichtbaren Desktop-Such-Button (gleiche Header-Actions-Zeile, Navi-Höhe).
		var icon = visibleSearchIcon();
		var btn = icon && icon.closest ? (icon.closest('.tdb-head-search-btn, .tdb_header_search, .tdb-header-search-wrap') || icon) : icon;
		if (btn && btn.parentNode) {
			el.classList.remove('m24hl-acct--float'); el.classList.add('m24hl-acct--inhdr');
			btn.parentNode.insertBefore(el, btn); wireDropdown(el); return 'desktop';
		}
		var host = firstVisible(['.td-header-menu-social', '.td-header-sp-top-menu', '.tdb-header-align']);
		if (host) {
			el.classList.remove('m24hl-acct--float'); el.classList.add('m24hl-acct--inhdr');
			host.appendChild(el); wireDropdown(el); return 'desktop';
		}
		// Kein Desktop-Host sichtbar → Float (nur hier zulässig, da mobileHeaderActive() bereits false ist).
		el.classList.remove('m24hl-acct--inhdr'); el.classList.add('m24hl-acct--float');
		document.body.appendChild(el); wireDropdown(el); return 'desktop-float';
	}
	// Autoritative Entscheidung — jederzeit erneut aufrufbar (Race-fest).
	function evaluate() {
		var el = acct;
		if (mobileHeaderActive()) {
			// Mobil: header-login platziert NICHTS im oberen Balken; das mobile Icon liefert .m24lg-micon (m24-login.js).
			// Falsch vorplatziertes Desktop/Float aufräumen (behebt das Timing-Race).
			if (placedMode !== 'mobile-none') { detach(el); placedMode = 'mobile-none'; }
			return;
		}
		if (placedMode === 'desktop' && el.parentNode && el.classList.contains('m24hl-acct--inhdr')) { return; }
		if (placedMode === 'desktop-float' && el.parentNode) { return; }
		placedMode = placeDesktop(el);
	}

	evaluate();
	var tries = 0;
	var iv = setInterval(function () { tries++; evaluate(); if (tries >= 8) { clearInterval(iv); } }, 300);
	window.addEventListener('load', evaluate);
	var rz;
	window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(evaluate, 150); });

	// Debug-Badge (temporär, nur bei ?m24dbg=1) — Live-Werte für die Race-Diagnose.
	(function debugBadge() {
		if (!/[?&]m24dbg=1(?:&|$)/.test(location.search)) { return; }
		if (document.getElementById('m24dbg-badge')) { return; }
		var box = document.createElement('div');
		box.id = 'm24dbg-badge';
		box.style.cssText = 'position:fixed;top:6px;right:6px;z-index:2147483647;background:rgba(0,0,0,.85);color:#0f0;font:11px/1.45 monospace;padding:8px 10px;border-radius:6px;max-width:72vw;white-space:pre;pointer-events:none';
		(document.body || document.documentElement).appendChild(box);
		function has(sel) { return document.querySelector(sel) ? 1 : 0; }
		function refresh() {
			var mm = 0; try { mm = window.matchMedia('(max-width:1018px)').matches ? 1 : 0; } catch (e) {}
			box.textContent = [
				'iw=' + window.innerWidth,
				'mm<=1018=' + mm,
				'mobActive=' + (mobileHeaderActive() ? 1 : 0),
				'mobWrapVis=' + (isVisible(document.querySelector('.td-header-mobile-wrap')) ? 1 : 0),
				'dtSearchVis=' + (isVisible(document.querySelector('.tdb-head-search-btn')) ? 1 : 0),
				'mobSearch=' + (visibleMobileSearch() ? 1 : 0),
				'.m24lg-micon=' + has('.m24lg-micon'),
				'.m24hl--inhdr=' + has('.m24hl-acct--inhdr'),
				'.m24hl--float=' + has('.m24hl-acct--float'),
				'.m24langsw--inhdr=' + has('.m24langsw--inhdr'),
				'.m24langsw--float=' + has('.m24langsw--float')
			].join('\n');
		}
		refresh();
		setInterval(refresh, 500);
		window.addEventListener('resize', refresh);
	})();
})();
