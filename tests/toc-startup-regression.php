<?php
/** Template boundary: prepare real content once before reserving a TOC slot. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
require __DIR__ . '/html-api-bootstrap.php';
$loops = 1;
$GLOBALS['toc_fixture'] = '<h2>Visible heading</h2><p>Body</p>';
function get_header($name = null, $args = array()) { $GLOBALS['header_args'] = $args; }
function get_footer() {}
function have_posts() { return $GLOBALS['loops'] > 0; }
function the_post() { $GLOBALS['loops']--; }
function the_content() { $GLOBALS['content_calls']++; echo $GLOBALS['toc_fixture']; }
function zen_get_option($name) { return $name === 'zen_show_toc' ? 1 : 0; }
function zen_show_reading_count() { return false; }
function zen_post_title() { return 'Title'; }
function has_excerpt() { return false; }
function zen_get_primary_category() { return null; }
function zen_posted_on_link() {}
function has_post_thumbnail() { return false; }
function wp_link_pages($args) {}
function get_the_author() { return ''; }
function get_previous_post() { return null; }
function get_next_post() { return null; }
function comments_open() { return false; }
function get_comments_number() { return 0; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_attr__($s, $domain = '') { return esc_attr($s); }
$failures = 0;
foreach (array(
    'visible heading' => array('<h2>Visible heading</h2><p>Body</p>', true),
    'filtered heading' => array('<div><h3 class="dynamic">Rendered block</h3></div>', true),
    'plain article' => array('<p>No headings</p>', false),
    'escaped code' => array('<pre>&lt;h2&gt;not a heading&lt;/h2&gt;</pre>', false),
    'protected body' => array('<form class="post-password-form"><p>Password required</p></form>', false),
    'commented heading' => array('<!-- <h2>Not visible</h2> --><p>Body</p>', false),
    'script text' => array('<script>const sample = "<h2>Not a heading</h2>";</script><p>Body</p>', false),
) as $label => $case) {
    $GLOBALS['loops'] = 1;
    $GLOBALS['toc_fixture'] = $case[0];
    $GLOBALS['content_calls'] = 0;
    $GLOBALS['header_args'] = array();
    ob_start(); include ABSPATH . 'single.php'; $html = ob_get_clean();
    $passed = $GLOBALS['content_calls'] === 1 && substr_count($html, $case[0]) === 1
        && str_contains($html, 'zen-toc-pending') === $case[1];
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failures += !$passed;
}
echo "TOC_STARTUP_COMPLETE 7 checks, $failures failures\n";
exit($failures ? 1 : 0);
