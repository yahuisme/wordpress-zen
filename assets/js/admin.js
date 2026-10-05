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
        window.addEventListener('beforeunload', function (event) {
            if ((!submission || submission.defaultPrevented) && dirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        function reveal(hash) {
            if (!/^#zen-[a-z-]+$/.test(hash)) return;
            var target = document.getElementById(hash.slice(1));
            if (target && target.tagName === 'DETAILS') target.open = true;
        }
        document.querySelectorAll('.zen-options-nav a').forEach(function (link) {
            link.addEventListener('click', function () { reveal(link.hash); });
        });
        window.addEventListener('hashchange', function () { reveal(window.location.hash); });
        reveal(window.location.hash);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
