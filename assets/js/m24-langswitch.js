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

	var wrap = document.createElement('div');
	wrap.className = 'm24langsw';
	wrap.setAttribute('role', 'navigation');
	wrap.setAttribute('aria-label', 'Sprache');
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

	function place() {
		var host = document.querySelector('.td-header-menu-social, .tdb-header-align .tdb-block-inner, .td-header-sp-top-menu, .top-header-menu');
		if (host) { wrap.classList.add('m24langsw--inhdr'); host.appendChild(wrap); return; }
		var m = document.querySelector('#td-mobile-nav .td-menu-login, #td-mobile-nav ul, .td-mobile-content');
		if (m) { wrap.classList.add('m24langsw--inhdr'); m.appendChild(wrap.cloneNode(true)); }
		wrap.classList.add('m24langsw--float');
		document.body.appendChild(wrap);
	}
	place();
})();
