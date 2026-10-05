"""Whole theme page geometry and task interactions, real loopback WordPress."""
from pathlib import Path
import json,sys
from playwright.sync_api import sync_playwright
r=Path(sys.argv[1]).resolve();f=json.loads((r/'fixture.json').read_text());o='http://127.0.0.1:18765';out=r/'evidence';rows=[];errors=[]
routes={'home':'/','article':'/?p='+str(f['normal']),'page':'/?p='+str(f['page']),'category':'/?cat='+str(f['categories'][0]),'tag':'/?tag_id='+str(f['tag']),'archives':'/?p='+str(f['archives']),'links':'/?p='+str(f['links']),'search':'/?s=WordPress','empty-search':'/?s=notfound','404':'/?p=2147483647','author':'/?author=1','date':'/?m=202610','empty-category':'/?cat='+str(f['emptycat']),'protected':'/?p='+str(f['protected']),'multipage':'/?p='+str(f['multipage'])}
with sync_playwright() as p:
 b=p.chromium.launch(executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage']);c=b.new_context(reduced_motion='reduce');page=c.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
 for name,route in routes.items():
  for width in (320,390,768,1440):
   for dark in (False,True):
    page.set_viewport_size({'width':width,'height':900});res=page.goto(o+route,wait_until='networkidle');assert res.status==(404 if name=='404' else 200),(name,res.status)
    page.evaluate('(v)=>{document.documentElement.classList.toggle("dark",v);document.documentElement.style.scrollBehavior="auto"}',dark)
    d=page.evaluate('''()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,items:[...document.querySelectorAll('.zen-post-item--standard')].map(e=>({title:e.querySelector('h2').getBoundingClientRect().width,excerpt:e.querySelector('.zen-post-excerpt').getBoundingClientRect().width})),reading:[...document.querySelectorAll('article.entry-content')].map(e=>({width:e.getBoundingClientRect().width,font:getComputedStyle(e).fontSize,line:getComputedStyle(e).lineHeight})),header:{bottom:document.querySelector('.zen-site-header').getBoundingClientRect().bottom}})''')
    assert d['scroll']<=width+1,(name,width,d);assert all(abs(x['title']-x['excerpt'])<1 for x in d['items']),(name,width,d)
    assert all(x['font']=='18px' and x['line']=='32px' for x in d['reading']),(name,d)
    if width in (390,1440) and not dark:
     for image in page.locator('main img').all():image.scroll_into_view_if_needed();page.wait_for_timeout(40)
     page.evaluate('scrollTo(0,0)');page.screenshot(path=str(out/(name+'-'+str(width)+'.png')),full_page=True)
    rows.append({'page':name,'width':width,'dark':dark,'metrics':d})
 page.set_viewport_size({'width':390,'height':900});page.goto(o+routes['protected'],wait_until='networkidle')
 password=page.locator('.post-password-form input[type=submit]');assert password.is_visible()
 style=password.evaluate('e=>{let c=getComputedStyle(e);return {height:e.getBoundingClientRect().height,background:c.backgroundColor,padding:c.paddingLeft}}')
 assert style['height']>=40 and style['background']!='rgba(0, 0, 0, 0)',('password submit lacks a visible button',style)
 page.set_viewport_size({'width':390,'height':900});page.goto(o+'/?p='+str(f['normal']),wait_until='networkidle');page.locator('#header-toc-btn').click();page.wait_for_timeout(350);assert page.locator('#drawer-toc-close').evaluate('e=>e===document.activeElement');page.keyboard.press('Escape');page.wait_for_timeout(350);assert page.locator('#header-toc-btn').evaluate('e=>e===document.activeElement')
 page.locator('#mobile-menu-btn').click();assert page.locator('#mobile-menu').is_visible();page.keyboard.press('Escape');page.locator('#search-toggle').click();page.wait_for_timeout(200);page.locator('#search-input').fill('WordPress')
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
