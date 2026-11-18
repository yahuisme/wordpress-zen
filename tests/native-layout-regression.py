"""Native layout/startup regression; marked disposable loopback fixture only."""
from pathlib import Path
import json, os, subprocess, sys
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
rows, errors, posts = [], [], []
def check(name, passed, detail=None):
    row = {'name': name, 'passed': bool(passed), 'detail': detail}
    rows.append(row)
    with (root / 'evidence/native-layout.jsonl').open('a') as stream:
        stream.write(json.dumps(row, ensure_ascii=False) + '\n')
    print(('PASS ' if passed else 'FAIL ') + name, flush=True)
content = '<p>Intro paragraph.</p>'
for level in range(1, 7):
    content += f'<!-- wp:heading {{"level":{level}}} --><h{level} id="level-{level}" class="wp-block-heading">Heading {level}</h{level}><!-- /wp:heading --><p>Following text.</p>'
content += '<h2 id="custom-size" style="font-size:40px;color:#aa3344">Explicit style</h2>'
content += '<figure class="wp-block-table"><table><tbody><tr><td>Table</td><td>Data</td></tr></tbody></table></figure><table id="standalone"><tbody><tr><td>Standalone</td></tr></tbody></table>'
for align in ('alignwide', 'alignfull'):
    content += f'<figure id="{align}" class="wp-block-embed is-type-video wp-embed-aspect-16-9 wp-has-aspect-ratio {align}"><div class="wp-block-embed__wrapper"><iframe src="/embed.html" title="Local embed" width="560" height="315"></iframe></div></figure>'
