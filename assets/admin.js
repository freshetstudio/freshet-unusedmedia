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

    function scanLoop() {
        if (stopped) {
            startButton.disabled = false;
            stopButton.hidden = true;
            return;
        }

        post('freshet_unusedmedia_scan_batch', config.nonceManage).then(function (data) {
            updateProgress(data.done, data.total);

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

    function deleteLoop() {
        post('freshet_unusedmedia_delete_batch', config.nonceManage).then(function (data) {
            totals.deleted += data.deleted;
            totals.skipped += data.skipped;
            totals.failed += data.failed;

            if (data.finished) {
                window.alert(
                    config.i18n.deleteDone
                        .replace('%1$s', String(totals.deleted))
                        .replace('%2$s', String(totals.skipped))
                );
                window.location.reload();
                return;
            }

            updateProgress(0, 0, config.i18n.deleting + ' ' + totals.deleted);
            deleteLoop();
        }).catch(function () {
            window.alert(config.i18n.error);
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
            deleteAllButton.disabled = true;
            updateProgress(0, 0, config.i18n.deleting);
            deleteLoop();
        });
    }

    // ------------------------------------------------------- select all

    var selectAll = document.getElementById('freshet-unusedmedia-select-all');

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('#freshet-unusedmedia-delete-form input[name="attachments[]"]').forEach(function (box) {
                box.checked = selectAll.checked;
            });
        });
    }
})();
