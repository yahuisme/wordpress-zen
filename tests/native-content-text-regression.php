<?php
/** Run: ZEN_TEST_ISOLATED=1 php tests/native-content-text-regression.php /isolated/wp-load.php */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) {
    fwrite(STDERR, "Set ZEN_TEST_ISOLATED=1 and pass an isolated wp-load.php path.\n");
    exit(1);
}
$content_native_complete = false;
register_shutdown_function(static function () use (&$content_native_complete) {
    if (!$content_native_complete) {
        fwrite(STDERR, "Native content regression did not complete.\n");
        exit(1);
    }
});
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') {
    throw new RuntimeException('Isolated loopback WordPress required');
}
foreach (array('inc/options.php', 'inc/template-tags.php', 'inc/schema.php', 'inc/enqueue.php', 'single.php') as $file) {
    if (hash_file('sha256', get_template_directory() . '/' . $file) !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/' . $file)) {
        throw new RuntimeException('Fixture source mismatch: ' . $file);
    }
}
add_filter('pre_http_request', static fn() => new WP_Error('isolated', 'Outbound HTTP blocked'), 1);
add_filter('pre_wp_mail', '__return_false');
$total = $failures = 0;
$ids = array();
$saved_options = array();
$saved_cookie = $_COOKIE['wp-postpass_' . COOKIEHASH] ?? null;
function content_native_check($condition, $label) {
    global $total, $failures;
    $total++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failures++;
}
function content_native_post($overrides = array()) {
    global $ids;
    $id = wp_insert_post(array_merge(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Native content fixture', 'post_content' => '<p>Body</p>'), $overrides), true);
    if (is_wp_error($id) || !$id) throw new RuntimeException('Post fixture insertion failed');
    $ids[] = $id;
    return $id;
}
function content_native_query($id) {
    $GLOBALS['wp_query'] = new WP_Query(array('p' => $id));
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post($id);
    setup_postdata($GLOBALS['post']);
    if (!is_singular('post')) throw new RuntimeException('Native singular query fixture failed');
}
function content_native_excerpt() {
    ob_start();
    get_template_part('template-parts/content', 'excerpt');
    $html = ob_get_clean();
    preg_match('~<div class="zen-post-excerpt[^>]*>(.*?)</div>~s', $html, $match);
    if (!isset($match[1])) throw new RuntimeException('Missing list excerpt template');
    return trim($match[1]);
}
function content_native_schema() {
    ob_start();
    do_action('wp_head');
    $html = ob_get_clean();
    preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match);
    if (!isset($match[1])) throw new RuntimeException('Missing native head JSON-LD');
    return array(json_decode($match[1], true, 512, JSON_THROW_ON_ERROR), $match[1]);
}
function content_native_assets($expected, $label) {
    $GLOBALS['wp_scripts'] = new WP_Scripts();
    $GLOBALS['wp_styles'] = new WP_Styles();
    do_action('wp_enqueue_scripts');
    content_native_check(wp_script_is('highlight-js', 'enqueued') === $expected && wp_style_is('highlight-css', 'enqueued') === $expected, $label . ': native enqueue visibility');
    content_native_check(wp_scripts()->registered['zen-main']->deps === array(), $label . ': basic controls never depend on optional highlighting');
    if ($expected) content_native_check(wp_scripts()->get_data('highlight-js', 'strategy') === 'async', $label . ': native optional highlighter is async');
}
function content_native_single() {
    ob_start();
    try {
        include get_template_directory() . '/single.php';
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}
try {
    foreach (array('zen_list_style' => 'standard', 'zen_excerpt_length' => 8, 'zen_show_highlight' => 1, 'zen_show_reading_count' => 1, 'zen_show_reading_time' => 1, 'zen_show_tags' => 1) as $key => $value) {
        $saved_options[$key] = get_option($key, null);
        update_option($key, $value);
        if (get_option($key) != $value) throw new RuntimeException('Option fixture failed: ' . $key);
    }
    foreach (array(
        array('<strong>中文摘要</strong>，手工填写。', '中文摘要，手工填…'),
        array('<strong>English excerpt</strong> manually entered.', 'English…'),
        array('&lt;img src=x onerror=alert(1)&gt; 中文', '&lt;img src…'),
    ) as $case) {
        $id = content_native_post(array('post_excerpt' => $case[0]));
        content_native_query($id);
        content_native_check(get_post($id)->post_excerpt === $case[0], 'manual excerpt fixture retains stored HTML/entities');
        content_native_check(content_native_excerpt() === $case[1], 'native get_the_excerpt to list template: ' . $case[0] . ' => ' . content_native_excerpt());
    }
    $id = content_native_post(array('post_title' => 'A & B "quote"'));
    content_native_query($id);
    $title = get_the_title();
    list($schema) = content_native_schema();
    content_native_check(str_contains($title, '&#038;') || str_contains($title, '&amp;'), 'native title filter supplies HTML entities');
    content_native_check($schema['headline'] === html_entity_decode(wp_strip_all_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'native title becomes plain JSON-LD headline');
    content_native_check(get_the_title() === $title, 'JSON boundary cleanup does not alter public title filters');
    $title_filter = static fn() => '<b>中文 &amp; title</b> &lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt; &quot;double&quot; &#039;single&#039;';
    add_filter('the_title', $title_filter, 99);
    try {
        list($schema, $json) = content_native_schema();
        content_native_check($schema['headline'] === '中文 & title </script><script>alert(1)</script> "double" \'single\'', 'filtered title HTML stripped and one entity layer decoded');
        content_native_check(!str_contains($json, '</script>') && str_contains($json, '\\u003C') && str_contains($json, '\\u0026') && str_contains($json, '\\u0022') && str_contains($json, '\\u0027'), 'native JSON serializer retains all script-safe HEX boundaries');
        content_native_check(str_contains(get_the_title(), '<b>'), 'title HTML cleanup is not installed as a global filter');
    } finally {
        remove_filter('the_title', $title_filter, 99);
    }
    $search = 'Literal HTML <figure> &copy;';
    $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query(array('s' => $search));
    content_native_check(is_search() && get_search_query(false) === $search, 'Core retains literal text in the native search query');
    ob_start();
    try {
        zen_output_meta_description();
        $head = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $tags = new WP_HTML_Tag_Processor($head);
    content_native_check($tags->next_tag('META') && str_starts_with((string) $tags->get_attribute('content'), '关于“' . $search . '”的搜索结果'), 'search metadata preserves literal Core query text after HTML decoding');
    if (function_exists('wp_render_block_visibility_support')) {
        $body = '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->'
            . '<!-- wp:group --><div class="wp-block-group">'
            . '<!-- wp:paragraph {"metadata":{"blockVisibility":false}} --><p>Hidden paragraph</p><!-- /wp:paragraph -->'
            . '<!-- wp:paragraph --><p>Visible group</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
            . '<!-- wp:group {"metadata":{"blockVisibility":false}} --><div class="wp-block-group">'
            . '<!-- wp:paragraph --><p>Hidden subtree</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
            . '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->';
        $id = content_native_post(array('post_content' => $body));
        content_native_query($id);
        $rendered = do_blocks($body);
        content_native_check(!str_contains($rendered, 'Hidden') && str_contains($rendered, 'Visible group'), 'Core test fixture hides the marked blocks');
        list($schema) = content_native_schema();
        content_native_check(zen_meta_description() === 'Before Visible group After' && $schema['description'] === zen_meta_description(), 'meta and JSON-LD exclude hidden block subtrees and keep visible order');
        $escaped_body = str_replace('blockVisibility', 'block\u0056isibility', $body);
        // Save as a native editor allowed to preserve the exact block comment spelling.
        $admins = get_users(array('role' => 'administrator', 'number' => 1));
        $reader = get_current_user_id();
        if (!$admins) throw new RuntimeException('Administrator fixture required');
        wp_set_current_user($admins[0]->ID);
        try {
            if (!current_user_can('unfiltered_html')) throw new RuntimeException('Unfiltered editor fixture required');
            $escaped_id = content_native_post(array('post_content' => wp_slash($escaped_body)));
        } finally {
            wp_set_current_user($reader);
        }
        content_native_query($escaped_id);
        $saved_body = get_post()->post_content;
        content_native_check($saved_body === $escaped_body && !str_contains(do_blocks($saved_body), 'Hidden'), 'native visibility does not depend on the stored JSON spelling');
        list($schema) = content_native_schema();
        content_native_check($schema['description'] === 'Before Visible group After', 'metadata respects parsed visibility attributes regardless of JSON spelling');
        content_native_query(content_native_post(array('post_content' => $body, 'post_excerpt' => 'Manual summary')));
        content_native_check(zen_meta_description() === 'Manual summary', 'manual description remains authoritative');
    }
    $code = '<!-- wp:code --><pre class="wp-block-code"><code>SECRET_NATIVE_CODE</code></pre><!-- /wp:code -->';
    $id = content_native_post(array('post_password' => 'fixture-pass', 'post_content' => $code));
    content_native_query($id);
    unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    content_native_check(post_password_required() && !str_contains(get_the_content(), 'SECRET_NATIVE_CODE'), 'locked content is hidden by native password protection');
    content_native_check(!zen_has_code_blocks(), 'locked article detector short circuits');
    content_native_assets(false, 'locked article');
    $locked_html = content_native_single();
    content_native_check(!str_contains($locked_html, '浏览次数') && !str_contains($locked_html, '预计阅读'), 'locked post hides both reading metrics');
    require_once ABSPATH . WPINC . '/class-phpass.php';
    $hasher = new PasswordHash(8, true);
    $_COOKIE['wp-postpass_' . COOKIEHASH] = $hasher->HashPassword('fixture-pass');
    content_native_check(!post_password_required() && str_contains(get_the_content(), 'SECRET_NATIVE_CODE'), 'actual WordPress password cookie unlocks content without deleting password');
    content_native_check(get_post($id)->post_password === 'fixture-pass' && zen_has_code_blocks(), 'unlocked password article still detects code');
    ob_start();
    zen_output_meta_description();
    zen_json_ld();
    content_native_check(ob_get_clean() === '', 'even a cookie-unlocked article does not emit public metadata');
    content_native_query($id);
    content_native_assets(true, 'cookie-unlocked article');
    $unlocked_html = content_native_single();
    content_native_check(!str_contains($unlocked_html, '浏览次数') && str_contains($unlocked_html, '预计阅读'), 'unlock restores reading time but does not enable tracking');
    unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    $pattern = content_native_post(array('post_type' => 'wp_block', 'post_content' => $code));
    $text_pattern = content_native_post(array('post_type' => 'wp_block', 'post_content' => '<!-- wp:paragraph --><p>Synced text</p><!-- /wp:paragraph -->'));
    $nested_pattern = content_native_post(array('post_type' => 'wp_block', 'post_content' => '<!-- wp:block {"ref":' . $text_pattern . '} /-->'));
    $draft_pattern = content_native_post(array('post_type' => 'wp_block', 'post_status' => 'draft', 'post_content' => '<p>Draft pattern</p>'));
    $protected_pattern = content_native_post(array('post_type' => 'wp_block', 'post_password' => 'fixture-pass', 'post_content' => '<p>Protected pattern</p>'));
    $pattern_markup = '<p>Before</p><!-- wp:block {"ref":' . $nested_pattern . '} /-->'
        . '<!-- wp:block {"ref":' . $draft_pattern . '} /--><!-- wp:block {"ref":' . $protected_pattern . '} /--><p>After</p>';
    content_native_query(content_native_post(array('post_content' => $pattern_markup)));
    $rendered = do_blocks($pattern_markup);
    content_native_check(str_contains($rendered, 'Synced text') && !str_contains($rendered, 'Draft pattern') && !str_contains($rendered, 'Protected pattern'), 'Core renders published nested synced text but not draft/protected patterns');
    list($schema) = content_native_schema();
    content_native_check(zen_meta_description() === 'Before Synced text After' && $schema['description'] === zen_meta_description(), 'article metadata includes public synced text in document order');
    $bound_pattern = content_native_post(array('post_type' => 'wp_block', 'post_content' =>
        '<!-- wp:paragraph {"metadata":{"name":"Body","bindings":{"content":{"source":"core/pattern-overrides"}}}} --><p>Default text</p><!-- /wp:paragraph -->'
        . '<!-- wp:paragraph --><p>Shared text</p><!-- /wp:paragraph -->'));
    $core_block = WP_Block_Type_Registry::get_instance()->get_registered('core/block');
    $native_context = isset($core_block->provides_context['pattern/overrides']);
    $legacy_text = $native_context ? 'Default text' : 'Legacy text';
    $override_cases = array(
        array($bound_pattern, array('content' => array('Body' => array('content' => '<em>Instance</em> &amp; text'))), 'Before Instance & text Shared text After'),
        array($bound_pattern, array('content' => array('Body' => array('content' => ''))), 'Before Shared text After'),
        array($bound_pattern, array('content' => array('Body' => array('values' => array('content' => 'Legacy text')))), 'Before ' . $legacy_text . ' Shared text After'),
        array($bound_pattern, array('content' => array('Other' => array('content' => 'Unrelated text'))), 'Before Default text Shared text After'),
    );
    $nested_bound = content_native_post(array('post_type' => 'wp_block', 'post_content' => '<!-- wp:block {"ref":' . $bound_pattern . '} /-->'));
    $nested_text = $native_context ? 'Default text' : 'Inherited text';
    $override_cases[] = array($nested_bound, array('content' => array('Body' => array('content' => 'Inherited text'))), 'Before ' . $nested_text . ' Shared text After');
    if (version_compare($GLOBALS['wp_version'], '6.6', '>=')) {
        $heading_pattern = content_native_post(array('post_type' => 'wp_block', 'post_content' =>
            '<!-- wp:heading {"metadata":{"name":"Title","bindings":{"__default":{"source":"core/pattern-overrides"}}}} --><h2 class="wp-block-heading">Default heading</h2><!-- /wp:heading -->'));
        $override_cases[] = array($heading_pattern, array('content' => array('Title' => array('content' => 'Instance heading'))), 'Before Instance heading After');
    }
    foreach ($override_cases as [$pattern_id, $attrs, $expected]) {
        $bound_body = '<p>Before</p><!-- wp:block ' . wp_json_encode(array('ref' => $pattern_id) + $attrs, JSON_HEX_TAG) . ' /--><p>After</p>';
        content_native_query(content_native_post(array('post_content' => wp_slash($bound_body))));
        $rendered_text = zen_clean_description(do_blocks(get_post()->post_content));
        content_native_check($rendered_text === $expected, 'Core renders the effective instance text: ' . $expected . ($rendered_text === $expected ? '' : ' (got ' . $rendered_text . ')'));
        list($schema) = content_native_schema();
        ob_start();
        zen_output_meta_description();
        $head = new WP_HTML_Tag_Processor(ob_get_clean());
        content_native_check($head->next_tag('META') && $head->get_attribute('content') === $expected && $schema['description'] === $expected,
            'native meta description and JSON-LD use effective saved text rather than pattern defaults');
    }
    wp_update_post(array('ID' => $text_pattern, 'post_content' => '<p>Synced text</p><!-- wp:block {"ref":' . $nested_pattern . '} /-->'));
    content_native_query(content_native_post(array('post_content' => '<!-- wp:block {"ref":' . $text_pattern . '} /--><!-- wp:block {"ref":' . $text_pattern . '} /-->')));
    content_native_check(zen_meta_description() === 'Synced text Synced text', 'a cyclic reference stops on its branch while intentional repeated patterns remain');
    foreach (array('classic' => '<pre><code>public code</code></pre>', 'core block' => $code, 'synced block' => '<!-- wp:block {"ref":' . $pattern . '} /-->') as $label => $body) {
        $id = content_native_post(array('post_content' => $body));
        content_native_query($id);
        content_native_check(zen_has_code_blocks(), 'public ' . $label . ': native code detection');
        content_native_assets(true, 'public ' . $label);
    }
    update_option('zen_show_highlight', 0);
    content_native_assets(false, 'disabled highlighting');
    update_option('zen_show_highlight', 1);
    content_native_query(content_native_post(array('post_content' => '<!-- wp:preformatted --><pre>Plain text</pre><!-- /wp:preformatted -->')));
    content_native_check(!zen_has_code_blocks(), 'native Preformatted block is not a code block');
    content_native_assets(false, 'preformatted text only');

    $tagged_id = content_native_post(array('post_content' => '<p>Tagged native article body</p>'));
    $test_tag = wp_insert_term('Zen native tag fallback', 'post_tag');
    if (is_wp_error($test_tag)) throw new RuntimeException($test_tag->get_error_message());
    try {
        wp_set_post_tags($tagged_id, array((int) $test_tag['term_id']));
        content_native_query($tagged_id);
        content_native_check(str_contains(content_native_single(), '#Zen native tag fallback'), 'native single renders a real assigned tag');
        $refuse_terms = static function ($terms, $post_id, $taxonomy) use ($tagged_id) {
            return $post_id === $tagged_id && $taxonomy === 'post_tag' ? new WP_Error('fixture', 'Terms unavailable') : $terms;
        };
        add_filter('get_the_terms', $refuse_terms, 10, 3);
        try {
            content_native_query($tagged_id);
            $warnings = array();
            set_error_handler(static function ($severity, $message) use (&$warnings) {
                $warnings[] = $message;
                return true;
            }, E_WARNING);
            try { $html = content_native_single(); }
            finally { restore_error_handler(); }
            content_native_check(str_contains($html, 'Tagged native article body') && !str_contains($html, 'zen-post-taxonomy') && !$warnings, 'native term failure keeps the article usable without broken tag links or warnings');
        } finally { remove_filter('get_the_terms', $refuse_terms, 10); }
    } finally { wp_delete_term($test_tag['term_id'], 'post_tag'); }
} finally {
    foreach (array_reverse($ids) as $id) wp_delete_post($id, true);
    foreach ($saved_options as $key => $value) {
        if (null === $value) delete_option($key);
        else update_option($key, $value);
    }
    if (null === $saved_cookie) unset($_COOKIE['wp-postpass_' . COOKIEHASH]);
    else $_COOKIE['wp-postpass_' . COOKIEHASH] = $saved_cookie;
    wp_reset_postdata();
}
$content_native_complete = true;
printf("NATIVE_CONTENT_COMPLETE %d checks, %d failures; WordPress %s; PHP %s\n", $total, $failures, $GLOBALS['wp_version'], PHP_VERSION);
exit($failures ? 1 : 0);
