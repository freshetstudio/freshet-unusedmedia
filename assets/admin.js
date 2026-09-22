/**
 * Freshet Unused Media — admin behavior.
 * Vanilla JS; config injected via wp_add_inline_script (window.freshetUnusedMedia).
 */
(function () {
    'use strict';

    var config = window.freshetUnusedMedia || {};

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
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        }).then(function (json) {
            if (!json || json.success !== true) {
                throw new Error((json && json.data && json.data.message) || 'error');
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
        }).catch(function () {
            trigger.textContent = original;
            window.alert(config.i18n.error);
        });
    });

    // ---------------------------------------------------------- full scan

    var startButton = document.getElementById('freshet-unusedmedia-scan-start');
    var stopButton = document.getElementById('freshet-unusedmedia-scan-stop');
    var resetButton = document.getElementById('freshet-unusedmedia-scan-reset');
    var progress = document.getElementById('freshet-unusedmedia-progress');
    var stopped = false;

    function updateProgress(done, total, label) {
        if (!progress) {
            return;
        }

        progress.hidden = false;
        var bar = progress.querySelector('.freshet-unusedmedia-progress__bar span');
        var text = progress.querySelector('.freshet-unusedmedia-progress__label');
        var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;

        bar.style.width = percent + '%';
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
            updateProgress(data.done, data.total, data.label);

            if (data.finished) {
                window.location.reload();
                return;
            }

            scanLoop();
        }).catch(function () {
            startButton.disabled = false;
            stopButton.hidden = true;
            window.alert(config.i18n.error);
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
            });
        });
    }

    // ------------------------------------------------------ delete all

    var deleteAllButton = document.getElementById('freshet-unusedmedia-delete-all');
    var totals = { deleted: 0, skipped: 0, failed: 0 };

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
        }).catch(function () {
            // A run that ended here has not made a pass, and the counts alone
            // cannot tell the two apart — so the opening word does, and what it
            // never reached is named rather than left to the reload.
            window.alert(config.i18n.error + '\n\n' + deleteReport(config.i18n.deleteStopped));
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

            totals = { deleted: 0, skipped: 0, failed: 0 };
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
})();
