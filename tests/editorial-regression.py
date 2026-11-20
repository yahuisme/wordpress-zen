"""Editorial index invariants against a seeded, isolated WordPress site."""
from datetime import datetime
import json
import os
import re
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

origin = 'http://127.0.0.1:18765/'
fixture = Path(sys.argv[1]).resolve()
assert (fixture / 'runtime/.zen-isolated-fixture').is_file()

def assert_date(date, context):
    published = datetime.fromisoformat(date.get_attribute('datetime'))
    current_year = datetime.now(published.tzinfo).year
    short = context == 'directory' or (context in ('home', 'list') and published.year == current_year)
    expected = f'{published.month}月 {published.day}日' if short else published.strftime('%Y.%m.%d')
    actual = ' '.join(date.inner_text().split())
    assert actual == expected, (context, actual, expected)
    if context == 'home' and short:
        assert date.locator('span').all_inner_texts() == [f'{published.month}月', f'{published.day}日']
        parts = date.locator('span').evaluate_all('es=>es.map(e=>e.getBoundingClientRect().top)')
        assert parts[1] > parts[0], parts

with sync_playwright() as p:
    browser = p.chromium.launch(
        executable_path='/usr/lib/chromium/chromium',
        args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
        env={**os.environ, 'DBUS_SESSION_BUS_ADDRESS': 'unix:path=/nonexistent', 'DBUS_SYSTEM_BUS_ADDRESS': 'unix:path=/nonexistent'},
    )
    cgroup = Path('/proc/self/cgroup').read_text()
    path = Path('/sys/fs/cgroup') / cgroup.strip().split('::', 1)[1].lstrip('/')
    assert any((a / 'memory.max').is_file() and (a / 'memory.max').read_text().strip().isdigit()
               and int((a / 'memory.max').read_text()) <= 4 * 1024**3
               and (a / 'memory.swap.max').read_text().strip() == '0' for a in [path, *path.parents])
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{process["id"]}/cgroup').read_text() == cgroup for process in processes)
    page = browser.new_page(reduced_motion='reduce')
    page.route('**/*', lambda r: r.continue_() if r.request.url.startswith(origin) else r.abort())
    for width in (320, 390, 768, 1440):
        page.set_viewport_size({'width': width, 'height': 900})
        for path, is_first in [('', True), ('?paged=2', False)]:
            response = page.goto(origin + path, wait_until='load')
            assert response.status == 200
            h1 = page.locator('main h1')
            assert h1.count() == 1 and h1.is_visible(), 'The posts index needs one visible main heading'
            masthead = page.locator('.zen-masthead')
            assert masthead.count() == int(is_first), 'Only the first page repeats the publication title'
            card = page.locator('main .zen-latest-card')
            assert card.count() == int(is_first), 'The latest article needs its own card on the first page only'
            items = page.locator('main .zen-post-list > .zen-post-item--standard')
            assert items.count() >= 2, 'Seed enough published posts for both pages'
            if is_first:
                assert card.locator('h2 a').is_visible() and card.locator('.zen-post-excerpt').is_visible()
                assert re.fullmatch(r'\d{4}\.\d{2}\.\d{2}', card.locator('time').inner_text())
                assert card.get_attribute('id') not in items.evaluate_all('items => items.map(i => i.id)')
                assert card.locator('time').get_attribute('datetime') > items.first.locator('time').get_attribute('datetime')
            for date in items.locator('.zen-post-date').all():
                assert_date(date, 'home')
            page.evaluate('document.fonts.ready.then(() => true)')
            geom = items.evaluate_all('''items => items.map(item => {
                const bounds = s => item.querySelector(s).getBoundingClientRect();
                const [title, text] = ['h2', '.zen-post-excerpt'].map(bounds);
                const time = item.querySelector('time');
                const nodes = time.classList.contains('zen-index-date') ? [...time.querySelectorAll('span')] : [time];
                const lines = nodes.map(node => {const range = document.createRange();range.selectNodeContents(node);return range;});
                return {titleX:title.x, excerptX:text.x, titleWidth:title.width, excerptWidth:text.width,
                        dateRight:Math.max(...lines.map(line=>line.getBoundingClientRect().right)),
                        dateLines:lines.map(line=>line.getClientRects().length)};
            })''')
            for row in geom:
                assert abs(row['titleX']-row['excerptX']) < 1 and abs(row['titleWidth']-row['excerptWidth']) < 1, row
                assert row['dateLines'] in ([1], [1, 1]) and row['titleX'] >= row['dateRight'] + 12, row
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
    if '--settings' in sys.argv:
        fixture_ids = json.loads((fixture / 'fixture.json').read_text())
        page.goto(origin, wait_until='load')
        latest = page.locator('.zen-latest-card').get_attribute('id')
        post_ids = page.locator('main article[id^=post-]').evaluate_all('items => items.map(i => i.id)')
        page.context.add_cookies(json.loads((fixture / 'cookies.json').read_text()))
        settings = origin + 'wp-admin/admin.php?page=zen-options'
        page.goto(settings, wait_until='load')
        card_toggle = page.locator('#zen_show_latest_card')
        assert card_toggle.is_checked() and page.locator('#zen_date_style').count() == 0

        def save():
            with page.expect_navigation(wait_until='load'):
                page.locator('#zen_save_top').click()
            assert page.locator('.notice-success, .updated').count() > 0

        try:
            card_toggle.uncheck()
            assert '有未保存的修改' in page.locator('#zen-save-state').inner_text()
            save()
            assert not card_toggle.is_checked()
            page.goto(origin, wait_until='load')
            assert page.locator('.zen-latest-card').count() == 0
            assert page.locator(f'.zen-post-list > #{latest}').count() == 1
            assert set(page.locator('main article[id^=post-]').evaluate_all('items => items.map(i => i.id)')) == set(post_ids)
            for path, date_selector, date_context in [
                ('', '.zen-post-list .zen-post-date', 'home'),
                ('?s=WordPress', '.zen-post-list .zen-post-date', 'list'),
                ('?page_id=' + str(fixture_ids['archives']), '.zen-archives-directory .zen-post-date', 'directory'),
                ('?p=' + str(fixture_ids['normal']), '.zen-article-header .zen-post-date', 'full'),
            ]:
                page.goto(origin + path, wait_until='load')
                assert_date(page.locator(date_selector).first, date_context)
            print('Settings saved through WordPress and reflected in the index, search, directory and article.')
        finally:
            page.goto(settings, wait_until='load')
            card_toggle.check()
            save()
    browser.close()
print('Editorial index holds at four widths on both list pages.')
