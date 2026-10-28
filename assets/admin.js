/**
 * Freshet Unused Media — admin behavior.
 * Vanilla JS; config injected via wp_add_inline_script (window.freshetUnusedMedia).
 */
(function () {
    'use strict';

    var config = window.freshetUnusedMedia || {};

    // An error carrying a sentence written for the person reading it. A dropped
    // connection or a reply that is not JSON throws a plain Error whose message
    // belongs in a console, so the flag is what separates the two and reported()
    // below falls back to the generic line when there is nothing to quote.
    function failure(message) {
        var error = new Error(message);

        error.userMessage = message;

        return error;
    }

    // What to show for a rejected request: the server's own sentence when it
    // sent one, the generic line when it did not.
    function reported(error) {
        return (error && error.userMessage) || config.i18n.error;
    }

    // The message inside a refusal's body, if it has one. Any body at all may
    // arrive here — a plugin's notice printed ahead of the JSON, an HTML error
    // page, nothing — so a parse that fails is simply no message rather than a
    // second failure on top of the first.
    function serverMessage(body) {
        try {
            var json = JSON.parse(body);

            return (json && json.data && json.data.message) || '';
        } catch (e) {
            return '';
        }
    }

    function post(action, nonce, data) {
        var body = new URLSearchParams(data || {});
        body.set('action', action);
        body.set('_ajax_nonce', nonce);

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (response) {
            if (!response.ok) {
                // The body is where the answer is, and this used to throw on the
                // status without reading it. Every refusal the plugin sends is a
                // wp_send_json_error() with a written message and a status to
                // match — 400, 403, 500 — so the one sentence that said what had
                // happened was fetched, discarded, and replaced by "Request
                // failed — try again". freshet-406 is what that costs: a 500 on
                // a production site whose PHP error log could not be read, and
                // the plugin's own account of it had been in the response all
                // along.
                return response.text().then(function (text) {
                    throw failure(
                        serverMessage(text)
                        || config.i18n.errorStatus.replace('%s', response.status)
                    );
                });
            }

            return response.json();
        }).then(function (json) {
            if (!json || json.success !== true) {
                throw failure((json && json.data && json.data.message) || config.i18n.error);
            }

            return json.data;
        });
    }

    // ------------------------------------------------------- single check

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('.freshet-unusedmedia-check');

        if (!trigger || !config.nonceCheck) {
            return;
        }

        event.preventDefault();

        var id = trigger.getAttribute('data-id');
        var original = trigger.textContent;
        trigger.textContent = config.i18n.checking;

        post('freshet_unusedmedia_check', config.nonceCheck, { id: id }).then(function (data) {
            var cell = document.querySelector('.freshet-unusedmedia-cell[data-id="' + id + '"]');

            if (cell) {
                cell.innerHTML = data.badge;
            }

            var box = trigger.closest('.freshet-unusedmedia-box');

            if (box) {
                var button = box.querySelector('.freshet-unusedmedia-check').parentNode;
                box.innerHTML = data.evidence;
                box.appendChild(button);
            }

            trigger.textContent = original;
        }).catch(function (error) {
            trigger.textContent = original;
            window.alert(reported(error));
        });
    });

    // ---------------------------------------------------------- full scan

    var startButton = document.getElementById('freshet-unusedmedia-scan-start');
    var stopButton = document.getElementById('freshet-unusedmedia-scan-stop');
    var resetButton = document.getElementById('freshet-unusedmedia-scan-reset');
    var progress = document.getElementById('freshet-unusedmedia-progress');
    var stopped = false;

    // The bar, and the line under it.
    //
    // `percent` is optional and the server sends it on every scan reply: a scan
    // has two phases measured in different units — corpus rows while the index
    // is built, then files — and which pair this reply's figures belong to is
    // something only the server knows. Before it existed the bar was drawn from
    // the file count, which is zero for the whole of phase one, so it showed
    // nothing for the longest part of a large library's scan (freshet-390).
    //
    // done/total stay the fallback rather than being replaced, because the
    // delete-all loop below drives this same bar from counts that exist only in
    // this browser — and because a cached copy of this script should keep
    // working against a reply that has no percent in it.
    function updateProgress(done, total, label, percent) {
        if (!progress) {
            return;
        }

        progress.hidden = false;
        var bar = progress.querySelector('.freshet-unusedmedia-progress__bar span');
        var text = progress.querySelector('.freshet-unusedmedia-progress__label');

        if (typeof percent !== 'number') {
            percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
        }

        bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        text.textContent = label || (done + ' / ' + total);
    }

    // A figure the server re-read for this reply, written straight into
    // whichever elements were rendered with that status. Same idiom as the
    // progress bar: it looks them up and does nothing when there are none, so a
    // tab that does not show the number simply shows no change. Nothing is
    // computed here — the string arrives formatted for the site's locale.
    function updateCount(status, display) {
        if (display === undefined || display === null) {
            return;
        }

        document.querySelectorAll('[data-freshet-unusedmedia-count="' + status + '"]').forEach(function (element) {
            element.textContent = display;
        });
    }

    // The Resume button's count, written the same way the bar's line is: the
    // string arrives composed, and this only puts it where it goes. Without it
    // the button keeps the figure PHP printed at page load, so stopping a scan
    // at 170 left a button still offering to resume from 130 until someone
    // reloaded (freshet-304).
    //
    // Every batch and not only the stop: the stop is a flag in this script that
    // the server never hears about, so the count to fall back on is whatever
    // the last completed batch reported. It also turns a fresh run's "Start
    // full scan" into a Resume the moment there is progress to resume from.
    function updateResumeLabel(text) {
        if (!startButton || !text) {
            return;
        }

        startButton.textContent = text;
        startButton.dataset.resume = '1';
    }

    function scanLoop() {
        if (stopped) {
            startButton.disabled = false;
            stopButton.hidden = true;
            return;
        }

        post('freshet_unusedmedia_scan_batch', config.nonceManage).then(function (data) {
            // The label arrives written. Unlike the delete loop below, whose
            // counts exist only in this browser, everything a scan can say
            // about itself — the figures in the site's locale, how long it has
            // spent, what is left, which check the time is going to — is on the
            // server, in the site's language. See ScanProgress.
            updateProgress(data.done, data.total, data.label, data.percent);
            updateResumeLabel(data.resume);

            if (data.finished) {
                window.location.reload();
                return;
            }

            scanLoop();
        }).catch(function (error) {
            startButton.disabled = false;
            stopButton.hidden = true;
            window.alert(reported(error));
        });
    }

    if (startButton) {
        startButton.addEventListener('click', function () {
            stopped = false;
            startButton.disabled = true;
            stopButton.hidden = false;
            updateProgress(0, 0, config.i18n.scanning);
            scanLoop();
        });
    }

    if (stopButton) {
        stopButton.addEventListener('click', function () {
            stopped = true;
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            post('freshet_unusedmedia_scan_reset', config.nonceManage).then(function () {
                window.location.reload();
            }).catch(function (error) {
                // It had no catch at all, so a refused reset reloaded nothing
                // and said nothing — the button simply looked broken.
                window.alert(reported(error));
            });
        });
    }

    // ------------------------------------------------------ delete all

    var deleteAllButton = document.getElementById('freshet-unusedmedia-delete-all');
    var totals = { deleted: 0, skipped: 0, failed: 0, leftOnDisk: 0 };

    // The filter the button was labelled with, and the cursor the server hands
    // back. Both travel with every batch: the count in the button described a
    // filtered set, so the loop has to walk that set and nothing wider.
    var deleteFilters = '';
    var deleteCursor = 0;

    // The size of that same set, read off the button's label. It is the
    // denominator of the bar: every batch decides some of these files, so the
    // client already holds both halves of a real percentage and the server
    // needs no extra field for it.
    var deleteTotal = 0;

    /** Files this run has an answer for — deleted, skipped or failed. */
    function deleteDecided() {
        return totals.deleted + totals.skipped + totals.failed;
    }

    /**
     * The files the run never got an answer for. Zero on an ordinary completed
     * pass; non-zero when the loop stopped early, which is the one thing the
     * counts alone cannot say. Floored, because the pool can shrink under a
     * long run and a negative remainder is not a report.
     */
    function deleteNotReached() {
        return Math.max(0, deleteTotal - deleteDecided());
    }

    /**
     * The completion sentence, from whichever opening the run earned. Parity
     * with the checkbox form's admin notice: the same three counts, and the
     * same "untouched and still listed" line for what was left — a file the
     * delete path could not remove is never silently absent from it.
     */
    function deleteReport(template) {
        var message = template
            .replace('%1$s', String(totals.deleted))
            .replace('%2$s', String(totals.skipped))
            .replace('%3$s', String(totals.failed));

        var left = deleteNotReached();

        if (left > 0) {
            message += ' ' + config.i18n.deleteNotReached.replace('%s', String(left));
        }

        // Already among the deleted — their library entries are gone — and
        // named apart, because their files are not (freshet-436).
        if (totals.leftOnDisk > 0) {
            message += ' ' + config.i18n.deleteLeftOnDisk.replace('%s', String(totals.leftOnDisk));
        }

        return message;
    }

    function deleteProgress() {
        updateProgress(deleteDecided(), deleteTotal, config.i18n.deleteProgress
            .replace('%1$s', String(deleteDecided()))
            .replace('%2$s', String(deleteTotal))
            .replace('%3$s', String(totals.deleted))
            .replace('%4$s', String(totals.skipped))
            .replace('%5$s', String(totals.failed)));
    }

    function deleteLoop() {
        var payload = {};

        new URLSearchParams(deleteFilters).forEach(function (value, key) {
            payload[key] = value;
        });

        payload.after = String(deleteCursor);

        post('freshet_unusedmedia_delete_batch', config.nonceManage, payload).then(function (data) {
            deleteCursor = data.cursor || 0;
            totals.deleted += data.deleted;
            totals.skipped += data.skipped;
            totals.failed += data.failed;
            totals.leftOnDisk += data.left_on_disk || 0;

            // Before the finished branch, so the last batch moves the number
            // too — the loop is not the only thing on the screen that changed.
            updateCount('unused', data.unused_display);

            if (data.finished) {
                // Move the bar before the modal blocks on it, so what is behind
                // the alert agrees with what the alert says.
                deleteProgress();
                window.alert(deleteReport(config.i18n.deleteDone));
                window.location.reload();
                return;
            }

            deleteProgress();
            deleteLoop();
        }).catch(function (error) {
            // A run that ended here has not made a pass, and the counts alone
            // cannot tell the two apart — so the opening word does, and what it
            // never reached is named rather than left to the reload.
            window.alert(reported(error) + '\n\n' + deleteReport(config.i18n.deleteStopped));
            window.location.reload();
        });
    }

    if (deleteAllButton) {
        deleteAllButton.addEventListener('click', function () {
            var count = deleteAllButton.getAttribute('data-count') || '0';
            var message = deleteAllButton.getAttribute('data-confirm') || '';

            if (!window.confirm(message.replace('%s', count))) {
                return;
            }

            totals = { deleted: 0, skipped: 0, failed: 0, leftOnDisk: 0 };
            deleteFilters = deleteAllButton.getAttribute('data-filters') || '';
            deleteCursor = 0;
            deleteTotal = parseInt(count, 10) || 0;
            deleteAllButton.disabled = true;
            updateProgress(0, deleteTotal, config.i18n.deleting);
            deleteLoop();
        });
    }

    // ------------------------------------------------------- select all

    // One per checkbox form — the unused list and the In-trash section each
    // carry their own — and each ticks the boxes of its own form only.
    document.querySelectorAll('.freshet-unusedmedia-select-all').forEach(function (selectAll) {
        var form = selectAll.closest('form');

        if (!form) {
            return;
        }

        selectAll.addEventListener('change', function () {
            form.querySelectorAll('input[name="attachments[]"]').forEach(function (box) {
                box.checked = selectAll.checked;
            });
        });
    });

    // --------------------------------------------------- selection gate

    // "Delete selected" ships disabled (ToolsPage::renderDeleteSelectedButton)
    // and lights up the moment a row is ticked, because an enabled button is a
    // promise that pressing it does something (freshet-D289).
    //
    // Each form gates on its own boxes, so a selection in the unused list never
    // lights up the In-trash button. Each form's two buttons — above the table
    // and below it — move together because the count is re-read from the DOM on
    // any click or change rather than tracked: the select-all box, a redrawn
    // table or a shift-click cannot put a re-read out of step, and there is no
    // counter to fall behind. A form with no boxes in it at all (the In-trash
    // section, when nothing in it was scanned unused) reads zero and stays
    // disabled, which is the same rule and not a special case.
    var gatedForms = [];

    document.querySelectorAll('.freshet-unusedmedia-delete-selected').forEach(function (button) {
        var form = button.closest('form');

        if (form && gatedForms.indexOf(form) === -1) {
            gatedForms.push(form);
        }
    });

    gatedForms.forEach(function (form) {
        var gated = [];

        form.querySelectorAll('.freshet-unusedmedia-delete-selected').forEach(function (button) {
            // The hint is kept, not re-derived: it comes off the rendered button
            // so the wording stays PHP's and translated.
            gated.push({ button: button, hint: button.getAttribute('title') || '' });
        });

        function sync() {
            var ready = form.querySelectorAll('input[name="attachments[]"]:checked').length > 0;

            gated.forEach(function (entry) {
                entry.button.disabled = !ready;

                // A working control carries no nagging tooltip.
                if (ready) {
                    entry.button.removeAttribute('title');
                } else {
                    entry.button.setAttribute('title', entry.hint);
                }
            });
        }

        form.addEventListener('click', sync);
        form.addEventListener('change', sync);
        sync();
    });
})();
