<?php
if (!defined('ABSPATH')) exit;

/**
 * Frontend assets.
 */
function zen_scripts() {
    $ver = '1.1.26';

    wp_enqueue_style('zen-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Noto+Serif+SC:wght@200..900&display=swap', array(), null);
    wp_enqueue_script('phosphor-icons', get_template_directory_uri() . '/assets/js/phosphor-icons.js', array(), $ver, false);

    if (is_singular() && zen_get_option('zen_show_highlight') && zen_has_code_blocks()) {
        wp_enqueue_style('highlight-css', get_template_directory_uri() . '/assets/css/github-dark.min.css', array(), $ver);
        wp_enqueue_script('highlight-js', get_template_directory_uri() . '/assets/js/highlight.min.js', array(), $ver, true);
    }

    $main_js = get_template_directory() . '/js/main.js';
    $main_dependencies = wp_script_is('highlight-js', 'enqueued') ? array('highlight-js') : array();
    wp_enqueue_script(
        'zen-main',
        get_template_directory_uri() . '/js/main.js',
        $main_dependencies,
        file_exists($main_js) ? filemtime($main_js) : $ver,
        true
    );

    wp_localize_script('zen-main', 'zenSettings', array(
        'theme_mode_default' => zen_get_option('zen_theme_mode_default'),
        'search_shortcut'    => (int) zen_get_option('zen_show_search_shortcut'),
    ));

    $compiled_css = get_template_directory() . '/assets/css/style.css';
    if (file_exists($compiled_css)) {
        wp_enqueue_style('zen-compiled-style', get_template_directory_uri() . '/assets/css/style.css', array(), filemtime($compiled_css));
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
