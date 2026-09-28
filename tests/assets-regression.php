<?php
/** Run: php tests/assets-regression.php. WordPress boundary doubles, no I/O. */
define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['font'] = 'system';
$GLOBALS['styles'] = array();
$GLOBALS['inline'] = array();
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_action(...$args) {}
function get_option($key, $default = false) { return $key === 'zen_font_family' ? $GLOBALS['font'] : $default; }
function wp_get_theme() { return new class { public function get($key) { return 'test'; } }; }
function get_template_directory_uri() { return 'https://example.test/zen'; }
function get_template_directory() { return ABSPATH; }
function is_singular() { return false; }
function wp_enqueue_style($handle, ...$args) { $GLOBALS['styles'][$handle] = $args; }
function wp_enqueue_script(...$args) {}
function wp_script_is(...$args) { return false; }
function wp_localize_script(...$args) {}
function wp_add_inline_style($handle, $css) { $GLOBALS['inline'][] = $css; }
require ABSPATH . 'inc/options.php';
require ABSPATH . 'inc/setup.php';
require ABSPATH . 'inc/enqueue.php';
$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failures++;
}
check(zen_sanitize_font_family('system') === 'system', 'system font can be saved');
zen_scripts();
check(!isset($GLOBALS['styles']['zen-google-fonts']), 'system font omits Google stylesheet');
check(zen_resource_hints(array(), 'preconnect') === array(), 'system font omits Google preconnect');
check(!preg_match('/Inter|Space Grotesk|Noto Sans SC|Noto Serif SC/', implode('', $GLOBALS['inline'])), 'system font CSS uses local stacks');
foreach (array('inter', 'space-grotesk') as $font) {
    $GLOBALS['font'] = $font;
    $GLOBALS['styles'] = array();
    zen_scripts();
    check(isset($GLOBALS['styles']['zen-google-fonts']), "$font retains font stylesheet");
    check(count(zen_resource_hints(array(), 'preconnect')) === 2, "$font retains preconnect");
}
exit($failures ? 1 : 0);
