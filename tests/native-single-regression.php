<?php
/** Native media-order and Loop contract; disposable loopback fixture only. */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1]) || empty($argv[2])) exit(2);
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') throw new RuntimeException('Isolated Zen required');
$zen_single_image = (int) $argv[2];
if (!wp_attachment_is_image($zen_single_image)) throw new RuntimeException('Real image attachment required');
$zen_single_toc = (int) ($argv[3] ?? 1);
$zen_single_old_toc = get_option('zen_show_toc', null);
$zen_single_old_featured = get_option('zen_show_featured_image', null);
$zen_single_events = array();
$zen_single_content_calls = 0;
$zen_single_footer_in_loop = null;
$zen_single_id = 0;
$zen_single_complete = false;
register_shutdown_function(static function () use (&$zen_single_complete) { if (!$zen_single_complete) { fwrite(STDERR, "NATIVE_SINGLE_INCOMPLETE\n"); exit(1); } });
try {
    update_option('zen_show_toc', $zen_single_toc);
    update_option('zen_show_featured_image', 1);
    $image = wp_get_attachment_url($zen_single_image);
    $body = '<h2>Reading section</h2>' . str_repeat('<p><img src="' . esc_url($image) . '" width="1200" height="800" alt="Body image"></p>', 3);
    $zen_single_id = wp_insert_post(array('post_status'=>'publish', 'post_type'=>'post', 'post_title'=>'Single media order', 'post_content'=>$body));
    set_post_thumbnail($zen_single_id, $zen_single_image);
    add_action('get_header', static function () use (&$zen_single_events) { $zen_single_events[] = 'header'; });
    add_action('wp_head', static function () use (&$zen_single_events) { $zen_single_events[] = 'head'; });
    add_filter('the_content', static function ($content) use (&$zen_single_content_calls, &$zen_single_events) { $zen_single_content_calls++; $zen_single_events[] = 'content'; return $content; }, 99);
    add_action('loop_end', static function ($query) use (&$zen_single_events) { if ($query === $GLOBALS['wp_query']) $zen_single_events[] = 'loop_end'; });
    add_action('get_footer', static function () use (&$zen_single_footer_in_loop) { $zen_single_footer_in_loop = in_the_loop(); });
    wp(array('p'=>$zen_single_id));
    ob_start(); include get_template_directory() . '/single.php'; $html = ob_get_clean();
    $doc = new DOMDocument(); @$doc->loadHTML('<?xml encoding="UTF-8">' . $html); $xpath = new DOMXPath($doc);
    $featured = $xpath->query('//img[contains(@class,"zen-featured-image")]')->item(0);
    $images = $xpath->query('//article[@id="post-content"]//img');
    $checks = array(
        'featured remains eager high-priority' => $featured && $featured->getAttribute('loading') !== 'lazy' && $featured->getAttribute('fetchpriority') === 'high',
        'body cannot steal featured priority' => $images->length === 3 && $xpath->query('//article[@id="post-content"]//img[@fetchpriority="high"]')->length === 0,
        'content filtered exactly once after wp_head' => $zen_single_content_calls === 1 && array_search('head', $zen_single_events, true) < array_search('content', $zen_single_events, true),
        'native loop ends once before footer' => count(array_keys($zen_single_events, 'loop_end', true)) === 1 && $zen_single_footer_in_loop === false,
        'toc reservation matches option' => ($xpath->query('//button[contains(concat(" ",normalize-space(@class)," ")," zen-toc-pending ")]')->length > 0) === (bool) $zen_single_toc,
    );
    $failures = 0;
    foreach ($checks as $label=>$passed) { echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; $failures += !$passed; }
    echo 'NATIVE_SINGLE_COMPLETE ' . count($checks) . ' checks, ' . $failures . ' failures; toc=' . $zen_single_toc . PHP_EOL;
} finally {
    if ($zen_single_id) wp_delete_post($zen_single_id, true);
    foreach (array('zen_show_toc'=>$zen_single_old_toc, 'zen_show_featured_image'=>$zen_single_old_featured) as $key=>$value) { if ($value === null) delete_option($key); else update_option($key, $value); }
    wp_reset_query();
}
$zen_single_complete = true;
exit($failures ? 1 : 0);
