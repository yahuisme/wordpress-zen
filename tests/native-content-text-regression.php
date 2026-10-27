<?php
/** Run: ZEN_TEST_ISOLATED=1 php tests/native-content-text-regression.php /isolated/wp-load.php */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) {
    fwrite(STDERR, "Set ZEN_TEST_ISOLATED=1 and pass an isolated wp-load.php path.\n");
    exit(1);
}
$content_native_complete = false;
register_shutdown_function(static function () use (&$content_native_complete) {
    if (!$content_native_complete) {
        fwrite(STDERR, "Native content regression did not complete.\n");
        exit(1);
    }
});
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Isolated loopback WordPress required');
}
foreach (array('template-tags.php', 'schema.php', 'enqueue.php') as $file) {
    if (hash_file('sha256', get_template_directory() . '/inc/' . $file) !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/inc/' . $file)) {
        throw new RuntimeException('Fixture source mismatch: ' . $file);
    }
}
$total = $failures = 0;
$ids = array();
$saved_options = array();
$saved_cookie = $_COOKIE['wp-postpass_' . COOKIEHASH] ?? null;
function content_native_check($condition, $label) {
    global $total, $failures;
    $total++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failures++;
}
function content_native_post($overrides = array()) {
    global $ids;
    $id = wp_insert_post(array_merge(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Native content fixture', 'post_content' => '<p>Body</p>'), $overrides), true);
    if (is_wp_error($id) || !$id) throw new RuntimeException('Post fixture insertion failed');
    $ids[] = $id;
    return $id;
}
function content_native_query($id) {
    $GLOBALS['wp_query'] = new WP_Query(array('p' => $id));
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post($id);
    setup_postdata($GLOBALS['post']);
    if (!is_singular('post')) throw new RuntimeException('Native singular query fixture failed');
}
function content_native_excerpt() {
    ob_start();
    get_template_part('template-parts/content', 'excerpt');
    $html = ob_get_clean();
    preg_match('~<div class="zen-post-excerpt[^>]*>(.*?)</div>~s', $html, $match);
    if (!isset($match[1])) throw new RuntimeException('Missing list excerpt template');
    return trim($match[1]);
}
function content_native_schema() {
    ob_start();
    do_action('wp_head');
    $html = ob_get_clean();
    preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match);
    if (!isset($match[1])) throw new RuntimeException('Missing native head JSON-LD');
    return array(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR), $match[1]);
}
function content_native_assets($expected, $label) {
    $GLOBALS['wp_scripts'] = new WP_Scripts();
    $GLOBALS['wp_styles'] = new WP_Styles();
    do_action('wp_enqueue_scripts');
    content_native_check(wp_script_is('highlight-js', 'enqueued') === $expected && wp_style_is('highlight-css', 'enqueued') === $expected, $label . ': native enqueue visibility');
    content_native_check(wp_scripts()->registered['zen-main']->deps === ($expected ? array('highlight-js') : array()), $label . ': main dependency');
}
try {
    foreach (array('zen_list_style' => 'standard', 'zen_excerpt_length' => 8, 'zen_show_highlight' => 1) as $key => $value) {
        $saved_options[$key] = get_option($key, null);
        update_option($key, $value);
        if (get_option($key) != $value) throw new RuntimeException('Option fixture failed: ' . $key);
    }
    foreach (array(
        array('<strong>中文摘要</strong>，手工填写。', '中文摘要，手工填…'),
        array('<strong>English excerpt</strong> manually entered.', 'English excerpt manually entered.'),
        array('&lt;img src=x onerror=alert(1)&gt; 中文', '&lt;img …'),
    ) as $case) {
        $id = content_native_post(array('post_excerpt' => $case[0]));
        content_native_query($id);
        content_native_check(get_post($id)->post_excerpt === $case[0], 'manual excerpt fixture retains stored HTML/entities');
        content_native_check(content_native_excerpt() === $case[1], 'native get_the_excerpt to list template: ' . $case[0] . ' => ' . content_native_excerpt());
    }
    $id = content_native_post(array('post_title' => 'A & B "quote"'));
    content_native_query($id);
    $title = get_the_title();
    list($schema) = content_native_schema();
    content_native_check(str_contains($title, '&#038;') || str_contains($title, '&amp;'), 'native title filter supplies HTML entities');
    content_native_check($schema['headline'] === html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'native title becomes plain JSON-LD headline');
    content_native_check(get_the_title() === $title, 'JSON boundary cleanup does not alter public title filters');
    $title_filter = static fn() => '<b>中文 &amp; title</b> &lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt; &quot;double&quot; &#039;single&#039;';
    add_filter('the_title', $title_filter, 99);
    try {
        list($schema, $json) = content_native_schema();
        content_native_check($schema['headline'] === '中文 & title </script><script>alert(1)</script> "double" \'single\'', 'filtered title HTML stripped and one entity layer decoded');
        content_native_check(!str_contains($json, '</script>') && str_contains($json, '\\u003C') && str_contains($json, '\\u0026') && str_contains($json, '\\u0022') && str_contains($json, '\\u0027'), 'native JSON serializer retains all script-safe HEX boundaries');
        content_native_check(str_contains(get_the_title(), '<b>'), 'title HTML cleanup is not installed as a global filter');
    } finally {
        remove_filter('the_title', $title_filter, 99);
    }
    $code = '<!-- wp:code --><pre class="wp-block-code"><code>SECRET_NATIVE_CODE</code></pre><!-- /wp:code -->';
    $id = content_native_post(array('post_password' => 'fixture-pass', 'post_content' => $code));
    content_native_query($id);
    unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    content_native_check(post_password_required() && !str_contains(get_the_content(), 'SECRET_NATIVE_CODE'), 'locked content is hidden by native password protection');
    content_native_check(!zen_has_code_blocks(), 'locked article detector short circuits');
    content_native_assets(false, 'locked article');
    require_once ABSPATH . WPINC . '/class-phpass.php';
    $hasher = new PasswordHash(8, true);
    $_COOKIE['wp-postpass_' . COOKIEHASH] = $hasher->HashPassword('fixture-pass');
    content_native_check(!post_password_required() && str_contains(get_the_content(), 'SECRET_NATIVE_CODE'), 'actual WordPress password cookie unlocks content without deleting password');
    content_native_check(get_post($id)->post_password === 'fixture-pass' && zen_has_code_blocks(), 'unlocked password article still detects code');
    content_native_assets(true, 'cookie-unlocked article');
    unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    $pattern = content_native_post(array('post_type' => 'wp_block', 'post_content' => $code));
    foreach (array('classic' => '<pre>public code</pre>', 'core block' => $code, 'synced block' => '<!-- wp:block {"ref":' . $pattern . '} /-->') as $label => $body) {
        $id = content_native_post(array('post_content' => $body));
        content_native_query($id);
        content_native_check(zen_has_code_blocks(), 'public ' . $label . ': native code detection');
        content_native_assets(true, 'public ' . $label);
    }
    update_option('zen_show_highlight', 0);
    content_native_assets(false, 'disabled highlighting');
} finally {
    foreach (array_reverse($ids) as $id) wp_delete_post($id, true);
    foreach ($saved_options as $key => $value) {
        if (null === $value) delete_option($key);
        else update_option($key, $value);
    }
    if (null === $saved_cookie) unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    else $_COOKIE['wp-postpass_' . COOKIEHASH] = $saved_cookie;
    wp_reset_postdata();
}
$content_native_complete = true;
printf("NATIVE_CONTENT_COMPLETE %d checks, %d failures; WordPress %s; PHP %s\n", $total, $failures, $GLOBALS['wp_version'], PHP_VERSION);
exit($failures ? 1 : 0);
