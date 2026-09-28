<?php
/** Focused, dependency-free tests: php tests/seo-regression.php. No WordPress writes. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['seo_hooks'] = array();
$GLOBALS['seo_filters'] = array();
$GLOBALS['seo_context'] = array();
$GLOBALS['seo_checks'] = 0;
$GLOBALS['seo_failures'] = 0;

function add_action($hook, $callback, $priority = 10) {
    $GLOBALS['seo_hooks'][$hook][$priority][] = $callback;
}
function apply_filters($hook, $value, ...$args) {
    foreach ($GLOBALS['seo_filters'][$hook] ?? array() as $callback) {
        $value = $callback($value, ...$args);
    }
    return $value;
}
function wp_head() {
    $hooks = $GLOBALS['seo_hooks']['wp_head'] ?? array();
    ksort($hooks);
    foreach ($hooks as $callbacks) {
        foreach ($callbacks as $callback) {
            $callback();
        }
    }
}
function seo_context($kind = 'post', $overrides = array()) {
    $GLOBALS['seo_filters'] = array();
    $GLOBALS['seo_context'] = array_merge(array(
        'kind' => $kind, 'protected' => false, 'excerpt' => 'An editorial excerpt.',
        'content' => 'Article body.', 'term' => '', 'bio' => '', 'archive_title' => '',
        'name' => 'Quiet Site', 'description' => 'A reading journal',
    ), $overrides);
    $GLOBALS['post'] = (object) array(
        'ID' => 17, 'post_author' => 42,
        'post_excerpt' => $GLOBALS['seo_context']['excerpt'],
        'post_content' => $GLOBALS['seo_context']['content'],
    );
}
function is_singular($type = '') {
    $kind = $GLOBALS['seo_context']['kind'];
    return '' === $type ? in_array($kind, array('post', 'page'), true) : $kind === $type;
}
function is_single() { return is_singular('post'); }
function is_page() { return is_singular('page'); }
function is_category() { return 'category' === $GLOBALS['seo_context']['kind']; }
function is_tag() { return 'tag' === $GLOBALS['seo_context']['kind']; }
function is_tax() { return 'tax' === $GLOBALS['seo_context']['kind']; }
function is_author() { return 'author' === $GLOBALS['seo_context']['kind']; }
function is_date() { return 'date' === $GLOBALS['seo_context']['kind']; }
function is_archive() { return is_category() || is_tag() || is_tax() || is_author() || is_date(); }
function is_search() { return 'search' === $GLOBALS['seo_context']['kind']; }
function is_home() { return 'home' === $GLOBALS['seo_context']['kind']; }
function is_front_page() { return is_home(); }
function is_404() { return '404' === $GLOBALS['seo_context']['kind']; }
function get_post($id = null) { return $GLOBALS['post']; }
function get_queried_object_id() { return is_author() ? 42 : 17; }
function get_queried_object() { return is_author() ? (object) array('ID' => 42) : get_post(); }
function get_the_excerpt($post = null) { return $GLOBALS['seo_context']['excerpt']; }
function post_password_required($post = null) { return $GLOBALS['seo_context']['protected']; }
function get_bloginfo($key) { return $GLOBALS['seo_context'][$key] ?? 'UTF-8'; }
function term_description() { return $GLOBALS['seo_context']['term']; }
function get_the_archive_description() { return is_author() ? $GLOBALS['seo_context']['bio'] : term_description(); }
function get_the_archive_title() { return $GLOBALS['seo_context']['archive_title']; }
function get_search_query($escaped = true) { return 'a "quote"'; }
function get_the_author_meta($key, $id = null) { return 'description' === $key ? $GLOBALS['seo_context']['bio'] : 'An Author'; }
function get_author_posts_url($id) { return 'https://example.test/writers/' . $id . '/'; }
function get_the_title($post = null) { return 'A <test> "title"'; }
function get_the_date($format, $post = null) { return '2026-09-01T12:00:00+00:00'; }
function get_the_modified_date($format, $post = null) { return '2026-09-02T12:00:00+00:00'; }
function get_permalink($post = null) { return 'https://example.test/article/'; }
function has_post_thumbnail($post = null) { return false; }
function get_site_icon_url($size) { return ''; }
function strip_shortcodes($text) { return preg_replace('/\[sample(?:[^\]]*)\](?:.*?\[\/sample\])?/s', '', $text); }
function wp_strip_all_tags($text) {
    return strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $text));
}
function wp_html_excerpt($text, $length, $more = null) {
    $text = wp_strip_all_tags($text);
    $excerpt = mb_substr($text, 0, $length);
    return $excerpt . ($excerpt !== $text ? $more : '');
}
function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function seo_output() {
    ob_start();
    wp_head();
    return ob_get_clean();
}
function seo_meta($output) {
    preg_match('/<meta name="description" content="([^"]*)"\s*\/?>/', $output, $match);
    return isset($match[1]) ? html_entity_decode($match[1], ENT_QUOTES, 'UTF-8') : null;
}
function seo_schema($output) {
    preg_match('@<script type="application/ld\+json">(.*?)</script>@s', $output, $match);
    return isset($match[1]) ? json_decode($match[1], true, 512, JSON_THROW_ON_ERROR) : null;
}
function seo_check($condition, $message) {
    $GLOBALS['seo_checks']++;
    if (!$condition) {
        $GLOBALS['seo_failures']++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

// Constants cannot be undefined: each simulated plugin gets a fresh PHP process.
if (isset($argv[1])) {
    define($argv[1], 'test-version');
    require dirname(__DIR__) . '/inc/schema.php';
    seo_context('post');
    $outputs = array('default' => seo_output());
    $GLOBALS['seo_filters']['zen_meta_description_enabled'][] = static fn() => true;
    $outputs['description'] = seo_output();
    seo_context('post');
    $GLOBALS['seo_filters']['zen_json_ld_enabled'][] = static fn() => true;
    $outputs['schema'] = seo_output();
    seo_context('post');
    $GLOBALS['seo_filters']['zen_seo_plugin_active'][] = static fn() => false;
    $outputs['common'] = seo_output();
    echo json_encode($outputs);
    exit;
}

require dirname(__DIR__) . '/inc/schema.php';
$header = file_get_contents(dirname(__DIR__) . '/header.php');
seo_check(!str_contains($header, 'name="robots"'), 'header delegates robots to native WordPress');
seo_check(str_contains($header, 'wp_head();'), 'header preserves the native head/canonical entrypoint');

seo_context('post', array('excerpt' => " <p>A &amp; B</p>\n [sample]hidden[/sample]\t excerpt&nbsp; text "));
$output = seo_output();
seo_check('A & B excerpt text' === seo_meta($output), 'hook emits a cleaned singular description');
seo_check(seo_meta($output) === (seo_schema($output)['description'] ?? null), 'meta and schema share a clean description');
seo_check(!str_contains($header, 'name="description"'), 'description no longer hardcoded in header');
seo_context('page', array('excerpt' => ' [sample]hidden[/sample] <b> </b> ', 'content' => "<p>Fallback</p>\n body [sample]hidden[/sample] text"));
seo_check('Fallback body text' === seo_meta(seo_output()), 'empty cleaned excerpt falls back to cleaned content');
seo_context('post', array('excerpt' => '', 'content' => ' [sample]hidden[/sample] <p> </p> '));
$output = seo_output();
seo_check(null === seo_meta($output), 'empty descriptions do not emit a meta tag');
seo_check(!array_key_exists('description', seo_schema($output) ?? array()), 'empty schema description omitted');
seo_context('post', array('excerpt' => str_repeat('文', 170)));
seo_check(str_repeat('文', 160) . '…' === seo_meta(seo_output()), 'description length remains bounded in Unicode characters');

foreach (array('post', 'page') as $kind) {
    seo_context($kind, array('protected' => true, 'excerpt' => 'Secret excerpt', 'content' => 'Secret body'));
    $output = seo_output();
    seo_check(null === seo_meta($output), "$kind password content emits no description");
    seo_check(null === seo_schema($output), "$kind password content emits no BlogPosting");
}
seo_context('post');
seo_check('https://example.test/writers/42/' === (seo_schema(seo_output())['author']['url'] ?? null), 'schema uses the native author archive URL');
foreach (array('category', 'tag', 'tax') as $kind) {
    seo_context($kind, array('term' => "<p>Real\n term biography [sample]hidden[/sample]</p>"));
    seo_check('Real term biography' === seo_meta(seo_output()), "$kind uses the actual term description");
    seo_context($kind);
    seo_check(null === seo_meta(seo_output()), "$kind without a description emits no invented text");
}
seo_context('author', array('bio' => "<p>Actual\n author biography.</p>"));
seo_check('Actual author biography.' === seo_meta(seo_output()), 'author archive uses queried author biography');
seo_context('author');
seo_check(null === seo_meta(seo_output()), 'author without biography emits no invented copy');
seo_context('date', array('archive_title' => '<span>月份：</span>2026年9月'));
seo_check('月份：2026年9月' === seo_meta(seo_output()), 'date archive uses its native localized archive title');
seo_context('404');
seo_check(null === seo_meta(seo_output()), '404 omits unrelated site description');
seo_context('home');
seo_check('Quiet Site - A reading journal' === seo_meta(seo_output()), 'home retains actual site name and tagline');
seo_context('home', array('name' => '', 'description' => ''));
seo_check(null === seo_meta(seo_output()), 'empty site metadata emits no empty meta');
seo_context('search');
seo_check('关于“a "quote"”的搜索结果 - Quiet Site' === seo_meta(seo_output()), 'search description safely escapes the query');

foreach (array('WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION') as $constant) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($constant);
    $result = array();
    exec($command, $result, $status);
    seo_check(0 === $status, "$constant simulation process succeeds");
    $outputs = json_decode(implode("\n", $result), true, 512, JSON_THROW_ON_ERROR);
    seo_check('' === $outputs['default'], "$constant owns description and schema by default");
    seo_check(null !== seo_meta($outputs['description']) && null === seo_schema($outputs['description']), "$constant description can be independently re-enabled");
    seo_check(null === seo_meta($outputs['schema']) && null !== seo_schema($outputs['schema']), "$constant schema can be independently re-enabled");
    seo_check(null !== seo_meta($outputs['common']) && null !== seo_schema($outputs['common']), "$constant common ownership can be overridden");
}
seo_context('post');
$GLOBALS['seo_filters']['zen_seo_plugin_active'][] = static fn() => true;
seo_check('' === seo_output(), 'other plugins can claim common metadata ownership');
seo_context('post');
$GLOBALS['seo_filters']['zen_meta_description_enabled'][] = static fn() => false;
$output = seo_output();
seo_check(null === seo_meta($output) && null !== seo_schema($output), 'description can be independently disabled');
seo_context('post');
$GLOBALS['seo_filters']['zen_json_ld_enabled'][] = static fn() => false;
$output = seo_output();
seo_check(null !== seo_meta($output) && null === seo_schema($output), 'schema can be independently disabled');

seo_context('post', array('excerpt' => '&lt;b&gt; &lt;/b&gt;', 'content' => 'Clean fallback'));
seo_check('Clean fallback' === seo_meta(seo_output()), 'entity-encoded empty markup also falls back');
seo_context('post', array('excerpt' => '<script>alert(1)</script><p>Safe "text" &amp; value</p>'));
$output = seo_output();
seo_check('Safe "text" & value' === seo_meta($output), 'description removes scripts and preserves escaped text');
seo_check('Safe "text" & value' === (seo_schema($output)['description'] ?? null), 'JSON-LD description matches escaped metadata');

echo "SEO: {$GLOBALS['seo_checks']} checks, {$GLOBALS['seo_failures']} failures\n";
exit($GLOBALS['seo_failures'] ? 1 : 0);
