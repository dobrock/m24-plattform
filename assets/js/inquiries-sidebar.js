/**
 * M24 Plattform — Inquiries Sammelanfrage-Sidebar
 *
 * LocalStorage-driven Sidebar mit Item-Management und Sessionless-POST-Submit.
 * Public API: window.M24Sidebar.addItem(itemObj)
 *
 * Item-Schema (alle Strings):
 *   { art, qty, price, src_url, src_pillar, src_modell, src_pid }
 *
 * @package M24_Plattform
 */
(function () {
    'use strict';

    // ─────────────────────────────────────────────────────────────────
    // Konfiguration & State
    // ─────────────────────────────────────────────────────────────────

    var Config = (typeof window.M24SidebarConfig === 'object' && window.M24SidebarConfig) || {
        submitUrl:  '/m24-anfrage-test/',
        storageKey: 'm24_sidebar_items',
        sessionKey: 'm24_sidebar_session_id',
        maxItems:   50,
        i18n:       {}
    };

    if (!Config.sessionKey) Config.sessionKey = 'm24_sidebar_session_id';

    // Clientseitige Sprachwahl: die ECHTE Browser-URL/<html lang>/googtrans-Cookie ist maßgeblich. Im
    // GTranslate-Proxy-Modus sieht der Server oft kein /en/ → Server-Best-Guess kann daneben liegen. Sind beide
    // Sprach-Sets eingebettet (i18nDe/i18nEn), hier nach der Anzeige-Sprache wählen; sonst Server-Fallback (i18n).
    function m24DisplayIsEn() {
        try {
            if (/^\/en(\/|$)/.test(location.pathname)) { return true; }
            var h = (document.documentElement.getAttribute('lang') || '').toLowerCase();
            if (0 === h.indexOf('en')) { return true; }
            if (/(?:^|;)\s*googtrans=\/[a-z]{2}\/en\b/.test(document.cookie)) { return true; }
        } catch (e) {}
        return false;
    }
    var T = (m24DisplayIsEn() ? Config.i18nEn : Config.i18nDe) || Config.i18n || {};

    var VALID_PILLARS = ['gebrauchtteile', 'katalog', 'fahrzeug', 'blog'];

    var state = {
        items:  [],
        isOpen: false
    };

    // ─────────────────────────────────────────────────────────────────
    // Storage-Layer
    // ─────────────────────────────────────────────────────────────────

    function loadFromStorage() {
        try {
            var raw = window.localStorage.getItem(Config.storageKey);
            if (!raw) return [];
            var parsed = JSON.parse(raw);
            if (!Array.isArray(parsed)) return [];
            return parsed.map(sanitizeItem).filter(function (it) { return it !== null; });
        } catch (e) {
            return [];
        }
    }

    /**
     * Holt oder erzeugt eine Cart-Session-UUID, persistent im LocalStorage.
     * Lebenszyklus: Erste Anlage beim ersten Add → bleibt bis zum Erfolgs-State,
     * der die ID via clearSessionId() löscht.
     */
    function getOrCreateSessionId() {
        try {
            var existing = window.localStorage.getItem(Config.sessionKey);
            if (existing && /^[a-f0-9-]{8,}$/i.test(existing)) return existing;
        } catch (e) {}

        var uuid;
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            uuid = window.crypto.randomUUID();
        } else {
            uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                var r = Math.random() * 16 | 0;
                var v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }

        try { window.localStorage.setItem(Config.sessionKey, uuid); } catch (e) {}
        return uuid;
    }

    function clearSessionId() {
        try { window.localStorage.removeItem(Config.sessionKey); } catch (e) {}
    }

    /**
     * Baut das inquiry_source_meta-Objekt für den Cart-Pfad.
     * Spec v4 §6.2: { cart_session_id, items_total, estimated_value_eur? }
     * estimated_value_eur wird WEGGELASSEN, wenn User keine Preise sehen darf.
     */
    function buildSourceMeta() {
        var meta = {
            cart_session_id: getOrCreateSessionId(),
            items_total:     state.items.length
        };

        if (Config.userCanSeePrices) {
            var sum = 0;
            var hasNumeric = false;
            for (var i = 0; i < state.items.length; i++) {
                var raw = String(state.items[i].price || '').replace(',', '.');
                var n = parseFloat(raw);
                var qty = parseInt(state.items[i].qty, 10) || 1;
                if (!isNaN(n) && n > 0) {
                    sum += n * qty;
                    hasNumeric = true;
                }
            }
            if (hasNumeric) {
                meta.estimated_value_eur = Math.round(sum * 100) / 100;
            }
        }

        return meta;
    }

    function saveToStorage() {
        try {
            window.localStorage.setItem(Config.storageKey, JSON.stringify(state.items));
        } catch (e) {
            // QuotaExceeded oder Privacy-Mode — silent fail
        }
    }

    function sanitizeItem(raw) {
        if (!raw || typeof raw !== 'object') return null;
        var art = String(raw.art || '').trim();
        if (!art) return null;
        var pillar = String(raw.src_pillar || '').toLowerCase();
        if (VALID_PILLARS.indexOf(pillar) === -1) {
            pillar = 'gebrauchtteile';
        }
        var qtyNum = parseInt(raw.qty, 10);
        if (isNaN(qtyNum) || qtyNum < 1) qtyNum = 1;
        if (qtyNum > 99) qtyNum = 99;
        return {
            art:         art,
            qty:         String(qtyNum),
            price:       String(raw.price || ''),
            src_url:     String(raw.src_url || ''),
            src_pillar:  pillar,
            src_modell:  String(raw.src_modell || ''),
            src_pid:     String(raw.src_pid || ''),
            // Varianten-Felder (Paket A): gewaehlte Options-art_nr + Options-label.
            // Bleiben durch Storage, getItems, Desk-Push, Mail-Payload erhalten.
            src_art_nr:  String(raw.src_art_nr || ''),
            src_variant: String(raw.src_variant || '')
        };
    }

    // ─────────────────────────────────────────────────────────────────
    // Item-Management
    // ─────────────────────────────────────────────────────────────────

    function findIndex(pid, modell) {
        for (var i = 0; i < state.items.length; i++) {
            if (state.items[i].src_pid === pid && state.items[i].src_modell === modell) {
                return i;
            }
        }
        return -1;
    }

    function addItem(rawItem) {
        var clean = sanitizeItem(rawItem);
        if (!clean) return false;

        // Identitäts-Check: gleiche pid + modell → Mengen-Erhöhung statt Doppel-Eintrag
        var existing = findIndex(clean.src_pid, clean.src_modell);
        if (existing > -1 && clean.src_pid) {
            var current = parseInt(state.items[existing].qty, 10) || 1;
            var add     = parseInt(clean.qty, 10) || 1;
            var sum     = Math.min(99, current + add);
            state.items[existing].qty = String(sum);
        } else {
            if (state.items.length >= Config.maxItems) {
                showToast(T.maxReached || 'Max reached');
                return false;
            }
            state.items.push(clean);
        }

        saveToStorage();
        render();
        flash();
        showToast(T.addedToast || 'Added');
        return true;
    }

    function removeItem(idx) {
        if (idx < 0 || idx >= state.items.length) return;
        state.items.splice(idx, 1);
        saveToStorage();
        render();
    }

    function setQty(idx, newQty) {
        if (idx < 0 || idx >= state.items.length) return;
        var n = parseInt(newQty, 10);
        if (isNaN(n) || n < 1) n = 1;
        if (n > 99) n = 99;
        state.items[idx].qty = String(n);
        saveToStorage();
        render();
    }

    // ─────────────────────────────────────────────────────────────────
    // Render
    // ─────────────────────────────────────────────────────────────────

    function getRoot()  { return document.getElementById('m24-sidebar-root'); }
    function getList() { var r = getRoot(); return r ? r.querySelector('[data-m24-list]') : null; }
    function getBadge(){ var r = getRoot(); return r ? r.querySelector('[data-m24-count]') : null; }
    function getSubmit(){var r = getRoot(); return r ? r.querySelector('[data-m24-action="submit"]') : null; }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function render() {
        var list   = getList();
        var badge  = getBadge();
        var submit = getSubmit();
        if (!list || !badge || !submit) return;

        // Badge & Submit-Status
        badge.textContent  = String(state.items.length);
        submit.disabled    = state.items.length === 0;

        // Liste
        if (state.items.length === 0) {
            list.innerHTML =
                '<div class="m24-sidebar__empty">' +
                    '<p class="m24-sidebar__empty-title">' + escapeHtml(T.empty || 'Empty') + '</p>' +
                    '<p class="m24-sidebar__empty-hint">' + escapeHtml(T.emptyHint || '') + '</p>' +
                '</div>';
            return;
        }

        var html = '<ul class="m24-sidebar__items">';
        for (var i = 0; i < state.items.length; i++) {
            var it = state.items[i];
            html +=
                '<li class="m24-sidebar__item" data-idx="' + i + '">' +
                    '<div class="m24-sidebar__item-art">' + escapeHtml(it.art) + '</div>' +
                    '<div class="m24-sidebar__item-meta">' +
                        '<span class="m24-sidebar__item-pillar m24-sidebar__item-pillar--' + escapeHtml(it.src_pillar) + '">' +
                            escapeHtml(it.src_pillar) +
                        '</span>' +
                        ((it.price && Config.userCanSeePrices) ? '<span class="m24-sidebar__item-price">' + escapeHtml(it.price) + '</span>' : '') +
                    '</div>' +
                    '<div class="m24-sidebar__item-controls">' +
                        '<div class="m24-sidebar__qty">' +
                            '<button type="button" class="m24-sidebar__qty-btn" data-m24-action="qty-down" aria-label="−">−</button>' +
                            '<input type="number" class="m24-sidebar__qty-input" data-m24-action="qty-input" min="1" max="99" value="' + escapeHtml(it.qty) + '" aria-label="' + escapeHtml(T.qtyLabel || 'Qty') + '">' +
                            '<button type="button" class="m24-sidebar__qty-btn" data-m24-action="qty-up" aria-label="+">+</button>' +
                        '</div>' +
                        '<button type="button" class="m24-sidebar__remove" data-m24-action="remove" aria-label="' + escapeHtml(T.remove || 'Remove') + '">×</button>' +
                    '</div>' +
                '</li>';
        }
        html += '</ul>';
        list.innerHTML = html;
    }

    // ─────────────────────────────────────────────────────────────────
    // UI-State (open/close/flash)
    // ─────────────────────────────────────────────────────────────────

    function open() {
        var root = getRoot();
        if (!root) return;
        state.isOpen = true;
        root.setAttribute('data-state', 'open');
        root.setAttribute('aria-hidden', 'false');
        var toggle = root.querySelector('[data-m24-action="toggle"]');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }

    function close() {
        var root = getRoot();
        if (!root) return;
        state.isOpen = false;
        root.setAttribute('data-state', 'closed');
        root.setAttribute('aria-hidden', 'true');
        var toggle = root.querySelector('[data-m24-action="toggle"]');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    function toggle() {
        if (state.isOpen) close(); else open();
    }

    /** Visuelles Feedback: kurzer Pulse-Effekt am Toggle */
    function flash() {
        var root = getRoot();
        if (!root) return;
        root.classList.remove('m24-sidebar--flash');
        // force reflow
        void root.offsetWidth;
        root.classList.add('m24-sidebar--flash');
    }

    /** Mini-Toast (3s) — minimalistisch, kein Library nötig */
    function showToast(msg) {
        var existing = document.querySelector('.m24-sidebar__toast');
        if (existing) existing.remove();
        var t = document.createElement('div');
        t.className = 'm24-sidebar__toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () {
            t.classList.add('m24-sidebar__toast--out');
            setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 400);
        }, 2200);
    }

    // ─────────────────────────────────────────────────────────────────
    // Submit (Sessionless POST)
    // ─────────────────────────────────────────────────────────────────

    function submit() {
        if (state.items.length === 0) return;

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = Config.submitUrl;
        form.style.display = 'none';

        var fldItems = document.createElement('input');
        fldItems.type = 'hidden';
        fldItems.name = 'items_json';
        fldItems.value = JSON.stringify(state.items);
        form.appendChild(fldItems);

        var fldSource = document.createElement('input');
        fldSource.type = 'hidden';
        fldSource.name = 'inquiry_source';
        fldSource.value = 'cart';
        form.appendChild(fldSource);

        var fldSourceMeta = document.createElement('input');
        fldSourceMeta.type = 'hidden';
        fldSourceMeta.name = 'inquiry_source_meta';
        try {
            fldSourceMeta.value = JSON.stringify(buildSourceMeta());
        } catch (e) {
            fldSourceMeta.value = '{}';
        }
        form.appendChild(fldSourceMeta);

        document.body.appendChild(form);
        form.submit();
    }

    // ─────────────────────────────────────────────────────────────────
    // Event-Handler
    // ─────────────────────────────────────────────────────────────────

    function handleClick(e) {
        var t = e.target;
        if (!t) return;

        // data-m24-add Buttons (auf Detail-Pages, Pillar-Pages, Test-Page)
        var addBtn = t.closest('[data-m24-add]');
        if (addBtn) {
            e.preventDefault();
            try {
                var payload = JSON.parse(addBtn.getAttribute('data-m24-add'));
                addItem(payload);
            } catch (err) {
                console.warn('M24Sidebar: invalid data-m24-add JSON', err);
            }
            return;
        }

        // Sidebar-interne Actions
        var root = getRoot();
        var actBtn = t.closest('[data-m24-action]');
        if (!actBtn || !root || !root.contains(actBtn)) {
            // Klick ausserhalb Panel/Toggle schliesst die offene Sidebar.
            if (state.isOpen && root && !root.contains(t)) { close(); }
            return;
        }

        var action = actBtn.getAttribute('data-m24-action');
        var li     = actBtn.closest('.m24-sidebar__item');
        var idx    = li ? parseInt(li.getAttribute('data-idx'), 10) : -1;

        switch (action) {
            case 'toggle':  toggle(); break;
            case 'close':   close(); break;
            case 'remove':  if (idx > -1) removeItem(idx); break;
            case 'qty-up':  if (idx > -1) setQty(idx, (parseInt(state.items[idx].qty, 10) || 1) + 1); break;
            case 'qty-down':if (idx > -1) setQty(idx, (parseInt(state.items[idx].qty, 10) || 1) - 1); break;
            case 'submit':  submit(); break;
        }
    }

    function handleQtyChange(e) {
        var t = e.target;
        if (!t || t.getAttribute('data-m24-action') !== 'qty-input') return;
        var li  = t.closest('.m24-sidebar__item');
        var idx = li ? parseInt(li.getAttribute('data-idx'), 10) : -1;
        if (idx > -1) setQty(idx, t.value);
    }

    // ─────────────────────────────────────────────────────────────────
    // Init
    // ─────────────────────────────────────────────────────────────────

    function init() {
        state.items = loadFromStorage();
        render();
        document.addEventListener('click',  handleClick);
        document.addEventListener('change', handleQtyChange);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && state.isOpen) { close(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Public API
    window.M24Sidebar = {
        addItem:     addItem,
        open:        open,
        close:       close,
        getItems:    function () { return state.items.slice(); },
        clear:       function () { state.items = []; saveToStorage(); clearSessionId(); render(); }
    };

})();
