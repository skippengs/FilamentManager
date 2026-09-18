/* ------------------------------------------------------------------
   Filament - browser side

   Everything here is a nicety on top of pages that already work without
   it. Turn JavaScript off and the search box still submits, the form
   still saves, the photos still upload. It is just less smooth.
   ------------------------------------------------------------------ */

(function () {
    'use strict';

    var cfg = window.FILAMENT || {};

    /* ---------- small helpers ---------- */

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    var toastTimer = null;

    function toast(message, isError) {
        var old = $('.toast');
        if (old) { old.remove(); }
        if (toastTimer) { clearTimeout(toastTimer); }

        var el = document.createElement('div');
        el.className = 'toast' + (isError ? ' toast-error' : '');
        el.setAttribute('role', 'status');
        el.textContent = message;
        document.body.appendChild(el);

        toastTimer = setTimeout(function () {
            el.classList.add('is-out');
            setTimeout(function () { el.remove(); }, 400);
        }, isError ? 4000 : 2200);
    }

    function post(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf: cfg.csrf }, payload))
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error('The server said something unexpected.');
            }).then(function (data) {
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || 'That did not save.');
                }
                return data;
            });
        });
    }

    function grams(weight, pct) {
        var left = weight * pct / 100;
        if (left >= 1000) {
            return String(Math.round(left / 100) / 10).replace(/\.0$/, '') + ' kg';
        }
        return Math.round(left) + ' g';
    }

    /* ------------------------------------------------------------------
     * Searching while you type
     *
     * Fetches the same page with &partial=1, which returns just the cards,
     * and swaps them in. The address bar is kept in step so a reload or a
     * shared link shows the same thing.
     * ------------------------------------------------------------------ */

    function initSearch() {
        var form = $('[data-search]');
        var results = $('[data-results]');
        if (!form || !results || !window.fetch || !window.history.replaceState) { return; }

        var timer = null;
        var inFlight = null;

        function run(push) {
            var params = new URLSearchParams(new FormData(form));

            // Drop the empty filters, so the address stays readable.
            Array.from(params.keys()).forEach(function (key) {
                if (!params.get(key)) { params.delete(key); }
            });

            var query = params.toString();
            var url = 'index.php' + (query ? '?' + query : '');

            if (inFlight) { inFlight.abort(); }
            inFlight = new AbortController();
            results.classList.add('is-loading');

            fetch(url + (query ? '&' : '?') + 'partial=1', {
                signal: inFlight.signal,
                headers: { 'X-Requested-With': 'fetch' }
            })
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    results.innerHTML = html;
                    results.classList.remove('is-loading');
                    if (push) { history.replaceState(null, '', url); }
                })
                .catch(function (err) {
                    if (err.name === 'AbortError') { return; }
                    results.classList.remove('is-loading');
                    toast('Could not reach the server.', true);
                });
        }

        $$('[data-search-input]', form).forEach(function (input) {
            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { run(true); }, 280);
            });
        });

        $$('[data-search-filter]', form).forEach(function (select) {
            select.addEventListener('change', function () { run(true); });
        });

        // The button still works, it just no longer reloads the page.
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearTimeout(timer);
            run(true);
        });
    }

    /* ------------------------------------------------------------------
     * One spool: photos and the quick slider
     * ------------------------------------------------------------------ */

    function initPhotos() {
        var main = $('[data-lightbox]');
        var box = $('[data-lightbox-wrap]');

        $$('[data-swap]').forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                var src = thumb.getAttribute('data-swap');
                var img = $('img', main);
                if (img) { img.src = src; }
                if (main) { main.setAttribute('data-lightbox', src); }

                $$('[data-swap]').forEach(function (t) { t.classList.remove('is-current'); });
                thumb.classList.add('is-current');
            });
        });

        if (!main || !box) { return; }

        function open() {
            $('[data-lightbox-img]', box).src = main.getAttribute('data-lightbox');
            box.hidden = false;
            document.body.style.overflow = 'hidden';
        }

        function close() {
            box.hidden = true;
            document.body.style.overflow = '';
        }

        main.addEventListener('click', open);
        box.addEventListener('click', close);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !box.hidden) { close(); }
        });
    }

    function initQuick() {
        var quick = $('[data-quick]');
        if (!quick || !cfg.id) { return; }

        var range = $('[data-quick-range]', quick);
        var note = $('[data-quick-note]', quick);
        var bar = $('[data-remaining-bar]');
        var text = $('[data-remaining-text]');
        var gramsOut = $('[data-remaining-grams]');
        var chip = $('[data-status-chip]');
        var timer = null;

        function paint(data) {
            if (bar) { bar.style.width = data.remaining_pct + '%'; }
            if (text) { text.textContent = data.remaining_pct + '%'; }
            if (gramsOut) { gramsOut.textContent = data.grams; }
            if (chip) {
                chip.textContent = data.status_label;
                chip.className = 'chip status-chip status-' + data.status;
            }

            $$('[data-quick-status]', quick).forEach(function (btn) {
                btn.classList.toggle('is-on', btn.getAttribute('data-quick-status') === data.status);
            });

            if (range && String(range.value) !== String(data.remaining_pct)) {
                range.value = data.remaining_pct;
            }

            var detail = $('.detail');
            if (detail) { detail.classList.toggle('is-empty', data.status === 'empty'); }
        }

        function save(payload, quiet) {
            if (note) { note.textContent = 'Saving…'; }

            post('api/remaining.php', Object.assign({ id: cfg.id }, payload))
                .then(function (data) {
                    paint(data);
                    if (note) { note.textContent = 'Saved.'; }
                    if (!quiet) { toast('Saved.'); }
                })
                .catch(function (err) {
                    if (note) { note.textContent = 'Not saved.'; }
                    toast(err.message, true);
                });
        }

        if (range) {
            // Show the move right away, save once you stop dragging.
            range.addEventListener('input', function () {
                if (bar) { bar.style.width = range.value + '%'; }
                if (text) { text.textContent = range.value + '%'; }
                if (gramsOut && cfg.weight) { gramsOut.textContent = grams(cfg.weight, range.value); }

                clearTimeout(timer);
                timer = setTimeout(function () {
                    save({ remaining_pct: parseInt(range.value, 10) }, true);
                }, 500);
            });
        }

        $$('[data-quick-status]', quick).forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                save({ status: btn.getAttribute('data-quick-status') });
            });
        });
    }

    /* ------------------------------------------------------------------
     * The add / edit form
     * ------------------------------------------------------------------ */

    /*
     * Brand and material are dropdowns, with a text box that only appears
     * when you pick "Something else". Phones give a dropdown a proper
     * full-height picker, which a datalist on a text field never got.
     */
    function initOtherFields() {
        $$('select[data-other]').forEach(function (select) {
            var wrap = select.parentNode.querySelector('[data-other-field]');
            if (!wrap) { return; }

            var input = wrap.querySelector('input');

            function apply(focus) {
                var isOther = select.value === '__other__';
                wrap.hidden = !isOther;

                // Required only while it is the field actually being used,
                // otherwise the browser blocks a submit over a hidden box.
                if (input) {
                    input.required = isOther;
                    if (isOther && focus) { input.focus(); }
                    if (!isOther) { input.value = ''; }
                }
            }

            select.addEventListener('change', function () { apply(true); });
            apply(false);
        });
    }

    function initColorField() {
        var picker = $('[data-color-picker]');
        var textIn = $('[data-color-text]');
        var clear = $('[data-color-clear]');
        if (!picker || !textIn) { return; }

        picker.addEventListener('input', function () { textIn.value = picker.value; });

        textIn.addEventListener('input', function () {
            var value = textIn.value.trim();
            if (/^#?[0-9a-f]{6}$/i.test(value)) {
                picker.value = value.charAt(0) === '#' ? value : '#' + value;
            }
        });

        if (clear) {
            clear.addEventListener('click', function () {
                textIn.value = '';
                textIn.focus();
            });
        }
    }

    function initRange() {
        $$('[data-range]').forEach(function (range) {
            var out = $('[data-range-out]', range.closest('.field'));
            if (!out) { return; }

            function show() { out.textContent = range.value + '%'; }
            range.addEventListener('input', show);
            show();
        });
    }

    /*
     * A sealed spool is full and an empty one is empty. Rather than letting
     * you set a percentage that the server will overrule anyway, the slider
     * follows the status and greys itself out.
     */
    function initStatusLink() {
        var group = $('[data-status-group]');
        var field = $('[data-remaining-field]');
        if (!group || !field) { return; }

        var range = $('[data-range]', field);
        var out = $('[data-range-out]', field);
        var hint = $('[data-remaining-hint]', field);
        var lastOpen = range ? range.value : '100';

        function apply(initial) {
            var checked = $('input:checked', group);
            var status = checked ? checked.value : 'sealed';
            var locked = status !== 'open';

            field.classList.toggle('is-locked', locked);

            if (range) {
                if (!locked) {
                    if (!initial) { range.value = lastOpen; }
                } else {
                    if (!initial) { lastOpen = range.value; }
                    range.value = status === 'sealed' ? 100 : 0;
                }
                if (out) { out.textContent = range.value + '%'; }
            }

            if (hint) {
                hint.textContent = locked
                    ? 'A ' + (status === 'sealed' ? 'sealed spool counts as full' : 'spool marked empty counts as nothing left') + '.'
                    : 'Roughly is fine. Nobody weighs these.';
            }
        }

        $$('input', group).forEach(function (radio) {
            radio.addEventListener('change', function () { apply(false); });
        });

        apply(true);
    }

    /* Price makes no sense on a gift, and "bought at" reads wrong there. */
    function initSourceLink() {
        var group = $('[data-source-group]');
        if (!group) { return; }

        var priceField = $('[data-price-field]');
        var vendorLabel = $('[data-vendor-label]');
        var dateLabel = $('[data-acquired-label]');

        function apply() {
            var checked = $('input:checked', group);
            var source = checked ? checked.value : 'bought';
            var bought = source === 'bought';

            if (priceField) { priceField.hidden = !bought; }
            if (vendorLabel) { vendorLabel.textContent = bought ? 'Bought at' : 'From'; }
            if (dateLabel) { dateLabel.textContent = bought ? 'Bought on' : 'Received on'; }
        }

        $$('input', group).forEach(function (radio) {
            radio.addEventListener('change', apply);
        });

        apply();
    }

    function initQuickpick() {
        $$('[data-fill]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = $(btn.getAttribute('data-fill'));
                if (target) {
                    target.value = btn.getAttribute('data-value');
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                }
            });
        });
    }

    /*
     * Collects the photos before they are uploaded, and shows them.
     *
     * A camera field holds exactly one picture, so shooting a second one
     * silently replaced the first and you had to save and come back to add
     * another. Every photo is moved into a basket field the moment it is
     * taken, which empties the camera field for the next shot and lets the
     * whole lot go up in one save.
     */
    function initPhotoBasket() {
        var box = $('[data-previews]');
        var basket = $('[data-photo-basket]');
        var hint = $('[data-photo-hint]');
        var inputs = $$('[data-photo-input]');

        if (!box || inputs.length === 0 || !window.FileReader) { return; }

        // Rebuilding a FileList needs DataTransfer. Without it, fall back to
        // the old one-photo-at-a-time behaviour rather than losing pictures.
        var canCollect = basket && typeof DataTransfer === 'function';

        try {
            if (canCollect) { new DataTransfer(); }
        } catch (e) {
            canCollect = false;
        }

        var room = parseInt(box.getAttribute('data-photo-room'), 10);
        if (isNaN(room)) { room = 6; }

        var picked = [];

        function sync() {
            if (!canCollect) { return; }

            var dt = new DataTransfer();
            picked.forEach(function (file) { dt.items.add(file); });
            basket.files = dt.files;
        }

        function say(message) {
            if (!hint) { return; }
            hint.textContent = message;
        }

        function render() {
            box.innerHTML = '';

            picked.forEach(function (file, index) {
                var figure = document.createElement('figure');
                var img = document.createElement('img');
                var remove = document.createElement('button');

                img.alt = '';
                var reader = new FileReader();
                reader.onload = function (e) { img.src = e.target.result; };
                reader.readAsDataURL(file);

                remove.type = 'button';
                remove.className = 'preview-x';
                remove.setAttribute('aria-label', 'Remove this photo');
                remove.textContent = '×';
                remove.addEventListener('click', function () {
                    picked.splice(index, 1);
                    sync();
                    render();
                });

                figure.appendChild(img);
                figure.appendChild(remove);
                box.appendChild(figure);
            });

            if (picked.length === 0) {
                say('Keep tapping "Take a photo" to add more — room for ' + room + ' here.');
            } else {
                var left = room - picked.length;
                say(picked.length + ' photo' + (picked.length === 1 ? '' : 's') + ' ready to upload'
                    + (left > 0 ? ', room for ' + left + ' more.' : '. That is the maximum.'));
            }
        }

        inputs.forEach(function (input) {
            input.addEventListener('change', function () {
                if (!canCollect) { return; }

                Array.prototype.forEach.call(input.files || [], function (file) {
                    if (!/^image\//.test(file.type)) { return; }
                    if (picked.length >= room) { return; }
                    picked.push(file);
                });

                // Emptying it matters twice over: the camera fires change
                // again for the next shot, and these files are not posted a
                // second time alongside the basket.
                input.value = '';

                sync();
                render();
            });
        });

        if (canCollect) { render(); }
    }

    /* A big upload over a slow line looks broken without this. */
    function initSubmitState() {
        var form = $('[data-spool-form]');
        if (!form) { return; }

        form.addEventListener('submit', function (e) {
            // The photo buttons post a different form; leave those alone.
            if (e.submitter && e.submitter.getAttribute('form')) { return; }

            var button = $('button[type="submit"]', form);
            if (button) {
                button.classList.add('is-busy');
                button.textContent = 'Saving…';
            }
        });
    }

    /* ---------- go ---------- */

    document.addEventListener('DOMContentLoaded', function () {
        initSearch();
        initPhotos();
        initQuick();
        initOtherFields();
        initColorField();
        initRange();
        initStatusLink();
        initSourceLink();
        initQuickpick();
        initPhotoBasket();
        initSubmitState();
    });
}());
