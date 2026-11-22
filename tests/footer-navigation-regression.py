"""Check real footer order at 127.0.0.1:18765 in a bounded cgroup.

Pass the visible link texts from the fixture's footer menu (with RSS last).
Enable custom footer text with <a href="/extra/">Extra</a> to test both groups.
"""
import re
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

origin = 'http://127.0.0.1:18765'
extras = '--custom' in sys.argv[1:]
expected = [arg for arg in sys.argv[1:] if arg != '--custom']
assert expected
scope = Path('/proc/self/cgroup').read_text()
limits = Path('/sys/fs/cgroup') / scope.strip().split('::/')[1]
assert int((limits / 'memory.max').read_text()) <= 4 * 1024**3
assert (limits / 'memory.swap.max').read_text().strip() == '0'


def link_named(links, label):
    return links.filter(has_text=re.compile(r'^\s*' + re.escape(label) + r'\s*$'))


def assert_tab_order(page, links, forward):
    # Start from the first group in visual order, then use only real key events.
    link_named(links, forward[0]).focus()
    for labels, key in ((forward[1:], 'Tab'), (forward[-2::-1], 'Shift+Tab')):
        for label in labels:
            page.keyboard.press(key)
            assert link_named(links, label).evaluate('e => e === document.activeElement'), (
                key, label, page.evaluate('document.activeElement.tagName + ":" + document.activeElement.getAttribute("href")'),
            )


with sync_playwright() as p:
    for mode in ('native', 'fallback', 'noscript'):
        args = ['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote']
        browser = p.chromium.launch(executable_path='/usr/lib/chromium/chromium', args=args)
        processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
        assert processes and all(Path(f'/proc/{process["id"]}/cgroup').read_text() == scope for process in processes)
        context = browser.new_context(java_script_enabled=mode != 'noscript', reduced_motion='reduce')
        if mode == 'fallback':
            # Exercise native Tab with neither the CSS effect nor its support signal.
            context.add_init_script('''(() => {
                const supports = CSS.supports.bind(CSS);
                CSS.supports = (...args) => args[0] !== 'reading-flow' && supports(...args);
            })();''')

        def route_local(route):
            url = route.request.url
            if not url.startswith(origin + '/'):
                route.abort()
            elif mode == 'fallback' and '/assets/css/layout.css?' in url:
                response = route.fetch(max_redirects=0)
                route.fulfill(response=response, body=re.sub(r'reading-flow\s*:[^;]+;', '', response.text()))
            else:
                route.continue_()

        context.route('**/*', route_local)
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        page.set_viewport_size({'width': 390, 'height': 844})
        page.goto(origin, wait_until='networkidle')
        assert page.evaluate("CSS.supports('reading-flow', 'flex-visual')") == (mode != 'fallback')
        footer = page.locator('.zen-site-footer')
        nav = footer.locator('.zen-footer-navigation')
        custom = footer.locator('.zen-footer-links')
        meta = footer.locator('.zen-footer-meta')
        links = footer.locator('a[href]')
        assert custom.count() == extras
        assert [label.strip() for label in nav.locator('a').all_inner_texts()] == expected
        assert links.count() == len(expected) + extras
        mobile_order = expected + (['Extra'] if extras else [])
        desktop_order = (['Extra'] if extras else []) + expected
        for width in (390, 639, 640, 1440):
            page.set_viewport_size({'width': width, 'height': 844})
            if mode == 'fallback' and extras:
                page.wait_for_function('''desktop => {
                    const first = document.querySelector('.zen-site-footer > div').firstElementChild;
                    return first.classList.contains('zen-footer-links') === desktop;
                }''', arg=width >= 640, timeout=3000)
            assert footer.evaluate('e => document.documentElement.scrollWidth <= innerWidth')
            assert all(item.evaluate('e => getComputedStyle(e).fontSize') == '13px'
                       for item in [meta, *links.all()]), 'Footer metadata and links must use 13px'
            nav_box, meta_box = nav.bounding_box(), meta.bounding_box()
            boxes = [nav_box, custom.bounding_box(), meta_box] if extras else [nav_box, meta_box]
            if width < 640:
                assert all(abs(box['x'] + box['width'] / 2 - width / 2) <= 1 for box in boxes)
                assert all(a['y'] + a['height'] < b['y'] for a, b in zip(boxes, boxes[1:]))
            else:
                assert footer.locator('> div').evaluate('e => getComputedStyle(e).getPropertyValue("reading-flow")') == ('normal' if mode == 'fallback' else 'flex-visual')
                desktop_boxes = boxes[::-1]
                assert all(a['x'] + a['width'] <= b['x'] for a, b in zip(desktop_boxes, desktop_boxes[1:]))
            assert_tab_order(page, links, mobile_order if width < 640 else desktop_order)
        if mode == 'fallback' and extras:
            for width, label, next_label in ((390, 'Extra', expected[-1]), (1440, expected[0], 'Extra')):
                last = link_named(links, label)
                last.focus()
                page.set_viewport_size({'width': width, 'height': 844})
                page.evaluate('() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))')
                assert last.evaluate('e => e === document.activeElement'), 'Resizing must not drop focus'
                page.keyboard.press('Shift+Tab')
                assert link_named(links, next_label).evaluate('e => e === document.activeElement')
        assert not errors, errors
        print(f'PASS footer {mode}: 390/639/640/1440px, menu={expected}, custom={extras}', flush=True)
        browser.close()
