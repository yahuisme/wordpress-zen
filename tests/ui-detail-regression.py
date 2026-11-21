"""Regression for first-paint mode, readable emphasis and guestbook spacing."""
from pathlib import Path
import json, os, sys
from playwright.sync_api import sync_playwright
root=Path(sys.argv[1]).resolve();f=json.loads((root/'fixture.json').read_text());origin=f['origin']
assert origin=='http://127.0.0.1:18765' and (root/'runtime/.zen-isolated-fixture').is_file()
case=sys.argv[2] if len(sys.argv)>2 else 'all';rows=[]
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/lib/chromium/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--single-process','--no-zygote'],env={**os.environ,'DBUS_SESSION_BUS_ADDRESS':'unix:path=/nonexistent','DBUS_SYSTEM_BUS_ADDRESS':'unix:path=/nonexistent'})
 group=Path('/proc/self/cgroup').read_text();control=Path('/sys/fs/cgroup')/group.strip().split('::',1)[1].lstrip('/')
 assert any((a/'memory.max').is_file() and (a/'memory.max').read_text().strip()=='4294967296' and (a/'memory.swap.max').read_text().strip()=='0' for a in [control,*control.parents])
 assert all(Path(f'/proc/{x["id"]}/cgroup').read_text()==group for x in b.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo'])
 c=b.new_context(reduced_motion='reduce',color_scheme='dark',java_script_enabled=case!='archive-noscript');c.route('**/*',lambda r:r.continue_() if r.request.url.startswith(origin+'/')or r.request.url.startswith('data:')else r.abort());page=c.new_page()
 def check(label,fn):
  if case not in ('all',label):return
  try:fn();rows.append({'name':label,'passed':True})
  except Exception as e:
   import traceback
   rows.append({'name':label,'passed':False,'error':traceback.format_exc()})
 def first_paint():
  page.goto(origin+'/',wait_until='networkidle')
  attrs=page.locator('head script').evaluate_all('es=>es.filter(e=>e.textContent.includes("var storageKey = ")).map(e=>({exclude:e.hasAttribute("nowprocket"),type:e.type}))')
  assert len(attrs)==1 and attrs[0]['exclude'],attrs
  assert page.locator('html').evaluate('e=>e.classList.contains("dark")')
 check('first-paint',first_paint)
 def emphasis():
  for width in [390,1440]:
   page.set_viewport_size({'width':width,'height':900});page.goto(f['surfaces']['article'],wait_until='networkidle');page.evaluate('document.fonts.ready')
   styles=page.locator('#post-content strong,#post-content em').evaluate_all('es=>es.map(e=>({tag:e.tagName,synthesis:getComputedStyle(e).fontSynthesis,weight:getComputedStyle(e).fontWeight,style:getComputedStyle(e).fontStyle,family:getComputedStyle(e).fontFamily}))')
   assert {s['tag']for s in styles}=={'STRONG','EM'},styles
   assert all(s['synthesis']!='none' for s in styles),styles
   assert all('Zen Kami Kai'in s['family']for s in styles)
 check('emphasis',emphasis)
 def guestbook():
  for width in [390,1440]:
   page.set_viewport_size({'width':width,'height':900});page.goto(f['surfaces']['notes'],wait_until='networkidle')
   label=page.locator('label[for=wp-comment-cookies-consent]').inner_text()
   assert '网站'not in label and '称呼'in label and '邮箱'in label,label
   gap=page.locator('#comments').evaluate('e=>e.getBoundingClientRect().top-document.querySelector(".zen-notes-heading").getBoundingClientRect().bottom')
   assert gap<=40,(width,gap)
 check('guestbook',guestbook)
 def taxonomy():
  for width in [320,390,768,1440]:
   page.set_viewport_size({'width':width,'height':900})
   for mode in ['light','dark']:
    page.emulate_media(color_scheme=mode)
    page.goto(origin+'/',wait_until='networkidle');page.evaluate('document.fonts.ready')
    reference=page.locator('.zen-post-list > .zen-post-item').first.evaluate('e=>({grid:getComputedStyle(e).gridTemplateColumns,gap:getComputedStyle(e).columnGap,font:getComputedStyle(e.querySelector("h2")).fontSize})')
    for family in ['category','tag']:
     page.goto(f['routes'][family],wait_until='networkidle');page.evaluate('document.fonts.ready')
     assert page.locator('body').evaluate('(e,family)=>e.classList.contains(family)',family)
     items=page.locator('.zen-post-list--editorial > .zen-post-item');assert items.count()>0
     assert items.locator('.zen-meta-category').count()==0
     assert page.locator('.zen-category-nav').count()==(1 if family=='category' else 0)
     for item in items.all():
      assert item.evaluate('e=>({grid:getComputedStyle(e).gridTemplateColumns,gap:getComputedStyle(e).columnGap,font:getComputedStyle(e.querySelector("h2")).fontSize})')==reference
      assert item.locator('.zen-post-meta').inner_text().strip()==item.locator('time').inner_text().strip()
      parts=item.locator('time span');assert parts.count()==2
      assert parts.nth(0).inner_text().endswith('月') and parts.nth(1).inner_text().endswith('日')
      assert parts.nth(1).bounding_box()['y']>parts.nth(0).bounding_box()['y']
     assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
     page.screenshot(path=str(root/'evidence'/f'taxonomy-{family}-{width}-{mode}.png'),full_page=True)
 check('taxonomy',taxonomy)
 def archive_years():
  for width in [320,390,768,1440]:
   page.set_viewport_size({'width':width,'height':900})
   for mode in ['light','dark']:
    page.emulate_media(color_scheme=mode);page.goto(f['surfaces']['archives'],wait_until='networkidle')
    years=page.locator('#zen-archive-years > details');assert years.count()>2
    assert years.locator('details').count()==0 and years.locator('summary').count()==years.count()
    assert years.evaluate_all('es=>es.every(e=>e.open)')
    assert page.locator('.zen-archive-group:not([hidden])').count()==2
    first=years.first;summary=first.locator('summary');meta=summary.locator('.zen-archive-year-meta')
    assert '/'in meta.inner_text() and meta.inner_text().strip().endswith('收起')
    count,slash,toggle=meta.locator(':scope > span').all()
    assert count.bounding_box()['x']<slash.bounding_box()['x']<toggle.bounding_box()['x']
    assert summary.bounding_box()['height']>=44
    summary.focus();page.keyboard.press('Enter');assert first.get_attribute('open') is None
    assert meta.inner_text().strip().endswith('展开') and first.locator('.zen-archive-month').first.is_hidden()
    more=page.locator('.zen-archive-more');more.click()
    assert first.get_attribute('open') is None
    assert page.locator('.zen-archive-group:not([hidden])').count()==min(4,years.count())
    assert years.nth(2).locator('summary').evaluate('e=>e===document.activeElement')
    summary.locator('.zen-archive-expand').click();assert first.get_attribute('open') is not None
    assert first.locator('.zen-archive-month').first.is_visible()
    assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
    page.screenshot(path=str(root/'evidence'/f'archive-year-{width}-{mode}.png'),full_page=True)
 check('archive-years',archive_years)
 def archive_noscript():
  page.set_viewport_size({'width':390,'height':900});page.goto(f['surfaces']['archives'],wait_until='networkidle')
  years=page.locator('#zen-archive-years > details');assert years.count()>2
  assert years.evaluate_all('es=>es.every(e=>!e.hidden && e.open)')
  assert page.locator('.zen-archive-more').is_hidden()
  first=years.first;summary=first.locator('summary');summary.focus();page.keyboard.press('Enter')
  assert first.get_attribute('open') is None and first.locator('.zen-archive-month').first.is_hidden()
  page.keyboard.press('Space');assert first.get_attribute('open') is not None
  assert first.locator('.zen-archive-month').first.is_visible()
  assert page.locator('.zen-archive-row a:visible').count()==page.locator('.zen-archive-row a').count()
 if case=='archive-noscript':check('archive-noscript',archive_noscript)
 def clipboard():
  page.add_init_script('''const nativeCopy=document.execCommand;document.execCommand=function(...args){window.__nativeCopy={command:args[0],selected:window.getSelection().toString(),active:document.activeElement.tagName};return nativeCopy.apply(this,args)};''')
  def deny_modern_write(route):
   response=route.fetch()
   route.fulfill(response=response,headers={**response.headers,'Permissions-Policy':'clipboard-write=()'})
  page.route(f['surfaces']['article'],deny_modern_write)
  page.goto(f['surfaces']['article'],wait_until='networkidle')
  assert page.evaluate('Boolean(isSecureContext && navigator.clipboard?.writeText)'), 'Native Clipboard API required'
  assert page.evaluate('navigator.permissions.query({name:"clipboard-write"}).then(p=>p.state)')=='denied', 'Document policy must deny native modern writes'
  denied=page.evaluate('navigator.clipboard.writeText("permission check").then(()=>false,error=>error.name==="NotAllowedError")')
  assert denied, 'Native modern-copy denial was not reached'
  copy=page.locator('.zen-code-copy').first;copy.scroll_into_view_if_needed();copy.focus();page.keyboard.press('Enter')
  page.wait_for_function('window.__nativeCopy && /已复制|复制失败/.test(document.activeElement.textContent)')
  assert page.evaluate('document.activeElement===document.querySelector(".zen-code-copy")')
  assert copy.inner_text()=='已复制', copy.inner_text()
  assert page.locator('body>textarea').count()==0
  native=page.evaluate('window.__nativeCopy')
  assert native['command']=='copy' and native['active']=='TEXTAREA' and native['selected']==page.locator('pre code').first.text_content(),native
  c.grant_permissions(['clipboard-read'],origin=origin)
  assert page.evaluate('navigator.clipboard.readText()')==native['selected'], 'Native clipboard must contain literal code'
  page.wait_for_timeout(600)
  copy.focus();page.keyboard.press('Enter')
  page.wait_for_timeout(1100)
  assert copy.inner_text()=='已复制' and copy.get_attribute('aria-label')=='已复制', 'Earlier reset must not erase newer feedback'
  assert copy.locator('.ph-check').count()==1 and 'is-copied' in copy.get_attribute('class')
  page.wait_for_function('document.querySelector(".zen-code-copy").getAttribute("aria-label")==="复制代码"')
  assert copy.locator('.ph-copy').count()==1 and 'is-copied' not in copy.get_attribute('class')
  c.clear_permissions()
 check('clipboard',clipboard)
 def audio_duration():
  page.goto(f['surfaces']['article'],wait_until='networkidle')
  duration=page.evaluate('''async()=>{
   const audio=document.querySelector('audio');if(!audio)throw new Error('Seed a native audio element');
   const media=new MediaSource();let metadata=0,changes=0;
   audio.addEventListener('loadedmetadata',()=>metadata++);audio.addEventListener('durationchange',()=>changes++);
   audio.src=URL.createObjectURL(media);audio.load();await new Promise((resolve,reject)=>{const timeout=setTimeout(()=>reject(new Error('MediaSource open timed out')),10000);media.addEventListener('sourceopen',()=>{clearTimeout(timeout);resolve()},{once:true})});
   window.__zenMedia=media;window.__zenMediaEvents=()=>({metadata,changes});media.duration=120;
   await new Promise((resolve,reject)=>{const timeout=setTimeout(()=>reject(new Error('Duration event timed out')),10000);audio.addEventListener('durationchange',()=>{clearTimeout(timeout);resolve()},{once:true})});
   return {duration:audio.duration,paused:audio.paused,metadata,changes};
  }''')
  assert duration['duration']==120 and duration['paused'],duration
  assert page.locator('.zen-audio-time').inner_text()=='0:00 / 2:00'
  page.evaluate('window.__zenMedia.duration=180')
  page.wait_for_function('document.querySelector(".zen-audio-time").innerText==="0:00 / 3:00"')
  final=page.evaluate('({duration:document.querySelector("audio").duration,paused:document.querySelector("audio").paused,...window.__zenMediaEvents()})')
  assert final['duration']==180 and final['paused'] and final['metadata']==duration['metadata'] and final['changes']>duration['changes'],final
 check('audio-duration',audio_duration)
 def admin():
  c.add_cookies(json.loads((root/'cookies.json').read_text()))
  for width in [320,390,768,1440]:
   page.set_viewport_size({'width':width,'height':900});page.goto(origin+'/wp-admin/admin.php?page=zen-options',wait_until='networkidle')
   assert page.locator('#zen-options-form').count()==1
   assert page.locator('#zen_reading_width').input_value()=='800'
   assert page.locator('#zen_list_style').input_value()=='standard'
   page.locator('#zen-code>summary').click()
   assert page.evaluate('document.documentElement.scrollWidth<=innerWidth')
   page.locator('#zen_footer_text').fill('unsaved fixture')
   assert '有未保存'in page.locator('#zen-save-state').inner_text()
   page.locator('#zen_footer_text').fill('')
   assert '没有未保存'in page.locator('#zen-save-state').inner_text()
   page.evaluate('window.scrollTo(0,1200)')
   top=page.locator('.zen-save-bar').evaluate('e=>e.getBoundingClientRect().top')
   assert abs(top-(12 if width<=600 else 58 if width<=782 else 44))<2,(width,top)
   page.evaluate('scrollTo(0,0)')
   page.screenshot(path=str(root/'evidence'/f'admin-{width}.png'),full_page=True)
 check('admin',admin)
 b.close()
(root/'evidence'/('ui-detail-'+case+'.json')).write_text(json.dumps(rows,ensure_ascii=False,indent=2));print(json.dumps(rows,ensure_ascii=False));assert rows and all(r['passed']for r in rows)
