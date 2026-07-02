/**
 * M24 DE/EN-Sprach-Switch (Lösung 3, Inline): Globus + „DE · EN" in die tagDiv-Header-Actions.
 * Echte <a href> auf die Ziel-URL der jeweils anderen Sprache (verdrahtet gegen GTranslate /en/).
 * Hover/Focus-Tooltip mit ausgeschriebenem Namen + Flagge. Config aus window.M24Lang.
 */
(function () {
	'use strict';
	var cfg = window.M24Lang || {};
	if (!cfg.de || !cfg.en) { return; }
	if (document.querySelector('.m24langsw')) { return; }

	var active = cfg.active === 'en' ? 'en' : 'de';

	function lnk(code, url, label, tip) {
		var a = document.createElement('a');
		a.className = 'm24langsw-lnk' + (active === code ? ' is-active' : '');
		a.href = url;
		a.setAttribute('hreflang', code);
		a.setAttribute('aria-label', tip);
		if (active === code) { a.setAttribute('aria-current', 'true'); }
		a.innerHTML = label + '<span class="m24langsw-tip" aria-hidden="true">' + tip + '</span>';
		return a;
	}

	// Frische Instanz je Ziel (Desktop + Mobil brauchen eigene Knoten, kein cloneNode-Teilen).
	function makeSwitch(ctx) {
		var wrap = document.createElement('div');
		wrap.className = 'm24langsw m24langsw--inhdr';
		wrap.setAttribute('role', 'navigation');
		wrap.setAttribute('aria-label', 'Sprache');
		wrap.setAttribute('data-m24langsw-ctx', ctx);
		var globe = document.createElement('span');
		globe.className = 'm24langsw-globe';
		globe.setAttribute('aria-hidden', 'true');
		globe.textContent = '🌐';
		wrap.appendChild(globe);
		wrap.appendChild(lnk('de', cfg.de, 'DE', '🇩🇪 Deutsch'));
		var sep = document.createElement('span');
		sep.className = 'm24langsw-sep';
		sep.setAttribute('aria-hidden', 'true');
		sep.textContent = '·';
		wrap.appendChild(sep);
		wrap.appendChild(lnk('en', cfg.en, 'EN', '🇬🇧 English'));
		return wrap;
	}

	function isVisible(el) {
		// offsetParent!==null schließt display:none-Ancestors aus (z. B. .td-header-mobile-wrap auf Desktop).
		return !!el && (el.offsetParent !== null || (el.getClientRects && el.getClientRects().length > 0));
	}
	function firstVisible(selectors) {
		for (var i = 0; i < selectors.length; i++) {
			var nodes = document.querySelectorAll(selectors[i]);
			for (var j = 0; j < nodes.length; j++) { if (isVisible(nodes[j])) { return nodes[j]; } }
		}
		return null;
	}

	// Sichtbaren Desktop-Header-Actions-Bereich (tdb: neben dem Such-Icon) — NUR sichtbare Kandidaten.
	var DESKTOP = [
		'.tdb-header-search-wrap', '.tdb_header_search', '.tdb-block-inner .td-icon-search',
		'.td-header-menu-social', '.td-header-sp-top-menu', '.top-header-menu',
		'.tdb-header-align', '.td-header-menu-wrap-full'
	];
	// Mobil-Menü/-Header — bewusst OHNE Sichtbarkeits-Filter (ist bis zum Öffnen display:none).
	var MOBILE = [ '#td-mobile-nav .td-menu-login', '#td-mobile-nav ul', '.td-mobile-content', '.td-header-mobile-wrap' ];

	function pickMobile() {
		for (var i = 0; i < MOBILE.length; i++) { var n = document.querySelector(MOBILE[i]); if (n) { return n; } }
		return null;
	}

	var placed = { desktop: false, mobile: false };
	function place() {
		if (!placed.desktop) {
			var host = firstVisible(DESKTOP);
			if (host) { host.appendChild(makeSwitch('desktop')); placed.desktop = true; }
		}
		if (!placed.mobile) {
			var m = pickMobile();
			if (m) { m.appendChild(makeSwitch('mobile')); placed.mobile = true; }
		}
		// Fallback: gibt es KEINE sichtbare Desktop-Instanz → fixe Ecke, damit der Switch nie ganz fehlt.
		if (!placed.desktop && !document.querySelector('.m24langsw--float')) {
			var f = makeSwitch('float'); f.classList.remove('m24langsw--inhdr'); f.classList.add('m24langsw--float');
			document.body.appendChild(f);
		}
	}

	// Sofort + Retries: tdb baut den Header teils per JS nach dem DOMContentLoaded.
	place();
	var tries = 0;
	var iv = setInterval(function () {
		tries++;
		if (!placed.desktop) { place(); }
		if (placed.desktop || tries >= 6) { clearInterval(iv); }
	}, 350);
})();
