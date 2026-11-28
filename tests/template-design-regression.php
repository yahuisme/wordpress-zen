<?php
/** Execute actual theme templates with explicit WP boundary doubles; no core/DB/browser claim. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
define('HOUR_IN_SECONDS', 3600);
require __DIR__ . '/html-api-bootstrap.php';
$GLOBALS['options'] = array();
$GLOBALS['is_search'] = false;
$failures = 0;
$checks = 0;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function test($name, $callback) {
    global $checks, $failures;
    $checks++;
    try { $callback(); echo "PASS $name\n"; }
    catch (Throwable $e) { $failures++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
function capture($callback) { ob_start(); try { $callback(); return ob_get_contents(); } finally { ob_end_clean(); } }
function render($file, $args = array()) { return capture(function () use ($file, $args) { include ABSPATH . $file; }); }
function add_action(...$args) {}
function add_filter($name, $callback, ...$args) {
    if ($name === 'comment_form_default_fields') $GLOBALS['comment_fields_filter'] = $callback;
}
function remove_filter($name, ...$args) {
    if ($name === 'comment_form_default_fields') unset($GLOBALS['comment_fields_filter']);
}
function apply_filters($name, $value) { return isset($GLOBALS['comment_fields_filter']) && $name === 'comment_form_default_fields' ? $GLOBALS['comment_fields_filter']($value) : $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return str_starts_with($value, 'javascript:') ? '' : esc_html($value); }
function __($value, $domain = '') { return $value; }
function esc_attr__($value, $domain = '') { return esc_attr($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr__($value, $domain); }
function zen_get_option($name) {
    if ($name === 'zen_category_ids') $GLOBALS['category_option_reads']++;
    return $GLOBALS['options'][$name] ?? array('zen_list_style' => 'standard', 'zen_excerpt_length' => 100)[$name] ?? 0;
}
function is_home() { return true; }
function is_front_page() { return $GLOBALS['is_front_page'] ?? false; }
function is_paged() { return $GLOBALS['paged'] ?? false; }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function is_search() { return $GLOBALS['is_search']; }
function the_ID() { echo '7'; }
function post_class($classes) { echo 'class="' . esc_attr($classes) . '"'; }
function get_the_category($post = false) {
    return $GLOBALS['fixture_categories_by_post'][$post] ?? $GLOBALS['fixture_categories'] ?? array();
}
function get_the_date($format = '', $post = null) { return gmdate($format ?: 'Y-m-d', strtotime($post->post_date ?? $GLOBALS['fixture_date'] ?? '2026-10-05')); }
function get_month_link($year, $month) { return '/2026/10/'; }
function the_permalink() { echo '/post/'; }
function get_the_title($post = null) { return $GLOBALS['fixture_title'] ?? '清晰的文章标题'; }
function get_the_ID() { return $GLOBALS['child_excerpt_post_id'] ?? 42; }
function the_title() { echo esc_html(get_the_title()); }
function the_title_attribute() { echo esc_attr(get_the_title()); }
function get_the_excerpt($post = null) {
    $GLOBALS['excerpt_calls']++;
    $GLOBALS['excerpt_posts'][] = is_object($post) ? $post->ID : ($post ?? get_the_ID());
    return $GLOBALS['fixture_excerpt'] ?? '标准列表摘要内容';
}
function wp_strip_all_tags($text, $remove_breaks = false) {
    $text = strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text));
    return trim($remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) : $text);
}
function wp_trim_words($text, $length, $more) { return $text; }
function get_search_query($escaped = true) { return $GLOBALS['query'] ?? '清晰'; }
require ABSPATH . 'inc/template-tags.php';

test('compact list omits excerpt at the WordPress boundary', function () {
    $GLOBALS['options']['zen_list_style'] = 'compact';
    $GLOBALS['excerpt_calls'] = 0;
    $html = render('template-parts/content-excerpt.php');
    check($GLOBALS['excerpt_calls'] === 0, 'compact list still fetches an excerpt');
    check(str_contains($html, 'zen-post-item--compact'), 'compact class missing');
});
test('untitled entries still expose a named reading link', function () {
    $GLOBALS['fixture_title'] = '';
    try {
        $html = render('template-parts/content-excerpt.php');
        check(str_contains($html, '无标题'), 'untitled entry has an empty reading link');
    } finally {
        unset($GLOBALS['fixture_title']);
    }
});
test('standard list has a linked title and bounded excerpt without duplicate continuation or divider', function () {
    $GLOBALS['options']['zen_list_style'] = 'standard';
    $GLOBALS['excerpt_calls'] = 0;
    $html = render('template-parts/content-excerpt.php');
    check(str_contains($html, 'zen-post-item') && str_contains($html, 'zen-post-excerpt'), 'list presentation contract missing');
    check(!str_contains($html, 'line-clamp'), 'rendered excerpt should only be bounded by the saved character limit');
    check((bool) preg_match('/<h2[^>]*>\s*<a[^>]*href="\/post\/"/', $html), 'title link missing');
    check(!str_contains($html, '阅读更多') && !str_contains($html, 'post-divider'), 'duplicate continuation/divider remains');
});

function paginate_links($args) { return array(); }
function get_pagenum_link($number) { return '/?paged=' . $number; }
function get_query_var($name, $default = '') { return $name === 'search_terms' ? array('0') : 1; }
$GLOBALS['wp_query'] = (object) array('max_num_pages' => 1, 'found_posts' => 0);
function get_locale() { return 'zh_CN'; }
function get_header($name = null, $args = array()) {
    if (!empty($GLOBALS['render_fixture_header'])) echo render('header.php', $args);
}
function get_footer() {}
function have_posts() { return !empty($GLOBALS['loop_left']); }
function the_post() {
    $GLOBALS['loop_left']--;
    if (isset($GLOBALS['child_excerpt_ids'])) {
        $GLOBALS['child_excerpt_post_id'] = array_shift($GLOBALS['child_excerpt_ids']);
    }
}
function get_template_part($slug, $name = null, $args = array()) {
    if (isset($GLOBALS['child_excerpt_ids']) && $slug === 'template-parts/content' && $name === 'excerpt') {
        // A pre-existing child template only reads the current Loop post.
        printf('<article id="post-%d" class="child-excerpt"></article>', get_the_ID());
        return;
    }
    echo render($slug . ($name ? '-' . $name : '') . '.php', $args);
}
function get_bloginfo($key) { return array('name' => '站点名称', 'description' => '记录技术与日常')[$key] ?? ''; }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_option($name, $default = false) { return $GLOBALS['native_options'][$name] ?? $default; }
function get_posts($args) { return array(); }
function get_post($id) { return (object) array('ID' => $id, 'post_type' => 'page', 'post_status' => 'publish'); }
function set_transient(...$args) {}
function get_permalink($post = null) { return '/post/' . (is_object($post) ? $post->ID : $post); }
function is_category() { return $GLOBALS['category_archive'] ?? false; }
function get_queried_object_id() { return $GLOBALS['current_category'] ?? 0; }
function get_category_link($id) { return '/category/' . $id . '/'; }
function get_categories($args) {
    $GLOBALS['category_args'] = $args;
    $all = array();
    foreach (range(1, 8) as $id) $all[] = (object) array('term_id' => $id, 'name' => $id === 8 ? '<分类8>' : '分类' . $id, 'count' => $id === 7 ? 0 : 1);
    if (!empty($args['include'])) {
        $all = array_map(function ($id) use ($all) { return $all[$id - 1]; }, $args['include']);
    }
    if (!empty($args['hide_empty'])) $all = array_values(array_filter($all, static fn($category) => $category->count > 0));
    return isset($args['number']) ? array_slice($all, 0, $args['number']) : $all;
}
test('home intro is optional and the index has no category strip', function () {
    $GLOBALS['options']['zen_show_site_intro'] = 1;
    $GLOBALS['options']['zen_category_ids'] = array();
    $GLOBALS['loop_left'] = 0;
    $html = render('index.php');
    check(str_contains($html, '记录技术与日常'), 'native site description missing');
    check(str_contains($html, '<h1 class="zen-site-intro">记录技术与日常</h1>'), 'native tagline must be the cover headline; the wordmark already names the site');
    check(!str_contains($html, '全部篇目'), 'unconfigured annual archive should not have a shortcut');
    check(!str_contains($html, 'zen-category-nav') && !str_contains($html, '分类8'), 'index still renders the category strip');
    check(str_contains($html, 'zen-post-list'), 'shared list wrapper missing');
    $GLOBALS['paged'] = true;
    check(!str_contains(render('index.php'), '记录技术与日常'), 'later index pages repeat the masthead');
    unset($GLOBALS['paged']);
    $GLOBALS['options']['zen_show_site_intro'] = 0;
    check(!str_contains(render('index.php'), '记录技术与日常'), 'disabled description still visible');
});
test('category navigation ignores legacy selections and uses every nonempty category and native posts page', function () {
    $GLOBALS['options']['zen_category_ids'] = array(7, 2, 5);
    $GLOBALS['category_option_reads'] = 0;
    $GLOBALS['native_options'] = array('show_on_front' => 'page', 'page_for_posts' => 42);
    $GLOBALS['category_archive'] = true;
    $GLOBALS['loop_left'] = 0;
    $html = render('archive.php');
    check($GLOBALS['category_option_reads'] === 0, 'frontend still reads the retired category option');
    check($GLOBALS['category_args'] === array('hide_empty' => true), 'navigation must use native nonempty categories without legacy include ordering');
    foreach (array(1, 2, 3, 4, 5, 6, 8) as $id) check(str_contains($html, 'href="/category/' . $id . '/"'), "nonempty category $id missing");
    check(!str_contains($html, 'href="/category/7/"') && str_contains($html, '&lt;分类8&gt;'), 'empty categories must stay absent and category names must be escaped');
    check(strpos($html, '分类1') < strpos($html, '分类2') && str_contains($html, 'href="/post/42"'), 'native category ordering or posts URL lost');
    check($GLOBALS['options']['zen_category_ids'] === array(7, 2, 5), 'rendering rewrote the legacy category row');
    check(str_contains($html, 'zen-category-track') && str_contains($html, 'zen-category-prev') && str_contains($html, 'zen-category-next'), 'frontend category rail controls were removed');
    $GLOBALS['category_archive'] = false;
});
function wp_date($format) { return $format === 'Y' ? '2026' : ''; }
test('current-year index dates use two lines without changing older dates', function () {
    $conditions = array(
        array(true, '2026-08-24', '<span>8月</span> <span>24日</span>'),
        array(true, '2025-12-15', '2025.12.15'),
        array(false, '2026-08-04', '<span>8月</span> <span>4日</span>'),
        array(false, '2025-12-15', '2025.12.15'),
    );
    try {
        foreach ($conditions as [$front, $date, $display]) {
            $GLOBALS['is_front_page'] = $front;
            $GLOBALS['fixture_date'] = $date . 'T12:00:00Z';
            $html = render('template-parts/content-excerpt.php', array('editorial_index' => true));
            check(str_contains($html, 'datetime="' . $date . 'T12:00:00+00:00">' . $display . '</time>'), ($front ? 'homepage' : 'separate posts page') . ' date mismatch: ' . $date);
        }
    } finally {
        unset($GLOBALS['is_front_page'], $GLOBALS['fixture_date']);
    }
});
test('published date is compact Chinese and field cannot wrap internally', function () {
    $html = capture('zen_posted_on_link');
    check(str_contains($html, '2026.10.05') && str_contains($html, '2026年10月5日'), 'visible date and accessible label must use their intended formats');
    check(str_contains($html, 'zen-meta-field'), 'nowrap meta field contract missing');
});
test('editorial dates retain older years; a later sticky position cannot pick an older card', function () {
    $GLOBALS['options']['zen_date_style'] = 'classic';
    check(zen_post_date_format('list', '2025') === 'Y.m.d' && zen_post_date_format('list', '2026') === 'n月 j日' && zen_post_date_format('directory') === 'n月 j日', 'legacy date setting must not override the new contextual format');
    unset($GLOBALS['options']['zen_date_style']);
    $older = (object) array('post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_date' => '2026-10-01 12:00:00', 'ID' => 4);
    $newer = clone $older;
    $newer->ID = 7;
    $newer->post_date = '2026-10-05 12:00:00';
    $locked = clone $newer;
    $locked->post_date = '2026-10-06 12:00:00';
    $locked->post_password = 'test';
    check(zen_get_latest_index_post(array($older, $locked, $newer)) === $newer, 'card selection follows sticky ordering or includes password-protected posts');
    check(zen_get_latest_index_post(array($locked)) === null, 'no eligible post should render no card');
});

test('latest card keeps a single-line date followed by its own category link', function () {
    $previous_query = $GLOBALS['wp_query'];
    $previous_options = $GLOBALS['options'];
    $GLOBALS['options']['zen_show_latest_card'] = 1;
    $GLOBALS['options']['zen_show_site_intro'] = 1;
    // The featured card renders before the main Loop and must use its explicit post.
    $GLOBALS['fixture_date'] = '2024-03-02T12:00:00Z';
    $GLOBALS['fixture_categories_by_post'] = array(7 => array((object) array('term_id' => 2, 'name' => '阅读 & 记录')));
    $GLOBALS['loop_left'] = 0;
    try {
        foreach (array('2026' => '11月 9日', '2025' => '2025.11.09') as $year => $display) {
            $latest = (object) array('ID' => 7, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_date' => $year . '-11-09 12:00:00');
            $GLOBALS['wp_query'] = (object) array('posts' => array($latest), 'post_count' => 1, 'found_posts' => 1, 'max_num_pages' => 1);
            $html = render('index.php');
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new DOMXPath($dom);
            $dates = $xpath->query('//article[@class="zen-latest-card"]//time');
            check($dates->length === 1, 'card must expose exactly one date');
            $date = $dates->item(0);
            check($date instanceof DOMElement, 'card date must be a time element');
            check($date->textContent === $display && $xpath->query('./*', $date)->length === 0, 'card needs a single unsplit date: ' . $year);
            check($date->getAttribute('datetime') === $year . '-11-09T12:00:00+00:00', 'card must retain its complete machine-readable date');
            $date_link = $date->parentNode;
            check($date_link instanceof DOMElement && str_contains($date_link->getAttribute('aria-label'), $year . '年11月9日'), 'card date must expose its full date to assistive technology');
            $category = $xpath->query('following-sibling::a[1][contains(@class,"zen-meta-category")]', $date_link)->item(0);
            check($category instanceof DOMElement && $category->textContent === '阅读 & 记录' && $category->getAttribute('href') === get_category_link(2), 'card date must be followed by the featured post category link');
            check(str_contains(capture(static function () use ($latest) { zen_index_post_meta('full', $latest); }), '阅读 &amp; 记录'), 'ordinary metadata must retain its category');
            check(str_contains($html, '最近发布 ' . $year . '.11.09'), 'masthead date is independent of the card');
            check(str_contains(zen_post_date_html('full', $latest), '>' . $year . '.11.09</time>'), 'article detail dates must keep the year');
        }
        unset($GLOBALS['fixture_categories_by_post']);
        check(!str_contains(render('index.php'), 'zen-meta-category'), 'uncategorized card has no empty category link');
        $GLOBALS['options']['zen_show_latest_card'] = 0;
        $html = render('index.php');
        check(str_contains($html, '最近发布 2025.11.09') && !str_contains($html, 'zen-latest-card'), 'disabling the card must not remove the masthead publication date');
    } finally {
        $GLOBALS['wp_query'] = $previous_query;
        $GLOBALS['options'] = $previous_options;
        unset($GLOBALS['fixture_date'], $GLOBALS['fixture_categories_by_post']);
    }
});

test('compact mode hides only list excerpts while the latest card keeps its explicit post excerpt', function () {
    $previous_query = $GLOBALS['wp_query'];
    $previous_options = $GLOBALS['options'];
    $older = (object) array('ID' => 42, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_date' => '2026-10-01 12:00:00');
    $latest = clone $older;
    $latest->ID = 7;
    $latest->post_date = '2026-11-09 12:00:00';
    $GLOBALS['wp_query'] = (object) array('posts' => array($older, $latest), 'post_count' => 2, 'found_posts' => 2, 'max_num_pages' => 1);
    $GLOBALS['options']['zen_show_latest_card'] = 1;
    try {
        foreach (array('compact', 'standard') as $style) {
            $GLOBALS['options']['zen_list_style'] = $style;
            $GLOBALS['options']['zen_excerpt_length'] = 4;
            $GLOBALS['loop_left'] = 1;
            $GLOBALS['excerpt_posts'] = array();
            $html = render('index.php');
            $dom = new DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new DOMXPath($dom);
            $card_excerpt = $xpath->query('//article[@class="zen-latest-card"]//div[contains(@class,"zen-post-excerpt")]');
            check($card_excerpt->length === 1 && $card_excerpt->item(0)->textContent === '标准列表…', 'latest card must retain its bounded excerpt in ' . $style . ' mode');
            $list_excerpts = $xpath->query('//div[@class="zen-post-list"]//div[contains(@class,"zen-post-excerpt")]');
            check($list_excerpts->length === ($style === 'compact' ? 0 : 1), 'only ordinary list excerpts should follow ' . $style . ' mode');
            check($GLOBALS['excerpt_posts'] === ($style === 'compact' ? array(7) : array(7, 42)), 'card must fetch its explicit post without fetching hidden list excerpts');
            $GLOBALS['fixture_excerpt'] = '';
            $GLOBALS['loop_left'] = 0;
            check(!str_contains(render('index.php'), 'zen-post-excerpt'), 'empty card excerpt must not leave a wrapper');
            unset($GLOBALS['fixture_excerpt']);
        }
    } finally {
        $GLOBALS['wp_query'] = $previous_query;
        $GLOBALS['options'] = $previous_options;
        $GLOBALS['loop_left'] = 0;
        unset($GLOBALS['excerpt_posts'], $GLOBALS['fixture_excerpt']);
    }
});

test('legacy child excerpt renders only ordinary Loop posts with an older sticky first', function () {
    $older = (object) array('post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_date' => '2026-10-01 12:00:00', 'ID' => 4);
    $newer = clone $older;
    $newer->ID = 7;
    $newer->post_date = '2026-10-05 12:00:00';
    $previous_query = $GLOBALS['wp_query'];
    $GLOBALS['wp_query'] = (object) array('posts' => array($older, $newer), 'post_count' => 2, 'max_num_pages' => 1);
    $GLOBALS['options']['zen_show_latest_card'] = 1;
    $GLOBALS['loop_left'] = 2;
    $GLOBALS['child_excerpt_post_id'] = 4;
    $GLOBALS['child_excerpt_ids'] = array(4, 7);
    try {
        $html = render('index.php');
        check(str_contains($html, '<article id="post-7" class="zen-latest-card"'), 'latest article was consumed by the child excerpt override');
        check(substr_count($html, '<article id="post-4" class="child-excerpt"') === 1, 'the sticky post should appear once in the child list');
        check(!str_contains($html, '<article id="post-7" class="child-excerpt"'), 'latest article repeats in the child list');
    } finally {
        $GLOBALS['wp_query'] = $previous_query;
        unset($GLOBALS['child_excerpt_post_id'], $GLOBALS['child_excerpt_ids'], $GLOBALS['options']['zen_show_latest_card']);
    }
});

function the_content() { echo '<p>正文内容</p>'; }
function wp_link_pages($args) { $GLOBALS['split_calls'][] = $args; }
function comments_open() { return $GLOBALS['comments_open'] ?? false; }
function get_comments_number() { return 0; }
function comments_template($file = '/comments.php') {
    if ($file === '/comments.php') {
        echo '<section id="native-comments"></section>';
        return;
    }
    echo render(ltrim($file, '/'));
}
function get_the_modified_date($format = '') { return get_the_date($format); }
function get_the_author() { return $GLOBALS['author'] ?? ''; }
function get_the_tags() { return $GLOBALS['tags'] ?? false; }
function get_tag_link($id) { return '/tag/' . $id; }
function get_previous_post() { return $GLOBALS['previous'] ?? null; }
function get_next_post() { return $GLOBALS['next'] ?? null; }
function has_post_thumbnail() { return $GLOBALS['has_thumbnail'] ?? false; }
function the_post_thumbnail($size, $attr = array()) {
    $GLOBALS['thumbnail_calls'][] = array($size, $attr);
    echo '<img class="' . esc_attr($attr['class'] ?? '') . '" width="1200" height="800" src="/real.jpg" srcset="/real.jpg 1200w, /real-small.jpg 600w">';
}
function number_format_i18n($value) { return number_format($value); }
function zen_get_reading_count() { return 12; }
function zen_show_reading_count() { return (bool) zen_get_option('zen_show_reading_count'); }
function zen_get_reading_time() { return 3; }
function zen_copyright_licenses() { return array('cc-by-4.0' => array('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/deed.zh-hans')); }
function single_fixture() {
    $GLOBALS['options'] = array('zen_show_toc' => 1, 'zen_show_featured_image' => 1, 'zen_show_updated_date' => 1, 'zen_copyright_license' => 'cc-by-4.0', 'zen_show_tags' => 1, 'zen_show_post_navigation' => 1, 'zen_show_reading_count' => 1);
    $GLOBALS['loop_left'] = 1;
    $GLOBALS['has_thumbnail'] = true;
    $GLOBALS['thumbnail_calls'] = array();
    $GLOBALS['split_calls'] = array();
    return render('single.php');
}
test('single title leads metadata and uses native featured image in a shared reading shell', function () {
    $html = single_fixture();
    check(strpos($html, '<h1') < strpos($html, 'zen-post-meta'), 'title is not before metadata');
    check(str_contains($html, 'zen-reading-shell'), 'shared reading width wrapper missing');
    check(count($GLOBALS['thumbnail_calls']) === 1 && $GLOBALS['thumbnail_calls'][0][0] === 'large', 'native large featured image not delegated');
    check(str_contains($html, 'zen-featured-image') && str_contains($html, 'srcset='), 'native featured image presentation missing');
    check(str_contains($html, '浏览次数') && !str_contains($html, '12 阅读'), 'counter meaning is misleading');
    check(count($GLOBALS['split_calls']) === 1, 'native split navigation must remain');
    $GLOBALS['options']['zen_show_featured_image'] = 0;
    $GLOBALS['loop_left'] = 1;
    check(!str_contains(render('single.php'), '/real.jpg'), 'disabled featured image shown');
});
test('table of contents keeps drawer and desktop IDs without a duplicate article trigger', function () {
    $html = single_fixture();
    foreach (array('toc-container', 'toc-nav', 'toc-overlay', 'drawer-toc', 'drawer-toc-title', 'drawer-toc-close', 'drawer-toc-nav', 'post-content') as $id) check(str_contains($html, 'id="' . $id . '"'), 'missing TOC contract ' . $id);
    check(!str_contains($html, 'id="floating-toc-btn"'), 'article header still has a duplicate TOC trigger');
    check(!str_contains($html, 'style="left:') && !str_contains($html, '--zen-content-half'), 'TOC still relies on inline global-wide offset');
});
test('single consolidates notes hides absent author and tags and stacks adjacent labels over titles', function () {
    $GLOBALS['author'] = '';
    $GLOBALS['tags'] = false;
    $GLOBALS['previous'] = (object) array('ID' => 6);
    $GLOBALS['next'] = (object) array('ID' => 8);
    $html = single_fixture();
    check(substr_count($html, 'class="zen-article-notes') === 1, 'notes are not consolidated');
    check(!str_contains($html, '本文作者') && !str_contains($html, '暂无标签') && !str_contains($html, 'zen-post-taxonomy'), 'absent content rendered');
    check(str_contains($html, '最后更新：<time class="zen-post-date"') && str_contains($html, '>2026.10.05</time>'), 'updated date not consistent');
    check(str_contains($html, 'zen-post-nav-label') && str_contains($html, 'zen-post-nav-title'), 'adjacent posts need separate label/title lines');
    check(!str_contains($html, 'mt-12'), 'repeated giant section gaps remain');
    $GLOBALS['author'] = '实际作者';
    $GLOBALS['options']['zen_copyright_license'] = 'none';
    $GLOBALS['loop_left'] = 1;
    $html = render('single.php');
    check(!str_contains($html, '版权声明') && str_contains($html, '实际作者'), 'license none or independent author visibility wrong');
});

function language_attributes() { echo 'lang="zh-CN"'; }
function esc_js($value) { return addslashes($value); }
function wp_head() {}
function body_class($classes) { echo 'class="' . esc_attr($classes) . '"'; }
function wp_body_open() {}
function has_nav_menu($location) { return $GLOBALS['has_menu'] ?? false; }
function wp_nav_menu($args) { $GLOBALS['menu_calls'][] = $args; echo '<ul><li><a href="/about/">关于</a></li></ul>'; }
function get_avatar_url($email) { return 'https://gravatar.example/admin'; }
function has_custom_logo() { return !empty($GLOBALS['logo']); }
function get_theme_mod($key) { return $GLOBALS['logo'] ?? 0; }
function wp_get_attachment_image($id, $size, $icon = false, $attr = array()) {
    $GLOBALS['logo_calls'][] = array($id, $size, $attr);
    return '<img width="200" height="80" alt="' . esc_attr($attr['alt'] ?? '') . '" class="' . esc_attr($attr['class'] ?? '') . '" src="/logo.png">';
}
function is_singular($type = '') { return ($GLOBALS['singular_type'] ?? '') === $type || ($type === '' && !empty($GLOBALS['singular_type'])); }
test('header uses native custom logo without admin Gravatar or nested home links', function () {
    $GLOBALS['logo'] = 91;
    $GLOBALS['logo_calls'] = array();
    $GLOBALS['has_menu'] = false;
    $html = render('header.php');
    check((bool) preg_match('/<script\s+nowprocket>\s*\(function\(\)/', $html), 'initial theme mode uses the supported WP Rocket delay exclusion');
    check(!str_contains($html, 'gravatar'), 'admin email avatar leaks into identity');
    check(count($GLOBALS['logo_calls']) === 1 && $GLOBALS['logo_calls'][0][0] === 91, 'native attachment logo missing');
    check(($GLOBALS['logo_calls'][0][2]['sizes'] ?? '') === '(max-width: 639.98px) 56px, 120px', 'logo responsive sizes must match its actual display limits');
    check(str_contains($html, 'alt="站点名称"'), 'logo alt does not describe site');
    check(substr_count($html, 'class="zen-site-brand') === 1 && !preg_match('/<a[^>]*class="zen-site-brand[^>]*>(?:(?!<\/a>).)*<a/s', $html), 'brand must have one nonnested home link');
    $GLOBALS['logo'] = 0;
    $html = render('header.php');
    check(!str_contains($html, '/logo.png') && str_contains($html, '站点名称'), 'no-logo title fallback missing');
});
test('header omits the assigned primary menu and retains search and article controls', function () {
    $GLOBALS['has_menu'] = true;
    $GLOBALS['menu_calls'] = array();
    $html = render('header.php', array('zen_show_toc' => true));
    check(!$GLOBALS['menu_calls'] && !str_contains($html, 'mobile-menu'), 'assigned menu still rendered');
    check(str_contains($html, 'id="search-fallback"') && str_contains($html, 'id="theme-toggle"'), 'tools disappeared with the primary menu');
    check((bool) preg_match('/<button[^>]*id="header-toc-btn"[^>]*\bhidden[^>]*>/', $html), 'prepared header TOC control missing');
    check(!str_contains(render('header.php'), 'id="header-toc-btn"'), 'header TOC without a template drawer');
});

test('a custom post type using single.php gets the same directory control as the drawer', function () {
    $previous_options = $GLOBALS['options'];
    $GLOBALS['render_fixture_header'] = true;
    $GLOBALS['singular_type'] = 'fixture_entry';
    try {
        $html = single_fixture();
        check(substr_count($html, 'id="drawer-toc"') === 1, 'expected the single-template drawer');
        check(substr_count($html, 'id="header-toc-btn"') === 1, 'missing single-template header trigger on custom post type');
        $GLOBALS['options']['zen_show_toc'] = 0;
        $GLOBALS['loop_left'] = 1;
        $html = render('single.php');
        check(!str_contains($html, 'id="header-toc-btn"') && !str_contains($html, 'id="drawer-toc"'), 'disabled TOC should emit neither trigger nor drawer');
    } finally {
        $GLOBALS['options'] = $previous_options;
        unset($GLOBALS['render_fixture_header'], $GLOBALS['singular_type']);
    }
});

function get_search_form() { echo '<form role="search" action="https://example.test/" method="get"><input type="search" name="s" value="' . esc_attr(get_search_query(false)) . '"><button>搜索</button></form>'; }
function get_pages($args) { $GLOBALS['page_args'] = $args; return $GLOBALS['archive_pages'] ?? array(); }
function get_page_by_path($path, $output = 'OBJECT', $post_type = 'page') { return null; }
function status_header($status) { $GLOBALS['status'] = $status; }
function wp_kses_post($html) { return $html; }
function is_tag() { return $GLOBALS['tag_archive'] ?? false; }
function is_author() { return false; }
function is_date() { return false; }
function is_day() { return false; }
function is_month() { return false; }
function is_year() { return false; }
function single_cat_title($prefix, $display) { return '分类名称'; }
function single_tag_title($prefix, $display) { return '标签名称'; }
function the_archive_title(...$args) { echo '归档'; }
function get_the_archive_description() { return ''; }
test('blank native search renders only the form even if WordPress has Loop posts', function () {
    $GLOBALS['archive_pages'] = array((object) array('ID' => 19));
    foreach (array('', '  ') as $query) {
        $GLOBALS['query'] = $query;
        $GLOBALS['loop_left'] = 1;
        $html = render('search.php');
        check(substr_count($html, 'name="s"') === 1, 'native search form missing');
        check(!str_contains($html, 'zen-post-list') && !str_contains($html, '找到') && !str_contains($html, '浏览归档'), 'blank search shows results or empty-state recovery');
        check($GLOBALS['loop_left'] === 1, 'blank search consumes the main Loop');
    }
});
test('search edits current query and archive links only identify an actual published archive', function () {
    $GLOBALS['query'] = '0 <script>';
    $GLOBALS['archive_pages'] = array();
    $GLOBALS['loop_left'] = 0;
    $html = render('search.php');
    check(str_contains($html, 'name="s"') && str_contains($html, 'value="0 &lt;script&gt;"'), 'inline editable escaped query missing');
    check(!str_contains($html, '浏览归档'), 'nonexistent archive is presented as a real destination');
    check(str_contains($html, 'zen-post-list'), 'search list wrapper missing');
    $GLOBALS['archive_pages'] = array((object) array('ID' => 19));
    $html = render('search.php');
    check(str_contains($html, 'href="/post/19"') && str_contains($html, '浏览归档'), 'real archive link missing');
    check(($GLOBALS['page_args']['post_status'] ?? '') === 'publish', 'private/draft archive must not be offered');
});
test('search treats a literal zero as a keyword and retains result highlighting', function () {
    $GLOBALS['query'] = '0';
    $GLOBALS['fixture_title'] = '第0章';
    $GLOBALS['loop_left'] = 1;
    try {
        $html = render('search.php');
        check(str_contains($html, 'value="0"') && str_contains($html, '<mark'), 'search query or title highlighting was lost');
    } finally {
        unset($GLOBALS['fixture_title']);
    }
});
test('404 preserves HTTP status and offers search home and real archive only', function () {
    $GLOBALS['archive_pages'] = array();
    $GLOBALS['status'] = 404;
    $html = render('404.php');
    check($GLOBALS['status'] === 404, '404 status changed');
    check(str_contains($html, '<h1') && str_contains($html, 'href="https://example.test/?s="') && str_contains($html, '返回首页'), '404 recovery missing');
    check(!str_contains($html, '数字的海洋') && !str_contains($html, '浏览归档'), 'unhelpful copy or false archive destination');
    $GLOBALS['archive_pages'] = array((object) array('ID' => 19));
    check(str_contains(render('404.php'), 'href="/post/19"'), 'published archive recovery missing');
});
test('category and tag lists share the index date-only metadata', function () {
    $GLOBALS['fixture_categories'] = array((object) array('term_id' => 2, 'name' => '文章分类'));
    $GLOBALS['fixture_date'] = '2026-08-24T12:00:00Z';
    try {
        foreach (array('category_archive', 'tag_archive') as $context) {
            $GLOBALS[$context] = true;
            $GLOBALS['loop_left'] = 1;
            $html = render('archive.php');
            check(str_contains($html, 'zen-post-list--editorial'), 'taxonomy list must use the same date-column layout');
            check(str_contains($html, '<span>8月</span> <span>24日</span>'), 'taxonomy date must match the current-year index date');
            check(!str_contains($html, 'zen-meta-category'), 'taxonomy rows must not repeat a category beneath the date');
            $GLOBALS[$context] = false;
        }
        $GLOBALS['loop_left'] = 1;
        $html = render('archive.php');
        check(!str_contains($html, 'zen-post-list--editorial') && str_contains($html, 'zen-meta-category'), 'unrelated archive metadata must remain unchanged');
    } finally {
        unset($GLOBALS['fixture_categories'], $GLOBALS['fixture_date'], $GLOBALS['category_archive'], $GLOBALS['tag_archive']);
    }
});
test('archive uses shared list and category navigation without changing category URLs', function () {
    $GLOBALS['category_archive'] = true;
    $GLOBALS['current_category'] = 2;
    $GLOBALS['options']['zen_category_ids'] = array(7, 2, 5);
    $GLOBALS['loop_left'] = 1;
    $html = render('archive.php');
    check(str_contains($html, 'zen-post-list') && str_contains($html, 'zen-post-item'), 'archive list contract missing');
    check(str_contains($html, '/category/2/') && str_contains($html, 'aria-current="page"'), 'category navigation destination/current state missing');
    $GLOBALS['category_archive'] = false;
});

test('empty archives recover to the posts index, annual directory or static home truthfully', function () {
    $saved_options = $GLOBALS['native_options'];
    $saved_pages = $GLOBALS['archive_pages'];
    $GLOBALS['tag_archive'] = true;
    $GLOBALS['loop_left'] = 0;
    try {
        foreach (array(
            array('posts', 0, 19, 'https://example.test/', '查看全部文章'),
            array('page', 42, 19, '/post/42', '查看全部文章'),
            array('page', 0, 19, '/post/19', '浏览归档'),
            array('page', 0, 0, 'https://example.test/', '返回首页'),
        ) as [$front, $posts, $directory, $url, $label]) {
            $GLOBALS['native_options'] = array('show_on_front' => $front, 'page_for_posts' => $posts);
            $GLOBALS['archive_pages'] = $directory ? array((object) array('ID' => $directory)) : array();
            check(str_contains(render('archive.php'), 'href="' . $url . '" class="zen-ui-link">' . $label . '</a>'), 'wrong recovery target or label for ' . $label);
        }
    } finally {
        $GLOBALS['native_options'] = $saved_options;
        $GLOBALS['archive_pages'] = $saved_pages;
        unset($GLOBALS['tag_archive']);
    }
});

function post_password_required() { return $GLOBALS['locked_page'] ?? false; }
test('guestbook renders native open comments, an explicit closed state, and no UI behind a password', function () {
    $GLOBALS['comments_open'] = true;
    $GLOBALS['loop_left'] = 1;
    check(str_contains(render('page-notes.php'), 'id="native-comment-form"'), 'open guestbook does not load native comments');
    $GLOBALS['comments_open'] = false;
    $GLOBALS['loop_left'] = 1;
    check(str_contains(render('page-notes.php'), '目前不接受留言'), 'empty closed guestbook looks broken');
    $GLOBALS['locked_page'] = $GLOBALS['comments_open'] = true;
    $GLOBALS['loop_left'] = 1;
    $html = render('page-notes.php');
    check(!str_contains($html, 'zen-notes-board') && !str_contains($html, '目前不接受留言'), 'protected guestbook exposed comment UI');
    unset($GLOBALS['locked_page'], $GLOBALS['comments_open']);
});
function has_excerpt() { return !empty($GLOBALS['article_excerpt']); }
function wp_get_current_commenter() { return array('comment_author' => '', 'comment_author_email' => ''); }
function admin_url($path) { return '/wp-admin/' . $path; }
function wp_logout_url($path) { return '/logout/'; }
function wp_get_current_user() { return (object) array('display_name' => '测试用户'); }
function have_comments() { return $GLOBALS['has_comments'] ?? false; }
function wp_list_comments($args) { $GLOBALS['comment_list_args'] = $args; echo '<li id="native-comment">已发布评论 <a class="comment-reply-link" href="?replytocom=1">回复</a></li>'; }
function get_comment_pages_count() { return 2; }
function previous_comments_link($text) { echo '<a href="?cpage=1">' . $text . '</a>'; }
function next_comments_link($text) { echo '<a href="?cpage=2">' . $text . '</a>'; }
function comment_form($args) {
    $GLOBALS['comment_form_args'] = $args;
    $fields = apply_filters('comment_form_default_fields', array('author' => '<input id="author">', 'email' => '<input id="email">'));
    echo '<form id="native-comment-form">' . implode('', $args['fields'] ?? $fields) . $args['comment_field'] . '</form>';
}
test('existing comments precede native form while reply pagination and required semantics remain', function () {
    $GLOBALS['has_comments'] = true;
    $GLOBALS['comments_open'] = true;
    $GLOBALS['native_options']['page_comments'] = 1;
    $GLOBALS['native_options']['require_name_email'] = 1;
    $html = render('comments.php');
    check(strpos($html, 'id="native-comment"') < strpos($html, 'id="native-comment-form"'), 'comment form precedes existing comments');
    check(str_contains($html, 'zen-reading-shell'), 'comments reading width missing');
    check(str_contains($html, '?replytocom=1') && str_contains($html, '?cpage=2'), 'native reply or pagination output lost');
    check(($GLOBALS['comment_list_args']['callback'] ?? '') === 'zen_comment_callback', 'comment callback contract changed');
    check(!isset($GLOBALS['comment_list_args']['reverse_top_level']) && !str_contains($html, 'data-zen-directory-batch'), 'article comments unexpectedly use guestbook ordering or disclosure');
    foreach (array('author', 'email', 'comment') as $id) check((bool) preg_match('/<(?:input|textarea)[^>]*id=[\'\"]' . $id . '[\'\"][^>]*\brequired\b/', $html), 'required field missing ' . $id);
    $GLOBALS['comments_open'] = false;
    $html = render('comments.php');
    check(str_contains($html, 'native-comment') && !str_contains($html, 'native-comment-form'), 'closed comments must preserve list without a form');
});
test('guestbook places one native form before existing comments and uses distinct copy', function () {
    $GLOBALS['comments_open'] = $GLOBALS['has_comments'] = true;
    $GLOBALS['loop_left'] = 1;
    $html = render('page-notes.php');
    $form = strpos($html, 'id="native-comment-form"');
    $first = strpos($html, 'id="native-comment"');
    check($form !== false && $first !== false && $form < $first, 'guestbook must show the native form before existing comments');
    check(substr_count($html, 'id="native-comment-form"') === 1, 'guestbook must not duplicate the form');
    check(($GLOBALS['comment_form_args']['title_reply'] ?? '') === '写留言', 'guestbook form title must be 写留言');
    check(($GLOBALS['comment_form_args']['label_submit'] ?? '') === '提交留言', 'guestbook button copy missing');
    check(str_contains($html, '留言内容') && str_contains($html, '邮箱不会公开'), 'guestbook field label or email privacy notice missing');
    check(str_contains($html, '?replytocom=1'), 'guestbook must keep native replies');
    check(($GLOBALS['comment_list_args']['reverse_top_level'] ?? null) === true, 'guestbook must show newer threads first');
    check(str_contains($html, 'id="zen-notes-entries"') && str_contains($html, 'hidden aria-controls="zen-notes-entries" data-zen-directory-batch="20"'), 'guestbook must reveal twenty threads at a time without hiding the native list in PHP');
    unset($GLOBALS['comments_open'], $GLOBALS['has_comments']);
});
test('native page uses same reading wrapper and split navigation', function () {
    $GLOBALS['loop_left'] = 1;
    $GLOBALS['split_calls'] = array();
    $html = render('page.php');
    check(str_contains($html, 'zen-reading-shell') && str_contains($html, 'zen-article-header'), 'page reading hierarchy missing');
    check(count($GLOBALS['split_calls']) === 1 && str_contains($html, 'entry-content'), 'native content/split contract lost');
});
function get_bookmarks($args) { return $GLOBALS['bookmarks'] ?? array(); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
test('friend links label new-window destinations and only show explicitly saved images', function () {
    $GLOBALS['loop_left'] = 1;
    $GLOBALS['bookmarks'] = array((object) array('link_name' => '友站', 'link_target' => '_blank', 'link_url' => 'https://friend.test/', 'link_image' => '', 'link_rating' => 0, 'link_description' => '简介'));
    $html = render('page-links.php');
    check(str_contains($html, 'aria-label="友站 (在新窗口打开)"'), 'new-window accessible name missing');
    check(!str_contains($html, 'zen-link-avatar') && str_contains($html, '>friend.test</bdi>'), 'image-free link should show destination without a placeholder avatar');
    check((bool) preg_match('/<div class="zen-reading-shell">\s*<div class="zen-directory-intro entry-content">/', $html), 'links introduction must preserve its own reading shell');
    $GLOBALS['loop_left'] = 1;
    $GLOBALS['bookmarks'][0]->link_image = 'https://friend.test/avatar.png';
    check(str_contains(render('page-links.php'), 'src="https://friend.test/avatar.png"'), 'saved bookmark image no longer renders');
});
test('friend links use an empty state when every saved URL is unrenderable', function () {
    $GLOBALS['loop_left'] = 1;
    $GLOBALS['bookmarks'][0]->link_url = 'javascript:example';
    $html = render('page-links.php');
    check(str_contains($html, '暂未添加友情链接。') && !str_contains($html, 'zen-links-entries'), 'unrenderable bookmarks must not leave an empty list');
    unset($GLOBALS['bookmarks']);
});
function get_tags($args) { return $GLOBALS['directory_tags'] ?? array(); }
function is_wp_error($value) { return false; }
function get_the_terms($post, $taxonomy) { return $GLOBALS['directory_post_terms'][$post->ID] ?? array(); }
class WP_Query {
    public $posts = array();
    public function __construct($args) {
        if (!isset($GLOBALS['directory_query_batches'])) return;
        $GLOBALS['directory_query_calls'][] = $args;
        $this->posts = array_shift($GLOBALS['directory_query_batches']) ?? array();
    }
    public function get($key) { return $GLOBALS['directory_query_vars'][$key] ?? ($key === 'posts_per_page' ? 100 : ''); }
}
test('tags use the effective batch size while narrowing the pending term set', function () {
    $common = (object) array('term_id' => 30, 'name' => 'Common', 'count' => 2);
    $rare = (object) array('term_id' => 31, 'name' => 'Rare', 'count' => 1);
    $common_post = (object) array('ID' => 7, 'post_date' => '2025-02-03');
    $older_common = (object) array('ID' => 8, 'post_date' => '2025-01-03');
    $rare_post = (object) array('ID' => 9, 'post_date' => '2024-01-03');
    $GLOBALS['directory_tags'] = array($common, $rare);
    $GLOBALS['directory_post_terms'] = array(7 => array($common), 8 => array($common), 9 => array($rare));
    try {
        foreach (array(false, true) as $unpaged) {
            $GLOBALS['directory_query_vars'] = array('posts_per_page' => 2, 'nopaging' => $unpaged);
            $GLOBALS['directory_query_batches'] = $unpaged ? array(array($common_post, $older_common, $rare_post)) : array(array($common_post, $older_common), array($rare_post));
            $GLOBALS['directory_query_calls'] = array();
            $GLOBALS['loop_left'] = 1;
            $html = render('page-tags.php');
            $calls = $GLOBALS['directory_query_calls'];
            check(str_contains($html, 'datetime="2025-02-03"'), 'newest common preview is missing');
            check(str_contains($html, 'datetime="2024-01-03"'), 'rare preview after a full filtered batch is missing');
            check(count($calls) === ($unpaged ? 1 : 2), 'wrong number of tag queries');
            if (!$unpaged) check($calls[1]['paged'] === 1 && $calls[1]['tax_query'][0]['terms'] === array(31), 'second query must restart after narrowing to the rare tag');
        }
    } finally {
        unset($GLOBALS['directory_tags'], $GLOBALS['directory_post_terms'], $GLOBALS['directory_query_vars'], $GLOBALS['directory_query_batches'], $GLOBALS['directory_query_calls']);
    }
});
test('tags and friend links only offer more when the list exceeds twenty entries', function () {
    foreach (array(20, 21) as $count) {
        $GLOBALS['directory_tags'] = $GLOBALS['bookmarks'] = array();
        for ($i = 1; $i <= $count; $i++) {
            $GLOBALS['directory_tags'][] = (object) array('term_id' => $i, 'name' => 'Tag ' . $i, 'count' => 1);
            $GLOBALS['bookmarks'][] = (object) array('link_name' => 'Link ' . $i, 'link_url' => 'https://example.test/' . $i, 'link_target' => '', 'link_image' => '', 'link_description' => '', 'link_rating' => 0);
        }
        foreach (array('tags' => 'zen-tag-item', 'links' => 'zen-link-card') as $kind => $item_class) {
            $GLOBALS['loop_left'] = 1;
            $html = render('page-' . $kind . '.php');
            check(substr_count($html, 'class="' . $item_class . '"') === $count, "$kind must retain all entries in HTML");
            check(str_contains($html, 'class="zen-directory-more"') === ($count > 20), "$kind shows the wrong threshold for $count entries");
            if ($count > 20) check(str_contains($html, 'aria-controls="zen-' . $kind . '-entries" data-zen-directory-batch="20"'), "$kind must reveal twenty items at a time");
        }
    }
    unset($GLOBALS['directory_tags'], $GLOBALS['bookmarks']);
});
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
test('archive directory has one open disclosure per year and static month headings', function () {
    $GLOBALS['transients'][zen_archive_cache_key()] = array(
        array('id' => 8, 'url' => '/post/8', 'title' => '同月的另一篇文章', 'year' => '2026', 'date' => '2026-10-19'),
        array('id' => 7, 'url' => '/post/7', 'title' => '归档文章', 'year' => '2026', 'date' => '2026-10-05'),
        array('id' => 6, 'url' => '/post/6', 'title' => '其他月份的文章', 'year' => '2026', 'date' => '2026-09-20'),
        array('id' => 5, 'url' => '/post/5', 'title' => '上一年的文章', 'year' => '2025', 'date' => '2025-12-31'),
        array('id' => 4, 'url' => '/post/4', 'title' => '更早月份的文章', 'year' => '2025', 'date' => '2025-10-01'),
        array('id' => 3, 'url' => '/post/3', 'title' => '更早一年的文章', 'year' => '2024', 'date' => '2024-11-01'),
    );
    $html = render('page-archives.php');
    check(str_contains($html, 'zen-post-list') && str_contains($html, 'zen-post-item'), 'archive directory list contract missing');
    check(str_contains($html, '>10月 5日</time>'), 'cached archive dates are not unified');
    preg_match_all('/<details class="zen-archive-group"([^>]*)>/', $html, $years);
    check(count($years[1]) === 3 && array_filter($years[1], static fn($attributes) => trim($attributes) !== 'open') === array(), 'every year must start open');
    check(substr_count($html, '<summary class="zen-archive-year-head"') === 3, 'each year must have exactly one disclosure');
    check(substr_count($html, 'class="zen-archive-collapse"') === 3 && substr_count($html, 'class="zen-archive-expand"') === 3, 'toggle copy belongs to years, not months');
    check(substr_count($html, '<section class="zen-archive-month"') === 5 && !str_contains($html, '<details class="zen-archive-month"'), 'month headings must not be separate disclosures');
    check((bool) preg_match('/3 篇<\/span>\s*<span aria-hidden="true">\/<\/span>\s*<span class="zen-archive-toggle"/', $html), 'year count and toggle must be adjacent with a slash');
    check(substr_count($html, 'class="zen-archive-year"') === 3 && substr_count($html, 'class="zen-archive-row') === 6, 'all older archive rows and year groups must remain present');
    check(str_contains($html, '其他月份的文章') && str_contains($html, '上一年的文章') && str_contains($html, '更早月份的文章'), 'month grouping must preserve article titles');
});
test('archive directory keeps short and empty archives intact', function () {
    $rows = array(
        array('id' => 7, 'url' => '/post/7', 'title' => '归档文章', 'year' => '2026', 'date' => '2026-10-05'),
        array('id' => 6, 'url' => '/post/6', 'title' => '上一年的文章', 'year' => '2025', 'date' => '2025-12-31'),
    );
    foreach (array(2, 1, 0) as $count) {
        $GLOBALS['transients'][zen_archive_cache_key()] = array_slice($rows, 0, $count);
        $html = render('page-archives.php');
        check(substr_count($html, '<details class="zen-archive-group" open>') === $count, 'every available year must open');
        check(substr_count($html, '<section class="zen-archive-month"') === $count, 'no empty month group may be invented');
        check(str_contains($html, '暂无文章。') === ($count === 0), 'empty archives must retain their empty state');
    }
});

test('archive directory progressively reveals older years without hiding the no-script content', function () {
    foreach (array(11, 3, 2, 1, 0) as $year_count) {
        $rows = array();
        for ($index = 0; $index < $year_count; $index++) {
            $year = (string) (2026 - $index);
            $rows[] = array('id' => $index + 1, 'url' => '/post/' . ($index + 1), 'title' => 'Article ' . $year, 'year' => $year, 'date' => $year . '-10-01');
        }
        $GLOBALS['transients'][zen_archive_cache_key()] = $rows;
        $html = render('page-archives.php');
        check(substr_count($html, 'class="zen-archive-group"') === $year_count, 'every year must stay available in the server HTML');
        check(!preg_match('/<details class="zen-archive-group"[^>]*\bhidden\b/', $html), 'no-script archives must not hide older years');
        check(str_contains($html, 'id="zen-archive-years"'), 'archive year container needs a stable control target');
        check(str_contains($html, 'class="zen-archive-more" hidden') === ($year_count > 2), 'only archives with more than two years need an initially hidden enhancement control');
        check(str_contains($html, 'aria-controls="zen-archive-years"') === ($year_count > 2), 'older-years control must identify its controlled content');
        check(substr_count($html, 'class="zen-archive-row') === $year_count, 'progressive display must not remove old articles');
    }
});

printf("%d tests, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
