"""Exercise runtime protection and real PHP config generation offline, without a database."""
import os
from pathlib import Path
import shutil
import subprocess
from tempfile import TemporaryDirectory
import zipfile

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

    # Drive the shipped setup through config generation. Only the network and
    # WP-CLI boundaries are fixtures; bash/cp/unzip/sed and PHP stay real.
    php = shutil.which('php')
    assert php, 'PHP is required to validate the generated configuration'
    (bin_dir / 'php').unlink()
    archive = root / 'sqlite-fixture.zip'
    with zipfile.ZipFile(archive, 'w') as fixture:
        fixture.writestr('sqlite-database-integration-fixture/db.copy', """<?php
// Match the upstream drop-in's single-quoted path and fallback contract.
$sqlite_plugin_implementation_folder_path = '{SQLITE_IMPLEMENTATION_FOLDER_PATH}';
if (!file_exists($sqlite_plugin_implementation_folder_path)) {
    $sqlite_plugin_implementation_folder_path = realpath(__DIR__ . '/plugins/sqlite-database-integration');
}
if (!$sqlite_plugin_implementation_folder_path || !file_exists($sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php')) {
    throw new RuntimeException('SQLite fixture implementation not found');
}
define('ZEN_TEST_SQLITE_PLUGIN', '{SQLITE_PLUGIN}');
require_once $sqlite_plugin_implementation_folder_path . '/wp-includes/sqlite/db.php';
""")
        fixture.writestr('sqlite-database-integration-fixture/wp-includes/sqlite/db.php', """<?php
define('ZEN_TEST_SQLITE_IMPLEMENTATION', __FILE__);
""")
    (bin_dir / 'curl').write_text('''#!/bin/bash
set -euo pipefail
[[ "$#" == 4 && "$1" == -fsSL && "$2" == zen-test:sqlite-fixture && "$3" == -o ]] || exit 94
cp -- "$ZEN_TEST_SQLITE_ARCHIVE" "$4"
''')
    wpcli = root / 'fixture-wp-cli.php'
    wpcli.write_text('''<?php
$runtime = null;
foreach ($argv as $arg) {
    if (0 === strpos($arg, '--path=')) $runtime = substr($arg, 7);
}
if (!$runtime) exit(95);
if (array_slice($argv, 1, 2) === ['core', 'download']) {
    file_put_contents($runtime . '/wp-settings.php', '<?php define("ZEN_TEST_SETTINGS_LOADED", true);');
    exit(0);
}
if (array_slice($argv, 1, 2) === ['core', 'install']) {
    file_put_contents($runtime . '/config-reached', 'core install');
    exit(90); // Stop before any install, database, or fixture creation.
}
exit(96);
''')
    config_env = dict(env, WPCLI_BIN=str(wpcli), ZEN_SQLITE_URL='zen-test:sqlite-fixture',
                      ZEN_TEST_SQLITE_ARCHIVE=str(archive))
    inspect = '''
require $argv[1] . '/wp-config.php';
if (DB_DIR !== $argv[1] . '/db/' || !is_dir(DB_DIR) || !ZEN_TEST_SETTINGS_LOADED) exit(97);
if (WP_HOME !== 'http://127.0.0.1:18765' || WP_ENVIRONMENT_TYPE !== 'local') exit(98);
require $argv[1] . '/wp-content/db.php';
if (ZEN_TEST_SQLITE_IMPLEMENTATION !== $argv[1] . '/wp-content/plugins/sqlite-database-integration/wp-includes/sqlite/db.php'
    || ZEN_TEST_SQLITE_PLUGIN !== 'sqlite-database-integration/load.php') exit(99);
echo "config+dropin ok\\n";
'''
    failures = []
    cases: list[tuple[str, Path | None]] = [(name, root / name) for name in (
        'ordinary runtime', "owner's runtime", r'back\slash runtime', r'double\\slash runtime',
        'pipe|runtime', 'amp&runtime',
    )]
    quoted_parent = root / "owner's temp"
    quoted_parent.mkdir()
    cases.append(('default runtime under quoted parent', None))
    for name, runtime in cases:
        overrides = dict(config_env)
        if runtime is None:
            overrides['RUNNER_TEMP'] = str(quoted_parent)
        else:
            overrides['ZEN_CI_RUNTIME'] = str(runtime)
        result = subprocess.run(['bash', str(SETUP), '--prepare-only'], env=overrides,
                                capture_output=True, text=True, timeout=10)
        if runtime is None:
            generated = list(quoted_parent.glob('zen-native.*'))
            assert len(generated) == 1, generated
            runtime = generated[0]
        files = ('private.php', 'wp-config.php', 'wp-content/db.php')
        if result.returncode != 90 or not (runtime / 'config-reached').is_file():
            failures.append((name, 'config generation did not reach install boundary', result.returncode, result.stderr))
        if not all((runtime / filename).is_file() for filename in files):
            failures.append((name, 'generated config files missing', result.returncode, result.stderr))
            continue
        for filename in files:
            lint = subprocess.run([php, '-l', str(runtime / filename)], capture_output=True, text=True, timeout=5)
            if lint.returncode:
                failures.append((name, filename, lint.returncode, lint.stdout + lint.stderr))
        loaded = subprocess.run([php, '-r', inspect, str(runtime)], capture_output=True, text=True, timeout=5)
        if loaded.returncode != 0 or loaded.stdout != 'config+dropin ok\n':
            failures.append((name, 'load config and drop-in', loaded.returncode, loaded.stdout + loaded.stderr))
        assert not list(runtime.rglob('*.sqlite')), 'Offline config probe must not create a database'
        if not any(failure[0] == name for failure in failures):
            print('PASS generated config/drop-in PHP syntax and resolved paths:', name)

    # These controls prove that success is not merely the planned WP-CLI stop:
    # PHP must actually load and validate the generated files and drop-in path.
    ordinary = cases[0][1]
    assert ordinary is not None
    private = ordinary / 'private.php'
    valid_private = private.read_bytes()
    private.write_text("<?php require 'unterminated;\n")
    malformed = subprocess.run([php, '-l', str(private)], capture_output=True, text=True, timeout=5)
    assert malformed.returncode != 0 and 'Parse error' in malformed.stdout + malformed.stderr, malformed
    private.write_bytes(valid_private)
    dropin = ordinary / 'wp-content/db.php'
    hidden = dropin.with_suffix('.disabled')
    dropin.rename(hidden)
    missing = subprocess.run([php, '-r', inspect, str(ordinary)], capture_output=True, text=True, timeout=5)
    assert missing.returncode != 0 and 'db.php' in missing.stderr, missing
    hidden.rename(dropin)
    print('PASS malformed PHP and missing drop-in negative controls')
    assert not failures, failures
