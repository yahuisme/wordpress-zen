<?php
/**
 * Focused native PHP regression; never runs against production.
 * ZEN_TEST_ISOLATED=1 php tests/php-native-regression.php /isolated/wp-load.php comments
 * Slices: comments, search, links, category-cache (omit for all).
 * The caller owns a disposable WP installation with outbound HTTP/mail blocked.
 */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) {
    fwrite(STDERR, "Set ZEN_TEST_ISOLATED=1 and pass isolated wp-load.php.\n");
    exit(1);
}
$zen_php_complete = false;
register_shutdown_function(function () use (&$zen_php_complete) {
    if (!$zen_php_complete) {
        fwrite(STDERR, "PHP native regression did not complete.\n");
        exit(1);
    }
});
$zen_php_slice = $argv[2] ?? 'all';
if (!in_array($zen_php_slice, array('all', 'comments', 'search', 'links', 'category-cache'), true)) {
    throw new InvalidArgumentException('Unknown PHP regression slice: ' . $zen_php_slice);
}
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? '127.0.0.1';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require $argv[1];
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        fwrite(STDERR, "PHP runtime error: $message at $file:$line\n");
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Isolated loopback WordPress required');
}
$zen_php_total = 0;
$zen_php_failed = 0;
$zen_php_posts = array();
function zen_php_check($condition, $label) {
    global $zen_php_total, $zen_php_failed;
    $zen_php_total++;
    if (!$condition) $zen_php_failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function zen_php_post($args = array()) {
    global $zen_php_posts;
    $id = wp_insert_post(array_merge(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Zen PHP fixture', 'comment_status' => 'open'), $args), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $zen_php_posts[] = $id;
    return $id;
}
function zen_php_capture($callback) {
    ob_start();
    try { $callback(); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
function zen_php_comments() {
    $saved_cookies = $_COOKIE;
    $saved_get = $_GET;
    $saved_user = get_current_user_id();
    $saved_post = $GLOBALS['post'] ?? null;
    $saved_options = array();
    foreach (array('thread_comments', 'thread_comments_depth', 'comment_registration') as $key) {
        $saved_options[$key] = get_option($key, null);
    }
    update_option('thread_comments', 1);
    update_option('thread_comments_depth', 5);
    update_option('comment_registration', 0);
    $post_id = zen_php_post();
    $GLOBALS['post'] = get_post($post_id);
    $comment_id = wp_insert_comment(array(
        'comment_post_ID' => $post_id, 'comment_author' => 'Fixture author',
        'comment_author_email' => 'author@example.test', 'comment_type' => 'comment',
        'comment_content' => wp_kses('<p>Pending <a href="https://example.test/pending/">HTTPS link</a> <strong>formatting</strong></p>', 'pre_comment'),
        'comment_approved' => '0', 'comment_date' => current_time('mysql'), 'comment_date_gmt' => current_time('mysql', true),
    ));
    $args = array('style' => 'ol', 'max_depth' => 5, 'avatar_size' => 0, 'format' => 'html5', 'short_ping' => true, 'echo' => false);
    try {
        wp_set_current_user(0);
        $_COOKIE = array();
        $comment = get_comment($comment_id);
        $_GET = array('unapproved' => (string) $comment_id, 'moderation-hash' => wp_hash($comment->comment_date_gmt));
        zen_php_check(wp_get_unapproved_comment_author_email() === 'author@example.test', 'comments: core accepts valid moderation-hash preview');
        // Author cookies, not login capability, control pending links in core.
        $admin = get_users(array('role' => 'administrator', 'number' => 1));
        if (!$admin) throw new RuntimeException('Fixture administrator required');
        foreach (array('preview' => array('0', false, 0), 'author-cookie' => array('0', true, 0), 'administrator' => array('0', false, $admin[0]->ID), 'approved' => array('1', false, 0)) as $name => $case) {
            wp_set_current_user($case[2]);
            $_COOKIE = $case[1] ? array('comment_author_' . COOKIEHASH => 'Fixture author', 'comment_author_email_' . COOKIEHASH => 'author@example.test') : array();
            wp_update_comment(array('comment_ID' => $comment_id, 'comment_approved' => $case[0]));
            $comment = get_comment($comment_id);
            $core = wp_list_comments($args, array($comment));
            $theme = wp_list_comments($args + array('callback' => 'zen_comment_callback'), array($comment));
            $show_links = $case[0] === '1' || $case[1];
            foreach (array('HTTPS link' => 'href="https://example.test/pending/"', 'reply' => 'comment-reply-link', 'formatting' => '<strong>formatting</strong>') as $behavior => $needle) {
                zen_php_check(str_contains($theme, $needle) === $show_links && str_contains($theme, $needle) === str_contains($core, $needle), "comments: $name $behavior matches native HTML5 walker");
            }
            zen_php_check(str_contains($theme, '<article id="div-comment-') && str_contains($theme, 'zen-comment-card'), "comments: $name retains HTML5 theme card");
        }
        wp_set_current_user(0);
        $_COOKIE = array();
        wp_update_comment(array('comment_ID' => $comment_id, 'comment_approved' => '0'));
        $late_link = function ($text) { return $text . '<a href="https://example.test/filter/">Plugin link</a>'; };
        add_filter('comment_text', $late_link, 30);
        try {
            $theme = wp_list_comments($args + array('callback' => 'zen_comment_callback'), array(get_comment($comment_id)));
            zen_php_check(!str_contains($theme, 'href="https://example.test/filter/"') && str_contains($theme, 'Plugin link'), 'comments: core priority-40 filter strips plugin links without losing text');
            $outside = zen_php_capture(function () use ($comment_id) { comment_text($comment_id); });
            zen_php_check(str_contains($outside, 'href="https://example.test/filter/"'), 'comments: callback removes its temporary filter');
        } finally { remove_filter('comment_text', $late_link, 30); }
        wp_update_comment(array('comment_ID' => $comment_id, 'comment_approved' => '1'));
        $comment = get_comment($comment_id);
        $shallow = array_merge($args, array('max_depth' => 1));
        $theme = wp_list_comments($shallow + array('callback' => 'zen_comment_callback'), array($comment));
        zen_php_check(!str_contains($theme, 'comment-reply-link'), 'comments: native maximum-depth reply suppression remains');
        update_option('comment_registration', 1);
        $theme = wp_list_comments($args + array('callback' => 'zen_comment_callback'), array($comment));
        $core = wp_list_comments($args, array($comment));
        zen_php_check(str_contains($theme, 'comment-reply-login') && str_contains($core, 'comment-reply-login'), 'comments: approved comment retains native login-required reply');
        $throw_filter = function () { throw new RuntimeException('Intentional comment filter boundary'); };
        add_filter('comment_text', $throw_filter, 35);
        try {
            zen_php_capture(function () use ($comment, $args) { zen_comment_callback($comment, $args, 1); });
            zen_php_check(false, 'comments: filter boundary must throw');
        } catch (RuntimeException $error) {
            zen_php_check($error->getMessage() === 'Intentional comment filter boundary', 'comments: filter boundary propagates original exception');
        } finally { remove_filter('comment_text', $throw_filter, 35); }
        wp_update_comment(array('comment_ID' => $comment_id, 'comment_approved' => '0'));
        $outside = zen_php_capture(function () use ($comment_id) { comment_text($comment_id); });
        zen_php_check(str_contains($outside, 'href="https://example.test/pending/"'), 'comments: temporary core filter is removed even when a plugin throws');
    } finally {
        wp_delete_comment($comment_id, true);
        foreach ($saved_options as $key => $value) {
            if (null === $value) delete_option($key); else update_option($key, $value);
        }
        $_COOKIE = $saved_cookies;
        $_GET = $saved_get;
        wp_set_current_user($saved_user);
        $GLOBALS['post'] = $saved_post;
    }
}
function zen_php_search() {
    $id = zen_php_post(array('post_title' => 'hello & world'));
    $titles = array(get_the_title($id), apply_filters('the_title', 'hello & world', $id));
    zen_php_check(str_contains($titles[1], '&#038;'), 'search: native title filter fixture contains a core-generated numeric entity');
    foreach ($titles as $title) {
        foreach (array('&' => 1, '038' => 0, '38' => 0, 'world' => 1) as $query => $marks) {
            $html = zen_highlight_search_terms($title, (string) $query);
            zen_php_check(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') === 'hello & world', "search: query $query preserves native visible title ($title)");
            zen_php_check(substr_count($html, '<mark ') === $marks, "search: query $query highlights visible text only ($title)");
        }
    }
    $html = zen_highlight_search_terms('&lt;script&gt; &amp;lt; &quot;title&quot;', '<script> &lt; title');
    zen_php_check(!str_contains($html, '<script>') && html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') === '<script> &lt; "title"', 'search: encoded markup stays text and doubly encoded entities retain their visible value');
}
function zen_php_links() {
    require_once ABSPATH . 'wp-admin/includes/bookmark.php';
    $page = zen_php_post(array('post_type' => 'page', 'post_title' => 'Links fixture'));
    $ids = array();
    $cases = array('me friend nofollow', "Me\tfriend  NOFOLLOW noopener nofollow", '', 'external sponsored ugc opener bad"token');
    try {
        foreach ($cases as $index => $rel) {
            $id = wp_insert_link(array('link_name' => 'Zen rel fixture ' . $index, 'link_url' => 'https://example.test/friend/' . $index . '/', 'link_rel' => $rel, 'link_visible' => 'Y', 'link_target' => '_blank'));
            if (is_wp_error($id) || !$id) throw new RuntimeException('Bookmark fixture failed');
            $ids[] = $id;
        }
        query_posts(array('page_id' => $page));
        $html = zen_php_capture(function () { include get_template_directory() . '/page-links.php'; });
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($doc);
        foreach ($ids as $index => $id) {
            $nodes = $xpath->query('//a[@href="https://example.test/friend/' . $index . '/"]');
            zen_php_check($nodes->length === 1, "links: bookmark $index is rendered once");
            if ($nodes->length !== 1) continue;
            $anchor = $nodes->item(0);
            $tokens = preg_split('/\s+/', $anchor->getAttribute('rel'), -1, PREG_SPLIT_NO_EMPTY);
            $expected = $index < 2 ? array('me', 'friend', 'nofollow', 'noopener', 'noreferrer') : ($index === 2 ? array('noopener', 'noreferrer') : array('external', 'sponsored', 'ugc', 'noopener', 'noreferrer'));
            sort($tokens);
            sort($expected);
            zen_php_check($tokens === $expected, "links: bookmark $index merges saved relations with unique safety tokens");
            zen_php_check($anchor->getAttribute('target') === '_blank' && $anchor->hasAttribute('aria-label'), "links: bookmark $index retains target and new-window label");
            zen_php_check(!$anchor->hasAttribute('onclick') && !$anchor->hasAttribute('onmouseover'), "links: bookmark $index has no injected attributes");
        }
    } finally {
        wp_reset_query();
        foreach ($ids as $id) wp_delete_link($id);
    }
}
function zen_php_category_cache() {
    $saved_structure = get_option('permalink_structure', null);
    $terms = array();
    $post_id = zen_php_post(array('post_name' => 'zen-cache-fixture'));
    $page = zen_php_post(array('post_type' => 'page'));
    $render = function () use ($page) {
        query_posts(array('page_id' => $page));
        try { return zen_php_capture(function () { include get_template_directory() . '/page-archives.php'; }); }
        finally { wp_reset_query(); }
    };
    $cached_url = function () use ($post_id) {
        foreach ((array) get_transient('zen_archives_public_posts') as $row) {
            if (isset($row['id']) && (int) $row['id'] === $post_id) return $row['url'];
        }
        return '';
    };
    try {
        update_option('permalink_structure', '/%category%/%postname%/');
        foreach (array('Zen cache old', 'Zen cache new') as $name) {
            $term = wp_insert_term($name, 'category');
            if (is_wp_error($term)) throw new RuntimeException($term->get_error_message());
            $terms[] = $term['term_id'];
        }
        wp_set_post_categories($post_id, array($terms[0]));
        $render();
        $old = get_permalink($post_id);
        zen_php_check($cached_url() === $old, 'category-cache: native archive warms with old category URL');
        $saved_modified = get_post($post_id)->post_modified_gmt;
        wp_set_post_categories($post_id, array($terms[1]));
        $new = get_permalink($post_id);
        zen_php_check($old !== $new && get_post($post_id)->post_modified_gmt === $saved_modified, 'category-cache: relation-only replacement changes permalink without saving post');
        zen_php_check(false === get_transient('zen_archives_public_posts'), 'category-cache: replacing category evicts warm archive');
        $html = $render();
        zen_php_check($cached_url() === $new && str_contains($html, esc_url($new)), 'category-cache: archive regenerates current category URL');
        wp_add_object_terms($post_id, $terms[0], 'category');
        zen_php_check(false === get_transient('zen_archives_public_posts'), 'category-cache: appending category evicts warm archive');
        $render();
        wp_remove_object_terms($post_id, $terms[0], 'category');
        zen_php_check(false === get_transient('zen_archives_public_posts'), 'category-cache: direct relationship removal evicts warm archive');
        $render();
        zen_php_check($cached_url() === get_permalink($post_id), 'category-cache: removal regeneration matches native permalink');
        wp_set_object_terms($post_id, array(), 'category');
        zen_php_check(false === get_transient('zen_archives_public_posts'), 'category-cache: removing all categories evicts warm archive');
        $render();
        zen_php_check($cached_url() === get_permalink($post_id), 'category-cache: uncategorized archive regenerates native fallback URL');
        $before = get_transient('zen_archives_public_posts');
        wp_set_object_terms($post_id, array(), 'category');
        zen_php_check(get_transient('zen_archives_public_posts') === $before, 'category-cache: unchanged relationships keep populated cache');
        $tag = wp_insert_term('Zen cache tag', 'post_tag');
        if (is_wp_error($tag)) throw new RuntimeException($tag->get_error_message());
        try {
            wp_set_object_terms($post_id, array($tag['term_id']), 'post_tag');
            zen_php_check(get_transient('zen_archives_public_posts') === $before, 'category-cache: tag relationship does not evict archive');
        } finally { wp_delete_term($tag['term_id'], 'post_tag'); }
        register_taxonomy_for_object_type('category', 'page');
        try {
            wp_set_object_terms($page, array($terms[0]), 'category');
            zen_php_check(get_transient('zen_archives_public_posts') === $before, 'category-cache: non-post relationship does not evict archive');
        } finally {
            wp_set_object_terms($page, array(), 'category');
            unregister_taxonomy_for_object_type('category', 'page');
        }
        update_option('permalink_structure', '/%postname%/');
        $render();
        $before = get_transient('zen_archives_public_posts');
        wp_set_post_categories($post_id, array($terms[0]));
        zen_php_check(get_transient('zen_archives_public_posts') === $before, 'category-cache: category-independent permalinks keep populated cache');
    } finally {
        if (null === $saved_structure) delete_option('permalink_structure'); else update_option('permalink_structure', $saved_structure);
        foreach ($terms as $term) wp_delete_term($term, 'category');
        delete_transient('zen_archives_public_posts');
    }
}
try {
    if (in_array($zen_php_slice, array('all', 'comments'), true)) zen_php_comments();
    if (in_array($zen_php_slice, array('all', 'search'), true)) zen_php_search();
    if (in_array($zen_php_slice, array('all', 'links'), true)) zen_php_links();
    if (in_array($zen_php_slice, array('all', 'category-cache'), true)) zen_php_category_cache();
} finally {
    foreach (array_reverse($zen_php_posts) as $id) wp_delete_post($id, true);
    wp_reset_query();
}
$zen_php_complete = true;
printf("PHP_NATIVE_COMPLETE %d checks, %d failures\n", $zen_php_total, $zen_php_failed);
exit($zen_php_failed ? 1 : 0);
