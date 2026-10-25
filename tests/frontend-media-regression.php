<?php
/** Run: php tests/frontend-media-regression.php [image|audio]. Template/CSS boundaries, no native WP/browser claim. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function add_action(...$args) {}
function get_option($key, $default = false) { return $GLOBALS['saved_options'][$key] ?? $default; }
require ABSPATH . 'inc/options.php';
function get_header() {}
function get_footer() {}
function have_posts() { return $GLOBALS['loop_left'] > 0; }
function the_post() { $GLOBALS['loop_left']--; }
function the_title() { echo 'Media regression'; }
function zen_get_primary_category() { return false; }
function zen_posted_on_link() {}
function has_post_thumbnail() { return $GLOBALS['has_thumbnail']; }
function the_post_thumbnail($size, $attributes) { $GLOBALS['thumbnail_calls'][] = array($size, $attributes); }
function the_content() { echo '<p>Article</p>'; }
function wp_link_pages($args) {}
function esc_attr__($value, $domain = '') { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function get_the_author() { return ''; }
function comments_open() { return false; }
function get_comments_number() { return 0; }
function render_single($options, $has_thumbnail = true) {
    $GLOBALS['saved_options'] = array_merge(array(
        'zen_show_toc' => 0, 'zen_show_reading_time' => 0,
        'zen_show_updated_date' => 0, 'zen_show_tags' => 0,
        'zen_show_post_navigation' => 0,
    ), $options);
    $GLOBALS['loop_left'] = 1;
    $GLOBALS['has_thumbnail'] = $has_thumbnail;
    $GLOBALS['thumbnail_calls'] = array();
    ob_start();
    try { include ABSPATH . 'single.php'; } finally { ob_end_clean(); }
    return $GLOBALS['thumbnail_calls'];
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$failures = 0;
function test($name, $callback) {
    global $failures;
    try { $callback(); echo "PASS $name\n"; }
    catch (Throwable $e) { $failures++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
$section = $argv[1] ?? 'all';
if ($section === 'all' || $section === 'image') {
    test('featured sizes follow saved reading width, site cap and responsive gutters', function () {
        // Main is border-box px-4 sm:px-6; sm starts at 640px. The reading shell sits inside it.
        foreach (array(
            array(array(), 720, 720),
            array(array('zen_reading_width' => 600), 600, 600),
            array(array('zen_reading_width' => '731'), 731, 731),
            array(array('zen_reading_width' => 960, 'zen_content_width' => 1920), 960, 960),
            array(array('zen_reading_width' => 960, 'zen_content_width' => 900), 868, 852),
            array(array('zen_reading_width' => 960, 'zen_content_width' => 600), 568, 552),
            array(array('zen_reading_width' => 10, 'zen_content_width' => 5000), 600, 600),
            array(array('zen_reading_width' => 5000, 'zen_content_width' => 5000), 960, 960),
        ) as $case) {
            list($options, $mobile_cap, $desktop_cap) = $case;
            $calls = render_single($options);
            check(count($calls) === 1 && $calls[0][0] === 'large', 'native large thumbnail delegation changed');
            check(($calls[0][1]['class'] ?? '') === 'zen-featured-image', 'featured image class changed');
            $expected = '(max-width: 639.98px) min(calc(100vw - 32px), ' . $mobile_cap . 'px), min(calc(100vw - 48px), ' . $desktop_cap . 'px)';
            $actual = $calls[0][1]['sizes'] ?? '';
            check($actual === $expected, 'options ' . json_encode($options) . ': expected ' . $expected . '; got ' . $actual);
        }
        check(render_single(array('zen_show_featured_image' => 0)) === array(), 'disabled featured image requested');
        check(render_single(array(), false) === array(), 'absent featured image requested');
    });
}

/** Resolve matching plain component selectors in source order, including specificity and !important. */
function component_style($classes, $pseudo = '') {
    $winners = array();
    foreach (array('style.css', 'reading.css', 'layout.css') as $file) {
        $css = preg_replace('~/\*.*?\*/~s', '', file_get_contents(ABSPATH . 'assets/css/' . $file));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            foreach (explode(',', trim($rule[1])) as $selector) {
                $selector = trim($selector);
                if (!preg_match('/^(?:\.[a-zA-Z0-9_-]+\s*)+(?:::before)?$/', $selector)) continue;
                if (str_ends_with($selector, '::before') !== ($pseudo === '::before')) continue;
                if (!str_contains($selector, '.zen-audio-progress-container')) continue;
                preg_match_all('/\.([a-zA-Z0-9_-]+)/', $selector, $matches);
                if (array_diff($matches[1], $classes)) continue;
                foreach (explode(';', $rule[2]) as $declaration) {
                    if (!str_contains($declaration, ':')) continue;
                    list($property, $value) = array_map('trim', explode(':', $declaration, 2));
                    $important = str_contains($value, '!important');
                    $rank = array((int) $important, count($matches[1]), (int) ($pseudo !== ''));
                    if (!isset($winners[$property]) || $rank >= $winners[$property][0]) {
                        $winners[$property] = array($rank, trim(str_replace('!important', '', $value)));
                    }
                }
            }
        }
    }
    return array_map(fn($winner) => $winner[1], $winners);
}
if ($section === 'all' || $section === 'audio') {
    test('audio hit zone stays transparent and 32px high in light and dark cascades', function () {
        foreach (array(array('zen-audio-progress-container'), array('dark', 'zen-audio-progress-container')) as $classes) {
            $style = component_style($classes);
            check(($style['height'] ?? '') === '32px', 'audio touch height changed');
            check(($style['background'] ?? '') === 'transparent', implode(' ', $classes) . ' background resolves to ' . ($style['background'] ?? 'unset'));
            check(($style['overflow'] ?? '') === 'visible', 'audio hit zone clips the visual track');
            $track = component_style($classes, '::before');
            check(($track['height'] ?? '') === '0.36rem', 'thin visual track height changed');
            check(($track['background'] ?? '') === 'var(--zen-border)', 'theme-aware visual track missing');
        }
    });
}
exit($failures ? 1 : 0);
