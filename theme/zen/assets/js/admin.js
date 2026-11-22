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
        var groups = Array.from(form.querySelectorAll('.zen-options-group')).filter(group => group.id && group.id !== 'zen-code');
        var groupsKey = 'zen-options-groups';
        try {
            var savedGroups = JSON.parse(window.sessionStorage.getItem(groupsKey) || '{}');
            groups.forEach(function (group) {
                if (!group.querySelector('[aria-invalid="true"]') && savedGroups && typeof savedGroups[group.id] === 'boolean') {
                    group.open = savedGroups[group.id];
                }
            });
        } catch (error) {}
        groups.forEach(function (group) {
            group.addEventListener('toggle', function () {
                var state = {};
                groups.forEach(item => { state[item.id] = item.open; });
                try { window.sessionStorage.setItem(groupsKey, JSON.stringify(state)); } catch (error) {}
            });
        });

        function snapshot() {
            // Successful controls only; unnamed UI presets are not settings.
            return JSON.stringify(Array.from(new FormData(form).entries()));
        }
        var original = snapshot();
        function dirty() { return snapshot() !== original; }
        function refresh() {
            submission = null;
            var changed = dirty();
            form.classList.toggle('zen-is-dirty', changed);
            if (status) {
                var message = changed ? status.dataset.dirty : status.dataset.clean;
                if (status.textContent !== message) status.textContent = message;
            }
        }
        function syncPreset() {
            if (!width || !preset) return;
            preset.value = Array.from(preset.options).some(option => option.value === width.value) ? width.value : 'custom';
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
        function refreshSetting(event) {
            if (event.target !== preset) refresh();
        }
        form.addEventListener('input', refreshSetting);
        form.addEventListener('change', refreshSetting);

        function saveSettings() {
            if (form.requestSubmit) form.requestSubmit(document.getElementById('zen_save_top'));
        }
        var codeSection = document.getElementById('zen-code');
        var codeFields = codeSection ? Array.from(codeSection.querySelectorAll('textarea')) : [];
        var editors = [];
        function focusCode(index) {
            if (index < 0) codeSection.querySelector('summary').focus();
            else if (index >= codeFields.length) document.getElementById('submit').focus();
            else if (editors[index]) editors[index].focus();
            else codeFields[index].focus();
        }
        function initCodeEditors() {
            if (!codeSection.open || !window.zenAdmin || !window.zenAdmin.codeEditor
                || !window.wp || !window.wp.codeEditor) return;
            codeFields.forEach(function (field, index) {
                if (!editors[index]) {
                    var instance = window.wp.codeEditor.initialize(field, {
                        codemirror: {
                            screenReaderLabel: field.labels[0].textContent.trim()
                        },
                        onTabNext: function () { focusCode(index + 1); },
                        onTabPrevious: function () { focusCode(index - 1); }
                    });
                    editors[index] = instance.codemirror;
                    if (field.getAttribute('aria-invalid') === 'true') {
                        var input = editors[index].getInputField();
                        input.setAttribute('aria-invalid', 'true');
                        input.setAttribute('aria-describedby', field.getAttribute('aria-describedby'));
                    }
                    editors[index].on('change', function () {
                        editors[index].save();
                        refresh();
                    });
                }
                editors[index].refresh();
            });
        }
        if (codeSection) {
            codeSection.addEventListener('toggle', initCodeEditors);
            initCodeEditors();
        }
        form.addEventListener('reset', function () {
            window.setTimeout(function () { syncPreset(); refresh(); }, 0);
        });
        function revealField(field) {
            var parent = field.parentElement;
            while (parent && parent !== form) {
                if (parent.tagName === 'DETAILS') parent.open = true;
                parent = parent.parentElement;
            }
        }
        form.addEventListener('invalid', function (event) { revealField(event.target); }, true);
        document.querySelectorAll('.zen-settings-errors a').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var field = document.getElementById(link.hash.slice(1));
                if (!field || !form.contains(field)) return;
                revealField(field);
                var editor = editors[codeFields.indexOf(field)];
                if (editor) {
                    event.preventDefault();
                    editor.getWrapperElement().scrollIntoView({ block: 'center' });
                    editor.focus();
                } else {
                    field.focus({ preventScroll: true });
                }
            });
        });
        form.addEventListener('submit', function (event) { submission = event; });
        document.querySelectorAll('[data-zen-confirm]').forEach(function (button) {
            if (!button.form) return;
            button.form.addEventListener('submit', function (event) {
                submission = event;
                if (!window.confirm(button.dataset.zenConfirm)) event.preventDefault();
            });
            button.form.addEventListener('formdata', function (event) {
                // Upgrade only the confirmed submission's payload, not the native form.
                if (!submission || submission.target !== button.form || submission.defaultPrevented || !button.dataset.zenConfirmNonce) return;
                event.formData.set('action', 'zen_reset_confirm');
                event.formData.set('zen_reset_nonce', button.dataset.zenConfirmNonce);
            });
        });
        function clearSubmission() { submission = null; }
        window.addEventListener('pageshow', clearSubmission);
        document.addEventListener('click', clearSubmission, true);
        document.addEventListener('keydown', clearSubmission, true);
        document.addEventListener('keydown', function (event) {
            // Capture before CodeMirror consumes the key: one validated save path for every control.
            if (event.defaultPrevented || event.isComposing || event.key.toLowerCase() !== 's'
                || !(event.ctrlKey || event.metaKey) || event.shiftKey || event.altKey || !form.requestSubmit) return;
            event.preventDefault();
            if (!event.repeat) saveSettings();
        }, true);
        window.addEventListener('beforeunload', function (event) {
            // A native submit may leave this document open (for example on HTTP 204).
            var leavingForSubmit = submission && !submission.defaultPrevented;
            clearSubmission();
            if (!leavingForSubmit && dirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
