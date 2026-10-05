<?php
/** Real option callbacks with explicit WordPress boundary doubles. */
define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['options'] = $GLOBALS['settings'] = $GLOBALS['errors'] = $GLOBALS['assets'] = array();
$GLOBALS['caps'] = array('manage_options' => true, 'unfiltered_html' => true);
function add_action(...$args) {}
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function current_user_can($cap) { return $GLOBALS['caps'][$cap] ?? false; }
function register_setting($group, $key, $args) { $GLOBALS['settings'][$key] = $args; }
function add_settings_error($key, $code, $message, $type = 'error') { $GLOBALS['errors'][] = compact('key', 'code', 'message', 'type'); }
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected="selected"'; }
function checked($a, $b) { if ((string) $a === (string) $b) echo ' checked="checked"'; }
function sanitize_text_field($text) { return strip_tags($text); }
function absint($value) { return abs((int) $value); }
function settings_errors() {}
function settings_fields($group) { echo '<input type="hidden" name="option_page" value="' . $group . '"><input type="hidden" name="_wpnonce" value="fixture">'; }
function submit_button($label, $type = 'primary', $name = 'submit', $wrap = true) { echo '<button type="submit" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" class="button button-primary">' . esc_html($label) . '</button>'; }
function get_admin_page_title() { return 'Zen 主题设置'; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function home_url($path = '') { return 'https://example.test/' . ltrim($path, '/'); }
function get_posts($args) { $GLOBALS['post_query'] = $args; return $GLOBALS['public_posts'] ?? array(42); }
function get_permalink($post) { return 'https://example.test/post-' . $post . '/'; }
function get_categories($args) { return array((object) array('term_id' => 2, 'name' => '设计'), (object) array('term_id' => 9, 'name' => '<研究>')); }
function is_wp_error($value) { return false; }
function get_transient($key) { return $GLOBALS['update_info'] ?? array('latest_version' => '1.5.2', 'url' => 'https://example.test/releases', 'error' => ''); }
function wp_get_theme() { return new class { function get($key) { return '1.5.2'; } }; }
function get_theme_file_uri($path) { return 'https://example.test/theme' . $path; }
function wp_enqueue_style(...$args) { $GLOBALS['assets'][] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['assets'][] = $args; }
require ABSPATH . 'inc/options.php';
$failed = 0; $total = 0;
function test($name, $callback) { global $failed, $total; $total++; try { $callback(); echo "PASS $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; } }
function expect($value, $message) { if (!$value) throw new RuntimeException($message); }
function render() { ob_start(); zen_options_page_html(); return ob_get_clean(); }
if (in_array('--html', $argv, true)) { echo render(); exit; }
test('new installations use conservative defaults and explicit legacy values survive', function () {
    foreach (array('zen_font_family' => 'system', 'zen_copyright_license' => 'none', 'zen_show_reading_count' => 0, 'zen_show_footer_theme_by' => 0, 'zen_show_footer_wordpress' => 0, 'zen_show_footer_rss' => 1, 'zen_reading_width' => 720, 'zen_list_style' => 'standard', 'zen_show_site_intro' => 1, 'zen_category_ids' => array(), 'zen_show_featured_image' => 1, 'zen_content_width' => 900) as $key => $default) {
        expect(zen_get_option($key) === $default, "$key default differs");
    }
    foreach (array('zen_font_family' => 'inter', 'zen_copyright_license' => 'cc-by-nc-sa-4.0', 'zen_show_reading_count' => 1, 'zen_show_footer_theme_by' => 1, 'zen_show_footer_wordpress' => 1) as $key => $legacy) {
        $GLOBALS['options'][$key] = $legacy;
        expect(zen_get_option($key) === $legacy, "$key legacy value overwritten");
    }
    $GLOBALS['options'] = array();
});
test('registered new options sanitize values and reject invalid numbers without losing saved settings', function () {
    zen_register_options();
    foreach (array('zen_reading_width', 'zen_list_style', 'zen_category_ids', 'zen_show_site_intro', 'zen_show_featured_image') as $key) {
        expect(isset($GLOBALS['settings'][$key]), "$key not registered");
    }
    expect(zen_sanitize_reading_width('800') === 800, 'valid reading width rejected');
    $GLOBALS['options']['zen_reading_width'] = 760;
    $GLOBALS['options']['zen_content_width'] = 1080;
    $GLOBALS['options']['zen_site_start_date'] = '2020-01-01';
    $GLOBALS['options']['zen_excerpt_length'] = 180;
    foreach (array('-600', '599', '961', '720.5', array(720), '') as $invalid) expect(zen_sanitize_reading_width($invalid) === 760, 'invalid reading width lost saved value');
    expect(zen_sanitize_content_width('3000') === 1080, 'layout width must preserve prior value');
    expect(zen_sanitize_excerpt_length('0') === 180, 'invalid excerpt length must preserve prior value');
    expect(zen_sanitize_site_start_date('2024-02-30') === '2020-01-01', 'bad date lost saved value');
    expect(zen_sanitize_site_start_date(array()) === '2020-01-01', 'non-string date lost saved value');
    expect(zen_sanitize_site_start_date('') === '', 'explicit empty date cannot clear value');
    expect(count($GLOBALS['errors']) === 10, 'invalid fields need native settings errors');
    expect(zen_sanitize_category_ids(array('2', '9', '2', '-2', '0', '2.5', array(5))) === array(2, 9), 'category IDs must be positive unique integers');
    expect(zen_sanitize_category_ids('') === array(), 'empty categories must mean all');
    expect(zen_sanitize_list_style('compact') === 'compact' && zen_sanitize_list_style('bogus') === 'standard', 'list enum mismatch');
    expect(zen_sanitize_font_family('bogus') === 'system', 'font fallback mismatch');
    expect(zen_sanitize_copyright_license('bogus') === 'none', 'invalid license should not impose a license');
    $GLOBALS['caps']['unfiltered_html'] = false;
    $GLOBALS['options']['zen_code_head'] = '<script>saved()</script>';
    $callback = $GLOBALS['settings']['zen_code_head']['sanitize_callback'];
    expect($callback(null) === '<script>saved()</script>' && $callback('<script>new()</script>') === '<script>saved()</script>', 'omitted or unauthorized code lost saved value');
    $GLOBALS['caps']['unfiltered_html'] = true;
    $GLOBALS['options'] = array();
});
test('single settings form groups all old and new fields with saved-only view links', function () {
    $html = render();
    preg_match_all('/\bid="([^"]+)"/', $html, $ids);
    expect(count($ids[1]) === count(array_unique($ids[1])), 'native submit buttons must not duplicate element IDs');
    expect(substr_count($html, '<form ') === 1 && str_contains($html, 'id="zen-options-form"'), 'one real settings form required');
    expect(str_contains($html, 'name="_wpnonce"'), 'settings nonce missing');
    foreach (array('reading', 'list', 'post', 'footer', 'code', 'about') as $group) expect(str_contains($html, 'id="zen-' . $group . '"'), "$group group missing");
    foreach (array_keys($GLOBALS['settings']) as $key) expect(str_contains($html, 'name="' . $key . ($key === 'zen_category_ids' ? '[]' : '') . '"'), "$key input omitted");
    expect(substr_count($html, 'name="zen_reading_width"') === 1, 'preset and number must not submit conflicting names');
    expect((bool) preg_match('/<details[^>]*id="zen-code"[^>]*>/', $html, $code) && !str_contains($code[0], ' open'), 'risky code must start collapsed');
    expect((bool) preg_match('/<details[^>]*id="zen-layout"[^>]*>/', $html, $width) && !str_contains($width[0], ' open'), 'advanced width must start collapsed');
    expect(strpos($html, '</form>') < strpos($html, 'id="zen-about"'), 'updates must be outside form');
    foreach (array('options-general.php', 'options-general.php#choose-from-library-button', 'nav-menus.php', 'options-reading.php', 'options-discussion.php') as $path) expect(str_contains($html, $path), 'native editor link missing: ' . $path);
    expect(str_contains($html, '查看已保存效果') && str_contains($html, 'https://example.test/post-42/'), 'saved home/article links missing');
    expect($GLOBALS['post_query']['post_status'] === 'publish' && $GLOBALS['post_query']['has_password'] === false, 'saved article link must be public');
    expect(str_contains($html, '&lt;研究&gt;'), 'category names must be escaped');
    expect(str_contains($html, '缓存命中') && str_contains($html, '关闭后停止计数') && str_contains($html, '更换主题'), 'risk and count explanations missing');
    expect(!str_contains($html, '<style>'), 'admin style must be enqueued separately');
});
test('limited permissions hide code editors without leaking or removing saved code', function () {
    $GLOBALS['caps']['unfiltered_html'] = false;
    $GLOBALS['options']['zen_code_head'] = 'private-code';
    $html = render();
    expect(!str_contains($html, 'name="zen_code_head"') && !str_contains($html, 'private-code'), 'restricted editor leaked');
    $GLOBALS['caps']['manage_options'] = false;
    expect(render() === '', 'settings page visible without manage_options');
    $GLOBALS['caps'] = array('manage_options' => true, 'unfiltered_html' => true);
    $GLOBALS['options'] = array();
});
test('no post yields no invented article link and failed updates never claim success', function () {
    $GLOBALS['public_posts'] = array();
    $GLOBALS['update_info'] = array('latest_version' => '', 'url' => 'https://example.test/releases', 'error' => '连接失败');
    $html = render();
    expect(!str_contains($html, 'https://example.test/post-'), 'nonexistent article link invented');
    expect(str_contains($html, '连接失败') && !str_contains($html, '已是最新'), 'update failure disguised as success');
    $GLOBALS['update_info']['error'] = '';
    expect(!str_contains(render(), '已是最新'), 'unknown version disguised as success');
    unset($GLOBALS['public_posts'], $GLOBALS['update_info']);
});
test('admin assets are isolated to the existing top-level hook', function () {
    zen_options_admin_assets('settings_page_other');
    expect(count($GLOBALS['assets']) === 0, 'assets leaked outside Zen');
    zen_options_admin_assets('toplevel_page_zen-options');
    expect(count($GLOBALS['assets']) === 2, 'admin stylesheet and script missing');
    expect(str_contains($GLOBALS['assets'][0][1], '/assets/css/admin.css') && str_contains($GLOBALS['assets'][1][1], '/assets/js/admin.js'), 'wrong admin assets');
});

test('impossible calendar dates retain the prior value, including year zero', function () {
    $GLOBALS['options']['zen_site_start_date'] = '2020-01-01';
    expect(zen_sanitize_site_start_date('0000-01-01') === '2020-01-01', 'year zero is not a valid calendar start date');
    expect(zen_sanitize_site_start_date('2024-02-29') === '2024-02-29', 'valid leap date rejected');
    $GLOBALS['options'] = array();
});
test('category choices are touch-friendly successful checkbox controls', function () {
    $html = render();
    expect(!str_contains($html, 'id="zen_category_ids" multiple'), 'keyboard-only multi-select remains');
    expect(str_contains($html, 'type="checkbox" name="zen_category_ids[]"'), 'touch category choices missing');
});

printf("%d tests, %d failures\n", $total, $failed);
exit($failed ? 1 : 0);
