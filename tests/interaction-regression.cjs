// Run after loading the shared NODE_PATH; see tests/README.md.
// node --test tests/interaction-regression.cjs
// Executes shipped frontend scripts in jsdom; no browser, network or WordPress writes.
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const main = readFileSync(join(__dirname, '..', 'theme', 'zen', 'js/main.js'), 'utf8');

async function countPage(t, html, prepare = () => {}) {
    const dom = new JSDOM('<!doctype html>' + html, {
        url: 'https://zen.test/article/', runScripts: 'outside-only',
    });
    const { window } = dom;
    const { document } = window;
    t.after(() => window.close());
    await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    window.zenReadingCount = { ajax_url: 'https://zen.test/wp-admin/admin-ajax.php', enabled: 1 };
    prepare(window);
    const script = join(__dirname, '..', 'theme', 'zen', 'js/reading-count.js');
    window.eval(readFileSync(script, 'utf8'));
    return { window, document };
}

const countMarkup = '<span class="zen-meta-field">浏览次数 <span id="zen-reading-count" data-post-id="42">7</span></span>';

test('reading count replaces a cached value only after a successful AJAX response', async t => {
    const requests = [];
    let respond;
    const { window, document } = await countPage(t, countMarkup, window => {
        window.fetch = (url, options) => {
            requests.push({ url, options });
            return new Promise(resolve => { respond = resolve; });
        };
    });
    const count = document.getElementById('zen-reading-count');
    assert.equal(count.textContent, '7', 'cached count remains while the request is pending');
    assert.equal(requests.length, 1, 'late/deferred execution sends one request');
    const { url, options } = requests[0];
    assert.equal(url, 'https://zen.test/wp-admin/admin-ajax.php');
    assert.equal(options.method, 'POST');
    assert.equal(options.credentials, 'same-origin');
    assert.ok(options.body instanceof window.URLSearchParams);
    assert.deepEqual([...options.body.entries()], [['action', 'zen_post_view'], ['post_id', '42']]);
    document.dispatchEvent(new window.Event('DOMContentLoaded'));
    assert.equal(requests.length, 1, 'another ready event never counts twice');
    respond({ ok: true, json: async () => ({ success: true, data: { count: 1234 } }) });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(count.textContent, '1234', 'replace, never increment the stale cached value');
    assert.ok(count.parentElement.textContent.startsWith('浏览次数 '));
});

