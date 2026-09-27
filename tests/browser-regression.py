"""Read-only browser checks against an isolated WordPress fixture.

Usage: python browser-regression.py URL MANIFEST [CASE]
MANIFEST contains numeric post IDs: normal (h2/h3/image), split (no headings),
longtitle (long unbroken title), archives, links (long URL introduction).
Run Chromium serially inside the host's verified test memory limit.
"""
import json
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
    browser = playwright.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox', '--disable-dev-shm-usage'])
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

    def empty_toc():
        for width in (375, 1279, 1280, 1300, 1359, 1360, 1440):
            visit('split', width)
            assert not page.locator('#floating-toc-btn').is_visible(), f'Empty TOC at {width}'
            assert not page.locator('#toc-container').is_visible(), f'Empty desktop TOC at {width}'

    def heading_offset():
        visit('normal', 2560)
        page.locator('#toc-nav a').nth(1).click()
        page.wait_for_timeout(200)
        bounds = page.evaluate('''()=>({top:document.querySelector(location.hash).getBoundingClientRect().top,
            bottom:document.querySelector('.zen-site-header').getBoundingClientRect().bottom})''')
        assert bounds['top'] >= bounds['bottom'], bounds

    def toc_space():
        for width in (375, 1300, 1440, 1920, 2560):
            visit('normal', width)
            desktop = page.locator('#toc-container')
            mobile = page.locator('#floating-toc-btn')
            assert desktop.is_visible() != mobile.is_visible(), f'Exactly one TOC required at {width}'
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
    check('empty_toc', empty_toc)
    check('heading_offset', heading_offset)
    check('toc_space', toc_space)
    check('long_content', long_content)
    browser.close()
print(json.dumps({'results': results, 'pageerrors': errors}, ensure_ascii=False))
assert results and all(result['passed'] for result in results) and not errors
