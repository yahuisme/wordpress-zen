<?php
/** Real option callbacks with explicit WordPress boundary doubles. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
$GLOBALS['options'] = $GLOBALS['settings'] = $GLOBALS['errors'] = $GLOBALS['assets'] = array();
$GLOBALS['caps'] = array('manage_options' => true, 'unfiltered_html' => true);
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['hooks'][] = $hook;
    $GLOBALS['hook_callbacks'][$hook][$priority][] = $callback;
}
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_filter($hook, $callback, $priority = 10) { $GLOBALS['save_filters'][$hook][$priority][] = $callback; }
function apply_save_filters($hook, $value) {
    $callbacks = $GLOBALS['save_filters'][$hook] ?? array();
    ksort($callbacks);
    foreach ($callbacks as $group) foreach ($group as $callback) $value = $callback($value);
    return $value;
}
function wp_unslash($value) { return stripslashes($value); }
function sanitize_text_field($text) { return is_string($text) ? trim(strip_tags($text)) : ''; }
function current_user_can($cap) { return $GLOBALS['caps'][$cap] ?? false; }
function register_setting($group, $key, $args) { $GLOBALS['settings'][$key] = $args; }
function add_settings_error($key, $code, $message, $type = 'error') { $GLOBALS['errors'][] = compact('key', 'code', 'message', 'type'); }
function __($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected="selected"'; }
function checked($a, $b) { if ((string) $a === (string) $b) echo ' checked="checked"'; }

// Native wp_kses_post also requires a string; its full allowlist is covered by the native backend test.
function wp_kses_post($text) {
    if (!is_string($text)) throw new TypeError('Footer HTML must be a string');
    return $text;
}
function absint($value) { return abs((int) $value); }
function get_settings_errors() { return $GLOBALS['wp_settings_errors'] ?? array(); }
function settings_errors() {}
function settings_fields($group) { echo '<input type="hidden" name="option_page" value="' . $group . '">'; wp_nonce_field($group . '-options'); }
function wp_nonce_field($action, $name = '_wpnonce', $referer = true) { echo '<input type="hidden" id="' . $name . '" name="' . $name . '" value="fixture">'; }
function submit_button($label, $type = 'primary', $name = 'submit', $wrap = true) { echo '<button type="submit" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" class="button button-primary">' . esc_html($label) . '</button>'; }
function get_admin_page_title() { return 'Zen 主题设置'; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function home_url($path = '') { return 'https://example.test/' . ltrim($path, '/'); }
function get_posts($args) { $GLOBALS['post_query'] = $args; return $GLOBALS['public_posts'] ?? array(42); }
function get_permalink($post) { return 'https://example.test/post-' . $post . '/'; }
function get_categories($args) { $GLOBALS['category_queries'][] = $args; return array(); }
function is_wp_error($value) { return false; }
function get_transient($key) { return array_key_exists('update_info', $GLOBALS) ? $GLOBALS['update_info'] : false; }
function wp_create_nonce($action) { return 'fixture-nonce'; }
function get_template() { return 'zen'; }
function wp_get_theme($stylesheet = false) { return new class { function get($key) { return '1.5.2'; } }; }
function get_theme_file_uri($path) { return 'https://example.test/theme' . $path; }
function get_template_directory() { return ABSPATH; }
function get_template_directory_uri() { return 'https://example.test/theme'; }
function wp_enqueue_style(...$args) { $GLOBALS['assets'][] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['assets'][] = $args; }
function wp_localize_script(...$args) { $GLOBALS['localized'][] = $args; }
function wp_enqueue_code_editor($args) {
    $GLOBALS['code_editor_args'][] = $args;
    return $GLOBALS['code_editor_result'] ?? array('codemirror' => array('mode' => 'htmlmixed', 'lint' => false));
}
require ABSPATH . 'inc/options.php';
$failed = 0; $total = 0;
function test($name, $callback) { global $failed, $total; $total++; try { $callback(); echo "PASS $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; } }
function expect($value, $message) { if (!$value) throw new RuntimeException($message); }
function render() { ob_start(); zen_options_page_html(); return ob_get_clean(); }
if (in_array('--html', $argv, true)) {
    if (in_array('--save-error', $argv, true)) {
        $GLOBALS['wp_settings_errors'] = array(array(
            'setting' => 'zen_site_start_date', 'code' => 'zen_site_start_date_invalid', 'type' => 'error',
            'message' => '请输入有效日期（YYYY-MM-DD）；已保留上次保存值。',
        ));
    }
    if (in_array('--code-save-error', $argv, true)) {
        $GLOBALS['wp_settings_errors'] = array(array(
            'setting' => 'zen_code_head', 'code' => 'zen_code_head_not_saved', 'type' => 'error',
            'message' => '未能保存本次提交的值。',
        ));
    }
    if (in_array('--saved-code', $argv, true)) $GLOBALS['options']['zen_code_head'] = "\r\n <!-- fixture C:\\saved --> \r\n";
    echo render();
    exit;
}
test('retired design and attribution controls are neither saved nor reset', function () {
    zen_register_options();
    $legacy = array('zen_font_family' => 'inter', 'zen_date_style' => 'classic', 'zen_show_footer_theme_by' => 1, 'zen_show_footer_wordpress' => 1, 'zen_category_ids' => array(9, 2), 'zen_content_width' => 1080);
    $GLOBALS['options'] = $legacy;
    $html = render();
    foreach ($legacy as $key => $value) {
        expect(!array_key_exists($key, zen_get_option_defaults()), "$key still in reset schema");
        expect(!array_key_exists($key, $GLOBALS['settings']), "$key still accepted by Settings API");
        expect(!str_contains($html, 'name="' . $key . '"') && !str_contains($html, 'name="' . $key . '[]"'), "$key still appears in the form");
        expect(get_option($key) === $value, "$key was rewritten or deleted");
    }
    $GLOBALS['options'] = array();
});
test('new installations use conservative defaults and explicit legacy values survive', function () {
    foreach (array('zen_copyright_license' => 'none', 'zen_show_reading_count' => 0, 'zen_show_footer_rss' => 1, 'zen_reading_width' => 800, 'zen_excerpt_length' => 60, 'zen_list_style' => 'standard', 'zen_show_site_intro' => 1, 'zen_show_latest_card' => 1, 'zen_show_featured_image' => 1) as $key => $default) {
        expect(zen_get_option($key) === $default, "$key default differs");
    }
    foreach (array('zen_copyright_license' => 'cc-by-nc-sa-4.0', 'zen_show_reading_count' => 1, 'zen_show_footer_rss' => 0, 'zen_reading_width' => '768', 'zen_excerpt_length' => '150') as $key => $legacy) {
        $GLOBALS['options'][$key] = $legacy;
        expect(zen_get_option($key) === $legacy, "$key legacy value overwritten");
    }
    $GLOBALS['options'] = array();
});
test('registered new options sanitize values and reject invalid numbers without losing saved settings', function () {
    zen_register_options();
    $schema = array_keys(zen_get_option_defaults());
    $registered = array_keys($GLOBALS['settings']);
    sort($schema);
    sort($registered);
    expect($schema === $registered, 'registered fields and reset schema must match');
    expect(zen_sanitize_reading_width('800') === 800, 'valid reading width rejected');
    expect(zen_sanitize_reading_width('800.0') === 800, 'valid integral decimal width rejected');
    expect(zen_sanitize_reading_width('8e2') === 800, 'valid integral exponent width rejected');
    expect(zen_sanitize_excerpt_length('60.000') === 60, 'valid integral decimal excerpt length rejected');
    $GLOBALS['options']['zen_reading_width'] = 760;
    $GLOBALS['options']['zen_site_start_date'] = '2020-01-01';
    $GLOBALS['options']['zen_excerpt_length'] = 180;
    foreach (array('-600', '599', '961', '720.5', array(720), '', '800.', '0x320', '1e500') as $invalid) expect(zen_sanitize_reading_width($invalid) === 760, 'invalid reading width lost saved value');
    expect(zen_sanitize_excerpt_length('0') === 180, 'invalid excerpt length must preserve prior value');
    expect(zen_sanitize_site_start_date('2024-02-30') === '2020-01-01', 'bad date lost saved value');
    expect(zen_sanitize_site_start_date(array()) === '2020-01-01', 'non-string date lost saved value');
    expect(zen_sanitize_site_start_date('') === '', 'explicit empty date cannot clear value');
    expect(count($GLOBALS['errors']) === 12, 'invalid fields need native settings errors');
    expect(zen_sanitize_list_style('compact') === 'compact' && zen_sanitize_list_style('bogus') === 'standard', 'list enum mismatch');
    expect(zen_sanitize_copyright_license('bogus') === 'none', 'invalid license should not impose a license');
    $GLOBALS['caps']['unfiltered_html'] = false;
    $GLOBALS['options']['zen_code_head'] = '<script>saved()</script>';
    $callback = $GLOBALS['settings']['zen_code_head']['sanitize_callback'];
    expect($callback(null) === '<script>saved()</script>' && $callback('<script>new()</script>') === '<script>saved()</script>', 'omitted or unauthorized code lost saved value');
    $GLOBALS['caps']['unfiltered_html'] = true;
    $GLOBALS['options'] = array();
});
test('malformed footer values preserve saved HTML and report a native settings error', function () {
    zen_register_options();
    $GLOBALS['options']['zen_footer_text'] = '<a href="/saved/">Saved footer</a>';
    $sanitize = $GLOBALS['settings']['zen_footer_text']['sanitize_callback'];
    $before = count($GLOBALS['errors']);
    foreach (array(array('unexpected'), null, 17, new stdClass()) as $invalid) {
        expect($sanitize($invalid) === $GLOBALS['options']['zen_footer_text'], 'malformed footer must not throw or overwrite saved HTML');
    }
    expect(count($GLOBALS['errors']) === $before + 4, 'each malformed footer needs a settings error');
    expect(end($GLOBALS['errors'])['code'] === 'zen_footer_text_invalid', 'footer error must identify the rejected field');
    $GLOBALS['options'] = array();
});

test('native invalid-value fallback does not materialize absent options', function () {
    $invalid = array('zen_reading_width' => '599', 'zen_excerpt_length' => '', 'zen_site_start_date' => '2026-02-30', 'zen_footer_text' => array('unexpected'));
    $GLOBALS['options'] = array();
    foreach ($invalid as $key => $input) {
        $sanitize = $GLOBALS['settings'][$key]['sanitize_callback'];
        // Core compares the sanitized value with get_option() before deciding to insert.
        expect($sanitize($input) === get_option($key), "$key would write a fallback into an absent row");
    }
});

test('settings save preserves restricted and untouched code but allows deliberate clearing', function () {
    $group = array('zen_options' => array('zen_code_head', 'zen_code_body', 'zen_code_footer', 'zen_reading_width', 'zen_show_toc', 'extension_option'), 'reading' => array('posts_per_page'));
    $old_method = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('action' => 'update', 'option_page' => 'zen_options', 'zen_code_head' => addslashes("\r\n <!-- fixture C:\\saved --> \n"), 'zen_code_body' => '', 'zen_code_footer' => ' ', 'zen_reading_width' => '599', 'extension_option' => 'extension');
    $GLOBALS['options'] = array('zen_code_head' => "\n <!-- fixture C:\\saved --> \r\n", 'zen_code_body' => '<!-- remove -->');
    try {
        $GLOBALS['caps']['unfiltered_html'] = false;
        $GLOBALS['action'] = 'update';
        $GLOBALS['option_page'] = 'reading';
        expect(zen_prepare_settings_save($group) === $group, 'other Core settings groups are unaffected');
        $GLOBALS['option_page'] = 'zen_options';
        $GLOBALS['hook_callbacks']['load-options.php'][10][0]();
        $allowed = apply_save_filters('allowed_options', $group);
        expect($allowed === array('zen_options' => array('zen_reading_width', 'extension_option'), 'reading' => $group['reading']), 'restricted, omitted and extension fields have the wrong write boundary');
        $GLOBALS['caps']['unfiltered_html'] = true;
        $GLOBALS['save_filters'] = array();
        $GLOBALS['hook_callbacks']['load-options.php'][10][0]();
        $allowed = apply_save_filters('allowed_options', $group);
        expect($allowed['zen_options'] === array('zen_code_head', 'zen_code_body', 'zen_code_footer', 'zen_reading_width', 'extension_option'), 'explicit edits remain eligible');
        $submitted = wp_unslash(trim($_POST['zen_code_head']));
        expect(apply_save_filters('sanitize_option_zen_code_head', $submitted) === $GLOBALS['options']['zen_code_head'], 'unchanged textarea content must preserve stored newline bytes');
        expect(apply_save_filters('sanitize_option_zen_code_body', '') === '', 'explicit empty code must clear the saved text');
        expect(apply_save_filters('sanitize_option_zen_code_footer', '') === ' ', 'Core trim must not discard deliberate outer whitespace');
        expect(apply_save_filters('sanitize_option_zen_code_footer', ' ') === ' ', 'Core add-option re-sanitization must keep restored bytes');
        apply_save_filters('sanitize_option_zen_reading_width', false);
        $GLOBALS['options']['zen_code_body'] = '';
        $GLOBALS['options']['zen_code_footer'] = ' ';
        expect(apply_save_filters('pre_set_transient_settings_errors', array()) === array(), 'correctly stored code and a validation-preserved absent value are not failed writes');
    } finally {
        $GLOBALS['options'] = $GLOBALS['save_filters'] = $_POST = array();
        unset($GLOBALS['action'], $GLOBALS['option_page']);
        $GLOBALS['caps']['unfiltered_html'] = true;
        if ($old_method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $old_method;
    }
});

test('single settings form groups all old and new fields', function () {
    $html = render();
    preg_match_all('/\bid="([^"]+)"/', $html, $ids);
    expect(str_contains($html, 'id="zen_reading_width" value="800"') && str_contains($html, '默认 800px'), 'rendered width default and help must say 800px');
    expect(str_contains($html, 'id="zen_excerpt_length" value="60"') && str_contains($html, '默认 60 字'), 'rendered excerpt default and help must say 60 characters');
    expect(count($ids[1]) === count(array_unique($ids[1])), 'Settings API and reset forms must not duplicate element IDs');
    expect(substr_count($html, '<form ') === 2 && str_contains($html, 'id="zen-options-form"') && str_contains($html, 'id="zen-reset-form"'), 'settings and reset forms required');
    expect(str_contains($html, 'name="_wpnonce"'), 'settings nonce missing');
    foreach (array('reading', 'list', 'post', 'footer', 'code') as $group) expect(str_contains($html, 'id="zen-' . $group . '"'), "$group group missing");
    foreach (array_keys($GLOBALS['settings']) as $key) expect(str_contains($html, 'name="' . $key . '"'), "$key input omitted");
    expect(substr_count($html, 'name="zen_reading_width"') === 1, 'preset and number must not submit conflicting names');
    expect((bool) preg_match('/<details[^>]*id="zen-code"[^>]*>/', $html, $code) && !str_contains($code[0], ' open'), 'risky code must start collapsed');
    expect(!str_contains($html, 'zen-layout') && !str_contains($html, 'zen_content_width'), 'retired advanced layout settings remain');
    expect(!function_exists('zen_sanitize_content_width'), 'unused content width sanitizer remains');
    expect(!str_contains($html, 'id="zen-update-status"') && !str_contains($html, '关于更新'), 'theme update interface still present');
    expect(str_contains($html, '仅粘贴可信代码') && str_contains($html, '切换主题后不再输出'), 'code risk explanation missing');
    expect(!str_contains($html, '整页缓存命中不计数'), 'cache-safe AJAX counter must not claim page cache hits never count');
    expect(str_contains($html, '恢复默认设置') && str_contains($html, 'zen_reset_options'), 'reset settings action missing');
    expect(!str_contains($html, '<style>'), 'admin style must be enqueued separately');
});
test('copyright choices keep their original order, labels and saved values', function () {
    $expected = array('none' => '不显示', 'all-rights-reserved' => '保留所有权利', 'cc-by-4.0' => 'CC BY 4.0', 'cc-by-sa-4.0' => 'CC BY-SA 4.0', 'cc-by-nc-sa-4.0' => 'CC BY-NC-SA 4.0');
    $GLOBALS['options']['zen_copyright_license'] = 'cc-by-sa-4.0';
    try {
        expect(preg_match('~<select[^>]*id="zen_copyright_license"[^>]*>(.*?)</select>~s', render(), $select), 'copyright select missing');
        preg_match_all('~<option value="([^"]+)"([^>]*)>([^<]*)</option>~', $select[1], $options);
        expect(array_combine($options[1], $options[3]) === $expected, 'copyright choice order, values or labels changed');
        foreach ($options[1] as $i => $value) {
            expect(zen_sanitize_copyright_license($value) === $value, "$value must stay accepted");
            expect(str_contains($options[2][$i], 'selected') === ($value === 'cc-by-sa-4.0'), 'saved choice must remain selected');
        }
        foreach (array(array(), null, 0, new stdClass()) as $invalid) expect(zen_sanitize_copyright_license($invalid) === 'none', 'invalid values must not impose a license');
    } finally { unset($GLOBALS['options']['zen_copyright_license']); }
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
test('theme updater no longer registers an AJAX endpoint', function () {
    expect(!in_array('wp_ajax_zen_update_info', $GLOBALS['hooks'], true), 'retired update hook still exists');
    expect(in_array('wp_ajax_zen_post_view', $GLOBALS['hooks'], true), 'unrelated reading-count AJAX must stay registered');
});
test('admin assets are isolated to the existing top-level hook', function () {
    zen_options_admin_assets('settings_page_other');
    expect(count($GLOBALS['assets']) === 0, 'assets leaked outside Zen');
    zen_options_admin_assets('toplevel_page_zen-options');
    expect(count($GLOBALS['assets']) === 2, 'admin stylesheet and script missing');
    expect(str_contains($GLOBALS['assets'][0][1], '/assets/css/admin.css') && str_contains($GLOBALS['assets'][1][1], '/assets/js/admin.js'), 'wrong admin assets');
    expect(count($GLOBALS['code_editor_args'] ?? array()) === 1, 'authorized settings must enqueue the native code editor once');
    expect($GLOBALS['code_editor_args'][0]['type'] === 'text/html' && $GLOBALS['code_editor_args'][0]['codemirror']['lint'] === false, 'snippets use HTML editing without blocking lint');
    expect($GLOBALS['assets'][1][2] === array('code-editor'), 'native editor must load before the settings script');
    expect($GLOBALS['localized'][0][1] === 'zenAdmin' && $GLOBALS['localized'][0][2]['codeEditor'] === true, 'only native editor availability should reach the page; Core owns its settings');
    $count = count($GLOBALS['code_editor_args']);
    $GLOBALS['caps']['unfiltered_html'] = false;
    zen_options_admin_assets('toplevel_page_zen-options');
    expect(count($GLOBALS['code_editor_args']) === $count && end($GLOBALS['assets'])[2] === array(), 'restricted users must not load code editing assets');
    $GLOBALS['caps']['unfiltered_html'] = true;
    $GLOBALS['code_editor_result'] = false;
    zen_options_admin_assets('toplevel_page_zen-options');
    expect(end($GLOBALS['assets'])[2] === array() && end($GLOBALS['localized'])[2]['codeEditor'] === false, 'disabled syntax highlighting keeps a plain textarea');
    unset($GLOBALS['code_editor_result']);
});

test('impossible calendar dates retain the prior value, including year zero', function () {
    $GLOBALS['options']['zen_site_start_date'] = '2020-01-01';
    expect(zen_sanitize_site_start_date('0000-01-01') === '2020-01-01', 'year zero is not a valid calendar start date');
    expect(zen_sanitize_site_start_date('2024-02-29') === '2024-02-29', 'valid leap date rejected');
    expect(zen_sanitize_site_start_date('<b>2024-02-29</b>') === '2020-01-01', 'markup is not a valid date submission');
    expect(zen_sanitize_site_start_date('<b></b>') === '2020-01-01', 'malformed date must not clear the saved date');
    $GLOBALS['options'] = array();
});
test('retired category settings leave no controls queries sanitizer or CSS', function () {
    $GLOBALS['category_queries'] = array();
    $html = render();
    foreach (array('zen_category_ids', 'zen-category-choices', 'zen-categories-help', '分类归档导航') as $retired) {
        expect(!str_contains($html, $retired), "retired category UI remains: $retired");
    }
    expect($GLOBALS['category_queries'] === array(), 'settings render still fetches retired category choices');
    expect(!function_exists('zen_sanitize_category_ids'), 'unused category sanitizer remains');
    expect(!str_contains(file_get_contents(ABSPATH . 'assets/css/admin.css'), 'zen-category-choices'), 'unused category CSS remains');
});

test('option descriptions use short copy without terminal punctuation', function () {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . render());
    $xpath = new DOMXPath($doc);
    foreach (array(
        'zen_show_site_intro' => '首页显示 WordPress 站点副标题',
        'zen_show_latest_card' => '从文章索引首页选出最新的公开文章',
        'zen_show_featured_image' => '在文章页展示原生特色图',
        'zen_show_lightbox' => '点击图片开启灯箱效果',
        'zen_show_reading_count' => '显示文章浏览次数',
    ) as $key => $description) {
        expect(trim($xpath->evaluate('string(//label[@for="' . $key . '"])')) === $description, "$key copy differs");
    }
    $descriptions = $xpath->query('//*[@id="zen-options-form"]//*[contains(concat(" ", normalize-space(@class), " "), " description ")]|//*[@id="zen-options-form"]//label[input[@type="checkbox"]]');
    expect($descriptions->length >= 25, 'descriptions missing');
    foreach ($descriptions as $element) {
        $text = trim($element->textContent);
        expect($text !== '' && !str_contains($text, '。') && !preg_match('/[.!?！？；;，,：:]$/u', $text), "inconsistent option copy: $text");
    }
    expect($xpath->evaluate('string(//*[@id="zen-list-style-help"])') === '紧凑模式隐藏列表摘要', 'list style help must use the requested copy');
    expect(!str_contains($xpath->evaluate('string(//*[@id="zen-excerpt-length-help"])'), '标准模式'), 'excerpt length also applies to the card in compact mode');
    $help = $xpath->query('//*[@id="zen_code_head"]/following-sibling::p')->item(0);
    expect($help !== null && trim($help->textContent) === '在 </head> 前输出', 'head code needs the generic placement hint');
    expect($help->childNodes->length === 1 && $help->firstChild->nodeType === XML_TEXT_NODE, 'head placement hint must be escaped text');
});

test('rendered controls associate option names and help with their inputs', function () {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . render());
    $xpath = new DOMXPath($doc);
    $refs = static fn($node, $attribute) => preg_split('/\s+/', $node->getAttribute($attribute), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($xpath->query('//*[@id="zen-options-form"]//input[@type="checkbox"]') as $node) {
        $title = $refs($node, 'aria-labelledby');
        $help = $refs($node, 'aria-describedby');
        expect(count($title) === 1 && trim($xpath->evaluate('string(//th[@id="' . $title[0] . '"])')) !== '', 'checkbox must announce its visible setting name');
        expect(count($help) === 1 && trim($xpath->evaluate('string(//label/span[@id="' . $help[0] . '"])')) !== '', 'checkbox must announce its visible help');
    }
    foreach (array('zen_theme_mode_default', 'zen_copyright_license', 'zen_footer_text', 'zen_site_start_date', 'zen_code_head', 'zen_code_body', 'zen_code_footer') as $key) {
        $node = $xpath->query('//*[@id="' . $key . '"]')->item(0);
        $help = $refs($node, 'aria-describedby');
        expect(count($help) === 1 && trim($xpath->evaluate('string(//p[@id="' . $help[0] . '"])')) !== '', "$key lacks associated help");
    }
    expect($xpath->query('//*[@hidden]/select[@id="zen_reading_width_preset"]')->length === 1, 'JS-only preset must not appear functional without JS');
});

test('custom code uses standard hooks once and preserves marker and whitespace bytes', function () {
    $render_hook = static function ($hook, $priority) {
        ob_start();
        foreach ($GLOBALS['hook_callbacks'][$hook][$priority] ?? array() as $callback) $callback();
        return ob_get_clean();
    };
    $marked = "<!-- zen:early -->\r\n<script>window.fixture = true;</script>";
    try {
        foreach (zen_code_fields() as $key => list($hook)) {
            expect(array_keys($GLOBALS['hook_callbacks'][$hook] ?? array()) === array(20), "$key must only use its standard priority 20 hook");
            $sanitize = $GLOBALS['settings'][$key]['sanitize_callback'];
            foreach (array('<script>window.fixture = true;</script>', $marked, "\n" . $marked, "\r\n" . $marked, " \t\r\n" . $marked) as $code) {
                $GLOBALS['options'][$key] = $code;
                expect($sanitize($code) === $code, 'authorized save must preserve literal code bytes');
                expect($render_hook($hook, 7) === '', 'code must not use an early output channel');
                expect($render_hook($hook, 20) === "\n$code\n", 'standard output must preserve the exact stored bytes once');
            }
            foreach (array('', " \t\r\n") as $empty) {
                $GLOBALS['options'][$key] = $empty;
                expect($render_hook($hook, 20) === '', 'empty code must not be output');
            }
        }
    } finally {
        foreach (array_keys(zen_code_fields()) as $key) unset($GLOBALS['options'][$key]);
    }
});

test('settings header has no link clusters or explanatory text', function () {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . render());
    $xpath = new DOMXPath($doc);
    foreach (array('zen-native-tools', 'zen-native-links', 'zen-options-nav', 'zen-saved-links', 'zen-options-intro') as $class) {
        expect($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]')->length === 0, 'unnecessary top entry remains: ' . $class);
    }
    expect($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " zen-save-bar ")]//a')->length === 0, 'save bar still has links');
});

printf("%d tests, %d failures\n", $total, $failed);
exit($failed ? 1 : 0);
