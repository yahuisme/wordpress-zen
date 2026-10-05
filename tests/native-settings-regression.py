"""Native Settings API and cross-page acceptance; isolated fixture only."""
import json,sys,subprocess,time
from pathlib import Path
from playwright.sync_api import sync_playwright
r=Path(sys.argv[1]).resolve();assert (r/'runtime/wp-load.php').is_file() and (r/'db/zen.sqlite').is_file(), 'Isolated fixture required';f=json.loads((r/'fixture.json').read_text());o='http://127.0.0.1:18765';e=r/'evidence';results=[]
def cli(*args):
 p=subprocess.run(['php','/opt/test-tools/wordpress/wp-cli.phar','--allow-root','--path='+str(r/'runtime'),*args],capture_output=True,text=True)
 assert p.returncode==0,p.stderr
 return p.stdout.strip()
def option(key):return json.loads(cli('option','get',key,'--format=json'))
def setoption(key,val):return cli('option','update',key,json.dumps(val,ensure_ascii=False),'--format=json')
def check(label,fn):
 try:fn();results.append({'name':label,'passed':True});print('PASS',label,flush=True)
 except Exception as ex:results.append({'name':label,'passed':False,'error':str(ex)});print('FAIL',label,str(ex),flush=True)
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
 c=b.new_context(viewport={'width':1440,'height':1000},reduced_motion='reduce');c.add_cookies(json.loads((r/'cookies.json').read_text()));page=c.new_page();page.on('dialog',lambda d:d.accept())
 def settings():page.goto(o+'/wp-admin/admin.php?page=zen-options',wait_until='networkidle');assert page.locator('#zen-options-form').count()==1
 def save():
  with page.expect_navigation(wait_until='networkidle'):page.locator('#zen_save_top').click()
  assert page.locator('.notice-success,.updated').count()>0,page.locator('body').inner_text()[:1500]
 def preserve():
  legacy={'zen_font_family':'inter','zen_content_width':1080,'zen_copyright_license':'cc-by-sa-4.0','zen_code_head':'<script>window.zenSaved = "\\\\n";</script>','zen_footer_text':'<a href="https://example.test">Saved footer</a>','zen_show_footer_theme_by':1,'zen_show_footer_wordpress':1}
  for key,val in legacy.items():setoption(key,val)
  settings();page.locator('#zen_footer_text').fill('Edited footer');save()
  for key,val in legacy.items():assert str(option(key))==str('Edited footer' if key=='zen_footer_text' else val),(key,option(key),val)
  assert page.locator('#zen-save-state').inner_text()=='没有未保存的修改'
 check('native save preserves legacy fonts width license and collapsed code',preserve)
 def edited():
  settings();page.locator('#zen_reading_width_preset').select_option('800');page.locator('#zen_list_style').select_option('compact');page.locator('#zen_category_ids input[type=checkbox]').evaluate_all('(es,ids)=>es.forEach(e=>{e.checked=ids.includes(e.value);e.dispatchEvent(new Event("change",{bubbles:true}))})',[str(f['categories'][2]),str(f['categories'][0])]);page.locator('#zen_show_site_intro').uncheck();page.locator('#zen_copyright_license').select_option('none');page.locator('#zen_show_reading_count').uncheck();save()
  assert int(option('zen_reading_width'))==800 and option('zen_list_style')=='compact'
  assert set(option('zen_category_ids'))=={f['categories'][0],f['categories'][2]},option('zen_category_ids')
  assert option('zen_show_site_intro')=='0' or option('zen_show_site_intro')==0
  page.goto(o+'/',wait_until='networkidle');assert page.locator('.zen-site-intro').count()==0 and page.locator('.zen-post-excerpt').count()==0
  page.locator('.zen-category-disclosure summary').click();assert page.locator('.zen-category-links a').count()==2
  page.goto(o+'/?p='+str(f['normal']),wait_until='networkidle');assert page.locator('#post-content').evaluate('e=>Math.round(e.getBoundingClientRect().width)')==800,page.locator('#post-content').evaluate('e=>({width:e.getBoundingClientRect().width,var:getComputedStyle(e).getPropertyValue("--zen-reading-width")})')
  assert '版权声明' not in page.locator('main').inner_text() and '浏览次数' not in page.locator('.zen-post-meta').inner_text()
 check('saved reading width list intro categories and license drive frontend',edited)
 def clear():
  settings();page.locator('#zen_footer_text').fill('');[control.uncheck() for control in page.locator('#zen_category_ids input[type=checkbox]').all()];page.locator('#zen-code > summary').click();page.locator('#zen_code_head').fill('');save()
  assert option('zen_footer_text')=='' and option('zen_category_ids')==[] and option('zen_code_head')==''
 check('explicit empty footer categories and authorized code clear',clear)
 def invalid():
  setoption('zen_site_start_date','2020-01-01');settings();data=page.locator('#zen-options-form').evaluate('e=>Object.fromEntries(new FormData(e))');data['zen_reading_width']='599';data['zen_site_start_date']='2024-02-30';data['action']='update';response=c.request.post(o+'/wp-admin/options.php',form=data);assert response.status==200
  assert int(option('zen_reading_width'))==800 and option('zen_site_start_date')=='2020-01-01'
  assert '已保留上次保存值' in response.text()
 check('invalid native submissions retain values and show field errors',invalid)
 def code():
  settings();page.locator('#zen-code > summary').click()
  values={'head':'<script>window.zenPositionHead="head\\n";</script>','body':'<span id="zen-position-body">body</span>','footer':'<script>window.zenPositionFooter="footer";</script>'}
  for pos,value in values.items():page.locator('#zen_code_'+pos).fill(value)
  save()
  for pos,value in values.items():assert option('zen_code_'+pos)==value
  anon=b.new_context();q=anon.new_page();q.goto(o+'/',wait_until='networkidle')
  assert q.evaluate('window.zenPositionHead')=='head\n' and q.evaluate('window.zenPositionFooter')=='footer'
  assert q.locator('body > #zen-position-body').count()==1
  anon.close()
 check('three authorized code positions preserve backslashes and anonymous placement',code)
 def responsive():
  for width in (320,390,768,1024,1440):
   page.set_viewport_size({'width':width,'height':900});settings();page.locator('#zen-code > summary').click()
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),width
   page.evaluate('scrollTo(0,1200)');page.wait_for_timeout(50)
   top=page.locator('.zen-save-bar').evaluate('e=>e.getBoundingClientRect().top');expected=12 if width<=600 else (58 if width<=782 else 44);assert abs(top-expected)<2,(width,top,expected)
   page.screenshot(path=str(e/('admin-'+str(width)+'.png')),full_page=True)
 check('all admin widths remain within viewport and sticky save stays reachable',responsive)
 def dirty():
  page.set_viewport_size({'width':1440,'height':900});settings();old=page.locator('#zen_footer_text').input_value();page.locator('#zen_footer_text').fill('unsaved');assert '有未保存' in page.locator('#zen-save-state').inner_text();page.locator('#zen_footer_text').fill(old);assert '没有未保存' in page.locator('#zen-save-state').inner_text()
  page.locator('#zen-code > summary').click();assert page.locator('#zen-code').evaluate('e=>e.open')
  assert page.locator('.zen-options-nav,.zen-native-links,.zen-saved-links,.zen-options-intro').count()==0
 check('real dirty restoration and clean settings header behave correctly',dirty)
 b.close()
(e/'native-settings.json').write_text(json.dumps(results,ensure_ascii=False,indent=2));assert all(v['passed'] for v in results),results
