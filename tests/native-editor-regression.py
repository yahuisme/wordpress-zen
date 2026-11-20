"""Native block editor/reader six-level typography parity; disposable fixture only."""
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
content = '<!-- wp:paragraph --><p>阅读记录 Lead text <strong>重点文字</strong> <em>斜体文字</em></p><!-- /wp:paragraph -->'
for level in range(1, 7):
    content += f'<!-- wp:heading {{"level":{level}}} --><h{level} class="wp-block-heading">阅读记录 Level {level}</h{level}><!-- /wp:heading --><!-- wp:paragraph --><p>阅读记录 Body text</p><!-- /wp:paragraph -->'
content += '<!-- wp:code --><pre class="wp-block-code"><code>printf "阅读记录 code"</code></pre><!-- /wp:code -->'
post = wp('post', 'create', '--post_status=publish', '--post_title=Native editor typography', '--post_content=' + content, '--porcelain')
try:
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/lib/chromium/chromium',
                                     args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
                                     env={**os.environ, 'DBUS_SESSION_BUS_ADDRESS': 'unix:path=/nonexistent', 'DBUS_SYSTEM_BUS_ADDRESS': 'unix:path=/nonexistent'})
        group_path = Path('/proc/self/cgroup').read_text().strip().split('::', 1)[1]
        scope = Path('/sys/fs/cgroup' + group_path).parent
        assert int((scope / 'memory.max').read_text()) <= 4 * 1024**3
        assert (scope / 'memory.swap.max').read_text().strip() == '0'
        common_group = str(scope.relative_to('/sys/fs/cgroup'))
        for process in browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']:
            path = Path('/proc') / str(process['id']) / 'cgroup'
            if path.exists(): assert ('::/' + common_group + '/') in path.read_text()
        context = browser.new_context(viewport={'width': 1440, 'height': 1000})
        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') else route.abort())
        page = context.new_page()
        page.goto(origin + '/?p=' + post, wait_until='networkidle')
        reader = page.locator('#post-content h1,#post-content h2,#post-content h3,#post-content h4,#post-content h5,#post-content h6').evaluate_all('es=>es.map(e=>({tag:e.tagName,font:getComputedStyle(e).fontSize,family:getComputedStyle(e).fontFamily,mt:getComputedStyle(e).marginTop,mb:getComputedStyle(e).marginBottom}))')
        emphasis_script = 'es=>es.map(e=>({tag:e.tagName,synthesis:getComputedStyle(e).fontSynthesis,weight:getComputedStyle(e).fontWeight,style:getComputedStyle(e).fontStyle}))'
        reader_emphasis = page.locator('#post-content strong,#post-content em').evaluate_all(emphasis_script)
        assert {item['tag'] for item in reader_emphasis} == {'STRONG', 'EM'}
        assert all(item['synthesis'] == 'weight style' for item in reader_emphasis), reader_emphasis
        context.add_cookies(json.loads((root / 'cookies.json').read_text()))
        page.goto(origin + '/wp-admin/post.php?post=' + post + '&action=edit', wait_until='networkidle')
        frame = page.frame_locator('iframe[name="editor-canvas"]')
        frame.locator('.wp-block-heading').first.wait_for(timeout=30000)
        page.evaluate('document.querySelector(\'iframe[name="editor-canvas"]\').contentDocument.fonts.ready')
        content_fonts = frame.locator('.wp-block-heading,p.wp-block-paragraph,.wp-block-code code').evaluate_all('es=>es.map(e=>({tag:e.tagName,family:getComputedStyle(e).fontFamily}))')
        assert content_fonts and all(item['family']==reader[0]['family'] for item in content_fonts),content_fonts
        assert page.locator('link[href*="zen-bins-google-code"]').count()==0
        cdp=context.new_cdp_session(page);cdp.send('DOM.enable');cdp.send('CSS.enable');cdp.send('DOM.getDocument',{'depth':-1,'pierce':True})
        rendered=[]
        for selector in ['.wp-block-heading','p.wp-block-paragraph','.wp-block-code code']:
            obj=cdp.send('Runtime.evaluate',{'expression':'document.querySelector(\'iframe[name="editor-canvas"]\').contentDocument.querySelector('+json.dumps(selector)+')'})['result']['objectId']
            node=cdp.send('DOM.requestNode',{'objectId':obj})['nodeId']
            fonts=cdp.send('CSS.getPlatformFontsForNode',{'nodeId':node})['fonts']
            assert any(font['isCustomFont'] and 'TsangerJinKai02' in font['familyName'] for font in fonts),(selector,fonts)
            rendered.append({'selector':selector,'fonts':fonts})
        editor = frame.locator('.wp-block-heading').evaluate_all('es=>es.map(e=>({tag:e.tagName,font:getComputedStyle(e).fontSize,family:getComputedStyle(e).fontFamily,mt:getComputedStyle(e).marginTop,mb:getComputedStyle(e).marginBottom}))')
        assert len(reader) == len(editor) == 6, (reader, editor)
        assert all('TsangerJinKai02' in heading['family'] for heading in reader), reader
        assert reader == editor, (reader, editor)
        editor_emphasis = frame.locator('.wp-block-paragraph strong,.wp-block-paragraph em').evaluate_all(emphasis_script)
        # Core uses 600 in the editor and prose uses 700; both must retain visible emphasis.
        assert len(editor_emphasis) == len(reader_emphasis) == 2
        assert all(item['synthesis'] == 'weight style' for item in editor_emphasis), editor_emphasis
        for reader_item, editor_item in zip(reader_emphasis, editor_emphasis):
            assert reader_item['tag'] == editor_item['tag'] and reader_item['style'] == editor_item['style']
            if reader_item['tag'] == 'STRONG':
                assert int(reader_item['weight']) > 400 and int(editor_item['weight']) > 400
        (root / 'evidence/native-editor.json').write_text(json.dumps({'reader': reader, 'editor': editor, 'reader_emphasis':reader_emphasis, 'editor_emphasis':editor_emphasis, 'content_fonts':content_fonts, 'rendered_fonts':rendered, 'passed': True}, indent=2))
        print('NATIVE_EDITOR_COMPLETE 6 heading levels match reader typography')
        browser.close()
finally:
    wp('post', 'delete', post, '--force')
