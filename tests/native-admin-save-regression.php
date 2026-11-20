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
    $case = $argv[2] ?? 'refused';
    if (!in_array($case, array('refused', 'invalid', 'unchanged', 'create', 'full', 'malformed-footer'), true)) throw new InvalidArgumentException('Unknown save case');
    if ($case === 'create') delete_option('zen_reading_width');
    $expected_width = in_array($case, array('create', 'full', 'malformed-footer'), true) ? 800 : 720;
    $refuse = static fn($new, $old) => $old;
    if ($case === 'refused') add_filter('pre_update_option_zen_reading_width', $refuse, 10, 2);
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
    if ($case === 'refused' || $case === 'invalid' || $case === 'malformed-footer') {
        $code = $case === 'malformed-footer' ? 'zen_footer_text_invalid' : ($case === 'refused' ? 'zen_reading_width_not_saved' : 'zen_reading_width_invalid');
        if ($case === 'malformed-footer') $check(get_option('zen_footer_text') === 'Saved footer', 'malformed footer preserves the previous text without aborting native save');
        $check(in_array($code, $codes, true), 'native notices identify the unsaved field');
        $check(!in_array('settings_updated', $codes, true), 'native notices do not falsely claim success');
    } else {
        $check(in_array('settings_updated', $codes, true), 'a valid save/unchanged value is not reported as a failure');
        $check(!in_array('zen_reading_width_not_saved', $codes, true), 'native notices do not include spurious write failure');
    }
} finally {
    if (isset($refuse)) remove_filter('pre_update_option_zen_reading_width', $refuse, 10);
    foreach ($old_values as $key => $value) {
        if ($value === null) delete_option($key); else update_option($key, $value);
    }
    if ($old_errors === false) delete_transient('settings_errors'); else set_transient('settings_errors', $old_errors, 30);
    $session->destroy($token);
}
$complete = true;
printf("NATIVE_ADMIN_SAVE_COMPLETE %s: %d checks, %d failures\n", $case, $checks, $failures);
exit($failures ? 1 : 0);
