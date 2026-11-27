<?php
/** Read-only native PHP regression for backend fixes; no database mutations or browser. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$complete = false;
register_shutdown_function(static function () use (&$complete) {
    if (!$complete) { fwrite(STDERR, "NATIVE_BACKEND_INCOMPLETE\n"); exit(1); }
});
$runtime = dirname(realpath($argv[1]) ?: '');
if (!is_file($runtime . '/.zen-isolated-fixture')) throw new RuntimeException('Disposable fixture marker required');
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];
require_once ABSPATH . 'wp-admin/includes/template.php';
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') {
    throw new RuntimeException('Disposable local loopback Zen installation required');
}
if (!defined('FQDB') || realpath(FQDB) !== realpath(dirname($runtime) . '/db/zen.sqlite')) {
    throw new RuntimeException('Fixture database path mismatch');
}
foreach (array('options.php', 'schema.php') as $file) {
    if (hash_file('sha256', get_template_directory() . '/inc/' . $file) !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/inc/' . $file)) {
        throw new RuntimeException('Installed backend source mismatch: ' . $file);
    }
}
$checks = $failures = 0;
function backend_native_check($ok, $label) {
    $GLOBALS['checks']++;
    $GLOBALS['failures'] += !$ok;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
}
zen_register_options();
$sanitize = get_registered_settings()['zen_footer_text']['sanitize_callback'];
$saved_footer = get_option('zen_footer_text');
foreach (array(array('unexpected'), null, 17, new stdClass()) as $invalid) {
    $before = count(get_settings_errors());
    backend_native_check($sanitize($invalid) === $saved_footer, 'registered footer sanitizer preserves stored value for ' . gettype($invalid));
    $errors = get_settings_errors();
    backend_native_check(count($errors) === $before + 1 && end($errors)['code'] === 'zen_footer_text_invalid', 'malformed footer receives native settings error');
}
backend_native_check($sanitize('') === '', 'explicit empty footer remains clearable');
backend_native_check($sanitize('<a href="/saved/">Footer</a>') === '<a href="/saved/">Footer</a>', 'valid safe footer HTML remains unchanged');
$clean = $sanitize('<a href="javascript:alert(1)" onclick="alert(2)">Footer</a>');
backend_native_check(!str_contains($clean, 'javascript:') && !str_contains($clean, 'onclick') && str_contains($clean, '>Footer</a>'), 'footer still uses native KSES allowlist');
foreach (array(
    '<p>First<p>Second</p>' => 'First Second',
    '<ul><li>first<li>second</ul>' => 'first second',
    '<table><tr><td>First</td><td>Second</td></tr></table>' => 'First Second',
    '<dl><dt>Term<dd>Meaning</dl>' => 'Term Meaning',
    '<p>inter<em>national</em><br />news</p>' => 'international news',
    '<p>&lt;br&gt;<p>literal</p>' => '<br> literal',
) as $source => $expected) {
    backend_native_check(zen_clean_description($source) === $expected, 'native Core text cleanup: ' . $source);
}
backend_native_check(get_option('zen_footer_text') === $saved_footer, 'read-only sanitizer checks leave stored footer unchanged');
$complete = true;
printf("NATIVE_BACKEND_COMPLETE %d checks, %d failures; WordPress %s; PHP %s\n", $checks, $failures, $GLOBALS['wp_version'], PHP_VERSION);
exit($failures ? 1 : 0);
