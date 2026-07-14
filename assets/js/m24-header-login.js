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

	var placed = false;
	function finish(el) {
		wireDropdown(el);
		if (tpl.parentNode) { tpl.parentNode.removeChild(tpl); }
	}
	function place() {
		if (placed) { return; }
		// Mobil injiziert m24-header-login.js NICHTS in den Header — dort liefert m24-login.js das einzige Icon (.m24lg-micon).
		if (mobileHeaderActive()) { return; }
		var el = acct;
		// (1) Desktop: als SIBLING VOR den sichtbaren Desktop-Such-Button (gleiche Header-Actions-Zeile, Navi-Höhe).
		var icon = visibleSearchIcon();
		var btn = icon && icon.closest ? (icon.closest('.tdb-head-search-btn, .tdb_header_search, .tdb-header-search-wrap') || icon) : icon;
		if (btn && btn.parentNode) {
			el.classList.add('m24hl-acct--inhdr');
			btn.parentNode.insertBefore(el, btn);
			placed = true;
		} else {
			var host = firstVisible(['.td-header-menu-social', '.td-header-sp-top-menu', '.tdb-header-align']);
			if (host) { el.classList.add('m24hl-acct--inhdr'); host.appendChild(el); placed = true; }
		}
		// Mobil platziert m24-header-login.js NICHTS: das einzige mobile Login-Icon ist .m24lg-micon (m24-login.js,
		// passwortloser/Garage-Login). Doppel-Icon-Regression aus 0.11.351 damit behoben.
		// Float-Fallback NUR, wenn KEIN mobiler Header aktiv ist (m24hl-acct darf nie über dem Balken schweben).
		if (placed) {
			finish(el);
		} else if (!mobileHeaderActive() && !document.querySelector('.m24hl-acct--float')) {
			el.classList.remove('m24hl-acct--inhdr');
			el.classList.add('m24hl-acct--float');
			document.body.appendChild(el);
			finish(el);
		}
	}

	place();
	var tries = 0;
	var iv = setInterval(function () {
		tries++;
		if (!placed) { place(); }
		if (placed || tries >= 6) { clearInterval(iv); }
	}, 350);
})();
