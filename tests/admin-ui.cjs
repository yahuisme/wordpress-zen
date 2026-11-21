const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '..', 'theme', 'zen');
const html = execFileSync('php', [path.join(__dirname, 'admin-options.php'), '--html'], { encoding: 'utf8' });
function asset(file) { const target = path.join(root, file); return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : ''; }
function boot(prepare = () => {}) {
    const dom = new JSDOM(html, { url: 'https://example.test/wp-admin/admin.php?page=zen-options', runScripts: 'outside-only' });
    prepare(dom.window);
    dom.window.eval(asset('assets/js/admin.js'));
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
    return dom;
}
function edit(dom, id, value, event = 'input') {
    const input = dom.window.document.getElementById(id);
    if (input.type === 'checkbox') input.checked = value;
    else input.value = value;
    input.dispatchEvent(new dom.window.Event(event, { bubbles: true }));
}
function unloading(dom) {
    const event = new dom.window.Event('beforeunload', { cancelable: true });
    dom.window.dispatchEvent(event);
    return event.defaultPrevented;
}
test('dirty state reflects real form changes and restoring originals becomes clean', () => {
    const dom = boot();
    const state = dom.window.document.getElementById('zen-save-state');
    assert.equal(unloading(dom), false);
    edit(dom, 'zen_footer_text', 'changed');
    assert.equal(state.textContent, state.dataset.dirty);
    assert.equal(unloading(dom), true);
    edit(dom, 'zen_footer_text', '');
    assert.equal(state.textContent, state.dataset.clean);
    assert.equal(unloading(dom), false);
    edit(dom, 'zen_show_toc', false, 'change');
    assert.equal(unloading(dom), true);
    edit(dom, 'zen_show_toc', true, 'change');
    assert.equal(unloading(dom), false);
    dom.window.close();
});
test('collapsed fields remain successful controls and native disclosure works', () => {
    const dom = boot();
    const doc = dom.window.document;
    doc.querySelector('#zen-footer').open = false;
    doc.querySelector('#zen-code').open = false;
    const data = new dom.window.FormData(doc.querySelector('form'));
    assert.equal(data.has('zen_footer_text'), true);
    assert.equal(data.has('zen_code_head'), true);
    assert.equal(data.has('zen_category_ids[]'), false);
    assert.equal(data.has('zen_content_width'), false);
    assert.equal(doc.querySelector('#zen-layout, #zen_content_width'), null);
    assert.equal(doc.querySelector('#zen_category_ids, .zen-category-choices, #zen-categories-help'), null);
    const headHelp = doc.querySelector('#zen_code_head').nextElementSibling;
    assert.equal(headHelp.textContent, '在 </head> 前输出');
    assert.equal(headHelp.innerHTML, '在 &lt;/head&gt; 前输出');
    doc.querySelector('#zen-code').open = true;
    assert.equal(new dom.window.FormData(doc.querySelector('form')).has('zen_code_head'), true);
    assert.equal(unloading(dom), false);
    dom.window.close();
});
test('presets update the single submitted width while custom selection alone stays clean', () => {
    const dom = boot();
    const doc = dom.window.document;
    const width = doc.getElementById('zen_reading_width');
    const preset = doc.getElementById('zen_reading_width_preset');
    const initial = width.value;
    const alternate = Array.from(preset.options).find(option => option.value !== initial && option.value !== 'custom').value;
    edit(dom, 'zen_reading_width_preset', 'custom', 'change');
    assert.equal(unloading(dom), false);
    edit(dom, 'zen_reading_width_preset', alternate, 'change');
    assert.equal(width.value, alternate);
    assert.deepEqual(new dom.window.FormData(doc.querySelector('form')).getAll('zen_reading_width'), [alternate]);
    assert.equal(unloading(dom), true);
    edit(dom, 'zen_reading_width', '731');
    assert.equal(preset.value, 'custom');
    edit(dom, 'zen_reading_width', initial);
    assert.equal(preset.value, initial);
    assert.equal(unloading(dom), false);
    dom.window.close();
});
test('a native preset selection snapshots the form only after applying the width', () => {
    const snapshots = [];
    const dom = boot(window => {
        window.FormData = class extends window.FormData {
            constructor(...args) {
                super(...args);
                snapshots.push(this.get('zen_reading_width'));
            }
        };
    });
    const preset = dom.window.document.getElementById('zen_reading_width_preset');
    const next = Array.from(preset.options).find(option => option.value !== preset.value && option.value !== 'custom').value;
    snapshots.length = 0;
    edit(dom, preset.id, next, 'input');
    edit(dom, preset.id, next, 'change');
    assert.deepEqual(snapshots, [next]);
    dom.window.close();
});
test('native validation opens collapsed invalid controls before submission', () => {
    const dom = boot();
    const doc = dom.window.document;
    doc.getElementById('zen-reading').open = false;
    edit(dom, 'zen_reading_width', '599');
    assert.equal(doc.getElementById('zen-reading').open, false);
    assert.equal(doc.querySelector('form').checkValidity(), false);
    assert.equal(doc.getElementById('zen-reading').open, true);
    assert.equal(unloading(dom), true);
    edit(dom, 'zen_reading_width', '800');
    edit(dom, 'zen_footer_text', 'new text');
    doc.querySelector('form').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    assert.equal(unloading(dom), false);
    dom.window.close();
});
test('empty numeric settings are invalid and reveal their collapsed ancestors', () => {
    const dom = boot();
    const doc = dom.window.document;
    for (const id of ['zen_reading_width', 'zen_excerpt_length']) {
        doc.getElementById(id === 'zen_reading_width' ? 'zen-reading' : 'zen-list').open = false;
        const control = doc.getElementById(id);
        const original = control.value;
        edit(dom, id, '');
        assert.equal(control.checkValidity(), false, id);
        for (let parent = control.parentElement; parent; parent = parent.parentElement) {
            if (parent.tagName === 'DETAILS') assert.equal(parent.open, true, id);
        }
        edit(dom, id, original);
    }
    dom.window.close();
});
test('a canceled submit retains the real dirty unload warning', () => {
    const dom = boot();
    edit(dom, 'zen_footer_text', 'unsaved');
    const event = new dom.window.Event('submit', { bubbles: true, cancelable: true });
    dom.window.document.querySelector('form').addEventListener('submit', e => e.preventDefault());
    dom.window.document.querySelector('form').dispatchEvent(event);
    assert.equal(unloading(dom), true);
    dom.window.close();
});
test('a submit that leaves the editor open cannot silence later unload warnings', () => {
    const dom = boot();
    const { document, Event } = dom.window;
    edit(dom, 'zen_footer_text', 'unsaved');
    const save = () => document.querySelector('#zen-options-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    save();
    assert.equal(unloading(dom), false, 'allow the actual form navigation');
    assert.equal(unloading(dom), true, 'one submit only exempts one unload');
    save();
    edit(dom, 'zen_footer_text', 'edited after submit');
    assert.equal(unloading(dom), true, 'new edits revoke an unused exemption');
    save();
    dom.window.dispatchEvent(new Event('pageshow'));
    assert.equal(unloading(dom), true, 'returning to the editor revokes an unused exemption');
    dom.window.close();
});
for (const outcome of ['confirmed', 'declined', 'canceled later']) {
    test(`dirty reset ${outcome} preserves the correct unload warning`, () => {
        const dom = boot();
        edit(dom, 'zen_footer_text', 'unsaved');
        const reset = dom.window.document.getElementById('zen-reset-form');
        let confirmations = 0;
        dom.window.confirm = () => { confirmations++; return outcome !== 'declined'; };
        if (outcome === 'canceled later') {
            dom.window.document.addEventListener('submit', event => event.preventDefault());
        }
        const event = new dom.window.Event('submit', { bubbles: true, cancelable: true });
        reset.dispatchEvent(event);
        assert.equal(confirmations, 1);
        assert.equal(event.defaultPrevented, outcome !== 'confirmed');
        assert.equal(unloading(dom), outcome !== 'confirmed');
        dom.window.close();
    });
}

test('ordinary settings groups remember disclosure without storing raw code or dirty values', async () => {
    const key = 'zen-options-groups';
    const dom = boot(window => {
        window.sessionStorage.setItem(key, JSON.stringify({ 'zen-reading': false, 'zen-footer': false, 'zen-code': true }));
    });
    const { document } = dom.window;
    assert.equal(document.getElementById('zen-reading').open, false);
    assert.equal(document.getElementById('zen-footer').open, false);
    assert.equal(document.getElementById('zen-code').open, false, 'advanced code keeps its existing default');
    edit(dom, 'zen_code_head', '<script>privateValue()</script>');
    const footer = document.getElementById('zen-footer');
    footer.open = true;
    footer.dispatchEvent(new dom.window.Event('toggle'));
    await new Promise(resolve => setTimeout(resolve, 0));
    const saved = JSON.parse(dom.window.sessionStorage.getItem(key));
    assert.equal(saved['zen-footer'], true);
    assert.equal(saved['zen-reading'], false);
    assert.equal(Object.hasOwn(saved, 'zen-code'), false);
    assert.ok(Object.values(saved).every(value => typeof value === 'boolean'));
    assert.equal(unloading(dom), true, 'disclosure persistence must not mark edits saved');
    dom.window.close();
});

for (const state of ['invalid JSON', 'storage denied']) {
    test(`settings still work with ${state}`, () => {
        const dom = boot(window => {
            if (state === 'storage denied') {
                Object.defineProperty(window, 'sessionStorage', { get() { throw new Error('denied'); } });
            } else {
                window.sessionStorage.setItem('zen-options-groups', '{');
            }
        });
        edit(dom, 'zen_footer_text', 'unsaved');
        assert.equal(unloading(dom), true);
        const footer = dom.window.document.getElementById('zen-footer');
        footer.open = false;
        assert.doesNotThrow(() => footer.dispatchEvent(new dom.window.Event('toggle')));
        dom.window.close();
    });
}

test('Ctrl or Command S uses the native validated settings submit without catching unrelated shortcuts', () => {
    const dom = boot();
    const { document, KeyboardEvent } = dom.window;
    const form = document.getElementById('zen-options-form');
    const save = document.getElementById('zen_save_top');
    const submissions = [];
    form.requestSubmit = button => submissions.push(button);
    for (const modifier of ['ctrlKey', 'metaKey']) {
        const event = new KeyboardEvent('keydown', { key: 's', [modifier]: true, bubbles: true, cancelable: true });
        document.dispatchEvent(event);
        assert.equal(event.defaultPrevented, true);
    }
    assert.deepEqual(submissions, [save, save]);
    for (const options of [{key:'s'}, {key:'p',ctrlKey:true}, {key:'s',ctrlKey:true,shiftKey:true}, {key:'s',metaKey:true,altKey:true}]) {
        const event = new KeyboardEvent('keydown', {...options,bubbles:true,cancelable:true});
        document.dispatchEvent(event);
        assert.equal(event.defaultPrevented, false);
    }
    const repeat = new KeyboardEvent('keydown', { key:'s',ctrlKey:true,repeat:true,bubbles:true,cancelable:true });
    document.dispatchEvent(repeat);
    assert.equal(repeat.defaultPrevented, true);
    const canceled = new KeyboardEvent('keydown', { key:'s',ctrlKey:true,bubbles:true,cancelable:true });
    canceled.preventDefault();
    document.dispatchEvent(canceled);
    assert.equal(submissions.length, 2);
    dom.window.close();
});

test('native code editors initialize on disclosure once, sync raw text and dirty state, and retain keyboard exits', () => {
    const instances = [];
    const dom = boot(window => {
        window.zenAdmin = { codeEditor: { codemirror: { mode: 'htmlmixed' } } };
        window.wp = { codeEditor: { initialize(textarea, settings) {
            const callbacks = {};
            const cm = { value: textarea.value, refreshes: 0,
                on(event, callback) { callbacks[event] = callback; },
                save() { textarea.value = cm.value; },
                refresh() { cm.refreshes++; },
                focus() { cm.focused = true; }
            };
            instances.push({textarea,settings,cm,callbacks});
            return { codemirror: cm };
        } } };
    });
    const { document, Event } = dom.window;
    assert.equal(instances.length, 0, 'collapsed code fields stay plain until opened');
    const section = document.getElementById('zen-code');
    section.open = true;
    section.dispatchEvent(new Event('toggle'));
    assert.equal(instances.length, 3);
    assert.equal(unloading(dom), false);
    const editor = instances[0];
    assert.equal(editor.settings.codemirror.screenReaderLabel, '头部代码');
    editor.cm.value = '<script>window.raw = "C:\\\\fixture";</script>';
    editor.callbacks.change();
    assert.equal(editor.textarea.value, editor.cm.value);
    assert.equal(new dom.window.FormData(document.getElementById('zen-options-form')).get('zen_code_head'), editor.cm.value);
    assert.equal(unloading(dom), true);
    editor.settings.onTabNext();
    assert.equal(instances[1].cm.focused, true);
    instances[2].settings.onTabNext();
    assert.equal(document.activeElement.id, 'submit');
    editor.settings.onTabPrevious();
    assert.equal(document.activeElement.tagName, 'SUMMARY');
    section.open = false;
    section.dispatchEvent(new Event('toggle'));
    section.open = true;
    section.dispatchEvent(new Event('toggle'));
    assert.equal(instances.length, 3, 'reopening must not create duplicate editors');
    dom.window.close();
});

test('CodeMirror keyboard save honors repeat, composition, and the native submit lifecycle', async () => {
    // Use the real Core-bundled keyboard engine; jsdom only needs geometry doubled.
    const core = process.env.ZEN_WP_CORE || '/opt/test-tools/wordpress/clean-core';
    const dom = boot(window => {
        window.Range.prototype.getBoundingClientRect = () => ({ left: 0, right: 0, top: 0, bottom: 0 });
        window.Range.prototype.getClientRects = () => [];
        window.eval(fs.readFileSync(path.join(core, 'wp-includes/js/codemirror/codemirror.min.js'), 'utf8'));
        window.zenAdmin = { codeEditor: { codemirror: { mode: 'htmlmixed' } } };
        window.wp.codeEditor = { initialize(textarea, settings) {
            return { codemirror: window.wp.CodeMirror.fromTextArea(textarea, settings.codemirror) };
        } };
    });
    try {
        const { document, KeyboardEvent, SubmitEvent, Event } = dom.window;
        const form = document.getElementById('zen-options-form');
        const section = document.getElementById('zen-code');
        section.open = true;
        section.dispatchEvent(new Event('toggle'));
        const cm = section.querySelector('.CodeMirror').CodeMirror;
        let submissions = 0;
        form.requestSubmit = submitter => {
            ++submissions;
            form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter }));
        };
        cm.setValue('<script>window.literal = "C:\\\\fixture";</script>');
        const key = options => cm.getInputField().dispatchEvent(new KeyboardEvent('keydown', {
            key: 's', keyCode: 83, which: 83, ctrlKey: true, bubbles: true, cancelable: true, ...options,
        }));
        key();
        assert.equal(submissions, 1, 'one physical shortcut submits once');
        assert.equal(unloading(dom), false, 'the CodeMirror submit keeps its native unload exemption');
        key({ repeat: true });
        assert.equal(submissions, 1, 'holding Ctrl S must not repeatedly submit from CodeMirror');
        key({ isComposing: true, keyCode: 229, which: 229 });
        assert.equal(submissions, 1, 'IME composition must not submit');
        assert.equal(unloading(dom), true, 'later non-submitting keyboard activity must leave dirty protection intact');
        assert.equal(new dom.window.FormData(form).get('zen_code_head'), cm.getValue());
    } finally {
        await new Promise(resolve => setTimeout(resolve, 0)); // Drain native disclosure toggles before closing jsdom.
        dom.window.close();
    }
});

