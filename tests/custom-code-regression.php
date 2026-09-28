<?php
/** Run: php tests/custom-code-regression.php. No WordPress database required. */
define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['hooks'] = array();
$GLOBALS['settings'] = array();
$GLOBALS['options'] = array();
$GLOBALS['can_code'] = true;
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function register_setting($group, $key, $args) { $GLOBALS['settings'][$key] = $args; }
function current_user_can($cap) { return $cap === 'manage_options' || $GLOBALS['can_code']; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
require ABSPATH . 'inc/options.php';
zen_register_options();
$failed = 0;
function check($condition, $label) {
    global $failed;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failed++;
}
foreach (array('head' => 'wp_head', 'body' => 'wp_body_open', 'footer' => 'wp_footer') as $position => $hook) {
    $key = 'zen_code_' . $position;
    check(isset($GLOBALS['settings'][$key]), "$position code registered");
    if (!isset($GLOBALS['settings'][$key])) continue;
    $sanitize = $GLOBALS['settings'][$key]['sanitize_callback'];
    $code = '<script>window.test="C:\\fixture";</script><noscript>Fallback</noscript>';
    check($sanitize($code) === $code, "$position preserves executable markup and backslashes");
    $GLOBALS['options'][$key] = $code;
    $GLOBALS['can_code'] = false;
    check($sanitize('<script>replace()</script>') === $code, "$position denies unauthorized replacement");
    check($sanitize(null) === $code, "$position preserves omitted restricted field");
    $GLOBALS['can_code'] = true;
    check($sanitize(array('invalid')) === $code, "$position rejects malformed value");
    check($sanitize('') === '', "$position accepts intentional clearing");
    check(zen_get_option($key) === $code, "$position reads stored code");
}
// Execute all output hooks with no logged-in permission, like a public visitor.
$GLOBALS['can_code'] = false;
foreach (array('head' => 'wp_head', 'body' => 'wp_body_open', 'footer' => 'wp_footer') as $position => $hook) {
    ob_start();
    foreach ($GLOBALS['hooks'][$hook] ?? array() as $callback) $callback();
    $output = ob_get_clean();
    $key = 'zen_code_' . $position;
    check(isset($GLOBALS['options'][$key]) && trim($output) === $GLOBALS['options'][$key], "$position emits saved code once to anonymous visitor");
    $GLOBALS['options'][$key] = '';
    ob_start();
    foreach ($GLOBALS['hooks'][$hook] ?? array() as $callback) $callback();
    check(trim(ob_get_clean()) === '', "$position empty code emits nothing");
}
exit($failed ? 1 : 0);
