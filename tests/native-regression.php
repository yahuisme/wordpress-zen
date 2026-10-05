<?php
/** Native WordPress test; isolated local installation only. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) {
    fwrite(STDERR, "Set ZEN_TEST_ISOLATED=1 and pass the isolated wp-load.php path.\n");
    exit(1);
}
$zen_native_complete = false;
register_shutdown_function(function () use (&$zen_native_complete) {
    if (!$zen_native_complete) {
        fwrite(STDERR, "Native regression did not complete.\n");
        exit(1);
    }
});
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Isolated loopback WordPress required');
}
$failures = 0;
$total = 0;
$ids = array();
function zen_native_check($condition, $label) {
    global $failures, $total;
    $total++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failures++;
}
function zen_fixture_post($content, $overrides = array()) {
    global $ids;
    $id = wp_insert_post(array_merge(array('post_title' => 'Zen isolated fixture', 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => $content), $overrides), true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    $ids[] = $id;
    return $id;
}
try {
    $zen_saved_count_option = get_option('zen_show_reading_count', null);
    update_option('zen_show_reading_count', 1);
    $code = '<!-- wp:code --><pre class="wp-block-code"><code>const answer = 42;</code></pre><!-- /wp:code -->';
    $pattern = zen_fixture_post($code);
    $post_id = zen_fixture_post('<!-- wp:block {"ref":' . $pattern . '} /-->', array('post_type' => 'post'));
    $GLOBALS['post'] = get_post($post_id);
    zen_native_check(str_contains(do_blocks($GLOBALS['post']->post_content), '<code>'), 'native synced pattern renders code');
    zen_native_check((bool) zen_has_code_blocks(), 'synced pattern code detected');
    $group = '<!-- wp:group --><div class="wp-block-group"><!-- wp:block {"ref":' . $pattern . '} /--></div><!-- /wp:group -->';
    $nested = zen_fixture_post($group);
    $cycle = zen_fixture_post('');
    wp_update_post(array('ID' => $cycle, 'post_content' => '<!-- wp:block {"ref":' . $cycle . '} /-->'));
    $draft = zen_fixture_post($code, array('post_status' => 'draft'));
    $protected = zen_fixture_post($code, array('post_password' => 'fixture'));
    $wrong_type = zen_fixture_post($code, array('post_type' => 'post'));
    $cases = array(
        'nested group and pattern' => array('<!-- wp:block {"ref":' . $nested . '} /-->', true),
        'self cycle' => array('<!-- wp:block {"ref":' . $cycle . '} /-->', false),
        'cycle beside valid code' => array('<!-- wp:block {"ref":' . $pattern . '} /--><!-- wp:block {"ref":' . $cycle . '} /-->', true),
        'draft pattern' => array('<!-- wp:block {"ref":' . $draft . '} /-->', false),
        'password pattern' => array('<!-- wp:block {"ref":' . $protected . '} /-->', false),
        'non-pattern reference' => array('<!-- wp:block {"ref":' . $wrong_type . '} /-->', false),
        'missing reference' => array('<!-- wp:block {"ref":2147483647} /-->', false),
        'malformed reference' => array('<!-- wp:block {"ref":[1]} /-->', false),
        'direct code' => array($code, true),
        'classic pre' => array('<pre>sample</pre>', true),
        'plain text' => array('<p>plain</p>', false),
    );
    foreach ($cases as $name => $case) {
        wp_update_post(array('ID' => $post_id, 'post_content' => $case[0]));
        $GLOBALS['post'] = get_post($post_id);
        zen_native_check((bool) zen_has_code_blocks() === $case[1], $name);
    }
    $GLOBALS['post'] = get_post($post_id);
    query_posts(array('p' => $post_id));
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    $before = zen_get_reading_count($post_id);
    zen_track_post_view();
    zen_native_check(zen_get_reading_count($post_id) === $before, 'crawler request does not increment views');
    foreach (array('bingbot/2.0', 'Baiduspider', 'DuckDuckBot/1.0', 'GPTBot/1.0', 'ClaudeBot/1.0', 'Yahoo! Slurp', 'facebookexternalhit/1.1', 'HeadlessChrome/130.0', 'ExampleCrawler/1.0') as $agent) {
        $_SERVER['HTTP_USER_AGENT'] = $agent;
        zen_track_post_view();
        zen_native_check(zen_get_reading_count($post_id) === $before, 'crawler excluded: ' . $agent);
    }
    foreach (array('Mozilla/5.0 Chrome/130.0 Safari/537.36', 'Mozilla/5.0 (iPhone) Version/18.0 Mobile Safari/604.1', '') as $agent) {
        $_SERVER['HTTP_USER_AGENT'] = $agent;
        zen_track_post_view();
        $before++;
        zen_native_check(zen_get_reading_count($post_id) === $before, 'ordinary or missing user agent remains countable');
    }
    $saved_count_option = get_option('zen_show_reading_count', null);
    try {
        update_option('zen_show_reading_count', 0);
        zen_track_post_view();
        zen_native_check(zen_get_reading_count($post_id) === $before, 'disabled count never writes');
    } finally {
        if (null === $saved_count_option) delete_option('zen_show_reading_count');
        else update_option('zen_show_reading_count', $saved_count_option);
    }
} finally {
    if (isset($zen_saved_count_option)) update_option('zen_show_reading_count', $zen_saved_count_option);
    else delete_option('zen_show_reading_count');
    foreach (array_reverse($ids) as $id) wp_delete_post($id, true);
    wp_reset_postdata();
}
$zen_native_complete = true;
printf("NATIVE_COMPLETE %d checks, %d failures\n", $total, $failures);
exit($failures ? 1 : 0);
