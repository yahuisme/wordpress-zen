/* A single Settings API form: disclosure never disables saved controls. */
(function () {
    'use strict';

    function init() {
        var form = document.getElementById('zen-options-form');
        if (!form || form.dataset.zenReady) return;
        form.dataset.zenReady = 'true';
        var status = document.getElementById('zen-save-state');
        var width = document.getElementById('zen_reading_width');
        var preset = document.getElementById('zen_reading_width_preset');
        var submission = null;

        function snapshot() {
            // Successful controls only; unnamed UI presets are not settings.
            return JSON.stringify(Array.from(new FormData(form).entries()));
        }
        var original = snapshot();
        function dirty() { return snapshot() !== original; }
        function refresh() {
            var changed = dirty();
            form.classList.toggle('zen-is-dirty', changed);
            if (status) status.textContent = changed ? status.dataset.dirty : status.dataset.clean;
        }
        function syncPreset() {
            if (!width || !preset) return;
            preset.value = ['600', '720', '800', '960'].includes(width.value) ? width.value : 'custom';
        }
        if (width && preset) {
            syncPreset();
            preset.addEventListener('change', function () {
                if (preset.value !== 'custom') width.value = preset.value;
                else width.focus();
                refresh();
            });
            width.addEventListener('input', syncPreset);
            width.addEventListener('change', syncPreset);
        }
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
        form.addEventListener('reset', function () {
            window.setTimeout(function () { syncPreset(); refresh(); }, 0);
        });
        form.addEventListener('invalid', function (event) {
            var parent = event.target.parentElement;
            while (parent && parent !== form) {
                if (parent.tagName === 'DETAILS') parent.open = true;
                parent = parent.parentElement;
            }
        }, true);
        form.addEventListener('submit', function (event) { submission = event; });
        document.querySelectorAll('[data-zen-confirm]').forEach(function (button) {
            if (!button.form) return;
            button.form.addEventListener('submit', function (event) {
                submission = event;
                if (!window.confirm(button.dataset.zenConfirm)) event.preventDefault();
            });
        });
        var updateStatus = document.getElementById('zen-update-status');
        var adminSettings = window.zenAdminSettings || {};
        if (updateStatus && updateStatus.dataset.updatePending === 'true' && adminSettings.ajaxUrl && adminSettings.nonce) {
            updateStatus.textContent = updateStatus.dataset.loading || updateStatus.textContent;
            window.fetch(adminSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({ action: 'zen_update_info', nonce: adminSettings.nonce }).toString()
            }).then(function (response) {
                if (!response.ok) throw new Error('update request failed');
                return response.json();
            }).then(function (payload) {
                if (!payload || !payload.success || !payload.data || typeof payload.data.message !== 'string') throw new Error('invalid update response');
                updateStatus.textContent = payload.data.message;
            }).catch(function () {
                updateStatus.textContent = updateStatus.dataset.error || '更新检查失败。';
            });
        }
        window.addEventListener('beforeunload', function (event) {
            if ((!submission || submission.defaultPrevented) && dirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
