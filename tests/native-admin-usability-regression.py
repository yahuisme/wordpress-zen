"""Native settings shortcuts, disclosure, code editing and narrow save bar."""
from pathlib import Path
import json
import os
import subprocess
import sys

from playwright.sync_api import sync_playwright

fixture = Path(sys.argv[1]).resolve()
origin = 'http://127.0.0.1:18765'
settings_url = origin + '/wp-admin/admin.php?page=zen-options'
assert (fixture / 'runtime/.zen-isolated-fixture').is_file()
assert (fixture / 'db/zen.sqlite').is_file()


def wp(*args):
    return subprocess.run(
        ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root',
         '--path=' + str(fixture / 'runtime'), *args],
        check=True, capture_output=True, text=True,
    ).stdout.strip()


def option(name):
    return json.loads(wp('option', 'get', name, '--format=json'))


identity = json.loads(wp('eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),FQDB,get_template()]);'))
assert identity == ['local', origin, str(fixture / 'db/zen.sqlite'), 'zen'], identity
admin = json.loads((fixture / 'fixture.json').read_text())['admin']
rows = []

with sync_playwright() as p:
    browser = p.chromium.launch(
        executable_path='/usr/lib/chromium/chromium',
        args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
        env={**os.environ, 'DBUS_SESSION_BUS_ADDRESS': 'unix:path=/nonexistent',
             'DBUS_SYSTEM_BUS_ADDRESS': 'unix:path=/nonexistent'},
    )
    group = Path('/proc/self/cgroup').read_text()
    scope = Path('/sys/fs/cgroup') / group.strip().split('::', 1)[1].lstrip('/')
    assert any((a / 'memory.max').is_file()
               and (a / 'memory.max').read_text().strip() == '4294967296'
               and (a / 'memory.swap.max').read_text().strip() == '0'
               for a in [scope, *scope.parents])
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{x["id"]}/cgroup').read_text() == group for x in processes)
    context = browser.new_context(viewport={'width': 1440, 'height': 900}, reduced_motion='reduce')
    context.add_cookies(json.loads((fixture / 'cookies.json').read_text()))
    context.route('**/*', lambda r: r.continue_() if r.request.url.startswith(origin + '/') else r.abort())
    page = context.new_page()
    errors, dialogs, posts = [], [], []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('request', lambda r: posts.append(r.url) if r.method == 'POST' and r.url == origin + '/wp-admin/options.php' else None)

    def dialog(d):
        dialogs.append(d.type)
        d.dismiss()

    page.on('dialog', dialog)

    def record(name, **details):
        row = {'name': name, 'passed': True, **details}
        rows.append(row)
        with (fixture / 'evidence/admin-usability.jsonl').open('a') as out:
            out.write(json.dumps(row, ensure_ascii=False) + '\n')
        print('PASS', name, flush=True)

    def settings():
        response = page.goto(settings_url, wait_until='networkidle')
        assert response.ok and page.locator('#zen-options-form').count() == 1

    def save(shortcut=None):
        before = len(posts)
        with page.expect_navigation(wait_until='networkidle'):
            if shortcut:
                page.keyboard.press(shortcut)
            else:
                page.locator('#zen_save_top').click()
        assert len(posts) == before + 1, 'One save must send one native POST'
        assert page.locator('.notice-success,.updated').count() > 0
        assert page.locator('#zen-save-state').inner_text() == '没有未保存的修改'
        assert not dialogs, dialogs

    def open_code():
        page.locator('#zen-code > summary').click()
        page.wait_for_function('document.querySelectorAll("#zen-code .CodeMirror").length === 3')

    settings()
    page.locator('#zen-reading > summary').click()
    page.locator('#zen-footer > summary').click()
    page.wait_for_function('JSON.parse(sessionStorage.getItem("zen-options-groups"))["zen-footer"] === false')
    settings()
    assert not page.locator('#zen-reading').evaluate('e=>e.open')
    assert not page.locator('#zen-footer').evaluate('e=>e.open')
    assert not page.locator('#zen-code').evaluate('e=>e.open')
    assert page.locator('#zen-save-state').inner_text() == '没有未保存的修改'
    page.locator('#zen-reading > summary').click()
    page.locator('#zen-footer > summary').click()
    record('ordinary disclosures survive reload without changing saved values or opening advanced code')

    page.locator('#zen_reading_width').fill('599')
    before = len(posts)
    page.keyboard.press('Control+s')
    assert len(posts) == before and page.locator('#zen_reading_width').evaluate('e=>!e.checkValidity()')
    page.locator('#zen_reading_width').fill('800')
    page.locator('#zen_footer_text').fill('Native keyboard save')
    save('Control+s')
    assert option('zen_footer_text') == 'Native keyboard save'
    record('Ctrl S uses validated native submission and leaves no unexpected unload dialog')

    open_code()
    fields = ['head', 'body', 'footer']
    values = {
        'head': '<script>\nwindow.zenEditorLiteral = "C:\\\\fixture\\n";\n</script>',
        'body': '<noscript>Native editor fallback</noscript>',
        'footer': '<script>window.zenEditorFooter = true;</script>',
    }
    for position in fields:
        editor = page.locator('#zen_code_' + position + ' + .CodeMirror')
        assert editor.is_visible()
        assert editor.evaluate('e=>e.CodeMirror.getOption("lineNumbers")')
        assert editor.evaluate('e=>e.CodeMirror.getOption("lint")') is False
        editor.evaluate('e=>e.CodeMirror.focus()')
        page.keyboard.press('Control+a')
        page.keyboard.insert_text(values[position])
        page.wait_for_function('(data)=>document.getElementById(data.id).value===data.value', arg={'id':'zen_code_'+position,'value':values[position]})
        actual = page.locator('#zen_code_' + position).input_value()
        assert actual == values[position], (position, repr(actual), repr(values[position]), editor.evaluate('e=>e.CodeMirror.getValue()'))
    head = page.locator('#zen_code_head + .CodeMirror')
    head.evaluate('e=>{e.CodeMirror.focus();e.CodeMirror.setCursor(1,0)}')
    page.keyboard.press('Tab')
    indented = page.locator('#zen_code_head').input_value()
    assert indented != values['head'] and indented.splitlines()[1].startswith(('\t', '    ')), indented
    page.keyboard.press('Control+z')
    assert page.locator('#zen_code_head').input_value() == values['head']
    head.evaluate('e=>e.CodeMirror.focus()')
    page.keyboard.press('Escape')
    page.keyboard.press('Tab')
    assert page.locator('#zen_code_body + .CodeMirror').evaluate('e=>e.CodeMirror.hasFocus()')
    head.evaluate('e=>e.CodeMirror.focus()')
    page.keyboard.press('Escape')
    page.keyboard.press('Shift+Tab')
    assert page.locator('#zen-code > summary').evaluate('e=>e===document.activeElement')
    assert page.evaluate('typeof window.zenEditorLiteral') == 'undefined', 'Code must not run in settings'
    page.locator('#zen_code_footer + .CodeMirror').evaluate('e=>e.CodeMirror.focus()')
    save('Control+s')
    for position, expected in values.items():
        assert option('zen_code_' + position).replace('\r\n', '\n') == expected
    record('real native code typing, indent, undo, Escape Tab and keyboard save preserve literal snippets')

    open_code()
    assert page.locator('#zen_code_head + .CodeMirror').evaluate('e=>e.CodeMirror.getOption("screenReaderLabel")') == '头部代码'
    page.locator('#zen-code > summary').click()
    open_code()
    assert page.locator('#zen-code .CodeMirror').count() == 3
    for width in (320, 390, 768, 1440):
        page.set_viewport_size({'width': width, 'height': 900})
        page.evaluate('document.querySelectorAll(".CodeMirror").forEach(e=>e.CodeMirror.refresh())')
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth'), width
        save_box = page.locator('#zen_save_top').bounding_box()
        reset_box = page.locator('[form="zen-reset-form"]').bounding_box()
        status_box = page.locator('#zen-save-state').bounding_box()
        assert save_box['x'] + save_box['width'] <= reset_box['x'], (width, save_box, reset_box)
        assert abs(save_box['y'] - reset_box['y']) < 2, (width, save_box, reset_box)
        if width <= 600:
            assert status_box['y'] >= max(save_box['y']+save_box['height'], reset_box['y']+reset_box['height'])
        page.evaluate('scrollTo(0,1200)')
        top = page.locator('.zen-save-bar').bounding_box()['y']
        assert abs(top - (12 if width <= 600 else 58 if width <= 782 else 44)) < 2, (width, top)
        page.evaluate('scrollTo(0,0)')
        if width in (390, 1440):
            page.screenshot(path=str(fixture / 'evidence' / f'admin-usability-{width}.png'), full_page=True)
        record('save/reset separation and editor geometry', width=width)

    page.set_viewport_size({'width': 1440, 'height': 900})
    wp('eval', f'update_user_meta({int(admin)},"syntax_highlighting","false");')
    settings()
    page.locator('#zen-code > summary').click()
    assert page.locator('#zen-code .CodeMirror').count() == 0
    assert page.locator('#zen_code_head').is_visible()
    assert page.evaluate('Boolean(zenAdmin.codeEditor)') is False
    page.locator('#zen_footer_text').fill('Native plain fallback')
    save('Meta+s')
    assert option('zen_footer_text') == 'Native plain fallback'
    assert option('zen_code_head').replace('\r\n', '\n') == values['head']
    record('disabled personal syntax highlighting retains plain editable controls and Command S save')

    assert not errors and not dialogs, (errors, dialogs)
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{x["id"]}/cgroup').read_text() == group for x in processes)
    browser.close()

(fixture / 'evidence/admin-usability.json').write_text(json.dumps(rows, ensure_ascii=False, indent=2))
assert len(rows) == 8
print('NATIVE_ADMIN_USABILITY_COMPLETE: 8 scenarios; no unexpected script errors or dialogs')
