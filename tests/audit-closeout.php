<?php
/** Execute the shipped counter against explicit WordPress boundary doubles. */
define('MINUTE_IN_SECONDS', 60);
require __DIR__ . '/reading-count-storage-double.php';
function add_action(...$args) {}
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
require ABSPATH . 'inc/template-tags.php';
$cache = array(); $counts = array(); $post_id = 42; $logged_in = false;
function zen_get_option($key) { return $key === 'zen_show_reading_count' ? true : ($GLOBALS['footer_options'][$key] ?? ''); }
function is_singular($type) { return true; }
function is_preview() { return false; }
function is_feed() { return false; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function get_queried_object_id() { return $GLOBALS['post_id']; }
function get_post($id) { return (object) array('ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => ''); }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { if (!in_array($ttl, array(300, 3600), true)) throw new RuntimeException('TTL changed'); $GLOBALS['cache'][$key] = $value; }
function get_post_meta($id, $key, $single) { return $GLOBALS['counts'][$id] ?? 0; }
function update_post_meta($id, $key, $value) { $GLOBALS['counts'][$id] = $value; return true; }
$source = file_get_contents(dirname(__DIR__) . '/theme/zen/inc/options.php');
$start = strpos($source, 'function zen_can_count_post(');
$end = strpos($source, 'function zen_ajax_post_view(', $start);
if (false === $start || false === $end) throw new RuntimeException('Counter source boundary not found');
// Execute only the trusted local counter function against WordPress doubles.
eval(substr($source, $start, $end - $start));
$checks = 0;
function check($ok, $label) { global $checks; ++$checks; if (!$ok) throw new RuntimeException($label); }
$_SERVER['REMOTE_ADDR'] = '192.0.2.1'; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
foreach (array('198.51.100.1', '198.51.100.2, 192.0.2.9', 'unknown', array('198.51.100.3')) as $xff) {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff; zen_track_post_view();
}
check(($counts[42] ?? 0) === 1 && count($cache) === 1, 'forged forwarding headers bypass dedupe');
$_SERVER['REMOTE_ADDR'] = '192.0.2.2'; zen_track_post_view();
check($counts[42] === 2, 'different peers must count independently');
$cache = array(); zen_track_post_view();
check($counts[42] === 3, 'expired dedupe bucket must count');
$post_id = 43; zen_track_post_view();
check($counts[43] === 1, 'per-post isolation');
$_SERVER['REMOTE_ADDR'] = '2001:db8::1'; zen_track_post_view();
$_SERVER['REMOTE_ADDR'] = '2001:0db8:0000:0000:0000:0000:0000:0001'; zen_track_post_view();
check($counts[43] === 2, 'equivalent IPv6 spellings must dedupe');
foreach (array('', 'not-an-ip', array('192.0.2.3')) as $invalid) { $_SERVER['REMOTE_ADDR'] = $invalid; zen_track_post_view(); }
unset($_SERVER['REMOTE_ADDR']); zen_track_post_view();
check($counts[43] === 2, 'missing or malformed peer should not count');
$_SERVER['REMOTE_ADDR'] = '192.0.2.10'; $logged_in = true; zen_track_post_view();
check($counts[43] === 2, 'logged-in user excluded');
$logged_in = false; $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1'; zen_track_post_view();
check($counts[43] === 2, 'crawler excluded');
$footer = file_get_contents(dirname(__DIR__) . '/theme/zen/footer.php');
check(str_contains($footer, 'Since <?php') && str_contains($footer, 'Days'), 'English footer design');
check(str_contains($footer, "esc_html($" . "zen_uptime['days'])"), 'dynamic escaped uptime');
$layout = file_get_contents(dirname(__DIR__) . '/theme/zen/assets/css/layout.css');
check((bool) preg_match('/@media \(max-width: 639\.98px\) \{.*?\.zen-site-footer > div \{.*?align-items: center;.*?\.zen-footer-navigation ul \{.*?justify-content: center;/s', $layout), 'mobile footer sections are centered');
$archive = file_get_contents(dirname(__DIR__) . '/theme/zen/page-archives.php');
check(!str_contains($archive, 'preg_match'), 'archive dead date branch');
check(str_contains($archive, 'zen_archives_public_posts_v3'), 'archive ignores old date format cache');
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value) { return esc_html($value); }
function date_i18n($format) { return '2026'; }
function get_bloginfo($key) { return $key === 'rss2_url' ? 'https://example.test/feed/' : '<Fixture>'; }
$cache['zen_site_uptime'] = array('year' => '2022', 'days' => 123);
function has_nav_menu($location) { return $location === 'footer' && !empty($GLOBALS['custom_footer_menu']); }
function wp_nav_menu($args) {
    check($args['theme_location'] === 'footer', 'assigned footer menu location');
    printf($args['items_wrap'] ?? '<ul id="%1$s" class="%2$s">%3$s</ul>', 'fixture-menu', 'menu', '<li><a href="/custom/">Custom</a></li>');
}
define('OBJECT', 'OBJECT');
function get_page_by_path($path, $output, $type) {
    return $path === 'about' && $type === array('page') ? ($GLOBALS['about_page'] ?? null) : null;
}
function wp_footer() {}
ob_start(); require dirname(__DIR__) . '/theme/zen/footer.php'; $html = ob_get_clean();
check(str_contains($html, 'Since 2022 · 123 Days'), 'rendered dynamic English footer');
check(str_contains($html, '&lt;Fixture&gt;'), 'footer escaping retained');
$cache['zen_site_uptime']['days'] = 1;
ob_start(); require dirname(__DIR__) . '/theme/zen/footer.php'; $singular = ob_get_clean();
check(str_contains($singular, 'Since 2022 · 1 Day</div>'), 'singular English uptime');
check(!str_contains($html, '>About</a>'), 'missing About page must not produce a broken link');
$GLOBALS['about_page'] = (object) array('ID' => 77, 'post_status' => 'publish');
ob_start(); require ABSPATH . 'footer.php'; $with_about = ob_get_clean();
check(str_contains($with_about, 'href="/post/77">About</a>'), 'published About page uses its native permalink');
check(!str_contains($with_about, '/feed/'), 'RSS off hides feed link');
$GLOBALS['footer_options']['zen_show_footer_rss'] = 1;
$GLOBALS['footer_options']['zen_footer_text'] = '<a href="/extra/">Extra</a>';
foreach (array(false => '>About</a>', true => '>Custom</a>') as $custom => $expected_menu_item) {
    $GLOBALS['custom_footer_menu'] = $custom;
    ob_start(); require ABSPATH . 'footer.php'; $footer_with_rss = ob_get_clean();
    preg_match('~<nav class="zen-footer-navigation"[^>]*>(.*?)</nav>~s', $footer_with_rss, $nav);
    preg_match_all('~<a\b[^>]*>.*?</a>~s', $nav[1] ?? '', $links);
    check(str_contains($nav[1] ?? '', $expected_menu_item) && str_contains(end($links[0]) ?: '', 'href="https://example.test/feed/"'), 'RSS is the last link in both default and custom footer menus');
    check(substr_count($footer_with_rss, 'href="https://example.test/feed/"') === 1, 'RSS appears exactly once');
    check((bool) preg_match('~<nav class="zen-footer-navigation".*?href="/extra/".*?class="zen-footer-meta\b~s', $footer_with_rss), 'mobile footer source and keyboard order match');
}
unset($GLOBALS['custom_footer_menu'], $GLOBALS['footer_options']);
$GLOBALS['about_page']->post_status = 'private';
ob_start(); require ABSPATH . 'footer.php'; $private_about = ob_get_clean();
check(!str_contains($private_about, '>About</a>'), 'private About page must not be linked');
define('HOUR_IN_SECONDS', 3600);
function get_locale() { return 'zh_CN'; }
function get_header() {}
function get_footer() {}
function __($value, $domain = '') { return $value; }
function post_password_required() { return false; }
function the_title() { echo 'Archive'; }
function get_option($key) { return ''; }
function get_the_title($post = null) { return $post ? 'Post' : 'Archive'; }
function get_permalink($post) { return '/post/' . (is_object($post) ? $post->ID : (int) $post); }
function get_the_date($format, $post) { return $format === 'Y' ? '2026' : '2026-10-05'; }
function esc_attr($value) { return esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function wp_kses_post($value) { return $value; }
function get_pages($args) { return array(); }
function home_url($path) { return 'https://example.test' . $path; }
function wp_reset_postdata() {}
class WP_Query {
    public $posts; public $max_num_pages = 1;
    public function __construct($args) { $this->posts = array((object) array('ID' => 7)); $GLOBALS['archive_queries'] = ($GLOBALS['archive_queries'] ?? 0) + 1; }
}
$cache['zen_archives_public_posts_v2'] = array('zh_CN:' => array(array('id' => 7, 'date' => '2025年4月1日')));
ob_start(); require dirname(__DIR__) . '/theme/zen/page-archives.php'; $html = ob_get_clean();
check(str_contains($html, '>10月 5日</time>') && !str_contains($html, '2025年4月1日'), 'legacy cache safely rebuilt');
ob_start(); require dirname(__DIR__) . '/theme/zen/page-archives.php'; ob_end_clean();
check($GLOBALS['archive_queries'] === 1, 'warm v3 cache avoids rebuild');

echo "PASS audit closeout: $checks checks\n";
