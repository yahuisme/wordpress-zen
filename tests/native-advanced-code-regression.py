"""Isolated native settings retirement and ordinary three-position code output."""
import json
import subprocess
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

fixture = Path(sys.argv[1]).resolve()
assert (fixture / 'runtime' / 'wp-load.php').is_file()
assert (fixture / 'db' / 'zen.sqlite').is_file(), 'Disposable loopback fixture required'
origin = 'http://127.0.0.1:18765'
settings_url = origin + '/wp-admin/admin.php?page=zen-options'


def wp(*args):
    result = subprocess.run(
        ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root',
         '--path=' + str(fixture / 'runtime'), *args],
        capture_output=True, text=True,
    )
    assert result.returncode == 0, result.stderr
    return result.stdout.strip()


def option(key):
    return json.loads(wp('option', 'get', key, '--format=json'))


def setoption(key, value):
    wp('option', 'update', key, json.dumps(value), '--format=json')


identity = json.loads(wp('eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),FQDB,get_template()]);'))
assert identity == ['local', origin, str(fixture / 'db' / 'zen.sqlite'), 'zen'], identity
categories = json.loads(wp('eval', 'echo wp_json_encode(array_values(array_map(static fn($c) => ["id" => $c->term_id, "url" => get_category_link($c->term_id)], get_categories(["hide_empty" => true]))));'))
assert len(categories) >= 2, 'Fixture requires at least two nonempty categories'
post_url = wp('eval', 'echo get_permalink(get_posts(["post_type" => "post", "post_status" => "publish", "has_password" => false, "numberposts" => 1])[0]);')
assert post_url.startswith(origin + '/'), post_url
retired = {'zen_category_ids': [categories[0]['id']], 'zen_content_width': 1080}
for key, value in retired.items():
    setoption(key, value)
retired = {key: option(key) for key in retired}
script_url = 'https://code-fixture.invalid/loader.js'
head = ('<!-- zen:early -->\n' + f'<script async src="{script_url}"></script>\n'
        + r'<script id="zen-code-fixture-head">window.zenCodeFixtureHead = "fixture\nhead";</script>')
