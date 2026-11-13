<?php
/** Worker for native-counter-concurrency.py; genuine WordPress storage only. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') {
    throw new RuntimeException('Isolated loopback Zen required');
}
if (hash_file('sha256', get_template_directory() . '/inc/options.php') !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/inc/options.php')) {
    throw new RuntimeException('Fixture counter source mismatch');
}
$mode = $argv[2];
if ($mode === 'create') {
    update_option('zen_show_reading_count', 1);
    $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Counter concurrency fixture'), true);
    if (!$id || is_wp_error($id)) throw new RuntimeException('Fixture insert failed');
    if (isset($argv[3]) && $argv[3] !== 'cold') update_post_meta($id, 'zen_view_count', (int) $argv[3]);
    echo json_encode(array('id' => $id));
} elseif ($mode === 'visit') {
    $id = (int) $argv[3];
    $_SERVER['REMOTE_ADDR'] = $argv[4];
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
    wp_set_current_user(0);
    $barrier = $argv[5];
    // Both workers finish the first genuine transient lookup before either writes.
    // Return the original filter value: no storage operation is substituted.
    $ready = false;
    $key = 'zen_viewed_' . $id . '_' . md5(inet_ntop(inet_pton($argv[4])));
    add_action('set_transient_' . $key, function () use ($barrier) { file_put_contents($barrier . '.bucket.' . getmypid(), "set\n", FILE_APPEND); });
    add_filter('transient_' . $key, function ($value) use ($barrier, &$ready) {
        if (!$ready) {
            $ready = true;
            touch($barrier . '.' . getmypid());
            $until = microtime(true) + 10;
            while (!file_exists($barrier . '.go')) {
                if (microtime(true) > $until) throw new RuntimeException('Barrier timed out');
                usleep(1000);
            }
        }
        return $value;
    });
    $count = zen_track_post_view($id);
    echo json_encode(array('count' => $count));
} elseif ($mode === 'inspect') {
    $id = (int) $argv[3];
    $buckets = array();
    foreach (array_slice($argv, 4) as $ip) {
        $key = 'zen_viewed_' . $id . '_' . md5(inet_ntop(inet_pton($ip)));
        $buckets[$ip] = array(get_transient($key), (int) get_option('_transient_timeout_' . $key) - time());
    }
    echo json_encode(array('count' => (int) get_post_meta($id, 'zen_view_count', true), 'rows' => count(get_post_meta($id, 'zen_view_count', false)), 'buckets' => $buckets));
} elseif ($mode === 'delete') {
    $id = (int) $argv[3];
    foreach (array_slice($argv, 4) as $ip) delete_transient('zen_viewed_' . $id . '_' . md5(inet_ntop(inet_pton($ip))));
    wp_delete_post($id, true);
    echo json_encode(array('deleted' => get_post($id) === null));
} else {
    throw new RuntimeException('Unknown test mode');
}
