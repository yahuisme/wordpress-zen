<?php
/**
 * Native directory/password and public-cache regressions; no server/browser.
 * ZEN_TEST_ISOLATED=1 php tests/native-directory-regression.php /isolated/wp-load.php [password|author|lifecycle|secondary|discovery|guestbook|queries]
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
foreach (array('footer.php', 'page-archives.php', 'page-links.php', 'page-tags.php', 'page-notes.php', 'comments-notes.php', 'comments.php', 'inc/comments.php', 'search.php', 'inc/template-tags.php') as $zen_directory_file) {
    if (hash_file('sha256', get_template_directory() . '/' . $zen_directory_file) !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/' . $zen_directory_file)) {
        throw new RuntimeException('Source mismatch: ' . $zen_directory_file);
    }
}
$zen_directory_slice = $argv[2] ?? 'all';
if (!in_array($zen_directory_slice, array('all', 'password', 'author', 'lifecycle', 'secondary', 'discovery', 'guestbook', 'queries'), true)) throw new RuntimeException('Unknown slice');
require_once ABSPATH . 'wp-admin/includes/bookmark.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter('pre_http_request', static function () { return new WP_Error('isolated', 'Outbound HTTP blocked'); }, 1);
add_filter('pre_wp_mail', '__return_false');
$zen_directory_checks = $zen_directory_failures = 0;
$zen_directory_posts = $zen_directory_users = $zen_directory_links = $zen_directory_terms = $zen_directory_options = array();
$zen_directory_comments = array();
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
    $before = zen_directory_warm($page_id);
    $draft = zen_directory_post(array('post_status'=>'draft'));
    wp_trash_post($draft);
    wp_untrash_post($draft);
    wp_delete_post($draft, true);
    zen_directory_stable($before, 'trashing, restoring and deleting a draft leaves public caches warm');
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
    foreach (array('publish', 'draft', 'publish', 'private', 'publish', 'future', 'publish') as $index=>$status) {
        zen_directory_warm($page_id);
        $data = array('ID'=>$post_id, 'post_status'=>$status, 'post_title'=>'Public boundary ' . $index);
        if ($status === 'future') { $data['post_date'] = $data['post_date_gmt'] = '2099-01-01 00:00:00'; }
        else { $data['post_date'] = $data['post_date_gmt'] = '2020-01-02 00:00:00'; }
        wp_update_post($data);
        zen_directory_check(get_post_status($post_id) === $status, "public boundary $index: native status $status");
        zen_directory_evicted("public boundary $index: $status save evicts both caches");
    }
    $before = zen_directory_warm($page_id);
    wp_update_post(array('ID'=>$post_id, 'post_content'=>'Body-only public edit'));
    zen_directory_stable($before, 'published body-only edit preserves both public caches');
    $archive_queries = 0;
    add_action('pre_get_posts', $query_probe);
    try {
        zen_directory_render('page-archives.php', $page_id);
        zen_directory_check($archive_queries === 0, 'body-only edit leaves next archive render warm without directory query');
    } finally { remove_action('pre_get_posts', $query_probe); }

    foreach (array('post_title'=>'Archive headline changed', 'post_name'=>'archive-slug-changed', 'post_password'=>'archive-test-lock') as $field=>$value) {
        $before = zen_directory_warm($page_id);
        wp_update_post(array('ID'=>$post_id, $field=>$value));
        zen_directory_check(get_transient('zen_archives_public_posts_v3') === false && get_transient('zen_site_uptime') === $before[1], "$field edit evicts only the archive");
        $html = zen_directory_render('page-archives.php', $page_id);
        zen_directory_check(str_contains($html, esc_html(get_the_title($post_id))) && zen_directory_cached_url($post_id) === get_permalink($post_id), "$field edit refreshes cached title and permalink");
    }
    zen_directory_warm($page_id);
    wp_update_post(array('ID'=>$post_id, 'post_date'=>'2019-02-03 12:00:00', 'post_date_gmt'=>get_gmt_from_date('2019-02-03 12:00:00')));
    zen_directory_evicted('publication time edit evicts both caches');
    zen_directory_check(str_contains(zen_directory_render('page-archives.php', $page_id), 'datetime="2019-02-03"'), 'reloaded archive shows the new publication date');
    zen_directory_warm($page_id);
    wp_update_post(array('ID'=>$post_id, 'post_type'=>'page'));
    zen_directory_evicted('converting a published post to a page evicts both caches');
    zen_directory_warm($page_id);
    zen_directory_check(zen_directory_cached_url($post_id) === '', 'converted page is not part of the refreshed post archive');
    wp_update_post(array('ID'=>$post_id, 'post_type'=>'post'));
    zen_directory_evicted('converting a published page to a post evicts both caches');
    zen_directory_warm($page_id);
    zen_directory_check(zen_directory_cached_url($post_id) === get_permalink($post_id), 'converted post appears in the refreshed archive');
    $scheduled = zen_directory_post(array('post_status'=>'future', 'post_date'=>'2099-01-01 00:00:00', 'post_date_gmt'=>'2099-01-01 00:00:00'));
    wp_publish_post($scheduled);
    zen_directory_evicted('native scheduled publication evicts both caches');
    zen_directory_warm($page_id);
    wp_trash_post($post_id);
    zen_directory_evicted('published trash evicts both caches');
    $before = zen_directory_warm($page_id);
    wp_untrash_post($post_id);
    zen_directory_check(get_post_status($post_id) === 'draft', 'native restore returns a published post to draft');
    zen_directory_stable($before, 'restoring to draft keeps both public caches warm');
    wp_update_post(array('ID'=>$post_id, 'post_status'=>'publish'));
    zen_directory_warm($page_id);
    wp_delete_post($post_id, true);
    zen_directory_evicted('published permanent deletion evicts both caches');
    foreach (array('home'=>home_url('/directory-base'), 'permalink_structure'=>'/%postname%/') as $key=>$value) {
        $before = zen_directory_warm($page_id);
        zen_directory_option($key, $value);
        zen_directory_check(get_transient('zen_archives_public_posts_v3') === false && get_transient('zen_site_uptime') === $before[1], "$key update evicts only archive links");
    }
    $before = zen_directory_warm($page_id);
    zen_directory_option('zen_site_start_date', '2001-02-03');
    zen_directory_check(get_transient('zen_site_uptime') === false && get_transient('zen_archives_public_posts_v3') === $before[0], 'changing explicit start date evicts only uptime');
    zen_directory_check(zen_get_site_uptime()['year'] === '2001', 'configured start date populates uptime cache');
    zen_directory_check(delete_option('zen_site_start_date'), 'native delete removes explicit start date');
    zen_directory_check(get_transient('zen_site_uptime') === false && get_transient('zen_archives_public_posts_v3') === $before[0], 'deleting explicit start date evicts only uptime');
    zen_directory_check(zen_get_site_uptime()['year'] !== '2001', 'uptime falls back after deleting start date');
    add_option('zen_site_start_date', '2002-03-04');
    zen_directory_check(get_transient('zen_site_uptime') === false && get_transient('zen_archives_public_posts_v3') === $before[0], 'adding explicit start date evicts only uptime');
    zen_directory_option('timezone_string', 'UTC');
    foreach (array('1970-01-01', '1969-12-31') as $date) {
        zen_get_site_uptime();
        zen_directory_option('zen_site_start_date', $date);
        zen_directory_check(get_transient('zen_site_uptime') === false, 'editing a historical start date evicts warm uptime');
        $uptime = zen_get_site_uptime();
        zen_directory_check($uptime['year'] === substr($date, 0, 4) && $uptime['days'] > 1, "UTC uptime accepts $date");
    }
    zen_directory_option('zen_site_start_date', '1970-01-01');
    zen_get_site_uptime();
    zen_directory_option('timezone_string', 'Asia/Hong_Kong');
    zen_directory_check(get_transient('zen_site_uptime') === false, 'timezone change evicts cached epoch uptime');
    $uptime = zen_get_site_uptime();
    zen_directory_check($uptime['year'] === '1970' && $uptime['days'] > 1, 'positive-offset site accepts a local epoch date before UTC epoch');
    $category = wp_insert_term('Zen directory category', 'category');
    if (is_wp_error($category)) throw new RuntimeException($category->get_error_message());
    try {
        $before = zen_directory_warm($page_id);
        wp_update_term($category['term_id'], 'category', array('slug'=>'zen-directory-renamed'));
        zen_directory_check(get_transient('zen_archives_public_posts_v3') === false && get_transient('zen_site_uptime') === $before[1], 'category editing evicts only archive links');
        $before = zen_directory_warm($page_id);
        wp_delete_term($category['term_id'], 'category');
        zen_directory_check(get_transient('zen_archives_public_posts_v3') === false && get_transient('zen_site_uptime') === $before[1], 'category deletion evicts only archive links');
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
function zen_directory_nested_link() {
    $existing = get_pages(array('meta_key'=>'_wp_page_template', 'meta_value'=>'page-archives.php', 'hierarchical'=>false));
    $parent = zen_directory_post(array('post_type'=>'page', 'post_title'=>'Native archive parent'));
    $child = zen_directory_post(array('post_type'=>'page', 'post_title'=>'Nested archives', 'post_parent'=>$parent));
    foreach ($existing as $page) delete_post_meta($page->ID, '_wp_page_template');
    try {
        update_post_meta($child, '_wp_page_template', 'page-archives.php');
        zen_directory_check(zen_get_archives_url() === get_permalink($child), 'sole published archive template can be a nested page');
    } finally {
        foreach ($existing as $page) update_post_meta($page->ID, '_wp_page_template', 'page-archives.php');
    }
}
function zen_directory_discovery() {
    $templates = array('page-archives.php'=>'archives', 'page-tags.php'=>'tags', 'page-links.php'=>'links', 'page-notes.php'=>'notes');
    $saved = array();
    foreach (get_pages(array('post_status'=>'publish', 'hierarchical'=>false)) as $existing) {
        $saved[$existing->ID] = array('slug'=>$existing->post_name, 'template'=>get_post_meta($existing->ID, '_wp_page_template', true));
        if (in_array($saved[$existing->ID]['template'], array_keys($templates), true)) delete_post_meta($existing->ID, '_wp_page_template');
        if (in_array($existing->post_name, array_values($templates), true) && !$existing->post_parent) wp_update_post(array('ID'=>$existing->ID, 'post_name'=>'fixture-existing-' . $existing->ID));
    }
    try {
        foreach ($templates as $template=>$slug) {
            $page = zen_directory_post(array('post_type'=>'page', 'post_name'=>$slug));
            zen_directory_check(zen_get_directory_url($template) === get_permalink($page), "$slug default-template page remains discoverable");
            foreach (array('draft', 'private') as $status) {
                wp_update_post(array('ID'=>$page, 'post_status'=>$status));
                zen_directory_check(zen_get_directory_url($template) === '', "$slug $status page is not offered");
            }
            wp_update_post(array('ID'=>$page, 'post_status'=>'publish'));
            $other = $template === 'page-links.php' ? 'page-tags.php' : 'page-links.php';
            update_post_meta($page, '_wp_page_template', $other);
            zen_directory_check(zen_get_directory_url($template) === '', "$slug assigned another template is not offered");
            delete_post_meta($page, '_wp_page_template');
            $explicit = zen_directory_post(array('post_type'=>'page', 'post_name'=>'explicit-' . $slug));
            update_post_meta($explicit, '_wp_page_template', $template);
            zen_directory_check(zen_get_directory_url($template) === get_permalink($explicit), "$slug explicitly assigned template has priority");
            wp_delete_post($explicit, true);
            wp_delete_post($page, true);
        }
    } finally {
        foreach ($saved as $id=>$state) {
            wp_update_post(array('ID'=>$id, 'post_name'=>$state['slug']));
            if ($state['template'] !== '') update_post_meta($id, '_wp_page_template', $state['template']);
            else delete_post_meta($id, '_wp_page_template');
        }
    }
}
function zen_directory_footer() {
    global $wpdb;
    $templates = array('page-archives.php', 'page-tags.php', 'page-notes.php', 'page-links.php');
    $locations = get_theme_mod('nav_menu_locations', array());
    $saved = array();
    $menu = wp_create_nav_menu('Zen directory fixture');
    if (is_wp_error($menu)) throw new RuntimeException($menu->get_error_message());
    set_theme_mod('nav_menu_locations', array());
    zen_directory_option('zen_show_footer_rss', 1);
    $render = static function ($cold = true) use ($wpdb) {
        if ($cold) wp_cache_flush();
        $before = count($wpdb->queries ?? array());
        ob_start();
        try { include get_template_directory() . '/footer.php'; $html = ob_get_contents(); }
        finally { ob_end_clean(); }
        preg_match('~<nav class="zen-footer-navigation"[^>]*>(.*?)</nav>~s', $html, $nav);
        preg_match_all('~href="([^"]+)"~', $nav[1] ?? '', $links);
        $reads = array_filter(array_slice($wpdb->queries ?? array(), $before), static function ($entry) use ($wpdb) {
            return str_contains($entry[0], "FROM $wpdb->posts") && str_contains($entry[0], "'_wp_page_template'");
        });
        return array($links[1], count($reads), $nav[1] ?? '');
    };
    try {
        foreach (get_pages(array('hierarchical'=>false)) as $existing) {
            $assigned = get_post_meta($existing->ID, '_wp_page_template', true);
            $slug_conflict = !$existing->post_parent && in_array($existing->post_name, array('archives', 'tags', 'links', 'about'), true);
            if (in_array($assigned, $templates, true)) {
                $saved[$existing->ID]['template'] = $assigned;
                delete_post_meta($existing->ID, '_wp_page_template');
            }
            if ($slug_conflict) {
                $saved[$existing->ID]['slug'] = $existing->post_name;
                wp_update_post(array('ID'=>$existing->ID, 'post_name'=>'zen-footer-fixture-existing-' . $existing->ID));
            }
        }
        $parent = zen_directory_post(array('post_type'=>'page', 'post_name'=>'zen-footer-parent'));
        $archive = zen_directory_post(array('post_type'=>'page', 'post_name'=>'custom-archive', 'post_parent'=>$parent, 'page_template'=>$templates[0], 'post_title'=>'A archive'));
        $runner_up = zen_directory_post(array('post_type'=>'page', 'post_name'=>'custom-archive-2', 'post_title'=>'Z archive'));
        $tags = zen_directory_post(array('post_type'=>'page', 'post_name'=>'tags'));
        $notes = zen_directory_post(array('post_type'=>'page', 'post_name'=>'custom-notes', 'page_template'=>$templates[2]));
        $about = zen_directory_post(array('post_type'=>'page', 'post_name'=>'about'));
        zen_directory_post(array('post_type'=>'page', 'post_name'=>'draft-notes', 'post_status'=>'draft', 'page_template'=>$templates[2], 'post_title'=>'A notes'));
        zen_directory_post(array('post_type'=>'page', 'post_name'=>'private-links', 'post_status'=>'private', 'page_template'=>$templates[3]));
        $rss = esc_url(get_bloginfo('rss2_url'));
        $expected = array(esc_url(home_url('/')), esc_url(get_permalink($archive)), esc_url(get_permalink($tags)), esc_url(get_permalink($notes)), esc_url(get_permalink($about)), $rss);
        list($links, $reads) = $render();
        zen_directory_check($links === $expected, 'footer: nested explicit page, default slug, published only, About, stable order and RSS last');
        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            echo 'FOOTER_COLD_TEMPLATE_READS ' . $reads . PHP_EOL;
            wp_cache_flush();
            zen_get_archives_url();
            $warm = $render(false);
            echo 'FOOTER_TEMPLATE_READS_AFTER_ARCHIVE_LOOKUP ' . $warm[1] . PHP_EOL;
            zen_directory_check($warm[0] === $expected && $warm[1] < $reads, 'Core already reuses the identical archive lookup while keeping footer links');
        }

        $default_archive = zen_directory_post(array('post_type'=>'page', 'post_name'=>'archives'));
        zen_directory_check($render()[0] === $expected, 'footer explicit assignment outranks a published default-slug page');
        update_post_meta($runner_up, '_wp_page_template', $templates[0]);
        zen_directory_check($render()[0] === $expected, 'multiple explicit candidates retain native title ordering');
        wp_update_post(array('ID'=>$runner_up, 'post_title'=>'A archive'));
        zen_directory_check($render()[0][1] === esc_url(zen_get_directory_url($templates[0])), 'equal titles retain the native single-template match');
        wp_update_post(array('ID'=>$runner_up, 'post_title'=>'Z archive'));
        $seen = array();
        $alternate = static function ($pages, $args) use (&$seen, $runner_up, $templates) {
            if ($args['meta_key'] !== '_wp_page_template') return $pages;
            $seen[] = $args['meta_value'];
            return $args['meta_value'] === $templates[0] ? array(get_post($runner_up)) : $pages;
        };
        add_filter('get_pages', $alternate, 10, 2);
        try {
            zen_directory_check($render()[0][1] === esc_url(get_permalink($runner_up)) && $seen === $templates, 'get_pages receives all original template arguments and can replace the match');
        } finally { remove_filter('get_pages', $alternate, 10); }
        // Polylang uses get_pages_query_args to scope these queries by language.
        $language = static function ($args, $parsed) use ($runner_up, $templates) {
            if ($parsed['meta_value'] === $templates[0]) $args['post__in'] = array($runner_up);
            return $args;
        };
        add_filter('get_pages_query_args', $language, 10, 2);
        try {
            zen_directory_check($render()[0][1] === esc_url(get_permalink($runner_up)), 'get_pages_query_args language-style filter keeps per-template selection');
        } finally { remove_filter('get_pages_query_args', $language, 10); }

        $item = wp_update_nav_menu_item($menu, 0, array('menu-item-title'=>'Custom feed', 'menu-item-url'=>get_bloginfo('rss2_url'), 'menu-item-type'=>'custom', 'menu-item-status'=>'publish'));
        if (is_wp_error($item) || !$item) throw new RuntimeException('Menu item fixture failed');
        set_theme_mod('nav_menu_locations', array('footer'=>$menu));
        list($links, $reads, $nav) = $render();
        zen_directory_check($links === array($rss) && str_contains($nav, 'Custom feed') && !str_contains($nav, 'ph-rss') && $reads === 0, 'assigned footer skips discovery and suppresses duplicate RSS');
    } finally {
        wp_delete_nav_menu($menu);
        set_theme_mod('nav_menu_locations', $locations);
        // Free the fixture slugs before restoring existing pages.
        foreach (array($tags ?? 0, $about ?? 0, $default_archive ?? 0) as $id) if ($id) wp_delete_post($id, true);
        foreach ($saved as $id=>$state) {
            if (isset($state['slug'])) wp_update_post(array('ID'=>$id, 'post_name'=>$state['slug']));
            if (isset($state['template'])) update_post_meta($id, '_wp_page_template', $state['template']);
        }
    }
}

function zen_directory_sparse_tags() {
    global $wpdb;
    $page = zen_directory_post(array('post_type'=>'page', 'post_content'=>'Intro one<!--nextpage-->Intro two'));
    $terms = array();
    foreach (array('Batch Dominant', 'Batch Shared', 'Batch Tie', 'Batch Rare') as $name) {
        $term = wp_insert_term($name, 'post_tag');
        if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
        $terms[] = (int) $term['term_id'];
        $GLOBALS['zen_directory_terms'][] = array($term['term_id'], 'post_tag');
    }
    list($dominant, $shared, $tie, $rare) = $terms;
    $make = static function ($name, $date, $tags, $status = 'publish') {
        $id = zen_directory_post(array('post_title'=>$name, 'post_date'=>$date . ' 12:00:00', 'post_status'=>$status));
        $result = wp_set_post_tags($id, $tags);
        if (is_wp_error($result) || count($result) !== count($tags)) throw new RuntimeException('Post tags fixture failed');
        return $id;
    };
    wp_defer_term_counting(true);
    try {
        $rare_post = $make('Rare first', '2020-01-01', array($rare));
        for ($i = 0; $i < 347; ++$i) $make('Dominant older ' . $i, '2022-01-01', array($dominant));
        $lower = $make('Equal-date lower ID', '2024-02-01', array($dominant, $shared, $tie));
        $higher = $make('Equal-date higher ID', '2024-02-01', array($dominant, $shared));
        $make('Draft should not win', '2025-01-01', array($rare, $shared), 'draft');
    } finally { wp_defer_term_counting(false); }
    $expected = array($dominant=>$higher, $shared=>$higher, $tie=>$lower, $rare=>$rare_post);
    $extract = static function ($html) {
        $dom = new DOMDocument();
        if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING)) throw new RuntimeException('Could not parse Tags');
        $xpath = new DOMXPath($dom);
        $rows = array();
        foreach ($xpath->query('//*[@id="zen-tags-entries"]/li') as $node) {
            $link = $xpath->query('.//h2/a', $node)->item(0);
            $preview = $xpath->query('.//div[@class="zen-tag-recent"]/a', $node)->item(0);
            $time = $xpath->query('.//time', $node)->item(0);
            $rows[$link->textContent] = array($link->getAttribute('href'), $preview ? $preview->getAttribute('href') : '', $time ? $time->getAttribute('datetime') : '');
        }
        return $rows;
    };
    wp_cache_flush();
    $before = count($wpdb->queries ?? array());
    $html = zen_directory_render('page-tags.php', $page);
    $batches = array();
    foreach (array_slice($wpdb->queries ?? array(), $before) as $entry) {
        if (str_contains($entry[0], 'term_relationships') && preg_match('/ORDER BY .*post_date DESC,.*ID DESC\s+LIMIT\s+(\d+),\s*(\d+)/s', $entry[0], $match)) {
            // Replay the logged native SELECT against unchanged fixtures to count rows without a selection hook.
            $batches[] = array((int) $match[1], (int) $match[2], count($wpdb->get_results($entry[0])));
        }
    }
    $ordered = array();
    foreach (get_tags(array('hide_empty'=>true, 'orderby'=>'count', 'order'=>'DESC')) as $term) {
        if (!isset($expected[$term->term_id])) continue;
        $id = $expected[$term->term_id];
        $ordered[$term->name] = array(get_tag_link($term), get_permalink($id), get_the_date('Y-m-d', $id));
    }
    $rows = $extract($html);
    zen_directory_check(array_intersect_key($rows, $ordered) === $ordered && count($ordered) === 4 && str_contains($html, 'datetime="2024-02-01"') && str_contains($html, 'class="zen-page-links"'), '350 posts: exact tag order, preview URLs, equal-date tiebreak, dates and content pagination');
    if (defined('SAVEQUERIES') && SAVEQUERIES) echo 'TAGS_COLD_BATCH_SQL ' . json_encode($batches) . PHP_EOL;

    $seen = array();
    $language = static function ($query) use (&$seen, $higher) {
        if (($query->get('tax_query')[0]['taxonomy'] ?? '') !== 'post_tag') return;
        $seen[] = (int) $query->get('posts_per_page');
        $query->set('post__not_in', array($higher));
    };
    add_action('pre_get_posts', $language);
    try { $filtered = $extract(zen_directory_render('page-tags.php', $page)); }
    finally { remove_action('pre_get_posts', $language); }
    zen_directory_check(($filtered['Batch Dominant'][1] ?? '') === get_permalink($lower) && $seen === array(100, 100), 'plugin-filtered tags retain original batch arguments and honor query selection');

    for ($i = 0; $i < 17; ++$i) {
        $term = wp_insert_term('Batch extra ' . $i, 'post_tag');
        if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
        $GLOBALS['zen_directory_terms'][] = array($term['term_id'], 'post_tag');
        wp_set_post_tags($higher, array((int) $term['term_id']), true);
    }
    $html = zen_directory_render('page-tags.php', $page);
    zen_directory_check(count(array_filter(array_keys($extract($html)), static function ($name) { return str_starts_with($name, 'Batch '); })) === 21 && str_contains($html, 'hidden aria-controls="zen-tags-entries" data-zen-directory-batch="20"'), 'all 21 fixture tags appear in unenhanced HTML with the enhancement control hidden');
}

function zen_directory_equal_dates() {
    $page = zen_directory_post(array('post_type'=>'page'));
    $ids = array();
    for ($n = 0; $n < 501; $n++) {
        $ids[] = zen_directory_post(array('post_date'=>'2023-03-22 04:05:06', 'post_date_gmt'=>'2023-03-22 04:05:06'));
    }
    $cold = zen_directory_render('page-archives.php', $page);
    $context = get_locale() . ':' . (function_exists('pll_current_language') ? pll_current_language() : '');
    $rows = get_transient('zen_archives_public_posts_v3')[$context];
    $actual = array_values(array_intersect(array_column($rows, 'id'), $ids));
    zen_directory_check($actual === array_reverse($ids), 'equal-date posts cross a native batch boundary without duplicates or omissions');
    zen_directory_check($cold === zen_directory_render('page-archives.php', $page), 'equal-date archive has identical cold and warm HTML');
}
function zen_directory_secondary() {
    $page_id = zen_directory_post(array('post_type'=>'page', 'post_title'=>'示例主题目录', 'post_content'=>'ZEN_TOPICS_INTRO', 'page_template'=>'page-tags.php'));
    zen_directory_check(get_page_template_slug($page_id) === 'page-tags.php', 'tag directory is assignable as a native page template');
    $tag_ids = array();
    foreach (array('Zen Active Topic', 'Zen Empty Topic') as $name) {
        $term = wp_insert_term($name, 'post_tag');
        if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
        $tag_ids[] = $term['term_id'];
        $GLOBALS['zen_directory_terms'][] = array($term['term_id'], 'post_tag');
    }
    foreach (array('2022-03-01' => 'ZEN_TOPICS_OLDER', '2023-03-01' => 'ZEN_TOPICS_RECENT', '2024-03-01' => 'ZEN_TOPICS_DRAFT') as $date => $title) {
        $post_id = zen_directory_post(array('post_title'=>$title, 'post_status'=>$title === 'ZEN_TOPICS_DRAFT' ? 'draft' : 'publish', 'post_date'=>$date . ' 12:00:00'));
        wp_set_post_tags($post_id, array($tag_ids[0]));
    }
    $html = zen_directory_render('page-tags.php', $page_id);
    zen_directory_check(str_contains($html, '示例主题目录') && str_contains($html, 'ZEN_TOPICS_INTRO'), 'tag directory uses saved page title and content');
    zen_directory_check(str_contains($html, 'href="' . esc_url(get_tag_link($tag_ids[0])) . '"') && !str_contains($html, 'Zen Empty Topic'), 'tag directory includes native nonempty term links only');
    zen_directory_check(str_contains($html, 'ZEN_TOPICS_RECENT') && !str_contains($html, 'ZEN_TOPICS_OLDER') && !str_contains($html, 'ZEN_TOPICS_DRAFT') && str_contains($html, '2 篇'), 'tag directory shows native count and newest published article');

    wp_set_post_tags(zen_directory_post(array('post_title'=>'ZEN_TOPICS_RARE', 'post_date'=>'2011-03-01 12:00:00')), array($tag_ids[1]));
    // An old rare tag should not force a full scan through the popular tag's posts.
    wp_defer_term_counting(true);
    try {
        for ($n = 0; $n < 101; $n++) {
            $recent = zen_directory_post(array('post_title'=>'ZEN_TOPICS_POPULAR_' . $n, 'post_date'=>'2025-03-01 12:00:00'));
            wp_set_post_tags($recent, array($tag_ids[0]));
        }
    } finally { wp_defer_term_counting(false); }
    $batches = array();
    $batch_probe = static function ($posts, $query) use (&$batches) {
        if ((int) $query->get('posts_per_page') === 100 && ($query->get('tax_query')[0]['taxonomy'] ?? '') === 'post_tag') {
            $batches[] = array_map('intval', $query->get('tax_query')[0]['terms']);
        }
        return $posts;
    };
    add_filter('the_posts', $batch_probe, 10, 2);
    try { $html = zen_directory_render('page-tags.php', $page_id); }
    finally { remove_filter('the_posts', $batch_probe, 10); }
    zen_directory_check(str_contains($html, 'ZEN_TOPICS_RARE') && str_contains($html, 'ZEN_TOPICS_POPULAR_100'), 'rare and popular tags both link to their newest post across batches');
    zen_directory_check(in_array($tag_ids[0], $batches[0] ?? array(), true) && in_array($tag_ids[1], $batches[1] ?? array(), true) && !in_array($tag_ids[0], $batches[1] ?? array(), true), 'later batches exclude the resolved popular tag');

    wp_update_post(array('ID'=>$page_id, 'post_password'=>'directory-secret'));
    $term_reads = 0;
    $term_probe = static function ($terms, $taxonomies) use (&$term_reads) {
        if (in_array('post_tag', $taxonomies, true)) $term_reads++;
        return $terms;
    };
    add_filter('get_terms', $term_probe, 10, 2);
    try {
        $html = zen_directory_render('page-tags.php', $page_id);
        zen_directory_check($term_reads === 0 && substr_count($html, 'class="post-password-form"') === 1 && !str_contains($html, 'Zen Active Topic') && !str_contains($html, 'ZEN_TOPICS_INTRO'), 'locked tag directory offers native password form without reading tags or exposing content');
    } finally { remove_filter('get_terms', $term_probe, 10); }

    foreach (array('', 'ZEN_TOPICS_RECENT') as $query) {
        wp(array('s'=>$query));
        zen_directory_check(is_search() && have_posts(), 'native search query is populated for ' . ($query === '' ? 'empty search' : $query));
        ob_start();
        try { include get_template_directory() . '/search.php'; $html = ob_get_contents(); }
        finally { ob_end_clean(); wp_reset_query(); }
        zen_directory_check((bool) preg_match('/<form[^>]+class="search-form"/', $html) && str_contains($html, 'name="s"'), 'search keeps Core GET form');
        if ($query === '') {
            zen_directory_check(!str_contains($html, 'zen-post-list') && !str_contains($html, 'zen-search-count'), 'blank native search hides existing Loop posts and statistics');
        } else {
            zen_directory_check(str_contains($html, 'zen-search-count') && (bool) preg_match('/<mark[^>]*>ZEN_TOPICS_RECENT<\/mark>/', $html), 'nonblank native search retains results and title highlighting');
        }
    }
}
function zen_directory_guestbook() {
    $page_id = zen_directory_post(array('post_type'=>'page', 'page_template'=>'page-notes.php', 'comment_status'=>'open'));
    zen_directory_option('page_comments', 1);
    zen_directory_option('comments_per_page', 4);
    zen_directory_option('thread_comments_depth', 2);
    $insert = static function ($label, $day, $parent = 0) use ($page_id) {
        $date = sprintf('2024-03-%02d 12:00:00', $day);
        $comment_id = wp_insert_comment(array('comment_post_ID'=>$page_id, 'comment_content'=>$label, 'comment_author'=>'Fixture', 'comment_approved'=>1, 'comment_parent'=>$parent, 'comment_date'=>$date, 'comment_date_gmt'=>$date));
        if (!$comment_id) throw new RuntimeException('Comment fixture failed');
        $GLOBALS['zen_directory_comments'][] = $comment_id;
        return $comment_id;
    };
    $roots = array();
    for ($day = 1; $day <= 8; $day++) $roots[] = $insert('Root ' . $day, $day);
    $reply = $insert('Recent reply to oldest thread', 9, $roots[0]);
    $grandchild = $insert('Deeper historical reply', 10, $reply);
    $before = has_filter('pre_option_page_comments');
    foreach (array(1, 0) as $threading) {
        zen_directory_option('thread_comments', $threading);
        $html = zen_directory_render('page-notes.php', $page_id);
        $dom = new DOMDocument();
        if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING)) throw new RuntimeException('Could not parse Notes');
        $xpath = new DOMXPath($dom);
        $visible_roots = array();
        foreach ($xpath->query('//*[@id="zen-notes-entries"]/li') as $node) $visible_roots[] = $node->getAttribute('id');
        $expected = array_map(static function ($id) { return 'comment-' . $id; }, array_reverse($roots));
        zen_directory_check($visible_roots === $expected, "guestbook threading=$threading: only newest-first top-level comments are counted");
        foreach (array($reply, $grandchild) as $child) {
            zen_directory_check($xpath->query('//*[@id="comment-' . $roots[0] . '"]//*[@id="comment-' . $child . '"]')->length === 1, "guestbook threading=$threading: historical reply stays under its original thread");
        }
        zen_directory_check(str_contains($html, 'class="comment-reply-link"') === (bool) $threading, "guestbook threading=$threading: reply controls respect Discussion settings");
        zen_directory_check(!str_contains($html, 'class="comment-pagination') && (int) get_option('page_comments') === 1 && has_filter('pre_option_page_comments') === $before, "guestbook threading=$threading: pagination is only suppressed during Notes render");
    }
    $stop = new RuntimeException('fixture interrupted comments query');
    $interrupt = static function () use ($stop) { throw $stop; };
    add_filter('comments_template_query_args', $interrupt);
    $caught = false;
    try {
        zen_directory_render('page-notes.php', $page_id);
    } catch (RuntimeException $error) {
        if ($error !== $stop) throw $error;
        $caught = true;
    } finally {
        remove_filter('comments_template_query_args', $interrupt);
    }
    zen_directory_check($caught && (int) get_option('page_comments') === 1 && has_filter('pre_option_page_comments') === $before, 'guestbook restores the pagination option after interrupted rendering');
}
try {
    wp_set_current_user(0);
    if (in_array($zen_directory_slice, array('all', 'guestbook'), true)) zen_directory_guestbook();
    if (in_array($zen_directory_slice, array('all', 'discovery'), true)) zen_directory_discovery();
    if (in_array($zen_directory_slice, array('all', 'password'), true)) zen_directory_password();
    if (in_array($zen_directory_slice, array('all', 'author'), true)) zen_directory_author();
    if (in_array($zen_directory_slice, array('all', 'lifecycle'), true)) {
        zen_directory_nested_link();
        zen_directory_lifecycle();
        zen_directory_equal_dates();
    }
    // Keep the 350-post query fixture opt-in; the ordinary suite covers directories without it.
    if ($zen_directory_slice === 'queries') {
        zen_directory_footer();
        zen_directory_sparse_tags();
    }
    if (in_array($zen_directory_slice, array('all', 'secondary'), true)) zen_directory_secondary();
} finally {
    wp_reset_query();
    foreach (array_reverse($zen_directory_comments) as $comment_id) wp_delete_comment($comment_id, true);
    foreach (array_reverse($zen_directory_posts) as $post_id) wp_delete_post($post_id, true);
    foreach ($zen_directory_users as $user_id) if (get_user_by('id', $user_id)) wp_delete_user($user_id);
    foreach ($zen_directory_links as $link_id) wp_delete_link($link_id);
    foreach ($zen_directory_terms as list($term_id, $taxonomy)) wp_delete_term($term_id, $taxonomy);
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
