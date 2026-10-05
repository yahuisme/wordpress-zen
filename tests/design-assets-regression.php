<?php
/** Execute the real enqueue/setup callbacks with explicit WordPress boundaries. */
define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['hooks'] = $GLOBALS['supports'] = $GLOBALS['styles'] = $GLOBALS['inline'] = array();
$GLOBALS['font'] = 'system';
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function remove_action(...$args) {}
function get_option($key, $default = false) { return $key === 'zen_font_family' ? $GLOBALS['font'] : $default; }
function zen_get_option($key) { return get_option($key, array('zen_font_family'=>'system','zen_content_width'=>900,'zen_reading_width'=>720)[$key] ?? 1); }
function add_theme_support($feature, ...$args) { $GLOBALS['supports'][$feature] = $args; }
function register_nav_menus(...$args) {}
function add_editor_style($styles) { $GLOBALS['editor_styles'] = (array) $styles; }
function wp_get_theme() { return new class { public function get($key) { return 'test'; } }; }
function get_template_directory_uri() { return 'https://example.test/zen'; }
function get_template_directory() { return ABSPATH; }
function is_singular() { return false; }
function wp_enqueue_style($handle, ...$args) { $GLOBALS['styles'][$handle] = $args; }
function wp_enqueue_script(...$args) {}
function wp_script_is(...$args) { return false; }
function wp_localize_script(...$args) {}
function wp_add_inline_style($handle, $css) { $GLOBALS['inline'][$handle][] = $css; }
function __($s, $domain = '') { return $s; }
require ABSPATH . 'inc/setup.php';
require ABSPATH . 'inc/enqueue.php';
$failed = 0;
function verify($yes, $name) { global $failed; echo ($yes ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$yes) $failed++; }
zen_setup();
verify(isset($GLOBALS['supports']['editor-styles']), 'editor opts into theme typography');
verify(isset($GLOBALS['supports']['custom-logo']), 'native site logo supported');
verify(isset($GLOBALS['supports']['align-wide']), 'wide native media supported');
verify(is_file(ABSPATH . 'assets/css/reading.css') && is_file(ABSPATH . 'assets/css/layout.css'), 'maintained readable design layers exist');
verify(substr_count(file_get_contents(ABSPATH . 'assets/css/style.css'), "\n") > 100, 'compatibility stylesheet is readable without missing build source');
zen_scripts();
verify(isset($GLOBALS['styles']['zen-reading-style']), 'shared reading stylesheet enqueued');
$css = implode('', array_merge(...array_values($GLOBALS['inline'])));
verify(str_contains($css, '--zen-reading-width:720px'), 'reading width independent from site width');
verify(str_contains($css, '--zen-content-width:900px'), 'legacy site width preserved');
verify(isset($GLOBALS['hooks']['block_editor_settings_all']), 'editor receives saved typography settings');
if (isset($GLOBALS['hooks']['block_editor_settings_all'])) {
    $settings = ($GLOBALS['hooks']['block_editor_settings_all'][0])(array('styles'=>array()));
    $editor = implode('', array_column($settings['styles'], 'css'));
    verify(str_contains($editor, '--zen-reading-width:720px'), 'editor receives same reading width');
    verify(str_contains($editor, 'system-ui'), 'editor receives same font stack');
}
exit($failed ? 1 : 0);
