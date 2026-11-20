"""Native standard/compact switching at the fixed 1000px site width."""
from pathlib import Path
import json
import subprocess
import sys

from playwright.sync_api import sync_playwright

label = sys.argv[2] if len(sys.argv) > 2 and not sys.argv[2].startswith('--') else 'green'
r = Path(sys.argv[1]).resolve()
assert (r / 'runtime/wp-load.php').is_file() and (r / 'db/zen.sqlite').is_file()
o = 'http://127.0.0.1:18765'
rows, failed = [], []


def cli(*args):
    result = subprocess.run(
        ['php', '/opt/test-tools/wordpress/wp-cli.phar', '--allow-root', '--path=' + str(r / 'runtime'), *args],
        capture_output=True, text=True,
    )
    assert result.returncode == 0, result.stderr
    return result.stdout.strip()


identity = json.loads(cli('eval', 'echo wp_json_encode([wp_get_environment_type(),home_url(),FQDB]);'))
assert identity == ['local', o, str(r / 'db/zen.sqlite')], identity
expected_ids = set(json.loads(cli('eval', 'echo wp_json_encode(array_map("strval",get_posts(["post_type"=>"post","post_status"=>"publish","posts_per_page"=>(int)get_option("posts_per_page"),"fields"=>"ids"])));')))
assert expected_ids, 'Homepage fixture requires published posts'
legacy = json.loads(cli('eval', 'echo wp_json_encode(get_option("zen_content_width", null));'))
with sync_playwright() as p:
    browser = p.chromium.launch(
        executable_path='/usr/lib/chromium/chromium',
        args=['--no-sandbox', '--disable-dev-shm-usage', '--single-process', '--no-zygote'],
    )
    group = Path('/proc/self/cgroup').read_text()
    scope = Path('/sys/fs/cgroup') / group.strip().split('::/', 1)[1]
    assert (scope / 'memory.max').read_text().strip() == '4294967296'
    assert (scope / 'memory.swap.max').read_text().strip() == '0'
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{process["id"]}/cgroup').read_text() == group for process in processes)
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    context.add_cookies(json.loads((r / 'cookies.json').read_text()))
    context.route('**/*', lambda route: route.continue_() if route.request.url.startswith(o + '/') or route.request.url.startswith('data:') else route.abort())
    page = context.new_page()
    dialogs = []

    def dismiss(dialog):
        dialogs.append(dialog.type)
        dialog.dismiss()

    page.on('dialog', dismiss)
    for mode in ('standard', 'compact', 'standard'):
        page.goto(o + '/wp-admin/admin.php?page=zen-options', wait_until='networkidle')
        assert page.locator('#zen-layout, #zen_content_width').count() == 0
        page.locator('#zen_list_style').select_option(mode)
        with page.expect_navigation(wait_until='networkidle'):
            page.locator('#zen_save_top').click()
        assert page.locator('.notice-success,.updated').count() > 0
        assert page.locator('#zen-save-state').inner_text() == '没有未保存的修改'
        assert not dialogs, dialogs
        assert cli('option', 'get', 'zen_list_style') == mode
        assert json.loads(cli('eval', 'echo wp_json_encode(get_option("zen_content_width", null));')) == legacy
        for width in (320, 390, 768, 1440, 2560):
            page.set_viewport_size({'width': width, 'height': 900})
            page.goto(o + '/', wait_until='networkidle')
            data = page.evaluate('''() => ({
                overflow: document.documentElement.scrollWidth > innerWidth + 1,
                site: getComputedStyle(document.documentElement).getPropertyValue('--zen-content-width').trim(),
                page: document.querySelector('main').getBoundingClientRect().toJSON(),
                latest: [...document.querySelectorAll('.zen-latest-card')].map(e => e.id.replace(/^post-/, '')),
                items: [...document.querySelectorAll('.zen-post-item')].map(e => {
                    const box = e.getBoundingClientRect(), title = e.querySelector('h2').getBoundingClientRect();
                    const excerpt = e.querySelector('.zen-post-excerpt');
                    return {id: e.id.replace(/^post-/, ''), item: box.width, title: title.width,
                        excerpt: excerpt ? excerpt.getBoundingClientRect().width : null, left: box.left, right: box.right};
                })
            })''')
            rendered_ids = [item['id'] for item in data['items']] + data['latest']
            error = (
                data['overflow'] or data['site'] != '1000px'
                or data['page']['width'] > min(width, 1000) + 1
                or data['page']['left'] < -1 or data['page']['right'] > width + 1
                or not data['items'] or set(rendered_ids) != expected_ids
                or len(rendered_ids) != len(expected_ids) or len(data['latest']) > 1
                or any(
                    item['item'] > min(width, 1000) + 1 or item['left'] < -1 or item['right'] > width + 1
                    or (mode == 'compact' and item['excerpt'] is not None)
                    or (mode == 'standard' and (item['excerpt'] is None or abs(item['title'] - item['excerpt']) > 1))
                    for item in data['items']
                )
            )
            row = {'content': 1000, 'mode': mode, 'viewport': width, 'measurements': data, 'passed': not error}
            rows.append(row)
            if error:
                failed.append(row)
        if failed and label == 'red':
            break
    processes = browser.new_browser_cdp_session().send('SystemInfo.getProcessInfo')['processInfo']
    assert processes and all(Path(f'/proc/{process["id"]}/cgroup').read_text() == group for process in processes)
    browser.close()
(r / (label + '-switch.json')).write_text(json.dumps(rows, ensure_ascii=False, indent=2))
print('Cases', len(rows), 'failures', len(failed))
print(json.dumps(failed[:2], ensure_ascii=False))
assert len(rows) == 15 and not failed, 'Fixed-width native compact-to-standard layout regression'
