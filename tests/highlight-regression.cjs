// Run: NODE_PATH=/opt/test-tools/node/common/node_modules node --test tests/highlight-regression.cjs
// Executes the shipped main.js and Highlight.js in jsdom; no browser or WordPress writes.
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const root = join(__dirname, '..');
const main = readFileSync(join(root, 'js/main.js'), 'utf8');
const highlight = readFileSync(join(root, 'assets/js/highlight.min.js'), 'utf8');

async function page(t, { positions = [20, 850, 3000, 6000], observer = true, enabled = true, prepare = () => {} } = {}) {
    const dom = new JSDOM('<!doctype html><div class="entry-content"></div>', {
        url: 'https://zen.test/', runScripts: 'outside-only', pretendToBeVisual: true,
    });
    const { window } = dom;
    t.after(() => window.close());
    await new Promise(resolve => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    const errors = [];
    window.addEventListener('error', event => errors.push(event.error));
    const blocks = positions.map(top => {
        const block = window.document.createElement('pre');
        block.className = 'language-javascript';
        const code = window.document.createElement('code');
        code.textContent = 'const message = "<safe>";\nconsole.log(message);';
        block.appendChild(code);
        block.getBoundingClientRect = code.getBoundingClientRect = () => ({ top, bottom: top + 100 });
        window.document.querySelector('.entry-content').appendChild(block);
        return block;
    });
    const observers = [];
    if (observer) {
        window.IntersectionObserver = class {
            constructor(callback, options) {
                this.callback = callback;
                this.options = options;
                this.targets = new Set();
                this.unobserved = [];
                this.disconnected = false;
                observers.push(this);
            }
            observe(target) { this.targets.add(target); }
            unobserve(target) { this.unobserved.push(target); this.targets.delete(target); }
            disconnect() { this.disconnected = true; this.targets.clear(); }
            emit(entries) { this.callback(entries, this); }
        };
    }
    const calls = [];
    if (enabled) {
        window.eval(highlight);
        const highlightElement = window.hljs.highlightElement;
        window.hljs.highlightElement = code => { calls.push(code); highlightElement(code); };
    }
    const copied = [];
    Object.defineProperty(window, 'isSecureContext', { value: true });
    Object.defineProperty(window.navigator, 'clipboard', { value: {
        writeText: async text => { copied.push(text); },
    } });
    prepare(window, blocks);
    window.eval(main);
    window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    assert.deepEqual(errors, [], 'main.js must initialize without runtime errors');
    return { window, blocks, codes: blocks.map(block => block.querySelector('code')), calls, observers, copied };
}

test('without IntersectionObserver all code still highlights', async t => {
    const { calls, codes, observers } = await page(t, { observer: false });
    assert.deepEqual(calls, codes);
    assert.equal(observers.length, 0);
    codes.forEach(code => assert.ok(code.querySelector('.hljs-keyword')));
});

for (const enabled of [true, false]) {
    test(`copy preserves literal offscreen code with highlighting ${enabled ? 'enabled' : 'disabled'}`, async t => {
        const { blocks, codes, observers, copied } = await page(t, { enabled });
        const text = 'const message = "<safe>";\nconsole.log(message);';
        for (const block of blocks) {
            const button = block.querySelector('.zen-code-copy');
            assert.equal(block.querySelectorAll('.zen-code-copy').length, 1);
            assert.equal(button.type, 'button');
            assert.equal(button.getAttribute('aria-label'), '复制代码');
            assert.equal(button.querySelector('i').getAttribute('aria-hidden'), 'true');
            button.click();
            await Promise.resolve();
            assert.equal(button.getAttribute('aria-label'), '已复制');
        }
        assert.deepEqual(copied, blocks.map(() => text));
        codes.forEach(code => {
            assert.equal(code.textContent, text);
            assert.ok(code.classList.contains('language-javascript'), 'inherit language from pre');
        });
        if (!enabled) {
            assert.equal(observers.length, 0);
            codes.forEach(code => assert.equal(code.childElementCount, 0));
        }
    });
}

test('does not create an observer without pending code', async t => {
    for (const positions of [[], [20, 850]]) {
        const { observers, calls } = await page(t, { positions });
        assert.equal(observers.length, 0);
        assert.equal(calls.length, positions.length);
    }
});

test('skips pre-highlighted code and bare pre while handling standalone Gutenberg code', async t => {
    const { window, calls, codes, observers } = await page(t, {
        positions: [3000, 4000, 5000],
        prepare(window, blocks) {
            blocks[0].querySelector('code').dataset.highlighted = 'yes';
            blocks[1].querySelector('code').remove();
            blocks[1].textContent = 'plain pre';
            blocks[2].classList.add('wp-block-code');
            window.document.body.appendChild(blocks[2]);
        },
    });
    assert.equal(calls.length, 0);
    assert.deepEqual([...observers[0].targets], [codes[2]]);
    assert.equal(window.document.querySelectorAll('.zen-code-copy').length, 2);
    codes[2].dataset.highlighted = 'yes'; // Another plugin may highlight while queued.
    observers[0].emit([{ target: codes[2], isIntersecting: true }]);
    assert.equal(calls.length, 0);
    assert.equal(observers[0].disconnected, true);
});

test('scroll-restored pages defer distant code above as well as below the viewport', async t => {
    const { codes, calls, observers } = await page(t, { positions: [-2000, -150, 50, 900, 1200] });
    assert.deepEqual(calls, codes.slice(1, 4));
    assert.deepEqual([...observers[0].targets], [codes[0], codes[4]]);
});

test('releases each deferred target once and disconnects after the last highlight', async t => {
    const { codes, calls, observers } = await page(t);
    const observer = observers[0];
    observer.emit([{ target: codes[2], isIntersecting: false }]);
    assert.equal(calls.length, 2, 'nonintersecting notifications must not parse code');
    observer.emit([{ target: codes[2], isIntersecting: true }]);
    assert.equal(calls.length, 3);
    assert.ok(codes[2].querySelector('.hljs-keyword'));
    assert.deepEqual(observer.unobserved, [codes[2]]);
    assert.equal(observer.disconnected, false, 'other targets still need observation');
    observer.emit([{ target: codes[2], isIntersecting: true }]);
    assert.equal(calls.length, 3, 'duplicate notifications must not parse twice');
    observer.emit([{ target: codes[3], isIntersecting: true }]);
    assert.equal(calls.length, 4);
    assert.deepEqual(observer.unobserved, codes.slice(2));
    assert.equal(observer.disconnected, true);
    assert.equal(observer.targets.size, 0);
});

test('highlights visible and near-viewport code immediately, not distant blocks', async t => {
    const { codes, calls, observers } = await page(t);
    assert.deepEqual(calls, codes.slice(0, 2), 'offscreen parsing must wait for intersection');
    assert.ok(codes[0].querySelector('.hljs-keyword'), 'execute the real bundled highlighter');
    assert.equal(codes[2].dataset.highlighted, undefined);
    assert.equal(observers.length, 1, 'share one observer for deferred code');
    assert.equal(observers[0].targets.size, 2);
    const margins = observers[0].options.rootMargin.split(/\s+/);
    assert.ok(margins.every(value => /^\d+px$/.test(value) && Number.parseInt(value) <= 300), 'bounded pixel lookahead');
    assert.ok(margins.some(value => Number.parseInt(value) > 0), 'pre-highlight before entering viewport');
});
