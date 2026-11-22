<?php
/** ZEN_TEST_ISOLATED=1 php tests/native-admin-save-regression.php /fixture/wp-load.php
 * Runs the native options.php controller with benign settings in disposable WordPress.
 */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$complete = false;
register_shutdown_function(static function () use (&$complete) {
    if (!$complete) { fwrite(STDERR, "NATIVE_ADMIN_SAVE_INCOMPLETE\n"); exit(1); }
});

define('WP_ADMIN', true);
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = $_SERVER['PHP_SELF'] = '/wp-admin/options.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') {
    throw new RuntimeException('Isolated local Zen required');
}
if (hash_file('sha256', get_template_directory() . '/inc/options.php') !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/inc/options.php')) {
    throw new RuntimeException('Installed options source mismatch');
}

class Zen_Test_Admin_Redirect extends RuntimeException {}
$admins = get_users(array('role' => 'administrator', 'number' => 1));
if (!$admins) throw new RuntimeException('Fixture administrator required');
$user = $admins[0]->ID;
wp_set_current_user($user);
$session = WP_Session_Tokens::get_instance($user);
$expiry = time() + 300;
$token = $session->create($expiry);
$_COOKIE[AUTH_COOKIE] = wp_generate_auth_cookie($user, $expiry, 'auth', $token);
$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($user, $expiry, 'logged_in', $token);
require_once ABSPATH . 'wp-admin/includes/admin.php';

$old_values = array();
foreach (array_keys(zen_get_option_defaults()) as $key) $old_values[$key] = get_option($key, null);
foreach (array('zen_category_ids', 'zen_content_width') as $key) $old_values[$key] = get_option($key, null);
$extension_key = 'zen_test_extension_option';
$old_values[$extension_key] = get_option($extension_key, null);
$old_errors = get_transient('settings_errors');
$failures = 0;
$checks = 0;
$check = static function ($ok, $label) use (&$failures, &$checks) {
    $checks++;
    $failures += !$ok;
    fwrite(STDOUT, ($ok ? 'PASS ' : 'FAIL ') . $label . "\n");
};
$redirect = static function ($location) { throw new Zen_Test_Admin_Redirect($location); };
add_filter('wp_redirect', $redirect, PHP_INT_MAX);

