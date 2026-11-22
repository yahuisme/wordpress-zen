"""Exercise the maintained browser checkers without launching a browser."""
import ast
from copy import deepcopy
from pathlib import Path

root = Path(__file__).resolve().parent
source = ast.parse((root / 'list-width-regression.py').read_text())
expression = next(node.value for node in ast.walk(source) if isinstance(node, ast.Assign) and any(isinstance(target, ast.Name) and target.id == 'error' for target in node.targets))
checker = compile(ast.Expression(expression), 'list-width-regression.py:error', 'eval')


def rejects(data, mode='standard', width=1440, expected_ids=frozenset({'4'})):
    # Evaluate only the local maintained predicate AST with fixed test fixtures;
    # no user, network or serialized input is executable here.
    rendered_ids = [item['id'] for item in data['items']] + data['latest']
    return eval(checker, {'data': data, 'mode': mode, 'width': width,
                          'expected_ids': expected_ids, 'rendered_ids': rendered_ids})


positive, negative = 0, 0
for mode in ('standard', 'compact'):
    for width in (320, 1440):
        item_width = 288 if width == 320 else 1000
        left = (width - item_width) / 2
        data = {
            'overflow': False,
            'site': '1000px',
            'page': {'width': item_width, 'left': left, 'right': left + item_width},
            'latest': [],
            'items': [{'id': '4', 'item': item_width, 'title': item_width,
                       'excerpt': None if mode == 'compact' else item_width,
                       'left': left, 'right': left + item_width}],
        }
        assert not rejects(data, mode, width), ('Valid list must pass', mode, width)
        positive += 1
        featured = deepcopy(data)
        featured['latest'] = ['5']
        assert not rejects(featured, mode, width, frozenset({'4', '5'})), ('Valid latest card must pass', mode, width)
        positive += 1

        # Each mutation starts from a passing measurement, so unrelated failures
        # cannot disguise an ineffective geometry or row-identity assertion.
        mutations = [
            ('empty list', ('items',), []),
            ('wrong fixture rows', ('items', 0, 'id'), '99'),
            ('duplicate rows', ('items',), data['items'] * 2),
            ('duplicate latest row', ('latest',), ['4']),
            ('overflow', ('overflow',), True),
            ('wrong site width', ('site',), '1200px'),
            ('page too wide', ('page', 'width'), min(width, 1000) + 2),
            ('page before viewport', ('page', 'left'), -2),
            ('page after viewport', ('page', 'right'), width + 2),
            ('item too wide', ('items', 0, 'item'), min(width, 1000) + 2),
            ('item before viewport', ('items', 0, 'left'), -2),
            ('item after viewport', ('items', 0, 'right'), width + 2),
        ]
        if mode == 'standard':
            mutations.extend([
                ('missing standard excerpt', ('items', 0, 'excerpt'), None),
                ('title/excerpt width mismatch', ('items', 0, 'excerpt'), item_width - 2),
            ])
        else:
            mutations.append(('unexpected compact excerpt', ('items', 0, 'excerpt'), item_width))
        for label, path, value in mutations:
            invalid = deepcopy(data)
            target = invalid
            for key in path[:-1]:
                target = target[key]
            target[path[-1]] = value
            assert rejects(invalid, mode, width), (label, mode, width)
            negative += 1
        invalid = deepcopy(data)
        invalid['latest'] = ['5', '6']
        assert rejects(invalid, mode, width, frozenset({'4', '5', '6'})), 'Multiple latest cards must fail'
        negative += 1

for entrypoint in ('page-review-regression.py', 'native-settings-regression.py'):
    page_review = (root / entrypoint).read_text()
    assert "'/?tag_id='" not in page_review, 'Tag coverage must use a native public tag route: ' + entrypoint
    assert "tag-" in page_review and 'body' in page_review, 'Tag page identity must be asserted: ' + entrypoint
for entrypoint in ('design-browser-regression.py', 'native-boundary-regression.py', 'repair-ui-regression.py'):
    browser_source = (root / entrypoint).read_text()
    assert "'--single-process'" in browser_source and "'--no-zygote'" in browser_source, 'Legacy browser entrypoint must be single-process: ' + entrypoint
    assert 'memory.max' in browser_source and 'memory.swap.max' in browser_source and 'SystemInfo.getProcessInfo' in browser_source, 'Legacy browser entrypoint must verify containment: ' + entrypoint
assert "x['size']=='18px'" not in (root / 'design-browser-regression.py').read_text(), 'Compare native editor with the current reader, not retired typography'
assert "page.locator('.zen-site-header nav a:visible')" not in (root / 'repair-ui-regression.py').read_text(), 'No-script navigation must use the current native footer owner'
print(f'PASS browser checker: {positive} valid controls, {negative} invalid controls, tag identity and maintained browser-runner contracts')
