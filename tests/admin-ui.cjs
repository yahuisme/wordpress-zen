const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');
const root = path.resolve(__dirname, '..');
const html = execFileSync('php', [path.join(__dirname, 'admin-options.php'), '--html'], { encoding: 'utf8' });
function asset(file) { const target = path.join(root, file); return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : ''; }
function boot() {
    const dom = new JSDOM(html, { url: 'https://example.test/wp-admin/admin.php?page=zen-options', runScripts: 'outside-only' });
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
    assert.equal(data.getAll('zen_category_ids[]').join(), '');
    doc.querySelector('#zen-code').open = true;
    assert.equal(new dom.window.FormData(doc.querySelector('form')).has('zen_code_head'), true);
    assert.equal(unloading(dom), false);
    dom.window.close();
});
test('presets update the single submitted width while custom selection alone stays clean', () => {
    const dom = boot();
    const doc = dom.window.document;
    edit(dom, 'zen_reading_width_preset', 'custom', 'change');
    assert.equal(unloading(dom), false);
    edit(dom, 'zen_reading_width_preset', '800', 'change');
    assert.equal(doc.getElementById('zen_reading_width').value, '800');
    assert.deepEqual(Array.from(new dom.window.FormData(doc.querySelector('form')).getAll('zen_reading_width')), ['800']);
    assert.equal(unloading(dom), true);
    edit(dom, 'zen_reading_width', '731');
    assert.equal(doc.getElementById('zen_reading_width_preset').value, 'custom');
    edit(dom, 'zen_reading_width', '720');
    assert.equal(doc.getElementById('zen_reading_width_preset').value, '720');
    assert.equal(unloading(dom), false);
    dom.window.close();
});
test('native validation opens collapsed invalid controls before submission', () => {
    const dom = boot();
    const doc = dom.window.document;
    edit(dom, 'zen_content_width', '599');
    assert.equal(doc.getElementById('zen-layout').open, false);
    assert.equal(doc.querySelector('form').checkValidity(), false);
    assert.equal(doc.getElementById('zen-layout').open, true);
    assert.equal(unloading(dom), true);
    edit(dom, 'zen_content_width', '900');
    edit(dom, 'zen_footer_text', 'new text');
    doc.querySelector('form').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    assert.equal(unloading(dom), false);
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
test('responsive admin CSS scopes controls and respects native adminbar offsets', () => {
    const css = asset('assets/css/admin.css');
    assert.match(css, /\.zen-options[^}]*max-width:\s*1120px/);
    assert.match(css, /position:\s*sticky/);
    assert.match(css, /\.admin-bar[^}]*--zen-adminbar-offset:\s*32px/);
    assert.match(css, /782px[\s\S]*--zen-adminbar-offset:\s*46px/);
    assert.match(css, /600px[\s\S]*--zen-adminbar-offset:\s*0px/);
    assert.match(css, /textarea[^}]*width:\s*100%[^}]*max-width:\s*800px/);
});