content += ''.join(f'<h2 id="section-{i}">Additional section {i}</h2>' for i in range(30))
try:
    post = wp('post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Native layout checks', '--comment_status=open', '--post_content=' + content, '--porcelain'); posts.append(post)
    plain = wp('post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Plain startup', '--post_content=<p>No headings.</p>', '--porcelain'); posts.append(plain)
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox', '--disable-dev-shm-usage'])
        group_path = Path('/proc/self/cgroup').read_text().strip().split('::', 1)[1]
        scope = Path('/sys/fs/cgroup' + group_path).parent
        assert int((scope / 'memory.max').read_text()) <= 4 * 1024**3
        assert (scope / 'memory.swap.max').read_text().strip() == '0'
        common_group = str(scope.relative_to('/sys/fs/cgroup'))
        membership = []
        for process in browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']:
            path = Path('/proc') / str(process['id']) / 'cgroup'
            if path.exists():
                group = path.read_text().strip()
                assert ('::/' + common_group + '/') in group, (process['id'], group)
                membership.append({'pid': process['id'], 'cgroup': group})
        (root / 'evidence/layout-cgroup.json').write_text(json.dumps(membership, indent=2))
        context = browser.new_context(reduced_motion='reduce')
        page = context.new_page(); page.on('pageerror', lambda error: errors.append(str(error)))
        for width in (320, 390, 768, 1440):
            page.set_viewport_size({'width': width, 'height': 900})
            page.goto(origin + '/?p=' + post, wait_until='networkidle')
            for dark in (False, True):
                page.evaluate('(v)=>document.documentElement.classList.toggle("dark",v)', dark)
                data = page.evaluate('''()=>{const box=e=>{let r=e.getBoundingClientRect(),s=getComputedStyle(e);return {x:r.x,y:r.y,w:r.width,h:r.height,font:parseFloat(s.fontSize),mt:parseFloat(s.marginTop),mb:parseFloat(s.marginBottom)}};let consent=document.querySelector('.comment-form-cookies-consent');return {heading:[1,2,3,4,5,6].map(n=>box(document.querySelector('#level-'+n))),custom:getComputedStyle(document.querySelector('#custom-size')).fontSize,table:box(document.querySelector('.wp-block-table table')),standalone:box(document.querySelector('#standalone')),wide:box(document.querySelector('#alignwide')),full:box(document.querySelector('#alignfull')),reading:box(document.querySelector('#post-content')),checkbox:box(consent.querySelector('input')),label:box(consent.querySelector('label')),inputs:[...document.querySelectorAll('.zen-comment-input')].map(box),overflow:document.documentElement.scrollWidth-innerWidth}}''')
                label = f'{width}/{"dark" if dark else "light"}'
                hs = data['heading']
                check(label + ' complete heading scale', hs[0]['font'] > hs[1]['font'] > hs[2]['font'] > hs[3]['font'] >= hs[4]['font'] >= hs[5]['font'] and all(h['mt'] > 0 and h['mb'] > 0 for h in hs), hs)
                check(label + ' explicit heading style retained', data['custom'] == '40px')
                check(label + ' table spacing owned by figure', data['table']['mt'] == data['table']['mb'] == 0 and data['standalone']['mt'] > 0, data['table'])
                check(label + ' consent checkbox shares first line', abs(data['checkbox']['y'] - data['label']['y']) <= 6 and data['label']['x'] > data['checkbox']['x'], {'checkbox': data['checkbox'], 'label': data['label']})
                check(label + ' comment inputs at least 16px', all(item['font'] >= 16 for item in data['inputs']))
                check(label + ' wide and full native embeds', data['reading']['w'] <= data['wide']['w'] < width and abs(data['wide']['x'] * 2 + data['wide']['w'] - width) <= 1 and abs(data['full']['w'] - width) <= 1 and abs(data['full']['x']) <= 1, data)
                check(label + ' document does not overflow', data['overflow'] <= 1, data['overflow'])
        page.goto(origin, wait_until='networkidle')
        targets = page.locator('nav[aria-label="分页导航"] .page-numbers').evaluate_all('es=>es.map(e=>({w:e.getBoundingClientRect().width,h:e.getBoundingClientRect().height}))')
        check('pagination touch targets', len(targets) >= 2 and all(t['w'] >= 44 and t['h'] >= 44 for t in targets), targets)
        context.close()
        context = browser.new_context(java_script_enabled=False, viewport={'width': 390, 'height': 900})
        page = context.new_page(); page.on('pageerror', lambda error: errors.append(str(error)))
        page.goto(origin + '/?p=' + post, wait_until='networkidle')
        check('no script removes unusable directory reservation', page.locator('#floating-toc-btn').evaluate('e=>e.getBoundingClientRect().height') == 0 and page.locator('#header-toc-btn').evaluate('e=>e.getBoundingClientRect().width') == 0)
        check('no script preserves content and native search', page.locator('#post-content h2').count() > 0 and page.locator('.zen-noscript-search input[type="search"]').is_visible())
        context.close()
        for width in (390, 768, 1440):
            for name, identifier in (('headings', post), ('plain', plain)):
                context = browser.new_context(viewport={'width': width, 'height': 900}, reduced_motion='reduce')
                held = []; context.route('**/js/main.js?*', lambda route: held.append(route))
                page = context.new_page(); page.on('pageerror', lambda error: errors.append(str(error)))
                page.goto(origin + '/?p=' + identifier, wait_until='commit'); page.wait_for_timeout(800)
                assert held, 'Main script must genuinely be held'
                before = page.locator('#post-content').evaluate('e=>e.getBoundingClientRect().top')
                for route in held: route.continue_()
                page.wait_for_load_state('networkidle')
                after = page.locator('#post-content').evaluate('e=>e.getBoundingClientRect().top')
                check(f'{width}/{name} delayed script keeps article position', abs(after-before) <= 1, {'before': before, 'after': after})
                if name == 'headings':
                    trigger = page.locator('#header-toc-btn' if width < 1280 else '#toc-container')
                    check(f'{width}/headings released directory is usable', trigger.is_visible() and page.locator('#toc-nav .toc-link').count() > 0)
                    if width == 390:
                        trigger.click()
                        close = page.locator('#drawer-toc-close')
                        check('drawer starts on the close button', close.evaluate('e=>e===document.activeElement'))
                        page.keyboard.press('Shift+Tab')
                        last = page.locator('#drawer-toc-nav .toc-link').last
                        check('reverse Tab reveals the last drawer link', last.evaluate('''e=>{
                            const scroll = e.closest('#drawer-toc-nav').parentElement;
                            const link = e.getBoundingClientRect(), panel = scroll.getBoundingClientRect();
                            return e === document.activeElement && panel.height > 0 && link.top >= panel.top && link.bottom <= Math.min(panel.bottom, innerHeight);
                        }'''))
                        page.keyboard.press('Tab')
                        check('forward Tab wraps to drawer close', close.evaluate('e=>e===document.activeElement'))
                if name == 'plain':
                    check(f'{width}/plain no unused directory slot', page.locator('#floating-toc-btn').evaluate('e=>e.getBoundingClientRect().height') == 0)
                context.close()
        browser.close()
finally:
    for post_id in posts: wp('post', 'delete', post_id, '--force')
check('no uncaught browser errors', not errors, errors)
(root / 'evidence/native-layout.json').write_text(json.dumps(rows, ensure_ascii=False, indent=2))
failures = [row for row in rows if not row['passed']]
print(f'NATIVE_LAYOUT_COMPLETE {len(rows)} checks, {len(failures)} failures')
assert rows and not failures, failures
