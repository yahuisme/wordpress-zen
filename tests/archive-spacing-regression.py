"""Render the real archive template at WordPress boundaries and measure spacing."""
from pathlib import Path
import json, os, subprocess, tempfile
from playwright.sync_api import sync_playwright
root = Path(__file__).resolve().parents[1]
php = '''<?php
function get_header() {}
function get_footer() {}
function the_title() { echo '归档'; }
function esc_html($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function esc_url($v) { return esc_html($v); }
function get_transient($k) {
 $rows = array();
 foreach (array('第一篇文章','第二篇文章','长标题：'.str_repeat('完整的中文与English标题',6),'最后一篇文章') as $i=>$t) {
  $rows[] = array('id'=>$i+1,'title'=>$t,'url'=>'#post-'.$i,'year'=>'2026','date'=>'2026年10月5日');
 }
 return $rows;
}
include TEMPLATE_PATH;
'''.replace('TEMPLATE_PATH', repr(str(root / 'page-archives.php')))
with tempfile.NamedTemporaryFile('w', suffix='.php', dir=os.environ.get('TMPDIR','/root/.hermes/cache/scratch'), delete=False) as file:
    file.write(php)
    path = Path(file.name)
try:
    html = subprocess.check_output(['php', str(path)], text=True)
finally:
    path.unlink()
css = '\n'.join((root / 'assets/css' / name).read_text() for name in ('style.css','reading.css','layout.css'))
results = []
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage'])
    page = browser.new_page()
    for width in (320,390,768,1440):
        page.set_viewport_size({'width':width,'height':900})
        page.set_content('<style>'+css+'</style><main style="padding:16px">'+html+'<article id="home-sentinel" class="zen-post-item">首页列表</article></main>')
        rows = page.locator('.zen-archive-row').evaluate_all('es=>es.map(e=>{let c=getComputedStyle(e),t=e.querySelector("a").getBoundingClientRect(),d=e.querySelector("span").getBoundingClientRect();return {top:parseFloat(c.paddingTop),bottom:parseFloat(c.paddingBottom),height:e.getBoundingClientRect().height,titleRight:t.right,dateLeft:d.left,dateWidth:d.width,dateScroll:e.querySelector("span").scrollWidth}})')
        home = page.locator('#home-sentinel').evaluate('e=>parseFloat(getComputedStyle(e).paddingTop)')
        archive_width = page.locator('.zen-archives-directory').evaluate('e=>e.getBoundingClientRect().width')
        results.append({'width':width,'rows':rows,'homePadding':home,'archiveWidth':archive_width})
    browser.close()
print(json.dumps(results,ensure_ascii=False))
assert all(r['archiveWidth']==min(r['width']-32,680) for r in results), 'Archive inherits article reading width instead of its own index measure'
assert all(r['homePadding']==32 for r in results), 'Archive adjustment leaked into homepage'
assert all(x['top']==12 and x['bottom']==12 for r in results for x in r['rows']), 'Archive rows inherit spacious summary-list padding'
assert all(x['titleRight']<=x['dateLeft'] and x['dateScroll']<=x['dateWidth']+1 for r in results for x in r['rows']), 'Long archive title/date collision'
print('PASS: four widths; compact archive rows and unchanged homepage spacing')
