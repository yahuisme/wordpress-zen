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

for (const listenerApi of ['addEventListener', 'addListener']) {
    test(`footer breakpoint ordering supports ${listenerApi} and preserves focus and later initialization`, async t => {
        let changed;
        const media = { matches: false };
        if (listenerApi === 'addEventListener') media.addEventListener = (type, listener) => { changed = listener; };
        else media.addListener = listener => { changed = listener; };
        const window = await page(t, `
            <button id="theme-toggle" hidden><i></i></button>
            <footer class="zen-site-footer"><nav class="zen-footer-navigation"><a id="footer-nav" href="#nav">Nav</a></nav>
                <div class="zen-footer-links"><a id="footer-custom" href="#custom">Custom</a></div></footer>`, window => {
            window.matchMedia = query => query === '(min-width: 640px)' ? media : { matches: false, addListener() {} };
            window.CSS = { supports: () => false };
        });
        const { document } = window;
        const nav = document.querySelector('.zen-footer-navigation');
        const links = document.querySelector('.zen-footer-links');
        assert.equal(nav.nextElementSibling, links, 'narrow fallback order stays navigation first');
        assert.equal(document.getElementById('theme-toggle').hidden, false, 'media listener compatibility must not abort basic controls');
        for (const id of ['footer-nav', 'footer-custom']) {
            for (const matches of [true, false]) {
                document.getElementById(id).focus();
                media.matches = matches;
                changed(media);
                assert.equal(document.activeElement.id, id, 'the focused subtree must never be detached');
                assert.equal(matches ? links.nextElementSibling : nav.nextElementSibling, matches ? nav : links);
            }
        }
    });
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
        assert.equal(copy.getAttribute('aria-label'), '复制代码');
        assert.equal(document.querySelector('.zen-code-block [role="status"]').textContent, outcome === 'success' ? '代码已复制' : '复制失败，请手动复制');
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
    assert.equal(window.document.querySelector('.zen-code-block [role="status"]').textContent, '');
});

test('audio progress does not rewrite unchanged time, percentage or availability', async t => {
    const window = await page(t, '<audio controls></audio>', w => {
        Object.defineProperties(w.document.querySelector('audio'), {
            duration: { value: 120, writable: true }, currentTime: { value: 30, writable: true },
        });
    });
    const { document } = window;
    const audio = document.querySelector('audio');
    const slider = document.querySelector('[role="slider"]');
    const time = document.querySelector('.zen-audio-time');
    const observer = new window.MutationObserver(() => {});
    observer.observe(slider, { attributes: true });
    const tick = (value, type = 'timeupdate') => {
        audio.currentTime = value;
        audio.dispatchEvent(new window.Event(type));
        return observer.takeRecords().map(record => record.attributeName).sort();
    };
    assert.deepEqual(tick(30.05), [], 'unchanged rounded values must not rewrite ARIA');
    assert.equal(time.innerText, '0:30 / 2:00');
    assert.deepEqual(tick(31), ['aria-valuenow', 'aria-valuetext']);
    assert.equal(time.innerText, '0:31 / 2:00');
    audio.duration = Infinity;
    assert.deepEqual(tick(31, 'durationchange'), ['aria-disabled', 'aria-valuenow', 'aria-valuetext', 'tabindex']);
    assert.deepEqual(tick(31.1), [], 'unavailable progress keeps the same accessible state');
    observer.disconnect();
});

test('paused audio reflects corrected duration without waiting for playback', async t => {
    const window = await page(t, '<audio controls></audio>', window => {
        Object.defineProperty(window.document.querySelector('audio'), 'duration', { value: NaN, writable: true });
    });
    const { document } = window;
    const audio = document.querySelector('audio');
    const time = document.querySelector('.zen-audio-time');
    const slider = document.querySelector('.zen-audio-progress-container');
    assert.equal(slider.tabIndex, -1);
    assert.equal(slider.getAttribute('aria-disabled'), 'true');
    audio.duration = 120;
    audio.dispatchEvent(new window.Event('loadedmetadata'));
    assert.equal(time.innerText, '0:00 / 2:00', 'positive control reaches the existing metadata handler');
    assert.equal(slider.tabIndex, 0);
    assert.equal(slider.hasAttribute('aria-disabled'), false);
    assert.equal(slider.getAttribute('aria-valuetext'), '0:00 / 2:00');
    audio.duration = 180;
    audio.dispatchEvent(new window.Event('durationchange'));
    assert.equal(time.innerText, '0:00 / 3:00', 'a paused player must not keep the old duration');
    slider.focus();
    assert.equal(document.activeElement, slider);
    audio.duration = Infinity;
    audio.dispatchEvent(new window.Event('durationchange'));
    assert.equal(time.innerText, '0:00 / --:--', 'live or unavailable duration must replace an obsolete finite duration');
    assert.equal(slider.tabIndex, -1);
    assert.equal(slider.getAttribute('aria-valuetext'), '音频暂不可定位');
    assert.equal(document.activeElement, document.querySelector('.zen-audio-btn'));
});

test('short audio supports incremental keyboard seeks and ignores a zero-width seek track', async t => {
    const window = await page(t, '<audio controls></audio>', w => {
        Object.defineProperty(w.document.querySelector('audio'), 'duration', { value: 2 });
    });
    const audio = window.document.querySelector('audio');
    const slider = window.document.querySelector('[role="slider"]');
    const press = key => slider.dispatchEvent(new window.KeyboardEvent('keydown', { key, cancelable: true }));
    assert.equal(press('End'), false);
    assert.equal(audio.currentTime, 2);
    assert.equal(press('Home'), false);
    assert.equal(audio.currentTime, 0);
    assert.equal(press('ArrowRight'), false);
    assert.ok(audio.currentTime > 0 && audio.currentTime < 2, 'one arrow key must not skip the entire clip');
    const seek = audio.currentTime;
    slider.getBoundingClientRect = () => ({ left: 10, width: 0 });
    slider.dispatchEvent(new window.MouseEvent('click', { clientX: 11 }));
    assert.equal(audio.currentTime, seek, 'a track without a usable width cannot seek');
    assert.equal(press('ArrowLeft'), false);
    assert.equal(audio.currentTime, 0);
});

for (const activation of ['click', 'touchstart']) test(`successful native cancel restores reply focus after ${activation}`, async t => {
    const window = await page(t, `<section id="comments"><a class="comment-reply-link" data-commentid="42" href="?replytocom=42">Reply</a>
        <a id="cancel-comment-reply-link" href="#respond">Cancel</a><input type="hidden" id="comment_parent" value="0"></section>`, w => {
        const doc = w.document, parent = doc.getElementById('comment_parent');
        const reply = doc.querySelector('.comment-reply-link'), cancel = doc.getElementById('cancel-comment-reply-link');
        reply.addEventListener(activation, event => { parent.value = '42'; event.preventDefault(); });
        cancel.addEventListener(activation, event => { if (parent.value !== '0') { parent.value = '0'; event.preventDefault(); } });
    });
    const doc = window.document, reply = doc.querySelector('.comment-reply-link'), cancel = doc.getElementById('cancel-comment-reply-link');
    const activate = element => element.dispatchEvent(new window.Event(activation, { bubbles: true, cancelable: true }));
    assert.equal(activate(reply), false);
    cancel.focus();
    assert.equal(activate(cancel), false);
    assert.equal(doc.getElementById('comment_parent').value, '0');
    assert.equal(doc.activeElement, reply);
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
