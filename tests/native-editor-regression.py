"""Native block editor/reader six-level typography parity; disposable fixture only."""
from pathlib import Path
import json, subprocess, sys
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
content = '<!-- wp:paragraph --><p>Lead text</p><!-- /wp:paragraph -->'
for level in range(1, 7):
    content += f'<!-- wp:heading {{"level":{level}}} --><h{level} class="wp-block-heading">Level {level}</h{level}><!-- /wp:heading --><!-- wp:paragraph --><p>Body text</p><!-- /wp:paragraph -->'
post = wp('post', 'create', '--post_status=publish', '--post_title=Native editor typography', '--post_content=' + content, '--porcelain')
try:
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox', '--disable-dev-shm-usage'])
        group_path = Path('/proc/self/cgroup').read_text().strip().split('::', 1)[1]
        scope = Path('/sys/fs/cgroup' + group_path).parent
        assert int((scope / 'memory.max').read_text()) <= 4 * 1024**3
        assert (scope / 'memory.swap.max').read_text().strip() == '0'
        common_group = str(scope.relative_to('/sys/fs/cgroup'))
        for process in browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']:
            path = Path('/proc') / str(process['id']) / 'cgroup'
            if path.exists(): assert ('::/' + common_group + '/') in path.read_text()
        context = browser.new_context(viewport={'width': 1440, 'height': 1000})
        page = context.new_page()
        page.goto(origin + '/?p=' + post, wait_until='networkidle')
        reader = page.locator('#post-content h1,#post-content h2,#post-content h3,#post-content h4,#post-content h5,#post-content h6').evaluate_all('es=>es.map(e=>({tag:e.tagName,font:getComputedStyle(e).fontSize,mt:getComputedStyle(e).marginTop,mb:getComputedStyle(e).marginBottom}))')
        context.add_cookies(json.loads((root / 'cookies.json').read_text()))
        page.goto(origin + '/wp-admin/post.php?post=' + post + '&action=edit', wait_until='networkidle')
        frame = page.frame_locator('iframe[name="editor-canvas"]')
        frame.locator('.wp-block-heading').first.wait_for(timeout=30000)
        editor = frame.locator('.wp-block-heading').evaluate_all('es=>es.map(e=>({tag:e.tagName,font:getComputedStyle(e).fontSize,mt:getComputedStyle(e).marginTop,mb:getComputedStyle(e).marginBottom}))')
        assert len(reader) == len(editor) == 6, (reader, editor)
        assert reader == editor, (reader, editor)
        (root / 'evidence/native-editor.json').write_text(json.dumps({'reader': reader, 'editor': editor, 'passed': True}, indent=2))
        print('NATIVE_EDITOR_COMPLETE 6 heading levels match reader typography')
        browser.close()
finally:
    wp('post', 'delete', post, '--force')