test('reading count keeps the cached value when AJAX fails', async t => {
    const { document } = await countPage(t, countMarkup, window => {
        window.fetch = async () => { throw new Error('offline'); };
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(document.getElementById('zen-reading-count').textContent, '7');
});

test('reading count accepts a numeric server count', async t => {
    const { document } = await countPage(t, countMarkup, window => {
        window.fetch = async () => ({ ok: true, json: async () => ({ success: true, data: { count: 0 } }) });
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(document.getElementById('zen-reading-count').textContent, '0');
});

test('reading count displays the server-formatted count as text', async t => {
    const { document } = await countPage(t, countMarkup, window => {
        window.fetch = async () => ({ ok: true, json: async () => ({ success: true, data: { count: '1,234' } }) });
    });
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(document.getElementById('zen-reading-count').textContent, '1,234');
});

for (const [label, response] of [
    ['HTTP failure', { ok: false, json: async () => ({ success: true, data: { count: 100 } }) }],
    ['rejected action', { ok: true, json: async () => ({ success: false, data: { count: 100 } }) }],
    ['invalid JSON', { ok: true, json: async () => { throw new SyntaxError('Invalid JSON'); } }],
    ['missing count', { ok: true, json: async () => ({ success: true, data: {} }) }],
]) {
    test(`reading count keeps the cached value for ${label}`, async t => {
        const { document } = await countPage(t, countMarkup, window => {
            window.fetch = async () => response;
        });
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(document.getElementById('zen-reading-count').textContent, '7');
    });
}

for (const [label, html, enabled] of [
    ['non-article page', '<main>Page without a count</main>', 1],
    ['disabled counter', countMarkup, 0],
    ['localized disabled counter', countMarkup, '0'],
    ...['', '0', '-1', '42junk', '4.2', ' 42', '042'].map(id => [
        `invalid post ID ${JSON.stringify(id)}`, countMarkup.replace('data-post-id="42"', `data-post-id="${id}"`), 1,
    ]),
]) {
    test(`reading count does not request for ${label}`, async t => {
        let requests = 0;
        await countPage(t, html, window => {
            window.zenReadingCount.enabled = enabled;
            window.fetch = () => { ++requests; };
        });
        assert.equal(requests, 0);
    });
}

test('reading count accepts the localized enabled flag and waits once for DOM ready', async t => {
    let requests = 0;
    const { window, document } = await countPage(t, countMarkup, window => {
        window.zenReadingCount.enabled = '1';
        Object.defineProperty(window.document, 'readyState', { value: 'loading' });
        window.fetch = async () => {
            ++requests;
            return { ok: true, json: async () => ({ success: true, data: { count: 0 } }) };
        };
    });
    assert.equal(requests, 0);
    document.dispatchEvent(new window.Event('DOMContentLoaded'));
    document.dispatchEvent(new window.Event('DOMContentLoaded'));
    assert.equal(requests, 1);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(document.getElementById('zen-reading-count').textContent, '0');
});

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
        <button id="search-toggle" aria-expanded="false" hidden>Search</button>
    </header>
    <main><button id="outside">Outside</button></main>
    <div id="search-modal">
        <button id="search-close" hidden>Close</button><input id="search-input"><button>Submit</button>
    </div>`;

test('bound search opens a dialog and restores its trigger on close', async t => {
    const { document, flush } = await page(t, searchMarkup);
    const search = document.getElementById('search-modal');
    const toggle = document.getElementById('search-toggle');
    assert.equal(toggle.hidden, false);
    assert.ok(search.classList.contains('hidden'));
    assert.equal(search.getAttribute('role'), 'dialog');
    assert.equal(search.getAttribute('aria-modal'), 'true');
    toggle.focus();
    toggle.click();
    flush();
    assert.equal(document.activeElement.id, 'search-input');
    document.getElementById('search-close').click();
    flush();
    assert.equal(document.activeElement, toggle);
    assert.equal(toggle.closest('[inert], [aria-hidden="true"]'), null);
});

test('search shortcut preserves focus on the page', async t => {
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

test('the localized disabled shortcut leaves the browser default alone', async t => {
    const { document, press } = await page(t, searchMarkup, window => {
        window.zenSettings.search_shortcut = '0';
    });
    assert.equal(press('k', { ctrlKey: true }), true);
    assert.ok(document.getElementById('search-modal').classList.contains('hidden'));
    document.getElementById('search-toggle').click();
    assert.equal(document.getElementById('search-toggle').getAttribute('aria-expanded'), 'true');
});

test('search shortcut does not interrupt the active Core image lightbox', async t => {
    const { document, press } = await page(t, `${searchMarkup}
        <div class="wp-lightbox-overlay active" role="dialog" aria-modal="true"><button id="core-close">Close</button></div>`);
    document.getElementById('core-close').focus();
    assert.equal(press('k', { ctrlKey: true }), true);
    assert.ok(document.getElementById('search-modal').classList.contains('hidden'));
    document.querySelector('.wp-lightbox-overlay').classList.remove('active');
    document.getElementById('outside').focus();
    assert.equal(press('k', { ctrlKey: true }), false);
    assert.equal(document.activeElement.id, 'search-input');
});

test('search focuses immediately and traps Tab around usable controls only', async t => {
    const { document, press } = await page(t, searchMarkup.replace('<button>Submit</button>',
        '<fieldset disabled><button>Unavailable</button></fieldset><button id="submit">Submit</button><div hidden><button>Hidden</button></div>'));
    const toggle = document.getElementById('search-toggle');
    toggle.focus(); toggle.click();
    assert.equal(document.activeElement.id, 'search-input');
    document.getElementById('submit').focus();
    assert.equal(press('Tab'), false);
    assert.equal(document.activeElement.id, 'search-close');
    assert.equal(press('Tab', { shiftKey: true }), false);
    assert.equal(document.activeElement.id, 'submit');
});

test('Escape does not steal focus when there is no active modal', async t => {
    const { document, press } = await page(t, searchMarkup);
    const outside = document.getElementById('outside');
    outside.focus();
    assert.equal(press('Escape'), true);
    assert.equal(document.activeElement, outside);
});

test('modal isolation is exclusive and restores existing inline and accessibility state', async t => {
    const { document, press, flush } = await page(t, `<body style="overflow: auto !important">
        ${searchMarkup}
        <section id="existing" inert aria-hidden="false"></section><div id="empty" aria-hidden=""></div>
        <button id="floating-toc-btn">Directory</button>
        <div id="toc-overlay" class="hidden opacity-0"></div>
        <aside id="drawer-toc" inert class="translate-x-full"><button id="drawer-toc-close">Close</button></aside>
    </body>`);
    const toggle = document.getElementById('search-toggle');
    const drawer = document.getElementById('drawer-toc');
    toggle.focus(); toggle.click(); flush();
    document.getElementById('floating-toc-btn').click(); flush();
    assert.equal(document.getElementById('floating-toc-btn').getAttribute('aria-expanded'), null);
    assert.ok(drawer.hasAttribute('inert'));
    assert.equal(document.activeElement.id, 'search-input');
    assert.equal(press('Escape'), false);
    flush();
    assert.equal(document.activeElement, toggle);
    assert.equal(document.body.style.overflow, 'auto');
    assert.equal(document.body.style.getPropertyPriority('overflow'), 'important');
    assert.ok(document.getElementById('existing').hasAttribute('inert'));
    assert.equal(document.getElementById('existing').getAttribute('aria-hidden'), 'false');
    assert.equal(document.getElementById('empty').getAttribute('aria-hidden'), '');
});

test('pointer lightbox returns focus to the actual image trigger', async t => {
    const { document, flush, press } = await page(t, `
        <article class="entry-content"><a id="prior" href="/about">About</a><img id="image" src="/photo.jpg"></article>
        <div id="lightbox" class="hidden"><button id="lightbox-close">Close</button><img id="lightbox-img"></div>`);
    document.getElementById('prior').focus();
    document.getElementById('image').click();
    flush(); press('Escape'); flush();
    assert.equal(document.activeElement.id, 'image');
});

test('search Meta+k opens and closes without losing footer link focus', async t => {
    const { document, press, flush } = await page(t, `${searchMarkup}<footer><a href="/about">About</a></footer>`);
    const about = document.querySelector('footer a');
    about.focus();
    press('k', { metaKey: true });
    flush();
    assert.equal(document.activeElement.id, 'search-input');
    press('Escape');
    flush();
    assert.equal(document.activeElement, about);
    assert.equal(document.body.style.overflow, '');
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
    test(`drawer ${dismiss} before its opening frame does not steal focus after dismissal`, async t => {
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
        assert.equal(document.activeElement, close, 'focus enters before the opening frame');
        press('k', { ctrlKey: true });
        assert.equal(document.getElementById('search-modal').classList.contains('hidden'), true, 'drawer continues to block another modal');
        if (dismiss === 'Escape') press('Escape');
        else if (dismiss === 'close button') close.click();
        else document.getElementById('toc-overlay').click();
        flush();
        assert.deepEqual(focusCalls, ['close'], 'no deferred focus occurs during closing');
        assert.equal(document.activeElement, trigger);
        assert.equal(drawer.hasAttribute('inert'), true);
        assert.equal(drawer.classList.contains('translate-x-full'), true, 'cancelled opening frame must not reveal the closed drawer');
        assert.equal(document.getElementById('toc-overlay').classList.contains('opacity-0'), true);
        assert.equal(document.getElementById('toc-overlay').classList.contains('hidden'), true);
        assert.equal(trigger.getAttribute('aria-expanded'), 'false');
        assert.equal(document.body.style.overflow, '');
        trigger.click(); flush();
        assert.deepEqual(focusCalls, ['close', 'close'], 'a later normal open still focuses its close control');
        assert.equal(document.activeElement, close);
        press('Escape'); flush();
        assert.equal(document.activeElement, trigger);
    });
}

test('drawer dismissal can return focus after its trigger disappears at a wider breakpoint', async t => {
    const { document, press, flush } = await page(t, `${searchMarkup}
        <button id="floating-toc-btn">Directory</button>
        <div id="toc-overlay" class="hidden opacity-0"></div>
        <aside id="drawer-toc" inert class="translate-x-full"><button id="drawer-toc-close">Close</button></aside>`);
    const trigger = document.getElementById('floating-toc-btn');
    trigger.focus(); trigger.click(); flush();
    trigger.focus = () => {}; // A CSS-hidden button cannot take focus in a browser.
    press('Escape'); flush();
    assert.equal(document.activeElement.id, 'search-toggle');
});

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

for (const navId of ['toc-nav', 'drawer-toc-nav']) {
    test(`TOC ${navId} navigation transfers focus without changing author tabindex or requesting scroll`, async t => {
        const { document, flush, press } = await page(t, `
            <header><button id="header-toc-btn">Directory</button></header>
            <main><nav id="toc-nav"></nav><article id="post-content">
                <h2 id="chapter">Chapter</h2><a id="after" href="/next">Next control</a>
                <h2 id="author-focus" tabindex="0">Author focus</h2>
            </article></main>
            <div id="toc-overlay" class="hidden opacity-0"></div>
            <aside id="drawer-toc" inert class="translate-x-full"><button id="drawer-toc-close">Close</button><nav id="drawer-toc-nav"></nav></aside>`);
        const trigger = document.getElementById('header-toc-btn');
        for (const id of ['chapter', 'author-focus']) {
            if (navId === 'drawer-toc-nav') { trigger.focus(); trigger.click(); flush(); }
            const target = document.getElementById(id);
            const focus = target.focus.bind(target);
            const options = [];
            target.focus = value => { options.push(value); focus(value); };
            const link = document.querySelector(`#${navId} [data-target="${id}"]`);
            link.focus(); link.click(); flush();
            assert.equal(document.activeElement, target, 'chapter navigation must not return to the header');
            assert.equal(target.closest('[inert]'), null, 'focus only after modal background is restored');
            assert.deepEqual(options.map(value => value?.preventScroll), [true], 'native anchor alone owns scrolling');
            assert.equal(target.getAttribute('tabindex'), id === 'chapter' ? '-1' : '0');
            document.getElementById('after').focus();
            assert.equal(target.getAttribute('tabindex'), id === 'chapter' ? null : '0', 'only a temporary tabindex is removed');
        }
        // Dismissal is not navigation and must still return to the trigger.
        trigger.focus(); trigger.click(); flush(); press('Escape'); flush();
        assert.equal(document.activeElement, trigger);
    });
}

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

