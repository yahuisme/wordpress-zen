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
            'zen_show_reading_count'   => 0,
            'zen_excerpt_length'       => 150,
            'zen_show_tags'            => 1,
            'zen_show_highlight'       => 1,
            'zen_show_toc'             => 1,
            'zen_show_updated_date'    => 1,
            'zen_show_reading_time'    => 1,
            'zen_show_post_navigation' => 1,
            'zen_show_reading_progress'=> 1,
            'zen_theme_mode_default'   => 'auto',
            'zen_font_family'          => 'system',
            'zen_content_width'        => 900,
            'zen_reading_width'        => 720,
            'zen_list_style'           => 'standard',
            'zen_show_site_intro'      => 1,
            'zen_category_ids'         => array(),
            'zen_show_featured_image'  => 1,
            'zen_show_back_to_top'     => 1,
            'zen_show_lightbox'        => 1,
            'zen_show_search_shortcut' => 1,
            'zen_footer_text'          => '',
            'zen_show_footer_theme_by' => 0,
            'zen_show_footer_wordpress'=> 0,
            'zen_show_footer_rss'      => 1,
            'zen_copyright_license'    => 'none',
            'zen_site_start_date'      => '',
            'zen_code_head'            => '',
            'zen_code_body'            => '',
            'zen_code_footer'          => '',
        );
    }

    return get_option($key, isset($defaults[$key]) ? $defaults[$key] : '');
}

/* -------------------------------------------------------------------------
 * Admin: settings page
 * ---------------------------------------------------------------------- */

function zen_register_options() {
    $checkbox_options = array(
        'zen_show_site_intro',
        'zen_show_featured_image',
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
    register_setting('zen_options', 'zen_content_width', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_content_width',
    ));
    register_setting('zen_options', 'zen_reading_width', array(
        'type'              => 'integer',
        'sanitize_callback' => 'zen_sanitize_reading_width',
    ));
    register_setting('zen_options', 'zen_list_style', array(
        'type'              => 'string',
        'sanitize_callback' => 'zen_sanitize_list_style',
    ));
    register_setting('zen_options', 'zen_category_ids', array(
        'type'              => 'array',
        'sanitize_callback' => 'zen_sanitize_category_ids',
    ));
    register_setting('zen_options', 'zen_footer_text', array(
        'type'              => 'string',
        'sanitize_callback' => 'wp_kses_post',
    ));
    register_setting('zen_options', 'zen_copyright_license', array(
        'type'              => 'string',
        'sanitize_callback' => 'zen_sanitize_copyright_license',
    ));

    register_setting('zen_options', 'zen_site_start_date', array(
        'type'              => 'string',
        'sanitize_callback' => 'zen_sanitize_site_start_date',
    ));

    foreach (array('head', 'body', 'footer') as $position) {
        $key = 'zen_code_' . $position;
        register_setting('zen_options', $key, array(
            'type'              => 'string',
            'show_in_rest'      => false,
            'sanitize_callback' => function ($value) use ($key) {
                if (!current_user_can('unfiltered_html') || !is_string($value)) {
                    return get_option($key, '');
                }
                return $value;
            },
        ));
    }
}
add_action('admin_init', 'zen_register_options');

// Raw markup is intentional: only unfiltered_html users may save these fields.
foreach (array('wp_head' => 'head', 'wp_body_open' => 'body', 'wp_footer' => 'footer') as $zen_hook => $zen_position) {
    add_action($zen_hook, function () use ($zen_position) {
        $code = zen_get_option('zen_code_' . $zen_position);
        if (is_string($code) && '' !== trim($code)) {
            echo "\n" . $code . "\n";
        }
    }, 20);
}

function zen_sanitize_checkbox($value) {
    return empty($value) ? 0 : 1;
}

function zen_sanitize_excerpt_length($value) {
    return zen_sanitize_number($value, 'zen_excerpt_length', 1, 300);
}

function zen_sanitize_content_width($value) {
    return zen_sanitize_number($value, 'zen_content_width', 600, 1920);
}

function zen_sanitize_reading_width($value) {
    return zen_sanitize_number($value, 'zen_reading_width', 600, 960);
}

function zen_sanitize_number($value, $key, $min, $max) {
    if ((is_int($value) || is_string($value)) && preg_match('/^\d+$/', (string) $value) && $value >= $min && $value <= $max) {
        return (int) $value;
    }
    add_settings_error($key, $key . '_invalid', sprintf(__('请输入 %1$d–%2$d 范围内的整数；已保留上次保存值。', 'zen'), $min, $max));
    return zen_get_option($key);
}

