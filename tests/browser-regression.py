"""Read-only browser checks against an isolated WordPress fixture.

Usage: python browser-regression.py URL MANIFEST [CASE]
MANIFEST contains numeric post IDs: normal (h2/h3/image), split (no headings),
longtitle (long unbroken title), archives, links (long URL introduction).
Run Chromium serially inside the host's verified test memory limit.
"""
import json
import os
import sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

origin, manifest_path = sys.argv[1:3]
assert urlparse(origin).hostname in ('127.0.0.1', 'localhost'), 'Isolated loopback site required'
fixture = json.loads(Path(manifest_path).read_text())
case = sys.argv[3] if len(sys.argv) > 3 else 'all'
results = []

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path=os.environ.get('ZEN_CHROMIUM', '/usr/lib/chromium/chromium'), args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'])
    group = Path('/proc/self/cgroup').read_text()
    control = Path('/sys/fs/cgroup') / group.strip().split('::/', 1)[1]
    ancestors = [p for p in (control, *control.parents) if (p / 'memory.max').is_file()]
    assert any((limit := (p / 'memory.max').read_text().strip()).isdigit()
               and int(limit) <= 4294967296 and (p / 'memory.swap.max').read_text().strip() == '0'
               for p in ancestors)
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{p["id"]}/cgroup').read_text() == group for p in processes)
    context = browser.new_context(viewport={'width': 1440, 'height': 900}, reduced_motion='reduce')
    context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') else route.abort())
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def visit(key, width=1440):
        page.set_viewport_size({'width': width, 'height': 900})
        page.goto(origin + '/?p=' + str(fixture[key]), wait_until='networkidle')

    def check(name, callback):
        if case not in ('all', name):
            return
        try:
            callback()
            results.append({'name': name, 'passed': True})
        except AssertionError as error:
            results.append({'name': name, 'passed': False, 'error': str(error)})

    def image_name():
        visit('normal')
        image = page.locator('#post-content img').first
        assert image.get_attribute('alt') in image.get_attribute('aria-label'), image.aria_snapshot()

    def back_to_top():
        visit('split')
        button = page.locator('#back-to-top')
        page.keyboard.press('Shift+Tab')
        assert page.evaluate('document.activeElement.id') != 'back-to-top', 'Hidden button entered keyboard sequence'
        visit('normal')
        page.evaluate('scrollTo(0, 500)')
        page.wait_for_timeout(100)
        assert button.evaluate('(e)=>!e.disabled && e.tabIndex === 0'), 'Visible button unavailable'
        button.click()
        page.wait_for_timeout(150)
        assert page.evaluate('scrollY') == 0
        page.evaluate('scrollTo(0, 500)')
        page.wait_for_function('!document.getElementById("back-to-top").disabled')
        button.focus()
        page.keyboard.press('Enter')
        assert page.evaluate('document.activeElement.id') == 'main-content'
        assert page.evaluate('scrollY') == 0

    def lightbox_touch_target():
        visit('normal', 320)
        image = page.locator('#post-content img[role="button"]').first
        image.click()
        close = page.locator('#lightbox-close')
        assert close.evaluate('''e => {
            const r = e.getBoundingClientRect();
            return r.width >= 44 && r.height >= 44 && e.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
        }''')
        page.keyboard.press('Escape')
        assert image.evaluate('e => e === document.activeElement')

    def empty_toc():
        for width in (375, 1279, 1280, 1300, 1359, 1360, 1440):
            visit('split', width)
            assert not page.locator('#header-toc-btn').is_visible(), f'Empty TOC at {width}'
            assert not page.locator('#toc-container').is_visible(), f'Empty desktop TOC at {width}'

    def heading_offset():
        visit('normal', 2560)
        page.locator('#toc-nav a').nth(1).click()
        page.wait_for_timeout(200)
        bounds = page.evaluate('''()=>({top:document.querySelector(location.hash).getBoundingClientRect().top,
            bottom:document.querySelector('.zen-site-header').getBoundingClientRect().bottom})''')
        assert bounds['top'] >= bounds['bottom'], bounds

    def toc_space():
        views = [('normal', width) for width in (375, 1300, 1359, 1360, 1440, 1920, 2560)]
        if 'custom' in fixture:
            views.extend(('custom', width) for width in (375, 1440))
        for key, width in views:
            visit(key, width)
            desktop = page.locator('#toc-container')
            mobile = page.locator('#header-toc-btn')
            assert page.locator('button[aria-controls="drawer-toc"]').count() == 1, f'Expected one drawer trigger on {key} at {width}'
            reading_width = page.evaluate("parseInt(getComputedStyle(document.documentElement).getPropertyValue('--zen-reading-width'), 10)")
            desktop_expected = width >= max(1200, reading_width + 560)
            assert desktop.is_visible() == desktop_expected and mobile.is_visible() != desktop_expected, f'Wrong TOC at {width}'
            assert page.locator('#drawer-toc').evaluate('e => getComputedStyle(e).boxShadow === "none"'), 'Closed drawer shadow leaks into viewport'
            if width == 375:
                page.evaluate('scrollTo(0, 500)')
                page.wait_for_function('scrollY > 300')
                assert mobile.evaluate('''e => {
                    const r = e.getBoundingClientRect();
                    return r.width >= 44 && r.height >= 44 && e.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
                }'''), 'The sticky header TOC must stay tappable while reading'
                mobile.click()
                assert mobile.get_attribute('aria-expanded') == 'true'
                assert page.locator('#drawer-toc-close').evaluate('e => e === document.activeElement')
                page.keyboard.press('Escape')
                page.wait_for_function('document.activeElement.id === "header-toc-btn"')
                assert mobile.get_attribute('aria-expanded') == 'false'
            if desktop.is_visible():
                bounds = page.evaluate('''()=>({right:document.querySelector('#post-content').getBoundingClientRect().right,
                    toc:document.querySelector('#toc-container').getBoundingClientRect().left,
                    edge:document.querySelector('#toc-container').getBoundingClientRect().right,width:innerWidth})''')
                assert bounds['toc'] >= bounds['right'] and bounds['edge'] <= bounds['width'], bounds

    def long_content():
        for key in ('longtitle', 'archives', 'links'):
            for width in (320, 375, 768, 1440):
                visit(key, width)
                measured = page.evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})')
                assert measured['scroll'] <= measured['width'] + 1, (key, measured)

    check('image_name', image_name)
    check('back_to_top', back_to_top)
    check('lightbox_touch_target', lightbox_touch_target)
    check('empty_toc', empty_toc)
    check('heading_offset', heading_offset)
    check('toc_space', toc_space)
    check('long_content', long_content)
    browser.close()
print(json.dumps({'results': results, 'pageerrors': errors}, ensure_ascii=False))
assert results and all(result['passed'] for result in results) and not errors
