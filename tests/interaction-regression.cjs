// Run after loading the shared NODE_PATH; see tests/README.md.
// node --test tests/interaction-regression.cjs
// Executes shipped main.js in jsdom; no browser, network or WordPress writes.
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const main = readFileSync(join(__dirname, '..', 'theme', 'zen', 'js/main.js'), 'utf8');

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
    window.matchMedia = () => ({ matches: false, addEventListener() {} });
    prepare(window);
    window.eval(main);
    document.dispatchEvent(new window.Event('DOMContentLoaded'));
    const press = (key, options = {}) => document.activeElement.dispatchEvent(
        new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...options })
    );
    return { window, document, observers, flush, press };
}

test('global keyboard handling registers one document listener', async t => {
    let listeners = 0;
    const { document, press, flush } = await page(t, searchMarkup, window => {
        const add = window.document.addEventListener.bind(window.document);
        window.document.addEventListener = (type, ...args) => {
            if (type === 'keydown') ++listeners;
            return add(type, ...args);
        };
    });
    assert.equal(listeners, 1);
    document.getElementById('outside').focus();
    press('k', { ctrlKey: true }); flush();
    assert.equal(document.getElementById('search-modal').classList.contains('hidden'), false);
    press('Escape'); flush();
    assert.equal(document.getElementById('search-modal').classList.contains('hidden'), true);
});

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

test('mobile menu communicates its close state with the shipped icon', async t => {
    const { document } = await page(t, searchMarkup.replace('>Menu</button>', '><i class="ph ph-list"></i></button>'));
    const trigger = document.getElementById('mobile-menu-btn');
    trigger.click();
    assert.ok(trigger.querySelector('.ph-x'), 'opened menu uses a close glyph');
    trigger.click();
    assert.ok(trigger.querySelector('.ph-list'), 'closed menu uses the list glyph');
});

test('both local and header directory triggers share state and restore the activating control', async t => {
    const { document, flush, press } = await page(t, `
        <header><button id="header-toc-btn" hidden>Directory</button></header>
        <main><button id="floating-toc-btn" hidden>Directory</button>
        <nav id="toc-nav"></nav><article id="post-content"><h2>One</h2></article>
        <div id="toc-overlay" class="hidden opacity-0"></div>
        <aside id="drawer-toc" inert class="translate-x-full"><button id="drawer-toc-close">Close</button><nav id="drawer-toc-nav"></nav></aside></main>`);
    const local = document.getElementById('floating-toc-btn');
    const header = document.getElementById('header-toc-btn');
    assert.equal(local.hidden, false);
    assert.equal(header.hidden, false);
    header.focus(); header.click(); flush();
    assert.equal(document.activeElement.id, 'drawer-toc-close');
    assert.equal(header.getAttribute('aria-expanded'), 'true');
    assert.equal(local.getAttribute('aria-expanded'), 'true');
    press('Escape'); flush();
    assert.equal(document.activeElement, header);
    assert.equal(header.getAttribute('aria-expanded'), 'false');
    assert.equal(document.body.style.overflow, '');
});

for (const dismiss of ['Escape', 'close button', 'backdrop']) {
    test(`drawer ${dismiss} before its focus timer never focuses the closing drawer`, async t => {
        const { document, press, flush } = await page(t, `
            <button id="floating-toc-btn">Directory</button>
            <div id="toc-overlay" class="hidden opacity-0"></div>
            <aside id="drawer-toc" inert class="translate-x-full"><button id="drawer-toc-close">Close</button></aside>
            <button id="search-toggle">Search</button>
            <div id="search-modal" class="hidden"><button id="search-close">Close</button><input id="search-input"></div>`);
        const trigger = document.getElementById('floating-toc-btn');
        const drawer = document.getElementById('drawer-toc');
        const close = document.getElementById('drawer-toc-close');
        const focusCalls = [];
        close.addEventListener('focus', () => focusCalls.push('close'));
        trigger.focus(); trigger.click();
        press('k', { ctrlKey: true });
        assert.equal(document.getElementById('search-modal').classList.contains('hidden'), true, 'drawer continues to block another modal');
        if (dismiss === 'Escape') press('Escape');
        else if (dismiss === 'close button') close.click();
        else document.getElementById('toc-overlay').click();
        flush();
        assert.deepEqual(focusCalls, [], 'cancelled open timer must not steal focus during closing');
        assert.equal(document.activeElement, trigger);
        assert.equal(drawer.hasAttribute('inert'), true);
        assert.equal(drawer.classList.contains('translate-x-full'), true, 'cancelled opening frame must not reveal the closed drawer');
        assert.equal(document.getElementById('toc-overlay').classList.contains('opacity-0'), true);
        assert.equal(document.getElementById('toc-overlay').classList.contains('hidden'), true);
        assert.equal(trigger.getAttribute('aria-expanded'), 'false');
        assert.equal(document.body.style.overflow, '');
        trigger.click(); flush();
        assert.deepEqual(focusCalls, ['close'], 'a later normal open still focuses its close control');
        assert.equal(document.activeElement, close);
        press('Escape'); flush();
        assert.equal(document.activeElement, trigger);
    });
}

