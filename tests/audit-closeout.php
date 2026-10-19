<?php
/** Execute the shipped counter against explicit WordPress boundary doubles. */
define('MINUTE_IN_SECONDS', 60);
$cache = array(); $counts = array(); $post_id = 42; $logged_in = false;
function zen_get_option($key) { return $key === 'zen_show_reading_count' ? true : ''; }
function is_singular($type) { return true; }
function is_preview() { return false; }
function is_feed() { return false; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function get_queried_object_id() { return $GLOBALS['post_id']; }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { if (!in_array($ttl, array(300, 3600), true)) throw new RuntimeException('TTL changed'); $GLOBALS['cache'][$key] = $value; }
function get_post_meta($id, $key, $single) { return $GLOBALS['counts'][$id] ?? 0; }
function update_post_meta($id, $key, $value) { $GLOBALS['counts'][$id] = $value; }
$source = file_get_contents(dirname(__DIR__) . '/inc/options.php');
$start = strpos($source, 'function zen_track_post_view()');
$end = strpos($source, "add_action('wp', 'zen_track_post_view')", $start);
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
$footer = file_get_contents(dirname(__DIR__) . '/footer.php');
check(str_contains($footer, 'Since <?php') && str_contains($footer, 'Days'), 'English footer design');
check(str_contains($footer, "esc_html($" . "zen_uptime['days'])"), 'dynamic escaped uptime');
$archive = file_get_contents(dirname(__DIR__) . '/page-archives.php');
check(!str_contains($archive, 'preg_match'), 'archive dead date branch');
check(str_contains($archive, 'zen_archives_public_posts_v2'), 'archive ignores old date format cache');
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value) { return esc_html($value); }
function date_i18n($format) { return '2026'; }
function get_bloginfo($key) { return '<Fixture>'; }
function zen_get_site_uptime() { return array('year' => '2022', 'days' => $GLOBALS['uptime_days'] ?? 123); }
function has_nav_menu($location) { return false; }
function wp_footer() {}
ob_start(); require dirname(__DIR__) . '/footer.php'; $html = ob_get_clean();
check(str_contains($html, 'Since 2022 · 123 Days'), 'rendered dynamic English footer');
check(str_contains($html, '&lt;Fixture&gt;'), 'footer escaping retained');
$GLOBALS['uptime_days'] = 1;
ob_start(); require dirname(__DIR__) . '/footer.php'; $singular = ob_get_clean();
check(str_contains($singular, 'Since 2022 · 1 Day</div>'), 'singular English uptime');
define('HOUR_IN_SECONDS', 3600);
function get_locale() { return 'zh_CN'; }
function get_header() {}
function get_footer() {}
function the_title() { echo 'Archive'; }
function get_option($key) { return ''; }
function get_the_title($post) { return 'Post'; }
function get_permalink($post) { return '/post/7'; }
function get_the_date($format, $post) { return $format === 'Y' ? '2026' : '2026年10月5日'; }
function wp_reset_postdata() {}
class WP_Query {
    public $posts; public $max_num_pages = 1;
    public function __construct($args) { $this->posts = array((object) array('ID' => 7)); $GLOBALS['archive_queries'] = ($GLOBALS['archive_queries'] ?? 0) + 1; }
}
$cache['zen_archives_public_posts'] = array(array('id' => 7, 'date' => '10-05'));
ob_start(); require dirname(__DIR__) . '/page-archives.php'; $html = ob_get_clean();
check(str_contains($html, '2026年10月5日') && !str_contains($html, '>10-05<'), 'legacy cache safely rebuilt');
ob_start(); require dirname(__DIR__) . '/page-archives.php'; ob_end_clean();
check($GLOBALS['archive_queries'] === 1, 'warm v2 cache avoids rebuild');

echo "PASS audit closeout: $checks checks\n";
