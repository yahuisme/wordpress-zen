// Run: NODE_PATH=/opt/test-tools/node/common/node_modules node --test tests/interaction-regression.cjs
// Executes shipped main.js in jsdom; no browser, network or WordPress writes.
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const main = readFileSync(join(__dirname, '..', 'js/main.js'), 'utf8');

async function page(t, html, prepare = () => {}) {
    const dom = new JSDOM('<!doctype html>' + html, {
        url: 'https://zen.test/', runScripts: 'outside-only', pretendToBeVisual: true,
    });
    const { window } = dom;
    const { document } = window;
    t.after(() => window.close());
    await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    const errors = [];
    window.addEventListener('error', event => errors.push(event.error));
    t.after(() => assert.deepEqual(errors, [], 'no runtime errors'));
    window.zenSettings = { search_shortcut: 1 };
    // Flush animation/focus timers explicitly without wall-clock races.
    const timers = new Map();
    let nextTimer = 0;
    window.setTimeout = callback => { timers.set(++nextTimer, callback); return nextTimer; };
    window.clearTimeout = id => timers.delete(id);
    window.requestAnimationFrame = callback => window.setTimeout(callback);
    const flush = () => {
        const callbacks = [...timers.values()];
        timers.clear();
        callbacks.forEach(callback => callback());
    };
    const observers = [];
    window.IntersectionObserver = class {
        constructor(callback, options) {
            this.callback = callback;
            this.options = options;
            this.targets = [];
            observers.push(this);
        }
        observe(target) { this.targets.push(target); }
    };
    prepare(window);
    window.eval(main);
    document.dispatchEvent(new window.Event('DOMContentLoaded'));
    const press = (key, options = {}) => document.activeElement.dispatchEvent(
        new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...options })
    );
    return { window, document, observers, flush, press };
}

const searchMarkup = `
    <header>
        <button id="mobile-menu-btn" aria-expanded="false">Menu</button>
        <div id="mobile-menu" class="hidden" aria-hidden="true"><a href="/about">About</a></div>
        <button id="search-toggle" aria-expanded="false">Search</button>
    </header>
    <main><button id="outside">Outside</button></main>
    <div id="search-modal" class="hidden opacity-0">
        <button id="search-close">Close</button><input id="search-input"><button>Submit</button>
    </div>`;

test('search click closes the menu and restores its visible trigger', async t => {
    const { document, flush } = await page(t, searchMarkup);
    document.getElementById('mobile-menu-btn').click();
    const toggle = document.getElementById('search-toggle');
    toggle.focus();
    toggle.click();
    flush();
    assert.equal(document.getElementById('mobile-menu').classList.contains('hidden'), true);
    assert.equal(document.getElementById('mobile-menu').getAttribute('aria-hidden'), 'true');
    assert.equal(document.activeElement.id, 'search-input');
    document.getElementById('search-close').click();
    flush();
    assert.equal(document.activeElement, toggle);
    assert.equal(toggle.closest('[inert], [aria-hidden="true"]'), null);
});

test('search shortcut preserves focus outside the mobile menu', async t => {
    const { document, press, flush } = await page(t, searchMarkup);
    const outside = document.getElementById('outside');
    outside.focus();
    press('k', { ctrlKey: true });
    flush();
    assert.equal(document.activeElement.id, 'search-input');
    press('k', { ctrlKey: true });
    flush();
    assert.equal(document.activeElement, outside);
});

for (const modifier of ['ctrlKey', 'metaKey']) {
    test(`search ${modifier} returns focus to a visible control after closing the mobile menu`, async t => {
        const { document, press, flush } = await page(t, searchMarkup);
        const menu = document.getElementById('mobile-menu');
        const menuButton = document.getElementById('mobile-menu-btn');
        const modal = document.getElementById('search-modal');
        menuButton.click();
        assert.equal(document.activeElement, menu.querySelector('a'));
        press('k', { [modifier]: true });
        flush();
        assert.equal(document.activeElement.id, 'search-input');
        assert.equal(menu.classList.contains('hidden'), true, 'shortcut closes the menu immediately');
        press('Escape');
        flush();
        assert.equal(modal.classList.contains('hidden'), true);
        // jsdom permits hidden focus: assert the actual visible return target explicitly.
        assert.equal(document.activeElement, menuButton);
        assert.equal(document.activeElement.closest('.hidden, [inert], [aria-hidden="true"]'), null);
        assert.equal(menu.classList.contains('hidden'), true);
        assert.equal(menu.getAttribute('aria-hidden'), 'true');
        assert.equal(menuButton.getAttribute('aria-expanded'), 'false');
        assert.equal(menuButton.getAttribute('aria-label'), '打开菜单');
        assert.equal(document.body.style.overflow, '');
    });
}