try {
    update_option('zen_reading_width', 720);
    update_option('zen_show_toc', 1);
    update_option('zen_show_back_to_top', 1);
    $case = $argv[2] ?? 'refused';
    if (!in_array($case, array('refused', 'invalid', 'unchanged', 'create', 'full', 'malformed-footer', 'invalid-date', 'decimal'), true)) throw new InvalidArgumentException('Unknown save case');
    if ($case === 'create') delete_option('zen_reading_width');
    $expected_width = in_array($case, array('create', 'full', 'malformed-footer', 'invalid-date', 'decimal'), true) ? 800 : 720;
    $refused_writes = 0;
    $refuse = static function ($query) use (&$refused_writes, $wpdb) {
        if (preg_match('/^UPDATE\s+`?' . preg_quote($wpdb->options, '/') . '`?\s+SET\s+.+\s+WHERE\s+`?option_name`?\s*=\s*\'zen_reading_width\'\s*$/is', $query)) {
            $refused_writes++;
            return "UPDATE $wpdb->options SET option_value = option_value WHERE 1 = 0";
        }
        return $query;
    };
    if ($case === 'refused') add_filter('query', $refuse);
    $input = array(
        'option_page' => 'zen_options',
        'action' => 'update',
        '_wpnonce' => wp_create_nonce('zen_options-options'),
        '_wp_http_referer' => '/wp-admin/admin.php?page=zen-options&zen_reset=1',
        'zen_reading_width' => $case === 'invalid' ? '599' : ($case === 'unchanged' ? '720' : '800'),
        'zen_show_back_to_top' => '0',
    );
    if ($case === 'refused') {
        // A complete normal form must not report success when only one write is refused.
        $input += zen_get_option_defaults();
        $input['zen_code_head'] = $old_values['zen_code_head'] ?? '';
    } elseif ($case === 'malformed-footer') {
        update_option('zen_footer_text', 'Saved footer');
        $input['zen_footer_text'] = array('unexpected');
    } elseif ($case === 'invalid-date') {
        update_option('zen_site_start_date', '2022-07-01');
        $input['zen_site_start_date'] = '2026-02-30';
    } elseif ($case === 'decimal') {
        update_option('zen_excerpt_length', 75);
        $input['zen_reading_width'] = '800.0';
        $input['zen_excerpt_length'] = '6e1';
    } elseif ($case === 'full') {
        update_option('zen_footer_text', 'Saved footer');
        update_option('zen_category_ids', array(2));
        update_option('zen_content_width', 1080);
        $retired_values = array('zen_category_ids' => get_option('zen_category_ids'), 'zen_content_width' => get_option('zen_content_width'));
        add_action('admin_init', static function () use ($extension_key) {
            register_setting('zen_options', $extension_key, array('sanitize_callback' => 'sanitize_text_field'));
        });
        $input += array(
            'zen_footer_text' => '',
            'zen_category_ids' => array('9'),
            'zen_content_width' => '1200',
            'zen_code_head' => '<meta name="fixture" content="C:\fixture">',
            $extension_key => 'Plugin settings fixture',
        );
    }
    $_POST = wp_slash($input);
    $_REQUEST = $_POST;
    try {
        require ABSPATH . 'wp-admin/options.php';
        throw new RuntimeException('Core controller did not redirect');
    } catch (Zen_Test_Admin_Redirect $result) {
        parse_str((string) parse_url($result->getMessage(), PHP_URL_QUERY), $params);
        if (($params['page'] ?? '') !== 'zen-options' || ($params['settings-updated'] ?? '') !== 'true') {
            throw new RuntimeException('Unexpected native save redirect');
        }
        $check(!isset($params['zen_reset']), 'save redirect discards the prior reset result');
    }
    $notices = get_transient('settings_errors');
    $check(is_array($notices) && $notices !== array(), 'native controller persisted notices');
    $codes = array_column((array) $notices, 'code');
    $check((int) get_option('zen_reading_width') === $expected_width, 'expected width persisted');
    if ($case === 'refused') {
        $row = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'zen_reading_width'));
        $check($refused_writes === 1 && (string) $row === '720', 'real UPDATE refusal leaves the durable width intact');
    }
    $check((string) get_option('zen_show_back_to_top') === '0', 'explicit unchecked value persisted');
    $check((string) get_option('zen_show_toc') === '1', 'existing checked value retained');
    if ($case === 'full') {
        $check(get_option('zen_footer_text', null) === '', 'an explicit empty footer clears the saved text');
        foreach ($retired_values as $key => $value) $check(get_option($key, null) === $value, 'native save ignores stale retired input without changing ' . $key);
        $check(get_option('zen_code_head', null) === $input['zen_code_head'], 'authorized native save preserves literal backslashes');
        $check(get_option($extension_key, null) === $input[$extension_key], 'native save preserves the Settings API group extension');
    } elseif ($case !== 'refused') {
        $check(get_option('zen_code_head', null) === $old_values['zen_code_head'], 'omitted code preserves existing value or absence');
    }
    $field_errors = array(
        'refused' => array('zen_reading_width', '正文宽度', 'zen-reading', '_not_saved'),
        'invalid' => array('zen_reading_width', '正文宽度', 'zen-reading', '_invalid'),
        'malformed-footer' => array('zen_footer_text', '自定义页脚', 'zen-footer', '_invalid'),
        'invalid-date' => array('zen_site_start_date', '博客起始日期', 'zen-footer', '_invalid'),
    );
    if (isset($field_errors[$case])) {
        list($field, $label, $group, $suffix) = $field_errors[$case];
        $code = $field . $suffix;
        if ($case === 'malformed-footer') $check(get_option($field) === 'Saved footer', 'malformed footer preserves the previous text without aborting native save');
        if ($case === 'invalid-date') $check(get_option($field) === '2022-07-01', 'impossible date retains the previously saved date');
        $check(in_array($code, $codes, true), 'native notices identify the unsaved field');
        $check(!in_array('settings_updated', $codes, true), 'native notices do not falsely claim success');
        // Render the settings callback without a script from the native redirect data.
        $GLOBALS['wp_settings_errors'] = array();
        $_GET = $params;
        ob_start();
        zen_options_page_html();
        $html = ob_get_clean();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($document);
        $summary = '//*[contains(concat(" ",normalize-space(@class)," ")," zen-settings-errors ")]';
        $check(trim($xpath->evaluate('string(' . $summary . '//a[@href="#' . $field . '"])')) === $label, 'error summary uses the visible label and links directly to its control');
        $check(str_contains($xpath->evaluate('string(' . $summary . ')'), '其他有效设置可能已保存'), 'summary explains partial save instead of suggesting rollback');
        $input_node = $xpath->query('//*[@id="' . $group . '" and @open]//*[@id="' . $field . '" and @aria-invalid="true"]')->item(0);
        $check($input_node !== null, 'no-script markup opens the affected group and identifies the invalid control');
        $inline_id = $field . '_save_error';
        $explanation = $case === 'refused' ? '此处显示当前保存值' : '已保留上次保存值';
        $check($input_node && in_array($inline_id, explode(' ', $input_node->getAttribute('aria-describedby')), true) && str_contains($xpath->evaluate('string(//*[@id="' . $inline_id . '"])'), $explanation), 'field-near explanation is connected to the control');
    } else {
        $check(in_array('settings_updated', $codes, true), 'a valid save/unchanged value is not reported as a failure');
        $check(!in_array('zen_reading_width_not_saved', $codes, true), 'native notices do not include spurious write failure');
        if ($case === 'decimal') $check((int) get_option('zen_excerpt_length') === 60, 'integral exponent persists as an integer');
    }
} finally {
    if (isset($refuse)) remove_filter('query', $refuse);
    foreach ($old_values as $key => $value) {
        if ($value === null) delete_option($key); else update_option($key, $value);
    }
    if ($old_errors === false) delete_transient('settings_errors'); else set_transient('settings_errors', $old_errors, 30);
    $session->destroy($token);
}
$complete = true;
printf("NATIVE_ADMIN_SAVE_COMPLETE %s: %d checks, %d failures\n", $case, $checks, $failures);
exit($failures ? 1 : 0);
