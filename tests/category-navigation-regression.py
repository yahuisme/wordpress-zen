"""Category navigation on isolated WordPress; run inside a bounded cgroup."""
import json
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

origin = 'http://127.0.0.1:18765'
out = Path(sys.argv[1])
out.mkdir(parents=True, exist_ok=True)
results = []


def launch_chromium(playwright):
    browser = playwright.chromium.launch(
        executable_path='/usr/lib/chromium/chromium',
        args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
    )
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    cgroup = Path('/proc/self/cgroup').read_text()
    assert processes and all(Path(f'/proc/{process["id"]}/cgroup').read_text() == cgroup for process in processes)
    return browser


with sync_playwright() as p:
    browser = launch_chromium(p)
    context = browser.new_context(reduced_motion='reduce')
    page = context.new_page()
    page.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin) else route.abort())
    for width in (320, 390, 768, 1440):
        page.set_viewport_size({'width': width, 'height': 900})
        for dark in (False, True):
            page.goto(origin, wait_until='networkidle')
            page.evaluate('(dark) => document.documentElement.classList.toggle("dark",dark)', dark)
            nav = page.locator('.zen-category-nav')
            links = nav.locator('.zen-category-track > a')
            track = nav.locator('.zen-category-track')
            upper = page.locator('.zen-site-intro')
            if not upper.count():
                upper = page.locator('.zen-site-header')
            upper_box = upper.bounding_box()
            before_track = track.bounding_box()['y'] - (upper_box['y'] + upper_box['height'])
            after_track = track.evaluate('''e => e.parentElement.getBoundingClientRect().bottom
                - e.getBoundingClientRect().bottom - parseFloat(getComputedStyle(e.parentElement).borderBottomWidth)''')
            assert abs(before_track - after_track) <= 1, ('homepage category spacing', width, before_track, after_track)
            assert len(set(links.evaluate_all('(es)=>es.map(e=>e.getBoundingClientRect().top)'))) == 1
            if track.evaluate('e=>e.scrollWidth>e.clientWidth+1'):
                assert nav.locator('.zen-category-next').is_visible()
                assert not nav.locator('.zen-category-prev').is_visible()
                for _ in range(20):
                    if not nav.locator('.zen-category-next').is_visible():
                        break
                    nav.locator('.zen-category-next').click()
                    page.wait_for_timeout(50)
                assert not nav.locator('.zen-category-next').is_visible()
                assert track.evaluate('e=>e.scrollLeft+e.clientWidth>=e.scrollWidth-1')
                for _ in range(20):
                    if not nav.locator('.zen-category-prev').is_visible():
                        break
                    nav.locator('.zen-category-prev').click()
                    page.wait_for_timeout(50)
                assert track.evaluate('e=>e.scrollLeft<=1')
            else:
                assert not nav.locator('.zen-category-arrow:visible').count()
            assert links.count() > 5, 'Use a fixture with more than five categories'
            assert nav.locator('details,summary').count() == 0
            assert all(link.is_visible() for link in links.all())
            assert nav.locator('[aria-current="page"]').inner_text() == '全部'
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), width
            before = links.all_text_contents()
            # Keyboard navigation reveals offscreen categories without losing focus.
            links.first.focus()
            for _ in range(links.count() - 1):
                page.keyboard.press('Tab')
            assert links.last.evaluate('e=>e===document.activeElement')
            last = links.last.bounding_box()
            bounds = track.bounding_box()
            assert last['x'] >= bounds['x'] - 1
            assert last['x'] + last['width'] <= bounds['x'] + bounds['width'] + 1
            links.first.focus()
            links.nth(1).click()
            page.wait_for_load_state('networkidle')
            assert page.locator('.zen-category-track > a').all_text_contents() == before
            assert page.locator('.zen-category-nav [aria-current="page"]').inner_text() == before[1]
            page.locator('.zen-category-track > a').first.click()
            page.wait_for_load_state('networkidle')
            page.evaluate('(dark) => document.documentElement.classList.toggle("dark",dark)', dark)
            page.wait_for_timeout(400)
            page.screenshot(path=str(out / f'categories-{width}-{dark}.png'), full_page=True)
            # Even an unusually long unbroken name must remain reachable without page overflow.
            page.locator('.zen-category-track > a').last.evaluate('e => e.textContent = "LongCategoryName".repeat(15)')
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), ('long-name', width)
            results.append({'width': width, 'dark': dark, 'links': len(before),
                            'spacing': [before_track, after_track],
                            'intro': page.locator('.zen-site-intro').count() == 1, 'passed': True})
    browser.close()
    # Single-process Chromium needs a fresh launch for a different context.
    browser = launch_chromium(p)
    no_script = browser.new_context(java_script_enabled=False, viewport={'width': 390, 'height': 844})
    no_script.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') else route.abort())
    page = no_script.new_page()
    page.goto(origin, wait_until='domcontentloaded')
    links = page.locator('.zen-category-track > a')
    assert links.count() > 5 and all(link.is_visible() for link in links.all())
    results.append({'javascript': False, 'passed': True})
    browser.close()
(out / 'category-navigation.json').write_text(json.dumps(results, ensure_ascii=False, indent=2))
print(json.dumps(results, ensure_ascii=False))
