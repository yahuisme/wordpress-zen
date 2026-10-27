<?php
/** Reading-time equivalence using the shared native WordPress text/block APIs. */
$core = getenv('ZEN_WP_CORE') ?: '/opt/test-tools/wordpress/clean-core';
if (!is_file($core . '/wp-includes/formatting.php')) {
    fwrite(STDERR, "Set ZEN_WP_CORE to an isolated WordPress core directory.\n");
    exit(1);
}
define('ABSPATH', rtrim($core, '/') . '/');
function add_action(...$args) {}
function apply_filters($hook, $value, ...$args) { return $value; }
function get_post_field($field, $id) { return $GLOBALS['reading_content']; }
function get_post($id) { return $GLOBALS['reading_patterns'][$id] ?? null; }
require ABSPATH . 'wp-includes/formatting.php';
require ABSPATH . 'wp-includes/shortcodes.php';
require ABSPATH . 'wp-includes/class-wp-block-parser.php';
require ABSPATH . 'wp-includes/blocks.php';
require dirname(__DIR__) . '/theme/zen/inc/options.php';
$failures = 0;
foreach (array(0, 300, 301, 600, 601) as $length) {
    $minutes = max(1, (int) ceil($length / 300));
    foreach (array(
        'literal' => str_repeat('字', $length),
        'numeric entity' => str_repeat('&#23383;', $length),
        'named entity' => str_repeat('&amp;', $length),
        'escaped code' => '<pre><code>' . str_repeat('&lt;b&gt;', $length) . '</code></pre>',
    ) as $name => $content) {
        // Escaped code is visible literal <b>, i.e. three characters, not markup.
        $expected = $name === 'escaped code' ? max(1, (int) ceil($length * 3 / 300)) : $minutes;
        foreach (array(false, true) as $synced) {
            $GLOBALS['reading_patterns'][12] = (object) array('post_type' => 'wp_block', 'post_status' => 'publish', 'post_password' => '', 'post_content' => $content);
            $GLOBALS['reading_content'] = $synced ? '<!-- wp:block {"ref":12} /-->' : $content;
            $actual = zen_get_reading_time(1);
            if ($actual !== $expected) {
                $failures++;
                fwrite(STDERR, "FAIL $name length=$length synced=" . (int) $synced . " expected=$expected actual=$actual\n");
            }
        }
    }
}
if (!$failures) echo "READING_ENTITY_COMPLETE\n";
exit($failures ? 1 : 0);
