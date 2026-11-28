<?php
/**
 * Run: php tests/php-regression.php
 * Executes theme PHP with small, explicit WordPress boundary doubles. No DB,
 * HTTP, core installation or downloaded dependencies. Browser/core acceptance
 * remains separate; list pagination links below are explicit Core boundary fixtures.
 */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
$GLOBALS['zen_test_hooks'] = array();
$GLOBALS['zen_test_options'] = array();
$GLOBALS['zen_test_transients'] = array();
$zen_test_total = 0;
$zen_test_failed = 0;

function zen_test($name, $callback) {
    global $zen_test_total, $zen_test_failed;
    // Optional focused rerun: php tests/php-regression.php 'search|bookmark'.
    if (isset($GLOBALS['argv'][1]) && !preg_match('/' . $GLOBALS['argv'][1] . '/i', $name)) return;
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
// The comments template briefly installs a Core filter around comment_form().
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    if ($hook === 'comment_form_default_fields') $GLOBALS['zen_test_comment_fields_filter'] = $callback;
}
function remove_filter($hook, $callback, $priority = 10) {
    if ($hook === 'comment_form_default_fields') unset($GLOBALS['zen_test_comment_fields_filter']);
}
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
// Identity sanitizer only for trusted fixed fixtures, not a wp_kses security test.
function wp_kses($html, $allowed) { return $html; }
function get_option($key, $default = false) { return $GLOBALS['zen_test_options'][$key] ?? $default; }
function get_transient($key) { return $GLOBALS['zen_test_transients'][$key] ?? false; }
function delete_transient($key) { unset($GLOBALS['zen_test_transients'][$key]); }
function paginate_links($args) {
    throw new RuntimeException('list pagination must not request numbered links');
}
// Trusted prebuilt links exercise theme rendering, not Core routing or URL generation.
function get_previous_posts_link($label = null) {
    $GLOBALS['zen_test_posts_link_calls'][] = array('previous', func_get_args());
    $url = $GLOBALS['zen_test_previous_posts_url'] ?? null;
    return $url === null ? null : '<a href="' . esc_url($url) . '">' . $label . '</a>';
}
function get_next_posts_link($label = null, $max_page = 0) {
    $GLOBALS['zen_test_posts_link_calls'][] = array('next', func_get_args());
    $url = $GLOBALS['zen_test_next_posts_url'] ?? null;
    return $url === null ? null : '<a href="' . esc_url($url) . '">' . $label . '</a>';
}

require ABSPATH . 'inc/template-tags.php';
require ABSPATH . 'inc/options.php';

foreach (array(
    'first' => array(3, null, 'https://example.test/?s=concurrent+links&paged=2'),
    'middle' => array(3, 'https://example.test/?s=concurrent+links', 'https://example.test/?s=concurrent+links&paged=3'),
    'last' => array(3, 'https://example.test/?s=concurrent+links&paged=2', null),
    'single' => array(1, null, null),
    'empty' => array(0, null, null),
) as $state => $fixture) {
    zen_test("list pagination $state renders only available native text links", function () use ($fixture) {
        [$total, $previous, $next] = $fixture;
        $GLOBALS['wp_query'] = (object) array('max_num_pages' => $total);
        $GLOBALS['zen_test_previous_posts_url'] = $previous;
        $GLOBALS['zen_test_next_posts_url'] = $next;
        $GLOBALS['zen_test_posts_link_calls'] = array();
        $html = zen_capture('zen_pagination');
        if ($total < 2) {
            zen_expect(trim($html) === '', 'single or empty list must not render a navigation wrapper');
            return;
        }
        zen_expect(substr_count($html, '<nav ') === 1 && str_contains($html, 'aria-label="分页导航"'), 'one named pagination landmark required');
        zen_expect(substr_count($html, '<a ') === (int) ($previous !== null) + (int) ($next !== null), 'missing directions must be omitted, not replaced by disabled links');
        zen_expect(!preg_match('/page-numbers|aria-current|aria-disabled|screen-reader-text|&hellip;/', $html), 'numbered, disabled or hidden-only pagination remains');
        $calls = $GLOBALS['zen_test_posts_link_calls'];
        zen_expect(array_column($calls, 0) === array('previous', 'next'), 'both directions must use native posts-link helpers');
        zen_expect(count($calls[0][1]) === 1 && count($calls[1][1]) === 1, 'Core must retain current query and page-count ownership');
        foreach (array('prev' => $previous, 'next' => $next) as $direction => $url) {
            $class = 'zen-pagination-' . $direction;
            if ($url === null) {
                zen_expect(!str_contains($html, $class), 'missing direction must not reserve a disabled placeholder');
                continue;
            }
            zen_expect((bool) preg_match('~<div class="' . $class . '">\s*(<a\b[^>]*>.*?</a>)\s*</div>~s', $html, $match), "$direction native link needs a stable alignment slot");
            $link = $match[1];
            $label = $direction === 'prev' ? '上一页' : '下一页';
            $arrow = $direction === 'prev' ? '←' : '→';
            zen_expect(str_contains($link, 'href="' . esc_url($url) . '"'), "$direction query or canonical URL changed");
            zen_expect((bool) preg_match('~<span aria-hidden="true">(?:&[a-z]+;|' . $arrow . ')</span>~', $link), "$direction decorative arrow must be hidden from assistive technology");
            $visible = preg_replace('~<span aria-hidden="true">.*?</span>~s', '', $link);
            zen_expect(trim(strip_tags($visible)) === $label, "$direction must show an ordinary text label");
        }
        if ($previous !== null && $next !== null) {
            zen_expect(strpos($html, 'zen-pagination-prev') < strpos($html, 'zen-pagination-next'), 'previous must precede next in keyboard order');
        }
    });
}