test('native code-editor opt-out and missing resources preserve plain-text saving', () => {
    for (const reason of ['localized false', 'missing API']) {
        const dom = boot(window => {
            window.zenAdmin = { codeEditor: reason === 'localized false' ? '' : { codemirror: {} } };
            if (reason === 'localized false') window.wp = { codeEditor: { initialize() { throw new Error('editor opt-out was ignored'); } } };
        });
        const { document, Event, KeyboardEvent } = dom.window;
        const section = document.getElementById('zen-code');
        section.open = true;
        section.dispatchEvent(new Event('toggle'));
        const field = document.getElementById('zen_code_head');
        const code = '<script>window.literal = "<>&\\\\fixture";</script>';
        edit(dom, field.id, code);
        assert.equal(field.style.display, '', reason + ' keeps a visible native textarea');
        assert.equal(document.querySelector('.CodeMirror'), null);
        assert.equal(unloading(dom), true);
        const form = document.getElementById('zen-options-form');
        const submissions = [];
        form.requestSubmit = submitter => {
            submissions.push(new dom.window.FormData(form).get(field.name));
            form.dispatchEvent(new dom.window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter }));
        };
        field.focus();
        field.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true, bubbles: true, cancelable: true }));
        assert.deepEqual(submissions, [code], reason + ' shares the native save path and literal bytes');
        assert.equal(unloading(dom), false);
        dom.window.close();
    }
});

