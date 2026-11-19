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
function __($v, $domain = '') { return $v; }
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
css = '\n'.join((root / 'assets/css' / name).read_text() for name in ('style.css','reading.css','layout.css','directories.css'))
results = []
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/lib/chromium/chromium',
        args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
        env={**os.environ, 'DBUS_SESSION_BUS_ADDRESS':'unix:path=/nonexistent', 'DBUS_SYSTEM_BUS_ADDRESS':'unix:path=/nonexistent'})
    group = Path('/proc/self/cgroup').read_text()
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{proc["id"]}/cgroup').read_text() == group for proc in processes), 'Chromium escaped the test cgroup'
    page = browser.new_page()
    for width in (320,390,768,1440):
        page.set_viewport_size({'width':width,'height':900})
        gutter = 28 if width <= 640 else 32
        page.set_content(f'<style>{css}</style><main style="padding-inline:{gutter}px;margin:auto;max-width:992px">'+html+'<div class="zen-post-list"><article class="zen-post-item">第一篇</article><article id="home-sentinel" class="zen-post-item">首页列表</article></div></main>')
        rows = page.locator('.zen-archive-row').evaluate_all('es=>es.map(e=>{let c=getComputedStyle(e),t=e.querySelector("a").getBoundingClientRect(),d=e.querySelector("time").getBoundingClientRect();return {align:c.alignItems,gap:parseFloat(c.columnGap),top:parseFloat(c.paddingTop),bottom:parseFloat(c.paddingBottom),height:e.getBoundingClientRect().height,titleLeft:t.left,dateRight:d.right,dateWidth:d.width,dateScroll:e.querySelector("time").scrollWidth}})')
        home = page.locator('#home-sentinel').evaluate('e=>parseFloat(getComputedStyle(e).paddingTop)')
        archive_width = page.locator('.zen-archives-directory').evaluate('e=>e.getBoundingClientRect().width')
        results.append({'width':width,'rows':rows,'homePadding':home,'archiveWidth':archive_width,'expectedWidth':min(width,992)-2*gutter})
    browser.close()
print(json.dumps(results,ensure_ascii=False))
assert all(len(r['rows']) == 4 for r in results), 'Archive fixture must render all four rows'
assert all(r['archiveWidth']==r['expectedWidth'] for r in results), 'Archive index does not use full main content width'
assert all(r['homePadding']>12 for r in results), 'Archive adjustment leaked into neighboring list'
assert all(x['top']==12 and x['bottom']==12 for r in results for x in r['rows']), 'Archive rows inherit spacious summary-list padding'
assert all(x['dateRight']<=x['titleLeft'] and x['dateScroll']<=x['dateWidth']+1 for r in results for x in r['rows']), 'Long archive title/date collision'
assert all(x['align']=='baseline' and x['gap']==(8 if r['width']<=640 else 12) for r in results for x in r['rows']), 'Archive grid alignment or column gap changed'
print('PASS: four widths; compact archive rows and unchanged homepage spacing')
