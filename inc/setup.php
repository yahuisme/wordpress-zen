<?php
if (!defined('ABSPATH')) exit;

/**
 * Resource hints.
 */
function zen_resource_hints($urls, $relation_type) {
    if ('preconnect' === $relation_type) {
        $urls[] = 'https://fonts.googleapis.com';
        $urls[] = array(
            'href' => 'https://fonts.gstatic.com',
            'crossorigin' => 'anonymous',
        );
    }

    return $urls;
}
add_filter('wp_resource_hints', 'zen_resource_hints', 10, 2);

/**
 * Theme setup.
 */
function zen_setup() {
    load_theme_textdomain('zen', get_template_directory() . '/languages');

    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');

    register_nav_menus(array(
        'primary' => __('Primary Menu', 'zen'),
        'footer' => __('Footer Menu', 'zen'),
    ));

    add_theme_support('html5', array('search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style'));
    add_filter('pre_option_link_manager_enabled', '__return_true');
}
add_action('after_setup_theme', 'zen_setup');

// Remove WordPress emoji assets.
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

function zen_custom_excerpt_length($length) {
    return 120;
}
add_filter('excerpt_length', 'zen_custom_excerpt_length', 999);
