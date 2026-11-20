"""Shared frontend typography and pagination, on a marked native WordPress fixture.

Run: python tests/typography-regression.py /path/to/fixture [--first-only]
The fixture manifest supplies native routes and optional representative surfaces.
"""
from pathlib import Path
import json
import os
import subprocess
import sys
from playwright.sync_api import sync_playwright
from urllib.parse import urlsplit

root = Path(sys.argv[1]).resolve()
assert (root / 'runtime/.zen-isolated-fixture').is_file()
manifest = json.loads((root / 'fixture.json').read_text())
origin = manifest['origin']
assert origin == 'http://127.0.0.1:18765'
cli = ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root', '--path=' + str(root / 'runtime')]
identity = subprocess.run(cli + ['eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),FQDB]);'], capture_output=True, text=True, check=True)
assert json.loads(identity.stdout) == ['local', origin, str(root / 'db/zen.sqlite')]
evidence = root / 'evidence'
evidence.mkdir(exist_ok=True)
rows = []
errors = []

def record(name, data):
    row = {'name': name, **data}
    rows.append(row)
    with (evidence / 'typography.jsonl').open('a') as output:
        output.write(json.dumps(row, ensure_ascii=False) + '\n')

properties = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'color', 'columnGap', 'textDecorationLine', 'textUnderlineOffset']
style_js = '(e,props)=>Object.fromEntries(props.map(p=>[p,getComputedStyle(e)[p]]))'
font_js = '''()=>{
    const expected=getComputedStyle(document.body).fontFamily;
    const seen=new Set(), mismatches=[];
    const walk=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);
    while(walk.nextNode()){
        const node=walk.currentNode,e=node.parentElement;
        if(!node.textContent.trim()||!e||seen.has(e)||e.closest('script,style,noscript,#wpadminbar,.ph'))continue;
        seen.add(e);
        const actual=getComputedStyle(e).fontFamily;
        if(actual!==expected)mismatches.push({tag:e.tagName,class:e.className,text:node.textContent.trim().slice(0,60),actual});
    }
    for(const e of document.querySelectorAll('button,input,textarea,select')){
        if(e.closest('#wpadminbar'))continue;
        const actual=getComputedStyle(e).fontFamily;
        if(actual!==expected)mismatches.push({tag:e.tagName,class:e.className,actual});
    }
    return {expected,checked:seen.size,mismatches};
}'''
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/lib/chromium/chromium', args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'], env={**os.environ, 'DBUS_SESSION_BUS_ADDRESS': 'unix:path=/nonexistent', 'DBUS_SYSTEM_BUS_ADDRESS': 'unix:path=/nonexistent'})
    cgroup = Path('/proc/self/cgroup').read_text()
    group = Path('/sys/fs/cgroup') / cgroup.strip().split('::', 1)[1].lstrip('/')
    assert any((a / 'memory.max').is_file() and (a / 'memory.max').read_text().strip().isdigit() and int((a / 'memory.max').read_text()) <= 4 * 1024**3 and (a / 'memory.swap.max').read_text().strip() == '0' for a in [group, *group.parents])
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{process["id"]}/cgroup').read_text() == cgroup for process in processes)
    (evidence / 'typography-cgroup.json').write_text(json.dumps(processes))
    context = browser.new_context(viewport={'width':390,'height':900}, reduced_motion='reduce', color_scheme='light')
    context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(origin + '/') or route.request.url.startswith('data:') else route.abort())
    page = context.new_page()
    page.on('pageerror', lambda error: errors.append(str(error)))
    cdp = context.new_cdp_session(page)
    cdp.send('DOM.enable'); cdp.send('CSS.enable')
    def actual_fonts(selector):
        cdp.send('DOM.getDocument', {'depth':-1,'pierce':True})
        node = cdp.send('DOM.querySelector', {'nodeId':cdp.send('DOM.getDocument')['root']['nodeId'], 'selector':selector})['nodeId']
        assert node, selector
        return cdp.send('CSS.getPlatformFontsForNode', {'nodeId':node})['fonts']
    if '--resume' in sys.argv and (evidence/'typography.jsonl').is_file():
        completed={row['name'] for row in map(json.loads,(evidence/'typography.jsonl').read_text().splitlines()) if row.get('passed')}
    else:
        completed=set()
        (evidence/'typography.jsonl').write_text('')
    if ('--bookmarks-only' in sys.argv or '--first-only' not in sys.argv) and 'bookmark-avatar' not in completed:
        page.goto(manifest['surfaces']['links'], wait_until='networkidle')
        avatar = page.locator('.zen-link-avatar').first
        avatar.wait_for(state='visible')
        avatar.scroll_into_view_if_needed()
        avatar.evaluate('e=>e.decode()')
        page.mouse.move(0,0)
        initial = avatar.evaluate('(e)=>{const s=getComputedStyle(e);return {radius:s.borderRadius,filter:s.filter,w:e.width,h:e.height}}')
        assert initial['radius']=='50%' and initial['filter']=='grayscale(1)' and initial['w']==initial['h'], initial
        avatar.hover()
        page.wait_for_function('getComputedStyle(document.querySelector(".zen-link-avatar")).filter==="grayscale(0)"')
        page.screenshot(path=str(evidence/'bookmark-color.png'), full_page=True)
        page.mouse.move(0,0)
        page.wait_for_function('getComputedStyle(document.querySelector(".zen-link-avatar")).filter==="grayscale(1)"')
        page.screenshot(path=str(evidence/'bookmark-grayscale.png'), full_page=True)
        record('bookmark-avatar',{'initial':initial,'hover':'grayscale(0)','passed':True})
    widths = [] if '--bookmarks-only' in sys.argv else ([390] if '--first-only' in sys.argv else [320,390,768,1440])
    modes = [('light','light'),('dark','dark'),('auto','dark')]
    routes = {'home':manifest['routes']['home']} if '--first-only' in sys.argv else manifest['routes']
    for width in widths:
        page.set_viewport_size({'width':width,'height':900})
        for mode,system in modes:
            if all(f'{family}/{width}/{mode}' in completed for family in routes): continue
            page.emulate_media(color_scheme=system)
            page.goto(manifest['routes']['home'], wait_until='networkidle')
            page.evaluate('(mode)=>localStorage.setItem("zen-theme-mode",mode)', mode)
            page.reload(wait_until='networkidle');page.evaluate('document.fonts.ready')
            assert page.locator('html').evaluate('e=>e.classList.contains("dark")') == (system=='dark')
            reference = page.locator('.zen-index-heading a')
            assert reference.count()==1 and '全部篇目' in reference.inner_text()
            reference_style = reference.evaluate(style_js, properties)
            reference.hover(); hover_style = reference.evaluate(style_js, properties)
            page.mouse.move(0,0)
            reference_fonts = actual_fonts('.zen-index-heading a')
            assert any(f['isCustomFont'] and 'TsangerJinKai02' in f['familyName'] for f in reference_fonts),reference_fonts
            for family,url in routes.items():
                if f'{family}/{width}/{mode}' in completed: continue
                response=page.goto(url,wait_until='networkidle');assert response.status==200,(family,response.status)
                page.evaluate('document.fonts.ready')
                font_data=page.evaluate(font_js)
                assert font_data['checked']>0 and not font_data['mismatches'],(family,width,mode,font_data)
                assert page.locator('link[href*="zen-bins-google-code"],link[href*="fonts.googleapis.com"]').count()==0
                next_link=page.locator('.zen-pagination-next a')
                assert next_link.count()==1,(family,'seed at least two pages')
                assert next_link.evaluate(style_js,properties)==reference_style,(family,width,mode,next_link.evaluate(style_js,properties),reference_style)
                next_link.hover();assert next_link.evaluate(style_js,properties)==hover_style
                page.mouse.move(0,0)
                used=actual_fonts('.zen-pagination-next a')
                assert any(f['isCustomFont'] and 'TsangerJinKai02' in f['familyName'] for f in used),used
                first_url=page.url
                with page.expect_navigation(wait_until='networkidle'):next_link.click()
                previous=page.locator('.zen-pagination-prev a');assert previous.count()==1
                assert previous.evaluate(style_js,properties)==reference_style
                previous_url=urlsplit(previous.get_attribute('href'));canonical=urlsplit(first_url)
                assert (previous_url.scheme,previous_url.netloc,previous_url.path or '/',previous_url.query)==(canonical.scheme,canonical.netloc,canonical.path or '/',canonical.query),(family,previous_url,canonical)
                for link in page.locator('.zen-pagination a').all():
                    box=link.bounding_box();assert box['width']>=44 and box['height']>=44
                    link.focus();assert link.evaluate('e=>getComputedStyle(e).outlineStyle')!='none'
                assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
                if family=='home':
                    page.locator('.zen-pagination').scroll_into_view_if_needed();page.evaluate('document.activeElement.blur()')
                    page.screenshot(path=str(evidence/f'pagination-{width}-{mode}.png'),full_page=True)
                record(f'{family}/{width}/{mode}',{'font_nodes':font_data['checked'],'pagination_fonts':used,'matched_style':reference_style,'passed':True})
    if '--first-only' not in sys.argv and '--bookmarks-only' not in sys.argv:
        for width in [390,1440]:
            page.set_viewport_size({'width':width,'height':1000})
            for mode in ['light','dark']:
                page.goto(manifest['routes']['home'],wait_until='networkidle')
                page.evaluate('(v)=>localStorage.setItem("zen-theme-mode",v)',mode)
                for family,url in manifest.get('surfaces',{}).items():
                    if f'{family}/{width}/{mode}' in completed: continue
                    response=page.goto(url,wait_until='networkidle')
                    assert response.status==(404 if family=='404' else 200),(family,response.status)
                    page.evaluate('document.fonts.ready')
                    data=page.evaluate(font_js)
                    assert data['checked']>0 and not data['mismatches'],(family,width,mode,data)
                    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'),(family,width,mode)
                    if family=='article':
                        for selector in ['#post-content h2','#post-content p','#post-content pre code','#post-content kbd','.comment-reply-link','.comment-form button[type=submit] span']:
                            used=actual_fonts(selector)
                            assert any(f['isCustomFont'] and 'TsangerJinKai02' in f['familyName'] for f in used),(selector,used)
                        page.screenshot(path=str(evidence/f'article-{width}-{mode}.png'),full_page=True)
                        page.locator('#search-toggle').click()
                        page.locator('#search-input').wait_for(state='visible')
                        search=page.locator('#search-input')
                        assert search.evaluate('e=>getComputedStyle(e).fontFamily')==data['expected']
                        page.keyboard.press('Escape')
                    record(f'{family}/{width}/{mode}',{'font_nodes':data['checked'],'passed':True})
    assert not errors,errors
    browser.close()
all_rows=[json.loads(line) for line in (evidence/'typography.jsonl').read_text().splitlines() if line.strip()]
by_name={row['name']:row for row in all_rows}
assert len(by_name)==len(all_rows), 'Duplicate evidence cases'
if '--first-only' not in sys.argv and '--bookmarks-only' not in sys.argv:
    expected={'bookmark-avatar'} | {f'{family}/{width}/{mode}' for family in manifest['routes'] for width in widths for mode,_ in modes} | {f'{family}/{width}/{mode}' for family in manifest.get('surfaces',{}) for width in [390,1440] for mode in ['light','dark']}
    assert set(by_name)==expected,(expected-set(by_name),set(by_name)-expected)
(evidence/'typography.json').write_text(json.dumps(all_rows,ensure_ascii=False,indent=2))
assert rows or completed
print(f'TYPOGRAPHY_COMPLETE {len(by_name)} unique route/width/mode cases, no font or pagination-style mismatches')