body = '<span id="zen-code-fixture-body" hidden></span>'
footer = r'<script id="zen-code-fixture-footer">window.zenCodeFixtureFooter = "fixture\nfooter";</script>'
fields = {'head': head, 'body': body, 'footer': footer}

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(
        executable_path='/usr/lib/chromium/chromium',
        args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
    )
    group = Path('/proc/self/cgroup').read_text()
    scope = Path('/sys/fs/cgroup') / group.strip().split('::/', 1)[1]
    assert (scope / 'memory.max').read_text().strip() == '4294967296'
    assert (scope / 'memory.swap.max').read_text().strip() == '0'
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{p["id"]}/cgroup').read_text() == group for p in processes)
    context = browser.new_context(viewport={'width': 1440, 'height': 900})
    cookies = json.loads((fixture / 'cookies.json').read_text())
    context.add_cookies(cookies)
    page = context.new_page()
    page.set_default_timeout(6_000)
    dialogs, requested, unexpected_external = [], [], []
    confirm_reset = False

    def route_resource(route):
        url = route.request.url
        if url == script_url:
            requested.append(url)
            route.fulfill(content_type='application/javascript', body='window.zenCodeFixtureLoader = true;')
        elif url.startswith(origin + '/') or url.startswith('data:'):
            route.continue_()
        else:
            if route.request.resource_type == 'script':
                unexpected_external.append(url)
            route.abort()

    def handle_dialog(dialog):
        if confirm_reset and dialog.type == 'confirm':
            dialogs.append('reset-confirm')
            dialog.accept()
        else:
            dialogs.append(dialog.type)
            dialog.dismiss()

    context.route('**/*', route_resource)
    page.on('dialog', handle_dialog)

    def settings():
        response = page.goto(settings_url)
        assert response.ok and page.url.startswith(settings_url)
        assert page.locator('#zen-options-form').count() == 1
        assert page.locator('#zen-code > summary').is_visible()
        assert page.locator('#zen_category_ids, [name="zen_category_ids[]"], .zen-category-choices, #zen-categories-help, #zen_content_width, #zen-layout').count() == 0
        page.locator('#zen-code > summary').click()
        help_text = page.locator('#zen_code_head').locator('..').locator('p.description')
        assert help_text.inner_text() == '在 </head> 前输出'
        assert help_text.inner_html() == '在 &lt;/head&gt; 前输出'

    def fill_code(position, text):
        field = page.locator(f'#zen_code_{position}')
        if field.is_visible():
            field.fill(text)
        else:
            field.evaluate('(field, text) => field.nextElementSibling.CodeMirror.setValue(text)', text)
        assert field.input_value() == text

    def save():
        with page.expect_navigation():
            page.locator('#zen_save_top').click()
        assert page.locator('.notice-success,.updated').count() > 0
        assert page.locator('#zen-save-state').inner_text() == '没有未保存的修改'
        assert not dialogs, dialogs

    def retired_unchanged():
        for key, value in retired.items():
            assert option(key) == value, (key, option(key), value)

    def public_layout(reading):
        response = page.goto(categories[0]['url'])
        assert response.ok and page.locator('body').evaluate(
            '(body, id) => body.classList.contains("category-" + id)', categories[0]['id'])
        actual = page.locator('.zen-category-track > a').evaluate_all('es => es.slice(1).map(e => e.href)')
        assert actual == [category['url'] for category in categories], actual
        assert page.evaluate('getComputedStyle(document.documentElement).getPropertyValue("--zen-content-width").trim()') == '1000px'
        page.goto(post_url)
        assert page.locator('#post-content').count() == 1
        assert page.locator('#post-content').evaluate('e => e.getBoundingClientRect().width') == reading
        assert page.evaluate('getComputedStyle(document.documentElement).getPropertyValue("--zen-reading-width").trim()') == f'{reading}px'
        assert page.locator('main').evaluate('e => e.getBoundingClientRect().width') <= 1000

    settings()
    for width in (320, 390, 768, 1440):
        page.set_viewport_size({'width': width, 'height': 900})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), width
    page.locator('#zen_reading_width').fill('720')
    page.locator('#zen_footer_text').fill('Ordinary native save')
    for position, text in fields.items():
        fill_code(position, text)
    save()
    retired_unchanged()
    assert option('zen_footer_text') == 'Ordinary native save'
    settings()
    for position, text in fields.items():
        assert page.locator(f'#zen_code_{position}').input_value() == text, position
        stored = option('zen_code_' + position)
        assert stored.replace('\r\n', '\n') == text, position
        database_value = json.loads(wp('eval', '$wpdb=$GLOBALS["wpdb"]; $r=$wpdb->get_row($wpdb->prepare("SELECT option_value FROM " . $wpdb->options . " WHERE option_name=%s", "zen_code_' + position + '")); echo wp_json_encode($r->option_value);'))
        assert database_value == stored, position
    assert not requested, 'Saved code must not execute in wp-admin'
    print('PASS generic escaped help, retired UI absence and ordinary save retain legacy rows', flush=True)

    context.clear_cookies()
    response = page.goto(origin + '/')
    assert response.ok and requested == [script_url]
    assert response.text().count(option('zen_code_head')) == 1, 'Stored marker is an ordinary comment, with exact saved bytes output once'
    tag = page.locator('head script[src="' + script_url + '"]')
    assert tag.count() == 1
    assert tag.evaluate('''tag => [...document.head.querySelectorAll('link[rel="stylesheet"]')].some(
        style => style.compareDocumentPosition(tag) & Node.DOCUMENT_POSITION_FOLLOWING
    )'''), 'Saved head code must retain ordinary head order'
    assert page.locator('head > #zen-code-fixture-head').count() == 1
    assert page.evaluate('window.zenCodeFixtureHead') == 'fixture\nhead'
    assert page.evaluate('window.zenCodeFixtureLoader') is True
    assert page.locator('body > #zen-code-fixture-body').count() == 1
    assert page.locator('body #zen-code-fixture-footer').count() == 1
    assert page.evaluate('window.zenCodeFixtureFooter') == 'fixture\nfooter'
    assert not page.locator('body').evaluate('e => e.classList.contains("logged-in")')
    print('PASS anonymous standard head order and three-position output with literal code preserved', flush=True)

    context.add_cookies(cookies)
    settings()
    for position in fields:
        fill_code(position, '')
    save()
    retired_unchanged()
    for position in fields:
        assert option('zen_code_' + position) == ''
    context.clear_cookies()
    request_count = len(requested)
    page.goto(origin + '/')
    assert page.locator('script[src="' + script_url + '"], #zen-code-fixture-head, #zen-code-fixture-body, #zen-code-fixture-footer').count() == 0
    assert len(requested) == request_count, 'Cleared code must not make another loader request'
    public_layout(720)
    print('PASS native clear stops code and requests; all nonempty categories and independent reading width render', flush=True)

    context.add_cookies(cookies)
    settings()
    stale = page.locator('#zen-options-form').evaluate('form => Object.fromEntries(new FormData(form))')
    assert stale['_wpnonce'], 'Native Settings API nonce required'
    stale.update({'action': 'update', 'zen_category_ids[]': str(categories[1]['id']),
                  'zen_content_width': '1200', 'zen_footer_text': 'Stale payload active footer'})
    response = context.request.post(origin + '/wp-admin/options.php', form=stale)
    assert response.ok
    assert option('zen_footer_text') == 'Stale payload active footer'
    retired_unchanged()
    context.clear_cookies()
    public_layout(720)
    assert 'Stale payload active footer' in page.locator('.zen-site-footer').inner_text()
    print('PASS stale native POST saves an active field and leaves both retired rows and layout unchanged', flush=True)

    context.add_cookies(cookies)
    settings()
    confirm_reset = True
    with page.expect_navigation():
        page.locator('[form="zen-reset-form"]').click()
    confirm_reset = False
    assert dialogs == ['reset-confirm'], dialogs
    dialogs.clear()
    assert 'zen_reset=1' in page.url and page.locator('.notice-success').count() > 0
    retired_unchanged()
    assert page.locator('#zen_reading_width').input_value() == '800'
    assert page.locator('#zen_footer_text').input_value() == ''
    context.clear_cookies()
    public_layout(800)
    assert len(requested) == request_count and not unexpected_external, unexpected_external
    evidence = fixture / 'evidence'
    evidence.mkdir(exist_ok=True)
    for width in (1440, 390):
        page.set_viewport_size({'width': width, 'height': 900})
        page.goto(origin + '/')
        assert page.locator('script[src="' + script_url + '"], #zen-code-fixture-body').count() == 0
        page.screenshot(path=str(evidence / f'settings-defaults-{width}.png'), full_page=True)
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{p["id"]}/cgroup').read_text() == group for p in processes)
    assert not dialogs, dialogs
    print('PASS confirmed native reset keeps retired rows, restores active defaults and makes no extra external requests', flush=True)
    browser.close()
