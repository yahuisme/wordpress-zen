// Footer loading contract + real bundled Highlight.js; no browser or WordPress writes.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');
const { JSDOM } = require('jsdom');
const root = join(__dirname, '..', 'theme', 'zen');
const main = readFileSync(join(root, 'js/main.js'), 'utf8');
const highlight = readFileSync(join(root, 'assets/js/highlight.min.js'), 'utf8');

// Execute actual enqueue.php with only WordPress API boundaries replaced.
function scriptQueue() {
    return JSON.parse(execFileSync('php', ['-r', `
        define('ABSPATH', ${JSON.stringify(root + '/')});
        function add_action(...$args) {}
        function add_filter(...$args) {}
        function zen_get_option($key) {
            return ['zen_font_family' => 'system', 'zen_show_highlight' => 1, 'zen_content_width' => 900, 'zen_reading_width' => 720][$key] ?? 0;
        }
        function get_template_directory() { return ABSPATH; }
        function get_template_directory_uri() { return 'https://zen.test/theme'; }
        function is_singular($type = null) { return $type === null; }
        function is_preview() { return false; }
        function get_post() { return (object) ['post_content' => '<pre><code>let x = 1;</code></pre>']; }
        function post_password_required($post) { return false; }
        function has_block(...$args) { return false; }
        function comments_open() { return false; }
        function wp_enqueue_style(...$args) {}
        function wp_add_inline_style(...$args) {}
        function wp_localize_script(...$args) {}
        function wp_script_is($handle, $status) { return isset($GLOBALS['queue'][$handle]); }
        function wp_enqueue_script($handle, ...$args) { $GLOBALS['queue'][$handle] = $args; }
        require ABSPATH . 'inc/enqueue.php';
        zen_scripts();
        echo json_encode($GLOBALS['queue']);
    `], { encoding: 'utf8' }));
}

test('critical UI is synchronous in the footer before an independent async highlighter', () => {
    const scripts = scriptQueue();
    const primary = scripts['zen-main'];
    assert.deepEqual(primary[1], [], 'main never depends on optional highlighting');
    assert.equal(primary[3].in_footer, true);
    assert.equal(primary[3].strategy, undefined, 'defer would still wait for DOMContentLoaded');
    assert.equal(scripts['highlight-js'][3].strategy, 'async');
    assert.ok(Object.keys(scripts).indexOf('zen-main') < Object.keys(scripts).indexOf('highlight-js'));
});

async function footerPage(t) {
    const dom = new JSDOM(`<!doctype html>
        <button id="mobile-menu-btn" aria-expanded="false">Menu</button>
        <div id="mobile-menu" class="hidden"><a href="/about">About</a></div>
        <button id="search-toggle" aria-expanded="false">Search</button>
        <div id="search-modal" class="hidden"><button id="search-close">Close</button><input id="search-input"></div>
        <article class="entry-content"><pre class="language-javascript"><code>const answer = 42;</code></pre></article>
        <script id="zen-main-js"></script><script id="highlight-js-js" async></script>`,
        { url: 'https://zen.test/', runScripts: 'outside-only', pretendToBeVisual: true });
    const { window } = dom;
    const { document } = window;
    t.after(() => window.close());
    await new Promise(resolve => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    // Model a synchronous footer script before the parser completes or DCL fires.
    Object.defineProperty(document, 'readyState', { configurable: true, value: 'loading' });
    Object.defineProperty(document, 'currentScript', { value: document.getElementById('zen-main-js') });
    const copied = [];
    Object.defineProperty(window, 'isSecureContext', { value: true });
    Object.defineProperty(window.navigator, 'clipboard', { value: { writeText: async value => copied.push(value) } });
    window.matchMedia = () => ({ matches: false, addEventListener() {} });
    window.eval(main);
    return { window, document, copied };
}

test('footer UI and copy work before DOMContentLoaded while the highlighter is unavailable', async t => {
    const { window, document, copied } = await footerPage(t);
    const menu = document.getElementById('mobile-menu-btn');
    menu.click();
    assert.equal(menu.getAttribute('aria-expanded'), 'true', 'menu must bind before DCL');
    document.getElementById('search-toggle').click();
    assert.equal(document.getElementById('search-toggle').getAttribute('aria-expanded'), 'true');
    const copy = document.querySelector('.zen-code-copy');
    assert.ok(copy, 'copy does not depend on the highlighter');
    copy.click(); await Promise.resolve();
    assert.deepEqual(copied, ['const answer = 42;']);
    document.dispatchEvent(new window.Event('DOMContentLoaded'));
    assert.equal(document.querySelectorAll('.zen-code-copy').length, 1, 'DCL does not initialize twice');
});

test('a failed optional highlighter leaves copy and the menu usable', async t => {
    const { window, document, copied } = await footerPage(t);
    document.getElementById('highlight-js-js').dispatchEvent(new window.Event('error'));
    document.querySelector('.zen-code-copy').click(); await Promise.resolve();
    assert.deepEqual(copied, ['const answer = 42;']);
    document.getElementById('mobile-menu-btn').click();
    assert.equal(document.getElementById('mobile-menu-btn').getAttribute('aria-expanded'), 'true');
    assert.equal(document.querySelector('code').childElementCount, 0);
});

test('an asynchronously loaded real highlighter enhances code after basic UI initialization', async t => {
    const { window, document } = await footerPage(t);
    const code = document.querySelector('code');
    assert.equal(code.dataset.highlighted, undefined);
    window.eval(highlight);
    const script = document.getElementById('highlight-js-js');
    script.dispatchEvent(new window.Event('load'));
    assert.equal(code.dataset.highlighted, 'yes');
    assert.ok(code.querySelector('.hljs-keyword'), 'real Highlight.js executes after its load event');
    script.dispatchEvent(new window.Event('load'));
    assert.equal(document.querySelectorAll('.zen-code-copy').length, 1);
    document.getElementById('mobile-menu-btn').click();
    assert.equal(document.getElementById('mobile-menu-btn').getAttribute('aria-expanded'), 'true');
});
