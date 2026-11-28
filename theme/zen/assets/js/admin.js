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
        var presetControls = document.getElementById('zen-width-presets');
        var submission = null;
        var groups = Array.from(form.querySelectorAll('.zen-options-group')).filter(group => group.id && group.id !== 'zen-code');
        var groupsKey = 'zen-options-groups';
        var groupPreferences = {};
        try {
            var savedGroups = JSON.parse(window.sessionStorage.getItem(groupsKey) || '{}');
            groups.forEach(function (group) {
                if (savedGroups && typeof savedGroups[group.id] === 'boolean') {
                    groupPreferences[group.id] = savedGroups[group.id];
                    if (!group.querySelector('[aria-invalid="true"]')) group.open = savedGroups[group.id];
                }
            });
        } catch (error) {}
        var lastOpen = new Map(groups.map(group => [group, group.open]));
        groups.forEach(function (group) {
            group.addEventListener('toggle', function () {
                // Initial markup, restored state and error reveals can all queue toggle.
                if (group.open === lastOpen.get(group)) return;
                lastOpen.set(group, group.open);
                if (group.open === groupPreferences[group.id]) return;
                groupPreferences[group.id] = group.open;
                try { window.sessionStorage.setItem(groupsKey, JSON.stringify(groupPreferences)); } catch (error) {}
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
                if (preset.value === 'custom') width.focus();
                else if (width.value !== preset.value) {
                    width.value = preset.value;
                    refresh();
                }
            });
            width.addEventListener('input', syncPreset);
            width.addEventListener('change', syncPreset);
            if (presetControls) presetControls.hidden = false;
            var presetHelp = document.getElementById('zen-width-preset-help');
            if (presetHelp) presetHelp.hidden = false;
        }
        function refreshSetting(event) {
            if (event.target.name && event.target.form === form && !event.target.disabled) refresh();
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
            // Core's wrapper can load even if its CodeMirror script did not.
            if (!codeSection.open || !window.zenAdmin?.codeEditor
                || typeof window.wp?.codeEditor?.initialize !== 'function'
                || typeof window.wp?.CodeMirror?.fromTextArea !== 'function') return;
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
                    var input = editors[index].getInputField();
                    for (var attribute of ['aria-invalid', 'aria-describedby']) {
                        if (field.hasAttribute(attribute)) input.setAttribute(attribute, field.getAttribute(attribute));
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
                if (parent.tagName === 'DETAILS' && !parent.open) {
                    if (lastOpen.has(parent)) lastOpen.set(parent, true);
                    parent.open = true;
                }
                parent = parent.parentElement;
            }
        }
        form.addEventListener('invalid', function (event) { revealField(event.target); }, true);
        document.querySelectorAll('.zen-settings-errors a').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var field = document.getElementById(link.hash.slice(1));
                if (!field || !form.contains(field)) return;
                event.preventDefault();
                revealField(field);
                var editor = editors[codeFields.indexOf(field)];
                if (editor) {
                    editor.getWrapperElement().scrollIntoView({ block: 'center' });
                    editor.focus();
                } else {
                    field.scrollIntoView({ block: 'center' });
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
        document.addEventListener('keydown', function (event) {
            // A held save shortcut must not revoke a pending native save exemption.
            var save = !event.defaultPrevented && !event.isComposing && event.key.toLowerCase() === 's'
                && (event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && form.requestSubmit;
            if (!save || !event.repeat) clearSubmission();
            // Capture before CodeMirror consumes the key: one validated save path for every control.
            if (!save) return;
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
        if (status) {
            status.textContent = status.dataset.clean;
            status.hidden = false;
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
}());
