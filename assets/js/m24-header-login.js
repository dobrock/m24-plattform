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
	// Kein Doppel-Icon: sitzt neben der Lupe schon ein M24-Login-/Personen-Icon (auch aus m24-login.js)?
	function mobileIconAlreadyThere(msearch) {
		var p = msearch && msearch.parentNode;
		return !!(p && p.querySelector('.m24hl-acct--mob, .m24lg-micon'));
	}
	// Ausgeloggten „Login"-Text-Chip zu einem dezenten Personen-Umriss-Icon machen (Mobile).
	var PERSON_SVG = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.6"></circle><path d="M5 20c0-3.6 3.4-5.6 7-5.6s7 2 7 5.6"></path></svg>';
	function toPersonIcon(el) {
		var chip = el.querySelector('.m24hl-chip');
		if (chip) { chip.innerHTML = PERSON_SVG; chip.setAttribute('aria-label', 'Login'); }
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
		// (2) Mobile: kein sichtbares Desktop-Icon → Personen-Icon DIREKT LINKS neben die mobile Such-Lupe.
		if (!placed) {
			var msearch = visibleMobileSearch();
			if (msearch && msearch.parentNode && !mobileIconAlreadyThere(msearch)) {
				el.classList.remove('m24hl-acct--inhdr');
				el.classList.add('m24hl-acct--mob');
				toPersonIcon(el); // ausgeloggter „Login"-Text → Personen-Umriss
				msearch.parentNode.insertBefore(el, msearch);
				placed = true;
			}
		}
		// (3) Float-Fallback NUR, wenn KEIN mobiler Header aktiv ist (sonst überlappt das schwebende Oval den Balken).
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
