<?php
/** Native reset permissions, sparse storage and refused DELETE; disposable local fixture only. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$zen_reset_storage_complete = false;
register_shutdown_function(static function () use (&$zen_reset_storage_complete) {
    if (!$zen_reset_storage_complete) { fwrite(STDERR, "NATIVE_RESET_STORAGE_INCOMPLETE\n"); exit(1); }
});
$zen_reset_storage_runtime = dirname(realpath($argv[1]) ?: '');
if (!is_file($zen_reset_storage_runtime . '/.zen-isolated-fixture')) throw new RuntimeException('Disposable fixture marker required');
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
require $argv[1];
global $wpdb;
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') {
    throw new RuntimeException('Disposable local loopback Zen installation required');
}
if (hash_file('sha256', get_template_directory() . '/inc/options.php') !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/inc/options.php')) {
    throw new RuntimeException('Installed options source mismatch');
}
add_filter('pre_http_request', static function () { return new WP_Error('isolated', 'Outbound HTTP blocked'); }, 1);
add_filter('pre_wp_mail', '__return_false');
class Zen_Reset_Storage_Redirect extends RuntimeException {}
class Zen_Reset_Storage_Denied extends RuntimeException {}
$zen_reset_storage_checks = $zen_reset_storage_failures = 0;
function zen_reset_storage_check($condition, $label) {
    $GLOBALS['zen_reset_storage_checks']++;
    $GLOBALS['zen_reset_storage_failures'] += !$condition;
    fwrite(STDOUT, ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL);
}
$zen_reset_storage_old_user = get_current_user_id();
$zen_reset_storage_old_request = $_REQUEST;
$zen_reset_storage_old_post = $_POST;
$zen_reset_storage_old = array();
foreach (array_merge(array_keys(zen_get_option_defaults()), array('zen_category_ids', 'zen_content_width', 'zen_reset_storage_unrelated')) as $key) {
    $zen_reset_storage_old[$key] = get_option($key, null);
}
$admins = get_users(array('role' => 'administrator', 'number' => 1));
if (!$admins) throw new RuntimeException('Fixture administrator required');
wp_set_current_user($admins[0]->ID);
$zen_reset_storage_redirect = static function ($location) { throw new Zen_Reset_Storage_Redirect($location); };
$zen_reset_storage_die = static function () { return static function ($message, $title, $args) {
    throw new Zen_Reset_Storage_Denied($message, $args['response'] ?? 0);
}; };
add_filter('wp_redirect', $zen_reset_storage_redirect, PHP_INT_MAX);
add_filter('wp_die_handler', $zen_reset_storage_die);
function zen_reset_storage_post($action, $nonce) {
    $_REQUEST = $_POST = array('action' => $action, 'zen_reset_nonce' => $nonce);
    do_action('admin_post_' . $action);
}
function zen_reset_storage_call($nonce = null) {
    try { zen_reset_storage_post('zen_reset_confirm', $nonce ?? wp_create_nonce('zen_reset_confirm')); }
    catch (Zen_Reset_Storage_Redirect $result) {
        parse_str((string) parse_url($result->getMessage(), PHP_URL_QUERY), $params);
        if (($params['page'] ?? '') !== 'zen-options') throw new RuntimeException('Unexpected reset redirect');
        return $params['zen_reset'] ?? '';
    }
    throw new RuntimeException('Reset handler did not redirect');
}
$zen_reset_storage_restrict = static function ($caps, $cap) {
    return $cap === 'unfiltered_html' ? array('do_not_allow') : $caps;
};
try {
    zen_register_options();
    foreach (array('zen_reading_width' => 720, 'zen_show_reading_count' => 0, 'zen_footer_text' => '', 'zen_category_ids' => array(9), 'zen_content_width' => 1080, 'zen_reset_storage_unrelated' => 'untouched') as $key => $value) update_option($key, $value);
    foreach (zen_code_fields() as $key => $field) update_option($key, '<script>/* reset storage fixture */</script>');
    delete_option('zen_show_tags');
    $legacy = array(get_option('zen_category_ids'), get_option('zen_content_width'));
    add_filter('map_meta_cap', $zen_reset_storage_restrict, 10, 2);
    try {
        zen_reset_storage_check(current_user_can('manage_options') && !current_user_can('unfiltered_html'), 'native capability control retains settings access but denies executable code');
        $request_nonce = wp_create_nonce('zen_reset_options');
        $confirmation = '';
        $deleted = 0;
        $watch_delete = static function () use (&$deleted) { $deleted++; };
        add_action('delete_option', $watch_delete);
        try {
            zen_reset_storage_post('zen_reset_options', $request_nonce);
        } catch (Zen_Reset_Storage_Denied $result) {
            if ($result->getCode() !== 200) throw $result;
            $confirmation = $result->getMessage();
        } catch (Zen_Reset_Storage_Redirect $result) {
            // Record the pre-fix destructive response as a failure, not an interrupted test.
        } finally { remove_action('delete_option', $watch_delete); }
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . ($confirmation ?: '<p>No confirmation</p>'));
        $xpath = new DOMXPath($document);
        $confirm_nonce = $xpath->evaluate('string(//form[@method="post"][input[@name="action" and @value="zen_reset_confirm"]]/input[@name="zen_reset_nonce"]/@value)');
        zen_reset_storage_check($confirm_nonce !== '' && $confirm_nonce !== $request_nonce, 'first POST renders a second, separately nonced native form');
        zen_reset_storage_check(str_contains($confirmation, '自定义代码将保留') && $xpath->query('//a[contains(@href,"page=zen-options") and normalize-space(.)="取消"]')->length === 1, 'confirmation explains restricted code retention and offers a non-mutating cancel link');
        $width_row = static fn() => $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'zen_reading_width'));
        zen_reset_storage_check($deleted === 0 && (string) $width_row() === '720', 'opening or canceling confirmation writes neither option nor transient deletions');
        try { zen_reset_storage_call($request_nonce); zen_reset_storage_check(false, 'first-step nonce cannot authorize deletion'); }
        catch (Zen_Reset_Storage_Denied $result) { zen_reset_storage_check($result->getCode() === 403 && (string) $width_row() === '720', 'confirm action rejects first-step nonce without deleting the stored row'); }
        catch (RuntimeException $result) { zen_reset_storage_check(false, 'separate confirm action was not dispatched'); }
        $status = $confirm_nonce === '' ? '' : zen_reset_storage_call($confirm_nonce);
    } finally { remove_filter('map_meta_cap', $zen_reset_storage_restrict, 10); }
    zen_reset_storage_check($status === 'partial', 'native restricted reset reports partial result');
    foreach (array_keys(zen_code_fields()) as $key) zen_reset_storage_check(get_option($key) === '<script>/* reset storage fixture */</script>', 'native restricted reset preserves ' . $key);
    zen_reset_storage_check(get_option('zen_reading_width', null) === null && get_option('zen_show_reading_count', null) === null && get_option('zen_footer_text', null) === null, 'native restricted reset removes ordinary explicit numeric zero and empty rows');
    zen_reset_storage_check(array(get_option('zen_category_ids'), get_option('zen_content_width')) === $legacy && get_option('zen_reset_storage_unrelated') === 'untouched', 'native reset leaves retired and unrelated storage unchanged');
    zen_reset_storage_check(zen_reset_storage_call() === '1', 'native authorized reset removes the remaining code');
    foreach (array_keys(zen_code_fields()) as $key) zen_reset_storage_check(get_option($key, null) === null, 'native authorized reset removes ' . $key);
    zen_reset_storage_check(zen_reset_storage_call() === '1', 'native reset of already-absent options succeeds');

    update_option('zen_reading_width', 720);
    $refused = 0;
    $refuse = static function ($query) use (&$refused, $wpdb) {
        if (preg_match('/^DELETE\s+FROM\s+`?' . preg_quote($wpdb->options, '/') . '`?\s+WHERE\s+`?option_name`?\s*=\s*\'zen_reading_width\'/i', $query)) {
            $refused++;
            // A refusal with zero affected rows exercises delete_option()'s native false result.
            return "DELETE FROM $wpdb->options WHERE 1 = 0";
        }
        return $query;
    };
    add_filter('query', $refuse);
    try { $status = zen_reset_storage_call(); }
    finally { remove_filter('query', $refuse); }
    $durable = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'zen_reading_width'));
    zen_reset_storage_check($refused === 1 && (string) $durable === '720' && $status === 'error', 'refused real DELETE retains durable row and never reports reset success: ' . wp_json_encode(array('intercepted' => $refused, 'durable' => $durable, 'status' => $status)));
    wp_cache_delete('zen_reading_width', 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    zen_reset_storage_check((int) get_option('zen_reading_width') === 720, 'fresh native option lookup confirms refused DELETE row');

    $deny = static function ($caps, $cap) { return $cap === 'manage_options' ? array('do_not_allow') : $caps; };
    add_filter('map_meta_cap', $deny, 10, 2);
    try { zen_reset_storage_call(); zen_reset_storage_check(false, 'native reset must reject denied manage_options'); }
    catch (Zen_Reset_Storage_Denied $result) { zen_reset_storage_check(true, 'native reset rejects denied manage_options'); }
    finally { remove_filter('map_meta_cap', $deny, 10); }
    try { zen_reset_storage_call('invalid'); zen_reset_storage_check(false, 'native reset must reject invalid nonce'); }
    catch (Zen_Reset_Storage_Denied $result) { zen_reset_storage_check(true, 'native reset rejects invalid nonce'); }
    zen_reset_storage_check((int) get_option('zen_reading_width') === 720, 'denied capability and nonce leave native row unchanged');
} finally {
    remove_filter('wp_redirect', $zen_reset_storage_redirect, PHP_INT_MAX);
    remove_filter('wp_die_handler', $zen_reset_storage_die);
    remove_filter('map_meta_cap', $zen_reset_storage_restrict, 10);
    foreach ($zen_reset_storage_old as $key => $value) {
        if ($value === null) delete_option($key); else update_option($key, $value);
    }
    wp_set_current_user($zen_reset_storage_old_user);
    $_REQUEST = $zen_reset_storage_old_request;
    $_POST = $zen_reset_storage_old_post;
}
$zen_reset_storage_complete = true;
printf("NATIVE_RESET_STORAGE_COMPLETE %d checks, %d failures\n", $zen_reset_storage_checks, $zen_reset_storage_failures);
exit($zen_reset_storage_failures ? 1 : 0);
