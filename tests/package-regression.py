"""Validate the actual release workflow against a local working-tree archive."""
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1] / 'theme' / 'zen'
PROJECT = ROOT.parent.parent
workflow = (PROJECT / '.github/workflows/release.yml').read_text()
validator_match = re.search(r"<<'PY'\n(.*?)\n          PY", workflow, re.S)
version_match = re.search(r'^Version:\s*(\S+)', (ROOT / 'style.css').read_text(), re.M)
assert validator_match and version_match, 'Release workflow contract missing'
validator = '\n'.join(line[10:] for line in validator_match.group(1).splitlines())
version = version_match.group(1)
assert '  workflow_dispatch:' in workflow and not re.search(r'^  (push|pull_request):', workflow, re.M)
assert '  contents: read' in workflow
assert 'release_notes:' in workflow
assert 'uses: actions/upload-artifact@v4' in workflow
assert 'gh release create' not in workflow
assert 'gh release upload' not in workflow
assert 'GH_TOKEN:' not in workflow
assert '    timeout-minutes: 15' in workflow, 'Manual packaging needs a bounded job timeout'
assert '          compression-level: 0' in workflow, 'Do not recompress the release ZIP'

# 发布文件集由 .gitattributes 的 export-ignore 规则决定。
# 开发文件不得出现在发布包中。
forbidden_prefixes = ('zen/tests/', 'zen/.github/', 'zen/AGENTS.md')
# 所有纳入版本控制的主题文件都应出现在发布包中。
tracked = subprocess.check_output(['git', 'ls-files', 'theme/zen'], cwd=PROJECT, text=True).split()
tracked = [f.removeprefix('theme/zen/') for f in tracked]
gitattributes = (PROJECT / '.gitattributes').read_text()
ignored = set()
for line in gitattributes.splitlines():
    m = re.match(r'^(\S+)\s+export-ignore', line)
    if m:
        pattern = m.group(1).lstrip('/')
        for f in tracked:
            if f == pattern or f.startswith(pattern.rstrip('/') + '/'):
                ignored.add(f)
expected_files = [f for f in tracked if f not in ignored]
assert 'assets/css/admin.css' in expected_files, 'Design asset missing from expected files'
assert 'assets/css/reading.css' in expected_files
assert 'assets/css/layout.css' in expected_files
assert 'assets/js/admin.js' in expected_files

with tempfile.TemporaryDirectory(prefix='zen-package-') as temp:
    temp = Path(temp)
    # An isolated Git index packages local edits without staging or committing them.
    env = dict(os.environ, GIT_INDEX_FILE=str(temp / 'index'))
    subprocess.run(['git', 'read-tree', 'HEAD'], cwd=PROJECT, env=env, check=True)
    subprocess.run(['git', 'add', '--'] + ['theme/zen/' + f for f in expected_files], cwd=PROJECT, env=env, check=True)
    tree = subprocess.check_output(['git', 'write-tree'], cwd=PROJECT, env=env, text=True).strip()
    package = temp / 'zen.zip'
    subprocess.run(['git', 'archive', '--format=zip', '--prefix=zen/', tree + ':theme/zen',
                    '-o', str(package)], cwd=PROJECT, check=True)
    original = package.read_bytes()

    def validate():
        return subprocess.run([sys.executable, '-c', validator, version], cwd=temp,
                              capture_output=True, text=True)

    result = validate()
    assert result.returncode == 0, result.stderr
    with zipfile.ZipFile(package) as archive:
        members = {name: archive.read(name) for name in archive.namelist() if not name.endswith('/')}
    assert len(members) == len(expected_files)
    for name, data in members.items():
        assert data == (ROOT / name.removeprefix('zen/')).read_bytes(), name
    print(f'PASS release ZIP: {len(members)} files match source; metadata accepted')

    cases = [
        ('theme name', 'zen/style.css', b'Theme Name: Zen', b'Theme Name: WordPress Zen Theme'),
        ('missing WordPress requirement', 'zen/style.css', b'Requires at least: 6.5', b''),
        ('wrong PHP requirement', 'zen/style.css', b'Requires PHP: 8.0', b'Requires PHP: 7.4'),
        ('duplicate PHP requirement', 'zen/style.css', b'Requires PHP: 8.0', b'Requires PHP: 8.0\nRequires PHP: 8.0'),
        ('version mismatch', 'zen/style.css', ('Version: ' + version).encode(), b'Version: 0.0.0'),
        ('README mismatch', 'zen/README.md', b'PHP 8.0+', b'PHP 7.4+'),
    ]
    for label, target, old, new in cases:
        assert old in members[target]
        with zipfile.ZipFile(package, 'w') as archive:
            for name, data in members.items():
                archive.writestr(name, data.replace(old, new) if name == target else data)
        result = validate()
        assert result.returncode != 0, label
        print('PASS rejects ' + label)
    with zipfile.ZipFile(package, 'w') as archive:
        for name, data in members.items():
            archive.writestr(name, data)
        archive.writestr('zen/tests/unwanted.txt', 'maintenance file')
    assert validate().returncode != 0
    print('PASS rejects extra maintenance files')

    if len(sys.argv) == 2:
        output = Path(sys.argv[1]).resolve()
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_bytes(original)
        print('Package: ' + str(output))
