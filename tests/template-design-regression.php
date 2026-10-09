<?php
/** Execute actual theme templates with explicit WP boundary doubles; no core/DB/browser claim. */
define('ABSPATH', dirname(__DIR__) . '/');
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
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function __($value, $domain = '') { return $value; }
function esc_attr__($value, $domain = '') { return esc_attr($value); }
function zen_get_option($name) { return $GLOBALS['options'][$name] ?? array('zen_list_style' => 'standard', 'zen_excerpt_length' => 100)[$name] ?? 0; }
function is_home() { return true; }
function is_search() { return $GLOBALS['is_search']; }
function the_ID() { echo '7'; }
function post_class($classes) { echo 'class="' . esc_attr($classes) . '"'; }
function get_the_category() { return array(); }
function get_the_date($format = '', $post = null) { return date($format ?: 'Y-m-d', strtotime('2026-10-05')); }
function get_month_link($year, $month) { return '/2026/10/'; }
function the_permalink() { echo '/post/'; }
function get_the_title($post = null) { return '清晰的文章标题'; }
function the_title() { echo esc_html(get_the_title()); }
function the_title_attribute() { echo esc_attr(get_the_title()); }
function get_the_excerpt() { $GLOBALS['excerpt_calls']++; return '标准列表摘要内容'; }
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
test('standard list has a linked title and bounded excerpt without duplicate continuation or divider', function () {
    $GLOBALS['options']['zen_list_style'] = 'standard';
    $GLOBALS['excerpt_calls'] = 0;
    $html = render('template-parts/content-excerpt.php');
    check(str_contains($html, 'zen-post-item') && str_contains($html, 'zen-post-excerpt'), 'list presentation contract missing');
    check(str_contains($html, 'line-clamp-3'), 'three-line excerpt contract missing');
    check((bool) preg_match('/<h2[^>]*>\s*<a[^>]*href="\/post\/"/', $html), 'title link missing');
    check(!str_contains($html, '阅读更多') && !str_contains($html, 'post-divider'), 'duplicate continuation/divider remains');
});

function paginate_links($args) { return array(); }
function get_pagenum_link($number) { return '/?paged=' . $number; }
function get_query_var($name) { return 1; }
$GLOBALS['wp_query'] = (object) array('max_num_pages' => 1, 'found_posts' => 0);
function get_header() {}
function get_footer() {}
function have_posts() { return !empty($GLOBALS['loop_left']); }
function the_post() { $GLOBALS['loop_left']--; }
function get_template_part($slug, $name = null, $args = array()) { echo render($slug . ($name ? '-' . $name : '') . '.php', $args); }
function get_bloginfo($key) { return array('name' => '站点名称', 'description' => '记录技术与日常')[$key] ?? ''; }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_option($name, $default = false) { return $GLOBALS['native_options'][$name] ?? $default; }
function get_permalink($post = null) { return '/post/' . (is_object($post) ? $post->ID : $post); }
function is_category() { return $GLOBALS['category_archive'] ?? false; }
function get_queried_object_id() { return $GLOBALS['current_category'] ?? 0; }
function get_category_link($id) { return '/category/' . $id . '/'; }
function get_categories($args) {
    $GLOBALS['category_args'] = $args;
    $all = array();
    foreach (range(1, 8) as $id) $all[] = (object) array('term_id' => $id, 'name' => '分类' . $id);
    if (!empty($args['include'])) {
        $all = array_map(function ($id) use ($all) { return $all[$id - 1]; }, $args['include']);
    }
    return isset($args['number']) ? array_slice($all, 0, $args['number']) : $all;
}
test('home intro is optional and complete categories are directly visible', function () {
    $GLOBALS['options']['zen_show_site_intro'] = 1;
    $GLOBALS['options']['zen_category_ids'] = array();
    $GLOBALS['loop_left'] = 0;
    $html = render('index.php');
    check(str_contains($html, '记录技术与日常'), 'native site description missing');
    check(!str_contains($html, '<details') && !str_contains($html, '<summary') && str_contains($html, '分类8'), 'categories must be visible without expanding a control');
    preg_match('/<nav class="zen-category-nav"[^>]*>(.*?)<\/nav>/s', $html, $nav);
    check(substr_count($nav[1] ?? '', '<a ') === 9, 'all eight categories plus all-posts link must render');
    check(!str_contains($html, '面包屑'), 'category choices are not breadcrumbs');
    check(str_contains($html, 'zen-post-list'), 'shared list wrapper missing');
    check(($GLOBALS['category_args']['hide_empty'] ?? null) === true, 'only nonempty categories should be requested by default');
    $GLOBALS['options']['zen_show_site_intro'] = 0;
    check(!str_contains(render('index.php'), '记录技术与日常'), 'disabled description still visible');
});
test('selected category order is preserved and all uses native posts page', function () {
    $GLOBALS['options']['zen_category_ids'] = array(7, 2, 5);
    $GLOBALS['native_options'] = array('show_on_front' => 'page', 'page_for_posts' => 42);
    $html = render('index.php');
    check(strpos($html, '分类7') < strpos($html, '分类2') && strpos($html, '分类2') < strpos($html, '分类5'), 'selected order changed');
    check(!str_contains($html, '分类1') && str_contains($html, 'href="/post/42"'), 'selection or native posts URL lost');
});
test('published date is compact Chinese and field cannot wrap internally', function () {
    $html = capture('zen_posted_on_link');
    check(str_contains($html, '2026年10月5日'), 'date format is inconsistent');
    check(str_contains($html, 'zen-meta-field'), 'nowrap meta field contract missing');
});

