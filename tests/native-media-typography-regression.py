"""Core audio/video player typography; marked loopback WordPress fixture only."""
from pathlib import Path
import json
import os
import subprocess
import sys
from playwright.sync_api import sync_playwright

root = Path(sys.argv[1]).resolve()
origin = 'http://127.0.0.1:18765'
assert (root / 'runtime/.zen-isolated-fixture').is_file()
assert all((root / 'runtime' / name).is_file() for name in ['media-font.wav', 'media-font.webm'])
cli = ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root', '--path=' + str(root / 'runtime')]

def wp(*args):
    result = subprocess.run(cli + list(args), capture_output=True, text=True, check=True)
    return result.stdout.strip()

assert json.loads(wp('eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),FQDB]);')) == ['local', origin, str(root / 'db/zen.sqlite')]
content = '<h2>原生音视频</h2>[audio src="' + origin + '/media-font.wav"][video src="' + origin + '/media-font.webm" width="160" height="90"]<audio controls src="' + origin + '/media-font.wav"></audio>'
post_id = wp('post', 'create', '--post_status=publish', '--post_title=原生播放器字体', '--post_content=' + content, '--porcelain')
rows = []
try:
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/lib/chromium/chromium', args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'], env={**os.environ, 'DBUS_SESSION_BUS_ADDRESS': 'unix:path=/nonexistent', 'DBUS_SYSTEM_BUS_ADDRESS': 'unix:path=/nonexistent'})
        group = Path('/proc/self/cgroup').read_text()
        control = Path('/sys/fs/cgroup') / group.strip().split('::', 1)[1].lstrip('/')
        assert any((a / 'memory.max').is_file() and (a / 'memory.max').read_text().strip().isdigit() and int((a / 'memory.max').read_text()) <= 4 * 1024**3 and (a / 'memory.swap.max').read_text().strip() == '0' for a in [control, *control.parents])
        processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
        assert processes and all(Path(f'/proc/{item["id"]}/cgroup').read_text() == group for item in processes)
        context = browser.new_context(viewport={'width':390,'height':900}, reduced_motion='reduce')
        context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') or route.request.url.startswith('data:') else route.abort())
        page = context.new_page()
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        cdp = context.new_cdp_session(page)
        cdp.send('DOM.enable'); cdp.send('CSS.enable')
        for width in [390,1440]:
            page.set_viewport_size({'width':width,'height':900})
            for mode in ['light','dark']:
                page.goto(origin + '/?p=' + post_id, wait_until='networkidle')
                page.evaluate('(mode)=>localStorage.setItem("zen-theme-mode",mode)',mode)
                page.reload(wait_until='networkidle')
                page.wait_for_selector('.mejs-audio .mejs-duration')
                page.wait_for_selector('.mejs-video .mejs-duration',state='attached')
                page.evaluate('document.fonts.ready')
                assert page.locator('.mejs-container').count()==2
                assert page.locator('.mejs-container .zen-audio-player').count()==0
                assert page.locator('.zen-audio-player').count()==1
                expected=page.locator('body').evaluate('e=>getComputedStyle(e).fontFamily')
                data=page.locator('.entry-content .mejs-container,.entry-content .mejs-container :not(.ph,script,style,source,track)').evaluate_all('es=>es.filter(e=>!e.closest("audio,video")).map(e=>({tag:e.tagName,class:e.className,font:getComputedStyle(e).fontFamily}))')
                assert data and all(item['font']==expected for item in data),(width,mode,expected,[item for item in data if item['font']!=expected])
                rendered=[]
                for kind in ['audio','video']:
                    player=page.locator('.mejs-'+kind)
                    player.scroll_into_view_if_needed();player.hover()
                    button=player.locator('.mejs-playpause-button button')
                    assert button.evaluate('e=>getComputedStyle(e).backgroundImage')!='none'
                    button.click()
                    page.wait_for_function('(kind)=>{const e=document.querySelector(".mejs-"+kind+" "+kind);return !e.paused&&Number.isFinite(e.duration)}',arg=kind)
                    button.click()
                    page.wait_for_function('(kind)=>document.querySelector(".mejs-"+kind+" "+kind).paused',arg=kind)
                    page.wait_for_function('(kind)=>{const e=document.querySelector(".mejs-"+kind+" .mejs-duration");return e&&e.textContent!=="00:00"}',arg=kind)
                    node=cdp.send('DOM.querySelector',{'nodeId':cdp.send('DOM.getDocument',{'depth':-1})['root']['nodeId'],'selector':'.mejs-'+kind+' .mejs-currenttime'})['nodeId']
                    fonts=cdp.send('CSS.getPlatformFontsForNode',{'nodeId':node})['fonts']
                    assert any(font['isCustomFont'] and 'TsangerJinKai02' in font['familyName'] for font in fonts),(kind,fonts)
                    rendered.append({'kind':kind,'fonts':fonts,'native_play_pause':True})
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
                row={'width':width,'mode':mode,'nodes':len(data),'rendered':rendered,'passed':True}
                rows.append(row)
                with (root/'evidence/native-media-typography.jsonl').open('a') as output:output.write(json.dumps(row,ensure_ascii=False)+'\n')
        assert not errors,errors
        browser.close()
finally:
    wp('post','delete',post_id,'--force')
(root/'evidence/native-media-typography.json').write_text(json.dumps(rows,ensure_ascii=False,indent=2))
assert len(rows)==4
print('NATIVE_MEDIA_TYPOGRAPHY_COMPLETE 4 width/mode cases; Core audio/video fonts and ownership preserved')
