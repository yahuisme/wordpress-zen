<?php
/** Verify Zen UTF-8 fallbacks without the optional mbstring extension. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
function add_action(...$args) {}
function get_option($key, $default = false) { return $default; }
function zen_get_option($key) { return ''; }
function wp_strip_all_tags($text) { return strip_tags($text); }
function strip_shortcodes($text) { return $text; }
function is_singular() { return false; }
function post_password_required() { return false; }
function add_filter(...$args) {}
function apply_filters($hook, $value) { return $value; }
function defined_seo_plugin() { return false; }
function get_pages($args) { return array(); }
function get_permalink($id) { return ''; }
function home_url($path = '') { return $path; }
function is_category() { return false; }
function get_queried_object_id() { return 0; }
function get_categories($args) { return array(); }
function get_category_link($id) { return ''; }
function is_home() { return false; }
function esc_url($value) { return $value; }
function esc_html($value) { return $value; }
function __($value, $domain = '') { return $value; }
require ABSPATH . 'inc/template-tags.php';
require ABSPATH . 'inc/schema.php';
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
check(!function_exists('mb_strlen') && !function_exists('mb_substr'), 'mbstring is absent in the fallback process');
check(zen_trim_excerpt('中文摘要', 4) === '中文摘要', 'exact UTF-8 length stays intact without mbstring');
check(zen_trim_excerpt('中文摘要', 2) === '中文…', 'CJK excerpt trims without mbstring');
check(zen_trim_excerpt("e\u{0301}x", 1) === "e\u{0301}…", 'grapheme boundaries do not require mbstring or intl');
check(zen_clean_description(str_repeat('中文', 100)) === str_repeat('中文', 80) . '…', 'SEO description trims without mbstring');

// Also run with php -n -d memory_limit=32M: the 160-character result must not allocate a match per source character.
check(zen_clean_description(str_repeat('甲a', 600000)) === str_repeat('甲a', 80) . '…', 'long post descriptions stay bounded without mbstring');
