"""Native audio cancellation, TOC keyboard focus and nonblocking highlight loading."""
from pathlib import Path
import json
import re
import subprocess
import sys
import wave
from playwright.sync_api import sync_playwright

root = Path(sys.argv[1]).resolve()
selection = sys.argv[2] if len(sys.argv) > 2 else 'all'
assert selection in ('all', 'audio', 'audio-ltr', 'audio-rtl', 'toc', 'highlight', 'highlight-failure')
assert (root / 'runtime/wp-load.php').is_file() and (root / 'db/zen.sqlite').is_file()
origin = 'http://127.0.0.1:18765'
cli = ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root', '--path=' + str(root / 'runtime')]
def wp(*args):
    result = subprocess.run(cli + list(args), capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    return result.stdout.strip()
assert json.loads(wp('eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),FQDB]);')) == ['local', origin, str(root / 'db/zen.sqlite')]
media = root / 'evidence/native-interaction.wav'
with wave.open(str(media), 'wb') as stream:
    stream.setparams((1, 2, 8000, 0, 'NONE', 'not compressed'))
    stream.writeframes(b'\x00\x00' * 16000)
content = '<h2 id="first">First chapter</h2><p><a href="#middle">First link</a></p>' + '<p>Native scrolling content.</p>' * 30
content += '<h2 id="middle">Middle chapter</h2><p><a id="middle-link" href="#end">Next native focus target</a></p><pre><code class="language-js">const quiet = 42;</code></pre><audio id="native-audio" controls preload="none" src="/native-interaction.wav"></audio><h2 id="end">End</h2>'
post = wp('post', 'create', '--post_title=Native interaction regression', '--post_status=publish', '--post_content=' + content, '--porcelain')
rows = []
errors = []
def record(name, passed, detail=None):
    rows.append({'name': name, 'passed': bool(passed), 'detail': detail})
try:
    with sync_playwright() as playwright:
        group = Path('/proc/self/cgroup').read_text()
        control = Path('/sys/fs/cgroup') / group.strip().split('::/', 1)[1]
        ancestors = [p for p in (control, *control.parents) if (p / 'memory.max').is_file()]
        assert any((p / 'memory.max').read_text().strip() == '4294967296' and (p / 'memory.swap.max').read_text().strip() == '0' for p in ancestors)
        for case in (('audio', 'audio-ltr', 'audio-rtl', 'toc', 'highlight', 'highlight-failure') if selection == 'all' else (selection,)):
            browser = playwright.chromium.launch(executable_path='/usr/lib/chromium/chromium', args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'])
            processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
            assert processes and all(Path(f'/proc/{p["id"]}/cgroup').read_text() == group for p in processes)
            context = browser.new_context(viewport={'width': 390, 'height': 900}, reduced_motion='reduce')
            held = []
            def route_request(route):
                url = route.request.url
                if case == 'audio-rtl' and route.request.is_navigation_request():
                    response = route.fetch()
                    document = re.sub(r'<html\b([^>]*)>', lambda m: '<html dir="rtl"' + re.sub(r'\sdir="[^"]*"', '', m[1]) + '>', response.text(), count=1)
                    route.fulfill(response=response, body=document)
                elif case in ('audio-ltr', 'audio-rtl') and '/native-interaction.wav' in url:
                    payload = media.read_bytes()
                    start, end = 0, len(payload) - 1
                    requested_range = route.request.headers.get('range')
                    if requested_range:
                        first, last = requested_range.removeprefix('bytes=').split('-', 1)
                        start = int(first or 0)
                        end = min(int(last) if last else end, end)
                    headers = {'Accept-Ranges': 'bytes', 'Content-Type': 'audio/wav'}
                    if requested_range:
                        headers['Content-Range'] = f'bytes {start}-{end}/{len(payload)}'
                    route.fulfill(status=206 if requested_range else 200, headers=headers, body=payload[start:end+1])
                elif case == 'highlight-failure' and '/highlight.min.js' in url:
                    route.abort()
                elif (case == 'highlight' and '/highlight.min.js' in url) or '/native-interaction.wav' in url:
                    held.append(route)
                elif url.startswith(origin + '/') or url.startswith('data:'):
                    route.continue_()
                else:
                    route.abort()
            context.route('**/*', route_request)
            page = context.new_page()
            page.on('pageerror', lambda error: errors.append(str(error)))
            page.goto(origin + '/?p=' + post, wait_until='commit' if case == 'highlight' else 'domcontentloaded')
            page.wait_for_selector('#search-toggle')
            page.wait_for_timeout(200)
            if case in ('audio-ltr', 'audio-rtl'):
                player = page.locator('#native-audio + .zen-audio-player')
                player.locator('button').click()
                page.wait_for_function('document.querySelector("#native-audio").readyState>=1')
                page.locator('#native-audio').evaluate('e=>e.pause()')
                slider = player.locator('.zen-audio-progress-container')
                assert slider.evaluate('e=>getComputedStyle(e).direction') == case.removeprefix('audio-')
                for x, expected in ((0.25, 0.25), (0.75, 0.75)):
                    box = slider.bounding_box()
                    slider.click(position={'x': box['width']*x, 'y':box['height']/2})
                    ratio = page.locator('#native-audio').evaluate('e=>e.currentTime/e.duration')
                    expected = 1-expected if case == 'audio-rtl' else expected
                    record(case + f' click {x} follows visible fill', abs(ratio-expected)<0.04, ratio)
            elif case == 'highlight':
                page.locator('#search-toggle').click()
                page.wait_for_timeout(220)
                record('search usable while highlighter response pending', page.locator('#search-toggle').get_attribute('aria-expanded') == 'true')
                page.keyboard.press('Escape')
                page.wait_for_timeout(400)
                for route in held:
                    route.continue_()
                page.wait_for_load_state('domcontentloaded')
                page.locator('pre').scroll_into_view_if_needed()
                page.wait_for_function('document.querySelector("pre code").dataset.highlighted === "yes"')
                record('late highlighter enhances code', page.locator('pre code.hljs').count() == 1)
                record('copy control remains available', page.locator('.zen-code-copy').is_visible())
            elif case == 'highlight-failure':
                page.locator('#search-toggle').click()
                page.wait_for_timeout(220)
                record('search survives unavailable highlighter', page.locator('#search-toggle').get_attribute('aria-expanded') == 'true')
                page.keyboard.press('Escape')
                page.wait_for_timeout(400)
                page.locator('pre').scroll_into_view_if_needed()
                record('plain code and copy survive unavailable library', page.locator('pre code').inner_text() == 'const quiet = 42;' and page.locator('.zen-code-copy').is_visible())
            elif case == 'toc':
                page.locator('#header-toc-btn').click()
                page.wait_for_timeout(350)
                page.locator('#drawer-toc-nav a[href="#middle"]').focus()
                page.keyboard.press('Enter')
                page.wait_for_timeout(400)
                record('chapter selection focuses destination', page.evaluate('location.hash==="#middle" && document.activeElement.id==="middle"'))
                page.keyboard.press('Tab')
                record('next Tab follows selected chapter', page.evaluate('document.activeElement.id==="middle-link"'))
                page.locator('#header-toc-btn').click()
                page.wait_for_timeout(350)
                page.keyboard.press('Escape')
                page.wait_for_timeout(350)
                record('Escape restores directory trigger', page.evaluate('document.activeElement.id==="header-toc-btn"'))
            else:
                button = page.locator('#native-audio + .zen-audio-player .zen-audio-btn')
                button.click()
                page.wait_for_timeout(80)
                pending = page.locator('#native-audio').evaluate('e=>!e.paused&&e.readyState===0')
                button.click()
                page.wait_for_timeout(120)
                record('pending native play was cancelled', pending and page.locator('#native-audio').evaluate('e=>e.paused'))
                record('AbortError keeps one custom player', page.locator('#native-audio').evaluate('e=>getComputedStyle(e).display==="none"&&e.nextElementSibling.getBoundingClientRect().height>0'))
                for route in held:
                    route.fulfill(status=200, content_type='audio/wav', body=media.read_bytes())
                # A separate real format failure must leave only the native fallback.
                page.evaluate('''()=>{const a=document.querySelector('#native-audio');a.src='data:audio/wav;base64,bm90LWF1ZGlv';a.load();}''')
                # The native error event itself may already remove the custom owner.
                page.wait_for_function('getComputedStyle(document.querySelector("#native-audio")).display!=="none"')
                record('real media failure has one native fallback', page.locator('#native-audio').evaluate('e=>getComputedStyle(e).display!=="none"&&(!e.nextElementSibling||!e.nextElementSibling.classList.contains("zen-audio-player")||e.nextElementSibling.getBoundingClientRect().height===0)'))
            browser.close()
finally:
    wp('post', 'delete', post, '--force')
record('no unexpected script errors', not errors, errors)
(root / ('evidence/native-interaction-' + selection + '.json')).write_text(json.dumps(rows, indent=2))
print(json.dumps(rows))
assert rows and all(row['passed'] for row in rows), 'Native interaction regression'
print('NATIVE_INTERACTION_COMPLETE', len(rows))
