"""Whole theme page geometry and task interactions, real loopback WordPress."""
from pathlib import Path
import json,sys,subprocess
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
r=Path(sys.argv[1]).resolve();f=json.loads((r/'fixture.json').read_text());o='http://127.0.0.1:18765';out=r/'evidence';rows=[];errors=[]
tag_url=subprocess.check_output(['php','/opt/test-tools/wordpress/wp-cli.phar','--allow-root','--path='+str(r/'runtime'),'eval','echo get_tag_link('+str(int(f['tag']))+');'],text=True).strip()
assert tag_url.startswith(o+'/'), 'Tag route must belong to the isolated site'
routes={'home':'/','article':'/?p='+str(f['normal']),'page':'/?p='+str(f['page']),'category':'/?cat='+str(f['categories'][0]),'tag':tag_url.removeprefix(o),'archives':'/?p='+str(f['archives']),'links':'/?p='+str(f['links']),'search':'/?s=WordPress','empty-search':'/?s=notfound','404':'/?p=2147483647','author':'/?author=1','date':'/?m=202610','empty-category':'/?cat='+str(f['emptycat']),'protected':'/?p='+str(f['protected']),'multipage':'/?p='+str(f['multipage'])}
for optional in ('tags', 'notes'):
 if optional in f:routes[optional]='/?p='+str(f[optional])
if f.get('pagination'):
 routes['list-page-2']='/?paged=2'
 routes['search-page-2']='/?s=WordPress&paged=2'
