/**
 * M24 Plattform — Erweiterte Teile-Verwaltung (Admin-Liste)
 *
 * 1. Inline-Preis-Edit (Change-Event → AJAX, visuelles Feedback)
 * 2. Quick-Edit: populate Preis/Modell/Baugruppe-Felder mit aktuellen Werten der Zeile
 * 3. Beschreibung Quick-View: Toggle eines Inline-Panels mit Excerpt
 * 4. Bulk-Action „Modell/Baugruppe zuweisen": prompt User vor Submit, hidden input injizieren
 */
(function($) {
    'use strict';

    var Cfg = (typeof M24AdminList !== 'undefined') ? M24AdminList : {};

    // ─── Tabelle in horizontal-scroll Wrapper haengen ──────
    $(function() {
        var $table = $('.wp-list-table.posts').first();
        if ($table.length && !$table.parent().hasClass('m24-table-scroll')) {
            $table.wrap('<div class="m24-table-scroll"></div>');
        }
        // Edit-Icon zu Title-Links hinzufuegen
        injectTitleEdit();
        // Beschreibung gedaempft unter den Titel
        injectRowDesc();
    });

    function escapeHtml(s) {
        return $('<div>').text(s).html();
    }

    function injectTitleEdit() {
        $('.column-title strong').each(function() {
            var $strong = $(this);
            if ($strong.find('.m24-title-edit').length) return;
            $strong.append(' <a href="#" class="m24-title-edit" title="Titel inline bearbeiten">✎</a>');
        });
    }

    // Gekuerzte Beschreibung (~140 Zeichen) gedaempft unter dem Titel; Volltext im title-Attr.
    function injectRowDesc() {
        $('#the-list > tr').each(function() {
            var $row = $(this);
            var $cell = $row.find('.column-title .row-title').closest('td.column-title');
            if (!$cell.length) { $cell = $row.find('td.column-title'); }
            if (!$cell.length || $cell.find('.m24-row-desc').length) return;
            var full = ($row.find('.m24-qv-data').attr('data-desc') || '').trim();
            if (!full) return;
            var short = full.length > 140 ? full.slice(0, 139).replace(/\s+\S*$/, '') + '…' : full;
            var $strong = $cell.find('strong').first();
            $('<div class="m24-row-desc"></div>').text(short).attr('title', full).insertAfter($strong);
        });
    }

    // ─── Bulk „Original BMW-Teil" (alle, batchweise) ───────
    function bulkOriginal(op, $btn) {
        var processed = 0;
        var $status = $('.m24-bulk-orig-status');
        function step(offset) {
            $.post(M24AdminList.ajaxUrl, {
                action: 'm24_bulk_original',
                nonce: M24AdminList.nonceBulkOrig,
                op: op,
                offset: offset
            }).done(function(res) {
                if (!res || !res.success) { $status.text('Fehler'); return; }
                processed += res.data.processed;
                $status.text(processed + ' / ' + res.data.total + ' …');
                if (res.data.next !== null && res.data.next !== undefined) {
                    step(res.data.next);
                } else {
                    $status.text('Fertig: ' + processed + ' Teile aktualisiert.');
                    setTimeout(function() { location.reload(); }, 900);
                }
            }).fail(function() { $status.text('Fehler'); });
        }
        var msg = (op === 'set')
            ? 'Wirklich ALLE Teile als „Original BMW-Teil" markieren?'
            : 'Wirklich bei ALLEN Teilen „Original BMW-Teil" entfernen?';
        if (!window.confirm(msg)) { return; }
        $status.text('Start …');
        step(0);
    }
    $(document).on('click', '.m24-bulk-orig-set', function(e) { e.preventDefault(); bulkOriginal('set', $(this)); });
    $(document).on('click', '.m24-bulk-orig-unset', function(e) { e.preventDefault(); bulkOriginal('unset', $(this)); });

    // ─── Inline-Status-Select ──────────────────────────────
    $(document).on('focus', '.m24-inline-status', function() { $(this).data('prev', $(this).val()); });
    $(document).on('change', '.m24-inline-status', function() {
        var $sel = $(this);
        var postId = $sel.data('post');
        var val = $sel.val();
        var prev = $sel.data('prev') || $sel.data('current') || 'aktiv';
        var $msg = $sel.closest('td').find('.m24-status-msg');
        if (val === 'geloescht' && !window.confirm('Teil in den Papierkorb verschieben? (wiederherstellbar)')) {
            $sel.val(prev); return;
        }
        $msg.text('…');
        $.post(M24AdminList.ajaxUrl, {
            action: 'm24_status_set',
            nonce: M24AdminList.nonceStatus,
            post_id: postId,
            status: val
        }).done(function(res) {
            if (res && res.success) {
                $sel.data('prev', val);
                if (res.data.trashed) { $sel.closest('tr').fadeOut(400); }
                else { $msg.text('✓'); setTimeout(function() { $msg.text(''); }, 1200); }
            } else {
                $msg.text('✗'); $sel.val(prev);
            }
        }).fail(function() { $msg.text('✗'); $sel.val(prev); });
    });

    // ─── Inline „Original BMW-Teil" (Checkbox) ─────────────
    $(document).on('change', '.m24-inline-original', function() {
        var $cb = $(this);
        var postId = $cb.data('post');
        var on = $cb.is(':checked') ? '1' : '0';
        var $st = $cb.closest('.m24-original-cell').find('.m24-original-status');
        $st.text('…');
        $.post(M24AdminList.ajaxUrl, {
            action: 'm24_original_toggle',
            nonce: M24AdminList.nonceOriginal,
            post_id: postId,
            on: on
        }).done(function(res) {
            $st.text(res && res.success ? '✓' : '✗');
            setTimeout(function() { $st.text(''); }, 1200);
        }).fail(function() {
            $st.text('✗');
            $cb.prop('checked', on !== '1'); // revert
        });
    });

    // ─── Inline „Rennsport-Hinweis" (Checkbox) ─────────────
    $(document).on('change', '.m24-inline-rennsport', function() {
        var $cb = $(this);
        var postId = $cb.data('post');
        var on = $cb.is(':checked') ? '1' : '0';
        var $st = $cb.closest('.m24-original-cell').find('.m24-original-status');
        $st.text('…');
        $.post(M24AdminList.ajaxUrl, {
            action: 'm24_rennsport_toggle',
            nonce: M24AdminList.nonceRennsport,
            post_id: postId,
            on: on
        }).done(function(res) {
            $st.text(res && res.success ? '✓' : '✗');
            setTimeout(function() { $st.text(''); }, 1200);
        }).fail(function() {
            $st.text('✗');
            $cb.prop('checked', on !== '1'); // revert
        });
    });

    // ─── Inline-Title-Edit (Klick auf ✎-Icon) ──────────────
    $(document).on('click', '.m24-title-edit', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var $btn = $(this);
        var $strong = $btn.closest('strong');
        var $a = $strong.find('a.row-title, a').filter(function() { return !$(this).hasClass('m24-title-edit'); }).first();
        var $row = $btn.closest('tr');
        var postId = parseInt(($row.attr('id') || '').replace('post-', ''), 10);
        if (!postId) return;
        var href = $a.attr('href');
        var oldTitle = $.trim($a.text());

        // Replace mit Input
        var $input = $('<input type="text" class="m24-title-input">').val(oldTitle);
        $strong.html('').append($input);
        $input.focus();
        // Cursor ans Ende (statt select-all, damit User Korrekturen am Ende einfach machen kann)
        $input[0].setSelectionRange(oldTitle.length, oldTitle.length);

        function restore(t) {
            $strong.html('<a href="' + href + '" class="row-title">' + escapeHtml(t) + '</a>');
            injectTitleEdit();
        }

        function save() {
            if ($input.prop('disabled')) return;
            var newTitle = $.trim($input.val());
            if (newTitle === oldTitle || !newTitle) {
                restore(oldTitle);
                return;
            }
            $input.prop('disabled', true).removeClass('saved error').addClass('saving');
            $.post(Cfg.ajaxUrl, {
                action: 'm24_inline_title',
                nonce: Cfg.nonceTitle,
                post_id: postId,
                title: newTitle
            }).done(function(res) {
                if (res && res.success && res.data && res.data.title) {
                    $input.removeClass('saving').addClass('saved');
                    setTimeout(function() { restore(res.data.title); }, 600);
                } else {
                    $input.removeClass('saving').addClass('error').prop('disabled', false);
                    alert((res && res.data && res.data.msg) || 'Fehler beim Speichern');
                }
            }).fail(function() {
                $input.removeClass('saving').addClass('error').prop('disabled', false);
                alert('Netzwerk-Fehler');
            });
        }

        $input.on('keydown', function(ev) {
            if (ev.key === 'Enter')       { ev.preventDefault(); save(); }
            else if (ev.key === 'Escape') { ev.preventDefault(); restore(oldTitle); }
        });
        $input.on('blur', save);
    });

    // ─── 1. Inline-Preis-Edit ───────────────────────────────
    // Enter im Preisfeld darf NICHT das #posts-filter-Formular abschicken (sonst Listen-Bug);
    // stattdessen den bestehenden change-Save auslösen.
    $(document).on('keydown', 'input.m24-inline-price', function(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            $(this).trigger('change');
        }
    });
    $(document).on('change', 'input.m24-inline-price', function() {
        var $inp = $(this);
        var postId = $inp.data('post');
        var newVal = $inp.val();
        var $status = $inp.siblings('.m24-price-status');
        $inp.removeClass('saved error').addClass('saving');
        $status.text('Speichere…').css('color', '#9a6b25');
        $.post(Cfg.ajaxUrl, {
            action: 'm24_inline_price',
            nonce: Cfg.noncePrice,
            post_id: postId,
            price: newVal
        }).done(function(res) {
            $inp.removeClass('saving');
            if (res && res.success && res.data && res.data.brutto_fmt) {
                $inp.val(res.data.brutto_fmt).addClass('saved');
                $status.text('✓ gespeichert').css('color', '#2f7d52');
                setTimeout(function() { $inp.removeClass('saved'); $status.text(''); }, 2000);
            } else {
                $inp.addClass('error');
                $status.text((res && res.data && res.data.msg) ? res.data.msg : 'Fehler').css('color', '#9e2b2b');
            }
        }).fail(function() {
            $inp.removeClass('saving').addClass('error');
            $status.text('Netzwerk-Fehler').css('color', '#9e2b2b');
        });
    });

    // ─── 2. Quick-Edit: populate ───────────────────────────
    // WP-Standard: inlineEditPost.edit liest data-Attribute aus der Zeile.
    // Wir hooken in inlineEditPost.edit ein und befuellen unsere Custom-Felder.
    if (typeof inlineEditPost !== 'undefined') {
        var origEdit = inlineEditPost.edit;
        inlineEditPost.edit = function(id) {
            origEdit.apply(this, arguments);
            var postId = (typeof id === 'object') ? this.getId(id) : id;
            if (!postId) return;
            var $row = $('#post-' + postId);
            var $editRow = $('#edit-' + postId);

            // Brutto-Preis aus Inline-Input lesen
            var priceVal = $row.find('input.m24-inline-price').val() || '';
            $editRow.find('input[name="m24_qe_brutto"]').val(priceVal);

            // Multi-Selects: aktuelle Term-IDs aus Multi-Cell-data-Attribut vorbefuellen.
            // So sieht der User was schon zugewiesen ist und kann gezielt hinzufuegen/abwaehlen.
            var currentModell = ($row.find('.m24-multi-modell').data('current') + '').split(',').filter(Boolean);
            $editRow.find('select[name="m24_qe_modell[]"]').val(currentModell);
            // Baugruppe-Spalte zeigt nur Namen — kein data-current. Selects bleiben leer.
            $editRow.find('select[name="m24_qe_baugruppe[]"]').val([]);
            // Replace/Clear-Checkboxes reset
            $editRow.find('input[name="m24_qe_modell_replace"], input[name="m24_qe_modell_clear"], input[name="m24_qe_baugruppe_replace"], input[name="m24_qe_baugruppe_clear"]').prop('checked', false);
        };
    }

    // ─── 3. Beschreibung Quick-View ───────────────────────
    $(document).on('click', '.m24-qv-toggle', function(e) {
        e.preventDefault();
        var postId = $(this).data('post');
        var $row = $('#post-' + postId);
        var $existing = $row.next('.m24-qv-row');
        if ($existing.length) { $existing.remove(); return; }
        var $dataNode = $row.find('.m24-qv-data[data-post="' + postId + '"]');
        var desc = $dataNode.attr('data-desc') || '(keine Beschreibung)';
        var colCount = $row.children('th, td').length;
        var $qvRow = $('<tr class="m24-qv-row"><td colspan="' + colCount + '"><div class="m24-qv-panel">' + desc + '</div></td></tr>');
        $row.after($qvRow);
    });

    // ─── 5. Multi-Select Modell-Dropdown pro Zeile ─────────
    // Toggle-Click → Dropdown lazy-rendern aus Cfg.modellTerms
    $(document).on('click', '.m24-multi-modell .m24-ms-toggle', function(e) {
        e.preventDefault();
        var $container = $(this).closest('.m24-multi-modell');
        var $dd = $container.find('.m24-ms-dropdown');
        if (!$dd.prop('hidden') && $dd.is(':visible')) {
            $dd.prop('hidden', true).hide();
            return;
        }
        // Schliesse alle anderen offenen Dropdowns
        $('.m24-ms-dropdown:visible').prop('hidden', true).hide();

        // Lazy-render
        if ($dd.is(':empty')) {
            var current = ($container.data('current') + '').split(',').map(function(x){ return parseInt(x,10); }).filter(Boolean);
            var terms = Cfg.modellTerms || [];
            var html = '';
            terms.forEach(function(t) {
                var checked = current.indexOf(t.id) !== -1 ? ' checked' : '';
                html += '<label><input type="checkbox" value="' + t.id + '"' + checked + '> ' + $('<div>').text(t.name).html() + '</label>';
            });
            if (!terms.length) html = '<p style="margin:0;color:#999;padding:4px">Keine Modell-Terms vorhanden.</p>';
            $dd.html(html);
        } else {
            // Re-Sync: Checkboxes an aktuellem state
            var current2 = ($container.data('current') + '').split(',').map(function(x){ return parseInt(x,10); }).filter(Boolean);
            $dd.find('input[type="checkbox"]').each(function() {
                this.checked = current2.indexOf(parseInt(this.value, 10)) !== -1;
            });
        }
        $dd.prop('hidden', false).show();
    });

    // Outside-Click schliesst Dropdown
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.m24-multi-modell').length) {
            $('.m24-ms-dropdown:visible').prop('hidden', true).hide();
        }
    });

    // Aktualisiert Chips + data-current IN PLACE (Container NICHT ersetzen), damit das
    // Multi-Select-Dropdown beim Auswaehlen offen bleibt (kein Schliessen pro Klick).
    // Offene Checkboxen werden an den neuen Stand angeglichen.
    function m24SyncModell($container, html) {
        var $new = $(html);
        var cur = ($new.attr('data-current') || '');
        $container.attr('data-current', cur).data('current', cur);
        $container.find('.m24-ms-chips').first().html($new.find('.m24-ms-chips').first().html());
        var $dd = $container.find('.m24-ms-dropdown');
        if ($dd.length && !$dd.prop('hidden')) {
            var ids = cur.split(',').map(function(x) { return parseInt(x, 10); }).filter(Boolean);
            $dd.find('input[type="checkbox"]').each(function() {
                this.checked = ids.indexOf(parseInt(this.value, 10)) !== -1;
            });
        }
    }

    // Checkbox-Change → AJAX add/remove
    $(document).on('change', '.m24-ms-dropdown input[type="checkbox"]', function() {
        var $cb = $(this);
        var $container = $cb.closest('.m24-multi-modell');
        var postId = $container.data('post');
        var termId = parseInt($cb.val(), 10);
        var op = $cb.is(':checked') ? 'add' : 'remove';
        $cb.prop('disabled', true);
        $.post(Cfg.ajaxUrl, {
            action: 'm24_modell_toggle',
            nonce: Cfg.nonceModellToggle,
            post_id: postId,
            term_id: termId,
            op: op
        }).done(function(res) {
            if (res && res.success && res.data && res.data.html) {
                // In place aktualisieren → Dropdown bleibt offen (Multi-Select).
                m24SyncModell($container, res.data.html);
            } else {
                $cb.prop('checked', !$cb.prop('checked'));  // revert
                alert((res && res.data && res.data.msg) || 'Fehler beim Speichern');
            }
        }).fail(function() {
            $cb.prop('checked', !$cb.prop('checked'));
            alert('Netzwerk-Fehler');
        }).always(function() {
            $cb.prop('disabled', false);
        });
    });

    // Chip-× Klick → AJAX remove
    $(document).on('click', '.m24-ms-chip .m24-ms-remove', function(e) {
        e.preventDefault();
        var $chip = $(this).closest('.m24-ms-chip');
        var $container = $chip.closest('.m24-multi-modell');
        var postId = $container.data('post');
        var termId = parseInt($chip.data('term-id'), 10);
        $chip.css('opacity', 0.4);
        $.post(Cfg.ajaxUrl, {
            action: 'm24_modell_toggle',
            nonce: Cfg.nonceModellToggle,
            post_id: postId,
            term_id: termId,
            op: 'remove'
        }).done(function(res) {
            if (res && res.success && res.data && res.data.html) {
                m24SyncModell($container, res.data.html);
            } else {
                $chip.css('opacity', 1);
                alert((res && res.data && res.data.msg) || 'Fehler');
            }
        }).fail(function() {
            $chip.css('opacity', 1);
            alert('Netzwerk-Fehler');
        });
    });

    // ─── 4. Bulk-Action Modell/Baugruppe → Multi-Select-Modal ─────
    var bulkPendingForm = null;
    $('#doaction, #doaction2').on('click', function(e) {
        var $btn = $(this);
        var $form = $btn.closest('form');
        var sel = ($btn.attr('id') === 'doaction') ? '#bulk-action-selector-top' : '#bulk-action-selector-bottom';
        var action = $form.find(sel).val();

        if (action !== 'm24_assign_modell' && action !== 'm24_assign_baugruppe') return;

        var isModell = (action === 'm24_assign_modell');
        var terms = isModell ? (Cfg.modellTerms || []) : (Cfg.baugruppeTerms || []);
        if (!terms.length) {
            alert('Keine ' + (isModell ? 'Modell' : 'Baugruppe') + '-Terms verfügbar. Bitte zuerst anlegen.');
            e.preventDefault();
            return;
        }

        e.preventDefault();
        bulkPendingForm = $form;

        var inputName = isModell ? 'm24_bulk_modell_terms[]' : 'm24_bulk_baugruppe_terms[]';
        var title = isModell ? 'Modelle zuweisen' : 'Baugruppen zuweisen';
        var optsHtml = terms.map(function(t) {
            return '<option value="' + t.id + '">' + $('<div>').text(t.name).html() + '</option>';
        }).join('');

        var modalHtml = ''
            + '<div class="m24-bulk-modal" id="m24-bulk-modal">'
            +   '<div class="m24-bulk-modal-content">'
            +     '<h3>' + title + '</h3>'
            +     '<p>Mehrere mit Ctrl/Cmd auswählen. Standard: zu bestehenden Zuordnungen hinzufügen.</p>'
            +     '<select multiple id="m24-bulk-select" name="' + inputName + '">' + optsHtml + '</select>'
            +     '<p><label><input type="checkbox" id="m24-bulk-replace-mode"> Bestehende Zuordnungen ersetzen (statt hinzufügen)</label></p>'
            +     '<div class="m24-bulk-modal-actions">'
            +       '<button class="button" id="m24-bulk-cancel" type="button">Abbrechen</button>'
            +       '<button class="button button-primary" id="m24-bulk-apply" type="button">Übernehmen</button>'
            +     '</div>'
            +   '</div>'
            + '</div>';
        $('body').append(modalHtml);
        $('#m24-bulk-select').focus();
    });

    $(document).on('click', '#m24-bulk-cancel', function() {
        $('#m24-bulk-modal').remove();
        bulkPendingForm = null;
    });

    $(document).on('click', '#m24-bulk-apply', function() {
        var $sel = $('#m24-bulk-select');
        var selected = $sel.val() || [];
        if (!selected.length) {
            alert('Bitte mindestens einen Term auswählen.');
            return;
        }
        if (!bulkPendingForm) { $('#m24-bulk-modal').remove(); return; }
        var inputName = $sel.attr('name');
        var replace = $('#m24-bulk-replace-mode').is(':checked');

        // Hidden inputs ins ursprueliche Bulk-Form injizieren
        selected.forEach(function(id) {
            bulkPendingForm.append('<input type="hidden" name="' + inputName + '" value="' + id + '">');
        });
        if (replace) {
            bulkPendingForm.append('<input type="hidden" name="m24_bulk_replace" value="1">');
        }
        $('#m24-bulk-modal').remove();
        bulkPendingForm.submit();
        bulkPendingForm = null;
    });

})(jQuery);
