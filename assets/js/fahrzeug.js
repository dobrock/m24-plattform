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

	// Mediagalerie-Chips (Kategorie-Wrapper umschalten).
	var chips = document.querySelectorAll('.m24fz-chip'), wraps = document.querySelectorAll('.m24fz-mosaic[data-catwrap]');
	chips.forEach(function (c) {
		c.addEventListener('click', function () {
			chips.forEach(function (x) { x.classList.remove('on'); });
			c.classList.add('on');
			var cat = c.getAttribute('data-cat');
			wraps.forEach(function (w) { w.hidden = w.getAttribute('data-catwrap') !== cat; });
		});
	});

	// Lightbox: Bilder (Slideshow) + Video (youtube-nocookie, erst bei Klick geladen).
	var lb = document.querySelector('.m24fz-lb'); if (lb) {
		var lbImg = lb.querySelector('img'), frame = lb.querySelector('.m24fz-lb-frame'), pics = [], idx = 0;
		function collect() { pics = []; var w = document.querySelector('.m24fz-mosaic[data-catwrap]:not([hidden])'); if (!w) { return; } w.querySelectorAll('.m24fz-mitem:not(.m24fz-video)').forEach(function (a) { pics.push(a.getAttribute('href')); }); }
		function open() { lb.hidden = false; document.body.style.overflow = 'hidden'; }
		function close() { lb.hidden = true; document.body.style.overflow = ''; lb.classList.remove('video'); if (frame) { frame.innerHTML = ''; } }
		function showImg(i) { if (!pics.length) { return; } lb.classList.remove('video'); idx = (i + pics.length) % pics.length; lbImg.src = pics[idx]; }
		function showVideo(yid) { if (!frame || !yid) { return; } lb.classList.add('video');
			frame.innerHTML = '<iframe src="https://www.youtube-nocookie.com/embed/' + yid + '?autoplay=1&rel=0" title="Video" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>'; }
		document.addEventListener('click', function (e) {
			var v = e.target.closest('.m24fz-video');
			if (v) { e.preventDefault(); showVideo(v.getAttribute('data-ytid')); open(); return; }
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
		// Galerie-Launcher öffnet das erste sichtbare Bild.
		document.querySelectorAll('.m24fz-gal-launch').forEach(function (b) {
			b.addEventListener('click', function () { collect(); if (pics.length) { showImg(0); open(); } });
		});
	}
})();
