"""Run on isolated WordPress in a bounded cgroup: <evidence-dir> <category-id> [--rtl] [--without-dir-selector]."""
import json
import re
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

origin = 'http://127.0.0.1:18765'
category_id = int(sys.argv[2])
assert category_id > 0
archive_url = f'{origin}/?cat={category_id}'
out = Path(sys.argv[1])
out.mkdir(parents=True, exist_ok=True)
results = []
rtl = '--rtl' in sys.argv[3:]
without_dir_selector = '--without-dir-selector' in sys.argv[3:]
sign = -1 if rtl else 1
focus_only = '--focus-only' in sys.argv[3:]


def route_local(route):
    if not route.request.url.startswith(origin + '/'):
        route.abort()
    elif without_dir_selector and '/assets/css/layout.css?' in route.request.url:
        response = route.fetch(max_redirects=0)
        # Simulate a browser that drops rules with the unsupported selector.
        route.fulfill(response=response, body=re.sub(r'(?m)^[^{}\n]*:dir\([^)]*\)[^{]*\{[^}]*\}', '', response.text()))
    elif rtl and route.request.is_navigation_request():
        response = route.fetch(max_redirects=0)
        if 'text/html' in response.headers.get('content-type', '') and response.ok:
            document = re.sub(r'<html\b([^>]*)>', lambda match: '<html dir="rtl"' + re.sub(r'\sdir="[^"]*"', '', match[1]) + '>', response.text(), count=1)
            route.fulfill(response=response, body=document)
        else:
            route.fulfill(response=response)
    else:
        route.continue_()


def launch_chromium(playwright):
    cgroup = Path('/proc/self/cgroup').read_text()
    limits = Path('/sys/fs/cgroup') / cgroup.strip().split('::/')[1]
    assert int((limits / 'memory.max').read_text()) <= 4 * 1024**3
    assert (limits / 'memory.swap.max').read_text().strip() == '0'
    browser = playwright.chromium.launch(
        executable_path='/usr/lib/chromium/chromium',
        args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
    )
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{process["id"]}/cgroup').read_text() == cgroup for process in processes)
    return browser


