<?php
/** Native default-category permalink cache lifecycle; disposable local SQLite fixture only. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$zen_cache_policy_complete = false;
register_shutdown_function(static function () use (&$zen_cache_policy_complete) {
    if (!$zen_cache_policy_complete) { fwrite(STDERR, "NATIVE_CACHE_POLICY_INCOMPLETE\n"); exit(1); }
});
$zen_cache_policy_runtime = dirname(realpath($argv[1]) ?: '');
if (!is_file($zen_cache_policy_runtime . '/.zen-isolated-fixture')) throw new RuntimeException('Disposable fixture marker required');
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') {
    throw new RuntimeException('Disposable local loopback Zen installation required');
}
foreach (array('inc/template-tags.php', 'page-archives.php') as $zen_cache_policy_file) {
    if (hash_file('sha256', get_template_directory() . '/' . $zen_cache_policy_file) !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/' . $zen_cache_policy_file)) {
        throw new RuntimeException('Source mismatch: ' . $zen_cache_policy_file);
    }
}
add_filter('pre_http_request', static function () { return new WP_Error('isolated', 'Outbound HTTP blocked'); }, 1);
add_filter('pre_wp_mail', '__return_false');
$zen_cache_policy_checks = $zen_cache_policy_failures = 0;
$zen_cache_policy_old = array();
$zen_cache_policy_posts = $zen_cache_policy_terms = array();
foreach (array('default_category', 'permalink_structure') as $zen_cache_policy_key) {
    $zen_cache_policy_old[$zen_cache_policy_key] = get_option($zen_cache_policy_key, null);
}
function zen_cache_policy_check($condition, $label) {
    $GLOBALS['zen_cache_policy_checks']++;
    $GLOBALS['zen_cache_policy_failures'] += !$condition;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
}
function zen_cache_policy_warm($page_id, $post_id) {
    wp(array('page_id' => $page_id));
    ob_start();
    try { include get_template_directory() . '/page-archives.php'; }
    finally { ob_end_clean(); wp_reset_query(); }
    $cache = get_transient(zen_archive_cache_key());
    foreach ((array) $cache as $row) {
        if ((int) $row['id'] === $post_id) return $row['url'];
    }
    throw new RuntimeException('Native archive positive control did not contain fixture post');
}
try {
    foreach (array('Zen fallback category old', 'Zen fallback category new') as $zen_cache_policy_name) {
        $term = wp_insert_term($zen_cache_policy_name, 'category');
        if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
        $zen_cache_policy_terms[] = (int) $term['term_id'];
    }
    foreach (array('post', 'page') as $zen_cache_policy_type) {
        $result = wp_insert_post(array('post_type' => $zen_cache_policy_type, 'post_status' => 'publish', 'post_title' => 'Zen category cache policy'), true);
        if (!$result || is_wp_error($result)) throw new RuntimeException('Fixture insert failed');
        $zen_cache_policy_posts[] = $result;
    }
    list($zen_cache_policy_post, $zen_cache_policy_page) = $zen_cache_policy_posts;
    // Core permits a post with no category; its permalink uses the configured default.
    wp_set_object_terms($zen_cache_policy_post, array(), 'category');
    zen_cache_policy_check(wp_get_post_categories($zen_cache_policy_post) === array(), 'native post has no explicit category relationship');
    update_option('permalink_structure', '/%category%/%postname%/');
    update_option('default_category', $zen_cache_policy_terms[0]);
    $old_url = zen_cache_policy_warm($zen_cache_policy_page, $zen_cache_policy_post);
    zen_cache_policy_check($old_url === get_permalink($zen_cache_policy_post), 'warm archive URL matches native old fallback category');
    $old_uptime = zen_get_site_uptime();
    update_option('default_category', $zen_cache_policy_terms[1]);
    $new_url = get_permalink($zen_cache_policy_post);
    zen_cache_policy_check($old_url !== $new_url, 'changing the default category changes native uncategorized permalink without saving the post');
    zen_cache_policy_check(false === get_transient(zen_archive_cache_key()), 'default category change evicts cached category-based archive URLs');
    zen_cache_policy_check(get_transient('zen_site_uptime') === $old_uptime, 'default category change preserves unrelated uptime');
    zen_cache_policy_check(zen_cache_policy_warm($zen_cache_policy_page, $zen_cache_policy_post) === $new_url, 'rewarmed archive follows native new fallback category');
    $stable = get_transient(zen_archive_cache_key());
    update_option('default_category', $zen_cache_policy_terms[1]);
    zen_cache_policy_check(get_transient(zen_archive_cache_key()) === $stable, 'unchanged default category does not evict populated archive');
    update_option('permalink_structure', '/%postname%/');
    zen_cache_policy_warm($zen_cache_policy_page, $zen_cache_policy_post);
    $stable = get_transient(zen_archive_cache_key());
    update_option('default_category', $zen_cache_policy_terms[0]);
    zen_cache_policy_check(get_transient(zen_archive_cache_key()) === $stable, 'category-independent permalinks keep archive warm when the default changes');
} finally {
    foreach ($zen_cache_policy_old as $zen_cache_policy_key => $zen_cache_policy_value) {
        if ($zen_cache_policy_value === null) delete_option($zen_cache_policy_key);
        else update_option($zen_cache_policy_key, $zen_cache_policy_value);
    }
    foreach (array_reverse($zen_cache_policy_posts) as $zen_cache_policy_post_id) wp_delete_post($zen_cache_policy_post_id, true);
    foreach ($zen_cache_policy_terms as $zen_cache_policy_term_id) wp_delete_term($zen_cache_policy_term_id, 'category');
    delete_transient(zen_archive_cache_key());
    delete_transient('zen_site_uptime');
}
$zen_cache_policy_complete = true;
printf("NATIVE_CACHE_POLICY_COMPLETE %d checks, %d failures\n", $zen_cache_policy_checks, $zen_cache_policy_failures);
exit($zen_cache_policy_failures ? 1 : 0);
