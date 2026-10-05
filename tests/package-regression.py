"""Validate the actual release workflow against a local working-tree archive."""
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
workflow = (ROOT / '.github/workflows/release.yml').read_text()
file_match = re.search(r'RELEASE_FILES=\(\n(.*?)\n          \)', workflow, re.S)
validator_match = re.search(r"<<'PY'\n(.*?)\n          PY", workflow, re.S)
version_match = re.search(r'^Version:\s*(\S+)', (ROOT / 'style.css').read_text(), re.M)
assert file_match and validator_match and version_match, 'Release workflow contract missing'
files = file_match.group(1).split()
for asset in ('assets/css/admin.css', 'assets/css/reading.css', 'assets/css/layout.css', 'assets/js/admin.js'):
    assert asset in files, 'Design/runtime asset missing from package: ' + asset
validator = '\n'.join(line[10:] for line in validator_match.group(1).splitlines())
version = version_match.group(1)
assert '--title "Zen v$VERSION"' in workflow
assert '  workflow_dispatch:' in workflow and not re.search(r'^  (push|pull_request):', workflow, re.M)

with tempfile.TemporaryDirectory(prefix='zen-package-') as temp:
    temp = Path(temp)
    # An isolated Git index packages local edits without staging or committing them.
    env = dict(os.environ, GIT_INDEX_FILE=str(temp / 'index'))
    subprocess.run(['git', 'read-tree', 'HEAD'], cwd=ROOT, env=env, check=True)
    subprocess.run(['git', 'add', '--', *files], cwd=ROOT, env=env, check=True)
    tree = subprocess.check_output(['git', 'write-tree'], cwd=ROOT, env=env, text=True).strip()
    package = temp / 'zen.zip'
    subprocess.run(['git', 'archive', '--format=zip', '--prefix=zen/', tree,
                    '-o', str(package), '--', *files], cwd=ROOT, check=True)
    original = package.read_bytes()

    def validate():
        return subprocess.run([sys.executable, '-c', validator, version], cwd=temp,
                              capture_output=True, text=True)

    result = validate()
    assert result.returncode == 0, result.stderr
    with zipfile.ZipFile(package) as archive:
        members = {name: archive.read(name) for name in archive.namelist() if not name.endswith('/')}
    assert len(members) == len(files)
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
