/* M24 „Meine Garage" — kontogebundener Warenkorb (Etappe 1).
 * - Verdrahtet die bestehenden „In meine Garage"-Buttons (.m24-garage-open) für eingeloggte Accounts:
 *   fügt direkt zum Konto-Warenkorb hinzu (Menge++) und unterdrückt den Gast-DOI-Dialog (Capture-Phase).
 * - Garage-Seite: Menge ± / Entfernen → REST → Zeile, Gesamtsumme und Zähler live aktualisieren.
 * - Hält jeden [data-m24-garage-count] (Schwebe- und Header-Zähler) live auf der Positionsanzahl.
 */
(function () {
	'use strict';
	var cfg = window.M24GarageCart || {};
	if (!cfg.rest) { return; }

	function headers() { return { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' }; }

	function post(path, body) {
		return fetch(cfg.rest + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers(),
			body: JSON.stringify(body || {})
		}).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); });
	}

	/* ── Gesamt/Netto live (alle [data-m24gc-grand]/[data-m24gc-net]); Netto = Brutto/1,19) ── */
	function parseMoney(str) { // "1.234,56 €" → 1234.56
		var m = String(str).replace(/[^0-9,.-]/g, '').replace(/\./g, '').replace(',', '.');
		var n = parseFloat(m);
		return isNaN(n) ? null : n;
	}
	function fmtMoney(n) { // 1234.56 → "1.234,56 €" (identisch zu M24_Catalog_Pricing::format)
		return n.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
	}
	// Teile-Tab-Summe NUR aus Teile-Zeilen (data-line) client-seitig — Fahrzeuge (§25a) fließen NICHT ein.
	function recalcTotals() {
		var panel = document.querySelector('[data-m24gc-panel="parts"]');
		if (panel) {
			var sum = 0;
			panel.querySelectorAll('[data-m24gc-row]').forEach(function (r) {
				var v = r.getAttribute('data-line');
				if (v) { var n = parseFloat(v); if (!isNaN(n)) { sum += n; } }
			});
			var g = fmtMoney(sum), net = fmtMoney(sum / 1.19);
			panel.querySelectorAll('[data-m24gc-grand]').forEach(function (el) { el.textContent = g; });
			panel.querySelectorAll('[data-m24gc-net]').forEach(function (el) { el.textContent = net; });
		}
		updateBadges();
	}
	// Tab-Badges = Zeilenzahl je Panel (Teile / Fahrzeuge); 0 → verstecken.
	function updateBadges() {
		['parts', 'vehicles'].forEach(function (key) {
			var p = document.querySelector('[data-m24gc-panel="' + key + '"]');
			var n = p ? p.querySelectorAll('[data-m24gc-row]').length : 0;
			document.querySelectorAll('[data-m24gc-badge="' + key + '"]').forEach(function (b) {
				b.textContent = String(n); b.hidden = n <= 0;
			});
		});
	}

	/* ── Tab-Umschaltung (client-seitig, kein Reload) ── */
	(function () {
		var tabsWrap = document.querySelector('[data-m24gc-tabs]');
		if (!tabsWrap) { return; }
		var tabs = tabsWrap.querySelectorAll('[data-m24gc-tab]');
		var panels = document.querySelectorAll('[data-m24gc-panel]');
		function activate(key) {
			var found = false;
			tabs.forEach(function (t) {
				var on = t.getAttribute('data-m24gc-tab') === key;
				if (on) { found = true; }
				t.classList.toggle('is-active', on);
				if (on) { t.setAttribute('aria-selected', 'true'); } else { t.removeAttribute('aria-selected'); }
			});
			if (!found) { return; }
			panels.forEach(function (p) {
				var on = p.getAttribute('data-m24gc-panel') === key;
				p.classList.toggle('is-active', on);
				p.hidden = !on;
			});
		}
		tabsWrap.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-m24gc-tab]');
			if (btn) { activate(btn.getAttribute('data-m24gc-tab')); }
		});
		// Deep-Link aus Alert-Mail „Benachrichtigungen verwalten": ?m24tab=notify öffnet den Tab.
		var m = location.search.match(/[?&]m24tab=([a-z]+)/);
		if (m) { activate(m[1]); }
	})();

	/* ── Zähler (Schwebe-FAB + jeder Header-Slot mit [data-m24-garage-count]) ── */
	function updateCount(count) {
		if (typeof count !== 'number') { return; }
		document.querySelectorAll('[data-m24-garage-count]').forEach(function (el) {
			el.textContent = String(count);
		});
		var fab = document.querySelector('.m24gc-fab');
		if (fab) { fab.classList.toggle('is-empty', count <= 0); }
	}

	/* ── Toast ── */
	var toastEl = null, toastT = null;
	function toast(msg) {
		if (!toastEl) {
			toastEl = document.createElement('div');
			toastEl.className = 'm24gc-toast';
			document.body.appendChild(toastEl);
		}
		toastEl.textContent = msg;
		// reflow → transition
		void toastEl.offsetWidth;
		toastEl.classList.add('show');
		if (toastT) { clearTimeout(toastT); }
		toastT = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
	}

	/* ── „In meine Garage"-Buttons: Herz-Toggle (nur eingeloggt) ── */
	// Zustand: nicht drin = Herz umrandet + „In meine Garage"; drin = Herz ausgefüllt + „In meiner Garage".
	function setGarageBtn(btn, inGarage) {
		if (!btn) { return; }
		btn.classList.toggle('is-ingarage', !!inGarage);
		btn.setAttribute('aria-pressed', inGarage ? 'true' : 'false');
		var svg = btn.querySelector('.m24-btn-i');
		if (svg) { svg.setAttribute('fill', inGarage ? 'currentColor' : 'none'); }
		var txt = btn.querySelector('.m24-garage-txt');
		if (txt) { txt.textContent = inGarage ? 'In meiner Garage' : 'In meine Garage'; }
	}

	if (cfg.loggedIn) {
		// Initialzustand aus dem Cart-State: welche post_ids liegen bereits in der Garage?
		var toggleBtns = Array.prototype.slice.call(document.querySelectorAll('.m24-garage-toggle'));
		if (toggleBtns.length && cfg.rest) {
			fetch(cfg.rest, { credentials: 'same-origin', headers: headers() })
				.then(function (r) { return r.json(); }).then(function (d) {
					var ids = {};
					if (d && d.items) { d.items.forEach(function (it) { ids[parseInt(it.post_id, 10)] = 1; }); }
					toggleBtns.forEach(function (b) {
						setGarageBtn(b, !!ids[parseInt(b.getAttribute('data-garage-id') || '0', 10)]);
					});
				}).catch(function () {});
		}

		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.m24-garage-open') : null;
			if (!btn) { return; }
			// Gast-DOI-Dialog (M24_Garage::render_modal) NICHT öffnen: Bubble-Listener vorab kappen.
			e.preventDefault();
			e.stopImmediatePropagation();
			var pid = parseInt(btn.getAttribute('data-garage-id') || '0', 10);
			if (!pid) { return; }
			if (btn.dataset.m24gcBusy === '1') { return; }
			var inGarage = btn.classList.contains('is-ingarage');
			var isToggle = btn.classList.contains('m24-garage-toggle');
			var path = ( isToggle && inGarage ) ? '/remove' : '/add';
			btn.dataset.m24gcBusy = '1';
			post(path, { post_id: pid }).then(function (res) {
				btn.dataset.m24gcBusy = '';
				if (res.ok && res.data && res.data.ok) {
					updateCount(res.data.count);
					if (isToggle) {
						var nowIn = ( '/add' === path );
						setGarageBtn(btn, nowIn);
						toast(nowIn ? ((cfg.i18n && cfg.i18n.added) || 'In deine Garage gelegt.') : 'Aus deiner Garage entfernt.');
					} else {
						toast((cfg.i18n && cfg.i18n.added) || 'In deine Garage gelegt.');
					}
				} else {
					toast((res.data && res.data.message) || (cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
				}
			}).catch(function () {
				btn.dataset.m24gcBusy = '';
				toast((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
			});
		}, true); // <-- Capture-Phase: läuft VOR dem Modal-Listener auf document
	}

	/* ── Garage-Seite: Menge ± / Entfernen ── */
	var page = document.querySelector('[data-m24gc-page]');
	if (page) {
		page.addEventListener('click', function (e) {
			var row = e.target.closest('[data-m24gc-row]');
			if (!row) { return; }
			var inc = e.target.closest('.m24gc-inc');
			var dec = e.target.closest('.m24gc-dec');
			var rem = e.target.closest('[data-m24gc-remove]');
			if (!inc && !dec && !rem) { return; }
			e.preventDefault();
			if (row.dataset.busy === '1') { return; }

			var pid = parseInt(row.getAttribute('data-post-id') || '0', 10);
			var pt = row.getAttribute('data-post-type') || '';
			var qtyEl = row.querySelector('[data-m24gc-qty]');
			var cur = parseInt((qtyEl && qtyEl.textContent) || '1', 10);
			var req, body;

			if (rem) {
				req = '/remove'; body = { post_id: pid };
			} else {
				var next = inc ? cur + 1 : cur - 1;
				req = '/qty'; body = { post_id: pid, qty: next };
			}

			row.dataset.busy = '1';
			post(req, body).then(function (res) {
				row.dataset.busy = '';
				if (!res.ok || !res.data || !res.data.ok) {
					toast((res.data && res.data.message) || (cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
					return;
				}
				var d = res.data;
				updateCount(d.count);
				if (d.removed) {
					row.parentNode.removeChild(row);
					if (d.count <= 0) { location.reload(); return; } // Leerzustand serverseitig rendern
				} else {
					if (qtyEl) { qtyEl.textContent = String(d.qty); }
					var lineEl = row.querySelector('[data-m24gc-line]');
					if (lineEl) { lineEl.textContent = d.line_fmt || '—'; }
					// Numerischen Zeilenwert für die Teile-Summe nachführen.
					var num = d.line_fmt ? parseMoney(d.line_fmt) : null;
					row.setAttribute('data-line', (num !== null) ? String(num) : '');
				}
				recalcTotals();
			}).catch(function () {
				row.dataset.busy = '';
				toast((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
			});
		});
	}

	/* ── Garage-Link versenden (nur Eigentümer, Etappe 2) ── */
	var sharePanel = document.querySelector('[data-m24gc-share]');
	if (sharePanel && cfg.share) {
		var inEl = sharePanel.querySelector('[data-m24gc-share-input]');
		var copyBtn = sharePanel.querySelector('[data-m24gc-share-copy]');
		var genBtn = sharePanel.querySelector('[data-m24gc-share-generate]');
		var rotBtn = sharePanel.querySelector('[data-m24gc-share-rotate]');
		var msgEl = sharePanel.querySelector('[data-m24gc-share-msg]');

		function shareMsg(t) { if (msgEl) { msgEl.textContent = t || ''; } }

		// Adresszeile = der teilbare Token-Link (deckt „Adresszeile kopieren" ab). Eigentümer sieht dabei
		// weiter die editierbare Ansicht (Server rendert den eigenen Token nicht read-only).
		function mirrorUrl(url) {
			if (url && url.indexOf('m24garage_share=') !== -1 && window.history && history.replaceState) {
				try { history.replaceState(null, '', url); } catch (e) {}
			}
		}

		function applyUrl(url) {
			if (inEl) { inEl.value = url || ''; }
			var has = !!url;
			if (copyBtn) { copyBtn.hidden = !has; }
			if (genBtn) { genBtn.hidden = has; }
			if (rotBtn) { rotBtn.hidden = !has; }
		}

		if (inEl && inEl.value) { applyUrl(inEl.value); mirrorUrl(inEl.value); }

		function shareReq(action, after) {
			return fetch(cfg.share, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers(),
				body: JSON.stringify({ action: action })
			}).then(function (r) { return r.json(); }).then(function (d) {
				if (d && d.ok) { applyUrl(d.url); mirrorUrl(d.url); if (after) { after(d); } }
				else { shareMsg((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.'); }
			}).catch(function () { shareMsg((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.'); });
		}

		if (genBtn) {
			genBtn.addEventListener('click', function () { shareMsg(''); shareReq('generate'); });
		}
		if (rotBtn) {
			rotBtn.addEventListener('click', function () {
				shareReq('rotate', function () { shareMsg((cfg.i18n && cfg.i18n.rotated) || ''); });
			});
		}
		if (copyBtn) {
			copyBtn.addEventListener('click', function () {
				var val = inEl ? inEl.value : '';
				if (!val) { return; }
				var done = function () { shareMsg((cfg.i18n && cfg.i18n.copied) || 'Kopiert.'); };
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(val).then(done).catch(function () {
						if (inEl) { inEl.select(); document.execCommand && document.execCommand('copy'); done(); }
					});
				} else if (inEl) {
					inEl.select(); document.execCommand && document.execCommand('copy'); done();
				}
			});
		}

		// Primär-Aktion: frischen Snapshot erzeugen (Freeze zum Klick-Zeitpunkt) → teilen bzw. kopieren.
		var primaryBtn = sharePanel.querySelector('[data-m24gc-share-primary]');
		if (primaryBtn) {
			primaryBtn.addEventListener('click', function () {
				shareMsg('');
				primaryBtn.disabled = true;
				shareReq('generate', function (d) {
					var url = d && d.url;
					if (!url) { return; }
					mirrorUrl(url);
					if (navigator.share) {
						navigator.share({ title: 'Meine MOTORSPORT24-Garage', url: url }).catch(function () {});
					} else if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(url).then(function () {
							toast('Link kopiert – in WhatsApp einfügen.');
						}).catch(function () { shareMsg((cfg.i18n && cfg.i18n.copied) || 'Kopiert.'); });
					} else if (inEl) {
						inEl.select(); document.execCommand && document.execCommand('copy');
						toast('Link kopiert – in WhatsApp einfügen.');
					}
				}).then(function () { primaryBtn.disabled = false; });
			});
		}
		// Server-seitiger Versand „An Kunden senden" (+ optional Exposé-PDF-Anhang).
		var sm = sharePanel.querySelector('[data-m24gc-sendmail]');
		if (sm && cfg.sendMail) {
			var smTo = sm.querySelector('[data-m24gc-sendmail-to]');
			var smMsg = sm.querySelector('[data-m24gc-sendmail-msg]');
			var smPdf = sm.querySelector('[data-m24gc-sendmail-pdf]');
			var smBtn = sm.querySelector('[data-m24gc-sendmail-btn]');
			var smStatus = sm.querySelector('[data-m24gc-sendmail-status]');
			var reEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

			function smSet(t, tone) {
				if (!smStatus) { return; }
				smStatus.textContent = t || '';
				smStatus.className = 'm24gc-sendmail-status' + (tone ? ' is-' + tone : '');
			}
			function redact(email) {
				var at = email.indexOf('@');
				if (at < 0) { return '***'; }
				return email.slice(0, Math.min(2, at)) + '***' + email.slice(at);
			}

			if (smBtn) {
				smBtn.addEventListener('click', function () {
					var to = (smTo && smTo.value || '').trim();
					if (!reEmail.test(to)) { smSet('Bitte eine gültige E-Mail-Adresse eingeben.', 'error'); if (smTo) { smTo.focus(); } return; }
					var payload = {
						to: to,
						message: (smMsg && smMsg.value || '').trim(),
						attach_pdf: smPdf ? !!smPdf.checked : true
					};
					smBtn.disabled = true;
					smBtn.classList.add('is-loading');
					smSet('Wird gesendet …', '');
					fetch(cfg.sendMail, {
						method: 'POST', credentials: 'same-origin', headers: headers(),
						body: JSON.stringify(payload)
					}).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
					.then(function (res) {
						smBtn.disabled = false;
						smBtn.classList.remove('is-loading');
						if (res.ok && res.d && res.d.sent) {
							smSet('Gesendet an ' + redact(to), 'ok');
							if (smMsg) { smMsg.value = ''; }
						} else {
							smSet((res.d && res.d.message) || 'Senden fehlgeschlagen.', 'error');
						}
					}).catch(function () {
						smBtn.disabled = false;
						smBtn.classList.remove('is-loading');
						smSet('Senden fehlgeschlagen.', 'error');
					});
				});
			}
		}
	}

	/* ── Benachrichtigen-Pills je Fahrzeug (Etappe 2: nur Präferenz speichern) ── */
	if (page && cfg.notify) {
		page.addEventListener('click', function (e) {
			var pill = e.target.closest ? e.target.closest('[data-m24gc-pref]') : null;
			if (!pill) { return; }
			var box = pill.closest('[data-m24gc-notify]');
			if (!box || pill.dataset.busy === '1') { return; }
			var pid = parseInt(box.getAttribute('data-post-id') || '0', 10);
			var key = pill.getAttribute('data-m24gc-pref');
			var on = !pill.classList.contains('is-on');
			pill.dataset.busy = '1';
			fetch(cfg.notify, {
				method: 'POST', credentials: 'same-origin', headers: headers(),
				body: JSON.stringify({ post_id: pid, key: key, on: on })
			}).then(function (r) { return r.json(); }).then(function (d) {
				pill.dataset.busy = '';
				if (d && d.ok) {
					var val = !!d[key];
					pill.classList.toggle('is-on', val);
					pill.setAttribute('aria-pressed', val ? 'true' : 'false');
				} else {
					toast((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
				}
			}).catch(function () {
				pill.dataset.busy = '';
				toast((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.');
			});
		});
	}

	/* ── Master-Schalter „Alle Benachrichtigungen" (Etappe 3) ── */
	if (page && cfg.notifyMaster) {
		var master = page.querySelector('[data-m24gc-master]');
		if (master) {
			master.addEventListener('change', function () {
				var on = master.checked;
				master.disabled = true;
				fetch(cfg.notifyMaster, {
					method: 'POST', credentials: 'same-origin', headers: headers(),
					body: JSON.stringify({ on: on })
				}).then(function (r) { return r.json(); }).then(function (d) {
					master.disabled = false;
					if (!d || !d.ok) { master.checked = !on; toast((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.'); }
				}).catch(function () { master.disabled = false; master.checked = !on; toast((cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.'); });
			});
		}
	}

	/* ── Teile-Merkzettel per Drag & Drop sortieren (pointer: Maus + Touch, ohne Fremd-Lib) ── */
	if (page && cfg.reorder) {
		var list = page.querySelector('[data-m24gc-panel="parts"] [data-m24gc-list]') || page.querySelector('[data-m24gc-list]');
		if (list && list.querySelector('[data-m24gc-row][data-row-id]')) {
			var dragEl = null;
			var rowsOf = function () { return Array.prototype.slice.call(list.querySelectorAll('[data-m24gc-row]')); };
			var onMove = function (e) {
				if (!dragEl) { return; }
				var y = e.clientY;
				var after = null;
				rowsOf().forEach(function (r) {
					if (r === dragEl) { return; }
					var b = r.getBoundingClientRect();
					if (y > b.top + b.height / 2) { after = r; }
				});
				if (after) {
					if (after.nextSibling !== dragEl) { list.insertBefore(dragEl, after.nextSibling); }
				} else {
					var first = list.querySelector('[data-m24gc-row]');
					if (first !== dragEl) { list.insertBefore(dragEl, first); }
				}
			};
			var onUp = function () {
				document.removeEventListener('pointermove', onMove);
				if (!dragEl) { return; }
				dragEl.classList.remove('m24gc-dragging');
				dragEl = null;
				var order = rowsOf().map(function (r) { return parseInt(r.getAttribute('data-row-id') || '0', 10); }).filter(Boolean);
				fetch(cfg.reorder, {
					method: 'POST', credentials: 'same-origin', headers: headers(),
					body: JSON.stringify({ order: order })
				}).catch(function () {});
			};
			list.addEventListener('pointerdown', function (e) {
				var h = e.target.closest ? e.target.closest('[data-m24gc-drag]') : null;
				if (!h) { return; }
				dragEl = h.closest('[data-m24gc-row]');
				if (!dragEl) { return; }
				e.preventDefault();
				dragEl.classList.add('m24gc-dragging');
				document.addEventListener('pointermove', onMove);
				document.addEventListener('pointerup', onUp, { once: true });
			});
		}
	}

	/* ── Garage als Anfrage senden (reuse Sammelanfrage-Strecke, Etappe 4) ── */
	var sendPanel = document.querySelector('[data-m24gc-send]');
	if (sendPanel && cfg.submit) {
		var sendBtn = sendPanel.querySelector('[data-m24gc-send-btn]');
		var sendMsgEl = sendPanel.querySelector('[data-m24gc-send-msg]');
		var sendStatus = sendPanel.querySelector('[data-m24gc-send-status]');
		if (sendBtn) {
			sendBtn.addEventListener('click', function () {
				if (sendBtn.dataset.busy === '1' || sendBtn.dataset.done === '1') { return; }
				sendBtn.dataset.busy = '1';
				sendBtn.disabled = true;
				if (sendStatus) { sendStatus.textContent = (cfg.i18n && cfg.i18n.sending) || 'Wird gesendet …'; }
				fetch(cfg.submit, {
					method: 'POST',
					credentials: 'same-origin',
					headers: headers(),
					body: JSON.stringify({ message: sendMsgEl ? sendMsgEl.value : '' })
				}).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
					.then(function (res) {
						sendBtn.dataset.busy = '';
						if (res.ok && res.data && res.data.ok) {
							sendBtn.dataset.done = '1';
							sendBtn.textContent = 'Anfrage gesendet ✓';
							if (sendStatus) { sendStatus.textContent = (res.data.message) || (cfg.i18n && cfg.i18n.sent) || ''; }
							if (sendMsgEl) { sendMsgEl.disabled = true; }
						} else {
							sendBtn.disabled = false;
							if (sendStatus) { sendStatus.textContent = (res.data && res.data.message) || (cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.'; }
						}
					}).catch(function () {
						sendBtn.dataset.busy = '';
						sendBtn.disabled = false;
						if (sendStatus) { sendStatus.textContent = (cfg.i18n && cfg.i18n.failed) || 'Aktion fehlgeschlagen.'; }
					});
			});
		}
	}
})();
