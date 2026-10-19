<?php
/** CLI boundary regression: php tests/reset-regression.php (no WordPress/database). */
define('ABSPATH', __DIR__ . '/');
class Zen_Reset_Redirect extends RuntimeException {}
class Zen_Reset_Denied extends RuntimeException {}
class Zen_Reset_Rendered extends RuntimeException {}
function add_action(...$args) {}
function current_user_can($capability) { return !empty($GLOBALS['caps'][$capability]); }
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_html_e($text, $domain = null) { echo esc_html__($text, $domain); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function wp_die($message, $title = '', $args = array()) { throw new Zen_Reset_Denied((string) $args['response']); }
function check_admin_referer($action) {
    if ($action !== 'zen_reset_options' || !$GLOBALS['nonce_valid']) throw new Zen_Reset_Denied('nonce');
}
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default; }
function delete_option($key) {
    $GLOBALS['deleted'][] = $key;
    if (in_array($key, $GLOBALS['refused'], true) || !array_key_exists($key, $GLOBALS['options'])) return false;
    unset($GLOBALS['options'][$key]);
    return true;
}
function delete_transient($key) { $GLOBALS['transients'][] = $key; return true; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($query, $url) { return $url . '?' . http_build_query($query); }
// Throw at the redirect boundary so the real handler's exit cannot stop the suite.
function wp_safe_redirect($url) { throw new Zen_Reset_Redirect($url); }
function get_admin_page_title() { return 'Zen 主题设置'; }
function settings_errors() {}
function settings_fields($group) {}
// The notice precedes the first submit button; stop rendering unrelated fields.
function submit_button(...$args) { throw new Zen_Reset_Rendered(); }
require dirname(__DIR__) . '/inc/options.php';
$total = 0;
$failures = 0;
function zen_reset_check($condition, $label) {
    global $total, $failures;
    $total++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failures++;
}
function zen_reset_fixture($unfiltered = true) {
    $GLOBALS['caps'] = array('manage_options' => true, 'unfiltered_html' => $unfiltered);
    $GLOBALS['nonce_valid'] = true;
    $GLOBALS['options'] = array_merge(zen_get_option_defaults(), array('unrelated_option' => 'untouched'));
    foreach (array('head', 'body', 'footer') as $position) $GLOBALS['options']['zen_code_' . $position] = '<script>' . $position . '()</script>';
    $GLOBALS['deleted'] = $GLOBALS['transients'] = $GLOBALS['refused'] = array();
}
function zen_reset_call() {
    try { zen_reset_options(); }
    catch (Zen_Reset_Redirect $redirect) {
        parse_str(parse_url($redirect->getMessage(), PHP_URL_QUERY), $query);
        zen_reset_check($query['page'] === 'zen-options', 'reset redirects to settings');
        return $query['zen_reset'];
    }
    throw new RuntimeException('Reset did not redirect');
}
function zen_reset_notice($status) {
    $_GET['zen_reset'] = $status;
    ob_start();
    try { zen_options_page_html(); }
    catch (Zen_Reset_Rendered $done) {}
    return ob_get_clean();
}
zen_reset_fixture(false);
$status = zen_reset_call();
foreach (array('head', 'body', 'footer') as $position) {
    $key = 'zen_code_' . $position;
    zen_reset_check(get_option($key) === '<script>' . $position . '()</script>' && !in_array($key, $GLOBALS['deleted'], true), 'restricted reset preserves ' . $key);
}
zen_reset_check(!array_key_exists('zen_reading_width', $GLOBALS['options']), 'restricted reset still resets ordinary setting');
zen_reset_check(get_option('unrelated_option') === 'untouched', 'reset leaves unrelated options alone');
zen_reset_check($status === 'partial', 'restricted reset reports partial status');
$html = zen_reset_notice($status);
zen_reset_check(str_contains($html, '自定义代码已保留') && str_contains($html, 'notice-warning') && !str_contains($html, 'Zen 设置已恢复默认值。'), 'restricted notice explains preserved code without full-success claim');
zen_reset_check(str_contains($html, 'id="zen-options-form"'), 'reset notice keeps settings form');
zen_reset_fixture();
$GLOBALS['caps']['manage_options'] = false;
$before = $GLOBALS['options'];
try { zen_reset_options(); zen_reset_check(false, 'permission denied'); }
catch (Zen_Reset_Denied $error) { zen_reset_check($error->getMessage() === '403', 'permission denied with 403'); }
zen_reset_check($GLOBALS['options'] === $before && !$GLOBALS['deleted'] && !$GLOBALS['transients'], 'permission denial performs no writes');
zen_reset_fixture();
$GLOBALS['nonce_valid'] = false;
$before = $GLOBALS['options'];
try { zen_reset_options(); zen_reset_check(false, 'nonce denied'); }
catch (Zen_Reset_Denied $error) { zen_reset_check($error->getMessage() === 'nonce', 'invalid nonce denied'); }
zen_reset_check($GLOBALS['options'] === $before && !$GLOBALS['deleted'] && !$GLOBALS['transients'], 'nonce denial performs no writes');
zen_reset_fixture();
$GLOBALS['options'] = array('unrelated_option' => 'untouched');
$status = zen_reset_call();
zen_reset_check($status === '1' && !$GLOBALS['deleted'], 'already-default absent options are successful without delete attempts');
zen_reset_check(get_option('unrelated_option') === 'untouched', 'already-default reset preserves unrelated option');
zen_reset_fixture();
unset($GLOBALS['options']['zen_show_tags']);
$GLOBALS['options']['zen_show_reading_count'] = 0;
$GLOBALS['options']['zen_footer_text'] = '';
$status = zen_reset_call();
zen_reset_check($status === '1', 'absent option and explicit zero/empty do not cause failure');
foreach (array_keys(zen_get_option_defaults()) as $key) {
    zen_reset_check(!array_key_exists($key, $GLOBALS['options']), 'authorized reset removes ' . $key);
}
zen_reset_check(get_option('unrelated_option') === 'untouched', 'authorized reset leaves unrelated options alone');
$html = zen_reset_notice($status);
zen_reset_check(str_contains($html, 'notice-success') && str_contains($html, 'Zen 设置已恢复默认值。'), 'complete reset shows success notice');
foreach (array(true, false) as $unfiltered) {
    zen_reset_fixture($unfiltered);
    $GLOBALS['refused'] = array('zen_reading_width');
    $status = zen_reset_call();
    zen_reset_check(get_option('zen_reading_width') === 720, 'refused delete leaves original value');
    zen_reset_check(!array_key_exists('zen_show_tags', $GLOBALS['options']), 'other settings can reset despite a refused delete');
    zen_reset_check($status === 'error', 'refused delete never reports successful or partial reset');
    $html = zen_reset_notice($status);
    zen_reset_check(str_contains($html, 'notice-error') && str_contains($html, '部分设置未能恢复默认值') && !str_contains($html, 'Zen 设置已恢复默认值。'), 'delete failure has a clear error notice without success claim');
    if (!$unfiltered) {
        foreach (array('head', 'body', 'footer') as $position) {
            zen_reset_check(get_option('zen_code_' . $position) === '<script>' . $position . '()</script>', 'failed restricted reset preserves code: ' . $position);
        }
        zen_reset_check(str_contains($html, '自定义代码已保留'), 'failed restricted reset also explains preserved code');
    }
}
printf("RESET_COMPLETE %d checks, %d failures\n", $total, $failures);
exit($failures ? 1 : 0);
