<?php
/**
 * Native directory/password and public-cache regressions; no server/browser.
 * ZEN_TEST_ISOLATED=1 php tests/native-directory-regression.php /isolated/wp-load.php [password|author|lifecycle]
 * Use a disposable local loopback Zen installation with outbound HTTP/mail blocked.
 */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$zen_directory_complete = false;
register_shutdown_function(static function () use (&$zen_directory_complete) {
    if (!$zen_directory_complete) { fwrite(STDERR, "NATIVE_DIRECTORY_INCOMPLETE\n"); exit(1); }
});
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') {
    throw new RuntimeException('Disposable loopback Zen installation required');
}
foreach (array('page-archives.php', 'page-links.php', 'inc/template-tags.php') as $zen_directory_file) {
    if (hash_file('sha256', get_template_directory() . '/' . $zen_directory_file) !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/' . $zen_directory_file)) {
        throw new RuntimeException('Source mismatch: ' . $zen_directory_file);
    }
}
$zen_directory_slice = $argv[2] ?? 'all';
if (!in_array($zen_directory_slice, array('all', 'password', 'author', 'lifecycle'), true)) throw new RuntimeException('Unknown slice');
require_once ABSPATH . 'wp-admin/includes/bookmark.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter('pre_http_request', static function () { return new WP_Error('isolated', 'Outbound HTTP blocked'); }, 1);
add_filter('pre_wp_mail', '__return_false');
$zen_directory_checks = $zen_directory_failures = 0;
$zen_directory_posts = $zen_directory_users = $zen_directory_links = $zen_directory_terms = $zen_directory_options = array();
$zen_directory_old_user = get_current_user_id();
$zen_directory_old_cookie = $_COOKIE['wp-postpass_' . COOKIEHASH] ?? null;
function zen_directory_check($ok, $label) {
    $GLOBALS['zen_directory_checks']++;
    $GLOBALS['zen_directory_failures'] += !$ok;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
}
function zen_directory_post($data = array()) {
    $defaults = array('post_type'=>'post', 'post_status'=>'publish', 'post_title'=>($data['post_type'] ?? 'post') === 'page' ? 'Zen directory page' : 'ZEN_DIRECTORY_PUBLIC', 'post_author'=>1);
    $result = wp_insert_post(wp_slash(array_merge($defaults, $data)), true);
    if (is_wp_error($result) || !$result) throw new RuntimeException('Post fixture failed');
    $GLOBALS['zen_directory_posts'][] = $result;
    return $result;
}
function zen_directory_user($name) {
    $result = wp_insert_user(array('user_login'=>$name, 'user_nicename'=>$name, 'user_pass'=>wp_generate_password(40), 'user_email'=>$name . '@example.test'));
    if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
    $GLOBALS['zen_directory_users'][] = $result;
    return $result;
}
function zen_directory_option($key, $value) {
    if (!array_key_exists($key, $GLOBALS['zen_directory_options'])) $GLOBALS['zen_directory_options'][$key] = get_option($key, null);
    update_option($key, $value);
}
function zen_directory_render($template, $page_id) {
    wp(array('page_id'=>$page_id));
    if (get_queried_object_id() !== $page_id || get_the_ID() !== $page_id) throw new RuntimeException('Directory request fixture mismatch');
    ob_start();
    try { include get_template_directory() . '/' . $template; return ob_get_contents(); }
    finally { ob_end_clean(); wp_reset_query(); }
}
function zen_directory_warm($page_id) {
    zen_directory_render('page-archives.php', $page_id);
    zen_get_site_uptime();
    $cache = array(get_transient('zen_archives_public_posts_v3'), get_transient('zen_site_uptime'));
    if (!is_array($cache[0]) || !is_array($cache[1])) throw new RuntimeException('Warm-cache positive control failed');
    return $cache;
}
function zen_directory_stable($before, $label) {
    zen_directory_check(get_transient('zen_archives_public_posts_v3') === $before[0] && get_transient('zen_site_uptime') === $before[1], $label);
}
function zen_directory_evicted($label) {
    zen_directory_check(get_transient('zen_archives_public_posts_v3') === false && get_transient('zen_site_uptime') === false, $label);
}
function zen_directory_cached_url($post_id) {
    $cache = (array) get_transient('zen_archives_public_posts_v3');
    $context = get_locale() . ':' . (function_exists('pll_current_language') ? pll_current_language() : '');
    foreach ($cache[$context] ?? array() as $row) if ((int) $row['id'] === $post_id) return $row['url'];
    return '';
}
function zen_directory_password() {
    zen_directory_post();
    $bookmark = wp_insert_link(array('link_name'=>'ZEN_DIRECTORY_BOOKMARK', 'link_url'=>'https://example.test/bookmark', 'link_visible'=>'Y'), true);
    if (is_wp_error($bookmark) || !$bookmark) throw new RuntimeException('Bookmark fixture failed');
    $GLOBALS['zen_directory_links'][] = $bookmark;
    $cookie_key = 'wp-postpass_' . COOKIEHASH;
    require_once ABSPATH . WPINC . '/class-phpass.php';
    $hasher = new PasswordHash(8, true);
    foreach (array('page-archives.php'=>'ZEN_DIRECTORY_PUBLIC', 'page-links.php'=>'ZEN_DIRECTORY_BOOKMARK') as $template=>$canary) {
        $page_id = zen_directory_post(array('post_type'=>'page', 'post_password'=>'directory-secret', 'post_content'=>'ZEN_DIRECTORY_PRIVATE'));
        $archive_queries = $bookmark_reads = 0;
        $query_probe = static function ($query) use (&$archive_queries) {
            if ((int) $query->get('posts_per_page') === 500 && $query->get('post_status') === 'publish') $archive_queries++;
        };
        $bookmark_probe = static function ($rows) use (&$bookmark_reads) { $bookmark_reads++; return $rows; };
        add_action('pre_get_posts', $query_probe);
        add_filter('get_bookmarks', $bookmark_probe);
        try {
            foreach (array('cold', 'warm', 'wrong-cookie') as $state) {
                unset($_COOKIE[$cookie_key]);
                if ($state === 'cold') delete_transient('zen_archives_public_posts_v3');
                if ($state === 'warm') {
                    $_COOKIE[$cookie_key] = $hasher->HashPassword('directory-secret');
                    zen_directory_render($template, $page_id);
                    unset($_COOKIE[$cookie_key]);
                }
                if ($state === 'wrong-cookie') $_COOKIE[$cookie_key] = $hasher->HashPassword('wrong-secret');
                $archive_queries = $bookmark_reads = 0;
                zen_directory_check(post_password_required($page_id), "$template $state: Core requires password");
                $html = zen_directory_render($template, $page_id);
                zen_directory_check(substr_count($html, 'class="post-password-form"') === 1 && str_contains($html, 'action="' . esc_url(site_url('wp-login.php?action=postpass', 'login_post')) . '"'), "$template $state: one native password form");
                zen_directory_check(!str_contains($html, $canary) && !str_contains($html, 'ZEN_DIRECTORY_PRIVATE'), "$template $state: directory and private body hidden");
                zen_directory_check($archive_queries === 0 && $bookmark_reads === 0, "$template $state: no generated-directory queries");
                if ($template === 'page-archives.php' && $state === 'cold') zen_directory_check(get_transient('zen_archives_public_posts_v3') === false, 'locked archives do not populate public directory cache');
            }
            $_COOKIE[$cookie_key] = $hasher->HashPassword('directory-secret');
            zen_directory_check(!post_password_required($page_id), "$template: native cookie unlocks page");
            $html = zen_directory_render($template, $page_id);
            zen_directory_check(str_contains($html, $canary) && !str_contains($html, 'post-password-form'), "$template: cookie restores directory");
            zen_directory_check(str_contains($html, 'ZEN_DIRECTORY_PRIVATE') === ($template === 'page-links.php'), "$template: unlocked body preserves existing template contract");
            wp_update_post(array('ID'=>$page_id, 'post_password'=>''));
            unset($_COOKIE[$cookie_key]);
            zen_directory_check(str_contains(zen_directory_render($template, $page_id), $canary), "$template: ordinary public directory remains available");
        } finally {
            remove_action('pre_get_posts', $query_probe);
            remove_filter('get_bookmarks', $bookmark_probe);
            unset($_COOKIE[$cookie_key]);
        }
    }
}
function zen_directory_author() {
    zen_directory_option('permalink_structure', '/%author%/%postname%/');
    $page_id = zen_directory_post(array('post_type'=>'page'));
    $author_id = zen_directory_user('zen-directory-old-author');
    $other_id = zen_directory_user('zen-directory-unpublished-author');
    zen_directory_post(array('post_status'=>'draft', 'post_author'=>$other_id));
    $post_id = zen_directory_post(array('post_author'=>$author_id));
    $before = zen_directory_warm($page_id);
    $old_url = zen_directory_cached_url($post_id);
    zen_directory_check($old_url === get_permalink($post_id) && str_contains($old_url, '/zen-directory-old-author/'), 'author: warm URL matches native permalink');
    $updated = wp_update_user(array('ID'=>$author_id, 'display_name'=>'Changed display', 'description'=>'Changed biography'));
    zen_directory_check(!is_wp_error($updated), 'author: native unrelated profile update succeeds');
    zen_directory_stable($before, 'author: unrelated profile fields retain both caches');
    wp_update_user(array('ID'=>$other_id, 'user_nicename'=>'zen-directory-unpublished-renamed'));
    zen_directory_stable($before, 'author: unrelated author without public posts retains both caches');
    $updated = wp_update_user(array('ID'=>$author_id, 'user_nicename'=>'zen-directory-new-author'));
    zen_directory_check(!is_wp_error($updated) && get_permalink($post_id) !== $old_url, 'author: native nicename update changes permalink');
    zen_directory_check(false === get_transient('zen_archives_public_posts_v3'), 'author: nicename change evicts warm archive');
    zen_directory_check(get_transient('zen_site_uptime') === $before[1], 'author: nicename change preserves unrelated uptime');
    $html = zen_directory_render('page-archives.php', $page_id);
    zen_directory_check(zen_directory_cached_url($post_id) === get_permalink($post_id) && str_contains($html, esc_url(get_permalink($post_id))), 'author: rebuilt archive uses current nicename URL');
    $before = zen_directory_warm($page_id);
    $old_url = get_permalink($post_id);
    $deleted = wp_delete_user($author_id, 1);
    zen_directory_check($deleted && (int) get_post($post_id)->post_author === 1 && get_permalink($post_id) !== $old_url, 'author: native deletion reassigns post and changes permalink');
    zen_directory_check(false === get_transient('zen_archives_public_posts_v3'), 'author: reassignment evicts warm archive');
    zen_directory_check(get_transient('zen_site_uptime') === $before[1], 'author: reassignment preserves unrelated uptime');
    $html = zen_directory_render('page-archives.php', $page_id);
    zen_directory_check(zen_directory_cached_url($post_id) === get_permalink($post_id) && str_contains($html, esc_url(get_permalink($post_id))), 'author: rebuilt reassigned URL matches native permalink');
    zen_directory_option('permalink_structure', '/%postname%/');
    $post_id = zen_directory_post(array('post_author'=>$other_id));
    $before = zen_directory_warm($page_id);
    wp_update_user(array('ID'=>$other_id, 'user_nicename'=>'zen-directory-independent'));
    zen_directory_stable($before, 'author: author-independent structure ignores nicename');
    wp_delete_user($other_id, 1);
    zen_directory_stable($before, 'author: author-independent structure ignores reassignment');
    zen_directory_option('permalink_structure', '/%author%/%postname%/');
    $deleted_id = zen_directory_user('zen-directory-deleted-with-post');
    $deleted_post_id = zen_directory_post(array('post_author'=>$deleted_id));
    zen_directory_warm($page_id);
    zen_directory_check(wp_delete_user($deleted_id) && get_post_status($deleted_post_id) !== 'publish', 'author: deletion without reassignment removes post from public archive');
    zen_directory_evicted('author: native post deletion still evicts both caches');
}
function zen_directory_lifecycle() {
    zen_directory_option('permalink_structure', '/%category%/%postname%/');
    $page_id = zen_directory_post(array('post_type'=>'page'));
    zen_directory_post();
    foreach (array('auto-draft', 'draft', 'pending', 'private', 'future') as $status) {
        $before = zen_directory_warm($page_id);
        $post_id = zen_directory_post(array('post_status'=>$status, 'post_date'=>'2099-01-01 00:00:00', 'post_date_gmt'=>'2099-01-01 00:00:00'));
        zen_directory_check(get_post_status($post_id) === $status, "$status: native fixture status");
        zen_directory_stable($before, "$status: never-public insertion preserves public caches");
        $before = zen_directory_warm($page_id);
        wp_update_post(array('ID'=>$post_id, 'post_content'=>'Never public edit'));
        zen_directory_stable($before, "$status to $status: content save preserves public caches");
    }
    $post_id = zen_directory_post(array('post_status'=>'draft'));
    foreach (array('pending', 'draft', 'private', 'draft') as $status) {
        $before = zen_directory_warm($page_id);
        wp_update_post(array('ID'=>$post_id, 'post_status'=>$status));
        zen_directory_stable($before, "never-public transition to $status preserves public caches");
    }
    $archive_queries = 0;
    $query_probe = static function ($query) use (&$archive_queries) {
        if ((int) $query->get('posts_per_page') === 500 && $query->get('post_status') === 'publish') $archive_queries++;
    };
    add_action('pre_get_posts', $query_probe);
    try {
        zen_directory_render('page-archives.php', $page_id);
        zen_directory_check($archive_queries === 0, 'never-public saves leave next archive render warm without directory query');
    } finally { remove_action('pre_get_posts', $query_probe); }
    foreach (array('publish', 'publish', 'draft', 'publish', 'private', 'publish', 'future', 'publish') as $index=>$status) {
        zen_directory_warm($page_id);
        $data = array('ID'=>$post_id, 'post_status'=>$status, 'post_title'=>'Public boundary ' . $index);
        if ($status === 'future') { $data['post_date'] = $data['post_date_gmt'] = '2099-01-01 00:00:00'; }
        else { $data['post_date'] = $data['post_date_gmt'] = '2020-01-02 00:00:00'; }
        wp_update_post($data);
        zen_directory_check(get_post_status($post_id) === $status, "public boundary $index: native status $status");
        zen_directory_evicted("public boundary $index: $status save evicts both caches");
    }
    zen_directory_warm($page_id);
    wp_trash_post($post_id);
    zen_directory_evicted('published trash evicts both caches');
    zen_directory_warm($page_id);
    wp_untrash_post($post_id);
    zen_directory_evicted('untrash retains existing invalidation boundary');
    wp_update_post(array('ID'=>$post_id, 'post_status'=>'publish'));
    zen_directory_warm($page_id);
    wp_delete_post($post_id, true);
    zen_directory_evicted('published permanent deletion evicts both caches');
    foreach (array('home'=>home_url('/directory-base'), 'permalink_structure'=>'/%postname%/', 'zen_site_start_date'=>'2001-02-03') as $key=>$value) {
        zen_directory_warm($page_id);
        zen_directory_option($key, $value);
        zen_directory_evicted("$key update evicts both caches");
    }
    $before = zen_directory_warm($page_id);
    zen_directory_check($before[1]['year'] === '2001', 'configured start date populates uptime cache');
    zen_directory_check(delete_option('zen_site_start_date'), 'native delete removes explicit start date');
    zen_directory_evicted('deleting explicit start date invalidates previously rendered uptime');
    zen_directory_check(zen_get_site_uptime()['year'] !== '2001', 'uptime falls back after deleting start date');
    zen_directory_warm($page_id);
    add_option('zen_site_start_date', '2002-03-04');
    zen_directory_evicted('first explicit start date invalidates fallback caches');
    $category = wp_insert_term('Zen directory category', 'category');
    if (is_wp_error($category)) throw new RuntimeException($category->get_error_message());
    try {
        zen_directory_warm($page_id);
        wp_update_term($category['term_id'], 'category', array('slug'=>'zen-directory-renamed'));
        zen_directory_evicted('category object editing preserves invalidation');
        zen_directory_warm($page_id);
        wp_delete_term($category['term_id'], 'category');
        zen_directory_evicted('category object deletion preserves invalidation');
    } finally { wp_delete_term($category['term_id'], 'category'); }
    foreach (array('timezone_string'=>'Asia/Shanghai', 'gmt_offset'=>3) as $key=>$value) {
        $before = zen_directory_warm($page_id);
        zen_directory_option($key, $value);
        zen_directory_check(get_transient('zen_site_uptime') === false && get_transient('zen_archives_public_posts_v3') === $before[0], "$key update evicts only uptime");
    }
    // Native language-taxonomy events without depending on a multilingual plugin.
    $registered_here = !taxonomy_exists('language');
    if ($registered_here) register_taxonomy('language', 'post', array('public'=>false));
    try {
        $term = wp_insert_term('Zen directory language', 'language');
        if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
        $GLOBALS['zen_directory_terms'][] = array($term['term_id'], 'language');
        $post_id = zen_directory_post();
        $before = zen_directory_warm($page_id);
        wp_set_object_terms($post_id, array((int) $term['term_id']), 'language');
        zen_directory_check(get_transient('zen_archives_public_posts_v3') === false && get_transient('zen_site_uptime') === $before[1], 'language relation evicts archive only');
        $before = zen_directory_warm($page_id);
        wp_set_object_terms($post_id, array(), 'language');
        zen_directory_check(get_transient('zen_archives_public_posts_v3') === false && get_transient('zen_site_uptime') === $before[1], 'language removal evicts archive only');
    } finally {
        foreach ($GLOBALS['zen_directory_terms'] as list($term_id, $taxonomy)) wp_delete_term($term_id, $taxonomy);
        $GLOBALS['zen_directory_terms'] = array();
        if ($registered_here) unregister_taxonomy('language');
    }
}
try {
    wp_set_current_user(0);
    if (in_array($zen_directory_slice, array('all', 'password'), true)) zen_directory_password();
    if (in_array($zen_directory_slice, array('all', 'author'), true)) zen_directory_author();
    if (in_array($zen_directory_slice, array('all', 'lifecycle'), true)) zen_directory_lifecycle();
} finally {
    wp_reset_query();
    foreach (array_reverse($zen_directory_posts) as $post_id) wp_delete_post($post_id, true);
    foreach ($zen_directory_users as $user_id) if (get_user_by('id', $user_id)) wp_delete_user($user_id);
    foreach ($zen_directory_links as $link_id) wp_delete_link($link_id);
    foreach ($zen_directory_options as $key=>$value) { if ($value === null) delete_option($key); else update_option($key, $value); }
    delete_transient('zen_archives_public_posts_v3');
    delete_transient('zen_site_uptime');
    wp_set_current_user($zen_directory_old_user);
    if ($zen_directory_old_cookie === null) unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    else $_COOKIE['wp-postpass_' . COOKIEHASH] = $zen_directory_old_cookie;
}
$zen_directory_complete = true;
printf("NATIVE_DIRECTORY_COMPLETE %d checks, %d failures (%s)\n", $zen_directory_checks, $zen_directory_failures, $zen_directory_slice);
exit($zen_directory_failures ? 1 : 0);
