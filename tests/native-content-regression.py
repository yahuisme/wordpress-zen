"""Focused native block acceptance; disposable loopback WordPress, external cgroup required."""
import json, subprocess, sys
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(sys.argv[1]).resolve()
origin = 'http://127.0.0.1:18765'
assert (root / 'runtime/wp-load.php').is_file() and (root / 'db/zen.sqlite').is_file()
fixture = json.loads((root / 'fixture.json').read_text())
slice_name = sys.argv[2] if len(sys.argv) > 2 else 'all'
assert slice_name in ('all', 'css', 'media', 'details')
cli = ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root', '--path=' + str(root / 'runtime')]

def wp(*args):
    result = subprocess.run(cli + list(args), capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    return result.stdout.strip()

identity = json.loads(wp('eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),DB_DIR.DB_FILE]);'))
assert identity == ['local', origin, str(root / 'db/zen.sqlite')], identity
ratios = ['21-9', '18-9', '16-9', '4-3', '1-1', '9-16', '1-2']
image = fixture['media'][0]
image_url = wp('eval', f'echo wp_get_attachment_url({image});')
content = '''<h2 id="visible-first">Visible first</h2>
<ul id="audit-ul"><li>First</li><li>Second<ul><li>Nested</li></ul></li></ul>
<ol id="audit-ol" start="3"><li>Third</li><li>Fourth</li></ol>
<ol id="audit-alpha" type="A"><li>Alphabetic</li></ol>
<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#visible-last">Default button</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
<details id="audit-details"><summary>Hidden by default</summary><h2 id="inside-details">Hidden chapter</h2><p>Hidden content</p></details>'''
content += f'<!-- wp:image {{"id":{image},"sizeSlug":"large","linkDestination":"none","lightbox":{{"enabled":true}}}} --><figure class="wp-block-image size-large"><img src="{image_url}" alt="Core lightbox" class="wp-image-{image}"/></figure><!-- /wp:image -->'
content += '<p><img id="ordinary-image" src="' + image_url + '" alt="Ordinary image"></p>'
content += '[audio src="' + origin + '/tone.wav"]<audio id="ordinary-audio" controls src="/tone.wav"></audio>'
for ratio in ratios:
    content += f'<figure id="audit-embed-{ratio}" class="wp-block-embed is-type-video wp-embed-aspect-{ratio} wp-has-aspect-ratio"><div class="wp-block-embed__wrapper"><iframe src="/embed.html" title="Ratio {ratio}" width="560" height="315"></iframe></div></figure>'
content += '<h2 id="visible-last">Visible last</h2><p>End</p>'
post = wp('post', 'create', '--post_type=post', '--post_status=publish', '--post_title=Native block acceptance', '--post_content=' + content, '--porcelain')
wp('eval', f'if(function_exists("pll_set_post_language"))pll_set_post_language({post},pll_default_language());')
rows, failures, errors = [], [], []
def check(name, condition, detail=None):
    row = {'name': name, 'passed': bool(condition)}
    if detail is not None:
        row['detail'] = detail
    rows.append(row)
    print(('PASS ' if condition else 'FAIL ') + name, flush=True)
    if not condition:
        failures.append(row)

try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox', '--disable-dev-shm-usage'])
        page = browser.new_page(viewport={'width': 1440, 'height': 1000}, reduced_motion='reduce')
        page.on('pageerror', lambda error: errors.append(str(error)))
        for width in ((320, 1440) if slice_name in ('all', 'css') else ()):
            page.set_viewport_size({'width': width, 'height': 1000})
            page.goto(origin + '/?p=' + post, wait_until='networkidle')
            for dark in (False, True):
                page.evaluate('(v)=>document.documentElement.classList.toggle("dark",v)', dark)
                page.wait_for_timeout(350)
                label = f'{width}/{"dark" if dark else "light"}'
                markers = page.locator('#audit-ul,#audit-ol').evaluate_all('es=>es.map(e=>getComputedStyle(e).listStyleType)')
                check(label + ' list markers', markers == ['disc', 'decimal'], markers)
                kinds = page.locator('#audit-alpha,#audit-ul ul').evaluate_all('es=>es.map(e=>getComputedStyle(e).listStyleType)')
                check(label + ' native list variants', kinds == ['circle', 'upper-alpha'], kinds)
                button = page.locator('.wp-block-button__link').evaluate('e=>{let s=getComputedStyle(e);return {color:s.color,bg:s.backgroundColor,line:s.textDecorationLine}}')
                check(label + ' readable button', button['color'] != button['bg'] and button['line'] == 'none', button)
                for ratio in ratios:
                    geometry = page.locator('#audit-embed-' + ratio).evaluate('e=>{let w=e.querySelector(".wp-block-embed__wrapper").getBoundingClientRect(),i=e.querySelector("iframe").getBoundingClientRect();return {width:w.width,height:w.height,top:i.top-w.top,bottom:i.bottom-w.bottom}}')
                    numerator, denominator = map(int, ratio.split('-'))
                    check(label + ' embed ' + ratio, abs(geometry['height'] - geometry['width'] * denominator / numerator) < 1 and abs(geometry['top']) < 1 and abs(geometry['bottom']) < 1, geometry)
                check(label + ' no overflow', page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'))
        page.goto(origin + '/?p=' + post, wait_until='networkidle')
        if slice_name in ('all', 'media'):
            check('core audio has one owner', page.locator('.mejs-container').count() == 1 and page.locator('.zen-audio-player').count() == 1 and page.locator('.mejs-container .zen-audio-player').count() == 0)
            check('ordinary audio keeps Zen player', page.locator('#ordinary-audio + .zen-audio-player').count() == 1)
            core_image = page.locator('.wp-lightbox-container img')
            check('core lightbox trigger not rewritten', core_image.get_attribute('role') != 'button')
            core_image.scroll_into_view_if_needed()
            core_image.click()
            page.wait_for_timeout(400)
            check('core image opens no Zen overlay', page.locator('#lightbox').evaluate('e=>e.classList.contains("hidden")'))
            check('core image overlay works', page.locator('.wp-lightbox-overlay.active').count() == 1)
            page.keyboard.press('Escape')
            page.wait_for_timeout(400)
            ordinary = page.locator('#ordinary-image')
            ordinary.scroll_into_view_if_needed()
            ordinary.click()
            page.wait_for_timeout(150)
            check('ordinary image keeps Zen lightbox', page.locator('#lightbox').evaluate('e=>!e.classList.contains("hidden")'))
            page.keyboard.press('Escape')
        if slice_name in ('all', 'details'):
            page.set_viewport_size({'width': 390, 'height': 900})
            page.reload(wait_until='networkidle')
            page.locator('#header-toc-btn').click()
            page.wait_for_timeout(350)
            page.locator('#drawer-toc-nav a[href="#inside-details"]').click()
            page.wait_for_timeout(400)
            check('directory expands hidden target', page.locator('#audit-details').evaluate('e=>e.open'))
            check('expanded heading is current', page.locator('#drawer-toc-nav a[href="#inside-details"]').get_attribute('aria-current') == 'location')
        browser.close()
finally:
    wp('post', 'delete', post, '--force')
check('no script errors', not errors, errors)
(root / ('evidence/native-content-' + slice_name + '.json')).write_text(json.dumps(rows, ensure_ascii=False, indent=2))
assert not failures, failures
print(f'NATIVE_CONTENT_COMPLETE {len(rows)} checks')