function zen_sanitize_list_style($value) {
    return in_array($value, array('standard', 'compact'), true) ? $value : 'standard';
}

function zen_sanitize_category_ids($value) {
    $ids = array();
    foreach (is_array($value) ? $value : array() as $id) {
        if ((is_int($id) || is_string($id)) && preg_match('/^\d+$/', (string) $id) && (int) $id > 0) {
            $ids[] = (int) $id;
        }
    }
    return array_values(array_unique($ids));
}

function zen_sanitize_theme_mode($value) {
    return in_array($value, array('auto', 'light', 'dark'), true) ? $value : 'auto';
}

function zen_sanitize_font_family($value) {
    return in_array($value, array('inter', 'space-grotesk', 'system'), true) ? $value : 'system';
}

function zen_sanitize_copyright_license($value) {
    $licenses = array(
        'none',
        'all-rights-reserved',
        'cc-by-4.0',
        'cc-by-sa-4.0',
        'cc-by-nc-sa-4.0',
    );

    return in_array($value, $licenses, true) ? $value : 'none';
}

function zen_sanitize_site_start_date($value) {
    if (!is_string($value)) {
        add_settings_error('zen_site_start_date', 'zen_site_start_date_invalid', __('请输入有效日期（YYYY-MM-DD）；已保留上次保存值。', 'zen'));
        return zen_get_option('zen_site_start_date');
    }
    $value = sanitize_text_field(trim($value));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if ($dt && $dt->format('Y-m-d') === $value && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return $value;
        }
    }
    add_settings_error('zen_site_start_date', 'zen_site_start_date_invalid', __('请输入有效日期（YYYY-MM-DD）；已保留上次保存值。', 'zen'));
    return zen_get_option('zen_site_start_date');
}

