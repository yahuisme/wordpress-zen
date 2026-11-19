<?php
/** Focused regression coverage for options.php category and outbound HTTP boundaries. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
$GLOBALS['options'] = array('zen_category_ids' => array('9'));
$GLOBALS['category_reads'] = 0;
$GLOBALS['http_count'] = 0;
function add_action(...$args) {}
function get_option($key, $default = false) {
    if ('zen_category_ids' === $key) $GLOBALS['category_reads']++;
    return $GLOBALS['options'][$key] ?? $default;
}
function current_user_can($cap) { return true; }
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_url_raw($text) { return $text; }
function esc_textarea($text) { return esc_html($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function selected($a, $b) {}
function checked($a, $b) { if ($a === $b) echo ' checked="checked"'; }
function sanitize_text_field($text) { return strip_tags($text); }
function absint($value) { return abs((int) $value); }
function settings_errors() {}
function settings_fields($group) {}
function wp_nonce_field($action) {}
function submit_button($label, ...$args) {}
function get_admin_page_title() { return 'Zen'; }
function admin_url($path) { return 'https://example.test/' . $path; }
function home_url($path = '') { return 'https://example.test/' . ltrim($path, '/'); }
function get_posts($args) { return array(); }
function get_permalink($post) { return ''; }
function get_categories($args) { return array((object) array('term_id' => 2, 'name' => 'Two'), (object) array('term_id' => 9, 'name' => 'Nine')); }
function is_wp_error($value) { return false; }
function get_transient($key) { return array('latest_version' => '9.9.9', 'url' => 'https://example.test/release', 'error' => ''); }
function wp_remote_get($url, $args) { $GLOBALS['http_count']++; return null; }
function get_theme_file_uri($path) { return $path; }
function get_template_directory() { return ABSPATH; }
function get_template_directory_uri() { return ''; }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_localize_script(...$args) {}
require ABSPATH . 'inc/options.php';
$failed = 0;
function expect($condition, $message) {
    global $failed;
    if (!$condition) { $failed++; fwrite(STDERR, "FAIL $message\n"); }
    else echo "PASS $message\n";
}
ob_start();
zen_options_page_html();
$html = ob_get_clean();
expect($GLOBALS['category_reads'] === 1, 'selected category option is read once per settings render');
expect(!str_contains($html, '9.9.9') && !str_contains($html, 'https://example.test/release'), 'stale update transient cannot affect settings rendering');
expect($GLOBALS['http_count'] === 0, 'rendering settings does not check GitHub');
exit($failed ? 1 : 0);
