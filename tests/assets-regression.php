<?php
/** Run: php tests/assets-regression.php. WordPress boundary doubles, no I/O. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
require __DIR__ . '/html-api-bootstrap.php';
$GLOBALS['styles'] = array();
$GLOBALS['inline'] = array();
$GLOBALS['scripts'] = array();
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['asset_hooks'][$hook][] = array($callback, $accepted_args);
}
function asset_action($hook, ...$args) {
    foreach ($GLOBALS['asset_hooks'][$hook] ?? array() as list($callback, $accepted_args)) {
        $callback(...array_slice($args, 0, $accepted_args));
    }
}
function register_setting(...$args) {}
function current_user_can($cap) { return $cap === 'manage_links'; }
function absint($value) { return abs((int) $value); }
function esc_html__($text, $domain = '') { return $text; }
function wp_die($message, $title, $args) { throw new DomainException($message, $args['response']); }
function add_filter(...$args) {}
function has_filter($hook) { return false; }
function remove_action(...$args) {}
function get_option($key, $default = false) {
    return $GLOBALS['asset_options'][$key] ?? $default;
}
function wp_get_theme() { return new class { public function get($key) { return 'test'; } }; }
function get_template_directory_uri() { return 'https://example.test/zen'; }
function get_template_directory() { return ABSPATH; }
function get_theme_file_uri($file) { return 'https://example.test/zen/' . $file; }
function esc_url_raw($url) { return $url; }
function is_singular() { return $GLOBALS['asset_singular'] ?? false; }
function is_preview() { return false; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_post($id = null) { return null === $id ? ($GLOBALS['post'] ?? null) : ($GLOBALS['asset_posts'][$id] ?? null); }
function post_password_required($post = null) {
    $GLOBALS['password_checked_post'] = $post;
    return $GLOBALS['asset_locked'] ?? false;
}
function has_block($name, $content) {
    $GLOBALS['code_scan_count'] = ($GLOBALS['code_scan_count'] ?? 0) + 1;
    return str_contains($content, '<!-- wp:' . str_replace('core/', '', $name) . ' ')
        || str_contains($content, '<!-- wp:' . str_replace('core/', '', $name) . ' -->');
}
function parse_blocks($content) {
    // These focused fixtures contain standalone synced-block references only.
    preg_match_all('/<!-- wp:block (\{.*?\}) \/-->/', $content, $matches);
    return array_map(static fn($attrs) => array('blockName' => 'core/block', 'attrs' => json_decode($attrs, true), 'innerBlocks' => array()), $matches[1]);
}
function comments_open() { return $GLOBALS['asset_comments_open'] ?? false; }
function wp_enqueue_style($handle, ...$args) { $GLOBALS['styles'][$handle] = $args; }
function wp_enqueue_script(...$args) { $GLOBALS['scripts'][] = $args; }
function wp_script_is($handle, $status = 'enqueued') {
    return in_array($handle, array_column($GLOBALS['scripts'], 0), true);
}
function wp_localize_script(...$args) {}
function wp_add_inline_style($handle, $css) { $GLOBALS['inline'][] = $css; }
require ABSPATH . 'inc/options.php';
require ABSPATH . 'inc/setup.php';
require ABSPATH . 'inc/enqueue.php';
$failures = 0;
function check($condition, $label) {
    global $failures;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failures++;
}
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array('zen_link_order' => '42', 'link_rating' => '0', 'link_id' => '7');
    asset_action('admin_init');
    check($_POST['link_rating'] === '0', 'unrelated admin initialization does not rewrite native fields');
    asset_action('load-link.php');
    asset_action('check_admin_referer', 'update-bookmark_7', 1);
    check($_POST['link_rating'] === 42, 'authorized native edit maps the custom order to the Core rating');
    $_POST['zen_link_order'] = '0';
    asset_action('check_admin_referer', 'add-bookmark', 1);
    check($_POST['link_rating'] === 0, 'authorized native add accepts zero');
    foreach (array('' => 0, '00042' => 42, '2147483647' => 2147483647) as $value => $expected) {
        $_POST['zen_link_order'] = (string) $value;
        asset_action('check_admin_referer', 'update-bookmark_7', 1);
        check($_POST['link_rating'] === $expected, 'saved bookmark order accepts blank, leading zeroes, and integer limit: ' . $value);
    }
    foreach (array('4.5', '-4', '2147483648', '2147483647.00000001', '1e2') as $invalid) {
        $_POST['link_rating'] = 0;
        $_POST['zen_link_order'] = $invalid;
        try {
            asset_action('check_admin_referer', 'update-bookmark_7', 1);
            check(false, 'invalid bookmark order is rejected before Core saves: ' . $invalid);
        } catch (DomainException $error) {
            check($error->getCode() === 400 && str_contains($error->getMessage(), '排序') && $_POST['link_rating'] === 0, 'invalid bookmark order leaves the native field unchanged: ' . $invalid);
        }
    }
} finally {
    $_POST = array();
    if ($method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $method;
}
foreach (array('system', 'kami', 'inter', 'space-grotesk') as $retired_font) {
    $GLOBALS['asset_options']['zen_font_family'] = $retired_font;
    $GLOBALS['styles'] = $GLOBALS['inline'] = $GLOBALS['scripts'] = array();
    zen_scripts();
    check(!isset($GLOBALS['styles']['zen-bins-google-code']) && !isset($GLOBALS['styles']['zen-google-fonts']), "$retired_font cannot switch the new theme to an old font");
    check(count($GLOBALS['scripts']) === 1 && $GLOBALS['scripts'][0][0] === 'zen-main', 'non-article pages omit code highlighter');
    check(!str_contains(implode('', $GLOBALS['inline']), 'url('), 'saved settings cannot inject font URLs');
}
$GLOBALS['asset_singular'] = true;
$GLOBALS['asset_posts'][42] = (object) array('post_type' => 'wp_block', 'post_status' => 'publish', 'post_password' => '', 'post_content' => '<pre><code>synced code</code></pre>');
foreach (array(
    'locked code article' => array('<pre><code>secret</code></pre>', true, 1, false),
    'unlocked password code article' => array('<pre><code>secret</code></pre>', false, 1, true),
    'public code article' => array('<pre><code>public</code></pre>', false, 1, true),
    'public synced code article' => array('<!-- wp:block {"ref":42} /-->', false, 1, true),
    'public plain article' => array('<p>plain</p>', false, 1, false),
    'plain preformatted block' => array('<!-- wp:preformatted --><pre>Text only</pre><!-- /wp:preformatted -->', false, 1, false),
    'inline code next to preformatted text' => array('<pre>Text only</pre><p><code>inline</code></p>', false, 1, false),
    'disabled code highlighting' => array('<pre>code</pre>', false, 0, false),
) as $name => $case) {
    $GLOBALS['post'] = (object) array('post_content' => $case[0], 'post_password' => str_contains($name, 'password') || $case[1] ? 'fixture' : '');
    $GLOBALS['asset_locked'] = $case[1];
    $GLOBALS['asset_options']['zen_show_highlight'] = $case[2];
    $GLOBALS['scripts'] = $GLOBALS['styles'] = array();
    $GLOBALS['code_scan_count'] = 0;
    zen_scripts();
    check(wp_script_is('highlight-js') === $case[3] && isset($GLOBALS['styles']['highlight-css']) === $case[3], "$name loads highlighter only for visible code");
    $main = array_values(array_filter($GLOBALS['scripts'], static fn($args) => $args[0] === 'zen-main'))[0];
    check($main[2] === array(), "$name keeps basic interactions independent of optional highlighting");
    if ($case[3]) {
        $highlighter = array_values(array_filter($GLOBALS['scripts'], static fn($args) => $args[0] === 'highlight-js'))[0];
        check(($highlighter[4]['strategy'] ?? '') === 'async', "$name loads optional highlighter asynchronously");
    }
    if ($case[1]) {
        check($GLOBALS['code_scan_count'] === 0, 'locked article does not scan hidden code or referenced patterns');
        check(($GLOBALS['password_checked_post'] ?? null) === $GLOBALS['post'], 'password guard checks the actual article object');
    }
}
foreach (array(
    'locked comments' => array(true, 1, true, false),
    'unlocked threaded comments' => array(true, 1, false, true),
    'threading disabled' => array(true, 0, false, false),
    'closed comments' => array(false, 1, false, false),
) as $name => [$comments_open, $thread_comments, $locked, $expected]) {
    $GLOBALS['asset_comments_open'] = $comments_open;
    $GLOBALS['asset_options']['thread_comments'] = $thread_comments;
    $GLOBALS['asset_locked'] = $locked;
    $GLOBALS['scripts'] = array();
    zen_scripts();
    check(wp_script_is('comment-reply') === $expected, "$name: Core owns reply behavior only when available");
}
check(zen_reference_font_styles() === array(), 'source-only checkout loads no missing Kai files or retired code font');
exit($failures ? 1 : 0);