with sync_playwright() as p:
    browser = launch_chromium(p)
    context = browser.new_context(reduced_motion='reduce')
    # Use the canonical URL so the RTL document route also runs on the first archive load.
    canonical = context.request.get(archive_url, max_redirects=0)
    if canonical.status in (301, 302):
        archive_url = canonical.headers['location']
        assert archive_url.startswith(origin + '/')
    page = context.new_page()
    page.route('**/*', route_local)
    page.set_viewport_size({'width': 320, 'height': 900})
    page.goto(archive_url, wait_until='networkidle')
    track = page.locator('.zen-category-track')
    track.evaluate('e => {e.scrollLeft=0;e.dispatchEvent(new Event("scroll"));}')
    assert track.evaluate('e=>e.scrollWidth>e.clientWidth+1')
    for arrow in ('next', 'prev'):
        button = page.locator('.zen-category-' + arrow)
        button.focus()
        steps = track.evaluate('e=>Math.ceil(e.scrollWidth/Math.max(64,e.clientWidth-96))+2')
        for _ in range(steps):
            if button.is_hidden():
                break
            before_scroll = track.evaluate('e=>e.scrollLeft')
            page.keyboard.press('Enter')
            page.wait_for_function('''([start, arrow, sign]) => {
                const track = document.querySelector('.zen-category-track');
                const atEdge = arrow === 'next'
                    ? sign * track.scrollLeft + track.clientWidth >= track.scrollWidth - 1
                    : Math.abs(track.scrollLeft) <= 1;
                // Wait for the scroll handler too, not just the changed offset.
                return (arrow === 'next' ? sign : -sign) * (track.scrollLeft - start) > 0
                    && track.parentElement.querySelector('.zen-category-' + arrow).hidden === atEdge;
            }''', arg=[before_scroll, arrow, sign], timeout=1500)
        assert button.is_hidden(), arrow
        assert track.evaluate('(e, arrow)=>e.contains(document.activeElement)&&document.activeElement===(arrow==="next"?e.querySelector("a:last-child"):e.querySelector("a:first-child"))', arrow), 'Hiding an activated edge arrow must focus the reached category link'
        assert track.evaluate('(e, args)=>args[0]==="next" ? args[1]*e.scrollLeft+e.clientWidth>=e.scrollWidth-1 : Math.abs(e.scrollLeft)<=1',[arrow,sign]), 'Focus transfer must not scroll away from the reached edge'
    results.append({'keyboard_edge_focus': True, 'rtl': rtl, 'passed': True})
    if focus_only:
        browser.close()
        (out/'category-focus.json').write_text(json.dumps(results,indent=2))
        print(json.dumps(results))
        sys.exit(0)
    for width in (320, 390, 768, 1440):
        page.set_viewport_size({'width': width, 'height': 900})
        for dark in (False, True):
            page.goto(origin, wait_until='networkidle')
            assert page.locator('.zen-category-nav').count() == 0
            page.goto(archive_url, wait_until='networkidle')
            page.evaluate('(dark) => document.documentElement.classList.toggle("dark",dark)', dark)
            nav = page.locator('.zen-category-nav')
            links = nav.locator('.zen-category-track > a')
            track = nav.locator('.zen-category-track')
            assert track.evaluate('e => getComputedStyle(e).direction') == ('rtl' if rtl else 'ltr')
            current = nav.locator('[aria-current="page"]').inner_text()
            assert nav.locator('[aria-current="page"]').evaluate('''e => {
                const link = e.getBoundingClientRect();
                const track = e.parentElement.getBoundingClientRect();
                return link.left >= track.left - 1 && link.right <= track.right + 1;
            }'''), 'Current category is initially revealed'
            # JS may initially scroll the active term into view.
            track.evaluate('e => { e.scrollLeft = 0; e.dispatchEvent(new Event("scroll")); }')
            assert len(set(links.evaluate_all('(es)=>es.map(e=>e.getBoundingClientRect().top)'))) == 1
            if track.evaluate('e=>e.scrollWidth>e.clientWidth+1'):
                assert nav.locator('.zen-category-next').is_visible()
                assert not nav.locator('.zen-category-prev').is_visible()
                next_arrow = nav.locator('.zen-category-next').bounding_box()
                track_box = track.bounding_box()
                edge = next_arrow['x'] - track_box['x'] if rtl else next_arrow['x'] + next_arrow['width'] - track_box['x'] - track_box['width']
                assert abs(edge) <= 1, 'Next points toward the inline end'
                assert nav.locator('.zen-category-next span').evaluate('''(e, sign) => {
                    const style = getComputedStyle(e);
                    const flipped = style.transform !== 'none' && new DOMMatrix(style.transform).a < 0;
                    return style.direction === 'ltr' && flipped === (sign < 0);
                }''', sign), 'The chevron is mirrored exactly once in RTL'
                for arrow, step in (('next', sign), ('prev', -sign)):
                    button = nav.locator(f'.zen-category-{arrow}')
                    assert button.is_visible()
                    assert button.evaluate('e=>getComputedStyle(e).opacity==="1" && getComputedStyle(e).pointerEvents!=="none"'), 'Pointer edge transfer must not hide the reverse arrow'
                    max_steps = track.evaluate('e => Math.ceil(e.scrollWidth / Math.max(64, e.clientWidth - 96)) + 1')
                    for _ in range(max_steps):
                        if not button.is_visible():
                            break
                        before_scroll = track.evaluate('e => e.scrollLeft')
                        button.click()
                        page.wait_for_function('''([step, start, sign, arrow]) => {
                            const track = document.querySelector('.zen-category-track');
                            const offset = sign * track.scrollLeft;
                            const atEdge = arrow === 'next'
                                ? offset + track.clientWidth >= track.scrollWidth - 1
                                : offset <= 1;
                            return step * (track.scrollLeft - start) > 0
                                && track.parentElement.querySelector('.zen-category-' + arrow).hidden === atEdge;
                        }''', arg=[step, before_scroll, sign, arrow], timeout=1500)
                    assert not button.is_visible(), (width, rtl, arrow)
                    if arrow == 'next':
                        assert track.evaluate('(e, sign) => sign * e.scrollLeft + e.clientWidth >= e.scrollWidth - 1', sign)
                    else:
                        assert track.evaluate('e => Math.abs(e.scrollLeft) <= 1')
            else:
                assert not nav.locator('.zen-category-arrow:visible').count()
            assert links.count() > 5, 'Use a fixture with more than five categories'
            assert nav.locator('details,summary').count() == 0
            assert all(link.is_visible() for link in links.all())
            assert current in links.all_text_contents() and current != '全部'
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
            for _ in range(links.count() - 1):
                page.keyboard.press('Shift+Tab')
            assert links.first.evaluate('e => e === document.activeElement')
            links.nth(1).click()
            page.wait_for_load_state('networkidle')
            assert page.locator('.zen-category-track > a').all_text_contents() == before
            assert page.locator('.zen-category-nav [aria-current="page"]').inner_text() == before[1]
            page.locator('.zen-category-track > a').first.click()
            page.wait_for_load_state('networkidle')
            assert page.locator('.zen-category-nav').count() == 0
            page.goto(archive_url, wait_until='networkidle')
            page.evaluate('(dark) => document.documentElement.classList.toggle("dark",dark)', dark)
            page.wait_for_timeout(400)
            page.screenshot(path=str(out / f'categories-{width}-{dark}.png'), full_page=True)
            # Even an unusually long unbroken name must remain reachable without page overflow.
            page.locator('.zen-category-track > a').last.evaluate('e => e.textContent = "LongCategoryName".repeat(15)')
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), ('long-name', width)
            results.append({'width': width, 'dark': dark, 'links': len(before), 'passed': True})
    # A long focused item fits the rail, but not the gap between two overlaid controls.
    page.set_viewport_size({'width': 320, 'height': 900})
    links = page.locator('.zen-category-track > a')
    long_link = links.nth(5)
    long_link.evaluate('e => { e.style.minWidth = (e.parentElement.clientWidth - 24) + "px"; }')
    links.nth(4).focus()
    page.keyboard.press('Tab')
    assert long_link.evaluate('''e => {
        const link = e.getBoundingClientRect(), track = e.parentElement.getBoundingClientRect();
        const arrows = e.closest('.zen-category-nav').querySelectorAll('button');
        return e === document.activeElement && link.left >= track.left - 1 && link.right <= track.right + 1
            && [...arrows].every(button => !button.hidden && getComputedStyle(button).opacity === '0')
            && [link.left + 4, link.right - 4].every(x => e.contains(document.elementFromPoint(x, link.top + link.height / 2)));
    }'''), 'Both ends of the focused link are inside the rail and unobstructed'
    # Next remains reachable from the link group and becomes visible on focus.
    for _ in range(5):
        page.keyboard.press('Shift+Tab')
    assert links.first.evaluate('e => e === document.activeElement')
    next_button = page.locator('.zen-category-next')
    # Start one link before the end, then Tab through the last link into Next.
    links.nth(-2).focus()
    page.keyboard.press('Tab')
    assert links.last.evaluate('e => e === document.activeElement')
    page.locator('.zen-category-track').evaluate('(e, sign) => { e.scrollLeft += -40 * sign; e.dispatchEvent(new Event("scroll")); }', sign)
    page.keyboard.press('Tab')
    assert next_button.evaluate('e => e === document.activeElement && getComputedStyle(e).opacity === "1"')
    results.append({'focused_link_clear_and_arrow_reachable': True, 'passed': True})
    # Simulate a fitting list then resize, exercising removal of overflow-only padding and controls.
    page.locator('.zen-category-track').evaluate("e => [...e.children].slice(2).forEach(link => link.remove())")
    page.set_viewport_size({'width': 900, 'height': 900})
    page.wait_for_function('''() => {
        const nav = document.querySelector('.zen-category-nav');
        return !nav.classList.contains('has-overflow') && [...nav.querySelectorAll('button')].every(button => button.hidden);
    }''')
    results.append({'fits_after_resize': True, 'passed': True})
    browser.close()
    # Single-process Chromium needs a fresh launch for a different context.
    browser = launch_chromium(p)
    no_script = browser.new_context(java_script_enabled=False, viewport={'width': 390, 'height': 844})
    no_script.route('**/*', route_local)
    page = no_script.new_page()
    page.goto(origin, wait_until='domcontentloaded')
    assert page.locator('.zen-category-nav').count() == 0
    page.goto(archive_url, wait_until='domcontentloaded')
    nav = page.locator('.zen-category-nav')
    links = nav.locator('.zen-category-track > a')
    assert links.count() > 5 and all(link.is_visible() for link in links.all())
    assert not nav.locator('button:visible').count()
    links.first.focus()
    for _ in range(links.count() - 1):
        page.keyboard.press('Tab')
    assert links.last.evaluate('e => e === document.activeElement && e.parentElement.scrollLeft !== 0')
    results.append({'javascript': False, 'passed': True})
    browser.close()
(out / 'category-navigation.json').write_text(json.dumps(results, ensure_ascii=False, indent=2))
print(json.dumps(results, ensure_ascii=False))
