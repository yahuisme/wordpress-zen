<?php
if (!defined('ABSPATH')) exit;

/**
 * Theme setup.
 */
function zen_setup() {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', array('height' => 80, 'width' => 240, 'flex-height' => true, 'flex-width' => true));
    add_theme_support('editor-styles');
    add_theme_support('align-wide');
    add_editor_style(array_merge(array_values(zen_reference_font_styles()), array('assets/css/reading.css')));
    add_theme_support('automatic-feed-links');

    register_nav_menus(array('footer' => __('页脚菜单', 'zen')));

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

/** Apply the frontend tie-break and zero-last policy only to the native ascending admin preview. */
function zen_order_link_manager_bookmarks($bookmarks, $args) {
    if (is_array($bookmarks) && empty($args['hide_invisible']) && $args['limit'] === -1
        && strtolower($args['orderby']) === 'rating' && strtoupper($args['order']) === 'ASC') {
        usort($bookmarks, 'zen_compare_link_order');
    }
    return $bookmarks;
}
add_action('load-link-manager.php', function () {
    add_filter('get_bookmarks', 'zen_order_link_manager_bookmarks', 10, 2);
});

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
        <label for="zen_link_order"><?php esc_html_e('排序：', 'zen'); ?></label>
        <input type="number" name="zen_link_order" id="zen_link_order" value="<?php echo esc_attr($order); ?>" min="0" max="2147483647" step="1" class="small-text" aria-describedby="zen-link-order-help">
    </p>
    <p class="description" id="zen-link-order-help"><?php esc_html_e('数字越小越靠前，填 0 或留空排在最后', 'zen'); ?></p>
    <?php
}

function zen_save_link_order($nonce_action, $verified) {
    if (!$verified || 'POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || !current_user_can('manage_links')
        || !isset($_POST['zen_link_order']) || ('add-bookmark' !== $nonce_action && !str_starts_with($nonce_action, 'update-bookmark_'))) return;

    $value = $_POST['zen_link_order'];
    $digits = is_string($value) && preg_match('/\A[0-9]*\z/', $value) ? ltrim($value, '0') : null;
    // Compare before conversion so neither floating-point rounding nor integer overflow can change the input.
    $max = '2147483647';
    if ($digits === null || strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
        wp_die(esc_html__('排序必须是 0 到 2147483647 之间的整数。请返回修改后再保存。', 'zen'), '', array('response' => 400, 'back_link' => true));
    }
    $_POST['link_rating'] = (int) $digits;
}
add_action('load-link.php', static function () {
    // Let Core validate its own add/edit nonce before translating the form field.
    add_action('check_admin_referer', 'zen_save_link_order', 10, 2);
});

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

