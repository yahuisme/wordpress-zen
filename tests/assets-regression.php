<?php
/** Run: php tests/assets-regression.php. WordPress boundary doubles, no I/O. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
$GLOBALS['font'] = 'system';
$GLOBALS['styles'] = array();
$GLOBALS['inline'] = array();
$GLOBALS['scripts'] = array();
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_action(...$args) {}
function get_option($key, $default = false) {
    if ($key === 'zen_font_family') return $GLOBALS['font'];
    return $GLOBALS['asset_options'][$key] ?? $default;
}
function wp_get_theme() { return new class { public function get($key) { return 'test'; } }; }
function get_template_directory_uri() { return 'https://example.test/zen'; }
function get_template_directory() { return ABSPATH; }
function is_singular() { return $GLOBALS['asset_singular'] ?? false; }
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
function comments_open() { return false; }
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
check(zen_sanitize_font_family('system') === 'system', 'system font can be saved');
zen_scripts();
check(!isset($GLOBALS['styles']['zen-google-fonts']), 'system font omits Google stylesheet');
check(count($GLOBALS['scripts']) === 1 && !str_contains($GLOBALS['scripts'][0][0] ?? '', 'highlight'), 'non-article pages omit code highlighter');
check(zen_resource_hints(array(), 'preconnect') === array(), 'system font omits Google preconnect');
check(!preg_match('/Inter|Space Grotesk|Noto Sans SC|Noto Serif SC/', implode('', $GLOBALS['inline'])), 'system font CSS uses local stacks');
foreach (array('inter', 'space-grotesk') as $font) {
    $GLOBALS['font'] = $font;
    $GLOBALS['styles'] = array();
    zen_scripts();
    check(isset($GLOBALS['styles']['zen-google-fonts']), "$font retains font stylesheet");
    check(count(zen_resource_hints(array(), 'preconnect')) === 2, "$font retains preconnect");
}
$GLOBALS['asset_singular'] = true;
$GLOBALS['font'] = 'system';
$GLOBALS['asset_posts'][42] = (object) array('post_type' => 'wp_block', 'post_status' => 'publish', 'post_password' => '', 'post_content' => '<pre><code>synced code</code></pre>');
foreach (array(
    'locked code article' => array('<pre><code>secret</code></pre>', true, 1, false),
    'unlocked password code article' => array('<pre><code>secret</code></pre>', false, 1, true),
    'public code article' => array('<pre><code>public</code></pre>', false, 1, true),
    'public synced code article' => array('<!-- wp:block {"ref":42} /-->', false, 1, true),
    'public plain article' => array('<p>plain</p>', false, 1, false),
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
    check($main[2] === ($case[3] ? array('highlight-js') : array()), "$name preserves main script dependencies");
    if ($case[1]) {
        check($GLOBALS['code_scan_count'] === 0, 'locked article does not scan hidden code or referenced patterns');
        check(($GLOBALS['password_checked_post'] ?? null) === $GLOBALS['post'], 'password guard checks the actual article object');
    }
}
exit($failures ? 1 : 0);
