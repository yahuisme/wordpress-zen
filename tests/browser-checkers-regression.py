"""Exercise the maintained browser checkers without launching a browser."""
import ast
from pathlib import Path

root = Path(__file__).resolve().parent
source = ast.parse((root / 'list-width-regression.py').read_text())
expression = next(node.value for node in ast.walk(source) if isinstance(node, ast.Assign) and any(isinstance(target, ast.Name) and target.id == 'error' for target in node.targets))
checker = compile(ast.Expression(expression), 'list-width-regression.py:error', 'eval')

def rejects(items, mode='standard', overflow=False):
    # Evaluate only the local maintained predicate AST with fixed test fixtures;
    # no user, network or serialized input is executable here.
    return eval(checker, {'d': {'overflow': overflow, 'items': items}, 'mode': mode, 'expected_ids': {'4'}})

for mode in ('standard', 'compact'):
    assert rejects([], mode), 'Empty list must not pass ' + mode
    assert rejects([{'id': '99', 'title': 400, 'excerpt': None if mode == 'compact' else 400}], mode), 'Wrong fixture rows must fail'
assert rejects([{'id': '4', 'title': 400, 'excerpt': 200}]), 'Width mismatch must fail'
assert rejects([{'id': '4', 'title': 400, 'excerpt': 400}], overflow=True), 'Overflow must fail'
assert not rejects([{'id': '4', 'title': 400, 'excerpt': 400}]), 'Valid standard list must pass'
assert not rejects([{'id': '4', 'title': 400, 'excerpt': None}], 'compact'), 'Valid compact list must pass'

page_review = (root / 'page-review-regression.py').read_text()
assert "'/?tag_id='" not in page_review, 'Tag coverage must use a native public tag route'
assert "tag-" in page_review and 'body' in page_review, 'Tag page identity must be asserted'
print('PASS browser checker empty/wrong rows, geometry controls and tag identity contract')
