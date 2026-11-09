<?php
/** Isolated regression tests for the cache-independent reading-count endpoint. */
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['hooks'] = array();
$GLOBALS['counts'] = array();
$GLOBALS['posts'] = array();
$GLOBALS['cache'] = array();
$GLOBALS['logged_in'] = false;
$GLOBALS['settings_enabled'] = true;
$GLOBALS['singular'] = true;
$GLOBALS['preview'] = false;
$GLOBALS['feed'] = false;
$GLOBALS['queried_id'] = 42;
$GLOBALS['loop_id'] = 42;
$GLOBALS['bot_api'] = false;
$GLOBALS['checks'] = 0;
function add_action($hook, $callback) { $GLOBALS['hooks'][] = array($hook, $callback); }
function zen_get_option($key) { return $key === 'zen_show_reading_count' ? $GLOBALS['settings_enabled'] : ''; }
function is_singular($type = '') { return $GLOBALS['singular'] && 'post' === $type; }
function is_preview() { return $GLOBALS['preview']; }
function is_feed() { return $GLOBALS['feed']; }
function is_bot() { return $GLOBALS['bot_api']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function get_queried_object_id() { return $GLOBALS['queried_id']; }
function get_the_ID() { return $GLOBALS['loop_id']; }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta($id, $key, $single) { check($key === 'zen_view_count' && $single, 'existing meta field preserved'); return $GLOBALS['counts'][$id] ?? 0; }
function update_post_meta($id, $key, $value) { check($key === 'zen_view_count', 'write existing meta field'); $GLOBALS['counts'][$id] = $value; }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { check($ttl === 300, 'five-minute dedupe TTL preserved'); $GLOBALS['cache'][$key] = $value; }
class ReadingCountResponse extends RuntimeException {
    public function __construct(public bool $success, public array $data, public int $status) { parent::__construct('JSON response'); }
}
function wp_send_json_error($data, $status = 200) { throw new ReadingCountResponse(false, $data, $status); }
function wp_send_json_success($data, $status = 200) { throw new ReadingCountResponse(true, $data, $status); }
function number_format_i18n($number) { return number_format($number, 0, ',', '.'); }
function nocache_headers() { $GLOBALS['nocache'] = true; }
function check($condition, $message) { ++$GLOBALS['checks']; if (!$condition) throw new RuntimeException($message); }
$source = file_get_contents(dirname(__DIR__) . '/theme/zen/inc/options.php');
check(!str_contains($source, '整页缓存命中不计数'), 'settings must not claim cache hits never count');
$start = strpos($source, 'function zen_track_post_view');
$end = strpos($source, 'function zen_get_reading_count', $start);
if (false === $start || false === $end) throw new RuntimeException('Counter source boundary not found');
// Execute only trusted repository source against explicit WordPress boundary doubles.
eval(substr($source, $start, $end - $start));

$GLOBALS['posts'] = array(
    42 => (object) array('ID' => 42, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => ''),
    43 => (object) array('ID' => 43, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => ''),
    44 => (object) array('ID' => 44, 'post_type' => 'post', 'post_status' => 'draft', 'post_password' => ''),
    45 => (object) array('ID' => 45, 'post_type' => 'post', 'post_status' => 'private', 'post_password' => ''),
    46 => (object) array('ID' => 46, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => 'secret'),
);
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
check(!in_array(array('wp', 'zen_track_post_view'), $GLOBALS['hooks'], true), 'automatic render hook must be removed');
foreach (array('wp_ajax_nopriv_zen_post_view', 'wp_ajax_zen_post_view') as $hook) {
    check(in_array(array($hook, 'zen_ajax_post_view'), $GLOBALS['hooks'], true), 'anonymous and logged-in hooks registered');
}
// AJAX has no singular query: explicit IDs must work without frontend conditionals.
$GLOBALS['singular'] = false;
check(zen_track_post_view(42) === 1, 'explicit published post increments outside singular query');
check(zen_track_post_view(42) === 1, 'same IP dedupes within five minutes');
$_SERVER['REMOTE_ADDR'] = '192.0.2.2';
check(zen_track_post_view(42) === 2, 'different peer increments');
foreach (array(43, 44, 45, 46, 99999, -1, '42.0', array(42)) as $invalid) {
    check(zen_track_post_view($invalid) === false, 'reject invalid or non-public post id');
}
check(($GLOBALS['counts'][42] ?? 0) === 2, 'rejected IDs do not mutate counts');

function run_ajax($post_id, $method = 'POST', $logged_in = false) {
    $GLOBALS['nocache'] = false; $GLOBALS['logged_in'] = $logged_in;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = array('post_id' => $post_id);
    try { zen_ajax_post_view(); } catch (ReadingCountResponse $response) {
        check($GLOBALS['nocache'] === true, 'all endpoint responses disable caching');
        return $response;
    }
    throw new RuntimeException('Endpoint did not emit JSON');
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.3';
$response = run_ajax('42');
check($response->success && $response->data['count'] === number_format_i18n(3), 'AJAX POST increments and responds with localized count');
check(run_ajax('42')->data['count'] === number_format_i18n(3), 'duplicate POST returns real count without increment');
foreach (array('GET', 'HEAD', 'PUT', 'DELETE', '') as $method) {
    $response = run_ajax('42', $method);
    check(!$response->success && $response->status === 405, 'non-POST request rejected');
}
foreach (array('', '0', '-1', '42.0', '+42', ' 42', "42\n", '42x', '042', '4e1', str_repeat('9', 40), array('42'), 42.0, true) as $invalid) {
    $response = run_ajax($invalid);
    check(!$response->success && $response->status === 400, 'malformed post_id rejected');
}
$_POST = array();
try { zen_ajax_post_view(); } catch (ReadingCountResponse $response) {
    check(!$response->success && $response->status === 400, 'missing post_id rejected');
}
foreach (array('43', '44', '45', '46', '99999') as $invalid) {
    $response = run_ajax($invalid);
    check(!$response->success && $response->status === 404, 'unavailable post rejected by endpoint');
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.4';
$response = run_ajax('42', 'POST', true);
check($response->success && $response->data['count'] === number_format_i18n(3), 'logged-in AJAX reads count without increment');
foreach (array('Googlebot/2.1', 'Baiduspider', 'ExampleCrawler', 'HeadlessChrome/130') as $agent) {
    $_SERVER['HTTP_USER_AGENT'] = $agent;
    check(run_ajax('42')->data['count'] === number_format_i18n(3), 'crawler AJAX reads count without increment');
}
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
$GLOBALS['bot_api'] = true;
check(run_ajax('42')->data['count'] === number_format_i18n(3), 'optional bot API exclusion preserved');
$GLOBALS['bot_api'] = false;
foreach (array('', 'invalid', array('192.0.2.5')) as $peer) {
    $_SERVER['REMOTE_ADDR'] = $peer;
    check(run_ajax('42')->data['count'] === number_format_i18n(3), 'invalid peer never increments');
}
unset($_SERVER['REMOTE_ADDR']);
check(run_ajax('42')->data['count'] === number_format_i18n(3), 'missing peer never increments');
$GLOBALS['settings_enabled'] = false;
check(!run_ajax('42')->success, 'disabled counter rejects endpoint');
check(($GLOBALS['counts'][42] ?? 0) === 3, 'invalid, duplicate, bot and logged-in requests never increment');
$GLOBALS['settings_enabled'] = true;

// Keep no-argument compatibility, including query guards and loop fallback.
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
check(zen_track_post_view() === false, 'no-argument non-singular query rejected');
$GLOBALS['singular'] = true;
foreach (array('preview', 'feed') as $flag) {
    $GLOBALS[$flag] = true;
    check(zen_track_post_view() === false, 'no-argument preview/feed rejected');
    $GLOBALS[$flag] = false;
}
check(zen_track_post_view() === 4, 'no-argument published queried post still works');
$GLOBALS['queried_id'] = 0;
$_SERVER['REMOTE_ADDR'] = '192.0.2.11';
check(zen_track_post_view() === 5, 'no-argument loop fallback retained');
$GLOBALS['loop_id'] = 0;
check(zen_track_post_view() === false, 'missing queried and loop IDs rejected');
$GLOBALS['counts'][42] = 12345;
$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
check(run_ajax('42')->data['count'] === '12.346', 'localized grouped numeric count returned as string');

fwrite(STDOUT, "PASS reading count regression: {$GLOBALS['checks']} checks\n");
