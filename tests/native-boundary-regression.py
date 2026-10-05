"""Native branding/media, restricted code and long-copy regression."""
import json,subprocess,sys
from pathlib import Path
from playwright.sync_api import sync_playwright
r=Path(sys.argv[1]).resolve();assert (r/'runtime/wp-load.php').is_file() and (r/'db/zen.sqlite').is_file(), 'Isolated fixture required';f=json.loads((r/'fixture.json').read_text());o='http://127.0.0.1:18765';results=[]
def cli(*args):
 p=subprocess.run(['php','/opt/test-tools/wordpress/wp-cli.phar','--allow-root','--path='+str(r/'runtime'),*args],capture_output=True,text=True);assert p.returncode==0,p.stderr;return p.stdout.strip()
def check(name,fn):
 try:fn();results.append({'name':name,'passed':True});print('PASS',name,flush=True)
 except Exception as x:results.append({'name':name,'passed':False,'error':str(x)});print('FAIL',name,str(x),flush=True)
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage']);c=b.new_context(viewport={'width':1440,'height':1000});q=c.new_page()
 def branding():
  cli('theme','mod','set','custom_logo',str(f['media'][0]));cli('post','meta','update',str(f['normal']),'_thumbnail_id',str(f['media'][1]));cli('option','update','zen_show_featured_image','1')
  q.goto(o+'/?p='+str(f['normal']),wait_until='networkidle');assert q.locator('.zen-site-brand img').count()==1 and q.locator('.zen-site-brand a').count()==0
  assert q.locator('.zen-featured-image').count()==1 and q.locator('.zen-featured-image').get_attribute('srcset')
  for width in (320,390,768,1440):
   q.set_viewport_size({'width':width,'height':900});q.reload(wait_until='networkidle');assert q.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),width
  cli('option','update','blogname','A very long site identity 中文名称与长标题测试')
  q.set_viewport_size({'width':320,'height':900});q.reload(wait_until='networkidle');assert q.evaluate('document.documentElement.scrollWidth<=innerWidth+1')
  # Detect overlapping visible brand and tool bounds, not just document overflow.
  bounds=q.evaluate('''()=>({brand:document.querySelector('.zen-site-brand').getBoundingClientRect().right,tools:document.querySelector('.zen-header-actions').getBoundingClientRect().left})''');assert bounds['brand']<=bounds['tools'],bounds
  cli('theme','mod','remove','custom_logo');cli('option','update','blogname','Zen Notes');cli('option','update','zen_show_featured_image','0');q.goto(o+'/?p='+str(f['normal']),wait_until='networkidle');assert q.locator('.zen-site-logo').count()==0 and q.locator('.zen-featured-image').count()==0
  cli('post','meta','delete',str(f['normal']),'_thumbnail_id');cli('option','delete','zen_show_featured_image')
 check('native logo title fallback featured image srcset and long-brand mobile geometry',branding)
 def fonts():
  q.set_viewport_size({'width':1440,'height':900})
  for value,family in [('system','system-ui'),('inter','Inter'),('space-grotesk','Space Grotesk')]:
   cli('option','update','zen_font_family',value);q.goto(o+'/?p='+str(f['normal']),wait_until='networkidle');q.evaluate('document.fonts.ready');computed=q.locator('#post-content').evaluate('e=>getComputedStyle(e).fontFamily');assert family in computed,computed
   assert bool(q.locator('link[href*="fonts.googleapis.com"]').count())==(value!='system')
  cli('option','delete','zen_font_family')
 check('all typography choices affect computed frontend font and system disables Google requests',fonts)
 def wide():
  original=cli('post','get',str(f['split']),'--field=post_content');code='<figure class="wp-block-image alignwide"><img src="'+o+'/wp-content/uploads/2026/10/distant.png" width="1200" height="600" alt="wide"></figure>'
  cli('post','update',str(f['split']),'--post_content='+code)
  for width in (320,390,1440):q.set_viewport_size({'width':width,'height':900});q.goto(o+'/?p='+str(f['split']),wait_until='networkidle');assert q.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),width
  cli('post','update',str(f['split']),'--post_content='+original)
 check('native wide media stays centered and inside viewport',wide)
 def restricted():
  cli('option','update','zen_code_head','<script>window.zenRestricted="original";</script>');rc=b.new_context();rc.add_cookies(json.loads((r/'restricted-cookies.json').read_text()));page=rc.new_page();page.goto(o+'/wp-admin/admin.php?page=zen-options',wait_until='networkidle');assert page.locator('#zen_code_head').count()==0
  data=page.locator('form').evaluate('e=>Object.fromEntries(new FormData(e))');data['zen_code_head']='<script>forged()</script>';data['zen_code_body']='<script>forgedBody()</script>';data['zen_footer_text']='restricted save';response=rc.request.post(o+'/wp-admin/options.php',form=data);assert response.status==200
  assert cli('option','get','zen_code_head')=='<script>window.zenRestricted="original";</script>'
  assert cli('option','get','zen_footer_text')=='restricted save';rc.close();cli('option','delete','zen_code_head');cli('option','delete','zen_code_body');cli('option','delete','zen_footer_text')
 check('native manage-options user cannot replace authorized raw code through forged fields',restricted)
 b.close()
(r/'evidence/native-boundary.json').write_text(json.dumps(results,ensure_ascii=False,indent=2));assert all(x['passed'] for x in results),results
