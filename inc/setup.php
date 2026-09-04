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
    return (int) zen_get_option('zen_excerpt_length');
}
add_filter('excerpt_length', 'zen_custom_excerpt_length', 999);

/**
 * Link order management.
 */
function zen_link_order_metabox() {
    add_meta_box(
        'zen_link_order_box',
        __('链接排序', 'zen'),
        'zen_link_order_metabox_render',
        'link',
        'side',
        'high'
    );
}
add_action('add_meta_boxes_link', 'zen_link_order_metabox');

function zen_link_order_metabox_render($link) {
    $order = isset($link->link_rating) ? (int) $link->link_rating : 0;
    ?>
    <p>
        <label for="zen_link_order"><?php esc_html_e('排序序号：', 'zen'); ?></label>
        <input type="number" name="zen_link_order" id="zen_link_order" value="<?php echo esc_attr($order); ?>" min="0" step="1" class="small-text">
    </p>
    <p class="description"><?php esc_html_e('自定义排序序号（数字越小越靠前，默认 0 排在最后）。', 'zen'); ?></p>
    <?php
}

function zen_save_link_order() {
    if (isset($_POST['zen_link_order'])) {
        $_POST['link_rating'] = absint($_POST['zen_link_order']);
    }
}
add_action('admin_init', 'zen_save_link_order');

function zen_link_manager_columns($columns) {
    if (isset($columns['rating'])) {
        $columns['rating'] = __('排序', 'zen');
    }
    return $columns;
}
add_filter('manage_link-manager_columns', 'zen_link_manager_columns');

function zen_admin_link_style() {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if ($screen && in_array($screen->id, array('link', 'link-add'), true)) {
        echo '<style>#linkadvanceddiv tr:has(#link_rating) { display: none; }</style>';
    }
}
add_action('admin_head', 'zen_admin_link_style');