test('reopening a closing drawer cancels stale close and focus work', async t => {
    const { document, flush, press } = await page(t, `
        <main><button id="floating-toc-btn">Directory</button><button id="outside">Outside</button></main>
        <div id="toc-overlay" class="hidden opacity-0"></div>
        <aside id="drawer-toc" inert class="translate-x-full"><button id="drawer-toc-close">Close</button></aside>`);
    const trigger = document.getElementById('floating-toc-btn');
    const drawer = document.getElementById('drawer-toc');
    const overlay = document.getElementById('toc-overlay');
    trigger.focus(); trigger.click();
    press('Escape');
    trigger.click(); // Before either the initial rAF or close timer has run.
    flush();
    assert.equal(drawer.hasAttribute('inert'), false);
    assert.equal(drawer.classList.contains('translate-x-full'), false);
    assert.equal(overlay.classList.contains('hidden'), false);
    assert.equal(overlay.classList.contains('opacity-0'), false);
    assert.equal(trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(document.getElementById('outside').closest('[inert]') !== null, true);
    assert.equal(document.activeElement.id, 'drawer-toc-close');
    press('Escape');
    trigger.click(); flush(); // The active element is still the close control while closing.
    assert.equal(document.activeElement.id, 'drawer-toc-close');
    press('Escape'); flush();
    assert.equal(drawer.hasAttribute('inert'), true);
    assert.equal(drawer.classList.contains('translate-x-full'), true);
    assert.equal(overlay.classList.contains('hidden'), true);
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(document.getElementById('outside').closest('[inert]'), null);
    assert.equal(document.activeElement, trigger);
    assert.equal(document.body.style.overflow, '');
});

test('Core lightbox images keep native ownership while plain images retain Zen controls', async t => {
    const { window, document, flush } = await page(t, `
        <article class="entry-content">
            <figure class="wp-block-image wp-lightbox-container"><img id="core" src="/core.jpg" alt="Core" tabindex="-1" role="img" aria-label="Native image"><button>Expand</button></figure>
            <figure class="wp-lightbox-container"><img id="core-bare" src="/bare.jpg" alt="Bare"></figure>
            <a href="/linked.jpg"><img id="linked" src="/linked.jpg" alt="Link"></a>
            <img id="plain" src="/plain.jpg" alt="Plain">
        </article>
        <div id="lightbox" class="hidden"><button id="lightbox-close">Close</button><img id="lightbox-img"></div>`);
    const lightbox = document.getElementById('lightbox');
    const core = document.getElementById('core');
    assert.equal(core.outerHTML, '<img id="core" src="/core.jpg" alt="Core" tabindex="-1" role="img" aria-label="Native image">');
    const bare = document.getElementById('core-bare');
    assert.equal(bare.hasAttribute('tabindex'), false);
    assert.equal(bare.hasAttribute('role'), false);
    assert.equal(bare.hasAttribute('aria-label'), false);
    for (const image of [core, bare, document.getElementById('linked')]) {
        assert.equal(image.classList.contains('cursor-zoom-in'), false);
        const click = new window.MouseEvent('click', { bubbles: true, cancelable: true });
        image.dispatchEvent(click);
        const key = new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        image.dispatchEvent(key);
        assert.equal(click.defaultPrevented, false);
        assert.equal(key.defaultPrevented, false);
        assert.equal(lightbox.classList.contains('hidden'), true);
    }
    const plain = document.getElementById('plain');
    assert.equal(plain.getAttribute('tabindex'), '0');
    assert.equal(plain.getAttribute('role'), 'button');
    assert.equal(plain.getAttribute('aria-label'), 'Plain，点击查看大图');
    plain.focus();
    plain.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
    flush();
    assert.equal(lightbox.classList.contains('hidden'), false);
    assert.equal(document.activeElement.id, 'lightbox-close');
    assert.equal(document.getElementById('lightbox-img').getAttribute('src'), 'https://zen.test/plain.jpg');
});

test('WordPress and MediaElement audio keep native ownership while plain and block audio retain Zen controls', async t => {
    const { document } = await page(t, `
        <audio id="shortcode" class="wp-audio-shortcode" controls preload="none"></audio>
        <div class="mejs-container"><div><audio id="container" controls style="width: 100%"></audio></div></div>
        <div class="mejs-mediaelement"><audio id="mediaelement" controls></audio></div>
        <audio id="plain-audio" controls></audio>
        <figure class="wp-block-audio"><audio id="block-audio" controls></audio></figure>`, window => {
        for (const id of ['shortcode', 'container', 'mediaelement']) {
            const audio = window.document.getElementById(id);
            audio.dataset.before = audio.outerHTML;
        }
    });
    for (const id of ['shortcode', 'container', 'mediaelement']) {
        const audio = document.getElementById(id);
        const before = audio.dataset.before;
        delete audio.dataset.before;
        assert.equal(audio.outerHTML, before, id + ' attributes stay untouched');
        assert.equal(audio.nextElementSibling?.classList.contains('zen-audio-player') || false, false, id + ' gets no competing player');
    }
    assert.equal(document.querySelectorAll('.zen-audio-player').length, 2);
    for (const id of ['plain-audio', 'block-audio']) {
        const audio = document.getElementById(id);
        assert.equal(audio.style.display, 'none');
        assert.ok(audio.nextElementSibling.classList.contains('zen-audio-player'));
    }
});

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
    test(`enabled TOC updates ${navId} from the nearest heading in both scroll directions`, async t => {
        let position = 0;
        const { window, document, flush } = await page(t, `
            <nav id="${navId}"></nav>
            <article id="post-content"><h2>One</h2><h3 id="existing">Two</h3></article>
            <section id="comments"></section>`, window => {
            [...window.document.querySelectorAll('h2, h3, #comments')].forEach((heading, index) => {
                heading.getBoundingClientRect = () => ({ top: index * 600 - position });
                heading.getClientRects = () => [{ top: index * 600 - position }];
            });
        });
        flush();
        const headings = [...document.querySelectorAll('h2, h3')];
        assert.deepEqual(headings.map(heading => heading.id), ['section-0', 'existing']);
        const links = [...document.querySelectorAll('.toc-link')];
        assert.deepEqual(links.map(link => link.getAttribute('href')), ['#section-0', '#existing', '#comments']);
        for (const [scroll, active] of [[0, 0], [650, 1], [300, 0], [1250, 2]]) {
            position = scroll;
            window.dispatchEvent(new window.Event('scroll'));
            flush();
            assert.equal(links[active].getAttribute('aria-current'), 'location');
            assert.equal(links.filter(link => link.classList.contains('active')).length, 1);
        }
    });
}

