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
		var tipEl = document.createElement('span');
		tipEl.className = 'm24langsw-tip';
		tipEl.setAttribute('aria-hidden', 'true');
		tipEl.textContent = tip;
		tipEl.style.cssText = 'position:absolute;left:50%;top:calc(100% + 8px);transform:translateX(-50%);'
			+ 'white-space:nowrap;background:#14161a;color:#fff;font-weight:400;font-size:12px;padding:5px 9px;'
			+ 'border-radius:6px;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .12s;'
			+ 'box-shadow:0 4px 14px rgba(0,0,0,.3);z-index:2';
		a.appendChild(tipEl);
		var show = function () { tipEl.style.opacity = '1'; tipEl.style.visibility = 'visible'; };
		var hide = function () { tipEl.style.opacity = '0'; tipEl.style.visibility = 'hidden'; };
		a.addEventListener('mouseenter', show);
		a.addEventListener('mouseleave', hide);
		a.addEventListener('focus', show);
		a.addEventListener('blur', hide);
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

	var placed = { desktop: false, mobile: false };
	function place() {
		if (!placed.desktop) {
			// Bevorzugt: als SIBLING VOR den Such-BUTTON (nicht in ihn hinein) → gleiche Header-Actions-Zeile,
			// vertikal auf Navi-Höhe. Vom gefundenen Icon zum Button-Wrapper hochklettern.
			var icon = visibleSearchIcon();
			var btn  = icon && icon.closest ? ( icon.closest('.tdb-head-search-btn, .tdb_header_search, .tdb-header-search-wrap') || icon ) : icon;
			if (btn && btn.parentNode) {
				btn.parentNode.insertBefore(makeSwitch('desktop'), btn);
				placed.desktop = true;
			} else {
				var host = firstVisible(DESKTOP);
				if (host) { host.appendChild(makeSwitch('desktop')); placed.desktop = true; }
			}
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
