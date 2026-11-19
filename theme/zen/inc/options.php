<?php
if (!defined('ABSPATH')) exit;

/**
 * Theme options: a minimal settings page plus front-end helpers.
 *
 * Settings are stored via the Settings API in the options table (no custom
 * database tables). The reading count is stored per post in post meta.
 */

/** Defaults and sanitizers for ordinary settings, in registration order. */
function zen_option_schema() {
    return array(
        'zen_show_site_intro'       => array(1, 'zen_sanitize_checkbox'),
        'zen_show_latest_card'      => array(1, 'zen_sanitize_checkbox'),
        'zen_show_featured_image'   => array(1, 'zen_sanitize_checkbox'),
        'zen_show_reading_count'    => array(0, 'zen_sanitize_checkbox'),
        'zen_show_tags'             => array(1, 'zen_sanitize_checkbox'),
        'zen_show_highlight'        => array(1, 'zen_sanitize_checkbox'),
        'zen_show_toc'              => array(1, 'zen_sanitize_checkbox'),
        'zen_show_updated_date'     => array(1, 'zen_sanitize_checkbox'),
        'zen_show_reading_time'     => array(1, 'zen_sanitize_checkbox'),
        'zen_show_post_navigation'  => array(1, 'zen_sanitize_checkbox'),
        'zen_show_reading_progress' => array(1, 'zen_sanitize_checkbox'),
        'zen_show_back_to_top'      => array(1, 'zen_sanitize_checkbox'),
        'zen_show_lightbox'         => array(1, 'zen_sanitize_checkbox'),
        'zen_show_search_shortcut'  => array(1, 'zen_sanitize_checkbox'),
        'zen_show_footer_rss'       => array(1, 'zen_sanitize_checkbox'),
        'zen_excerpt_length'       => array(60, 'zen_sanitize_excerpt_length'),
        'zen_theme_mode_default'    => array('auto', 'zen_sanitize_theme_mode'),
        'zen_content_width'        => array(992, 'zen_sanitize_content_width'),
        'zen_reading_width'         => array(800, 'zen_sanitize_reading_width'),
        'zen_list_style'            => array('standard', 'zen_sanitize_list_style'),
        'zen_category_ids'          => array(array(), 'zen_sanitize_category_ids'),
        'zen_footer_text'           => array('', 'wp_kses_post'),
        'zen_copyright_license'     => array('none', 'zen_sanitize_copyright_license'),
        'zen_site_start_date'       => array('', 'zen_sanitize_site_start_date'),
    );
}

function zen_code_fields() {
    return array(
        'zen_code_head'   => array('wp_head', '头部代码', '输出到 </head> 前。'),
        'zen_code_body'   => array('wp_body_open', '正文起始代码', '输出到 <body> 起始处。'),
        'zen_code_footer' => array('wp_footer', '页脚代码', '输出到 </body> 前。'),
    );
}

/** These are theme read fallbacks, not defaults registered with WordPress. */
function zen_get_option_defaults() {
    static $defaults;
    if ($defaults === null) {
        $defaults = array_map(static function ($field) { return $field[0]; }, zen_option_schema())
            + array_fill_keys(array_keys(zen_code_fields()), '');
    }
    return $defaults;
}

function zen_get_option($key) {
    return get_option($key, zen_get_option_defaults()[$key] ?? '');
}

function zen_register_options() {
    foreach (zen_option_schema() as $key => list($default, $sanitize)) {
        register_setting('zen_options', $key, array(
            'type'              => gettype($default),
            'sanitize_callback' => $sanitize,
        ));
    }

    foreach (array_keys(zen_code_fields()) as $key) {
        register_setting('zen_options', $key, array(
            'type'              => 'string',
            'show_in_rest'      => false,
            'sanitize_callback' => static function ($value) use ($key) {
                if (!current_user_can('unfiltered_html') || !is_string($value)) {
                    return get_option($key, '');
                }
                return $value;
            },
        ));
    }
}
add_action('admin_init', 'zen_register_options');

