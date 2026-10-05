"""Native standard/compact switching geometry; disposable loopback site only."""
from pathlib import Path
import json,subprocess,sys
from playwright.sync_api import sync_playwright
r=Path(sys.argv[1]).resolve();assert (r/'db/zen.sqlite').is_file();o='http://127.0.0.1:18765';rows=[];failed=[]
def cli(*a):
 p=subprocess.run(['php','/opt/test-tools/wordpress/wp-cli.phar','--allow-root','--path='+str(r/'runtime'),*a],capture_output=True,text=True);assert p.returncode==0,p.stderr;return p.stdout.strip()
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage']);c=b.new_context(viewport={'width':1440,'height':1000});c.add_cookies(json.loads((r/'cookies.json').read_text()));page=c.new_page();q=b.new_page(viewport={'width':1440,'height':1000})
 for font in ['space-grotesk','inter','system']:
  for content in [600,900,1050,1200,1920]:
   for mode in ['standard','compact','standard']:
    page.goto(o+'/wp-admin/admin.php?page=zen-options',wait_until='networkidle');page.locator('#zen_font_family').select_option(font);page.locator('#zen_list_style').select_option(mode);page.locator('#zen-layout > summary').click();page.locator('#zen_content_width').fill(str(content))
    with page.expect_navigation(wait_until='networkidle'):page.locator('#zen_save_top').click()
    assert cli('option','get','zen_list_style')==mode and int(cli('option','get','zen_content_width'))==content
    for width in [320,390,768,1440,2560]:
     q.set_viewport_size({'width':width,'height':900});q.goto(o+'/',wait_until='networkidle')
     d=q.evaluate('''()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,items:[...document.querySelectorAll('.zen-post-item')].map(e=>{let b=e.getBoundingClientRect(),h=e.querySelector('h2').getBoundingClientRect(),x=e.querySelector('.zen-post-excerpt');return {item:b.width,title:h.width,excerpt:x?x.getBoundingClientRect().width:null,left:b.left,right:b.right}})})''')
     error=d['overflow'] or any((mode=='compact' and x['excerpt'] is not None) or (mode=='standard' and (x['excerpt'] is None or abs(x['title']-x['excerpt'])>1)) for x in d['items'])
     row={'font':font,'content':content,'mode':mode,'viewport':width,'measurements':d,'passed':not error};rows.append(row)
     if error:failed.append(row)
   if failed and sys.argv[-1]=='red':break
  if failed and sys.argv[-1]=='red':break
 b.close()
(r/(sys.argv[-1]+'-switch.json')).write_text(json.dumps(rows,ensure_ascii=False,indent=2));print('Cases',len(rows),'failures',len(failed));print(json.dumps(failed[:2],ensure_ascii=False));assert not failed,'standard excerpt is narrower than the title/list after native compact-to-standard save'
