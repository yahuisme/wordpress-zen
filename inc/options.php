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
    static $defaults;

    if ($defaults === null) {
        $defaults = array(
            'zen_show_reading_count'   => 1,
            'zen_excerpt_length'       => 100,
            'zen_show_tags'            => 1,
            'zen_show_highlight'       => 1,
            'zen_show_toc'             => 1,
            'zen_show_updated_date'    => 1,
            'zen_show_reading_time'    => 1,
            'zen_show_post_navigation' => 1,
            'zen_show_reading_progress'=> 1,
            'zen_theme_mode_default'   => 'auto',
            'zen_font_family'          => 'inter',
            'zen_show_back_to_top'     => 1,
            'zen_show_lightbox'        => 1,
            'zen_show_search_shortcut' => 1,
            'zen_footer_text'          => '',
            'zen_show_footer_theme_by' => 1,
            'zen_show_footer_wordpress'=> 1,
            'zen_show_footer_rss'      => 1,
            'zen_show_copyright'       => 0,
            'zen_copyright_license'    => 'cc-by-nc-sa-4.0',
        );
    }

    return get_option($key, isset($defaults[$key]) ? $defaults[$key] : '');
}

/* -------------------------------------------------------------------------
 * Admin: settings page
 * ---------------------------------------------------------------------- */