/** Keep Core's capability, nonce and save flow; verify the values it attempted to save. */
function zen_prepare_settings_save() {
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || 'zen_options' !== ($_POST['option_page'] ?? '') || 'update' !== ($_POST['action'] ?? '')) {
        return;
    }

    // Core appends settings-updated to the form's referer, which may be a reset result.
    add_filter('wp_redirect', static function ($location) {
        return $location ? remove_query_arg('zen_reset', $location) : $location;
    });

    $zen_options = zen_get_option_defaults();
    $submitted = array_keys(array_intersect_key($zen_options, $_POST));
    $omitted = array_keys(array_diff_key($zen_options, $_POST));
    // Core normally sends null to sanitizers for missing controls. Zen's clearable
    // controls submit explicit empty values, while restricted fields may be absent.
    add_filter('allowed_options', static function ($allowed) use ($omitted) {
        if (isset($allowed['zen_options'])) {
            $allowed['zen_options'] = array_values(array_diff($allowed['zen_options'], $omitted));
        }
        return $allowed;
    });

    $expected = array();
    foreach ($submitted as $key) {
        add_filter('sanitize_option_' . $key, static function ($value) use ($key, &$expected) {
            $expected[$key] = $value;
            return $value;
        }, PHP_INT_MAX);
    }
    // Core ignores update_option()'s result (false also means unchanged).
    // Check the sanitized values just before Core persists the redirect notices.
    add_filter('pre_set_transient_settings_errors', static function ($notices) use (&$expected) {
        $failed = array();
        foreach ($expected as $key => $value) {
            $stored = get_option($key, null);
            if ($stored !== $value && !(is_scalar($stored) && is_scalar($value) && (string) $stored === (string) $value)) {
                $failed[] = array(
                    'setting' => $key,
                    'code' => $key . '_not_saved',
                    'message' => sprintf(__('设置 %s 未能保存，请重试。', 'zen'), $key),
                    'type' => 'error',
                );
            }
        }
        if (!$failed) return $notices;
        foreach ($notices as $index => $notice) {
            if ('settings_updated' === $notice['code'] && in_array($notice['type'], array('success', 'updated'), true)) {
                unset($notices[$index]);
            }
        }
        return array_merge($notices, $failed);
    });
}
add_action('load-options.php', 'zen_prepare_settings_save');

/**
 * Restore only Zen-owned settings through the native admin-post boundary.
 */
