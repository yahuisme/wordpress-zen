<?php
/** Native lease/failure/expiry boundaries; disposable fixture only. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$complete = false;
register_shutdown_function(function () use (&$complete) { if (!$complete) { fwrite(STDERR, "NATIVE_COUNTER_STORAGE_INCOMPLETE\n"); exit(1); } });
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') throw new RuntimeException('Isolated local Zen required');
if (hash_file('sha256', get_template_directory() . '/inc/options.php') !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/inc/options.php')) throw new RuntimeException('Source mismatch');
$old = get_option('zen_show_reading_count', null); $old_user = get_current_user_id();
$id = 0; $checks = $failures = 0;
function counter_storage_check($ok, $label) {
    global $checks, $failures;
    $checks++; $failures += !$ok; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
try {
    update_option('zen_show_reading_count', 1); wp_set_current_user(0);
    $id = wp_insert_post(array('post_type'=>'post','post_status'=>'publish','post_title'=>'Counter storage fixture'), true);
    if (!$id || is_wp_error($id)) throw new RuntimeException('Fixture insert failed');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.244'; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    $key = 'zen_viewed_' . $id . '_' . md5($_SERVER['REMOTE_ADDR']);
    $lock_key = 'zen_view_lock_' . $id;
    $refuse = static function ($value, $object_id, $meta_key) use ($id) { return $object_id === $id && $meta_key === 'zen_view_count' ? false : $value; };
    add_filter('update_post_metadata', $refuse, 10, 3);
    try { $result = zen_track_post_view($id); } finally { remove_filter('update_post_metadata', $refuse, 10); }
    counter_storage_check($result === 0 && !metadata_exists('post', $id, 'zen_view_count') && get_transient($key) === false, 'refused count write does not consume visitor bucket');
    counter_storage_check($wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $lock_key)) === null, 'refused write releases lease');
    counter_storage_check(zen_track_post_view($id) === 1, 'same visitor can retry failed count write');
    // Expiration must also refresh negative/per-request option caches.
    update_option('_transient_timeout_' . $key, time() - 1);
    counter_storage_check(zen_track_post_view($id) === 2, 'expired five-minute bucket permits a new visit');
    delete_transient($key);
    add_option($lock_key, (time() - 60) . ':crashed-owner', '', false);
    counter_storage_check(zen_track_post_view($id) === 3, 'expired owner lease is reclaimed atomically');
    delete_transient($key);
    add_option($lock_key, (time() + 60) . ':other-owner', '', false);
    $start = microtime(true);
    counter_storage_check(zen_track_post_view($id) === 3 && microtime(true) - $start < 2 && get_transient($key) === false, 'active foreign lease fails closed in bounded time without consuming bucket');
    counter_storage_check($wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $lock_key)) === (string) get_option($lock_key), 'timeout does not delete foreign lease');
    delete_option($lock_key);
    counter_storage_check(zen_track_post_view($id) === 4, 'visitor retries after foreign lease release');
    // Simulate a reclaimed lease immediately before finally; old owner cannot delete it.
    delete_transient($key);
    $replace = static function ($value, $object_id, $meta_key) use ($id, $lock_key, $wpdb) {
        if ($object_id === $id && $meta_key === 'zen_view_count') $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s", (time()+60).':replacement-owner', $lock_key));
        return $value;
    };
    add_filter('update_post_metadata', $replace, 10, 3);
    try { zen_track_post_view($id); } finally { remove_filter('update_post_metadata', $replace, 10); }
    counter_storage_check(str_ends_with((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $lock_key)), ':replacement-owner'), 'finally cannot delete a replacement lease');
} finally {
    if ($id && !is_wp_error($id)) { delete_transient('zen_viewed_' . $id . '_' . md5('192.0.2.244')); delete_option('zen_view_lock_' . $id); wp_delete_post($id, true); }
    if ($old === null) delete_option('zen_show_reading_count'); else update_option('zen_show_reading_count', $old);
    wp_set_current_user($old_user);
}
$complete = true;
printf("NATIVE_COUNTER_STORAGE_COMPLETE %d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
