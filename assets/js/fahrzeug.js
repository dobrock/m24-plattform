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

	// LazyLoad (WP Rocket) entschleiern: Bilder in ausgeblendeten Reitern werden vom IntersectionObserver
	// NIE sichtbar → echtes src/srcset aus den data-Attributen sofort setzen (kein Nachladen beim Klick).
	function unveilLazy(scope) {
		if (!scope) { return; }
		[].forEach.call(scope.querySelectorAll('img,source'), function (el) {
			var s = el.getAttribute('data-lazy-src') || el.getAttribute('data-src');
			if (s && el.getAttribute('src') !== s) { el.setAttribute('src', s); }
			var ss = el.getAttribute('data-lazy-srcset') || el.getAttribute('data-srcset');
			if (ss && el.getAttribute('srcset') !== ss) { el.setAttribute('srcset', ss); }
			el.classList && el.classList.remove('lazyload');
		});
	}

	// Mediagalerie-Chips (Kategorie-Wrapper umschalten — Jetpack-Tiled-Mosaik je Kategorie, Video eigener Tab).
	var chips = document.querySelectorAll('.m24fz-chip'), wraps = document.querySelectorAll('[data-catwrap]');
	chips.forEach(function (c) {
		c.addEventListener('click', function () {
			chips.forEach(function (x) { x.classList.remove('on'); });
			c.classList.add('on');
			var cat = c.getAttribute('data-cat');
			wraps.forEach(function (w) {
				var show = w.getAttribute('data-catwrap') === cat;
				w.hidden = !show;
				if (show) { unveilLazy(w); } // Bilder des neuen Reiters sofort anzeigen
			});
			// Jetpack vermisst Tiled-Galerien, die erst jetzt Breite > 0 haben.
			try { window.dispatchEvent(new Event('resize')); } catch (err) {}
		});
	});

	// „+N · Alle Bilder"-Overlay auf der letzten Vorschau-Kachel des eigenen Zickzack-Mosaiks.
	function setupOverlay(galcat) {
		var preview = galcat.querySelector('.m24fz-tg-preview'); if (!preview) { return; }
		var rest = preview.getAttribute('data-rest') || '';
		var tiles = preview.querySelectorAll('.m24fz-mz-tile'); if (!tiles.length) { return; }
		var host = tiles[tiles.length - 1]; // letzte Vorschau-Kachel
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
		if (full) { unveilLazy(full); full.hidden = false; if (!reduce) { full.classList.add('m24fz-flyin'); } try { window.dispatchEvent(new Event('resize')); } catch (e) {} }
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

	// Eigene Bild-Lightbox (für das Zickzack-Mosaik) + Video-Lightbox (youtube-nocookie).
	var lb = document.querySelector('.m24fz-lb'); if (lb) {
		var frame = lb.querySelector('.m24fz-lb-frame');
		var lbImg = lb.querySelector('img');
		var lbPrev = lb.querySelector('.m24fz-lb-prev'), lbNext = lb.querySelector('.m24fz-lb-next');
		var lbList = [], lbIdx = 0;
		function close() { lb.hidden = true; document.body.style.overflow = ''; lb.classList.remove('video'); if (frame) { frame.innerHTML = ''; } if (lbImg) { lbImg.removeAttribute('src'); } lbList = []; }
		function lbShow() { if (lbImg && lbList[lbIdx]) { lbImg.src = lbList[lbIdx].src; lbImg.alt = lbList[lbIdx].alt || ''; } }
		function lbStep(d) { if (!lbList.length) { return; } lbIdx = (lbIdx + d + lbList.length) % lbList.length; lbShow(); }
		function openImg(list, idx) { if (!list || !list.length) { return; } lbList = list; lbIdx = Math.max(0, Math.min(idx, list.length - 1)); lb.classList.remove('video'); if (frame) { frame.innerHTML = ''; } lbShow(); lb.hidden = false; document.body.style.overflow = 'hidden'; }
		// Mosaik-Kachel öffnen — das „+N"-Overlay hat einen eigenen Handler (expand) und wird übersprungen.
		document.addEventListener('click', function (e) {
			if (e.target.closest('.m24fz-more-ov')) { return; }
			var tile = e.target.closest('.m24fz-mz-tile'); if (!tile) { return; }
			e.preventDefault();
			var wrap = tile.closest('.m24fz-mz-wrap'); if (!wrap) { return; }
			var imgs = []; try { imgs = JSON.parse(wrap.getAttribute('data-images') || '[]'); } catch (err) {}
			openImg(imgs, parseInt(tile.getAttribute('data-idx'), 10) || 0);
		});
		document.addEventListener('keydown', function (e) {
			if ((e.key === 'Enter' || e.key === ' ') && e.target.classList && e.target.classList.contains('m24fz-mz-tile')) { e.preventDefault(); e.target.click(); }
		});
		// Video.
		document.addEventListener('click', function (e) {
			var v = e.target.closest('.m24fz-video'); if (!v || !frame) { return; }
			e.preventDefault(); var yid = v.getAttribute('data-ytid'); if (!yid) { return; }
			if (lbImg) { lbImg.removeAttribute('src'); }
			lb.classList.add('video');
			frame.innerHTML = '<iframe src="https://www.youtube-nocookie.com/embed/' + yid + '?autoplay=1&rel=0" title="Video" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>';
			lb.hidden = false; document.body.style.overflow = 'hidden';
		});
		if (lbPrev) { lbPrev.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); lbStep(-1); }); }
		if (lbNext) { lbNext.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); lbStep(1); }); }
		lb.querySelector('.m24fz-lb-close').addEventListener('click', close);
		lb.addEventListener('click', function (e) { if (e.target === lb) { close(); } });
		document.addEventListener('keydown', function (e) {
			if (lb.hidden) { return; }
			if (e.key === 'Escape') { close(); }
			else if (e.key === 'ArrowLeft') { lbStep(-1); }
			else if (e.key === 'ArrowRight') { lbStep(1); }
		});
	}

	// „Jetzt anfragen" (Anfrage-Modal) UND „Auf die Interessentenliste" (IL-Modal) — getrennte Modals/Handler.
	var anfModal  = document.getElementById('m24fz-anfrage-modal');
	var ilModal   = document.getElementById('m24fz-il-modal');
	var parkModal = document.getElementById('m24fz-park-modal');

	function modalOpen(m) {
		if (!m) { return; }
		m.hidden = false; m.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden';
		var f = m.querySelector('input[name=vorname], input[name=name]'); if (f) { f.focus(); }
	}
	function modalClose(m) { if (!m) { return; } m.hidden = true; m.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; }

	if (anfModal || ilModal || parkModal) {
		document.addEventListener('click', function (e) {
			if (e.target.closest('.m24fz-anfrage-open')) { e.preventDefault(); modalOpen(anfModal); return; }
			if (e.target.closest('.m24fz-il-open'))      { e.preventDefault(); modalOpen(ilModal); return; }
			if (e.target.closest('.m24fz-park-open'))    { e.preventDefault(); modalOpen(parkModal); return; }
			if (e.target.closest('.m24fz-anfrage-close')) {
				modalClose(e.target.closest('.m24fz-anfrage-modal')); return;
			}
			if (e.target === anfModal)  { modalClose(anfModal); }
			if (e.target === ilModal)   { modalClose(ilModal); }
			if (e.target === parkModal) { modalClose(parkModal); }
		});
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') { return; }
			if (anfModal && !anfModal.hidden)   { modalClose(anfModal); }
			if (ilModal && !ilModal.hidden)     { modalClose(ilModal); }
			if (parkModal && !parkModal.hidden) { modalClose(parkModal); }
		});
	}

	// (Kundentyp-Toggle im Anfrage-Modal kommt aus dem gemeinsamen Feld-Set / m24-inquiry-fields.js.)

	// Generischer REST-Submit für ein Modal-Formular (eigener Endpoint je Flow).
	function wireModalForm(modal, formSel, endpoint) {
		if (!modal) { return; }
		var form = modal.querySelector(formSel), amsg = modal.querySelector('.m24fz-anf-msg');
		if (!form) { return; }
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!endpoint || !cfg.nonce) { return; }
			// Gemeinsame Client-Validierung NUR für Formulare mit dem geteilten Feld-Set (Anfrage), nicht IL.
			if (window.M24IqFields && form.querySelector('[name="kundentyp"]') && !M24IqFields.validate(form).ok) { return; }
			var fd = new FormData(form); fd.append('post_id', form.getAttribute('data-pid'));
			var btn = form.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; }
			if (amsg) { amsg.textContent = 'Wird gesendet …'; }
			fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (amsg) { amsg.textContent = (d && d.message) ? d.message : 'Danke!'; }
					if (d && d.ok) { form.reset(); setTimeout(function () { modalClose(modal); }, 1800); }
					if (btn) { btn.disabled = false; }
				})
				.catch(function () { if (amsg) { amsg.textContent = 'Senden fehlgeschlagen. Bitte später erneut.'; } if (btn) { btn.disabled = false; } });
		});
	}
	wireModalForm(anfModal, '.m24fz-anfrage-form', cfg.anfrage);
	wireModalForm(ilModal, '.m24fz-il-form', cfg.interessent);

	// Deep-Link aus „Meine Garage" (Fahrzeug-Karte → „Anfrage senden"): ?m24anfrage=1 öffnet das Modal.
	if (anfModal && /[?&]m24anfrage=1\b/.test(location.search)) { modalOpen(anfModal); }

	// „Fahrzeug parken"-Modal — gleiche REST-Logik + Button-Feedback (Hero-Pill + Preisbox) nach Erfolg.
	if (parkModal && cfg.parken) {
		var pForm = parkModal.querySelector('.m24fz-park-form');
		var pMsg  = parkModal.querySelector('.m24fz-anf-msg');
		if (pForm) {
			pForm.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!cfg.nonce) { return; }
				var fd = new FormData(pForm); fd.append('post_id', pForm.getAttribute('data-pid') || cfg.pid || '0');
				var btn = pForm.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; }
				if (pMsg) { pMsg.textContent = 'Wird gesendet …'; }
				fetch(cfg.parken, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd })
					.then(function (r) { return r.json(); })
					.then(function (d) {
						if (pMsg) { pMsg.textContent = (d && d.message) ? d.message : 'Bitte E-Mail bestätigen.'; }
						if (d && d.ok) {
							pForm.reset();
							Array.prototype.forEach.call(document.querySelectorAll('.m24fz-park-open'), function (b) {
								b.textContent = '✓ Gemerkt — bitte E-Mail bestätigen'; b.disabled = true;
							});
							setTimeout(function () { modalClose(parkModal); }, 1800);
						}
						if (btn) { btn.disabled = false; }
					})
					.catch(function () { if (pMsg) { pMsg.textContent = 'Senden fehlgeschlagen. Bitte später erneut.'; } if (btn) { btn.disabled = false; } });
			});
		}
	}

	// Off-Market-Inline-Formular (kein Modal) — gleiche REST-Logik, eigener Endpoint.
	(function () {
		var omForm = document.querySelector('.m24fz-offmarket-form');
		if (!omForm || !cfg.offmarket) { return; }
		var omsg = omForm.querySelector('.m24fz-anf-msg');
		omForm.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!cfg.nonce) { return; }
			var fd = new FormData(omForm); fd.append('post_id', omForm.getAttribute('data-pid') || '0');
			var btn = omForm.querySelector('button[type=submit]'); if (btn) { btn.disabled = true; }
			if (omsg) { omsg.textContent = 'Wird gesendet …'; }
			fetch(cfg.offmarket, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce }, body: fd })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (omsg) { omsg.textContent = (d && d.message) ? d.message : 'Bitte E-Mail bestätigen.'; }
					if (d && d.ok) { omForm.reset(); }
					if (btn) { btn.disabled = false; }
				})
				.catch(function () { if (omsg) { omsg.textContent = 'Senden fehlgeschlagen. Bitte später erneut.'; } if (btn) { btn.disabled = false; } });
		});
	})();

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
