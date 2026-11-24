"""Native directory reveal: pointer, keyboard and scroll position on WordPress pages."""
import json
import os
from pathlib import Path
import sys

from playwright.sync_api import sync_playwright

root = Path(sys.argv[1]).resolve()
fixture = json.loads((root / 'fixture.json').read_text())
origin = fixture['origin']
assert origin == 'http://127.0.0.1:18765' and (root / 'runtime/.zen-isolated-fixture').is_file()
group = Path('/proc/self/cgroup').read_text()
control = Path('/sys/fs/cgroup') / group.strip().split('::', 1)[1].lstrip('/')
assert any((a / 'memory.max').is_file() and (a / 'memory.max').read_text().strip() == '4294967296'
           and (a / 'memory.swap.max').read_text().strip() == '0' for a in (control, *control.parents))

pages = {'archives': ('zen-archive-years', 'summary', 2), 'links': ('zen-links-entries', 'a', 20),
         'tags': ('zen-tags-entries', 'a', 20), 'notes': ('zen-notes-entries', None, 20)}
failures = []

def check(condition, scenario, symptom):
    if not condition:
        failures.append((scenario, symptom))

with sync_playwright() as playwright:
    for width, touch in ((390, True), (1440, False)):
        browser = playwright.chromium.launch(executable_path='/usr/lib/chromium/chromium',
            args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
            env={**os.environ, 'DBUS_SESSION_BUS_ADDRESS': 'unix:path=/nonexistent', 'DBUS_SYSTEM_BUS_ADDRESS': 'unix:path=/nonexistent'})
        context = browser.new_context(viewport={'width': width, 'height': 840}, is_mobile=touch,
                                      has_touch=touch, reduced_motion='reduce')
        context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(origin + '/') else r.abort())
        page = context.new_page()
        page.set_default_timeout(5000)
        errors = []
        page.on('pageerror', lambda error: errors.append(str(error)))
        processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
        assert processes and all(Path(f'/proc/{p["id"]}/cgroup').read_text() == group for p in processes)
        for mode in ('light', 'dark'):
            page.emulate_media(color_scheme=mode)
            for name, (list_id, focus_selector, batch) in pages.items():
                url = fixture['surfaces'][name]
                assert url.startswith(origin + '/')
                scenario = f'{name}/{width}/{mode}'
                assert page.goto(url).status == 200
                items = page.locator(f'#{list_id} > *')
                shown = page.locator(f'#{list_id} > :not([hidden])')
                more = page.locator(f'button[aria-controls="{list_id}"]')
                assert items.count() > batch * 2 and shown.count() == batch
                target = items.nth(batch)
                if focus_selector:
                    target = target.locator(focus_selector).first
                if name == 'archives':
                    items.first.locator('summary').press('Enter')
                    assert items.first.get_attribute('open') is None
                # WebKit-like pointer default: the button does not take focus on press.
                # Dispatch a real tap/click while maintaining the preceding keyboard focus.
                more.evaluate('e => e.addEventListener("mousedown", e => e.preventDefault(), {once: true})')
                more.focus()
                assert more.evaluate('e => e.matches(":focus-visible")'), scenario
                if touch:
                    more.tap()
                else:
                    more.click()
                assert shown.count() == batch * 2
                assert target.evaluate('e => document.activeElement === e')
                check(target.evaluate('e => getComputedStyle(e).outlineStyle === "none"'), scenario,
                      'pointer activation inherits the keyboard focus ring')
                if name == 'archives':
                    assert items.first.get_attribute('open') is None
                    assert items.nth(batch).get_attribute('open') is not None
                    if touch and mode == 'light':
                        page.screenshot(path=str(root / 'evidence' / 'archive-after-pointer.png'))
                    target.press('Enter')
                    assert items.nth(batch).get_attribute('open') is None
                    check(target.evaluate('e => getComputedStyle(e).outlineStyle !== "none"'), scenario,
                          'first keyboard action must restore a visible focus cue')
                    target.press('Space')
                    assert items.nth(batch).get_attribute('open') is not None
                # Activate consecutive batches with the keyboard and check the last, possibly partial, batch.
                while more.is_visible():
                    count = shown.count()
                    last_target = items.nth(count)
                    if focus_selector:
                        last_target = last_target.locator(focus_selector).first
                    more.press('Space')
                    assert shown.count() == min(count + batch, items.count())
                    assert last_target.evaluate('e => document.activeElement === e && getComputedStyle(e).outlineStyle !== "none"'), scenario
                assert shown.count() == items.count() and more.is_hidden()

                # A button just below the sticky header can be tapped, but the inserted
                # rows start above/behind it unless reveal brings the first row into view.
                assert page.goto(url).status == 200
                more.evaluate('''e => {
                    const sticky = document.querySelector('.zen-site-header').getBoundingClientRect();
                    scrollBy(0, e.getBoundingClientRect().top - sticky.height - 2);
                }''')
                assert more.evaluate('''e => {
                    const r = e.getBoundingClientRect();
                    return e.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));
                }'''), scenario
                if touch:
                    more.tap()
                else:
                    more.click()
                check(target.evaluate('''e => {
                    const r = e.getBoundingClientRect();
                    const header = document.querySelector('.zen-site-header').getBoundingClientRect();
                    return r.top >= header.bottom - 1 && r.top + Math.min(r.height, 44) <= innerHeight;
                }'''), scenario, 'revealed content starts behind the sticky header or outside the viewport')
                if name == 'notes':
                    # A long thread must show its start, not its vertical midpoint.
                    for activation in ('pointer', 'keyboard'):
                        assert page.goto(url).status == 200
                        # Exercise browsers without scroll anchoring, with enough room to position the button.
                        page.add_style_tag(content='html { overflow-anchor: none } body { padding-bottom: 100vh }')
                        target.locator('.zen-comment-text').first.evaluate('''e => {
                            for (let i = 0; i < 50; i++) {
                                const paragraph = document.createElement('p');
                                paragraph.textContent = '一段需要完整展示的长留言。';
                                e.append(paragraph);
                            }
                        }''')
                        more.focus()
                        assert more.evaluate('''e => {
                            const header = document.querySelector('.zen-site-header').getBoundingClientRect();
                            scrollBy({top: e.getBoundingClientRect().top - header.bottom + 18, behavior: 'instant'});
                            const rect = e.getBoundingClientRect();
                            return rect.top < header.bottom && e.contains(document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2));
                        }'''), scenario
                        if activation == 'keyboard':
                            more.press('Space')
                        elif touch:
                            more.tap()
                        else:
                            more.click()
                        assert target.evaluate('e => e === document.activeElement && e.offsetHeight > innerHeight')
                        check(target.evaluate('''e => {
                            const top = e.getBoundingClientRect().top;
                            const header = document.querySelector('.zen-site-header').getBoundingClientRect();
                            return top >= header.bottom - 1 && top + 44 <= innerHeight;
                        }'''), scenario, f'tall thread start is not visible after {activation} reveal')
                print(scenario, [symptom for case, symptom in failures if case == scenario], flush=True)
        assert not errors, errors
        browser.close()
assert not failures, failures
