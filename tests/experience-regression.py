"""Basic UX acceptance on a loopback WordPress fixture; no admin writes."""
import json
import sys
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright

origin, manifest = sys.argv[1:3]
assert urlparse(origin).hostname == '127.0.0.1'
f = json.loads(Path(manifest).read_text())
results = []
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox', '--disable-dev-shm-usage'])
    context = browser.new_context(viewport={'width': 1440, 'height': 900}, color_scheme='light')
    context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(origin + '/') else r.abort())
    page = context.new_page()
    errors, requests = [], []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('request', lambda r: requests.append(r.url))

    def check(name, fn):
        try:
            fn()
            results.append({'name': name, 'passed': True})
        except Exception as exc:
            results.append({'name': name, 'passed': False, 'error': str(exc)})

    def visit(key='normal', width=1440):
        page.set_viewport_size({'width': width, 'height': 900})
        page.goto(origin + '/?p=' + str(f[key]), wait_until='networkidle')

    def lazy_media():
        requests.clear()
        visit()
        first = page.locator('#post-content img').first
        distant = page.locator('#post-content img').last
        frame = page.locator('#post-content iframe')
        assert first.get_attribute('loading') != 'lazy'
        assert first.get_attribute('fetchpriority') == 'high'
        assert first.get_attribute('decoding') == 'async'
        assert first.get_attribute('width') and first.get_attribute('height')
        assert first.get_attribute('srcset') and first.get_attribute('sizes')
        assert distant.get_attribute('loading') == 'lazy'
        assert frame.get_attribute('loading') == 'lazy'
        assert not any('/distant' in u or '/embed.html' in u for u in requests), requests
        distant.scroll_into_view_if_needed()
        page.wait_for_function("document.querySelectorAll('#post-content img')[3].naturalWidth > 0")
        frame.scroll_into_view_if_needed()
        page.wait_for_timeout(250)
        assert any('/distant' in u for u in requests)
        assert any('/embed.html' in u for u in requests)

    def smooth_scroll():
        visit()
        assert page.evaluate('getComputedStyle(document.documentElement).scrollBehavior') == 'smooth'
        page.locator('#toc-nav a').nth(1).click()
        page.wait_for_timeout(70)
        early = page.evaluate('scrollY')
        page.wait_for_timeout(1500)
        late = page.evaluate('scrollY')
        assert 0 < early < late, (early, late)
        bounds = page.evaluate("({target:document.querySelector(location.hash).getBoundingClientRect().top,header:document.querySelector('.zen-site-header').getBoundingClientRect().bottom})")
        assert bounds['target'] >= bounds['header'] - 1, bounds
        page.locator('#back-to-top').click()
        page.wait_for_timeout(1500)
        assert page.evaluate('scrollY') == 0
        page.emulate_media(reduced_motion='reduce')
        page.reload(wait_until='networkidle')
        assert page.evaluate('getComputedStyle(document.documentElement).scrollBehavior') == 'auto'
        page.locator('#toc-nav a').nth(1).click()
        page.wait_for_timeout(100)
        assert page.evaluate('scrollY') > 500
        page.locator('#back-to-top').click()
        page.wait_for_timeout(100)
        assert page.evaluate('scrollY') == 0
        page.emulate_media(reduced_motion='no-preference')

    def search_focus():
        visit(width=375)
        page.locator('#mobile-menu-btn').click()
        assert page.evaluate("!!document.activeElement.closest('#mobile-menu')")
        page.keyboard.press('Control+k')
        page.wait_for_timeout(220)
        assert page.evaluate('document.activeElement.id') == 'search-input'
        page.keyboard.press('Escape')
        page.wait_for_timeout(380)
        assert page.evaluate('document.activeElement.id') == 'mobile-menu-btn'
        page.locator('#search-toggle').click()
        page.wait_for_timeout(220)
        assert page.locator('main').get_attribute('inert') is not None
        page.locator('#search-close').focus()
        page.keyboard.press('Shift+Tab')
        assert page.evaluate("!!document.activeElement.closest('#search-modal')")
        page.keyboard.press('Escape')
        page.wait_for_timeout(380)
        assert page.locator('main').get_attribute('inert') is None
        assert page.evaluate('document.activeElement.id') == 'search-toggle'

    def dark_modes():
        visit('split')
        page.evaluate("localStorage.removeItem('zen-theme-mode')")
        page.reload(wait_until='networkidle')
        for expected in ('light', 'dark', 'auto'):
            page.locator('#theme-toggle').click()
            assert page.evaluate('document.documentElement.dataset.themeMode') == expected
            page.reload(wait_until='networkidle')
            assert page.evaluate('document.documentElement.dataset.themeMode') == expected
        page.emulate_media(color_scheme='dark')
        page.wait_for_timeout(100)
        assert page.evaluate("document.documentElement.classList.contains('dark')")
        page.emulate_media(color_scheme='light')
        page.wait_for_timeout(100)
        assert not page.evaluate("document.documentElement.classList.contains('dark')")

    def lightbox():
        visit()
        image = page.locator('#post-content img').first
        image.focus()
        page.keyboard.press('Enter')
        page.wait_for_timeout(150)
        assert page.locator('#lightbox').is_visible()
        assert page.evaluate('document.activeElement.id') == 'lightbox-close'
        page.keyboard.press('Escape')
        assert not page.locator('#lightbox').is_visible()
        assert image.evaluate('(e)=>e===document.activeElement')

    def mobile_toc():
        visit(width=375)
        page.locator('#floating-toc-btn').click()
        page.wait_for_timeout(350)
        assert page.evaluate('document.activeElement.id') == 'drawer-toc-close'
        page.locator('#drawer-toc-nav a').nth(1).click()
        page.wait_for_timeout(1600)
        assert page.locator('#drawer-toc').get_attribute('inert') is not None
        bounds = page.evaluate("({target:document.querySelector(location.hash).getBoundingClientRect().top,header:document.querySelector('.zen-site-header').getBoundingClientRect().bottom})")
        assert bounds['target'] >= bounds['header'] - 1, bounds

    def synced_highlight():
        for key in ('direct', 'synced'):
            visit(key)
            assert page.locator('#post-content code').get_attribute('data-highlighted') == 'yes'

    def audio():
        visit()
        player = page.locator('.zen-audio-player')
        player.scroll_into_view_if_needed()
        page.wait_for_function("document.querySelector('audio').readyState >= 1")
        assert '0:03' in player.locator('.zen-audio-time').inner_text()
        player.locator('button').click()
        page.wait_for_timeout(150)
        assert player.locator('button').get_attribute('aria-label') == '暂停音频'
        player.locator('button').click()
        assert player.locator('button').get_attribute('aria-label') == '播放音频'

    def reading_and_copy():
        visit()
        page.evaluate("document.documentElement.style.scrollBehavior='auto';scrollTo(0,document.documentElement.scrollHeight)")
        page.wait_for_timeout(150)
        assert page.locator('#reading-progress').evaluate('(e)=>parseFloat(e.style.width)') > 99
        code = page.locator('#post-content code')
        code.scroll_into_view_if_needed()
        page.wait_for_function("document.querySelector('#post-content code').dataset.highlighted === 'yes'")
        context.grant_permissions(['clipboard-read', 'clipboard-write'])
        page.locator('.zen-code-copy').click()
        page.wait_for_timeout(100)
        assert page.evaluate('navigator.clipboard.readText()') == code.text_content()
        assert page.locator('.zen-code-copy').get_attribute('aria-label') == '已复制'

    def page_family():
        for route in ('/', '/?s=测试', '/?p=' + str(f['archives']), '/?p=' + str(f['links']), '/?p=' + str(f['page']), '/?p=2147483647'):
            for width in (375, 1440):
                page.set_viewport_size({'width':width, 'height':900})
                response = page.goto(origin + route, wait_until='networkidle')
                assert response.status == (404 if '2147483647' in route else 200), route
                assert page.locator('main').is_visible()
                assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), route
        nojs = browser.new_context(java_script_enabled=False)
        nojs.route('**/*', lambda r: r.continue_() if r.request.url.startswith(origin + '/') else r.abort())
        fallback = nojs.new_page()
        fallback.goto(origin + '/?p=' + str(f['normal']),wait_until='networkidle')
        assert fallback.locator('#post-content').is_visible()
        assert fallback.locator('audio').is_visible()
        assert fallback.locator('audio').get_attribute('controls') is not None
        nojs.close()

    def responsive():
        for width in (320, 375, 768, 1440):
            visit(width=width)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), width

    for name, fn in [('native_lazy_media',lazy_media),('smooth_scroll_and_reduced_motion',smooth_scroll),('search_focus',search_focus),('theme_modes',dark_modes),('lightbox_keyboard',lightbox),('mobile_toc_anchor',mobile_toc),('synced_highlight',synced_highlight),('audio_controls',audio),('reading_progress_and_copy',reading_and_copy),('page_family_and_nojs',page_family),('responsive_media',responsive)]:
        check(name,fn)
    browser.close()
print(json.dumps({'results': results, 'pageerrors': errors}, ensure_ascii=False))
assert all(x['passed'] for x in results) and not errors
