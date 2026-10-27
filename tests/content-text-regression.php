<?php
/** Run: php tests/content-text-regression.php. Native integration runs separately. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
function add_action(...$args) {}
function wp_strip_all_tags($text, $remove_breaks = false) {
    $text = strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text));
    return trim($remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) : $text);
}
// Model the space-separated word path; Unicode length is exercised by the theme.
function wp_trim_words($text, $length, $more = null) {
    $words = preg_split('/[\n\r\t ]+/', wp_strip_all_tags($text), $length + 1, PREG_SPLIT_NO_EMPTY);
    $truncated = count($words) > $length;
    return implode(' ', array_slice($words, 0, $length)) . ($truncated ? $more : '');
}
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function __($text, $domain = '') { return $text; }
function zen_get_option($key) { return array('zen_list_style' => 'standard', 'zen_excerpt_length' => 8)[$key] ?? ''; }
function the_ID() { echo '17'; }
function post_class($class) { echo 'class="' . esc_attr($class) . '"'; }
function the_permalink() { echo '/article/'; }
function the_title() { echo 'Fixture'; }
function get_the_category() { return array(); }
function is_search() { return false; }
function get_the_date($format = '') { return '2026-10-27'; }
function get_month_link($year, $month) { return '/2026/10/'; }
function get_the_excerpt() { return $GLOBALS['content_excerpt']; }
require ABSPATH . 'inc/template-tags.php';
$checks = 0;
$failures = 0;
function content_check($condition, $label) {
    global $checks, $failures;
    $checks++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$condition) $failures++;
}
content_check(zen_trim_excerpt('<strong>中文摘要</strong>，手工填写。', 8) === '中文摘要，手工填…', 'CJK manual HTML is removed before character trimming');
content_check(zen_trim_excerpt('<b>日本語の要約</b>です。', 6) === '日本語の要約…', 'Japanese manual HTML does not consume the character limit');
content_check(zen_trim_excerpt('<b>한국어 요약</b>입니다.', 6) === '한국어 요약…', 'Korean manual HTML does not consume the character limit');
content_check(zen_trim_excerpt('<script>中文恶意内容</script><style>中文样式</style><b>English excerpt</b> after', 2) === 'English excerpt…', 'script and style contents are removed before language detection');
content_check(zen_trim_excerpt("  <b>中文</b>\n\t 摘要  ", 8) === '中文 摘要', 'plain excerpt whitespace stays normalized');
content_check(zen_trim_excerpt('<b> </b>', 8) === '', 'markup-only excerpt is empty');
content_check(zen_trim_excerpt('<b>中文</b>', 2) === '中文', 'exact character limit does not add ellipsis');
$GLOBALS['content_excerpt'] = '<strong>中文摘要</strong>，手工填写。';
ob_start();
include ABSPATH . 'template-parts/content-excerpt.php';
$html = ob_get_clean();
preg_match('~<div class="zen-post-excerpt[^>]*>(.*?)</div>~s', $html, $match);
content_check(trim($match[1] ?? '') === '中文摘要，手工填…', 'actual list template renders plain CJK excerpt');
$GLOBALS['content_excerpt'] = '&lt;img src=x onerror=alert(1)&gt; 中文 &amp; text';
ob_start();
include ABSPATH . 'template-parts/content-excerpt.php';
$html = ob_get_clean();
content_check(!str_contains($html, '<img') && str_contains($html, '&lt;img'), 'encoded excerpt markup remains escaped at the HTML boundary');
printf("Content text: %d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