function the_content() { echo '<p>正文内容</p>'; }
function wp_link_pages($args) { $GLOBALS['split_calls'][] = $args; }
function comments_open() { return $GLOBALS['comments_open'] ?? false; }
function get_comments_number() { return 0; }
function comments_template() { echo '<section id="native-comments"></section>'; }
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
function zen_get_reading_time() { return 3; }
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
test('table of contents keeps JS ids with local text trigger and no inline wide positioning', function () {
    $html = single_fixture();
    foreach (array('toc-container', 'toc-nav', 'floating-toc-btn', 'toc-overlay', 'drawer-toc', 'drawer-toc-title', 'drawer-toc-close', 'drawer-toc-nav', 'post-content') as $id) check(str_contains($html, 'id="' . $id . '"'), 'missing TOC contract ' . $id);
    preg_match('/<button[^>]*id="floating-toc-btn"[^>]*>(.*?)<\/button>/s', $html, $trigger);
    check(!str_contains($trigger[0] ?? '', 'fixed') && str_contains($trigger[1] ?? '', '目录'), 'trigger remains viewport-floating rather than inline text');
    check(!str_contains($html, 'style="left:') && !str_contains($html, '--zen-content-half'), 'TOC still relies on inline global-wide offset');
    check(strpos($html, 'id="floating-toc-btn"') < strpos($html, 'id="post-content"'), 'TOC trigger must be before content');
});
test('single consolidates notes hides absent author and tags and stacks adjacent labels over titles', function () {
    $GLOBALS['author'] = '';
    $GLOBALS['tags'] = false;
    $GLOBALS['previous'] = (object) array('ID' => 6);
    $GLOBALS['next'] = (object) array('ID' => 8);
    $html = single_fixture();
    check(substr_count($html, 'class="zen-article-notes') === 1, 'notes are not consolidated');
    check(!str_contains($html, '本文作者') && !str_contains($html, '暂无标签') && !str_contains($html, 'zen-post-taxonomy'), 'absent content rendered');
    check(str_contains($html, '最后更新：2026年10月5日'), 'updated date not consistent');
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
function is_singular($type = '') { return $GLOBALS['singular_post'] ?? false; }
test('header uses native custom logo without admin Gravatar or nested home links', function () {
    $GLOBALS['logo'] = 91;
    $GLOBALS['logo_calls'] = array();
    $GLOBALS['has_menu'] = false;
    $html = render('header.php');
    check(!str_contains($html, 'gravatar'), 'admin email avatar leaks into identity');
    check(count($GLOBALS['logo_calls']) === 1 && $GLOBALS['logo_calls'][0][0] === 91, 'native attachment logo missing');
    check(str_contains($html, 'alt="站点名称"'), 'logo alt does not describe site');
    check(substr_count($html, 'class="zen-site-brand') === 1 && !preg_match('/<a[^>]*class="zen-site-brand[^>]*>(?:(?!<\/a>).)*<a/s', $html), 'brand must have one nonnested home link');
    $GLOBALS['logo'] = 0;
    $html = render('header.php');
    check(!str_contains($html, '/logo.png') && str_contains($html, '站点名称'), 'no-logo title fallback missing');
});
test('header hides unconfigured mobile menu and exposes single-level noscript links and prepared TOC control', function () {
    $GLOBALS['has_menu'] = false;
    $html = render('header.php');
    check(!str_contains($html, 'id="mobile-menu-btn"') && !str_contains($html, 'id="mobile-menu"'), 'empty mobile navigation visible');
    $GLOBALS['has_menu'] = true;
    $GLOBALS['menu_calls'] = array();
    $GLOBALS['singular_post'] = true;
    $GLOBALS['options']['zen_show_toc'] = 1;
    $html = render('header.php');
    check(str_contains($html, '<noscript>') && str_contains($html, 'zen-noscript-nav'), 'no-JS mobile navigation missing');
    foreach ($GLOBALS['menu_calls'] as $args) check($args['depth'] === 1 && $args['fallback_cb'] === false, 'single-level native menu contract lost');
    check((bool) preg_match('/<button[^>]*id="header-toc-btn"[^>]*\bhidden[^>]*>/', $html), 'prepared header TOC control missing');
    $GLOBALS['singular_post'] = false;
    check(!str_contains(render('header.php'), 'id="header-toc-btn"'), 'header TOC on nonpost');
});

function get_search_form() { echo '<form role="search" action="https://example.test/" method="get"><input type="search" name="s" value="' . esc_attr(get_search_query(false)) . '"><button>搜索</button></form>'; }
function get_pages($args) { $GLOBALS['page_args'] = $args; return $GLOBALS['archive_pages'] ?? array(); }
function status_header($status) { $GLOBALS['status'] = $status; }
function wp_kses_post($html) { return $html; }
function is_tag() { return false; }
function is_author() { return false; }
function is_date() { return false; }
function is_day() { return false; }
function is_month() { return false; }
function is_year() { return false; }
function single_cat_title($prefix, $display) { return '分类名称'; }
function the_archive_title(...$args) { echo '归档'; }
function get_the_archive_description() { return ''; }
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
test('404 preserves HTTP status and offers search home and real archive only', function () {
    $GLOBALS['archive_pages'] = array();
    $GLOBALS['status'] = 404;
    $html = render('404.php');
    check($GLOBALS['status'] === 404, '404 status changed');
    check(str_contains($html, '<h1') && str_contains($html, 'name="s"') && str_contains($html, '返回首页'), '404 recovery missing');
    check(!str_contains($html, '数字的海洋') && !str_contains($html, '浏览归档'), 'unhelpful copy or false archive destination');
    $GLOBALS['archive_pages'] = array((object) array('ID' => 19));
    check(str_contains(render('404.php'), 'href="/post/19"'), 'published archive recovery missing');
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

function post_password_required() { return false; }
function wp_get_current_commenter() { return array('comment_author' => '', 'comment_author_email' => ''); }
function admin_url($path) { return '/wp-admin/' . $path; }
function wp_logout_url($path) { return '/logout/'; }
function wp_get_current_user() { return (object) array('display_name' => '测试用户'); }
function have_comments() { return $GLOBALS['has_comments'] ?? false; }
function wp_list_comments($args) { $GLOBALS['comment_list_args'] = $args; echo '<li id="native-comment">已发布评论 <a class="comment-reply-link" href="?replytocom=1">回复</a></li>'; }
function get_comment_pages_count() { return 2; }
function previous_comments_link($text) { echo '<a href="?cpage=1">' . $text . '</a>'; }
function next_comments_link($text) { echo '<a href="?cpage=2">' . $text . '</a>'; }
function comment_form($args) { $GLOBALS['comment_form_args'] = $args; echo '<form id="native-comment-form">' . implode('', $args['fields']) . $args['comment_field'] . '</form>'; }
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
    foreach (array('author', 'email', 'comment') as $id) check((bool) preg_match('/<(?:input|textarea)[^>]*id=[\'\"]' . $id . '[\'\"][^>]*\brequired\b/', $html), 'required field missing ' . $id);
    $GLOBALS['comments_open'] = false;
    $html = render('comments.php');
    check(str_contains($html, 'native-comment') && !str_contains($html, 'native-comment-form'), 'closed comments must preserve list without a form');
});
test('native page uses same reading wrapper and split navigation', function () {
    $GLOBALS['loop_left'] = 1;
    $GLOBALS['split_calls'] = array();
    $html = render('page.php');
    check(str_contains($html, 'zen-reading-shell') && str_contains($html, 'zen-article-header'), 'page reading hierarchy missing');
    check(count($GLOBALS['split_calls']) === 1 && str_contains($html, 'entry-content'), 'native content/split contract lost');
});
function get_bookmarks($args) { return $GLOBALS['bookmarks'] ?? array(); }
test('friend links label new-window destinations and keep image-free initial fallback', function () {
    $GLOBALS['loop_left'] = 1;
    $GLOBALS['bookmarks'] = array((object) array('link_name' => '友站', 'link_target' => '_blank', 'link_url' => 'https://friend.test/', 'link_image' => '', 'link_rating' => 0, 'link_description' => '简介'));
    $html = render('page-links.php');
    check(str_contains($html, 'aria-label="友站 (在新窗口打开)"'), 'new-window accessible name missing');
    check(str_contains($html, 'zen-link-avatar') && !str_contains($html, 'ph-arrow-up-right'), 'initial fallback or stable iconless layout missing');
    check(str_contains($html, 'zen-reading-shell'), 'links introduction/shared comments width missing');
});
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
test('archive directory has shared list item rhythm and unified Chinese dates', function () {
    $GLOBALS['transients']['zen_archives_public_posts'] = array(array('id' => 7, 'url' => '/post/7', 'title' => '归档文章', 'year' => '2026', 'date' => '10-05'));
    $html = render('page-archives.php');
    check(str_contains($html, 'zen-post-list') && str_contains($html, 'zen-post-item'), 'archive directory list contract missing');
    check(str_contains($html, '2026年10月5日'), 'cached legacy archive dates are not unified');
    check(str_contains($html, 'zen-reading-shell') && !str_contains($html, '时光机'), 'archive shell or concise copy missing');
});

printf("%d tests, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
