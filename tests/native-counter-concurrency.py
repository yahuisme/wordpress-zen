"""Run isolated native concurrency: ZEN_TEST_ISOLATED=1 python tests/native-counter-concurrency.py /fixture/wp-load.php [cold|warm|same]."""
from pathlib import Path
import json, os, subprocess, sys, tempfile, time
if os.environ.get('ZEN_TEST_ISOLATED') != '1' or len(sys.argv) < 2:
    raise SystemExit('Set ZEN_TEST_ISOLATED=1 and pass isolated wp-load.php')
worker = Path(__file__).with_suffix('.php')
command = ['php', str(worker), sys.argv[1]]
def run(*args):
    p = subprocess.run(command + list(map(str, args)), text=True, capture_output=True, timeout=30)
    if p.returncode: raise RuntimeError(p.stdout + p.stderr)
    return json.loads(p.stdout)
scenarios = {'cold': ('cold', ['192.0.2.241', '192.0.2.242'], 2), 'warm': ('40', ['192.0.2.241', '192.0.2.242'], 42), 'same': ('cold', ['192.0.2.241', '192.0.2.241'], 1)}
failures = 0
for name in sys.argv[2:] or scenarios:
    initial, ips, expected = scenarios[name]
    post_id = run('create', initial)['id']
    try:
        with tempfile.TemporaryDirectory(prefix='zen-counter-', dir=os.environ['TMPDIR']) as directory:
            barrier = str(Path(directory)/'ready')
            jobs = [subprocess.Popen(command + ['visit', str(post_id), ip, barrier], text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE) for ip in ips]
            until = time.monotonic() + 15
            while len(list(Path(directory).glob('ready.*'))) < len(jobs):
                if any(p.poll() is not None for p in jobs) or time.monotonic() > until:
                    for p in jobs: p.kill()
                    raise RuntimeError('Workers failed before barrier: ' + repr([p.communicate() for p in jobs]))
                time.sleep(.005)
            Path(barrier+'.go').touch()
            results = []
            for p in jobs:
                out, err = p.communicate(timeout=20)
                if p.returncode: raise RuntimeError(out+err)
                results.append(json.loads(out))
            bucket_writes = sum(len(p.read_text().splitlines()) for p in Path(directory).glob('ready.bucket.*'))
        result = run('inspect', post_id, *dict.fromkeys(ips))
        passed = result['count'] == expected and result['rows'] == 1 and bucket_writes == len(set(ips)) and all(v[0] is not False and 295 <= v[1] <= 300 for v in result['buckets'].values())
        print(json.dumps({'scenario': name, 'expected': expected, 'result': result, 'workers': results, 'bucket_writes': bucket_writes, 'passed': passed}), flush=True)
        failures += not passed
    finally:
        if not run('delete', post_id, *dict.fromkeys(ips))['deleted']: raise RuntimeError('Fixture cleanup failed')
print('NATIVE_COUNTER_CONCURRENCY_COMPLETE failures=' + str(failures))
raise SystemExit(bool(failures))
