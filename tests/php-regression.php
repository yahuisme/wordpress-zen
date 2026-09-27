<?php
/**
 * Run: php tests/php-regression.php
 * Executes theme PHP with small, explicit WordPress boundary doubles. No DB,
 * HTTP, core installation or downloaded dependencies. Browser/core acceptance
 * remains separate; pagination markup below models paginate_links() output.
 */
define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['zen_test_hooks'] = array();
$GLOBALS['zen_test_options'] = array();
$GLOBALS['zen_test_transients'] = array();
$zen_test_total = 0;
$zen_test_failed = 0;

function zen_test($name, $callback) {
    global $zen_test_total, $zen_test_failed;
    $zen_test_total++;
    try {
        $callback();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        $zen_test_failed++;
        echo "FAIL $name: {$error->getMessage()}\n";
    }
}
function zen_expect($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function zen_capture($callback) {
    ob_start();
    try {
        $callback();
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['zen_test_hooks'][$hook][] = $callback;
}
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
// Identity sanitizer only for trusted fixed fixtures, not a wp_kses security test.
function wp_kses($html, $allowed) { return $html; }
function get_option($key, $default = false) { return $GLOBALS['zen_test_options'][$key] ?? $default; }
function get_transient($key) { return $GLOBALS['zen_test_transients'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['zen_test_transients'][$key]); }
function get_pagenum_link($number) { return 'https://example.test/?s=concurrent&paged=' . $number; }
function get_query_var($key) { return 1; }
function paginate_links($args) { return $GLOBALS['zen_test_pages']; }

require ABSPATH . 'inc/template-tags.php';
require ABSPATH . 'inc/options.php';

zen_test('search pagination preserves concurrent links and only marks the current node', function () {
    $GLOBALS['wp_query'] = (object) array('max_num_pages' => 3);
    $GLOBALS['zen_test_pages'] = array(
        '<span aria-current="page" class="page-numbers current">1</span>',
        '<a class="page-numbers" href="https://example.test/?s=concurrent&amp;paged=2">2</a>',
        '<span class="page-numbers dots">&hellip;</span>',
        '<a class="next page-numbers" href="https://example.test/?s=concurrent&amp;paged=3">Next</a>',
    );
    $html = zen_capture('zen_pagination');
    zen_expect(substr_count($html, '<a ') === 2, 'search URLs were converted into non-clickable spans');
    zen_expect(substr_count($html, 'aria-current=') === 1, 'exactly one current page expected');
    zen_expect(str_contains($html, 'dots'), 'ellipsis must remain non-current');
});

foreach (array('add', 'update') as $operation) {
    zen_test("$operation start date invalidates populated uptime cache", function () use ($operation) {
        $GLOBALS['zen_test_transients']['zen_site_uptime'] = array('year' => '2000', 'days' => 100);
        foreach ($GLOBALS['zen_test_hooks'][$operation . '_option_zen_site_start_date'] ?? array() as $callback) {
            $callback();
        }
        zen_expect(false === get_transient('zen_site_uptime'), 'stale uptime survives option hook');
    });
}

zen_test('search highlights zero without losing escaping or duplicate handling', function () {
    $html = zen_highlight_search_terms('<b>0 zero 10</b>', '0 0 zero');
    zen_expect(substr_count($html, '<mark ') === 3, 'zero terms are missing');
    zen_expect(str_contains($html, '&lt;b&gt;') && !str_contains($html, '<b>'), 'title must stay escaped');
    zen_expect(!str_contains(zen_highlight_search_terms('plain', '   '), '<mark'), 'empty query must not highlight');
});

function get_header() {}
function get_footer() {}
function have_posts() { return empty($GLOBALS['zen_test_loop_done']); }
function the_post() { $GLOBALS['zen_test_loop_done'] = true; }
function the_title() { echo 'Fixture title'; }
function the_content() { echo '<p>Fixture content</p>'; }
function comments_open() { return $GLOBALS['zen_test_comments_open'] ?? false; }
function get_comments_number() { return $GLOBALS['zen_test_comment_count'] ?? 0; }
function comments_template() { echo '<section id="fixture-comments"></section>'; }
function zen_render_template($file) {
    $GLOBALS['zen_test_loop_done'] = false;
    return zen_capture(function () use ($file) { include ABSPATH . $file; });
}

foreach (array(array(true, 0, true), array(false, 2, true), array(false, 0, false)) as $state) {
    zen_test('page comments open=' . (int) $state[0] . ' count=' . $state[1], function () use ($state) {
        $GLOBALS['zen_test_comments_open'] = $state[0];
        $GLOBALS['zen_test_comment_count'] = $state[1];
        $html = zen_render_template('page.php');
        zen_expect(str_contains($html, 'fixture-comments') === $state[2], 'conditional comments template was not respected');
    });
}

function __($text, $domain = '') { return $text; }
function esc_attr__($text, $domain = '') { return esc_attr($text); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function get_the_category() { return array(); }
function get_the_date($format = '') { return '2020'; }
function get_month_link($year, $month) { return 'https://example.test/2020/01/'; }
// Verify delegation and wrapper contract; native split routing is an integration test.
function wp_link_pages($args) {
    $GLOBALS['zen_test_link_pages_calls'][] = $args;
    if ($GLOBALS['zen_test_multipage'] ?? false) {
        echo $args['before'] . '<span aria-current="page">1</span><a href="/fixture/2/">2</a>' . $args['after'];
    }
}
foreach (array('page.php', 'single.php', 'page-links.php') as $template) {
    zen_test("$template delegates split navigation to WordPress", function () use ($template) {
        $saved_options = $GLOBALS['zen_test_options'];
        foreach (array('toc', 'reading_count', 'reading_time', 'updated_date', 'tags', 'post_navigation') as $option) {
            $GLOBALS['zen_test_options']['zen_show_' . $option] = 0;
        }
        $GLOBALS['zen_test_options']['zen_copyright_license'] = 'none';
        try {
            foreach (array(true, false) as $multipage) {
                $GLOBALS['zen_test_multipage'] = $multipage;
                $GLOBALS['zen_test_link_pages_calls'] = array();
                $html = zen_render_template($template);
                zen_expect(count($GLOBALS['zen_test_link_pages_calls']) === 1, 'wp_link_pages must be called once');
                $args = $GLOBALS['zen_test_link_pages_calls'][0];
                zen_expect(str_contains($args['before'], '<nav ') && str_contains($args['before'], 'zen-page-links') && str_contains($args['before'], 'aria-label='), 'accessible navigation wrapper missing');
                zen_expect($args['after'] === '</nav>', 'navigation wrapper not closed');
                zen_expect(str_contains($html, '/fixture/2/') === $multipage, 'split navigation visibility mismatch');
            }
        } finally {
            $GLOBALS['zen_test_options'] = $saved_options;
        }
    });
}

function get_bookmarks($args) { return $GLOBALS['zen_test_bookmarks'] ?? array(); }
foreach (array('' => '_self', '_self' => '_self', '_blank' => '_blank', '_parent' => '_parent', '_top' => '_top', 'invalid' => '_blank') as $target => $expected) {
    zen_test("bookmark target '$target' renders as $expected", function () use ($target, $expected) {
        $GLOBALS['zen_test_bookmarks'] = array((object) array(
            'link_rating' => 0, 'link_name' => 'Fixture', 'link_url' => 'https://example.test/friend',
            'link_target' => $target, 'link_image' => '', 'link_description' => 'Description',
        ));
        $html = zen_render_template('page-links.php');
        zen_expect(str_contains($html, 'target="' . $expected . '"'), 'bookmark target changed');
    });
}

function zen_tag_has_attribute($html, $id, $attribute) {
    preg_match('/<(?:input|textarea)\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/', $html, $tag);
    return isset($tag[0]) && (bool) preg_match('/\s' . preg_quote($attribute, '/') . '(?:\s|=|\/?>)/', $tag[0]);
}
function post_password_required() { return false; }
function wp_get_current_commenter() { return array('comment_author' => '', 'comment_author_email' => ''); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_permalink() { return 'https://example.test/fixture/'; }
function wp_logout_url($redirect) { return 'https://example.test/logout/'; }
function wp_get_current_user() { return (object) array('display_name' => 'Fixture'); }
function have_comments() { return false; }
function comment_form($args) { echo implode('', $args['fields']) . $args['comment_field']; }
foreach (array(true, false) as $required) {
    zen_test('comment native required attributes with require_name_email=' . (int) $required, function () use ($required) {
        $GLOBALS['zen_test_comments_open'] = true;
        $GLOBALS['zen_test_options']['require_name_email'] = $required;
        $html = zen_render_template('comments.php');
        foreach (array('author', 'email') as $id) {
            zen_expect(zen_tag_has_attribute($html, $id, 'required') === $required, "$id native required mismatch");
            zen_expect(zen_tag_has_attribute($html, $id, 'aria-required') === $required, "$id aria-required mismatch");
        }
        zen_expect(zen_tag_has_attribute($html, 'comment', 'required'), 'comment textarea must always be required');
    });
}

zen_test('links introduction uses existing entry-content wrapping', function () {
    $html = zen_render_template('page-links.php');
    zen_expect((bool) preg_match('/<div class="[^"]*\bentry-content\b[^"]*">\s*<p>Fixture content<\/p>/', $html), 'introduction has no entry-content wrapper');
});

zen_test('archive rows expose scoped wrapping classes', function () {
    $GLOBALS['zen_test_transients']['zen_archives_public_posts'] = array(array(
        'id' => 1, 'title' => str_repeat('LongTitle', 30), 'url' => 'https://example.test/fixture/',
        'year' => '2020', 'date' => '01-02',
    ));
    $html = zen_render_template('page-archives.php');
    zen_expect((bool) preg_match('/<li class="[^"]*\bzen-archive-row\b/', $html), 'archive row class missing');
    zen_expect((bool) preg_match('/<a\b[^>]*class="[^"]*\bzen-archive-link\b/', $html), 'archive link class missing');
});

function current_user_can($capability) { return true; }
function get_admin_page_title() { return 'Zen settings'; }
function settings_errors() {}
function settings_fields($group) {}
function submit_button($label) {}
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function checked($checked, $current) {}
function selected($selected, $current) {}
function wp_get_theme() {
    return new class {
        public function get($key) { return '1.0.0'; }
    };
}
zen_test('admin footer textarea has fluid width with a desktop cap', function () {
    // Seed the existing update cache: rendering this test never contacts GitHub.
    $GLOBALS['zen_test_transients']['zen_theme_update_info'] = array(
        'latest_version' => '1.0.0', 'url' => 'https://example.test/releases', 'error' => '',
    );
    $html = zen_capture('zen_options_page_html');
    preg_match('/<textarea\b[^>]*id="zen_footer_text"[^>]*style="([^"]*)"/', $html, $field);
    $style = $field[1] ?? '';
    zen_expect((bool) preg_match('/(?:^|;)\s*width:\s*100%\s*;/', $style), 'textarea still forces intrinsic 800px width');
    zen_expect((bool) preg_match('/(?:^|;)\s*max-width:\s*800px\s*;/', $style), 'desktop width cap missing');
});

// Keep the summary last when adding a regression case.
printf("%d tests, %d failures\n", $zen_test_total, $zen_test_failed);
exit($zen_test_failed ? 1 : 0);
