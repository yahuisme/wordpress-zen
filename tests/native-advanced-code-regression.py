"""Isolated native settings save, category help spacing and early Google tag loading."""
import json
import sys
from pathlib import Path

from playwright.sync_api import TimeoutError, sync_playwright

fixture = Path(sys.argv[1]).resolve()
assert (fixture / 'runtime' / 'wp-load.php').is_file()
assert (fixture / 'db' / 'zen.sqlite').is_file(), 'Disposable loopback fixture required'
origin = 'http://127.0.0.1:18765'
settings_url = origin + '/wp-admin/admin.php?page=zen-options'
tag_url = 'https://www.googletagmanager.com/gtag/js?id=G-ZENTEST001'
early_marker = '<!-- zen:early -->\n'
head = f'''<!-- Google tag -->
<script async src="{tag_url}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){{dataLayer.push(arguments);}}
  gtag('js', new Date());
  gtag('config', 'G-ZENTEST001');
</script>'''
body = '<span id="zen-code-fixture-body" hidden></span>'
footer = r'<script>window.zenCodeFixtureFooter = "fixture\nfooter";</script>'
fields = {'head': head, 'body': body, 'footer': footer}

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        executable_path='/usr/lib/chromium/chromium',
        args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
    )
    group = Path('/proc/self/cgroup').read_text()
    scope = Path('/sys/fs/cgroup') / group.strip().split('::/', 1)[1]
    assert (scope / 'memory.max').read_text().strip() == '4294967296'
    assert (scope / 'memory.swap.max').read_text().strip() == '0'
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{p["id"]}/cgroup').read_text() == group for p in processes)
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    cookies = json.loads((fixture / 'cookies.json').read_text())
    context.add_cookies(cookies)
    page = context.new_page()
    page.set_default_timeout(6_000)
    dialogs, requested, pending_css = [], [], []
    hold_css = False

    def route_resource(route):
        url = route.request.url
        if url == tag_url:
            requested.append(url)
            route.fulfill(content_type='application/javascript', body='window.zenTestTagLoader = true;')
        elif url.startswith(origin + '/'):
            if hold_css and route.request.resource_type == 'stylesheet':
                pending_css.append(route)
            else:
                route.continue_()
        else:
            route.abort()

    def dismiss(dialog):
        dialogs.append(dialog.type)
        dialog.dismiss()

    context.route('**/*', route_resource)
    page.on('dialog', dismiss)

    def settings():
        response = page.goto(settings_url)
        assert response.ok and page.url.startswith(settings_url)
        assert page.locator('#zen-options-form').count() == 1
        assert page.locator('#zen-code > summary').is_visible()
        page.locator('#zen-code > summary').click()

    def save():
        with page.expect_navigation():
            page.locator('#zen_save_top').click()
        assert page.locator('.notice-success,.updated').count() > 0
        assert not dialogs, dialogs

    settings()
    gaps = {}
    for width in (320, 390, 768, 1440):
        page.set_viewport_size({'width': width, 'height': 900})
        gaps[width] = page.locator('#zen-categories-help').evaluate('''help => {
            const labels = [...help.previousElementSibling.querySelectorAll('label')];
            if (!labels.length) throw new Error('Category fixture is empty');
            return help.getBoundingClientRect().top - Math.max(...labels.map(e => e.getBoundingClientRect().bottom));
        }''')
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), width

    for position, text in fields.items():
        page.locator(f'#zen_code_{position}').fill(text)
    save()
    settings()
    for position, text in fields.items():
        assert page.locator(f'#zen_code_{position}').input_value() == text, position
    assert not requested, 'Saved tracking code must not execute in wp-admin'

    context.clear_cookies()
    page.goto(origin + '/')
    tag = page.locator('head script[src*="/gtag/js"]')
    assert tag.count() == 1 and requested == [tag_url]
    assert tag.evaluate('''tag => [...document.head.querySelectorAll('link[rel="stylesheet"]')].some(
        style => style.compareDocumentPosition(tag) & Node.DOCUMENT_POSITION_FOLLOWING
    )'''), 'Ordinary saved head snippets must retain their original order'
    print('PASS unmarked saved head code retains native head order', flush=True)

    context.add_cookies(cookies)
    settings()
    page.locator('#zen_code_head').fill(early_marker + head)
    save()
    settings()
    assert page.locator('#zen_code_head').input_value() == early_marker + head
    assert requested == [tag_url], 'Opted-in tracking code must not execute in wp-admin'
    context.clear_cookies()
    hold_css = True
    page.goto(origin + '/', wait_until='commit')
    configured = "window.zenTestTagLoader === true && window.dataLayer?.some(x => x[0] === 'config' && x[1] === 'G-ZENTEST001')"
    try:
        page.wait_for_function(configured, timeout=3_000)
        early = True
    except TimeoutError:
        early = False
    finally:
        hold_css = False
        assert pending_css, 'No stylesheet was held; the early-load check is invalid'
        for route in pending_css:
            route.continue_()
    page.wait_for_load_state('load')
    page.wait_for_function(configured)
    assert requested == [tag_url, tag_url], requested
    assert page.locator('head script[src*="/gtag/js"]').count() == 1
    assert page.locator('body > #zen-code-fixture-body').count() == 1
    assert page.evaluate('window.zenCodeFixtureFooter') == 'fixture\nfooter'
    assert not page.locator('body').evaluate("e => e.classList.contains('logged-in')")
    print('PASS native save/reload and anonymous head, body-start and footer execution', flush=True)

    context.add_cookies(cookies)
    settings()
    page.locator('#zen_code_head').fill('')
    save()
    context.clear_cookies()
    page.goto(origin + '/')
    assert page.locator('head script[src*="/gtag/js"]').count() == 0
    assert requested == [tag_url, tag_url], requested
    print('PASS native clearing removes the Google tag', flush=True)
    browser.close()

assert early and all(0 <= gap <= 8 for gap in gaps.values()), (
    f'Google tag started before held CSS: {early}; category help gaps in px: {gaps}'
)
print(f'PASS Google tag starts before CSS completes; category help gaps in px: {gaps}')
