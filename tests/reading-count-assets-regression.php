<?php
/** Counter assets must run on cached article loads, not wait for interaction. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
$hooks = $scripts = $localized = array();
$singular = true; $preview = false; $enabled = 1;
$post = (object) array('post_status' => 'publish', 'post_password' => '');
function add_action(...$args) {}
function add_filter($hook, $callback) { $GLOBALS['hooks'][$hook] = $callback; }
function zen_get_option($key) { return $key === 'zen_show_reading_count' ? $GLOBALS['enabled'] : ($key === 'zen_font_family' ? 'system' : 0); }
function get_template_directory() { return ABSPATH; }
function get_template_directory_uri() { return 'https://zen.test/themes/zen'; }
function is_singular($type = '') { return $GLOBALS['singular']; }
function is_preview() { return $GLOBALS['preview']; }
function get_queried_object() { return $GLOBALS['post']; }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script($handle, ...$args) { $GLOBALS['scripts'][$handle] = $args; }
function wp_script_is() { return false; }
function wp_localize_script($handle, $name, $data) { $GLOBALS['localized'][$handle] = array($name, $data); }
function wp_add_inline_style(...$args) {}
function admin_url($path) { return 'https://zen.test/wp-admin/' . $path; }
function comments_open() { return false; }
require ABSPATH . 'inc/enqueue.php';
function check($value, $label) { if (!$value) throw new RuntimeException($label); echo "PASS $label\n"; }
check(isset($hooks['rocket_delay_js_exclusions']), 'counter compatibility filter registered');
$callback = $hooks['rocket_delay_js_exclusions'];
$existing = array('existing-plugin.js');
$excluded = $callback($existing);
check(in_array('existing-plugin.js', $excluded, true), 'existing exclusions preserved');
check(in_array('reading-count.js', $excluded, true) && in_array('zenReadingCount', $excluded, true), 'counter script and inline config bypass interaction delay');
check(!in_array('main.js', $excluded, true), 'unrelated scripts retain delay policy');
foreach (array(
    'public post' => array(true, false, 1, 'publish', '', true),
    'not a post' => array(false, false, 1, 'publish', '', false),
    'preview' => array(true, true, 1, 'publish', '', false),
    'disabled' => array(true, false, 0, 'publish', '', false),
    'protected post' => array(true, false, 1, 'publish', 'fixture', false),
    'private post' => array(true, false, 1, 'private', '', false),
) as $name => [$singular, $preview, $enabled, $status, $password, $expected]) {
    $post = (object) array('post_status' => $status, 'post_password' => $password);
    $scripts = $localized = array();
    zen_scripts();
    check(isset($scripts['zen-reading-count']) === $expected && isset($localized['zen-reading-count']) === $expected, "$name: only eligible articles enqueue the counter and its config");
    if ($expected) {
        check($localized['zen-reading-count'] === array('zenReadingCount', array('ajax_url' => admin_url('admin-ajax.php'), 'enabled' => 1)), 'cache-safe public AJAX config');
        check($scripts['zen-reading-count'][3]['strategy'] === 'defer', 'counter remains non-blocking');
    }
}
