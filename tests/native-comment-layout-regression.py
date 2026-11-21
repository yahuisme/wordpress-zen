"""Native comment names and preformatted text stay inside the reading column."""
from pathlib import Path
import json
import os
import subprocess
import sys
from playwright.sync_api import sync_playwright

root = Path(sys.argv[1]).resolve()
origin = 'http://127.0.0.1:18765'
assert (root / 'runtime/.zen-isolated-fixture').is_file()
cli = ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root', '--path=' + str(root / 'runtime')]
def wp(*args):
    result = subprocess.run(cli + list(args), capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    return result.stdout.strip()
assert json.loads(wp('eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),FQDB]);')) == ['local', origin, str(root / 'db/zen.sqlite')]
source = Path(__file__).resolve().parents[1] / 'theme/zen/assets/css/style.css'
assert source.read_bytes() == (root / 'runtime/wp-content/themes/zen/assets/css/style.css').read_bytes()
rows, errors = [], []
post = wp('post', 'create', '--post_title=Native comment layout', '--post_status=publish', '--post_content=<p>Comment layout.</p>', '--comment_status=open', '--porcelain')
try:
    parent = '0'
    for depth in range(3):
        parent = wp('comment', 'create', '--comment_post_ID=' + post, '--comment_parent=' + parent,
                    '--comment_author=' + 'Reader' * 35, '--comment_author_email=local@example.invalid',
                    '--comment_content=<p>' + 'https://example.test/long/' * 12 + '</p><pre><code>' + 'abcdefghijklmnopqrstuvwxyz' * 25 + '</code></pre>',
                    '--comment_approved=1', '--porcelain')
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/lib/chromium/chromium', args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'], env={**os.environ, 'DBUS_SESSION_BUS_ADDRESS': 'unix:path=/nonexistent', 'DBUS_SYSTEM_BUS_ADDRESS': 'unix:path=/nonexistent'})
        group = Path('/proc/self/cgroup').read_text()
        control = Path('/sys/fs/cgroup') / group.strip().split('::/', 1)[1]
        assert any((x / 'memory.max').is_file() and (x / 'memory.max').read_text().strip() == '4294967296' and (x / 'memory.swap.max').read_text().strip() == '0' for x in (control, *control.parents))
        processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
        assert processes and all(Path(f'/proc/{item["id"]}/cgroup').read_text() == group for item in processes)
        context = browser.new_context(reduced_motion='reduce')
        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') or route.request.url.startswith('data:') else route.abort())
        page = context.new_page()
        page.on('pageerror', lambda error: errors.append(str(error)))
        for width in (320, 390, 768, 1440):
            page.set_viewport_size({'width': width, 'height': 900})
            response = page.goto(origin + '/?p=' + post, wait_until='networkidle')
            assert response.status == 200
            for dark in (False, True):
                page.evaluate('(dark)=>document.documentElement.classList.toggle("dark",dark)', dark)
                data = page.evaluate('''()=>({overflow:document.documentElement.scrollWidth-innerWidth,cards:[...document.querySelectorAll('.zen-comment-card')].map(e=>{const r=e.getBoundingClientRect(),name=e.querySelector('h4'),pre=e.querySelector('pre'),reply=e.querySelector('.comment-reply-link'),code=e.querySelector('pre code');return {left:r.left,right:r.right,nameWrap:getComputedStyle(name).overflowWrap,nameOverflow:name.scrollWidth-name.clientWidth,preWidth:pre.clientWidth,preScroll:pre.scrollWidth,preOverflow:getComputedStyle(pre).overflowX,code:code.textContent,reply:!!reply&&reply.getBoundingClientRect().width>0}})})''')
                assert data['overflow'] <= 1, (width, dark, data)
                assert len(data['cards']) == 3, data
                for card in data['cards']:
                    assert 0 <= card['left'] < card['right'] <= width, card
                    assert card['nameWrap'] == 'anywhere' and card['nameOverflow'] <= 1, card
                    assert card['preWidth'] > 0 and card['preScroll'] > card['preWidth'] and card['preOverflow'] == 'auto', card
                    assert card['code'] == 'abcdefghijklmnopqrstuvwxyz' * 25 and card['reply'], card
                scrollable = page.locator('.zen-comment-text pre').evaluate_all('es=>es.map(e=>{e.scrollLeft=e.scrollWidth;return e.scrollLeft>0})')
                assert all(scrollable) and len(scrollable)==3, scrollable
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
                row = {'width': width, 'dark': dark, 'passed': True, 'metrics': data}
                rows.append(row)
                with (root / 'evidence/native-comment-layout.jsonl').open('a') as stream:
                    stream.write(json.dumps(row) + '\n')
                print('PASS native long comments', width, 'dark' if dark else 'light', flush=True)
                if width in (390, 1440):
                    page.evaluate('scrollTo(0,0)')
                    page.screenshot(path=str(root / 'evidence' / f'comment-layout-{width}-{dark}.png'), full_page=True)
        browser.close()
finally:
    wp('post', 'delete', post, '--force')
assert not errors, errors
print('NATIVE_COMMENT_LAYOUT_COMPLETE', len(rows), 'cases, no script errors')
