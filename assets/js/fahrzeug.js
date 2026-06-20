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
	// View-Beacon (cache-sicher, 1×/Session server-seitig dedupliziert).
	track('view');

	document.addEventListener('click', function (e) {
		var t = e.target.closest('[data-m24fz-track]');
		if (t) { track(t.getAttribute('data-m24fz-track')); }
		var sh = e.target.closest('[data-m24fz-share]');
		if (sh) {
			if (navigator.share) { navigator.share({ title: document.title, url: location.href }); }
			else if (navigator.clipboard) { navigator.clipboard.writeText(location.href); sh.textContent = '✓ Link kopiert'; }
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
	function initOverlays() { document.querySelectorAll('.m24fz-galcat[data-total]').forEach(setupOverlay); }
	initOverlays();
	window.addEventListener('load', initOverlays);

	document.addEventListener('click', function (e) {
		var ov = e.target.closest('.m24fz-more-ov');
		if (ov) { e.preventDefault(); e.stopPropagation(); var g = ov.closest('.m24fz-galcat'); if (g) { expand(g); } return; }
		var less = e.target.closest('.m24fz-gal-less');
		if (less) { e.preventDefault(); var g2 = less.closest('.m24fz-galcat'); if (g2) { collapse(g2); } }
	});
	document.addEventListener('keydown', function (e) {
		if ((e.key === 'Enter' || e.key === ' ') && document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('m24fz-more-ov')) {
			e.preventDefault(); var g = document.activeElement.closest('.m24fz-galcat'); if (g) { expand(g); }
		}
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

	// Hero-Galerie-Button → erste Kachel der aktiven Kategorie anklicken (öffnet Jetpack-Carousel).
	document.querySelectorAll('.m24fz-gal-launch').forEach(function (b) {
		b.addEventListener('click', function () {
			var g = document.querySelector('.m24fz-galcat[data-catwrap]:not([hidden])'); if (!g) { return; }
			var img = g.querySelector('.tiled-gallery__item img, .tiled-gallery-item img'); if (img) { img.click(); }
		});
	});
})();
