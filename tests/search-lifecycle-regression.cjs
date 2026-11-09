// Focused search lifecycle regressions; no browser or reading-count fixtures.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const main = fs.readFileSync(path.join(__dirname, '../theme/zen/js/main.js'), 'utf8');

async function searchPage(t) {
    const dom = new JSDOM(`<!doctype html><button id="search-toggle" aria-expanded="false">Search</button>
        <main><button id="outside">Outside</button></main>
        <div id="search-modal" class="hidden opacity-0"><button id="search-close">Close</button><input id="search-input"></div>`,
        { url: 'https://zen.test/', runScripts: 'outside-only', pretendToBeVisual: true });
    const { window } = dom;
    const { document } = window;
    t.after(() => window.close());
    await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    const timers = new Map();
    const frames = [];
    let id = 0;
    window.zenSettings = { search_shortcut: 1 };
    window.matchMedia = () => ({ matches: false, addEventListener() {} });
    window.setTimeout = callback => { timers.set(++id, callback); return id; };
    window.clearTimeout = token => timers.delete(token);
    window.requestAnimationFrame = callback => frames.push(callback);
    window.eval(main);
    const flushFrames = () => frames.splice(0).forEach(callback => callback());
    const flushTimers = () => {
        const pending = [...timers.values()];
        timers.clear();
        pending.forEach(callback => callback());
    };
    const shortcut = () => document.activeElement.dispatchEvent(new window.KeyboardEvent('keydown', {
        key: 'k', ctrlKey: true, bubbles: true, cancelable: true,
    }));
    return { document, flushFrames, flushTimers, shortcut };
}

test('dismiss before the opening frame does not reactivate the closing search', async t => {
    const { document, flushFrames, flushTimers } = await searchPage(t);
    const trigger = document.getElementById('search-toggle');
    const modal = document.getElementById('search-modal');
    trigger.focus(); trigger.click();
    document.getElementById('search-close').click();
    flushFrames();
    assert.equal(modal.classList.contains('is-open'), false);
    assert.equal(modal.classList.contains('opacity-0'), true);
    flushTimers();
    assert.equal(modal.classList.contains('hidden'), true);
    assert.equal(document.activeElement, trigger);
    assert.equal(document.body.style.overflow, '');
});

test('reopening during close retains the original focus return target', async t => {
    const { document, flushFrames, flushTimers, shortcut } = await searchPage(t);
    const trigger = document.getElementById('search-toggle');
    const modal = document.getElementById('search-modal');
    trigger.focus(); trigger.click(); flushFrames(); flushTimers();
    assert.equal(document.activeElement.id, 'search-input');
    document.getElementById('search-close').click();
    // Close animation still owns the focused input: reopen before it completes.
    trigger.click(); flushFrames(); flushTimers();
    assert.equal(modal.classList.contains('hidden'), false);
    shortcut(); flushFrames(); flushTimers();
    assert.equal(modal.classList.contains('hidden'), true);
    assert.equal(document.activeElement, trigger);
    assert.equal(document.activeElement.closest('.hidden, [inert]'), null);
    assert.equal(trigger.getAttribute('aria-expanded'), 'false');
});

test('repeated open on an active search does not replace its focus return target', async t => {
    const { document, flushFrames, flushTimers } = await searchPage(t);
    const outside = document.getElementById('outside');
    const trigger = document.getElementById('search-toggle');
    outside.focus(); trigger.click(); flushFrames(); flushTimers();
    trigger.click(); flushFrames(); flushTimers();
    document.getElementById('search-close').click(); flushFrames(); flushTimers();
    assert.equal(document.activeElement, outside);
});
