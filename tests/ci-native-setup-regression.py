"""Exercise runtime selection without deletion, downloads, or a database."""
import os
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory

SETUP = Path(__file__).with_name('ci-native-setup.sh').resolve()

with TemporaryDirectory(prefix='zen-setup-test-') as temp:
    root = Path(temp)
    bin_dir = root / 'bin'
    bin_dir.mkdir()
    for command, body in (
        ('rm', 'exit 92'),
        ('curl', 'printf "%s\\n" "${@: -1}"; exit 91'),
        ('php', 'printf "%s\\n" "$1"; exit 93'),
    ):
        stub = bin_dir / command
        stub.write_text('#!/bin/bash\n' + body + '\n')
        stub.chmod(0o700)
    env = dict(os.environ, PATH=str(bin_dir) + os.pathsep + os.environ['PATH'], RUNNER_TEMP=temp)
    for key in ('ZEN_CI_RUNTIME', 'WPCLI_BIN'):
        env.pop(key, None)

    def run(runtime=None, **overrides):
        if runtime is not None:
            overrides['ZEN_CI_RUNTIME'] = str(runtime)
        return subprocess.run(['bash', str(SETUP), '--prepare-only'], env=dict(env, **overrides), capture_output=True, text=True, timeout=5)

    retained = root / 'retained'
    retained.mkdir()
    sentinel = retained / 'keep.txt'
    sentinel.write_text('keep')
    alias = root / 'symlink'
    alias.symlink_to(retained)
    for path in (retained, alias):
        result = run(path)
        assert result.returncode == 1 and 'Choose a new ZEN_CI_RUNTIME' in result.stderr, result
        assert sentinel.read_text() == 'keep'
    print('PASS retained and symlink runtimes refused before downloads')

    first, second = run(), run()
    assert first.returncode == second.returncode == 91, (first, second)
    generated = [Path(result.stdout.strip()).parent for result in (first, second)]
    assert generated[0] != generated[1] and all(p.parent == root and (p / 'db').is_dir() for p in generated), generated
    requested = root / 'explicit runtime'
    result = run(requested)
    assert result.returncode == 91 and Path(result.stdout.strip()).parent == requested and (requested / 'db').is_dir(), result
    print('PASS distinct default runtimes and a fresh explicit path reach the downloader')

    sentinel.chmod(0o600)
    result = run(WPCLI_BIN=str(sentinel))
    assert result.returncode == 93 and result.stdout.strip() == str(sentinel), result
    assert sentinel.read_text() == 'keep' and sentinel.stat().st_mode & 0o777 == 0o600
    result = run(WPCLI_BIN=str(root / 'missing.phar'))
    assert result.returncode == 1 and 'WPCLI_BIN' in result.stderr and not result.stdout, result
    print('PASS WPCLI_BIN is consumed as an existing input, never a download destination')
