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
            card = page.locator('main > .zen-latest-card')
            assert card.count() == int(is_first), 'The latest article needs its own card on the first page only'
            items = page.locator('main .zen-post-list > .zen-post-item--standard')
            assert items.count() >= 2, 'Seed enough published posts for both pages'
            if is_first:
                assert card.locator('h2 a').is_visible() and card.locator('.zen-post-excerpt').is_visible()
                assert re.fullmatch(r'\d{4}\.\d{2}\.\d{2}', card.locator('time').inner_text())
                assert card.get_attribute('id') not in items.evaluate_all('items => items.map(i => i.id)')
                assert card.locator('time').get_attribute('datetime') > items.first.locator('time').get_attribute('datetime')
                first_date = items.first.locator('time')
                published = datetime.fromisoformat(first_date.get_attribute('datetime'))
                expected = f'{published.month}月 {published.day}日' if published.year == datetime.now(published.tzinfo).year else published.strftime('%Y.%m.%d')
                assert first_date.inner_text() == expected
            geom = items.evaluate_all('''items => items.map(item => {
                const bounds = s => item.querySelector(s).getBoundingClientRect();
                const [title, text, date] = ['h2', '.zen-post-excerpt', 'time'].map(bounds);
                return {titleX:title.x, excerptX:text.x, titleWidth:title.width, excerptWidth:text.width,
                        titleY:title.y, dateRight:date.right, dateBottom:date.bottom};
            })''')
            for row in geom:
                assert abs(row['titleX']-row['excerptX']) < 1 and abs(row['titleWidth']-row['excerptWidth']) < 1, row
                if width >= 768:
                    assert row['titleX'] >= row['dateRight'] + 12, row
                else:
                    assert row['titleY'] >= row['dateBottom'], row
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
        date_picker = page.locator('#zen_date_style')
        assert card_toggle.is_checked() and date_picker.input_value() == 'editorial'

        def save():
            with page.expect_navigation(wait_until='load'):
                page.locator('#zen_save_top').click()
            assert page.locator('.notice-success, .updated').count() > 0

        try:
            card_toggle.uncheck()
            date_picker.select_option('classic')
            assert '有未保存的修改' in page.locator('#zen-save-state').inner_text()
            save()
            assert not card_toggle.is_checked() and date_picker.input_value() == 'classic'
            page.goto(origin, wait_until='load')
            assert page.locator('.zen-latest-card').count() == 0
            assert page.locator(f'.zen-post-list > #{latest}').count() == 1
            assert set(page.locator('main article[id^=post-]').evaluate_all('items => items.map(i => i.id)')) == set(post_ids)
            for path, date_selector in [
                ('', '.zen-post-list .zen-post-date'),
                ('?s=WordPress', '.zen-post-list .zen-post-date'),
                ('?page_id=' + str(fixture_ids['archives']), '.zen-archives-directory .zen-post-date'),
                ('?p=' + str(fixture_ids['normal']), '.zen-article-header .zen-post-date'),
            ]:
                page.goto(origin + path, wait_until='load')
                date = page.locator(date_selector).first
                assert re.fullmatch(r'\d{4}年\d{1,2}月\d{1,2}日', date.inner_text()), path
            print('Settings saved through WordPress and reflected in the index, search, directory and article.')
        finally:
            page.goto(settings, wait_until='load')
            card_toggle.check()
            date_picker.select_option('editorial')
            save()
    browser.close()
print('Editorial index holds at four widths on both list pages.')