test('muted or zero-volume audio can be unmuted or adjusted in its native player', async t => {
    const { document } = await page(t, `
        <audio id="muted" controls muted src="/audio.wav"></audio>
        <figure class="wp-block-audio"><audio id="muted-block" controls muted src="/audio.wav"></audio></figure>
        <audio id="runtime-muted" src="/audio.wav"></audio>
        <audio id="silent" controls src="/audio.wav"></audio>`, window => {
        window.document.getElementById('runtime-muted').muted = true;
        window.document.getElementById('silent').volume = 0;
    });
    assert.equal(document.querySelectorAll('.zen-audio-player').length, 0);
    for (const audio of document.querySelectorAll('audio')) {
        assert.equal(audio.controls, true);
        assert.notEqual(audio.style.display, 'none');
    }
});

test('keyboard back-to-top moves focus to the first page link without a second scroll', async t => {
    const scrolls = [];
    const focusOptions = [];
    const { document } = await page(t, `
        <a class="skip-link" href="#main-content">Skip to content</a><main id="main-content"></main>
        <button id="back-to-top" disabled>Back to top</button>`, window => {
        window.matchMedia = query => ({ matches: query === '(prefers-reduced-motion: reduce)' });
        window.scrollY = 700;
        window.scrollTo = options => { scrolls.push([options.top, options.behavior]); };
        const skip = window.document.querySelector('.skip-link');
        const originalFocus = skip.focus.bind(skip);
        skip.focus = options => { focusOptions.push(options?.preventScroll); originalFocus(options); };
    });
    const button = document.getElementById('back-to-top');
    button.focus();
    button.click();
    assert.equal(document.activeElement, document.querySelector('.skip-link'));
    assert.deepEqual(focusOptions, [true]);
    assert.deepEqual(scrolls, [[0, 'auto']]);
});

