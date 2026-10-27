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
    // Reading-time traversal must reject malformed refs before using them as keys.
    $reading_pattern = zen_fixture_post('<p>' . str_repeat('字', 301) . '</p>');
    $reading_ref = '<!-- wp:block {"ref":' . $reading_pattern . '} /-->';
    $reading_cases = array(
        'array ref' => array('<!-- wp:block {"ref":[1]} /-->', '', 1),
        'empty array ref' => array('<!-- wp:block {"ref":[]} /-->', '', 1),
        'string ref' => array('<!-- wp:block {"ref":"' . $reading_pattern . '"} /-->', '', 1),
        'missing ref attribute' => array('<!-- wp:block /-->', '', 1),
        'missing pattern' => array('<!-- wp:block {"ref":2147483647} /-->', '', 1),
        'zero ref' => array('<!-- wp:block {"ref":0} /-->', '', 1),
        'negative ref' => array('<!-- wp:block {"ref":-1} /-->', '', 1),
        'cycle' => array('<!-- wp:block {"ref":' . $cycle . '} /-->', '', 1),
        'valid pattern' => array($reading_ref, '<p>' . str_repeat('字', 301) . '</p>', 2),
        'repeated pattern' => array($reading_ref . $reading_ref, str_repeat('<p>' . str_repeat('字', 301) . '</p>', 2), 3),
        'cycle beside text' => array($reading_ref . '<!-- wp:block {"ref":' . $cycle . '} /-->', '<p>' . str_repeat('字', 301) . '</p>', 2),
        'ordinary text' => array('<p>' . str_repeat('字', 301) . '</p>', '<p>' . str_repeat('字', 301) . '</p>', 2),
    );
    foreach ($reading_cases as $name => $case) {
        try {
            zen_native_check(zen_reading_block_text(parse_blocks($case[0])) === $case[1], 'reading text: ' . $name);
            wp_update_post(array('ID' => $post_id, 'post_content' => $case[0]));
            zen_native_check(zen_get_reading_time($post_id) === $case[2], 'reading minutes: ' . $name);
        } catch (Throwable $error) {
            zen_native_check(false, 'reading traversal: ' . $name . ' (' . $error->getMessage() . ')');
        }
    }
    foreach (array(
        'empty' => array('', 1),
        'literal boundary' => array(str_repeat('字', 300), 1),
        'numeric entity boundary' => array(str_repeat('&#23383;', 300), 1),
        'named entity boundary' => array(str_repeat('&amp;', 300), 1),
        'over boundary' => array(str_repeat('&#23383;', 301), 2),
        'encoded code stays literal' => array('<pre><code>' . str_repeat('&lt;b&gt;', 101) . '</code></pre>', 2),
    ) as $name => $case) {
        wp_update_post(array('ID' => $reading_pattern, 'post_content' => $case[0]));
        foreach (array('direct' => $case[0], 'synced' => $reading_ref) as $kind => $content) {
            wp_update_post(array('ID' => $post_id, 'post_content' => $content));
            zen_native_check(zen_get_reading_time($post_id) === $case[1], 'entity reading ' . $kind . ': ' . $name);
        }
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
    $agent_index = 0;
    foreach (array('Mozilla/5.0 Chrome/130.0 Safari/537.36', 'Mozilla/5.0 (iPhone) Version/18.0 Mobile Safari/604.1', '') as $agent) {
        // Distinct peers isolate user-agent behavior from five-minute deduplication.
        $_SERVER['REMOTE_ADDR'] = '192.0.2.' . ++$agent_index;
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
