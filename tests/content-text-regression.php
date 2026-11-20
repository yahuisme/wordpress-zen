<?php
/** Run: php tests/content-text-regression.php. Native integration runs separately. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_filter(...$args) {}
function wp_strip_all_tags($text, $remove_breaks = false) {
    $text = strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text));
    return trim($remove_breaks ? preg_replace('/[\r\n\t ]+/', ' ', $text) : $text);
}

function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false); }
function esc_attr($text) { return esc_html($text); }
function esc_url($text) { return esc_html($text); }
function __($text, $domain = '') { return $text; }
function zen_get_option($key) { return array('zen_list_style' => 'standard', 'zen_excerpt_length' => $GLOBALS['content_length'] ?? 8)[$key] ?? ''; }
function get_the_ID() { return 17; }
function post_class($class) { echo 'class="' . esc_attr($class) . '"'; }
function the_permalink() { echo '/article/'; }
function get_the_title($post = null) { return 'Fixture'; }
function get_the_category() { return array(); }
function is_search() { return false; }
function get_the_date($format = '', $post = null) {
    return gmdate($format ?: 'Y-m-d', strtotime('2026-10-27T12:00:00Z'));
}
function wp_date($format) { return gmdate($format, strtotime('2026-11-01T12:00:00Z')); }
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
content_check(zen_trim_excerpt('<script>中文内容</script><style>中文样式</style><b>English excerpt</b> after', 7) === 'English…', 'strip script and style contents before counting Latin characters');
content_check(zen_trim_excerpt('😀 café', 1) === '😀…', 'emoji-only prefix is truncated without breaking UTF-8');
content_check(zen_trim_excerpt("e\u{0301}x", 1) === "e\u{0301}…", 'a combining accent stays with its base letter');
content_check(zen_trim_excerpt(str_repeat('a ', 60), 60) === rtrim(str_repeat('a ', 30)) . '…', 'a 60-character Latin excerpt is not a 60-word excerpt');
content_check(zen_trim_excerpt("  <b>中文</b>\n\t 摘要  ", 8) === '中文 摘要', 'plain excerpt whitespace stays normalized');
content_check(zen_trim_excerpt('<p>First</p><p>Second</p>', 8) === 'First Se…', 'block separator counts as one visible character');
content_check(zen_trim_excerpt('<p>First<p>Second</p>', 16) === 'First Second', 'paragraphs with an omitted end tag retain their separator');
content_check(zen_trim_excerpt('<ul><li>first<li>second</ul>', 16) === 'first second', 'list items with omitted end tags retain their separator');
content_check(zen_trim_excerpt('<div>inter<em>national</em><br />news</div>', 24) === 'international news', 'line breaks separate text without splitting inline markup');
content_check(zen_trim_excerpt('<p>&lt;br&gt;</p><p>literal</p>', 16) === '<br> literal', 'encoded HTML remains literal after spacing real tags');
content_check(zen_trim_excerpt('<b> </b>', 8) === '', 'markup-only excerpt is empty');
content_check(zen_trim_excerpt('<b>中文</b>', 2) === '中文', 'exact character limit does not add ellipsis');
content_check(zen_trim_excerpt('中文 &amp; example', 6) === '中文 & e…', 'named entity consumes one visible character');
content_check(zen_trim_excerpt('&#20013;&#x6587;&#20013;&#x6587;', 2) === '中文…', 'numeric CJK entities use the CJK character limit');
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
$GLOBALS['content_excerpt'] = 'A & B';
ob_start();
include ABSPATH . 'template-parts/content-excerpt.php';
$html = ob_get_clean();
content_check(str_contains($html, 'A &amp; B') && !str_contains($html, '&amp;amp;'), 'plain ampersands are escaped exactly once in list excerpts');
foreach (array(
    array('中文 &amp;amp; literal', '中文 &amp;…'),
    array('English &amp;lt;tag&amp;gt; literal', 'English &lt;tag&gt; literal'),
    array('English &amp;#65; decimal literal', 'English &#65; decimal literal'),
    array('English &amp;#x41; lowercase hex literal', 'English &#x41; lowercase hex literal'),
    array('English &amp;#X41; uppercase hex literal', 'English &#X41; uppercase hex literal'),
    array('English &lt;code&gt;literal&lt;/code&gt; words here', 'English <code>literal</code> words here'),
) as list($source, $visible)) {
    $GLOBALS['content_length'] = str_starts_with($source, 'English') ? 60 : 8;
    $GLOBALS['content_excerpt'] = $source;
    ob_start();
    include ABSPATH . 'template-parts/content-excerpt.php';
    $html = ob_get_clean();
    preg_match('~<div class="zen-post-excerpt[^>]*>(.*?)</div>~s', $html, $match);
    $actual = html_entity_decode(trim($match[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    content_check($actual === $visible, 'one-layer visible text survives actual template: ' . $source . ' => ' . $actual);
}
printf("Content text: %d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
