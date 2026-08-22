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
        'zen_show_reading_count'   => 1,
        'zen_excerpt_length'       => 100,
        'zen_show_toc'             => 1,
        'zen_show_reading_progress'=> 1,
        'zen_theme_mode_default'   => 'auto',
        'zen_show_back_to_top'     => 1,
        'zen_footer_text'          => '',
        'zen_show_footer_credits'  => 1,
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
    register_setting('zen_options', 'zen_show_toc', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_checkbox',
    ));
    register_setting('zen_options', 'zen_show_reading_progress', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_checkbox',
    ));
    register_setting('zen_options', 'zen_theme_mode_default', array(
        'type'              => 'string',
        'sanitize_callback' => 'zen_sanitize_theme_mode',
    ));
    register_setting('zen_options', 'zen_show_back_to_top', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_checkbox',
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

function zen_sanitize_theme_mode($value) {
    return in_array($value, array('auto', 'light', 'dark'), true) ? $value : 'auto';
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

            <h2 class="title"><?php esc_html_e('阅读', 'zen'); ?></h2>
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
                    <th scope="row"><?php esc_html_e('文章目录', 'zen'); ?></th>
                    <td>
                        <input type="hidden" name="zen_show_toc" value="0">
                        <label for="zen_show_toc">
                            <input type="checkbox" name="zen_show_toc" id="zen_show_toc" value="1" <?php checked(1, zen_get_option('zen_show_toc')); ?>>
                            <?php esc_html_e('在文章页显示目录（侧边栏 / 移动端抽屉）', 'zen'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('阅读进度条', 'zen'); ?></th>
                    <td>
                        <input type="hidden" name="zen_show_reading_progress" value="0">
                        <label for="zen_show_reading_progress">
                            <input type="checkbox" name="zen_show_reading_progress" id="zen_show_reading_progress" value="1" <?php checked(1, zen_get_option('zen_show_reading_progress')); ?>>
                            <?php esc_html_e('在页面顶部显示阅读进度条', 'zen'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="title"><?php esc_html_e('外观', 'zen'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="zen_theme_mode_default"><?php esc_html_e('深色模式默认值', 'zen'); ?></label></th>
                    <td>
                        <select name="zen_theme_mode_default" id="zen_theme_mode_default">
                            <option value="auto" <?php selected('auto', zen_get_option('zen_theme_mode_default')); ?>><?php esc_html_e('跟随系统', 'zen'); ?></option>
                            <option value="light" <?php selected('light', zen_get_option('zen_theme_mode_default')); ?>><?php esc_html_e('浅色', 'zen'); ?></option>
                            <option value="dark" <?php selected('dark', zen_get_option('zen_theme_mode_default')); ?>><?php esc_html_e('深色', 'zen'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('访客首次访问时使用的主题模式；用户手动切换后会记住其个人选择。', 'zen'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('返回顶部按钮', 'zen'); ?></th>
                    <td>
                        <input type="hidden" name="zen_show_back_to_top" value="0">
                        <label for="zen_show_back_to_top">
                            <input type="checkbox" name="zen_show_back_to_top" id="zen_show_back_to_top" value="1" <?php checked(1, zen_get_option('zen_show_back_to_top')); ?>>
                            <?php esc_html_e('显示右下角返回顶部按钮', 'zen'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="title"><?php esc_html_e('页脚', 'zen'); ?></h2>
            <table class="form-table" role="presentation">
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