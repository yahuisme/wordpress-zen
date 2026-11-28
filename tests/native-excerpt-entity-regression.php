<?php
/** Native manual and automatic excerpts; no browser/network or asset checks. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$complete = false;
register_shutdown_function(function () use (&$complete) { if (!$complete) { fwrite(STDERR, "NATIVE_EXCERPT_INCOMPLETE\n"); exit(1); } });
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') throw new RuntimeException('Isolated loopback Zen required');
foreach (array('inc/template-tags.php', 'template-parts/content-excerpt.php') as $file) {
    if (hash_file('sha256', get_template_directory() . '/' . $file) !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/' . $file)) throw new RuntimeException('Source mismatch: ' . $file);
}
$checks = $failures = 0; $ids = $old = array();
$old_user = get_current_user_id();
$old_locale = $GLOBALS['wp_locale'];
try {
    foreach (array('zen_list_style'=>'standard', 'zen_excerpt_length'=>6) as $key=>$value) {
        $old[$key] = get_option($key, null); update_option($key, $value);
    }
    wp_set_current_user(0);
    foreach (array(
        array('中文 & example', 6, '中文 & e…'),
        array('中文 &amp; example', 6, '中文 & e…'),
        array('&#20013;&#x6587;&#20013;&#x6587;', 2, '中文…'),
        array('中文 &amp;amp; literal', 8, '中文 &amp;…'),
        array('English &amp;lt;tag&amp;gt; literal', 60, 'English &lt;tag&gt; literal'),
        array('English &lt;code&gt;literal&lt;/code&gt; words here', 28, 'English <code>literal</code>…'),
        array('<b>English text</b> words here', 12, 'English text…'),
        array('<p>First paragraph.</p><p>Second paragraph.</p>', 60, 'First paragraph. Second paragraph.'),
        array('<p>First<p>Second</p>', 60, 'First Second'),
        array('first<BR class="line">second<br />third', 60, 'first second third'),
        array('inter<em>national</em> &lt;br&gt; text', 60, 'international <br> text'),
        array('<ul><li>first</li><li>second</li></ul>', 60, 'first second'),
        array('中文&nbsp;&amp;&nbsp;more', 6, '中文 & m…'),
        array('&lt;img src=x onerror=alert(1)&gt; 中文', 8, '<img src…'),
        array('中文中文', 2, '中文…', true),
        array('&#20013;&#x6587;&#20013;&#x6587;', 2, '中文…', true),
        array('中文', 2, '中文', true),
        array("e\u{0301}x", 1, "e\u{0301}…", true),
        array('👩‍💻👨‍💻', 1, '👩‍💻…', true),
        array('<p>First</p><p>Second<br>third &lt;br&gt;.</p>', 60, 'First Second third <br>.', true),
        array('<!-- wp:paragraph --><p>第一段</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>第二段</p><!-- /wp:paragraph -->', 60, '第一段 第二段', true),
    ) as $case) {
        list($input, $length, $expected) = $case;
        $auto = $case[3] ?? false;
        if ($auto) {
            // Exercise Core's real character-based excerpt branch without a language download.
            $GLOBALS['wp_locale'] = new class extends WP_Locale {
                public function get_word_count_type() { return 'characters_excluding_spaces'; }
            };
        }
        update_option('zen_excerpt_length', $length);
        $id = wp_insert_post(wp_slash(array(
            'post_type'=>'post', 'post_status'=>'publish', 'post_title'=>'Entity excerpt fixture',
            $auto ? 'post_content' : 'post_excerpt' => $input,
        )), true);
        if (!$id || is_wp_error($id)) throw new RuntimeException('Fixture insert failed');
        $ids[] = $id;
        $GLOBALS['wp_query'] = new WP_Query(array('p'=>$id));
        $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
        $GLOBALS['post'] = get_post($id); setup_postdata($GLOBALS['post']);
        ob_start(); get_template_part('template-parts/content', 'excerpt'); $html = ob_get_clean();
        preg_match('~<div class="zen-post-excerpt[^>]*>(.*?)</div>~s', $html, $match);
        $output = trim($match[1] ?? '');
        $visible = html_entity_decode($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ok = $visible === $expected && !str_contains($output, '<') && !str_contains($output, '>')
            && apply_filters('excerpt_length', 55) === $length;
        $checks++; $failures += !$ok;
        echo ($ok ? 'PASS ' : 'FAIL ') . json_encode(array('mode'=>$auto ? 'auto' : 'manual', 'input'=>$input, 'length'=>$length, 'visible'=>$visible, 'expected'=>$expected), JSON_UNESCAPED_UNICODE) . "\n";
    }

    // A plugin may fetch a different native excerpt before or after Core's callback.
    $nested_id = wp_insert_post(array(
        'post_type'=>'post', 'post_status'=>'publish', 'post_title'=>'Nested excerpt fixture',
        'post_content'=>str_repeat('文', 70),
    ), true);
    if (!$nested_id || is_wp_error($nested_id)) throw new RuntimeException('Nested fixture insert failed');
    $ids[] = $nested_id;
    update_option('zen_excerpt_length', 60);
    $native_expected = str_repeat('文', 60) . ' [&hellip;]';
    $observed = array();
    $plugin_excerpt = static function ($excerpt, $post) use ($id, $nested_id, &$observed) {
        if ((int) $post->ID === $id) $observed[] = get_the_excerpt($nested_id);
        return $excerpt;
    };
    add_filter('get_the_excerpt', $plugin_excerpt, 9, 2);
    add_filter('get_the_excerpt', $plugin_excerpt, 11, 2);
    ob_start();
    try {
        zen_index_post_excerpt($id);
    } finally {
        ob_end_clean();
        remove_filter('get_the_excerpt', $plugin_excerpt, 9);
        remove_filter('get_the_excerpt', $plugin_excerpt, 11);
    }
    $ok = $observed === array($native_expected, $native_expected) && get_the_excerpt($nested_id) === $native_expected;
    $checks++; $failures += !$ok;
    echo ($ok ? 'PASS ' : 'FAIL ') . "nested native excerpts keep their normal limit both before and after the Core callback\n";
} finally {
    $GLOBALS['wp_locale'] = $old_locale;
    foreach ($ids as $id) wp_delete_post($id, true);
    foreach ($old as $key=>$value) { if ($value === null) delete_option($key); else update_option($key, $value); }
    wp_reset_postdata();
    wp_set_current_user($old_user);
}
$complete = true;
printf("NATIVE_EXCERPT_COMPLETE %d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
