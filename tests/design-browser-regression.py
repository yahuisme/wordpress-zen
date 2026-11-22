"""Targeted TDD browser reproduction on an isolated WordPress article."""
import json,sys
from pathlib import Path
from playwright.sync_api import sync_playwright
r=Path(sys.argv[1]); f=json.loads((r/'fixture.json').read_text()); result={}; failed=[]
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/lib/chromium/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--single-process','--no-zygote'])
 group=Path('/proc/self/cgroup').read_text();control=Path('/sys/fs/cgroup')/group.strip().split('::',1)[1].lstrip('/')
 assert any((a/'memory.max').is_file() and (a/'memory.max').read_text().strip().isdigit() and int((a/'memory.max').read_text())<=4*1024**3 and (a/'memory.swap.max').read_text().strip()=='0' for a in (control,*control.parents))
 processes=b.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
 assert processes and all(Path(f'/proc/{x["id"]}/cgroup').read_text()==group for x in processes)
 c=b.new_context(viewport={'width':390,'height':844},reduced_motion='reduce');page=c.new_page()
 page.route('**/*',lambda route:route.continue_() if route.request.url.startswith('http://127.0.0.1:18765/') else route.abort())
 page.goto('http://127.0.0.1:18765/?p='+str(f['normal']),wait_until='networkidle')
 overlaps=[]
 for y in (300,500,700,900,1200):
  page.evaluate('y=>scrollTo(0,y)',y);page.wait_for_timeout(50)
  overlaps.append(page.evaluate('''()=>{let e=document.querySelector('#floating-toc-btn');let b=e.getBoundingClientRect();let covered='';let w=document.createTreeWalker(document.querySelector('#post-content'),NodeFilter.SHOW_TEXT);while(w.nextNode()){let n=w.currentNode;for(let i=0;i<n.length;i++){let r=document.createRange();r.setStart(n,i);r.setEnd(n,i+1);let t=r.getBoundingClientRect();if(t.width&&t.height&&t.left<b.right&&t.right>b.left&&t.top<b.bottom&&t.bottom>b.top)covered+=n.textContent[i];}}return {position:getComputedStyle(e).position,covered}}'''))
 result['toc']=overlaps
 if any(x['covered'] for x in overlaps):failed.append('directory trigger overlaps article glyphs')
 if any(x['position']=='fixed' for x in overlaps):failed.append('directory trigger must not float in the reading line')
 page.evaluate('scrollTo(0,0)')
 meta=page.evaluate('''()=>{let e=document.querySelector('.zen-article-header .zen-post-meta,main>header>div');return [...e.children].map(x=>({text:x.textContent,height:x.getBoundingClientRect().height}))}''')
 result['meta']=meta
 if any(x['height']>26 for x in meta):failed.append('short metadata fragments wrap internally')
 page.set_viewport_size({'width':1440,'height':1000})
 result['reader']=page.locator('#post-content p').first.evaluate('e=>{const c=getComputedStyle(e);return {size:c.fontSize,line:c.lineHeight,font:c.fontFamily}}')
 c.add_cookies(json.loads((r/'cookies.json').read_text()));page.goto('http://127.0.0.1:18765/wp-admin/post.php?post='+str(f['normal'])+'&action=edit',wait_until='networkidle');page.wait_for_function('document.querySelector("iframe[name=editor-canvas]")?.contentDocument?.querySelector(".wp-block-paragraph,.wp-block-freeform p")',timeout=30000)
 result['editor']=[frame.evaluate('''()=>{let e=document.querySelector('.wp-block-freeform p,.wp-block-paragraph');if(!e)return null;let c=getComputedStyle(e);return {size:c.fontSize,line:c.lineHeight,font:c.fontFamily}}''') for frame in page.frames]
 # Compare the same viewport with the live shared typography, not retired 18px/32px values.
 if result['reader'] not in result['editor']:failed.append('editor typography does not match reader')
 b.close()
result['failed']=failed
out=r/'evidence';out.mkdir(exist_ok=True);(out/(sys.argv[2]+'.json')).write_text(json.dumps(result,ensure_ascii=False,indent=2));print(json.dumps(result,ensure_ascii=False));assert not failed,failed
