/**
 * M24 DE/EN-Sprach-Switch (Lösung 3, Inline): Globus + „DE · EN" in die tagDiv-Header-Actions.
 * Echte <a href> auf die Ziel-URL der jeweils anderen Sprache (verdrahtet gegen GTranslate /en/).
 * Hover/Focus-Tooltip mit ausgeschriebenem Namen + Flagge. Config aus window.M24Lang.
 */
(function () {
	'use strict';
	var cfg = window.M24Lang || {};
	// DE/EN-Ziel-URLs IMMER client-seitig aus der AKTUELLEN URL ableiten (cache-immun): das server-
	// gerenderte M24Lang kann in gecachten /en/-Seiten veralten (falsches /en/ bzw. /en/en/, aktive
	// Sprache falsch). Führendes /en/ zuerst strippen → kanonischer DE-Basis-Pfad; EN = /en/ + Basis.
	// DE-Link = Basis (ohne /en/), EN-Link = /en/ + Basis (nie /en/en/). Query + Hash bleiben erhalten.
	(function () {
		var path = location.pathname || '/';
		var isEn = /^\/en(\/|$)/.test(path);
		var base = isEn ? (path.replace(/^\/en/, '') || '/') : path;
		var enPath = isEn ? path : '/en' + (base === '/' ? '/' : base);
		var origin = location.origin || (location.protocol + '//' + location.host);
		var tail = (location.search || '') + (location.hash || '');
		cfg.active = isEn ? 'en' : 'de';
		cfg.de = origin + base + tail;
		cfg.en = origin + enPath + tail;
	})();
	if (!cfg.de || !cfg.en) { return; }
	if (document.querySelector('.m24langsw')) { return; }

	var active = cfg.active === 'en' ? 'en' : 'de';

	function lnk(code, url, label, tip) {
		var a = document.createElement('a');
		a.className = 'm24langsw-lnk' + (active === code ? ' is-active' : '');
		a.href = url;
		a.setAttribute('hreflang', code);
		a.setAttribute('aria-label', tip);
		a.style.position = 'relative'; // Anker für das Tooltip (auch bei veraltetem Server-CSS)
		if (active === code) { a.setAttribute('aria-current', 'true'); }
		// Inline NUR das kompakte Label (DE/EN). Name + Flagge kommen in ein Tooltip-Element, das per
		// INLINE-Styles bis :hover/:focus visuell verborgen ist → cache-immun (unabhängig von evtl.
		// veraltetem Server-CSS erscheinen Name/Flagge nie inline).
		a.appendChild(document.createTextNode(label));
		// Kein sichtbares Hover-Tooltip mehr („Deutsch"/„English"). Der Sprachname bleibt als aria-label
		// (oben gesetzt) für Screenreader erhalten.
		return a;
	}

	// Frische Instanz je Ziel (Desktop + Mobil brauchen eigene Knoten, kein cloneNode-Teilen).
	function makeSwitch(ctx) {
		var wrap = document.createElement('div');
		wrap.className = 'm24langsw m24langsw--inhdr';
		wrap.setAttribute('role', 'navigation');
		wrap.setAttribute('aria-label', 'Sprache');
		wrap.setAttribute('data-m24langsw-ctx', ctx);
		wrap.appendChild(lnk('de', cfg.de, 'DE', 'Deutsch'));
		var sep = document.createElement('span');
		sep.className = 'm24langsw-sep';
		sep.setAttribute('aria-hidden', 'true');
		sep.textContent = '·';
		wrap.appendChild(sep);
		wrap.appendChild(lnk('en', cfg.en, 'EN', 'English'));
		return wrap;
	}

	function isVisible(el) {
		// offsetParent!==null schließt display:none-Ancestors aus (z. B. .td-header-mobile-wrap auf Desktop).
		return !!el && (el.offsetParent !== null || (el.getClientRects && el.getClientRects().length > 0));
	}
	function inMobile(el) {
		return !!(el && el.closest && el.closest('.tdb_mobile_search, .tdb-header-search-button-mob, .tdb-mobile-search-icon, .td-header-mobile-wrap, #td-mobile-nav, .td-mobile-content'));
	}
	function firstVisible(selectors) {
		for (var i = 0; i < selectors.length; i++) {
			var nodes = document.querySelectorAll(selectors[i]);
			for (var j = 0; j < nodes.length; j++) { if (isVisible(nodes[j]) && !inMobile(nodes[j])) { return nodes[j]; } }
		}
		return null;
	}

	// Sichtbaren Desktop-Header-Actions-Bereich (tdb: neben dem Such-Icon) — NUR sichtbare Kandidaten.
	var DESKTOP = [
		'.tdb-header-search-wrap', '.tdb_header_search', '.tdb-block-inner .td-icon-search',
		'.td-header-menu-social', '.td-header-sp-top-menu', '.top-header-menu',
		'.tdb-header-align', '.td-header-menu-wrap-full'
	];
	// Such-Icon-Selektoren (tdb/Newspaper): dessen sichtbarer Container = Header-Actions-Bereich.
	var SEARCH = [ '.tdb-search-icon', '.tdb-head-search-btn', '.td-icon-search', '.tdb_header_search a', '.td-search-opener' ];
	// Mobil-Menü/-Header — bewusst OHNE Sichtbarkeits-Filter (ist bis zum Öffnen display:none).
	var MOBILE = [ '#td-mobile-nav .td-menu-login', '#td-mobile-nav ul', '.td-mobile-content', '.td-header-mobile-wrap' ];

	function pickMobile() {
		for (var i = 0; i < MOBILE.length; i++) { var n = document.querySelector(MOBILE[i]); if (n) { return n; } }
		return null;
	}

	// Sichtbares Desktop-Such-Icon → daneben (gleiche Flex-Zeile) einfügen = korrekte vertikale Höhe.
	function visibleSearchIcon() {
		for (var i = 0; i < SEARCH.length; i++) {
			var nodes = document.querySelectorAll(SEARCH[i]);
			for (var j = 0; j < nodes.length; j++) { if (isVisible(nodes[j]) && !inMobile(nodes[j])) { return nodes[j]; } }
		}
		return null;
	}

	// Ist der mobile tagDiv-Header aktiv? Dann darf der Switch NIE als Float im oberen Balken landen.
	function mobileHeaderActive() {
		try { if (window.matchMedia && window.matchMedia('(max-width:1018px)').matches) { return true; } } catch (e) {}
		return !!(isVisible(document.querySelector('.td-header-mobile-wrap')) || document.querySelector('.tdb_mobile_search'));
	}

	// Genau eine Instanz je Kontext; Referenzen halten, um bei Moduswechsel sauber aufzuräumen.
	var els = { desktop: null, mobile: null, float: null };
	function removeEl(k) { if (els[k] && els[k].parentNode) { els[k].parentNode.removeChild(els[k]); } els[k] = null; }
	function placeDesktopSwitch() {
		var icon = visibleSearchIcon();
		var btn  = icon && icon.closest ? ( icon.closest('.tdb-head-search-btn, .tdb_header_search, .tdb-header-search-wrap') || icon ) : icon;
		if (btn && btn.parentNode) { els.desktop = makeSwitch('desktop'); btn.parentNode.insertBefore(els.desktop, btn); return true; }
		var host = firstVisible(DESKTOP);
		if (host) { els.desktop = makeSwitch('desktop'); host.appendChild(els.desktop); return true; }
		return false;
	}
	// Autoritative Entscheidung — jederzeit erneut aufrufbar (Race-fest): mobil ⇒ NUR Hamburger, nie Balken/Float.
	function evaluate() {
		if (mobileHeaderActive()) {
			removeEl('desktop'); removeEl('float'); // falsch vorplatzierte Balken-Instanzen entfernen
			if (!els.mobile || !els.mobile.parentNode) {
				removeEl('mobile');
				var ham = document.querySelector('#td-mobile-nav .td-menu-login, #td-mobile-nav ul, #td-mobile-nav');
				if (ham) { els.mobile = makeSwitch('mobile'); ham.appendChild(els.mobile); }
			}
			return;
		}
		// Desktop: Hamburger-Instanz raus; im Balken platzieren; Float NUR wenn gar kein Desktop-Host da ist.
		removeEl('mobile');
		if (els.desktop && els.desktop.parentNode) { removeEl('float'); return; }
		removeEl('desktop');
		if (placeDesktopSwitch()) { removeEl('float'); return; }
		if (!els.float) { els.float = makeSwitch('float'); els.float.classList.remove('m24langsw--inhdr'); els.float.classList.add('m24langsw--float'); document.body.appendChild(els.float); }
	}

	// Sofort + Retries (tdb baut den Header async nach) + Neuentscheidung auf load & resize (debounced).
	evaluate();
	var tries = 0;
	var iv = setInterval(function () { tries++; evaluate(); if (tries >= 8) { clearInterval(iv); } }, 300);
	window.addEventListener('load', evaluate);
	var rz;
	window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(evaluate, 150); });
})();