const detailsMarkup = `
    <nav id="toc-nav"></nav><nav id="drawer-toc-nav"></nav>
    <article id="post-content">
        <h2 id="intro">Intro</h2>
        <details id="outer"><summary>Outer</summary>
            <details id="inner"><summary>Inner</summary><h3 id="nested">Nested</h3></details>
        </details>
        <h2 id="later">Later</h2>
    </article>`;

function detailsLayout(window) {
    for (const [id, top] of [['intro', 50], ['nested', 70], ['later', 700]]) {
        const target = window.document.getElementById(id);
        const visible = () => {
            for (let parent = target.parentElement; parent; parent = parent.parentElement) {
                if (parent.matches('details:not([open])')) return false;
            }
            return true;
        };
        target.getClientRects = () => visible() ? [{ top }] : [];
        target.getBoundingClientRect = () => ({ top: visible() ? top : 0 });
    }
}

for (const navId of ['toc-nav', 'drawer-toc-nav']) {
    test(`TOC ${navId} expands all details ancestors without replacing native hash navigation`, async t => {
        const { window, document, flush } = await page(t, detailsMarkup, detailsLayout);
        const link = document.querySelector(`#${navId} [href="#nested"]`);
        assert.ok(link, 'initially folded heading remains in the directory');
        let openedAtDefaultAction = false;
        document.addEventListener('click', event => {
            if (event.target === link) {
                openedAtDefaultAction = document.getElementById('outer').open && document.getElementById('inner').open;
                assert.equal(event.defaultPrevented, false, 'native anchor default is not intercepted');
            }
        });
        link.click();
        assert.equal(openedAtDefaultAction, true, 'all ancestors open before the anchor default action');
        flush();
        assert.equal(link.getAttribute('aria-current'), 'location');
        await new Promise(resolve => setTimeout(resolve, 30));
        assert.equal(window.location.hash, '#nested');
        window.history.back();
        flush();
        await new Promise(resolve => setTimeout(resolve, 30));
        flush();
        assert.equal(window.location.hash, '', 'Back preserves native history');
    });
}

test('TOC ignores folded targets and refreshes on non-bubbling details toggles', async t => {
    const { window, document, flush } = await page(t, detailsMarkup, detailsLayout);
    const nestedLinks = [...document.querySelectorAll('[href="#nested"]')];
    const assertActive = id => {
        assert.deepEqual([...document.querySelectorAll('.toc-link.active')].map(link => link.dataset.target), [id, id]);
        assert.deepEqual([...document.querySelectorAll('[aria-current="location"]')].map(link => link.dataset.target), [id, id]);
    };
    flush();
    assert.equal(nestedLinks.length, 2, 'folded entries are retained in both directories');
    assertActive('intro');
    document.getElementById('outer').open = true;
    document.getElementById('inner').open = true;
    document.getElementById('inner').dispatchEvent(new window.Event('toggle'));
    flush();
    assertActive('nested');
    document.getElementById('outer').open = false;
    document.getElementById('outer').dispatchEvent(new window.Event('toggle'));
    flush();
    assertActive('intro');
    document.getElementById('intro').getClientRects = () => [];
    document.getElementById('later').getClientRects = () => [];
    document.getElementById('outer').dispatchEvent(new window.Event('toggle'));
    flush();
    assert.equal(document.querySelectorAll('.toc-link.active, [aria-current="location"]').length, 0, 'no hidden target remains active');
});

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
