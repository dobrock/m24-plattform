/* M24 Startseite v2 – Slider-Pfeile, Teile-Tabs, Modell-Liste (31.10.2026) */
(function () {
	var root = document.getElementById('m24-startseite');
	if (!root) { return; }
	var track = root.querySelector('.m24s-track');
	root.querySelectorAll('.m24s-arr').forEach(function (b) {
		b.addEventListener('click', function () {
			if (!track) { return; }
			var card = track.querySelector('.m24s-car');
			var step = card ? card.getBoundingClientRect().width + 22 : 400;
			track.scrollBy({ left: step * parseInt(b.getAttribute('data-dir'), 10), behavior: 'smooth' });
		});
	});
	root.querySelectorAll('.m24s-tab').forEach(function (t) {
		t.addEventListener('click', function () {
			var k = t.getAttribute('data-tab');
			root.querySelectorAll('.m24s-tab').forEach(function (x) { x.classList.toggle('is-on', x === t); });
			root.querySelectorAll('.m24s-parts').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== k; });
		});
	});
	root.querySelectorAll('.m24s-model').forEach(function (m) {
		m.addEventListener('click', function () {
			var panel = m.closest('.m24s-parts');
			var k = m.getAttribute('data-model');
			panel.querySelectorAll('.m24s-model').forEach(function (x) { x.classList.toggle('is-on', x === m); });
			panel.querySelectorAll('[data-grid]').forEach(function (g) { g.hidden = g.getAttribute('data-grid') !== k; });
		});
	});
})();
