"""Focused UI repairs on a disposable native WordPress fixture.

Run serially inside a bounded cgroup. Uses runtime/, fixture.json, cookies.json.
The optional case argument runs one RED/GREEN behavior slice.
"""
from pathlib import Path
import json
import sys
from playwright.sync_api import sync_playwright

root = Path(sys.argv[1]).resolve()
assert (root / 'runtime/wp-load.php').is_file() and (root / 'db/zen.sqlite').is_file()
fixture = json.loads((root / 'fixture.json').read_text())
origin = 'http://127.0.0.1:18765'
case = sys.argv[2] if len(sys.argv) > 2 else 'all'
output = root / 'evidence'
output.mkdir(exist_ok=True)
results = []
errors = []

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox', '--disable-dev-shm-usage'])
    context = browser.new_context(reduced_motion='reduce')
    context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin) else route.abort())
    page = context.new_page()
    page.on('pageerror', lambda error: errors.append(str(error)))

    def check(name, callback):
        if case not in ('all', name):
            return
        try:
            callback()
            results.append({'name': name, 'passed': True})
            print('PASS', name, flush=True)
        except Exception as error:
            results.append({'name': name, 'passed': False, 'error': str(error)})
            print('FAIL', name, str(error), flush=True)

    def adminbar():
        logged = browser.new_context(reduced_motion='reduce')
        logged.add_cookies(json.loads((root / 'cookies.json').read_text()))
        p = logged.new_page()
        p.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin) else route.abort())
        try:
            for width in (390, 600, 601, 700, 768, 782, 783, 1440):
                p.set_viewport_size({'width': width, 'height': 900})
                p.goto(origin + '/?p=' + str(fixture['normal']), wait_until='networkidle')
                for scroll in (0, 500):
                    p.evaluate('v=>scrollTo(0,v)', scroll)
                    p.wait_for_timeout(50)
                    metrics = p.evaluate('''()=>{let h=document.querySelector('.zen-site-header').getBoundingClientRect(),b=document.querySelector('#wpadminbar').getBoundingClientRect(),t=document.querySelector('#theme-toggle'),r=t.getBoundingClientRect(),hit=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2);return {headerTop:h.top,barBottom:b.bottom,buttonHit:t===hit||t.contains(hit)}}''')
                    assert metrics['headerTop'] >= metrics['barBottom'] - 1 and metrics['buttonHit'], (width, scroll, metrics)
                    # Native background toolbar remains operable, not just visually displaced.
                    p.locator('#search-toggle').click()
                    p.wait_for_timeout(220)
                    assert p.locator('#search-input').evaluate('e=>e===document.activeElement')
                    p.keyboard.press('Escape')
                    p.wait_for_timeout(350)
        finally:
            logged.close()
    check('adminbar', adminbar)

    def toc_anchor():
        for logged_in in (False, True):
            client = browser.new_context(reduced_motion='reduce')
            if logged_in:
                client.add_cookies(json.loads((root / 'cookies.json').read_text()))
            p = client.new_page()
            p.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin) else route.abort())
            try:
                for width in (390, 700, 1440):
                    p.set_viewport_size({'width': width, 'height': 900})
                    p.goto(origin + '/?p=' + str(fixture['normal']), wait_until='networkidle')
                    if width < 1280:
                        p.locator('#header-toc-btn').click()
                        p.wait_for_timeout(350)
                        p.locator('#drawer-toc-nav a').nth(6).click()
                        p.wait_for_timeout(350)
                        target = p.locator('#drawer-toc-nav [aria-current=location]')
                    else:
                        p.locator('#toc-nav a').nth(6).click()
                        p.wait_for_timeout(150)
                        target = p.locator('#toc-nav [aria-current=location]')
                    assert target.get_attribute('href') == p.evaluate('location.hash'), (logged_in, width, target.all_text_contents(), p.evaluate('location.hash'))
                    assert p.evaluate('document.querySelector(location.hash).getBoundingClientRect().top>=document.querySelector(".zen-site-header").getBoundingClientRect().bottom-1')
            finally:
                client.close()
    check('toc-anchor', toc_anchor)

    def metadata():
        for width in (320, 390, 768, 1440):
            page.set_viewport_size({'width': width, 'height': 900})
            for route in ('/', '/?p=' + str(fixture['long'])):
                page.goto(origin + route, wait_until='networkidle')
                for dark in (False, True):
                    page.evaluate('(dark)=>document.documentElement.classList.toggle("dark",dark)', dark)
                    data = page.evaluate('''()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,meta:[...document.querySelectorAll('.zen-post-meta')].map(e=>({left:e.getBoundingClientRect().left,right:e.getBoundingClientRect().right,children:[...e.children].map(c=>{let r=document.createRange();r.selectNodeContents(c);return r.getBoundingClientRect().toJSON()})}))})''')
                    assert data['scroll'] <= width + 1, data
                    for meta in data['meta']:
                        assert all(child['right'] <= meta['right'] + 1 and child['left'] >= meta['left'] - 1 for child in meta['children']), meta
                if width == 390:
                    page.screenshot(path=str(output / ('metadata-home.png' if route == '/' else 'metadata-single.png')), full_page=True)
    check('metadata', metadata)

    def noscript():
        nojs = browser.new_context(java_script_enabled=False)
        p = nojs.new_page()
        try:
            for width in (390, 639, 640, 700, 767, 768):
                p.set_viewport_size({'width': width, 'height': 900})
                p.goto(origin, wait_until='domcontentloaded')
                assert p.locator('.zen-site-header nav a:visible').count() >= 3, width
        finally:
            nojs.close()
    check('noscript', noscript)

    def search():
        for width in (390, 1440):
            page.set_viewport_size({'width': width, 'height': 900})
            page.goto(origin, wait_until='networkidle')
            page.locator('#search-toggle').click()
            page.wait_for_timeout(220)
            page.locator('#search-input').click()
            assert page.locator('#search-modal').is_visible(), 'panel click must not dismiss'
            page.mouse.click(width / 2, 100)
            page.wait_for_timeout(360)
            assert not page.locator('#search-modal').is_visible(), 'panel-adjacent blank area must dismiss'
            assert page.locator('#search-toggle').evaluate('e=>e===document.activeElement')
    check('search', search)

    def toc():
        for width, nav in ((390, '#drawer-toc-nav'), (1440, '#toc-nav')):
            page.set_viewport_size({'width': width, 'height': 900})
            page.goto(origin + '/?p=' + str(fixture['normal']), wait_until='networkidle')
            # Exact repro: enter the next heading band, then return to previous section.
            for offset in (-150, -350):
                page.evaluate('off=>scrollTo(0,document.getElementById("section-5").getBoundingClientRect().top+scrollY+off)', offset)
                page.wait_for_timeout(150)
            current = page.evaluate('''()=>{let hs=[...document.querySelectorAll('#post-content h2,#post-content h3')],top=document.querySelector('.zen-site-header').getBoundingClientRect().bottom+24;return hs.filter(e=>e.getBoundingClientRect().top<=top).at(-1).id}''')
            active = page.locator(nav + ' [aria-current=location]')
            assert active.count() == 1 and active.get_attribute('data-target') == current, (width, active.all_text_contents(), current)
            # Forward motion must also select the same nearest-above-heading contract.
            page.evaluate('scrollTo(0,document.getElementById("section-8").getBoundingClientRect().top+scrollY-105)')
            page.wait_for_timeout(150)
            assert page.locator(nav + ' [aria-current=location]').get_attribute('data-target') == 'section-8'
    check('toc', toc)

    def required():
        logged = browser.new_context()
        logged.add_cookies(json.loads((root / 'cookies.json').read_text()))
        p = logged.new_page()
        try:
            p.goto(origin + '/wp-admin/admin.php?page=zen-options', wait_until='networkidle')
            for key in ('zen_reading_width', 'zen_content_width', 'zen_excerpt_length'):
                control = p.locator('#' + key)
                old = control.input_value()
                p.locator('#zen-layout').evaluate('e=>e.open=false')
                control.evaluate('e=>{e.value="";e.dispatchEvent(new Event("input",{bubbles:true}))}')
                assert not control.evaluate('e=>e.checkValidity()'), key
                assert control.evaluate('e=>{let p=e.parentElement;while(p){if(p.tagName==="DETAILS"&&!p.open)return false;p=p.parentElement}return true}'), key
                control.fill(old)
        finally:
            logged.close()
    check('required', required)

    def audio():
        # PHP's development server omits byte-range support; serve the real local WAV with ranges.
        payload = (root / 'runtime/tone.wav').read_bytes()
        def media(route):
            start, end = 0, len(payload) - 1
            requested = route.request.headers.get('range')
            if requested:
                first, last = requested.removeprefix('bytes=').split('-', 1)
                start = int(first or 0)
                end = min(int(last) if last else end, end)
            headers = {'Content-Type': 'audio/wav', 'Accept-Ranges': 'bytes', 'Content-Length': str(end - start + 1)}
            if requested:
                headers['Content-Range'] = f'bytes {start}-{end}/{len(payload)}'
            route.fulfill(status=206 if requested else 200, headers=headers, body=payload[start:end + 1])
        page.route('**/tone.wav', media)
        page.set_viewport_size({'width': 390, 'height': 900})
        page.goto(origin + '/?p=' + str(fixture['normal']), wait_until='networkidle')
        slider = page.locator('.zen-audio-progress-container')
        slider.scroll_into_view_if_needed()
        data = slider.evaluate('''e=>{let r=e.getBoundingClientRect(),bar=e.querySelector('.zen-audio-progress-bar').getBoundingClientRect();return {width:r.width,height:r.height,barHeight:bar.height}}''')
        assert data['height'] >= 32 and 4 <= data['barHeight'] <= 8, data
        # Click in the expanded transparent area, not on the visible line.
        slider.click(position={'x': data['width'] / 2, 'y': 2})
        page.wait_for_timeout(100)
        value = slider.get_attribute('aria-valuenow')
        assert 45 <= int(value) <= 55, value
        slider.focus()
        page.keyboard.press('Home')
        page.wait_for_timeout(100)
        assert slider.get_attribute('aria-valuenow') == '0'
        page.keyboard.press('End')
        page.wait_for_timeout(100)
        assert slider.get_attribute('aria-valuenow') == '100'
    check('audio', audio)
    browser.close()

assert results, 'unknown case'
result = {'results': results, 'pageerrors': errors}
(output / ('repair-ui-' + case + '.json')).write_text(json.dumps(result, ensure_ascii=False, indent=2))
assert not errors and all(row['passed'] for row in results), result