foreach (array('add', 'update', 'delete') as $operation) {
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

zen_test('search highlights decoded text without matching entity syntax or exposing markup', function () {
    foreach (array('&#038;', '&amp;', '&#x26;') as $entity) {
        foreach (array('&' => 1, '038' => 0, 'amp' => 0, 'x26' => 0) as $query => $marks) {
            $html = zen_highlight_search_terms('A ' . $entity . ' B', (string) $query);
            zen_expect(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') === 'A & B', 'visible entity changed');
            zen_expect(substr_count($html, '<mark ') === $marks, 'entity syntax was highlighted');
        }
    }
    $html = zen_highlight_search_terms('&lt;img src=x onerror=alert(1)&gt; &amp;lt;', '<img &lt;');
    zen_expect(!str_contains($html, '<img') && !str_contains($html, '<script'), 'encoded markup became executable');
    zen_expect(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') === '<img src=x onerror=alert(1)> &lt;', 'one-layer decoding changed literal entity text');
});

zen_test('search does not highlight entity escaping introduced only for output', function () {
    $html = zen_highlight_search_terms('&amp;lt; amp', 'amp');
    zen_expect(substr_count($html, '<mark ') === 1, 'output-only amp entity syntax created a false search match');
    zen_expect(str_contains($html, '&amp;lt; <mark'), 'unmatched literal entity-looking text must stay outside the mark');
    zen_expect(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') === '&lt; amp', 'literal entity-looking text changed');
});

zen_test('search preserves uppercase hexadecimal entity-looking text after one-layer decoding', function () {
    foreach (array('amp', '#X41;', 'missing') as $query) {
        $html = zen_highlight_search_terms('Literal &amp;#X41; amp', $query);
        zen_expect(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8') === 'Literal &#X41; amp', 'uppercase hexadecimal literal became a decoded letter');
        zen_expect(substr_count($html, '<mark ') === (int) ($query !== 'missing'), 'literal hexadecimal match count changed');
    }
});

zen_test('search highlighting escapes decoded ampersands exactly once', function () {
    $html = zen_highlight_search_terms('Tom &amp; Jerry', 'Jerry');
    zen_expect(str_contains($html, 'Tom &amp; <mark'), 'ampersand was double-escaped');
    zen_expect(!str_contains($html, '&amp;amp;'), 'double entity escaping leaked to the visitor');
});

zen_test('search keeps Unicode literal regex matches overlap precedence and query bounds', function () {
    $html = zen_highlight_search_terms('中文 C++ [a] WORLD world', '中文 C++ [a] world world');
    zen_expect(substr_count($html, '<mark ') === 5, 'Unicode, regex literal or case matching changed');
    zen_expect(substr_count(zen_highlight_search_terms('world', 'wor world'), '<mark ') === 1, 'longest overlap must win');
    zen_expect(!str_contains(zen_highlight_search_terms('0', str_repeat('x', 301)), '<mark '), 'oversized query should be ignored');
    zen_expect(!str_contains(zen_highlight_search_terms('&amp;', "\xFF"), '<mark '), 'invalid UTF-8 query must fail closed');
    zen_expect(zen_highlight_search_terms('', '&') === '', 'empty title should remain empty');
    $terms = array_map(function ($n) { return 'term' . str_pad((string) $n, 2, '0', STR_PAD_LEFT); }, range(1, 21));
    zen_expect(!str_contains(zen_highlight_search_terms('term21', implode(' ', $terms)), '<mark '), 'query term limit must remain 20');
});

function get_locale() { return 'zh_CN'; }
function get_header() {}
function get_footer() {}
function have_posts() { return empty($GLOBALS['zen_test_loop_done']); }
function the_post() { $GLOBALS['zen_test_loop_done'] = true; }
function get_the_title($post = null) { return 'Fixture title'; }
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
function has_post_thumbnail() { return false; }
function get_the_author() { return ''; }
function get_the_tags() { return false; }
function get_the_modified_date($format = '') { return '2020年1月1日'; }
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
function get_tags($args) { return array(); }
foreach (array('page.php', 'single.php', 'page-links.php', 'page-notes.php', 'page-tags.php') as $template) {
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
                zen_expect($args === array('before' => '<nav class="zen-page-links" aria-label="正文分页">', 'after' => '</nav>'), 'native pagination wrapper changed');
                zen_expect(str_contains($html, '/fixture/2/') === $multipage, 'split navigation visibility mismatch');
            }
        } finally {
            $GLOBALS['zen_test_options'] = $saved_options;
        }
    });
}

function get_bookmarks($args) { return $GLOBALS['zen_test_bookmarks'] ?? array(); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
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
function has_excerpt() { return false; }
function wp_get_current_commenter() {
    $GLOBALS['zen_test_commenter_reads'] = ($GLOBALS['zen_test_commenter_reads'] ?? 0) + 1;
    return array('comment_author' => $GLOBALS['zen_test_commenter_name'] ?? '', 'comment_author_email' => '');
}
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function get_permalink() { return 'https://example.test/fixture/'; }
function wp_logout_url($redirect) { return 'https://example.test/logout/'; }
function wp_get_current_user() { return (object) array('display_name' => 'Fixture'); }
function have_comments() { return false; }
function comment_form($args) {
    $fields = array('author' => '<input id="author">', 'email' => '<input id="email">');
    if (isset($GLOBALS['zen_test_comment_fields_filter'])) $fields = $GLOBALS['zen_test_comment_fields_filter']($fields);
    echo implode('', $args['fields'] ?? $fields) . $args['comment_field'];
}
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

require ABSPATH . 'inc/comments.php';
function get_avatar($comment, $size, $default, $alt, $args) {
    $GLOBALS['zen_test_avatar_calls'][] = array($comment, $size, $default, $alt, $args);
    return '<img class="fixture-avatar" alt="">';
}
function get_comment_reply_link($args, $comment) { return '<a class="fixture-reply" href="#respond">Reply</a>'; }
function comment_ID() { echo '17'; }
function comment_class() { echo 'class="comment"'; }
function get_comment_author() { return 'Fixture reader'; }
function get_comment_link($comment) { return '#comment-17'; }
function get_comment_time(...$args) { return '2020-01-01T12:00:00+00:00'; }
function get_comment_date(...$args) { return '2020.01.01'; }
function comment_text($comment, $args) { echo 'Existing comment'; }

zen_test('approved comment cards skip commenter-cookie lookups', function () {
    $args = array('avatar_size' => 0);
    foreach (array('pending' => '0', 'approved' => '1') as $state => $approved) {
        $comment = (object) array('comment_approved' => $approved, 'comment_type' => 'comment');
        $GLOBALS['zen_test_commenter_name'] = 'Remembered reader';
        $GLOBALS['zen_test_commenter_reads'] = 0;
        try {
            $html = zen_capture(static function () use ($comment, $args) { zen_comment_callback($comment, $args, 1); });
            zen_expect(str_contains($html, 'Existing comment') && str_contains($html, 'fixture-reply'), "$state comment still renders content and reply");
            zen_expect($GLOBALS['zen_test_commenter_reads'] === (int) ($state === 'pending'), "$state comment read the commenter cookie boundary an unexpected number of times");
        } finally {
            unset($GLOBALS['zen_test_commenter_name']);
        }
    }
});

zen_test('comment avatars use native lazy and async attributes without repeating the author name', function () {
    $comment = (object) array('comment_approved' => '1', 'comment_type' => 'comment');
    $GLOBALS['zen_test_avatar_calls'] = array();
    $html = zen_capture(static function () use ($comment) { zen_comment_callback($comment, array('avatar_size' => 48), 1); });
    zen_expect(str_contains($html, 'fixture-avatar') && str_contains($html, '>Fixture reader</h4>'), 'avatar and visible author must still render');
    zen_expect(count($GLOBALS['zen_test_avatar_calls']) === 1, 'one native avatar call');
    [$subject, $size, $default, $alt, $args] = $GLOBALS['zen_test_avatar_calls'][0];
    zen_expect($subject === $comment && $size === 48 && $default === '', 'native avatar subject, size and default preserved');
    zen_expect($alt === '', 'decorative avatar must have empty alt beside the author heading');
    zen_expect(($args['loading'] ?? null) === 'lazy' && ($args['decoding'] ?? null) === 'async', 'request lazy loading and async decoding via the native API');
    zen_capture(static function () use ($comment) { zen_comment_callback($comment, array('avatar_size' => 0), 1); });
    zen_expect(count($GLOBALS['zen_test_avatar_calls']) === 1, 'zero-size request still skips the native avatar');
});

zen_test('links introduction uses existing entry-content wrapping', function () {
    $html = zen_render_template('page-links.php');
    zen_expect((bool) preg_match('/<div class="[^"]*\bentry-content\b[^"]*">\s*<p>Fixture content<\/p>/', $html), 'introduction has no entry-content wrapper');
});

zen_test('archive rows expose scoped wrapping classes', function () {
    $GLOBALS['zen_test_transients'][zen_archive_cache_key()] = array(array(
        'id' => 1, 'title' => str_repeat('LongTitle', 30), 'url' => 'https://example.test/fixture/',
        'year' => '2020', 'date' => '2020-01-02',
    ));
    $html = zen_render_template('page-archives.php');
    zen_expect((bool) preg_match('/<li class="[^"]*\bzen-archive-row\b/', $html), 'archive row class missing');
    zen_expect((bool) preg_match('/<a\b[^>]*class="[^"]*\bzen-archive-link\b/', $html), 'archive link class missing');
});

function current_user_can($capability) { return true; }
function get_admin_page_title() { return 'Zen settings'; }
function settings_errors() {}
function get_settings_errors() { return array(); }
function settings_fields($group) {}
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="fixture">'; }
function wp_create_nonce($action) { return 'fixture-nonce'; }
function submit_button($label) {}
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function checked($checked, $current) {}
function selected($selected, $current) {}
// Admin rendering boundaries: no queries or remote update requests in this test.
function get_posts($args) {
    if (($args['lang'] ?? null) !== '') throw new RuntimeException('Uptime must query posts across languages');
    return array();
}
function home_url($path = '') { return 'https://example.test/' . ltrim($path, '/'); }
function get_categories($args) { return array(); }
function is_wp_error($value) { return false; }
function get_template() { return 'zen'; }
function wp_get_theme($stylesheet = false) {
    return new class {
        public function get($key) { return '1.0.0'; }
    };
}
zen_test('admin footer textarea has fluid width with a desktop cap', function () {
    $html = zen_capture('zen_options_page_html');
    preg_match('/<textarea\b[^>]*id="zen_footer_text"[^>]*class="([^"]*)"/', $html, $field);
    zen_expect(str_contains($field[1] ?? '', 'large-text'), 'footer textarea must retain native fluid control class');
    $style = file_get_contents(ABSPATH . 'assets/css/admin.css');
    zen_expect((bool) preg_match('/(?:^|;)\s*width:\s*100%\s*;/', $style), 'textarea still forces intrinsic 800px width');
    zen_expect((bool) preg_match('/(?:^|;)\s*max-width:\s*800px\s*;/', $style), 'desktop width cap missing');
});

// Keep the summary last when adding a regression case.
printf("%d tests, %d failures\n", $zen_test_total, $zen_test_failed);
exit($zen_test_failed ? 1 : 0);
