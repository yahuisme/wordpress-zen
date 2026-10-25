<?php
/** Focused regression coverage for options.php theme and category boundaries. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['options'] = array('zen_category_ids' => array('9'));
$GLOBALS['category_reads'] = 0;
$GLOBALS['theme_lookups'] = array();
$GLOBALS['http_args'] = null;
$GLOBALS['json_response'] = null;
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
function get_transient($key) { return false; }
function set_transient(...$args) {}
function wp_create_nonce($action) { return 'nonce'; }
function get_template() { return 'parent-theme'; }
function wp_get_theme($stylesheet = false) {
    $GLOBALS['theme_lookups'][] = $stylesheet;
    $version = $stylesheet === 'parent-theme' ? '2.7.4' : '0.1-child';
    return new class($version) {
        private $version;
        function __construct($version) { $this->version = $version; }
        function get($key) { return $this->version; }
    };
}
function wp_remote_get($url, $args) { $GLOBALS['http_args'] = $args; return array('response' => array('code' => 200), 'body' => '{"tag_name":"v2.7.4","html_url":"https://example.test/release"}'); }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_send_json_success($response) { $GLOBALS['json_response'] = $response; }
function check_ajax_referer(...$args) {}
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
expect(str_contains($html, '当前版本 v2.7.4。'), 'settings display uses parent theme version');
expect($GLOBALS['category_reads'] === 1, 'selected category option is read once per settings render');
expect(in_array('parent-theme', $GLOBALS['theme_lookups'], true), 'theme lookup explicitly targets parent theme');
zen_fetch_update_info();
expect(($GLOBALS['http_args']['user-agent'] ?? '') === 'Zen/2.7.4', 'update request User-Agent uses parent theme version');
$GLOBALS['theme_lookups'] = array();
zen_ajax_update_info();
expect(isset($GLOBALS['json_response']['message']) && str_contains($GLOBALS['json_response']['message'], '当前版本 v2.7.4'), 'AJAX display uses parent theme version');
expect($failed === 0, "$failed focused assertions failed");
exit($failed ? 1 : 0);
