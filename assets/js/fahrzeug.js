/* M24 Fahrzeug-Detail — Galerie-Filter, Weiterlesen-Flip, Lightbox, Tracking-Beacon. */
(function () {
	'use strict';
	var cfg = window.M24FZ || {};
	function track(what) {
		if (!cfg.ajax || !cfg.pid) { return; }
		var fd = new FormData();
		fd.append('action', 'm24fz_track'); fd.append('post_id', cfg.pid); fd.append('what', what);
		fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: fd, keepalive: true }).catch(function () {});
	}
	// View-Beacon (cache-sicher via REST view-ping; Admins/Bots/Dups serverseitig ausgefiltert).
	if (cfg.viewping && cfg.pid) {
		var vf = new FormData(); vf.append('post_id', cfg.pid);
		fetch(cfg.viewping, { method: 'POST', credentials: 'same-origin', body: vf, keepalive: true }).catch(function () {});
	}

	document.addEventListener('click', function (e) {
		var t = e.target.closest('[data-m24fz-track]');
		if (t) { track(t.getAttribute('data-m24fz-track')); }
		var sh = e.target.closest('[data-m24fz-share]');
		if (sh) {
			if (navigator.share) { navigator.share({ title: document.title, url: location.href }); }
			else if (navigator.clipboard) { navigator.clipboard.writeText(location.href); sh.textContent = '✓ Link kopiert'; }
		}
		// Hero-„Galerie (N)" → smooth-Scroll zur Galerie-Sektion. FRÜHE Document-Delegation,
		// damit Bindung weder von DOM-Timing noch von einem späteren JS-Fehler abhängt.
		var gl = e.target.closest('.m24fz-gal-launch');
		if (gl) {
			e.preventDefault();
			var sec = document.getElementById('galerie');
			if (sec) {
				var rm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				sec.scrollIntoView({ behavior: rm ? 'auto' : 'smooth', block: 'start' });
			}
		}
	});

	// Weiterlesen-Fly-out (Slide + Fade, Chevron dreht 180°).
	var more = document.querySelector('.m24fz-more'), body = document.querySelector('.m24fz-desc-body');
	if (more && body) {
		more.addEventListener('click', function () {
			var open = body.classList.toggle('open'); body.classList.toggle('clamp', !open);
			more.classList.toggle('open', open);
			var t = more.querySelector('.t'); if (t) { t.textContent = open ? 'Weniger anzeigen' : 'Weiterlesen'; }
		});
	}

	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	// Mediagalerie-Chips (Kategorie-Wrapper umschalten — Jetpack-Tiled-Mosaik je Kategorie, Video eigener Tab).
	var chips = document.querySelectorAll('.m24fz-chip'), wraps = document.querySelectorAll('[data-catwrap]');
	chips.forEach(function (c) {
		c.addEventListener('click', function () {
			chips.forEach(function (x) { x.classList.remove('on'); });
			c.classList.add('on');
			var cat = c.getAttribute('data-cat');
			wraps.forEach(function (w) { w.hidden = w.getAttribute('data-catwrap') !== cat; });
			// Jetpack vermisst Tiled-Galerien, die erst jetzt Breite > 0 haben.
			try { window.dispatchEvent(new Event('resize')); } catch (err) {}
		});
	});

	function jpTiles(scope) {
		return [].slice.call(scope.querySelectorAll('.tiled-gallery__item, .tiled-gallery-item, .tiled-gallery-item-small, .tiled-gallery__item--last'));
	}

	// „9 + +X + Fly-out" OBENDRAUF auf dem Jetpack-Tiled-Mosaik (Jetpack bestimmt die Kachelgrößen).
	function setupOverlay(galcat) {
		var preview = galcat.querySelector('.m24fz-tg-preview'); if (!preview) { return; }
		var rest = preview.getAttribute('data-rest') || '';
		var tiles = jpTiles(preview); if (!tiles.length) { return; }
		var host = tiles[tiles.length - 1]; // letzte (= 9.) Vorschau-Kachel
		host.classList.add('m24fz-ov-host');
		if (host.querySelector('.m24fz-more-ov')) { return; }
		var ov = document.createElement('span');
		ov.className = 'm24fz-more-ov'; ov.setAttribute('role', 'button'); ov.setAttribute('tabindex', '0');
		ov.setAttribute('aria-label', 'Alle Bilder anzeigen');
		ov.innerHTML = '<b>+' + rest + '</b><small>Alle Bilder</small>';
		// EIGENER Handler direkt auf der +X-Kachel: stoppt den Klick, BEVOR ihn Jetpacks Carousel
		// (auf Tile-/Gallery-Ebene) abgreift → Inline-Aufklappen statt Lightbox.
		ov.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); expand(galcat); }, true);
		ov.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); expand(galcat); }
		});
		host.appendChild(ov);
	}
	function expand(galcat) {
		var preview = galcat.querySelector('.m24fz-tg-preview'), full = galcat.querySelector('.m24fz-tg-full'), less = galcat.querySelector('.m24fz-gal-less');
		if (preview) { preview.hidden = true; }
		if (full) { full.hidden = false; if (!reduce) { full.classList.add('m24fz-flyin'); } try { window.dispatchEvent(new Event('resize')); } catch (e) {} }
		if (less) { less.hidden = false; }
		galcat.classList.add('expanded');
	}
	function collapse(galcat) {
		var preview = galcat.querySelector('.m24fz-tg-preview'), full = galcat.querySelector('.m24fz-tg-full'), less = galcat.querySelector('.m24fz-gal-less');
		if (full) { full.hidden = true; full.classList.remove('m24fz-flyin'); }
		if (preview) { preview.hidden = false; }
		if (less) { less.hidden = true; }
		galcat.classList.remove('expanded');
		galcat.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
	}
	// Overlays initialisieren (Jetpack rendert evtl. erst nach load).
	function initOverlays() { try { document.querySelectorAll('.m24fz-galcat[data-total]').forEach(setupOverlay); } catch (err) {} }
	initOverlays();
	window.addEventListener('load', initOverlays);

	// „Weniger anzeigen" liegt außerhalb der Jetpack-Galerie → Document-Delegation reicht.
	// (Das „+X" selbst hat einen eigenen Handler in setupOverlay, damit Jetpack es nicht abfängt.)
	document.addEventListener('click', function (e) {
		var less = e.target.closest('.m24fz-gal-less');
		if (less) { e.preventDefault(); var g2 = less.closest('.m24fz-galcat'); if (g2) { collapse(g2); } }
	});

	// Bild-Lightbox liefert Jetpack-Carousel. Hier nur die Video-Lightbox (youtube-nocookie, erst bei Klick).
	var lb = document.querySelector('.m24fz-lb'); if (lb) {
		var frame = lb.querySelector('.m24fz-lb-frame');
		function close() { lb.hidden = true; document.body.style.overflow = ''; lb.classList.remove('video'); if (frame) { frame.innerHTML = ''; } }
		document.addEventListener('click', function (e) {
			var v = e.target.closest('.m24fz-video'); if (!v || !frame) { return; }
			e.preventDefault(); var yid = v.getAttribute('data-ytid'); if (!yid) { return; }
			lb.classList.add('video');
			frame.innerHTML = '<iframe src="https://www.youtube-nocookie.com/embed/' + yid + '?autoplay=1&rel=0" title="Video" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>';
			lb.hidden = false; document.body.style.overflow = 'hidden';
		});
		lb.querySelector('.m24fz-lb-close').addEventListener('click', close);
		lb.addEventListener('click', function (e) { if (e.target === lb) { close(); } });
		document.addEventListener('keydown', function (e) { if (!lb.hidden && e.key === 'Escape') { close(); } });
	}

	// (Hero-„Galerie"-Scroll ist oben als Document-Delegation gebunden — robust ggü. Timing/Fehlern.)

	// Galerie-Bilder im Hintergrund vorladen (versteckte Vollgalerie sofort da beim Ausklappen).
	// Nicht den initialen Load blockieren: erst nach 'load', dann via Idle, gedrosselt (5 parallel).
	function preloadGalleries() {
		var imgs = [].slice.call(document.querySelectorAll('.m24fz-galcat img'));
		var urls = [];
		imgs.forEach(function (img) {
			var u = img.getAttribute('data-lazy-src') || img.getAttribute('data-src') || img.getAttribute('src') || '';
			if (u && u.indexOf('data:') !== 0) { urls.push(u); }
		});
		urls = urls.filter(function (u, i) { return urls.indexOf(u) === i; }); // dedupe
		var i = 0, active = 0, MAX = 5;
		function pump() {
			while (active < MAX && i < urls.length) {
				var u = urls[i++]; active++;
				var im = new Image();
				im.onload = im.onerror = function () { active--; pump(); };
				im.src = u;
			}
		}
		pump();
	}
	function schedulePreload() {
		if ('requestIdleCallback' in window) { window.requestIdleCallback(preloadGalleries, { timeout: 3000 }); }
		else { setTimeout(preloadGalleries, 1200); }
	}
	if (document.readyState === 'complete') { schedulePreload(); }
	else { window.addEventListener('load', schedulePreload); }
})();
