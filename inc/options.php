<?php
if (!defined('ABSPATH')) exit;

/**
 * Theme options: a minimal settings page plus front-end helpers.
 *
 * Settings are stored via the Settings API in the options table (no custom
 * database tables). The reading count is stored per post in post meta.
 */

/**
 * Read a theme option with a default.
 */
function zen_get_option($key) {
    $defaults = array(
        'zen_show_reading_count'  => 1,
        'zen_excerpt_length'      => 100,
        'zen_footer_text'         => '',
        'zen_show_footer_credits' => 1,
    );

    return get_option($key, isset($defaults[$key]) ? $defaults[$key] : '');
}

/* -------------------------------------------------------------------------
 * Admin: settings page
 * ---------------------------------------------------------------------- */

function zen_register_options() {
    register_setting('zen_options', 'zen_show_reading_count', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_checkbox',
    ));
    register_setting('zen_options', 'zen_excerpt_length', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_excerpt_length',
    ));
    register_setting('zen_options', 'zen_footer_text', array(
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
    ));
    register_setting('zen_options', 'zen_show_footer_credits', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_checkbox',
    ));
}
add_action('admin_init', 'zen_register_options');

function zen_sanitize_checkbox($value) {
    return empty($value) ? 0 : 1;
}

function zen_sanitize_excerpt_length($value) {
    $value = absint($value);

    if ($value < 1) {
        return 100;
    }

    return min($value, 300);
}

function zen_options_menu() {
    add_theme_page(
        __('主题设置', 'zen'),
        __('主题设置', 'zen'),
        'manage_options',
        'zen-options',
        'zen_options_page_html'
    );
}
add_action('admin_menu', 'zen_options_menu');

function zen_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('zen_options'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('阅读数量', 'zen'); ?></th>
                    <td>
                        <input type="hidden" name="zen_show_reading_count" value="0">
                        <label for="zen_show_reading_count">
                            <input type="checkbox" name="zen_show_reading_count" id="zen_show_reading_count" value="1" <?php checked(1, zen_get_option('zen_show_reading_count')); ?>>
                            <?php esc_html_e('在文章页显示阅读数量', 'zen'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zen_excerpt_length"><?php esc_html_e('摘要字数', 'zen'); ?></label></th>
                    <td>
                        <input type="number" name="zen_excerpt_length" id="zen_excerpt_length" value="<?php echo esc_attr(zen_get_option('zen_excerpt_length')); ?>" min="1" max="300" step="1" class="small-text">
                        <p class="description"><?php esc_html_e('文章列表中每篇摘要显示的字数（1–300）。', 'zen'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zen_footer_text"><?php esc_html_e('自定义页脚内容', 'zen'); ?></label></th>
                    <td>
                        <textarea name="zen_footer_text" id="zen_footer_text" rows="3" class="large-text code"><?php echo esc_textarea(zen_get_option('zen_footer_text')); ?></textarea>
                        <p class="description"><?php esc_html_e('显示在页脚版权下方的自定义内容，支持少量 HTML（如链接）。留空则只显示默认版权。', 'zen'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('页脚署名', 'zen'); ?></th>
                    <td>
                        <input type="hidden" name="zen_show_footer_credits" value="0">
                        <label for="zen_show_footer_credits">
                            <input type="checkbox" name="zen_show_footer_credits" id="zen_show_footer_credits" value="1" <?php checked(1, zen_get_option('zen_show_footer_credits')); ?>>
                            <?php esc_html_e('显示「Theme By RyanZ / Powered By WordPress / RSS」链接', 'zen'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('保存设置', 'zen')); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Front-end: reading count
 * ---------------------------------------------------------------------- */

function zen_track_post_view() {
    if (!is_singular('post') || is_preview() || is_feed()) {
        return;
    }

    if (function_exists('is_bot') && is_bot()) {
        return;
    }

    $post_id = get_queried_object_id();
    if (!$post_id) {
        $post_id = get_the_ID();
    }

    if (!$post_id) {
        return;
    }

    $count = (int) get_post_meta($post_id, 'zen_view_count', true);
    update_post_meta($post_id, 'zen_view_count', $count + 1);
}
add_action('wp', 'zen_track_post_view');

function zen_get_reading_count($post_id = 0) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }

    return (int) get_post_meta($post_id, 'zen_view_count', true);
}