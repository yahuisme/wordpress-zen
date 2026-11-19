"""Render the real archive template at WordPress boundaries and measure spacing."""
from pathlib import Path
import json, os, subprocess, tempfile
from playwright.sync_api import sync_playwright
root = Path(__file__).resolve().parents[1] / 'theme' / 'zen'
php = '''<?php
function get_header() {}
function get_footer() {}
function post_password_required() { return false; }
function get_the_title($post = null) { return '归档'; }
function esc_html($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function esc_url($v) { return esc_html($v); }
function get_locale() { return 'zh_CN'; }
function esc_attr($v) { return esc_html($v); }
function add_action(...$args) {}
function zen_get_option($key) { return 'editorial'; }
define('ABSPATH', dirname(TEMPLATE_PATH) . '/');
require ABSPATH . 'inc/template-tags.php';
function get_transient($k) {
 if ($k !== 'zen_archives_public_posts_v3') throw new RuntimeException('Unexpected archive cache key');
 $rows = array();
 foreach (array('第一篇文章','第二篇文章','长标题：'.str_repeat('完整的中文与English标题',6),'最后一篇文章') as $i=>$t) {
  $rows[] = array('id'=>$i+1,'title'=>$t,'url'=>'#post-'.$i,'year'=>'2026','date'=>'2026-10-05');
 }
 return array('zh_CN:' => $rows);
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
        page.set_content('<style>'+css+'</style><main style="padding:16px">'+html+'<div class="zen-post-list"><article class="zen-post-item">第一篇</article><article id="home-sentinel" class="zen-post-item">首页列表</article></div></main>')
        rows = page.locator('.zen-archive-row').evaluate_all('es=>es.map(e=>{let c=getComputedStyle(e),t=e.querySelector("a").getBoundingClientRect(),d=e.querySelector("time").getBoundingClientRect();return {top:parseFloat(c.paddingTop),bottom:parseFloat(c.paddingBottom),height:e.getBoundingClientRect().height,titleRight:t.right,titleBottom:t.bottom,dateLeft:d.left,dateTop:d.top,dateWidth:d.width,dateScroll:e.querySelector("time").scrollWidth}})')
        home = page.locator('#home-sentinel').evaluate('e=>parseFloat(getComputedStyle(e).paddingTop)')
        archive_width = page.locator('.zen-archives-directory').evaluate('e=>e.getBoundingClientRect().width')
        results.append({'width':width,'rows':rows,'homePadding':home,'archiveWidth':archive_width})
    browser.close()
print(json.dumps(results,ensure_ascii=False))
assert all(len(r['rows']) == 4 for r in results), 'Archive fixture must render all four rows'
assert all(r['archiveWidth']==min(r['width']-32,680) for r in results), 'Archive inherits article reading width instead of its own index measure'
assert all(r['homePadding']==(16 if r['width']<768 else 22) for r in results), 'Archive adjustment leaked into homepage'
assert all(x['top']==12 and x['bottom']==12 for r in results for x in r['rows']), 'Archive rows inherit spacious summary-list padding'
assert all((x['titleBottom']<=x['dateTop'] if r['width']<=480 else x['titleRight']<=x['dateLeft']) and x['dateScroll']<=x['dateWidth']+1 for r in results for x in r['rows']), 'Long archive title/date collision'
print('PASS: four widths; compact archive rows and unchanged homepage spacing')
