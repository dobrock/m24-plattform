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

	// Weiterlesen-Flip.
	var more = document.querySelector('.m24fz-more'), body = document.querySelector('.m24fz-desc-body');
	if (more && body) {
		more.addEventListener('click', function () {
			var open = body.classList.toggle('open'); body.classList.toggle('clamp', !open);
			more.textContent = open ? 'Weniger anzeigen' : 'Weiterlesen';
		});
	}

	// Mediagalerie-Chips (Kategorie-Wrapper umschalten — Bilder = Jetpack Tiled, Video = eigener Tab).
	var chips = document.querySelectorAll('.m24fz-chip'), wraps = document.querySelectorAll('[data-catwrap]');
	chips.forEach(function (c) {
		c.addEventListener('click', function () {
			chips.forEach(function (x) { x.classList.remove('on'); });
			c.classList.add('on');
			var cat = c.getAttribute('data-cat');
			wraps.forEach(function (w) { w.hidden = w.getAttribute('data-catwrap') !== cat; });
			// Jetpack Tiled Gallery rechnet ihr Layout bei Breite>0 — sichtbar gewordene Galerie neu vermessen.
			try { window.dispatchEvent(new Event('resize')); } catch (err) {}
		});
	});

	// Hero-Galerie-Button → erstes Bild der aktiven Kategorie öffnen (Jetpack-Carousel bzw. Datei-Link).
	document.querySelectorAll('.m24fz-gal-launch').forEach(function (b) {
		b.addEventListener('click', function () {
			var w = document.querySelector('.m24fz-galcat[data-catwrap]:not([hidden])'); if (!w) { return; }
			var a = w.querySelector('a'); if (a) { a.click(); }
		});
	});

	// Video-Lightbox (youtube-nocookie, erst bei Klick geladen). Bild-Lightbox liefert Jetpack-Carousel.
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
})();
