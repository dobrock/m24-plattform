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

	// Mediagalerie-Chips (echter Filter).
	var chips = document.querySelectorAll('.m24fz-chip'), items = document.querySelectorAll('.m24fz-mitem');
	chips.forEach(function (c) {
		c.addEventListener('click', function () {
			chips.forEach(function (x) { x.classList.remove('on'); });
			c.classList.add('on');
			var cat = c.getAttribute('data-cat');
			items.forEach(function (it) { it.hidden = it.getAttribute('data-cat') !== cat; });
		});
	});

	// Lightbox für Bild-Items (nicht Video).
	var lb = document.querySelector('.m24fz-lb'); if (lb) {
		var lbImg = lb.querySelector('img'), pics = [], idx = 0;
		function collect() { pics = []; document.querySelectorAll('.m24fz-mitem:not(.m24fz-video)').forEach(function (a) { if (!a.hidden) { pics.push(a.getAttribute('href')); } }); }
		function show(i) { if (!pics.length) { return; } idx = (i + pics.length) % pics.length; lbImg.src = pics[idx]; }
		document.addEventListener('click', function (e) {
			var a = e.target.closest('.m24fz-mitem:not(.m24fz-video)');
			if (a) { e.preventDefault(); collect(); show(pics.indexOf(a.getAttribute('href'))); lb.hidden = false; document.body.style.overflow = 'hidden'; }
		});
		lb.querySelector('.m24fz-lb-close').addEventListener('click', function () { lb.hidden = true; document.body.style.overflow = ''; });
		lb.querySelector('.m24fz-lb-prev').addEventListener('click', function () { show(idx - 1); });
		lb.querySelector('.m24fz-lb-next').addEventListener('click', function () { show(idx + 1); });
		lb.addEventListener('click', function (e) { if (e.target === lb) { lb.hidden = true; document.body.style.overflow = ''; } });
		document.addEventListener('keydown', function (e) {
			if (lb.hidden) { return; }
			if (e.key === 'Escape') { lb.hidden = true; document.body.style.overflow = ''; }
			else if (e.key === 'ArrowLeft') { show(idx - 1); } else if (e.key === 'ArrowRight') { show(idx + 1); }
		});
		// Galerie-Launcher öffnet das erste sichtbare Bild.
		document.querySelectorAll('.m24fz-gal-launch').forEach(function (b) {
			b.addEventListener('click', function () { collect(); if (pics.length) { show(0); lb.hidden = false; document.body.style.overflow = 'hidden'; } });
		});
	}
})();