test('responsive lightbox uses its own slot and clears responsive attributes for a single image', async t => {
    const { document, press } = await page(t, `
        <div class="entry-content">
            <img id="responsive" src="small.jpg" srcset="small.jpg 320w, large.jpg 1200w" sizes="320px">
            <img id="single" src="single.jpg">
        </div>
        <div id="lightbox" class="hidden"><button id="lightbox-close">Close</button><img id="lightbox-img"></div>`);
    const large = document.getElementById('lightbox-img');
    const bodyImage = document.getElementById('responsive');
    bodyImage.click();
    assert.equal(large.srcset, bodyImage.srcset);
    assert.equal(large.sizes, 'min(92vw, 72rem)');
    assert.equal(bodyImage.sizes, '320px');
    press('Escape');
    document.getElementById('single').click();
    assert.equal(large.hasAttribute('srcset'), false);
    assert.equal(large.hasAttribute('sizes'), false);
    assert.equal(large.getAttribute('src'), 'https://zen.test/single.jpg');
});

test('audio that already failed before startup retains native controls', async t => {
    const { document } = await page(t, '<audio controls></audio>', window => {
        Object.defineProperty(window.document.querySelector('audio'), 'error', { value: { code: 4 } });
    });
    const audio = document.querySelector('audio');
    assert.equal(audio.controls, true);
    assert.equal(audio.style.display, '');
    assert.equal(document.querySelector('.zen-audio-player'), null);
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

test('a scroll burst queues one frame and only changes previous and next TOC entries', async t => {
    let position = 0;
    let frames = 0;
    const { window, document, flush } = await page(t, `
        <div id="reading-progress"></div><button id="back-to-top">Top</button>
        <nav id="toc-nav"></nav><nav id="drawer-toc-nav"></nav>
        <article id="post-content"><h2 id="first">One</h2><h2 id="second">Two</h2><h2 id="third">Three</h2></article>`, window => {
        const frame = window.requestAnimationFrame;
        window.requestAnimationFrame = callback => { ++frames; return frame(callback); };
        Object.defineProperty(window, 'scrollY', { get: () => position });
        Object.defineProperties(window.document.documentElement, {
            scrollTop: { get: () => position }, scrollHeight: { value: 1800 }, clientHeight: { value: 600 },
        });
        [...window.document.querySelectorAll('h2')].forEach((heading, index) => {
            heading.getClientRects = () => [{ top: index * 600 - position }];
        });
    });
    flush();
    frames = 0;
    const mutations = new window.MutationObserver(() => {});
    mutations.observe(document.querySelector('#toc-nav'), { subtree: true, attributes: true });
    position = 600;
    for (let i = 0; i < 3; i++) window.dispatchEvent(new window.Event('scroll'));
    assert.equal(frames, 1);
    flush();
    assert.equal(document.getElementById('reading-progress').style.width, '50%');
    assert.equal(document.getElementById('back-to-top').disabled, false);
    assert.deepEqual([...document.querySelectorAll('.toc-link.active')].map(link => link.dataset.target), ['second', 'second']);
    assert.deepEqual([...new Set(mutations.takeRecords().map(record => record.target.dataset.target))], ['first', 'second']);
    position = 700;
    window.dispatchEvent(new window.Event('scroll')); flush();
    assert.equal(mutations.takeRecords().length, 0);
});

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

// jsdom does not implement media playback: keep only that browser boundary doubled.
async function pendingAudioPage(t) {
    const attempts = [];
    // Explicit tabindex models native controls focusability, which jsdom omits.
    const context = await page(t, '<audio controls tabindex="0"></audio>', window => {
        const audio = window.document.querySelector('audio');
        Object.defineProperties(audio, {
            paused: { value: true, writable: true },
            ended: { value: false, writable: true },
        });
        audio.play = () => {
            audio.paused = false;
            audio.dispatchEvent(new window.Event('play'));
            return new Promise((resolve, reject) => attempts.push({ resolve, reject }));
        };
        audio.pause = () => {
            audio.paused = true;
            audio.dispatchEvent(new window.Event('pause'));
        };
    });
    const { document } = context;
    return {
        ...context, attempts, audio: document.querySelector('audio'),
        button: document.querySelector('.zen-audio-btn'),
        settle: () => new Promise(resolve => setImmediate(resolve)),
    };
}

test('cancelling pending audio playback keeps only the custom owner', async t => {
    const { window, document, audio, button, attempts, settle } = await pendingAudioPage(t);
    button.click();
    assert.equal(audio.paused, false);
    button.click();
    attempts[0].reject(new window.DOMException('play() interrupted by pause()', 'AbortError'));
    await settle();
    assert.equal(audio.paused, true);
    assert.equal(audio.style.display, 'none', 'normal cancellation must not expose native controls');
    assert.equal(document.querySelectorAll('.zen-audio-player').length, 1);
    assert.equal(button.getAttribute('aria-label'), '播放音频');
});

test('a stale audio play resolution cannot overwrite the paused state', async t => {
    const { audio, button, attempts, settle } = await pendingAudioPage(t);
    button.click(); button.click();
    attempts[0].resolve();
    await settle();
    assert.equal(audio.paused, true);
    assert.equal(button.getAttribute('aria-label'), '播放音频');
    assert.equal(audio.style.display, 'none');
});

test('a stale audio rejection cannot downgrade a newer successful play', async t => {
    const { audio, button, attempts, settle } = await pendingAudioPage(t);
    button.click(); button.click(); button.click();
    attempts[1].resolve();
    await settle();
    attempts[0].reject(new Error('failure from an obsolete attempt'));
    await settle();
    assert.equal(audio.paused, false);
    assert.equal(audio.style.display, 'none', 'only the current play request may downgrade');
    assert.equal(button.getAttribute('aria-label'), '暂停音频');
});

for (const completion of ['resolve', 'reject']) {
    test(`audio ${completion} after an external pause cannot change the cancelled custom owner`, async t => {
        const { audio, button, attempts, settle } = await pendingAudioPage(t);
        button.click();
        audio.pause(); // Media/session controls may pause without using the Zen button.
        if (completion === 'resolve') attempts[0].resolve();
        else attempts[0].reject(new Error('obsolete failure after external pause'));
        await settle();
        assert.equal(audio.style.display, 'none');
        assert.equal(button.getAttribute('aria-label'), '播放音频');
    });
}

test('an old queued pause event cannot invalidate the current audio failure', async t => {
    const { window, document, audio, button, attempts, settle } = await pendingAudioPage(t);
    button.click(); button.click(); button.click();
    assert.equal(audio.paused, false);
    // Native media events are queued: the earlier pause can arrive after a new play().
    audio.dispatchEvent(new window.Event('pause'));
    attempts[1].reject(new Error('current attempt failed'));
    await settle();
    assert.equal(audio.style.display, '');
    assert.equal(document.querySelectorAll('.zen-audio-player').length, 0);
});

for (const failure of ['rejected play', 'media error']) {
    test(`audio ${failure} leaves only native controls and moves focused custom controls`, async t => {
        const { window, document, audio, button, attempts, settle } = await pendingAudioPage(t);
        button.focus(); button.click();
        if (failure === 'rejected play') attempts[0].reject(new Error('unsupported media'));
        else audio.dispatchEvent(new window.Event('error'));
        await settle();
        assert.equal(audio.style.display, '');
        assert.equal(audio.controls, true);
        assert.equal(document.querySelectorAll('.zen-audio-player').length, 0, 'fallback removes the competing owner');
        assert.equal(document.activeElement, audio, 'keyboard focus survives removal of custom controls');
        // A later completion from the abandoned custom player cannot restore it.
        attempts[0].resolve();
        await settle();
        assert.equal(document.querySelectorAll('.zen-audio-player').length, 0);
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