function zen_reset_options() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('你没有执行此操作的权限。', 'zen'), '', array('response' => 403));
    }
    check_admin_referer('zen_reset_options', 'zen_reset_nonce');

    $can_reset_code = current_user_can('unfiltered_html');
    $code_fields = zen_code_fields();
    $failed = false;
    $missing = new stdClass();
    foreach (array_keys(zen_get_option_defaults()) as $key) {
        if (!$can_reset_code && isset($code_fields[$key])) continue;
        if (get_option($key, $missing) !== $missing && !delete_option($key)) $failed = true;
    }
    delete_transient('zen_site_uptime');
    delete_transient('zen_archives_public_posts_v3');

    wp_safe_redirect(add_query_arg(array(
        'page'      => 'zen-options',
        'zen_reset' => $failed ? 'error' : ($can_reset_code ? '1' : 'partial'),
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_post_zen_reset_options', 'zen_reset_options');

// Raw markup is intentional: only unfiltered_html users may save these fields.
foreach (zen_code_fields() as $zen_key => list($zen_hook)) {
    add_action($zen_hook, static function () use ($zen_key) {
        $code = zen_get_option($zen_key);
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
            $ids[(int) $id] = (int) $id;
        }
    }
    return array_values($ids);
}

function zen_sanitize_theme_mode($value) {
    return in_array($value, array('auto', 'light', 'dark'), true) ? $value : 'auto';
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

function zen_get_published_block_pattern($ref) {
    if (!is_int($ref) || $ref <= 0) {
        return null;
    }
    $pattern = get_post($ref);
    if (!$pattern || 'wp_block' !== $pattern->post_type || 'publish' !== $pattern->post_status || '' !== $pattern->post_password) {
        return null;
    }
    return $pattern;
}

function zen_reading_block_text($blocks, $ancestors = array()) {
    $text = '';
    foreach ($blocks as $block) {
        if ('core/block' === $block['blockName']) {
            $ref = $block['attrs']['ref'] ?? 0;
            if (!is_int($ref) || $ref <= 0 || isset($ancestors[$ref])) continue;
            $pattern = zen_get_published_block_pattern($ref);
            if (!$pattern) continue;
            $path = $ancestors;
            $path[$ref] = true;
            $text .= zen_reading_block_text(parse_blocks($pattern->post_content), $path);
        } else {
            $text .= $block['innerHTML'];
            $text .= zen_reading_block_text($block['innerBlocks'], $ancestors);
        }
    }
    return $text;
}

function zen_get_reading_time($post_id = 0) {
    $content = get_post_field('post_content', $post_id ? $post_id : get_the_ID());
    if (has_block('core/block', $content)) {
        $content = zen_reading_block_text(parse_blocks($content));
    }
    $content = strip_shortcodes($content);
    $content = preg_replace('/<!--\s*wp:.*?-->/s', ' ', $content);
    $content = trim(html_entity_decode(wp_strip_all_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

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
    $dir = get_template_directory();
    $uri = get_template_directory_uri();
    wp_enqueue_style('zen-admin', $uri . '/assets/css/admin.css', array(), filemtime($dir . '/assets/css/admin.css'));
    wp_enqueue_script('zen-admin', $uri . '/assets/js/admin.js', array(), filemtime($dir . '/assets/js/admin.js'), true);
}
add_action('admin_enqueue_scripts', 'zen_options_admin_assets');

function zen_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $reading_width = zen_get_option('zen_reading_width');
    $theme_mode = zen_get_option('zen_theme_mode_default');
    $list_style = zen_get_option('zen_list_style');
    $license = zen_get_option('zen_copyright_license');
    // The Settings API redirect preserves the previous request's query string.
    $reset = isset($_GET['settings-updated']) ? '' : ($_GET['zen_reset'] ?? '');
    ?>
    <div class="wrap zen-options">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php settings_errors(); ?>
        <form method="post" action="options.php" id="zen-options-form">
            <?php settings_fields('zen_options'); ?>
            <?php if ('1' === $reset) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Zen 设置已恢复默认值。', 'zen'); ?></p></div>
            <?php elseif ('partial' === $reset) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('可管理的 Zen 设置已恢复默认值；受权限限制，自定义代码已保留。', 'zen'); ?></p></div>
            <?php elseif ('error' === $reset) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('部分设置未能恢复默认值，请重试并检查数据库写入权限。', 'zen'); ?><?php if (!current_user_can('unfiltered_html')) : ?> <?php esc_html_e('受权限限制，自定义代码已保留。', 'zen'); ?><?php endif; ?></p></div>
            <?php endif; ?>
            <div class="zen-save-bar">
                <?php submit_button(__('保存设置', 'zen'), 'primary', 'zen_save_top', false); ?>
                <span id="zen-save-state" role="status" aria-live="polite" data-clean="<?php echo esc_attr(__('没有未保存的修改', 'zen')); ?>" data-dirty="<?php echo esc_attr(__('有未保存的修改', 'zen')); ?>"><?php esc_html_e('没有未保存的修改', 'zen'); ?></span>
                <button type="submit" form="zen-reset-form" class="button" data-zen-confirm="<?php echo esc_attr(__('确定恢复 Zen 全部设置的默认值吗？此操作不可撤销。', 'zen')); ?>"><?php esc_html_e('恢复默认设置', 'zen'); ?></button>
            </div>
            <details class="zen-options-group" id="zen-reading" open>
                <summary><?php esc_html_e('排版与阅读', 'zen'); ?></summary>
                <div class="zen-group-content">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="zen_theme_mode_default"><?php esc_html_e('默认外观', 'zen'); ?></label></th>
                            <td><select name="zen_theme_mode_default" id="zen_theme_mode_default">
                                <?php foreach (array('auto' => '跟随系统', 'light' => '浅色', 'dark' => '深色') as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $theme_mode); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select><p class="description"><?php esc_html_e('访客首次访问时的默认模式。', 'zen'); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="zen_reading_width"><?php esc_html_e('正文宽度', 'zen'); ?></label></th>
                            <td>
                                <label for="zen_reading_width_preset"><?php esc_html_e('预设', 'zen'); ?></label>
                                <select id="zen_reading_width_preset" aria-describedby="zen-reading-width-help">
                                    <?php $zen_reading_presets = array(600, 720, 768, 800, 960); ?>
                                    <?php foreach ($zen_reading_presets as $width) : ?>
                                        <option value="<?php echo esc_attr($width); ?>" <?php selected($width, $reading_width); ?>><?php echo esc_html($width . ' px'); ?></option>
                                    <?php endforeach; ?>
                                    <option value="custom" <?php selected(false, in_array((int) $reading_width, $zen_reading_presets, true)); ?>><?php esc_html_e('自定义', 'zen'); ?></option>
                                </select>
                                <input type="number" name="zen_reading_width" id="zen_reading_width" value="<?php echo esc_attr($reading_width); ?>" min="600" max="960" step="1" required class="small-text" aria-describedby="zen-reading-width-help"> px
                                <p class="description" id="zen-reading-width-help"><?php printf(esc_html__('正文最大宽度，默认 %dpx；自定义范围 600–960px。选择预设或直接输入。', 'zen'), zen_get_option_defaults()['zen_reading_width']); ?></p>
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
                            <td><input type="number" name="zen_content_width" id="zen_content_width" value="<?php echo esc_attr(zen_get_option('zen_content_width')); ?>" min="600" max="1920" step="1" required class="small-text"> px
                            <p class="description"><?php esc_html_e('整体版面最大宽度，默认 992px（600–1920px）。保留原有值，不等同于正文宽度。', 'zen'); ?></p></td>
                        </tr></table>
                    </details>
                </div>
            </details>
            <details class="zen-options-group" id="zen-list" open>
                <summary><?php esc_html_e('首页与文章列表', 'zen'); ?></summary>
                <div class="zen-group-content"><table class="form-table" role="presentation">
                    <?php zen_checkbox_field('zen_show_site_intro', __('站点简介', 'zen'), __('显示 WordPress 站点副标题（在原生站点身份中编辑）', 'zen')); ?>
                    <?php zen_checkbox_field('zen_show_latest_card', __('最新文章卡片', 'zen'), __('从文章索引首页选出最新的公开、无密码文章，不在下方重复显示。', 'zen')); ?>
                    <tr><th scope="row"><label for="zen_list_style"><?php esc_html_e('列表样式', 'zen'); ?></label></th>
                        <td><select name="zen_list_style" id="zen_list_style" aria-describedby="zen-list-style-help">
                            <option value="standard" <?php selected('standard', $list_style); ?>><?php esc_html_e('标准', 'zen'); ?></option>
                            <option value="compact" <?php selected('compact', $list_style); ?>><?php esc_html_e('紧凑', 'zen'); ?></option>
                        </select><p class="description" id="zen-list-style-help"><?php esc_html_e('紧凑模式省略卡片及文章列表的摘要。', 'zen'); ?></p></td></tr>
                    <tr><th scope="row"><label for="zen_excerpt_length"><?php esc_html_e('摘要长度', 'zen'); ?></label></th>
                        <td><input type="number" name="zen_excerpt_length" id="zen_excerpt_length" value="<?php echo esc_attr(zen_get_option('zen_excerpt_length')); ?>" min="1" max="300" step="1" required class="small-text" aria-describedby="zen-excerpt-length-help"> 字
                        <p class="description" id="zen-excerpt-length-help"><?php printf(esc_html__('标准模式下卡片和列表的摘要长度，默认 %d 字（1–300 字）。', 'zen'), zen_get_option_defaults()['zen_excerpt_length']); ?></p></td></tr>
                    <tr><th scope="row" id="zen-categories-label"><?php esc_html_e('分类归档导航', 'zen'); ?></th>
                        <td><input type="hidden" name="zen_category_ids[]" value="">
                            <fieldset id="zen_category_ids" class="zen-category-choices" aria-labelledby="zen-categories-label" aria-describedby="zen-categories-help">
                            <?php $categories = get_categories(array('hide_empty' => false, 'lang' => '')); ?>
                            <?php $selected_category_ids = array_flip(zen_sanitize_category_ids(zen_get_option('zen_category_ids'))); ?>
                            <?php foreach ($categories as $category) : ?>
                                <label><input type="checkbox" name="zen_category_ids[]" value="<?php echo esc_attr($category->term_id); ?>" <?php checked(true, isset($selected_category_ids[(int) $category->term_id])); ?>> <?php echo esc_html($category->name); ?></label>
                            <?php endforeach; ?>
                            </fieldset><p class="description" id="zen-categories-help"><?php esc_html_e('仅在分类归档中显示。全部不勾选时显示所有非空分类。', 'zen'); ?></p>
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
                    <?php zen_checkbox_field('zen_show_reading_count', __('浏览次数', 'zen'), __('同一 IP 五分钟内计数一次，登录用户不计数。', 'zen')); ?>
                    <tr><th scope="row"><label for="zen_copyright_license"><?php esc_html_e('版权协议', 'zen'); ?></label></th>
                        <td><select name="zen_copyright_license" id="zen_copyright_license">
                            <?php foreach (array('none' => '不显示', 'all-rights-reserved' => '保留所有权利', 'cc-by-4.0' => 'CC BY 4.0', 'cc-by-sa-4.0' => 'CC BY-SA 4.0', 'cc-by-nc-sa-4.0' => 'CC BY-NC-SA 4.0') as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $license); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select><p class="description"><?php esc_html_e('文章底部的协议声明。请仅选择你明确授予的许可。', 'zen'); ?></p>
                        <?php $legacy_copyright = get_option('zen_show_copyright', null); ?>
                        <?php if (null !== $legacy_copyright && !$legacy_copyright && 'none' !== $license) : ?>
                            <p><?php esc_html_e('旧版曾关闭版权声明，但当前已选协议。如不打算公开声明，请改为「不显示」并保存。', 'zen'); ?></p>
                        <?php endif; ?></td></tr>
                </table></div>
            </details>
            <details class="zen-options-group" id="zen-footer" open>
                <summary><?php esc_html_e('页脚', 'zen'); ?></summary>
                <div class="zen-group-content"><table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="zen_footer_text"><?php esc_html_e('自定义页脚', 'zen'); ?></label></th>
                        <td><textarea name="zen_footer_text" id="zen_footer_text" rows="4" class="large-text code" placeholder="例如：&lt;a href=&quot;https://example.com&quot;&gt;Hosted by Example&lt;/a&gt;"><?php echo esc_textarea(zen_get_option('zen_footer_text')); ?></textarea>
                        <p class="description"><?php esc_html_e('支持安全 HTML 标签；留空不显示。', 'zen'); ?></p></td></tr>
                    <tr><th scope="row"><label for="zen_site_start_date"><?php esc_html_e('博客起始日期', 'zen'); ?></label></th>
                        <td><input type="text" name="zen_site_start_date" id="zen_site_start_date" value="<?php echo esc_attr(zen_get_option('zen_site_start_date')); ?>" placeholder="<?php echo esc_attr(__('例如：2022-07-01', 'zen')); ?>" class="regular-text" pattern="\d{4}-\d{2}-\d{2}" autocomplete="off">
                        <p class="description"><?php esc_html_e('页脚运行时间的起点（YYYY-MM-DD）。留空按首篇公开文章计算。', 'zen'); ?></p></td></tr>
                    <?php zen_checkbox_field('zen_show_footer_rss', __('RSS', 'zen'), __('显示 RSS 订阅链接', 'zen')); ?>
                </table></div>
            </details>
            <details class="zen-options-group" id="zen-code">
                <summary><?php esc_html_e('高级代码', 'zen'); ?></summary>
                <div class="zen-group-content">
                    <p><?php esc_html_e('代码将直接输出到页面，可运行脚本并影响安全与加载速度。仅粘贴可信来源代码；更换主题后这些代码不再输出。长期统计或业务功能请使用插件。', 'zen'); ?></p>
                    <?php if (current_user_can('unfiltered_html')) : ?>
                        <table class="form-table" role="presentation">
                            <?php foreach (zen_code_fields() as $key => list(, $label, $description)) : ?>
                            <tr><th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                                <td><textarea name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" rows="6" class="large-text code" spellcheck="false" placeholder="<?php echo esc_attr(__('完整 HTML 或 script 代码', 'zen')); ?>"><?php echo esc_textarea(zen_get_option($key)); ?></textarea>
                                <p class="description"><?php echo esc_html($description); ?></p></td></tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e('当前账号无权编辑原始代码；保存其他设置不会更改已有代码。', 'zen'); ?></p>
                    <?php endif; ?>
                </div>
            </details>
            <?php submit_button(__('保存设置', 'zen')); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="zen-reset-form" class="zen-reset-form">
            <input type="hidden" name="action" value="zen_reset_options">
            <?php wp_nonce_field('zen_reset_options', 'zen_reset_nonce', false); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Front-end: reading count
 * ---------------------------------------------------------------------- */

function zen_can_count_post($post) {
    return $post && 'post' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password;
}

function zen_show_reading_count() {
    return zen_get_option('zen_show_reading_count') && is_singular('post') && !is_preview() && !is_feed()
        && zen_can_count_post(get_queried_object());
}

/** Return the current count, or false for a disabled/unavailable article. */
function zen_track_post_view($post_id = 0) {
    if (!zen_get_option('zen_show_reading_count')) {
        return false;
    }

    if (0 === $post_id) {
        if (!is_singular('post') || is_preview() || is_feed()) {
            return false;
        }
        $post_id = get_queried_object_id();
        if (!$post_id) $post_id = get_the_ID();
    }

    if (!is_int($post_id) || $post_id <= 0) return false;
    $post = get_post($post_id);
    if (!zen_can_count_post($post)) {
        return false;
    }
    $count = (int) get_post_meta($post_id, 'zen_view_count', true);

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    if (preg_match('/bot\b|spider|crawler|slurp|bingpreview|facebookexternalhit|headlesschrome/i', $user_agent) || (function_exists('is_bot') && is_bot())) {
        return $count;
    }

    if (is_user_logged_in()) {
        return $count;
    }

    // Trust the server-resolved peer only, never client-supplied forwarding headers.
    // Reverse proxies should resolve trusted client IPs in the web server config.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return $count;
    }
    // Canonicalize IPv6 so equivalent spellings share the five-minute bucket.
    $ip = inet_ntop(inet_pton($ip));
    $dedupe_key = 'zen_viewed_' . $post_id . '_' . md5($ip);
    if (false !== get_transient($dedupe_key)) {
        return $count;
    }
    // Serialize the cold metadata insert, increment and IP bucket together.
    // options.option_name is unique; postmeta's "unique" flag is only a read check.
    // Do not use a transient read/write pair as a lock (including with Redis).
    global $wpdb;
    $lock_key = 'zen_view_lock_' . $post_id;
    $token = wp_generate_uuid4();
    $deadline = microtime(true) + 1;
    $locked = false;
    do {
        $lease = (time() + 30) . ':' . $token;
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, %s)", $lock_key, $lease, 'no'));
        $owner = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $lock_key));
        if (is_string($owner) && (int) $owner < time()) {
            // Compare the complete lease: never evict a replacement owner's lock.
            $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND option_value = %s", $lease, $lock_key, $owner));
            $owner = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", $lock_key));
        }
        $locked = $owner === $lease;
        if (!$locked) usleep(10000);
    } while (!$locked && microtime(true) < $deadline);
    if (!$locked) return $count;

    try {
        // Another request may have filled either cache since our first read.
        wp_cache_delete($post_id, 'post_meta');
        if (wp_using_ext_object_cache()) {
            // Refresh a drop-in's request-local miss; deleting would erase the
            // other request's valid persistent bucket instead of re-reading it.
            wp_cache_get($dedupe_key, 'transient', true);
        } else {
            wp_cache_delete('_transient_' . $dedupe_key, 'options');
            wp_cache_delete('_transient_timeout_' . $dedupe_key, 'options');
            wp_cache_delete('notoptions', 'options');
        }
        $count = (int) get_post_meta($post_id, 'zen_view_count', true);
        if (false === get_transient($dedupe_key) && update_post_meta($post_id, 'zen_view_count', $count + 1)) {
            set_transient($dedupe_key, 1, 5 * MINUTE_IN_SECONDS);
        }
        return (int) get_post_meta($post_id, 'zen_view_count', true);
    } finally {
        $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s", $lock_key, $lease));
        wp_cache_delete($lock_key, 'options');
    }
}

/** Public, nonce-free counter: no privileged state, and safe for cached pages. */
function zen_ajax_post_view() {
    nocache_headers();
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
        wp_send_json_error(array('message' => 'POST required.'), 405);
        return;
    }
    $post_id = $_POST['post_id'] ?? '';
    if (!is_string($post_id) || !preg_match('/\A[1-9][0-9]*\z/', $post_id) || (string) (int) $post_id !== $post_id) {
        wp_send_json_error(array('message' => 'Invalid post ID.'), 400);
        return;
    }
    $count = zen_track_post_view((int) $post_id);
    if (false === $count) {
        wp_send_json_error(array('message' => 'Reading count unavailable.'), 404);
        return;
    }
    wp_send_json_success(array('count' => number_format_i18n($count)));
}
add_action('wp_ajax_nopriv_zen_post_view', 'zen_ajax_post_view');
add_action('wp_ajax_zen_post_view', 'zen_ajax_post_view');

function zen_get_reading_count($post_id = 0) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }

    return (int) get_post_meta($post_id, 'zen_view_count', true);
}
