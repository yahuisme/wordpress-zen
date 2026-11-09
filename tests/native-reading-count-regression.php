<?php
/** Real WordPress AJAX hooks/JSON/metadata; disposable loopback installation only. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) {
    fwrite(STDERR, "Set ZEN_TEST_ISOLATED=1 and pass isolated wp-load.php.\n");
    exit(1);
}
$zen_view_complete = false;
register_shutdown_function(function () use (&$zen_view_complete) {
    if (!$zen_view_complete) {
        fwrite(STDERR, "Native reading-count regression did not complete.\n");
        exit(1);
    }
});
define('DOING_AJAX', true);
define('WP_ADMIN', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1'
    || get_template() !== 'zen') {
    throw new RuntimeException('Isolated loopback WordPress with Zen required');
}
add_filter('pre_http_request', function () { return new WP_Error('isolated', 'Outbound HTTP blocked'); });
add_filter('pre_wp_mail', '__return_false');
final class Zen_View_Ajax_Die extends RuntimeException {}
$zen_view_checks = array();
function zen_view_check($condition, $label) {
    global $zen_view_checks;
    $zen_view_checks[] = array((bool) $condition, $label);
}
function zen_view_ajax($post_id) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('action' => 'zen_post_view', 'post_id' => $post_id);
    $_GET = array();
    $_REQUEST = $_POST;
    $hook = is_user_logged_in() ? 'wp_ajax_zen_post_view' : 'wp_ajax_nopriv_zen_post_view';
    $handler = function () { return function () { throw new Zen_View_Ajax_Die(); }; };
    add_filter('wp_die_ajax_handler', $handler);
    $terminated = false;
    ob_start();
    try {
        do_action($hook);
    } catch (Zen_View_Ajax_Die $error) {
        $terminated = true;
    } finally {
        $output = ob_get_clean();
        remove_filter('wp_die_ajax_handler', $handler);
    }
    zen_view_check($terminated, $hook . ' terminates via native wp_die');
    $response = json_decode($output, true);
    zen_view_check(is_array($response) && isset($response['success']), $hook . ' emits native JSON envelope');
    return $response;
}
$zen_view_saved_option = get_option('zen_show_reading_count', null);
$zen_view_saved_user = get_current_user_id();
$zen_view_ids = array();
try {
    zen_view_check(wp_doing_ajax(), 'native AJAX context is active');
    zen_view_check(has_action('wp_ajax_nopriv_zen_post_view') !== false, 'anonymous AJAX hook registered');
    zen_view_check(has_action('wp_ajax_zen_post_view') !== false, 'authenticated AJAX hook registered');
    update_option('zen_show_reading_count', 1);
    wp_set_current_user(0);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.231';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Zen native AJAX fixture'), true);
    if (is_wp_error($id) || !$id) throw new RuntimeException('Post fixture failed');
    $zen_view_ids[] = $id;
    $response = zen_view_ajax((string) $id);
    zen_view_check(($response['success'] ?? false) === true && ($response['data']['count'] ?? null) === number_format_i18n(1), 'anonymous POST returns localized incremented count');
    zen_view_check((int) get_post_meta($id, 'zen_view_count', true) === 1, 'native post metadata persists AJAX increment');
    $response = zen_view_ajax((string) $id);
    zen_view_check(($response['data']['count'] ?? null) === number_format_i18n(1), 'repeat AJAX reads current count without increment');
    $key = 'zen_viewed_' . $id . '_' . md5('192.0.2.231');
    zen_view_check(get_transient($key) !== false && get_option('_transient_timeout_' . $key) > time()
        && get_option('_transient_timeout_' . $key) <= time() + 5 * MINUTE_IN_SECONDS, 'native transient stores five-minute IP bucket');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.239';
    $response = zen_view_ajax((string) $id);
    zen_view_check(($response['data']['count'] ?? null) === number_format_i18n(1), 'untrusted forwarded IP cannot bypass bucket');
    $_SERVER['REMOTE_ADDR'] = '2001:db8::231';
    $response = zen_view_ajax((string) $id);
    zen_view_check(($response['data']['count'] ?? null) === number_format_i18n(2), 'different native peer increments same article');
    $_SERVER['REMOTE_ADDR'] = '2001:0db8:0000:0000:0000:0000:0000:0231';
    $response = zen_view_ajax((string) $id);
    zen_view_check(($response['data']['count'] ?? null) === number_format_i18n(2), 'equivalent IPv6 spellings share native transient');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.232';
    $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
    $response = zen_view_ajax((string) $id);
    zen_view_check(($response['data']['count'] ?? null) === number_format_i18n(2), 'crawler AJAX reads count without increment');
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $admins = get_users(array('role' => 'administrator', 'number' => 1));
    if (!$admins) throw new RuntimeException('Fixture administrator required');
    wp_set_current_user($admins[0]->ID);
    $response = zen_view_ajax((string) $id);
    zen_view_check(($response['success'] ?? false) === true && ($response['data']['count'] ?? null) === number_format_i18n(2), 'authenticated hook reads count without increment');
    wp_set_current_user(0);
    foreach (array(
        'page' => array('post_type' => 'page'),
        'draft' => array('post_status' => 'draft'),
        'private' => array('post_status' => 'private'),
        'password' => array('post_password' => 'isolated-secret'),
    ) as $label => $overrides) {
        $other = wp_insert_post(array_merge(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Zen AJAX exclusion fixture'), $overrides), true);
        if (is_wp_error($other) || !$other) throw new RuntimeException('Exclusion fixture failed');
        $zen_view_ids[] = $other;
        $response = zen_view_ajax((string) $other);
        zen_view_check(($response['success'] ?? null) === false && !metadata_exists('post', $other, 'zen_view_count'), $label . ' rejected without creating count metadata');
    }
    $response = zen_view_ajax((string) $id . '.0');
    zen_view_check(($response['success'] ?? null) === false, 'noncanonical article ID rejected by native JSON endpoint');
    update_option('zen_show_reading_count', 0);
    $response = zen_view_ajax((string) $id);
    zen_view_check(($response['success'] ?? null) === false, 'disabled option rejects native AJAX');
    zen_view_check((int) get_post_meta($id, 'zen_view_count', true) === 2, 'excluded and disabled requests preserve stored count');
} finally {
    wp_set_current_user($zen_view_saved_user);
    if (null === $zen_view_saved_option) delete_option('zen_show_reading_count');
    else update_option('zen_show_reading_count', $zen_view_saved_option);
    foreach (array_reverse($zen_view_ids) as $id) {
        foreach (array('192.0.2.231', '2001:db8::231', '192.0.2.232') as $ip) {
            delete_transient('zen_viewed_' . $id . '_' . md5(inet_ntop(inet_pton($ip))));
        }
        wp_delete_post($id, true);
    }
}
$zen_view_failed = 0;
foreach ($zen_view_checks as list($passed, $label)) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$passed) $zen_view_failed++;
}
$zen_view_complete = true;
printf("NATIVE_READING_COUNT_COMPLETE %d checks, %d failures\n", count($zen_view_checks), $zen_view_failed);
exit($zen_view_failed ? 1 : 0);