function zen_register_options() {
    $checkbox_options = array(
        'zen_show_reading_count',
        'zen_show_tags',
        'zen_show_highlight',
        'zen_show_toc',
        'zen_show_updated_date',
        'zen_show_reading_time',
        'zen_show_post_navigation',
        'zen_show_reading_progress',
        'zen_show_back_to_top',
        'zen_show_lightbox',
        'zen_show_search_shortcut',
        'zen_show_footer_theme_by',
        'zen_show_footer_wordpress',
        'zen_show_footer_rss',
        'zen_show_copyright',
    );

    foreach ($checkbox_options as $key) {
        register_setting('zen_options', $key, array(
            'type'              => 'integer',
            'sanitize_callback' => 'zen_sanitize_checkbox',
        ));
    }

    register_setting('zen_options', 'zen_excerpt_length', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_excerpt_length',
    ));
    register_setting('zen_options', 'zen_theme_mode_default', array(
        'type'              => 'string',
        'sanitize_callback' => 'zen_sanitize_theme_mode',
    ));
    register_setting('zen_options', 'zen_font_family', array(
        'type'              => 'string',
        'sanitize_callback' => 'zen_sanitize_font_family',
    ));
    register_setting('zen_options', 'zen_footer_text', array(
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
    ));
    register_setting('zen_options', 'zen_copyright_license', array(
        'type'              => 'string',
        'sanitize_callback' => 'zen_sanitize_copyright_license',
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

function zen_sanitize_font_family($value) {
    return in_array($value, array('inter', 'space-grotesk'), true) ? $value : 'inter';
}

function zen_sanitize_copyright_license($value) {
    $licenses = array(
        'none',
        'all-rights-reserved',
        'cc-by-4.0',
        'cc-by-sa-4.0',
        'cc-by-nc-sa-4.0',
    );

    return in_array($value, $licenses, true) ? $value : 'cc-by-nc-sa-4.0';
}

function zen_get_reading_time($post_id = 0) {
    $content = get_post_field('post_content', $post_id ? $post_id : get_the_ID());
    $content = strip_shortcodes($content);
    $content = preg_replace('/<!--\s*wp:.*?-->/s', ' ', $content);
    $content = trim(wp_strip_all_tags($content));

    if (function_exists('mb_strlen')) {
        $length = mb_strlen($content);
    } else {
        $length = preg_match_all('/./u', $content, $matches);
    }

    return max(1, (int) ceil($length / 300));
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

function zen_checkbox_field($key, $label, $desc) {
    ?>
    <tr>
        <th scope="row"><?php echo esc_html($label); ?></th>
        <td>
            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="0">
            <label for="<?php echo esc_attr($key); ?>">
                <input type="checkbox" name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" value="1" <?php checked(1, zen_get_option($key)); ?>>
                <?php echo esc_html($desc); ?>
            </label>
        </td>
    </tr>
    <?php
}

function zen_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <style>
            .zen-options-section-title {
                margin-top: 2rem;
                margin-bottom: 1rem;
                padding-bottom: .5rem;
                border-bottom: 1px solid #dcdcde;
                font-size: 1.25rem;
                line-height: 1.4;
                font-weight: 600;
            }
        </style>
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php settings_fields('zen_options'); ?>

            <h2 class="title zen-options-section-title"><?php esc_html_e('文章', 'zen'); ?></h2>
            <table class="form-table" role="presentation">
                <?php zen_checkbox_field('zen_show_reading_count', __('阅读量', 'zen'), __('在文章页显示阅读量', 'zen')); ?>
                <tr>
                    <th scope="row"><label for="zen_excerpt_length"><?php esc_html_e('摘要长度', 'zen'); ?></label></th>
                    <td>
                        <input type="number" name="zen_excerpt_length" id="zen_excerpt_length" value="<?php echo esc_attr(zen_get_option('zen_excerpt_length')); ?>" min="1" max="300" step="1" class="small-text">
                        <p class="description"><?php esc_html_e('文章列表中每篇摘要显示的字数（1–300）。', 'zen'); ?></p>
                    </td>
                </tr>
                <?php zen_checkbox_field('zen_show_tags', __('标签区', 'zen'), __('在文章页底部显示标签区', 'zen')); ?>
                <?php zen_checkbox_field('zen_show_highlight', __('代码高亮', 'zen'), __('启用代码块语法高亮', 'zen')); ?>
                <?php zen_checkbox_field('zen_show_toc', __('文章目录', 'zen'), __('显示文章目录（侧边栏 / 移动端抽屉）', 'zen')); ?>
                <?php zen_checkbox_field('zen_show_updated_date', __('最后更新时间', 'zen'), __('显示最后更新时间', 'zen')); ?>
                <?php zen_checkbox_field('zen_show_reading_time', __('预计阅读时间', 'zen'), __('显示预计阅读时间', 'zen')); ?>
                <?php zen_checkbox_field('zen_show_post_navigation', __('上一篇 / 下一篇文章', 'zen'), __('显示上一篇 / 下一篇文章', 'zen')); ?>
            </table>

            <h2 class="title zen-options-section-title"><?php esc_html_e('界面', 'zen'); ?></h2>
            <table class="form-table" role="presentation">
                <?php zen_checkbox_field('zen_show_reading_progress', __('阅读进度条', 'zen'), __('在页面顶部显示阅读进度条', 'zen')); ?>
                <tr>
                    <th scope="row"><label for="zen_theme_mode_default"><?php esc_html_e('默认外观', 'zen'); ?></label></th>
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
                    <th scope="row"><label for="zen_font_family"><?php esc_html_e('界面字体', 'zen'); ?></label></th>
                    <td>
                        <select name="zen_font_family" id="zen_font_family">
                            <option value="inter" <?php selected('inter', zen_get_option('zen_font_family')); ?>>Inter</option>
                            <option value="space-grotesk" <?php selected('space-grotesk', zen_get_option('zen_font_family')); ?>>Space Grotesk</option>
                        </select>
                        <p class="description"><?php esc_html_e('Inter 模式使用 Noto Serif SC 作为文章标题字体；Space Grotesk 模式使用 Space Grotesk 与 Noto Sans SC 作为文章标题字体。字体通过 Google Fonts 在线加载。', 'zen'); ?></p>
                    </td>
                </tr>
                <?php zen_checkbox_field('zen_show_back_to_top', __('返回顶部按钮', 'zen'), __('显示右下角返回顶部按钮', 'zen')); ?>
                <?php zen_checkbox_field('zen_show_lightbox', __('图片灯箱', 'zen'), __('启用图片灯箱（点击图片放大查看）', 'zen')); ?>
                <?php zen_checkbox_field('zen_show_search_shortcut', __('搜索快捷键', 'zen'), __('启用搜索快捷键（Ctrl/⌘ + K）', 'zen')); ?>
            </table>

            <h2 class="title zen-options-section-title"><?php esc_html_e('页脚', 'zen'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="zen_footer_text"><?php esc_html_e('自定义页脚内容', 'zen'); ?></label></th>
                    <td>
                        <textarea name="zen_footer_text" id="zen_footer_text" rows="5" class="large-text code" placeholder="例如：&lt;a href=&quot;https://example.com&quot; target=&quot;_blank&quot; rel=&quot;noopener noreferrer&quot;&gt;Hosted by Example&lt;/a&gt;"><?php echo esc_textarea(zen_get_option('zen_footer_text')); ?></textarea>
                        <p class="description"><?php esc_html_e('显示在右侧页脚信息上方的自定义内容，支持少量 HTML（如链接）。留空则不显示。', 'zen'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('页脚信息', 'zen'); ?></th>
                    <td>
                        <?php foreach (array(
                            'zen_show_footer_theme_by'  => __('Theme By RyanZ', 'zen'),
                            'zen_show_footer_wordpress' => __('Powered By WordPress', 'zen'),
                            'zen_show_footer_rss'       => __('RSS', 'zen'),
                        ) as $key => $label) : ?>
                            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="0">
                            <label for="<?php echo esc_attr($key); ?>" style="display: inline-flex; align-items: center; margin-right: 1.5rem;">
                                <input type="checkbox" name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" value="1" <?php checked(1, zen_get_option($key)); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>

            <h2 class="title zen-options-section-title"><?php esc_html_e('高级', 'zen'); ?></h2>
            <table class="form-table" role="presentation">
                <?php zen_checkbox_field('zen_show_copyright', __('文章版权信息', 'zen'), __('在文章底部显示版权信息', 'zen')); ?>
                <tr>
                    <th scope="row"><label for="zen_copyright_license"><?php esc_html_e('版权协议', 'zen'); ?></label></th>
                    <td>
                        <select name="zen_copyright_license" id="zen_copyright_license">
                            <option value="none" <?php selected('none', zen_get_option('zen_copyright_license')); ?>><?php esc_html_e('不显示', 'zen'); ?></option>
                            <option value="all-rights-reserved" <?php selected('all-rights-reserved', zen_get_option('zen_copyright_license')); ?>><?php esc_html_e('保留所有权利', 'zen'); ?></option>
                            <option value="cc-by-4.0" <?php selected('cc-by-4.0', zen_get_option('zen_copyright_license')); ?>><?php esc_html_e('CC BY 4.0', 'zen'); ?></option>
                            <option value="cc-by-sa-4.0" <?php selected('cc-by-sa-4.0', zen_get_option('zen_copyright_license')); ?>><?php esc_html_e('CC BY-SA 4.0', 'zen'); ?></option>
                            <option value="cc-by-nc-sa-4.0" <?php selected('cc-by-nc-sa-4.0', zen_get_option('zen_copyright_license')); ?>><?php esc_html_e('CC BY-NC-SA 4.0', 'zen'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('选择「不显示」时，不输出文章版权信息。', 'zen'); ?></p>
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
    if (!zen_get_option('zen_show_reading_count') || !is_singular('post') || is_preview() || is_feed()) {
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