test('settings initialization does not check for updates', async () => {
    let requests = 0;
    const dom = boot(window => {
        window.fetch = async () => { requests++; throw new Error('unexpected admin request'); };
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(requests, 0);
    assert.equal(dom.window.document.getElementById('zen-update-status'), null);
    assert.equal(dom.window.document.getElementById('zen-update-link'), null);
    dom.window.close();
});

test('responsive admin CSS scopes controls and respects native adminbar offsets', () => {
    const css = asset('assets/css/admin.css');
    assert.match(css, /\.zen-options[^}]*max-width:\s*1120px/);
    assert.match(css, /position:\s*sticky/);
    assert.match(css, /\.admin-bar[^}]*--zen-adminbar-offset:\s*32px/);
    assert.match(css, /782px[\s\S]*--zen-adminbar-offset:\s*46px/);
    assert.match(css, /600px[\s\S]*--zen-adminbar-offset:\s*0px/);
    assert.match(css, /textarea[^}]*width:\s*100%[^}]*max-width:\s*800px/);
    assert.match(css, /\.zen-save-bar\s+\[form="zen-reset-form"\][^}]*margin-inline-start:\s*auto/);
    assert.match(css, /600px[\s\S]*#zen-save-state[^}]*order:\s*1[^}]*flex-basis:\s*100%/);
    assert.doesNotMatch(css, /zen-category-choices/);
    assert.doesNotMatch(css, /zen-options-subgroup/);
});