selected=next((arg.split('=',1)[1].split(',') for arg in sys.argv[2:] if arg.startswith('--routes=')),None)
if selected:
 assert all(name in routes for name in selected), 'Unknown route selection'
 routes={name:routes[name] for name in selected}
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/lib/chromium/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--single-process','--no-zygote'])
 group=Path('/proc/self/cgroup').read_text()
 control=Path('/sys/fs/cgroup')/group.strip().split('::/',1)[1]
 limits=[parent for parent in (control,*control.parents) if (parent/'memory.max').is_file()]
 assert any((parent/'memory.max').read_text().strip()=='4294967296' and (parent/'memory.swap.max').read_text().strip()=='0' for parent in limits)
 processes=b.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
 assert processes and all(Path(f'/proc/{item["id"]}/cgroup').read_text()==group for item in processes)
 c=b.new_context(reduced_motion='reduce');c.route('**/*',lambda route:route.continue_() if route.request.url.startswith(o+'/') or route.request.url.startswith('data:') else route.abort())
 page=c.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
 for name,route in routes.items():
  for width in (320,390,768,1440):
   for dark in (False,True):
    page.set_viewport_size({'width':width,'height':900});res=page.goto(o+route,wait_until='networkidle');assert res.status==(404 if name=='404' else 200),(name,res.status)
    if name=='tag':assert page.locator('body').evaluate('(e,id)=>e.classList.contains("tag-"+id)',f['tag']) and page.locator('main h1').count()==1, 'Expected the native tag archive, not the homepage'
    page.evaluate('(v)=>{document.documentElement.classList.toggle("dark",v);document.documentElement.style.scrollBehavior="auto"}',dark)
    d=page.evaluate('''()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,items:[...document.querySelectorAll('.zen-post-item--standard')].map(e=>({title:e.querySelector('h2').getBoundingClientRect().width,excerpt:e.querySelector('.zen-post-excerpt')?.getBoundingClientRect().width??null})),reading:[...document.querySelectorAll('article.entry-content')].map(e=>({width:e.getBoundingClientRect().width,font:getComputedStyle(e).fontSize,line:getComputedStyle(e).lineHeight})),header:{bottom:document.querySelector('.zen-site-header').getBoundingClientRect().bottom}})''')
    assert d['scroll']<=width+1,(name,width,d)
    if width<640:
     assert page.locator('.zen-footer-navigation li + li').evaluate_all("es=>es.every(e=>getComputedStyle(e,'::before').content==='none')"),'Wrapped footer rows must not start with orphan separators'
    if name in ('list-page-2','search-page-2'):
     nav=page.get_by_role('navigation',name='分页导航')
     assert nav.locator('.page-numbers, [aria-current="page"]').count()==0, name
     assert 1<=nav.get_by_role('link').count()<=2, name
     previous=nav.get_by_role('link',name='上一页')
     assert urlsplit(previous.get_attribute('href')).netloc==urlsplit(o).netloc, name
     assert '/page/1/' not in previous.get_attribute('href') and 'paged=1' not in previous.get_attribute('href'), name
     if name=='search-page-2': assert 's=WordPress' in previous.get_attribute('href'), name
     assert all(a.bounding_box()['height']>=44 for a in nav.get_by_role('link').all()), name
    if name=='links' and f.get('bookmarks'):
     cards=page.locator('main .zen-link-card')
     assert cards.count()==f['bookmarks'], 'Fixture must contain nonempty native bookmarks'
     assert cards.locator('h2').count()==cards.count(), 'Bookmark name should be a level-two heading'
     heading_rule=page.locator('main .zen-links-heading').evaluate('e=>getComputedStyle(e).borderBottomWidth')
     row_rules=page.locator('main .zen-links-directory > li').evaluate_all('es=>es.map(e=>{const s=getComputedStyle(e);return [s.borderTopWidth,s.borderBottomWidth]})')
     assert heading_rule=='1px' and row_rules==[['0px','1px']]*cards.count(), ('Only the heading rule should appear above the first bookmark; keep the row dividers',width,heading_rule,row_rules)
     names_and_descriptions=cards.locator('h2, p').evaluate_all('es=>es.map(e=>({width:e.clientWidth,scroll:e.scrollWidth,wrap:getComputedStyle(e).whiteSpace}))')
     assert all(x['width']>=x['scroll'] and x['wrap']!='nowrap' for x in names_and_descriptions), (name,width,names_and_descriptions)
    if name in ('home','category','tag','search','author','date'):assert d['items'], ('Expected nonempty standard-list fixture',name)
    assert all(x['excerpt'] is None or abs(x['title']-x['excerpt'])<1 for x in d['items']),(name,width,d)
    expected_font=16 if width<640 else 17
    assert all(float(x['font'].removesuffix('px'))==expected_font and abs(float(x['line'].removesuffix('px'))-expected_font*1.55)<0.1 for x in d['reading']),(name,d)
    if width in (390,1440) and not dark:
     for image in page.locator('main img').all():image.scroll_into_view_if_needed();page.wait_for_timeout(40)
     page.evaluate('scrollTo(0,0)');page.screenshot(path=str(out/(name+'-'+str(width)+'.png')),full_page=True)
    row={'page':name,'width':width,'dark':dark,'metrics':d}
    rows.append(row)
    with (out/'page-review.jsonl').open('a') as stream:stream.write(json.dumps(row,ensure_ascii=False)+'\n')
 if selected:
  b.close();assert not errors,errors
  (out/'page-review-selected.json').write_text(json.dumps({'rows':rows,'errors':errors},ensure_ascii=False,indent=2));print('PASS',len(rows),'selected page/state/width cases');sys.exit(0)
 page.set_viewport_size({'width':390,'height':900});page.goto(o+routes['protected'],wait_until='networkidle')
 password=page.locator('.post-password-form input[type=submit]');assert password.is_visible()
 style=password.evaluate('e=>{let c=getComputedStyle(e);return {height:e.getBoundingClientRect().height,background:c.backgroundColor,padding:c.paddingLeft}}')
 assert style['height']>=40 and style['background']!='rgba(0, 0, 0, 0)',('password submit lacks a visible button',style)
 page.set_viewport_size({'width':390,'height':900});page.goto(o+'/?p='+str(f['normal']),wait_until='networkidle');page.locator('#header-toc-btn').click();page.wait_for_timeout(350);assert page.locator('#drawer-toc-close').evaluate('e=>e===document.activeElement');page.keyboard.press('Escape');page.wait_for_timeout(350);assert page.locator('#header-toc-btn').evaluate('e=>e===document.activeElement')
 for width in (390,768):
  page.set_viewport_size({'width':width,'height':900})
  assert page.locator('.zen-site-header nav, #mobile-menu-btn').count()==0 and page.locator('.zen-footer-navigation a').first.is_visible()
 page.set_viewport_size({'width':390,'height':900})
 page.evaluate('window.scrollTo({top: document.documentElement.scrollHeight, behavior: "instant"})')
 assert page.locator('.zen-footer-navigation a').last.evaluate('''el => {
  const r = el.getBoundingClientRect();
  return el.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
 }'''), 'fixed UI must not cover the final footer link'
 page.evaluate('window.scrollTo({top: 0, behavior: "instant"})')
 page.locator('#search-toggle').click();page.wait_for_timeout(200);page.locator('#search-input').fill('WordPress')
 with page.expect_navigation(wait_until='networkidle'):page.locator('.zen-search-submit').click()
 assert 's=WordPress' in page.url
 page.goto(o+routes['empty-search'],wait_until='networkidle');page.locator('.zen-inline-search input[type=search]').fill('WordPress')
 with page.expect_navigation(wait_until='networkidle'):page.locator('.zen-inline-search input[type=submit]').click()
 assert page.locator('.zen-post-item').count()>0
 page.goto(o+routes['article'],wait_until='networkidle');page.locator('.comment-reply-link').first.click();assert page.locator('#comment_parent').input_value()!='0';page.locator('#cancel-comment-reply-link').click();assert page.locator('#comment_parent').input_value()=='0'
 page.goto(o+routes['multipage'],wait_until='networkidle');page.locator('.zen-page-links a').first.click();page.wait_for_load_state('networkidle');assert '第二页正文' in page.locator('main').inner_text()
 c.add_cookies(json.loads((r/'cookies.json').read_text()))
 for width in (320,390,768,1440):
  page.set_viewport_size({'width':width,'height':900});page.goto(o+'/wp-admin/admin.php?page=zen-options',wait_until='networkidle');assert page.locator('.zen-native-links,.zen-options-nav,.zen-saved-links,.zen-options-intro').count()==0
  page.locator('#zen-code > summary').click();assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1');page.evaluate('scrollTo(0,1100)');page.wait_for_timeout(50);top=page.locator('.zen-save-bar').evaluate('e=>e.getBoundingClientRect().top');assert abs(top-(12 if width<=600 else 58 if width<=782 else 44))<2,(width,top)
  page.evaluate('scrollTo(0,0)');page.screenshot(path=str(out/('admin-'+str(width)+'.png')),full_page=True);rows.append({'page':'admin','width':width,'top-links':0})
 b.close()
assert not errors,errors
(out/'page-review.json').write_text(json.dumps({'rows':rows,'errors':errors,'interactions':['header directory focus','mobile menu Escape','search submit','empty search retry','comment reply/cancel','native multipage']},ensure_ascii=False,indent=2));print('PASS',len(rows),'page/state/width cases and 6 interaction paths')
