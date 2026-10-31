/* M24 Startseite v2 – Slider, Teile-Finder, Mobil-Hero-Slider (31.10.2026) */
(function () {
	var root = document.getElementById('m24-startseite');
	if (root) {
		var track = root.querySelector('.m24s-track');
		root.querySelectorAll('.m24s-arr').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!track) { return; }
				var card = track.querySelector('.m24s-car');
				var step = card ? card.getBoundingClientRect().width + 16 : 400;
				track.scrollBy({ left: step * parseInt(b.getAttribute('data-dir'), 10), behavior: 'smooth' });
			});
		});
		root.querySelectorAll('[data-tab]').forEach(function (t) {
			t.addEventListener('click', function () {
				var k = t.getAttribute('data-tab');
				root.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.toggle('is-on', x === t); });
				root.querySelectorAll('.m24s-parts').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== k; });
			});
		});
		root.querySelectorAll('[data-model]').forEach(function (m) {
			m.addEventListener('click', function () {
				var panel = m.closest('.m24s-parts');
				var k = m.getAttribute('data-model');
				panel.querySelectorAll('[data-model]').forEach(function (x) { x.classList.toggle('is-on', x === m); });
				panel.querySelectorAll('[data-grid]').forEach(function (g) { g.hidden = g.getAttribute('data-grid') !== k; });
			});
		});
	}
	/* Mobil-Hero-Slider: Punkte + Auto-Weiter alle 5 s (stoppt bei Berührung) */
	var ms = document.querySelector('.m24s-ms');
	if (ms) {
		var mt = ms.querySelector('.m24s-ms__track');
		var dots = ms.querySelectorAll('.m24s-ms__dots i');
		var idx = function () { return Math.round(mt.scrollLeft / mt.clientWidth); };
		var mark = function () { var i = idx(); dots.forEach(function (d, n) { d.classList.toggle('is-on', n === i); }); };
		mark();
		mt.addEventListener('scroll', function () { window.requestAnimationFrame(mark); }, { passive: true });
		var auto = setInterval(function () {
			var n = idx() + 1; if (n >= dots.length) { n = 0; }
			mt.scrollTo({ left: n * mt.clientWidth, behavior: 'smooth' });
		}, 5000);
		mt.addEventListener('touchstart', function () { clearInterval(auto); }, { passive: true });
	}
})();
