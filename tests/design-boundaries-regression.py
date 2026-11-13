"""Native local-color inheritance and no-script search; isolated WP only."""
from pathlib import Path
import json
import subprocess
import sys
from playwright.sync_api import sync_playwright

root = Path(sys.argv[1]).resolve()
mode = sys.argv[2] if len(sys.argv) > 2 else 'all'
assert mode in ('all', 'colors', 'noscript')
assert (root / 'runtime/wp-load.php').is_file() and (root / 'db/zen.sqlite').is_file()
origin = 'http://127.0.0.1:18765'
cli = ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root', '--path=' + str(root / 'runtime')]

def wp(*args):
    result = subprocess.run(cli + list(args), capture_output=True, text=True)
    assert result.returncode == 0, result.stderr
    return result.stdout.strip()

identity = json.loads(wp('eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),FQDB]);'))
assert identity == ['local', origin, str(root / 'db/zen.sqlite')], identity
content = '<h2 id="ordinary-heading">Ordinary heading</h2><p>Ordinary text</p>'
for kind in ('cover', 'group'):
    for color, background in (('white', 'black'), ('black', 'white')):
        key = kind + '-' + color
        attrs = {'textColor': color, 'backgroundColor': background}
        classes = f'wp-block-{kind} has-{color}-color has-{background}-background-color has-text-color has-background'
        if kind == 'cover':
            content += f'<!-- wp:cover {json.dumps(attrs)} --><div id="{key}" class="{classes}"><span aria-hidden="true" class="wp-block-cover__background has-{background}-background-color has-background-dim-100 has-background-dim"></span><div class="wp-block-cover__inner-container">'
        else:
            content += f'<!-- wp:group {json.dumps(attrs)} --><div id="{key}" class="{classes}">'
        content += '<!-- wp:heading --><h2 class="wp-block-heading">Inherited heading</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Inherited paragraph</p><!-- /wp:paragraph -->'
        content += '</div></div><!-- /wp:cover -->' if kind == 'cover' else '</div><!-- /wp:group -->'
content += '<h2 id="explicit-heading" class="has-text-color" style="color:#e35b5b">Explicit heading</h2>'
post = wp('post', 'create', '--post_title=Design boundary regression', '--post_status=publish', '--post_content=' + content, '--porcelain')
rows = []
try:
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox', '--disable-dev-shm-usage'])
        context = browser.new_context(viewport={'width': 390, 'height': 900}, reduced_motion='reduce')
        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') or route.request.url.startswith('data:') else route.abort())
        page = context.new_page()
        if mode in ('all', 'colors'):
            page.goto(origin + '/?p=' + post, wait_until='domcontentloaded')
            for dark in (False, True):
                page.evaluate('(dark)=>document.documentElement.classList.toggle("dark",dark)', dark)
                for kind in ('cover', 'group'):
                    for color in ('white', 'black'):
                        key = kind + '-' + color
                        colors = page.locator('#' + key).evaluate('e=>({heading:getComputedStyle(e.querySelector("h2")).color,paragraph:getComputedStyle(e.querySelector("p")).color})')
                        expected = 'rgb(255, 255, 255)' if color == 'white' else 'rgb(0, 0, 0)'
                        rows.append({'name': key, 'dark': dark, **colors, 'passed': colors['heading'] == colors['paragraph'] == expected})
                rows.append({'name': 'explicit-heading', 'dark': dark, 'passed': page.locator('#explicit-heading').evaluate('e=>getComputedStyle(e).color') == 'rgb(227, 91, 91)'})
                rows.append({'name': 'ordinary-heading', 'dark': dark, 'passed': page.locator('#ordinary-heading').evaluate('e=>getComputedStyle(e).color===getComputedStyle(e.closest(".entry-content")).color')})
            page.locator('#cover-white').screenshot(path=str(root / 'evidence/cover-inherited.png'))
        context.close()
        if mode in ('all', 'noscript'):
            context = browser.new_context(java_script_enabled=False, viewport={'width': 390, 'height': 900})
            context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') else route.abort())
            page = context.new_page()
            page.goto(origin + '/', wait_until='domcontentloaded')
            form = page.locator('.zen-noscript-search form[role=search]')
            visible = form.count() == 1 and form.is_visible()
            rows.append({'name': 'noscript-search-visible', 'passed': visible})
            if visible:
                form.locator('input[type=search]').fill('WordPress')
                with page.expect_navigation(wait_until='domcontentloaded'):
                    form.locator('[type=submit]').click()
                rows.append({'name': 'noscript-search-submits', 'passed': 's=WordPress' in page.url and page.locator('.zen-post-item').count() > 0})
                rows.append({'name': 'noscript-no-overflow', 'passed': page.evaluate('document.documentElement.scrollWidth<=innerWidth+1')})
        browser.close()
finally:
    wp('post', 'delete', post, '--force')
(root / ('evidence/design-boundaries-' + mode + '.json')).write_text(json.dumps(rows, indent=2))
print(json.dumps(rows))
assert rows and all(row['passed'] for row in rows), 'Native design boundary failure'
print('DESIGN_BOUNDARIES_COMPLETE', len(rows))