test('audio initializes from metadata and playback already available before DOMContentLoaded', async t => {
    const { document } = await page(t, '<audio controls></audio>', window => {
        const audio = window.document.querySelector('audio');
        Object.defineProperties(audio, {
            duration: { value: 120 },
            currentTime: { value: 30, writable: true },
            paused: { value: false },
            ended: { value: false },
            readyState: { value: 1 },
        });
        audio.dispatchEvent(new window.Event('loadedmetadata'));
        audio.dispatchEvent(new window.Event('play'));
    });
    const slider = document.querySelector('[role="slider"]');
    assert.deepEqual({
        time: document.querySelector('.zen-audio-time').innerText,
        width: document.querySelector('.zen-audio-progress-bar').style.width,
        now: slider.getAttribute('aria-valuenow'),
        text: slider.getAttribute('aria-valuetext'),
        label: document.querySelector('.zen-audio-btn').getAttribute('aria-label'),
        playingIcon: !!document.querySelector('.zen-audio-btn .ph-pause'),
    }, { time: '0:30 / 2:00', width: '25%', now: '25', text: '25%', label: '暂停音频', playingIcon: true });
});

test('disabled TOC preserves heading IDs without a TOC observer or disabling article interactions', async t => {
    const { document, observers } = await page(t, `
        <article id="post-content" class="entry-content">
            <h2>One</h2><h3 id="existing">Two</h3><h2 id="existing">Three</h2>
            <pre><code>const value = 1;</code></pre><audio controls></audio>
        </article>
        <div id="reading-progress"></div><button id="back-to-top">Top</button>`);
    assert.deepEqual({
        ids: [...document.querySelectorAll('h2, h3')].map(heading => heading.id),
        observers: observers.length,
    }, { ids: ['', 'existing', 'existing'], observers: 0 });
    assert.ok(document.querySelector('.zen-code-copy'), 'code controls still initialize');
    assert.ok(document.querySelector('.zen-audio-player'), 'audio still initializes');
    assert.equal(document.getElementById('reading-progress').style.width, '0%');
    assert.equal(document.getElementById('back-to-top').disabled, true);
});

for (const navId of ['toc-nav', 'drawer-toc-nav']) {
    test(`enabled TOC still generates links and observes headings with ${navId}`, async t => {
        const { document, observers } = await page(t, `
            <nav id="${navId}"></nav>
            <article id="post-content"><h2>One</h2><h3 id="existing">Two</h3></article>
            <section id="comments"></section>`);
        const headings = [...document.querySelectorAll('h2, h3')];
        assert.deepEqual(headings.map(heading => heading.id), ['section-0', 'existing']);
        const links = [...document.querySelectorAll('.toc-link')];
        assert.deepEqual(links.map(link => link.getAttribute('href')), ['#section-0', '#existing', '#comments']);
        assert.equal(observers.length, 1);
        assert.deepEqual(observers[0].targets, [...headings, document.getElementById('comments')]);
        observers[0].callback([{ target: headings[1], isIntersecting: true }]);
        assert.equal(links[1].getAttribute('aria-current'), 'location');
    });
}

test('audio tolerates unavailable metadata and keeps later media events synchronized', async t => {
    const { window, document } = await page(t, '<audio controls></audio>', window => {
        Object.defineProperties(window.document.querySelector('audio'), {
            duration: { value: NaN, writable: true },
            paused: { value: true, writable: true },
            ended: { value: false, writable: true },
        });
    });
    const audio = document.querySelector('audio');
    const time = document.querySelector('.zen-audio-time');
    const button = document.querySelector('.zen-audio-btn');
    const slider = document.querySelector('[role="slider"]');
    assert.equal(time.innerText, '0:00 / --:--');
    assert.equal(slider.getAttribute('aria-valuenow'), '0');
    assert.equal(button.getAttribute('aria-label'), '播放音频');
    audio.duration = 120;
    audio.dispatchEvent(new window.Event('loadedmetadata'));
    assert.equal(time.innerText, '0:00 / 2:00');
    audio.currentTime = 60;
    audio.dispatchEvent(new window.Event('timeupdate'));
    assert.equal(time.innerText, '1:00 / 2:00');
    assert.equal(slider.getAttribute('aria-valuenow'), '50');
    audio.paused = false;
    audio.dispatchEvent(new window.Event('play'));
    assert.equal(button.getAttribute('aria-label'), '暂停音频');
    audio.paused = true;
    audio.dispatchEvent(new window.Event('pause'));
    assert.equal(button.getAttribute('aria-label'), '播放音频');
    audio.currentTime = 120;
    audio.ended = true;
    audio.dispatchEvent(new window.Event('ended'));
    assert.equal(time.innerText, '2:00 / 2:00');
    assert.equal(slider.getAttribute('aria-valuenow'), '100');
    assert.equal(button.getAttribute('aria-label'), '播放音频');
});
