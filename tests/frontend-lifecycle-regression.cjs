// Native clipboard selection and media events are explicit jsdom boundaries.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const main = fs.readFileSync(path.join(__dirname, '../theme/zen/js/main.js'), 'utf8');
const firstPaint = fs.readFileSync(path.join(__dirname, '../theme/zen/header.php'), 'utf8')
    .match(/<script\b[^>]*>([\s\S]*?)<\/script>/)[1];

async function page(t, markup, prepare = () => {}) {
    const dom = new JSDOM('<!doctype html>' + markup, {
        url: 'http://zen.test/', runScripts: 'outside-only', pretendToBeVisual: true,
    });
    const { window } = dom;
    t.after(() => window.close());
    await new Promise(resolve => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    window.matchMedia = () => ({ matches: false, addEventListener() {} });
    prepare(window);
    window.eval(main);
    return window;
}

for (const outcome of ['success', 'failure', 'throw']) {
    test(`fallback code copy ${outcome} preserves the keyboard position`, async t => {
        const commands = [];
        const window = await page(t, '<article class="entry-content"><pre><code>const answer = 42;</code></pre></article>', window => {
            // jsdom implements selection but not the browser's selection-focus behavior.
            const select = window.HTMLTextAreaElement.prototype.select;
            window.HTMLTextAreaElement.prototype.select = function () { this.focus(); select.call(this); };
            window.document.execCommand = command => {
                commands.push([command, window.document.activeElement.value]);
                if (outcome === 'throw') throw new Error('clipboard denied');
                return outcome === 'success';
            };
        });
        const { document } = window;
        const copy = document.querySelector('.zen-code-copy');
        const focusOptions = [];
        const focus = copy.focus.bind(copy);
        copy.focus = options => { focusOptions.push(options?.preventScroll); focus(options); };
        copy.focus();
        focusOptions.length = 0;
        copy.click();
        await new Promise(resolve => setImmediate(resolve));
        assert.deepEqual(commands, [['copy', 'const answer = 42;']], 'real fallback reached the native clipboard boundary');
        assert.equal(document.querySelector('textarea'), null, 'temporary control is removed on every outcome');
        assert.equal(document.activeElement, copy, 'Tab must continue from the copy button, not body');
        assert.deepEqual(focusOptions, [true], 'focus restoration must not scroll the article');
        assert.equal(copy.textContent, outcome === 'success' ? '已复制' : '复制失败');
    });
}

for (const clipboardFailure of ['reject', 'throw']) {
    test(`secure clipboard ${clipboardFailure} retries the native copy fallback without losing focus`, async t => {
        const calls = [];
        const window = await page(t, '<article class="entry-content"><pre><code>const answer = 42;</code></pre></article>', window => {
            Object.defineProperty(window, 'isSecureContext', { value: true });
            Object.defineProperty(window.navigator, 'clipboard', { value: { writeText(text) {
                calls.push(['clipboard', text]);
                if (clipboardFailure === 'throw') throw new window.DOMException('Denied', 'NotAllowedError');
                return Promise.reject(new window.DOMException('Denied', 'NotAllowedError'));
            } } });
            window.document.execCommand = command => {
                calls.push([command, window.document.querySelector('textarea').value]);
                return true;
            };
        });
        const copy = window.document.querySelector('.zen-code-copy');
        copy.focus(); copy.click();
        await new Promise(resolve => setImmediate(resolve));
        assert.deepEqual(calls, [['clipboard', 'const answer = 42;'], ['copy', 'const answer = 42;']]);
        assert.equal(copy.textContent, '已复制');
        assert.equal(window.document.querySelector('textarea'), null);
        assert.equal(window.document.activeElement, copy);
    });
}

test('rapid code copies keep the newest result and feedback timeout', async t => {
    const attempts = [];
    const timers = new Map();
    let token = 0;
    const window = await page(t, '<article class="entry-content"><pre><code>copy me</code></pre></article>', window => {
        Object.defineProperty(window, 'isSecureContext', { value: true });
        Object.defineProperty(window.navigator, 'clipboard', { value: { writeText: () => new Promise((resolve, reject) => attempts.push({ resolve, reject })) } });
        window.document.execCommand = () => false;
        window.setTimeout = callback => { timers.set(++token, callback); return token; };
        window.clearTimeout = id => timers.delete(id);
    });
    const copy = window.document.querySelector('.zen-code-copy');
    const settle = () => new Promise(resolve => setImmediate(resolve));
    copy.click(); attempts[0].resolve(); await settle();
    assert.equal(copy.textContent, '已复制', 'positive control reaches clipboard success');
    copy.click(); attempts[1].reject(new Error('denied')); await settle();
    assert.equal(copy.textContent, '复制失败');
    assert.equal(copy.classList.contains('is-copied'), false, 'failure must not keep the previous success appearance');
    assert.equal(copy.querySelector('i').classList.contains('ph-check'), false);
    assert.equal(timers.size, 1, 'only the latest feedback may schedule a reset');
    copy.click(); copy.click();
    attempts[3].resolve(); await settle();
    attempts[2].reject(new Error('obsolete failure')); await settle();
    assert.equal(copy.textContent, '已复制', 'a late earlier copy must not overwrite the newest result');
    assert.equal(timers.size, 1);
    [...timers.values()][0]();
    assert.equal(copy.textContent, '复制');
    assert.equal(copy.getAttribute('aria-label'), '复制代码');
});

test('paused audio reflects corrected duration without waiting for playback', async t => {
    const window = await page(t, '<audio controls></audio>', window => {
        Object.defineProperty(window.document.querySelector('audio'), 'duration', { value: NaN, writable: true });
    });
    const { document } = window;
    const audio = document.querySelector('audio');
    const time = document.querySelector('.zen-audio-time');
    audio.duration = 120;
    audio.dispatchEvent(new window.Event('loadedmetadata'));
    assert.equal(time.innerText, '0:00 / 2:00', 'positive control reaches the existing metadata handler');
    audio.duration = 180;
    audio.dispatchEvent(new window.Event('durationchange'));
    assert.equal(time.innerText, '0:00 / 3:00', 'a paused player must not keep the old duration');
    audio.duration = Infinity;
    audio.dispatchEvent(new window.Event('durationchange'));
    assert.equal(time.innerText, '0:00 / --:--', 'live or unavailable duration must replace an obsolete finite duration');
});

for (const [label, defaultMode, storedMode, blocked, expectedMode, expectedDark] of [
    ['default light', 'light', null, false, 'light', false],
    ['default dark', 'dark', null, false, 'dark', true],
    ['system auto', 'auto', null, false, 'auto', true],
    ['stored light overrides auto', 'auto', 'light', false, 'light', false],
    ['stored dark overrides light', 'light', 'dark', false, 'dark', true],
    ['stored auto overrides light', 'light', 'auto', false, 'auto', true],
    ['invalid stored value', 'dark', 'invalid', false, 'dark', true],
    ['blocked storage', 'light', null, true, 'light', false],
]) {
    test(`theme first-paint and main initialization agree: ${label}`, async t => {
        const window = await page(t, '<button id="theme-toggle" hidden><i></i><span data-theme-toggle-label></span></button>', window => {
            window.zenSettings = { theme_mode_default: defaultMode };
            window.matchMedia = query => ({ matches: query === '(prefers-color-scheme: dark)', addEventListener() {} });
            if (storedMode !== null) window.localStorage.setItem('zen-theme-mode', storedMode);
            if (blocked) Object.defineProperty(window, 'localStorage', { get() { throw new window.DOMException('Denied', 'SecurityError'); } });
            // Supply only the PHP option boundary; execute the shipped first-paint script unchanged.
            window.eval(firstPaint.replace(/<\?php[\s\S]*?\?>/, defaultMode));
            assert.equal(window.document.documentElement.dataset.themeMode, expectedMode);
            assert.equal(window.document.documentElement.classList.contains('dark'), expectedDark);
        });
        const html = window.document.documentElement;
        assert.equal(html.dataset.themeMode, expectedMode);
        assert.equal(html.classList.contains('dark'), expectedDark);
        assert.equal(html.style.colorScheme, expectedDark ? 'dark' : 'light');
        assert.equal(window.document.getElementById('theme-toggle').hidden, false);
        if (blocked) {
            window.document.getElementById('theme-toggle').click();
            assert.equal(html.dataset.themeMode, 'dark', 'storage failure must not disable the current page toggle');
        }
    });
}

test('theme cycle persists and system changes only affect auto mode', async t => {
    let systemChanged;
    const scheme = { matches: false, addEventListener(type, listener) { systemChanged = listener; } };
    const window = await page(t, '<button id="theme-toggle"><i></i><span data-theme-toggle-label></span></button>', window => {
        window.matchMedia = query => query === '(prefers-color-scheme: dark)' ? scheme : { matches: false };
    });
    const html = window.document.documentElement;
    const toggle = window.document.getElementById('theme-toggle');
    for (const mode of ['light', 'dark', 'auto']) {
        toggle.click();
        assert.equal(html.dataset.themeMode, mode);
        assert.equal(window.localStorage.getItem('zen-theme-mode'), mode);
    }
    scheme.matches = true; systemChanged();
    assert.equal(html.classList.contains('dark'), true);
    toggle.click();
    assert.equal(html.dataset.themeMode, 'light');
    systemChanged();
    assert.equal(html.classList.contains('dark'), false, 'system changes must not override an explicit preference');
});
