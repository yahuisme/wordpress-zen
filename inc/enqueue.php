<?php
if (!defined('ABSPATH')) exit;

/**
 * Frontend assets.
 */
function zen_scripts() {
    $ver = wp_get_theme()->get('Version');

    $font_family = zen_get_option('zen_font_family');
    $font_query  = $font_family === 'space-grotesk'
        ? 'family=Space+Grotesk:wght@300..700'
        : 'family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900';
    $font_stack  = $font_family === 'space-grotesk'
        ? '"Space Grotesk", "Noto Sans SC", "PingFang SC", "Microsoft YaHei", "Noto Sans CJK SC", ui-sans-serif, system-ui, sans-serif'
        : 'Inter, "Noto Sans SC", "PingFang SC", "Microsoft YaHei", "Noto Sans CJK SC", ui-sans-serif, system-ui, sans-serif';
    $heading_stack = $font_family === 'space-grotesk'
        ? $font_stack
        : '"Noto Serif SC", "Songti SC", "SimSun", "Noto Serif CJK SC", ui-serif, Georgia, Cambria, "Times New Roman", Times, serif';
    $heading_weight = $font_family === 'space-grotesk' ? ' body h1, body h2, body h3 { font-weight: 600 !important; }' : '';
    $font_query .= '&family=Noto+Sans+SC:wght@100..900';

    if ($font_family !== 'space-grotesk') {
        $font_query .= '&family=Noto+Serif+SC:wght@200..900';
    }

    $font_query .= '&display=swap';
    wp_enqueue_style('zen-google-fonts', 'https://fonts.googleapis.com/css2?' . $font_query, array(), null);
    wp_enqueue_style('phosphor-icons', get_template_directory_uri() . '/assets/css/phosphor-icons.css', array(), $ver);

    if (is_singular() && zen_get_option('zen_show_highlight') && zen_has_code_blocks()) {
        wp_enqueue_style('highlight-css', get_template_directory_uri() . '/assets/css/github-dark.min.css', array(), $ver);
        wp_enqueue_script('highlight-js', get_template_directory_uri() . '/assets/js/highlight.min.js', array(), $ver, true);
    }

    $main_dependencies = wp_script_is('highlight-js', 'enqueued') ? array('highlight-js') : array();
    wp_enqueue_script(
        'zen-main',
        get_template_directory_uri() . '/js/main.js',
        $main_dependencies,
        $ver,
        true
    );

    wp_localize_script('zen-main', 'zenSettings', array(
        'theme_mode_default' => zen_get_option('zen_theme_mode_default'),
        'search_shortcut'    => (int) zen_get_option('zen_show_search_shortcut'),
    ));

    $compiled_css = get_template_directory() . '/assets/css/style.css';
    if (file_exists($compiled_css)) {
        wp_enqueue_style('zen-compiled-style', get_template_directory_uri() . '/assets/css/style.css', array(), $ver);
        wp_add_inline_style('zen-compiled-style', 'body, body button, body input, body textarea, body select, body .comment-reply-title small { font-family: ' . $font_stack . '; } body h1, body h2, body h3, body h4, body h5, body h6, body .font-serif, body .serif { font-family: ' . $heading_stack . '; }' . $heading_weight);
        $zen_content_width = max(600, min(1920, (int) zen_get_option('zen_content_width')));
        wp_add_inline_style('zen-compiled-style', ':root{--zen-content-width:' . $zen_content_width . 'px;--zen-content-half:' . round($zen_content_width / 2) . 'px;--zen-archives-width:' . round($zen_content_width * 0.75) . 'px}.max-w-zen{max-width:var(--zen-content-width)}.max-w-zen-narrow{max-width:var(--zen-archives-width)}');
    }

    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
}
add_action('wp_enqueue_scripts', 'zen_scripts');

function zen_has_code_blocks() {
    $post = get_post();

    if (!$post) {
        return false;
    }

    return has_block('core/code', $post) || preg_match('/<pre\b[^>]*>\s*(?:<code\b)?/i', $post->post_content);
}