function zen_get_update_info() {
    $transient_key = 'zen_theme_update_info';

    $cached = get_transient($transient_key);
    if (false !== $cached) {
        return $cached;
    }

    $result = array(
        'latest_version' => '',
        'url'            => 'https://github.com/yahuisme/wordpress-zen/releases',
        'error'          => '',
    );
    $response = wp_remote_get('https://api.github.com/repos/yahuisme/wordpress-zen/releases/latest', array(
        'timeout'    => 5,
        'user-agent' => 'Zen/' . wp_get_theme()->get('Version'),
        'headers'    => array('Accept' => 'application/vnd.github+json'),
    ));

    if (is_wp_error($response)) {
        $result['error'] = $response->get_error_message();
    } elseif (200 !== wp_remote_retrieve_response_code($response)) {
        $result['error'] = __('暂时无法连接 GitHub。', 'zen');
    } else {
        $release = json_decode(wp_remote_retrieve_body($response), true);
        $tag = isset($release['tag_name']) ? sanitize_text_field($release['tag_name']) : '';
        if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $matches)) {
            $result['latest_version'] = $matches[1];
            $result['url'] = !empty($release['html_url']) ? esc_url_raw($release['html_url']) : $result['url'];
        } else {
            $result['error'] = __('GitHub 返回的版本信息无效。', 'zen');
        }
    }

    set_transient($transient_key, $result, 12 * HOUR_IN_SECONDS);
    return $result;
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
    add_menu_page(
        __('Zen 主题设置', 'zen'),
        __('Zen 主题设置', 'zen'),
        'manage_options',
        'zen-options',
        'zen_options_page_html',
        'dashicons-admin-appearance',
        81
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

function zen_options_admin_assets($hook) {
    if ('toplevel_page_zen-options' !== $hook) {
        return;
    }
    $version = wp_get_theme()->get('Version');
    wp_enqueue_style('zen-admin', get_theme_file_uri('/assets/css/admin.css'), array(), $version);
    wp_enqueue_script('zen-admin', get_theme_file_uri('/assets/js/admin.js'), array(), $version, true);
}
add_action('admin_enqueue_scripts', 'zen_options_admin_assets');

function zen_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $reading_width = zen_get_option('zen_reading_width');
    ?>
    <div class="wrap zen-options">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php settings_errors(); ?>
        <form method="post" action="options.php" id="zen-options-form">
            <?php settings_fields('zen_options'); ?>
            <div class="zen-save-bar">
                <?php submit_button(__('保存设置', 'zen'), 'primary', 'zen_save_top', false); ?>
                <span id="zen-save-state" role="status" aria-live="polite" data-clean="<?php echo esc_attr(__('没有未保存的修改', 'zen')); ?>" data-dirty="<?php echo esc_attr(__('有未保存的修改', 'zen')); ?>"><?php esc_html_e('没有未保存的修改', 'zen'); ?></span>
            </div>
            <details class="zen-options-group" id="zen-reading" open>
                <summary><?php esc_html_e('排版与阅读', 'zen'); ?></summary>
                <div class="zen-group-content">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="zen_theme_mode_default"><?php esc_html_e('默认外观', 'zen'); ?></label></th>
                            <td><select name="zen_theme_mode_default" id="zen_theme_mode_default">
                                <?php foreach (array('auto' => '跟随系统', 'light' => '浅色', 'dark' => '深色') as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($value, zen_get_option('zen_theme_mode_default')); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select><p class="description"><?php esc_html_e('访客首次访问时的默认模式。', 'zen'); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="zen_font_family"><?php esc_html_e('排版方案', 'zen'); ?></label></th>
                            <td><select name="zen_font_family" id="zen_font_family" aria-describedby="zen-font-help">
                                <?php foreach (array('system' => '系统排版', 'inter' => '现代衬线', 'space-grotesk' => '现代无衬线') as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($value, zen_get_option('zen_font_family')); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select><p class="description" id="zen-font-help"><?php esc_html_e('系统排版不加载外部字体；现代衬线使用 Inter 正文与 Noto Serif SC 标题，现代无衬线使用 Space Grotesk 组合。后两者从 Google Fonts 加载字体，会向其发送字体请求。', 'zen'); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="zen_reading_width"><?php esc_html_e('正文宽度', 'zen'); ?></label></th>
                            <td>
                                <label class="screen-reader-text" for="zen_reading_width_preset"><?php esc_html_e('正文宽度预设', 'zen'); ?></label>
                                <select id="zen_reading_width_preset" aria-describedby="zen-reading-width-help">
                                    <?php foreach (array(600, 720, 800, 960) as $width) : ?>
                                        <option value="<?php echo esc_attr($width); ?>" <?php selected($width, $reading_width); ?>><?php echo esc_html($width . ' px'); ?></option>
                                    <?php endforeach; ?>
                                    <option value="custom" <?php selected(false, in_array((int) $reading_width, array(600, 720, 800, 960), true)); ?>><?php esc_html_e('自定义', 'zen'); ?></option>
                                </select>
                                <input type="number" name="zen_reading_width" id="zen_reading_width" value="<?php echo esc_attr($reading_width); ?>" min="600" max="960" step="1" class="small-text" aria-describedby="zen-reading-width-help"> px
                                <p class="description" id="zen-reading-width-help"><?php esc_html_e('正文最大宽度，默认 720px；自定义范围 600–960px。选择预设或直接输入。', 'zen'); ?></p>
                            </td>
                        </tr>
                        <?php zen_checkbox_field('zen_show_reading_progress', __('阅读进度条', 'zen'), __('在页面顶部显示阅读进度条', 'zen')); ?>
                        <?php zen_checkbox_field('zen_show_back_to_top', __('返回顶部', 'zen'), __('显示返回顶部按钮', 'zen')); ?>
                        <?php zen_checkbox_field('zen_show_search_shortcut', __('搜索快捷键', 'zen'), __('启用 Ctrl/⌘ + K 搜索', 'zen')); ?>
                    </table>
                    <details class="zen-options-subgroup" id="zen-layout">
                        <summary><?php esc_html_e('高级版面宽度', 'zen'); ?></summary>
                        <table class="form-table" role="presentation"><tr>
                            <th scope="row"><label for="zen_content_width"><?php esc_html_e('整体内容宽度', 'zen'); ?></label></th>
                            <td><input type="number" name="zen_content_width" id="zen_content_width" value="<?php echo esc_attr(zen_get_option('zen_content_width')); ?>" min="600" max="1920" step="1" class="small-text"> px
                            <p class="description"><?php esc_html_e('整体版面最大宽度，默认 900px（600–1920px）。保留原有值，不等同于正文宽度。', 'zen'); ?></p></td>
                        </tr></table>
                    </details>
                </div>
            </details>
            <details class="zen-options-group" id="zen-list" open>
                <summary><?php esc_html_e('文章列表', 'zen'); ?></summary>
                <div class="zen-group-content"><table class="form-table" role="presentation">
                    <?php zen_checkbox_field('zen_show_site_intro', __('站点简介', 'zen'), __('显示 WordPress 站点副标题（在原生站点身份中编辑）', 'zen')); ?>
                    <tr><th scope="row"><label for="zen_list_style"><?php esc_html_e('列表样式', 'zen'); ?></label></th>
                        <td><select name="zen_list_style" id="zen_list_style">
                            <option value="standard" <?php selected('standard', zen_get_option('zen_list_style')); ?>><?php esc_html_e('标准', 'zen'); ?></option>
                            <option value="compact" <?php selected('compact', zen_get_option('zen_list_style')); ?>><?php esc_html_e('紧凑', 'zen'); ?></option>
                        </select></td></tr>
                    <tr><th scope="row"><label for="zen_excerpt_length"><?php esc_html_e('摘要长度', 'zen'); ?></label></th>
                        <td><input type="number" name="zen_excerpt_length" id="zen_excerpt_length" value="<?php echo esc_attr(zen_get_option('zen_excerpt_length')); ?>" min="1" max="300" step="1" class="small-text"> 字
                        <p class="description"><?php esc_html_e('标准列表的自动摘要字数（1–300 字）。', 'zen'); ?></p></td></tr>
                    <tr><th scope="row" id="zen-categories-label"><?php esc_html_e('分类导航', 'zen'); ?></th>
                        <td><input type="hidden" name="zen_category_ids[]" value="">
                            <fieldset id="zen_category_ids" class="zen-category-choices" aria-labelledby="zen-categories-label" aria-describedby="zen-categories-help">
                            <?php $categories = get_categories(array('hide_empty' => false)); ?>
                            <?php if (!is_wp_error($categories)) : foreach ($categories as $category) : ?>
                                <label><input type="checkbox" name="zen_category_ids[]" value="<?php echo esc_attr($category->term_id); ?>" <?php checked(true, in_array((int) $category->term_id, zen_sanitize_category_ids(zen_get_option('zen_category_ids')), true)); ?>> <?php echo esc_html($category->name); ?></label>
                            <?php endforeach; endif; ?>
                            </fieldset><p class="description" id="zen-categories-help"><?php esc_html_e('全部不勾选时显示所有非空分类；勾选后仅显示所选分类。', 'zen'); ?></p>
                        </td></tr>
                </table></div>
            </details>
            <details class="zen-options-group" id="zen-post" open>
                <summary><?php esc_html_e('文章详情', 'zen'); ?></summary>
                <div class="zen-group-content"><table class="form-table" role="presentation">
                    <?php zen_checkbox_field('zen_show_featured_image', __('特色图', 'zen'), __('在文章页展示原生特色图；首页不默认堆叠缩略图', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_toc', __('文章目录', 'zen'), __('显示文章目录（桌面侧栏 / 移动端抽屉）', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_reading_time', __('预计阅读时间', 'zen'), __('显示预计阅读时间', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_updated_date', __('最后更新时间', 'zen'), __('显示最后更新时间', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_tags', __('标签', 'zen'), __('显示文章底部标签', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_post_navigation', __('上一篇 / 下一篇', 'zen'), __('显示相邻文章导航', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_lightbox', __('图片灯箱', 'zen'), __('点击正文图片放大查看', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_highlight', __('代码高亮', 'zen'), __('启用代码块语法高亮', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_reading_count', __('浏览次数', 'zen'), __('显示简单浏览次数；整页缓存命中不计数，关闭后停止计数。不是独立访客统计。', 'zen')); ?>
                    <tr><th scope="row"><label for="zen_copyright_license"><?php esc_html_e('版权协议', 'zen'); ?></label></th>
                        <td><select name="zen_copyright_license" id="zen_copyright_license">
                            <?php foreach (array('none' => '不显示', 'all-rights-reserved' => '保留所有权利', 'cc-by-4.0' => 'CC BY 4.0', 'cc-by-sa-4.0' => 'CC BY-SA 4.0', 'cc-by-nc-sa-4.0' => 'CC BY-NC-SA 4.0') as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($value, zen_get_option('zen_copyright_license')); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select><p class="description"><?php esc_html_e('文章底部的协议声明。请仅选择你明确授予的许可。', 'zen'); ?></p></td></tr>
                </table></div>
            </details>
            <details class="zen-options-group" id="zen-footer" open>
                <summary><?php esc_html_e('页脚', 'zen'); ?></summary>
                <div class="zen-group-content"><table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="zen_footer_text"><?php esc_html_e('自定义页脚', 'zen'); ?></label></th>
                        <td><textarea name="zen_footer_text" id="zen_footer_text" rows="4" class="large-text code" placeholder="例如：&lt;a href=&quot;https://example.com&quot;&gt;Hosted by Example&lt;/a&gt;"><?php echo esc_textarea(zen_get_option('zen_footer_text')); ?></textarea>
                        <p class="description"><?php esc_html_e('支持安全 HTML 标签；留空不显示。', 'zen'); ?></p></td></tr>
                    <tr><th scope="row"><label for="zen_site_start_date"><?php esc_html_e('博客起始日期', 'zen'); ?></label></th>
                        <td><input type="text" name="zen_site_start_date" id="zen_site_start_date" value="<?php echo esc_attr(zen_get_option('zen_site_start_date')); ?>" placeholder="<?php echo esc_attr(__('例如：2022-07-01', 'zen')); ?>" class="regular-text" style="width: 160px;" pattern="\d{4}-\d{2}-\d{2}" autocomplete="off">
                        <p class="description"><?php esc_html_e('页脚运行时间的起点（YYYY-MM-DD）。留空按首篇公开文章计算。', 'zen'); ?></p></td></tr>
                    <?php zen_checkbox_field('zen_show_footer_theme_by', __('主题署名', 'zen'), __('显示 Theme By RyanZ', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_footer_wordpress', __('WordPress 署名', 'zen'), __('显示 Powered By WordPress', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_footer_rss', __('RSS', 'zen'), __('显示 RSS 订阅链接', 'zen')); ?>
                </table></div>
            </details>
            <details class="zen-options-group" id="zen-code">
                <summary><?php esc_html_e('高级代码', 'zen'); ?></summary>
                <div class="zen-group-content">
                    <p><?php esc_html_e('代码将直接输出到页面，可运行脚本并影响安全与加载速度。仅粘贴可信来源代码；更换主题后这些代码不再输出。长期统计或业务功能请使用插件。', 'zen'); ?></p>
                    <?php if (current_user_can('unfiltered_html')) : ?>
                        <table class="form-table" role="presentation">
                            <?php foreach (array('head' => array('头部代码', '输出到 </head> 前。'), 'body' => array('正文起始代码', '输出到 <body> 起始处。'), 'footer' => array('页脚代码', '输出到 </body> 前。')) as $position => $field) : ?>
                            <tr><th scope="row"><label for="zen_code_<?php echo esc_attr($position); ?>"><?php echo esc_html($field[0]); ?></label></th>
                                <td><textarea name="zen_code_<?php echo esc_attr($position); ?>" id="zen_code_<?php echo esc_attr($position); ?>" rows="6" class="large-text code" spellcheck="false" placeholder="<?php echo esc_attr(__('完整 HTML 或 script 代码', 'zen')); ?>"><?php echo esc_textarea(zen_get_option('zen_code_' . $position)); ?></textarea>
                                <p class="description"><?php echo esc_html($field[1]); ?></p></td></tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e('当前账号无权编辑原始代码；保存其他设置不会更改已有代码。', 'zen'); ?></p>
                    <?php endif; ?>
                </div>
            </details>
            <?php submit_button(__('保存设置', 'zen')); ?>
        </form>
        <section class="zen-options-group zen-options-about" id="zen-about" aria-labelledby="zen-about-title">
            <h2 id="zen-about-title"><?php esc_html_e('关于更新', 'zen'); ?></h2>
            <?php
            $version = wp_get_theme()->get('Version');
            $update = zen_get_update_info();
            ?>
            <p><?php echo esc_html(sprintf(__('当前版本 v%s。更新检查结果缓存 12 小时，不会自动安装。', 'zen'), $version)); ?></p>
            <?php if ($update['error']) : ?>
                <p><?php echo esc_html($update['error']); ?></p>
            <?php elseif (!$update['latest_version']) : ?>
                <p><?php esc_html_e('暂未获得有效的最新版本信息。', 'zen'); ?></p>
            <?php elseif (version_compare($update['latest_version'], $version, '>')) : ?>
                <p><?php echo esc_html(sprintf(__('有新版本 v%s 可供下载。', 'zen'), $update['latest_version'])); ?></p>
            <?php else : ?>
                <p><?php echo esc_html(sprintf(__('当前版本 v%s 已是最新版本。', 'zen'), $version)); ?></p>
            <?php endif; ?>
            <a class="button button-secondary" href="<?php echo esc_url($update['url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr(__('GitHub 发布页（在新窗口打开）', 'zen')); ?>"><?php esc_html_e('GitHub 发布页', 'zen'); ?></a>
        </section>
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

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if (preg_match('/bot\b|spider|crawler|slurp|bingpreview|facebookexternalhit|headlesschrome/i', $user_agent) || (function_exists('is_bot') && is_bot())) {
        return;
    }

    if (is_user_logged_in()) {
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
