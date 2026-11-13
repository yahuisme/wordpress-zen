<?php
/** Native saved manual excerpts; no browser/network and no unrelated asset suite. */
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
        array('English &amp;lt;tag&amp;gt; literal', 8, 'English &lt;tag&gt; literal'),
        array('English &lt;code&gt;literal&lt;/code&gt; words here', 3, 'English <code>literal</code> words…'),
        array('<b>English text</b> words here', 3, 'English text words…'),
        array('中文&nbsp;&amp;&nbsp;more', 6, '中文 & m…'),
        array('&lt;img src=x onerror=alert(1)&gt; 中文', 8, '<img src…'),
    ) as list($input, $length, $expected)) {
        update_option('zen_excerpt_length', $length);
        $id = wp_insert_post(wp_slash(array('post_type'=>'post','post_status'=>'publish','post_title'=>'Entity excerpt fixture','post_excerpt'=>$input)), true);
        if (!$id || is_wp_error($id)) throw new RuntimeException('Fixture insert failed');
        $ids[] = $id;
        $GLOBALS['wp_query'] = new WP_Query(array('p'=>$id));
        $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
        $GLOBALS['post'] = get_post($id); setup_postdata($GLOBALS['post']);
        ob_start(); get_template_part('template-parts/content', 'excerpt'); $html = ob_get_clean();
        preg_match('~<div class="zen-post-excerpt[^>]*>(.*?)</div>~s', $html, $match);
        $output = trim($match[1] ?? '');
        $visible = html_entity_decode($output, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ok = $visible === $expected && !str_contains($output, '<') && !str_contains($output, '>');
        $checks++; $failures += !$ok;
        echo ($ok ? 'PASS ' : 'FAIL ') . json_encode(array('input'=>$input, 'stored'=>get_post($id)->post_excerpt, 'length'=>$length, 'visible'=>$visible, 'expected'=>$expected), JSON_UNESCAPED_UNICODE) . "\n";
    }
} finally {
    foreach ($ids as $id) wp_delete_post($id, true);
    foreach ($old as $key=>$value) { if ($value === null) delete_option($key); else update_option($key, $value); }
    wp_reset_postdata();
    wp_set_current_user($old_user);
}
$complete = true;
printf("NATIVE_EXCERPT_COMPLETE %d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
