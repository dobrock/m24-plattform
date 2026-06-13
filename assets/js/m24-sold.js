/**
 * M24 Plattform — Verkauft-Ansicht: Desktop-Lightbox mit Alternativen.
 *
 * Zeigt ~5 s nach dem Laden (oder per Exit-Intent) den Alternativen-Block (geklont aus
 * dem Inline-Block .m24-sold-alt) in einer Lightbox mit geblurrtem Hintergrund.
 * NUR Desktop (>= breakpoint) — mobil keine Auto-Lightbox (Intrusive-Interstitial-Schutz).
 * Schliessen via ESC, Hintergrund-Klick, Close-Button. Scroll-Lock waehrend offen.
 * Einmal pro Session.
 */
(function () {
	'use strict';
	var CFG = window.M24Sold || {};
	var BP = CFG.delay ? (CFG.breakpoint || 783) : 783;
	var DELAY = CFG.delay || 5000;
	var SKEY = 'm24SoldLbShown';

	function isDesktop() { return window.matchMedia('(min-width:' + BP + 'px)').matches; }
	function alreadyShown() { try { return sessionStorage.getItem(SKEY) === '1'; } catch (e) { return false; } }
	function markShown() { try { sessionStorage.setItem(SKEY, '1'); } catch (e) {} }

	var lb = null, opened = false, timer = null, srcEl = null;

	function buildLightbox() {
		var src = document.querySelector('.m24-sold-alt:not(.m24-sold-alt--lb)');
		if (!src) { return null; }
		srcEl = src;
		var overlay = document.createElement('div');
		overlay.className = 'm24-sold-lb';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');

		var backdrop = document.createElement('div');
		backdrop.className = 'm24-sold-lb-backdrop';

		var panel = document.createElement('div');
		panel.className = 'm24-sold-lb-panel';

		var close = document.createElement('button');
		close.className = 'm24-sold-lb-close';
		close.setAttribute('aria-label', CFG.i18n ? CFG.i18n.close : 'Schließen');
		close.innerHTML = '&times;';

		var head = document.createElement('div');
		head.className = 'm24-sold-lb-title';
		head.textContent = (CFG.i18n && CFG.i18n.title) ? CFG.i18n.title : '';

		var body = src.cloneNode(true);
		body.classList.add('m24-sold-alt--lb');

		panel.appendChild(close);
		if (head.textContent) { panel.appendChild(head); }
		panel.appendChild(body);
		overlay.appendChild(backdrop);
		overlay.appendChild(panel);

		backdrop.addEventListener('click', closeLb);
		close.addEventListener('click', closeLb);
		return overlay;
	}

	function openLb() {
		if (opened || alreadyShown() || !isDesktop()) { return; }
		lb = buildLightbox();
		if (!lb) { return; }
		document.body.appendChild(lb);
		document.body.classList.add('m24-sold-lb-lock'); // Scroll-Lock
		// reflow → Transition
		// eslint-disable-next-line no-unused-expressions
		lb.offsetHeight;
		lb.classList.add('m24-sold-lb--on');
		if (srcEl) { srcEl.classList.add('is-lb-hidden'); } // Dedup: Inline waehrend Modal aus
		opened = true;
		markShown();
		document.addEventListener('keydown', onKey);
	}

	function closeLb() {
		if (!lb) { return; }
		lb.classList.remove('m24-sold-lb--on');
		if (srcEl) { srcEl.classList.remove('is-lb-hidden'); } // Inline nach Schliessen wieder zeigen
		document.body.classList.remove('m24-sold-lb-lock');
		document.removeEventListener('keydown', onKey);
		var node = lb; lb = null;
		setTimeout(function () { if (node && node.parentNode) { node.parentNode.removeChild(node); } }, 220);
	}

	function onKey(e) { if (e.key === 'Escape') { closeLb(); } }

	function arm() {
		if (!isDesktop() || alreadyShown()) { return; }
		timer = setTimeout(openLb, DELAY);
		// Exit-Intent: Maus verlaesst oben den Viewport
		document.addEventListener('mouseout', function onOut(e) {
			if (!e.relatedTarget && e.clientY <= 0) {
				document.removeEventListener('mouseout', onOut);
				if (timer) { clearTimeout(timer); }
				openLb();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', arm);
	} else {
		arm();
	}
})();
