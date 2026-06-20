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

	// ── Justified-Mosaik-Layout (Zeilenhöhe konstant, Breite ∝ Seitenverhältnis, Reihe füllt voll) ──
	var GAP = 8, ROW_H = 230, ROW_MAX = ROW_H * 1.5;
	function layout(mosaic) {
		if (!mosaic || mosaic.hidden) { return; }
		var cw = mosaic.clientWidth; if (!cw) { return; }
		mosaic.classList.add('m24fz-jslayout');
		var tiles = [].slice.call(mosaic.querySelectorAll('.m24fz-mitem')).filter(function (t) { return !t.hidden; });
		var row = [], sumR = 0;
		function flush(isLast) {
			if (!row.length) { return; }
			var avail = cw - GAP * (row.length - 1);
			var h = isLast ? ROW_H : avail / sumR;
			if (h > ROW_MAX) { h = ROW_MAX; }
			row.forEach(function (t) {
				var r = parseFloat(t.getAttribute('data-ratio')) || 1.5;
				t.style.width = Math.floor(r * h) + 'px';
				t.style.height = Math.round(h) + 'px';
			});
			row = []; sumR = 0;
		}
		tiles.forEach(function (t) {
			var r = parseFloat(t.getAttribute('data-ratio')) || 1.5;
			row.push(t); sumR += r;
			if (sumR * ROW_H + GAP * (row.length - 1) >= cw) { flush(false); }
		});
		flush(true);
	}
	function layoutVisible() { document.querySelectorAll('.m24fz-mosaic[data-catwrap]:not([hidden])').forEach(layout); }

	// Mediagalerie-Chips (Kategorie-Wrapper umschalten — Mosaik je Kategorie, Video eigener Tab).
	var chips = document.querySelectorAll('.m24fz-chip'), wraps = document.querySelectorAll('[data-catwrap]');
	chips.forEach(function (c) {
		c.addEventListener('click', function () {
			chips.forEach(function (x) { x.classList.remove('on'); });
			c.classList.add('on');
			var cat = c.getAttribute('data-cat');
			wraps.forEach(function (w) { w.hidden = w.getAttribute('data-catwrap') !== cat; });
			layoutVisible(); // sichtbar gewordene Galerie justieren (im versteckten Tab war clientWidth=0)
		});
	});

	// Erstes Layout + bei Resize (debounced).
	layoutVisible();
	window.addEventListener('load', layoutVisible);
	var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(layoutVisible, 120); });

	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	// „9 + +X + Fly-out": Bilder 10+ erst beim Aufklappen laden + gestaffelt einblenden.
	function expand(mosaic) {
		var extras = mosaic.querySelectorAll('.m24fz-extra');
		extras.forEach(function (a, i) {
			var img = a.querySelector('img');
			if (img && !img.getAttribute('src') && img.getAttribute('data-src')) { img.setAttribute('src', img.getAttribute('data-src')); }
			a.hidden = false;
			if (!reduce) { a.style.animationDelay = (i * 45) + 'ms'; a.classList.add('m24fz-flyin'); }
		});
		var ov = mosaic.querySelector('.m24fz-more-ov'); if (ov) { ov.style.display = 'none'; }
		var less = mosaic.querySelector('.m24fz-gal-less'); if (less) { less.hidden = false; }
		mosaic.classList.add('expanded');
		layout(mosaic); // neue Kacheln ins justierte Mosaik einrechnen
	}
	function collapse(mosaic) {
		mosaic.querySelectorAll('.m24fz-extra').forEach(function (a) { a.hidden = true; a.classList.remove('m24fz-flyin'); a.style.animationDelay = ''; });
		var ov = mosaic.querySelector('.m24fz-more-ov'); if (ov) { ov.style.display = ''; }
		var less = mosaic.querySelector('.m24fz-gal-less'); if (less) { less.hidden = true; }
		mosaic.classList.remove('expanded');
		layout(mosaic);
		mosaic.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
	}
	document.addEventListener('click', function (e) {
		var ov = e.target.closest('.m24fz-more-ov');
		if (ov) { e.preventDefault(); e.stopPropagation(); var m = ov.closest('.m24fz-mosaic'); if (m) { expand(m); } return; }
		var less = e.target.closest('.m24fz-gal-less');
		if (less) { e.preventDefault(); var m2 = less.closest('.m24fz-mosaic'); if (m2) { collapse(m2); } }
	});
	document.addEventListener('keydown', function (e) {
		if ((e.key === 'Enter' || e.key === ' ') && document.activeElement && document.activeElement.classList && document.activeElement.classList.contains('m24fz-more-ov')) {
			e.preventDefault(); var m = document.activeElement.closest('.m24fz-mosaic'); if (m) { expand(m); }
		}
	});

	// Lightbox: Bilder (Slideshow je Kategorie, inkl. eingeklappter) + Video (youtube-nocookie, erst bei Klick).
	var lb = document.querySelector('.m24fz-lb'); if (lb) {
		var lbImg = lb.querySelector('img'), frame = lb.querySelector('.m24fz-lb-frame'), pics = [], idx = 0;
		function collect() {
			pics = [];
			var w = document.querySelector('.m24fz-mosaic[data-catwrap]:not([hidden])'); if (!w) { return; }
			w.querySelectorAll('.m24fz-mitem').forEach(function (a) { pics.push(a.getAttribute('href')); }); // alle, auch eingeklappte
		}
		function open() { lb.hidden = false; document.body.style.overflow = 'hidden'; }
		function close() { lb.hidden = true; document.body.style.overflow = ''; lb.classList.remove('video'); if (frame) { frame.innerHTML = ''; } }
		function showImg(i) { if (!pics.length) { return; } lb.classList.remove('video'); idx = (i + pics.length) % pics.length; lbImg.src = pics[idx]; }
		function showVideo(yid) { if (!frame || !yid) { return; } lb.classList.add('video');
			frame.innerHTML = '<iframe src="https://www.youtube-nocookie.com/embed/' + yid + '?autoplay=1&rel=0" title="Video" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>'; }
		document.addEventListener('click', function (e) {
			var v = e.target.closest('.m24fz-video');
			if (v) { e.preventDefault(); showVideo(v.getAttribute('data-ytid')); open(); return; }
			if (e.target.closest('.m24fz-more-ov')) { return; } // +X klappt auf, kein Lightbox
			var a = e.target.closest('.m24fz-mitem:not(.m24fz-video)');
			if (a) { e.preventDefault(); collect(); showImg(pics.indexOf(a.getAttribute('href'))); open(); }
		});
		lb.querySelector('.m24fz-lb-close').addEventListener('click', close);
		lb.querySelector('.m24fz-lb-prev').addEventListener('click', function () { showImg(idx - 1); });
		lb.querySelector('.m24fz-lb-next').addEventListener('click', function () { showImg(idx + 1); });
		lb.addEventListener('click', function (e) { if (e.target === lb) { close(); } });
		document.addEventListener('keydown', function (e) {
			if (lb.hidden) { return; }
			if (e.key === 'Escape') { close(); }
			else if (!lb.classList.contains('video')) { if (e.key === 'ArrowLeft') { showImg(idx - 1); } else if (e.key === 'ArrowRight') { showImg(idx + 1); } }
		});
		// Hero-Galerie-Button → erstes Bild der aktiven Kategorie.
		document.querySelectorAll('.m24fz-gal-launch').forEach(function (b) {
			b.addEventListener('click', function () { collect(); if (pics.length) { showImg(0); open(); } });
		});
	}
})();
