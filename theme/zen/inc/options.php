<?php
if (!defined('ABSPATH')) exit;

/**
 * Theme options: a minimal settings page plus front-end helpers.
 *
 * Settings are stored via the Settings API in the options table (no custom
 * database tables). The reading count is stored per post in post meta.
 */

/** Defaults, sanitizers and display labels, in registration order. */
function zen_option_schema() {
    return array(
        'zen_show_site_intro'       => array(1, 'zen_sanitize_checkbox', '站点简介'),
        'zen_show_latest_card'      => array(1, 'zen_sanitize_checkbox', '最新文章卡片'),
        'zen_show_featured_image'   => array(1, 'zen_sanitize_checkbox', '特色图'),
        'zen_show_reading_count'    => array(0, 'zen_sanitize_checkbox', '浏览次数'),
        'zen_show_tags'             => array(1, 'zen_sanitize_checkbox', '标签'),
        'zen_show_highlight'        => array(1, 'zen_sanitize_checkbox', '代码高亮'),
        'zen_show_toc'              => array(1, 'zen_sanitize_checkbox', '文章目录'),
        'zen_show_updated_date'     => array(1, 'zen_sanitize_checkbox', '最后更新时间'),
        'zen_show_reading_time'     => array(1, 'zen_sanitize_checkbox', '预计阅读时间'),
        'zen_show_post_navigation'  => array(1, 'zen_sanitize_checkbox', '上一篇 / 下一篇'),
        'zen_show_reading_progress' => array(1, 'zen_sanitize_checkbox', '阅读进度条'),
        'zen_show_back_to_top'      => array(1, 'zen_sanitize_checkbox', '返回顶部'),
        'zen_show_lightbox'         => array(1, 'zen_sanitize_checkbox', '图片灯箱'),
        'zen_show_search_shortcut'  => array(1, 'zen_sanitize_checkbox', '搜索快捷键'),
        'zen_show_footer_rss'       => array(1, 'zen_sanitize_checkbox', 'RSS'),
        'zen_excerpt_length'        => array(60, 'zen_sanitize_excerpt_length', '摘要长度'),
        'zen_theme_mode_default'    => array('auto', 'zen_sanitize_theme_mode', '默认外观'),
        'zen_reading_width'         => array(800, 'zen_sanitize_reading_width', '正文宽度'),
        'zen_list_style'            => array('standard', 'zen_sanitize_list_style', '列表样式'),
        'zen_footer_text'           => array('', 'zen_sanitize_footer_text', '自定义页脚'),
        'zen_copyright_license'     => array('none', 'zen_sanitize_copyright_license', '版权协议'),
        'zen_site_start_date'       => array('', 'zen_sanitize_site_start_date', '博客起始日期'),
    );
}

function zen_code_fields() {
    return array(
        'zen_code_head'   => array('wp_head', '头部代码', '在 </head> 前输出'),
        'zen_code_body'   => array('wp_body_open', '正文起始代码', '紧接 <body> 输出'),
        'zen_code_footer' => array('wp_footer', '页脚代码', '在 </body> 前输出'),
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
                if (!current_user_can('unfiltered_html')) {
                    return get_option($key);
                }
                if (!is_string($value)) {
                    add_settings_error($key, $key . '_invalid', __('请输入有效的代码文本；已保留上次保存值。', 'zen'));
                    return get_option($key);
                }
                return $value;
            },
        ));
    }
}
add_action('admin_init', 'zen_register_options');

/** Keep Core's capability, nonce and save flow; verify the values it attempted to save. */
function zen_prepare_settings_save($allowed) {
    global $action, $option_page;
    // The native controller has already resolved the routing keys at this filter.
    if ('zen_options' !== $option_page || 'update' !== $action || !isset($allowed['zen_options'])) {
        return $allowed;
    }

    // Core appends settings-updated to the form's referer, which may be a reset result.
    add_filter('wp_redirect', static function ($location) {
        return $location ? remove_query_arg('zen_reset', $location) : $location;
    });

    $zen_options = zen_get_option_defaults();
    $code_fields = zen_code_fields();
    $submitted = array_intersect_key($zen_options, $_POST);
    if (!current_user_can('unfiltered_html')) {
        $submitted = array_diff_key($submitted, $code_fields);
    }
    $omitted = array_keys(array_diff_key($zen_options, $submitted));
    // Core sends null to sanitizers for missing controls. Skip only Zen-owned
    // omissions (or fields the user cannot edit); leave extensions alone.
    if ($omitted) $allowed['zen_options'] = array_values(array_diff($allowed['zen_options'], $omitted));

    // options.php trims scalar inputs before unslashing. Restore only this
    // form's authorized string values before the registered code sanitizer.
    foreach (array_intersect_key($submitted, $code_fields) as $key => $_) {
        if (!is_string($_POST[$key])) continue;
        add_filter('sanitize_option_' . $key, static function ($value) use ($key) {
            $raw = $_POST[$key] ?? null;
            if (!current_user_can('unfiltered_html') || !is_string($raw) || $value !== wp_unslash(trim($raw))) return $value;

            $code = wp_unslash($raw);
            $stored = get_option($key);
            // Browsers normalize textarea line endings, even when untouched.
            $lf = static fn($text) => str_replace(array("\r\n", "\r"), "\n", $text);
            return is_string($stored) && $lf($stored) === $lf($code) ? $stored : $code;
        }, 9);
    }

    if (!$submitted) return $allowed;
    $expected = array();
    foreach (array_keys($submitted) as $key) {
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
            // A rejected value can return Core's false sentinel for an absent row.
            $stored = get_option($key, $value === false ? false : null);
            if ($stored !== $value && !(is_int($value) && $stored === (string) $value)) {
                $failed[] = array(
                    'setting' => $key,
                    'code' => $key . '_not_saved',
                    'message' => __('未能保存本次提交的值；此处显示当前保存值，请重试。', 'zen'),
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
    return $allowed;
}
add_action('load-options.php', static function () {
    add_filter('allowed_options', 'zen_prepare_settings_save');
});

function zen_check_reset_request($action) {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('你没有执行此操作的权限。', 'zen'), '', array('response' => 403));
    }
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || !is_string($_POST['zen_reset_nonce'] ?? null)
        || $_POST['zen_reset_nonce'] !== ($_REQUEST['zen_reset_nonce'] ?? null)) {
        wp_die(esc_html__('此操作需要提交有效的表单。', 'zen'), '', array('response' => 400));
    }
    check_admin_referer($action, 'zen_reset_nonce');
}

function zen_reset_confirmation_message() {
    return current_user_can('unfiltered_html')
        ? __('恢复 Zen 默认设置将清除已保存的自定义代码，并丢弃未保存的修改。此操作无法撤销。', 'zen')
        : __('恢复 Zen 默认设置将丢弃未保存的修改。当前账号无权修改自定义代码，自定义代码将保留。此操作无法撤销。', 'zen');
}

/** First native POST: render the confirmation without touching settings or caches. */
function zen_request_reset_options() {
    zen_check_reset_request('zen_reset_options');
    ob_start();
    ?>
    <h1><?php esc_html_e('恢复默认设置', 'zen'); ?></h1>
    <p><?php echo esc_html(zen_reset_confirmation_message()); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="zen_reset_confirm">
        <?php wp_nonce_field('zen_reset_confirm', 'zen_reset_nonce', false); ?>
        <p><a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'zen-options'), admin_url('admin.php'))); ?>"><?php esc_html_e('取消', 'zen'); ?></a>
        <button type="submit" class="button button-primary"><?php esc_html_e('确认恢复默认设置', 'zen'); ?></button></p>
    </form>
    <?php
    wp_die(ob_get_clean(), esc_html__('恢复默认设置', 'zen'), array('response' => 200));
}
add_action('admin_post_zen_reset_options', 'zen_request_reset_options');

/** Restore only Zen-owned settings through the separately nonced confirm action. */
function zen_reset_options() {
    zen_check_reset_request('zen_reset_confirm');

    $can_reset_code = current_user_can('unfiltered_html');
    $code_fields = zen_code_fields();
    $failed = false;
    $missing = new stdClass();
    foreach (array_keys(zen_get_option_defaults()) as $key) {
        if (!$can_reset_code && isset($code_fields[$key])) continue;
        if (get_option($key, $missing) !== $missing && !delete_option($key)) $failed = true;
    }
    delete_transient('zen_site_uptime');
    zen_clear_archives_cache();

    wp_safe_redirect(add_query_arg(array(
        'page'      => 'zen-options',
        'zen_reset' => $failed ? 'error' : ($can_reset_code ? '1' : 'partial'),
    ), admin_url('admin.php')));
    exit;
}
add_action('admin_post_zen_reset_confirm', 'zen_reset_options');

// Raw markup is intentional: only unfiltered_html users may save these fields.
function zen_print_custom_code($key) {
    $code = zen_get_option($key);
    if (!is_string($code) || '' === trim($code)) {
        return;
    }
    echo "\n" . $code . "\n";
}

foreach (zen_code_fields() as $zen_key => list($zen_hook)) {
    add_action($zen_hook, static fn() => zen_print_custom_code($zen_key), 20);
}

function zen_sanitize_checkbox($value) {
    return empty($value) ? 0 : 1;
}

function zen_sanitize_excerpt_length($value) {
    return zen_sanitize_number($value, 'zen_excerpt_length', 1, 300);
}

function zen_sanitize_reading_width($value) {
    return zen_sanitize_number($value, 'zen_reading_width', 600, 960);
}

function zen_sanitize_number($value, $key, $min, $max) {
    // Match the number control's notation (including decimal/exponent forms).
    if (is_int($value) || (is_string($value) && preg_match('/\A-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/', $value))) {
        $number = (float) $value;
        if ($number >= $min && $number <= $max && floor($number) === $number) {
            return (int) $number;
        }
    }
    add_settings_error($key, $key . '_invalid', sprintf(__('请输入 %1$d–%2$d 范围内的整数；已保留上次保存值。', 'zen'), $min, $max));
    return get_option($key);
}

function zen_sanitize_footer_text($value) {
    if (!is_string($value)) {
        add_settings_error('zen_footer_text', 'zen_footer_text_invalid', __('请输入有效的页脚文本；已保留上次保存值。', 'zen'));
        return get_option('zen_footer_text');
    }
    return wp_kses_post($value);
}

function zen_sanitize_list_style($value) {
    return zen_sanitize_choice($value, 'zen_list_style', array('standard', 'compact'));
}

function zen_sanitize_theme_mode($value) {
    return zen_sanitize_choice($value, 'zen_theme_mode_default', array('auto', 'light', 'dark'));
}

function zen_sanitize_choice($value, $key, $choices) {
    if (in_array($value, $choices, true)) return $value;
    add_settings_error($key, $key . '_invalid', __('请选择此字段支持的选项；已保留上次保存值。', 'zen'));
    return get_option($key);
}

function zen_copyright_licenses() {
    return array(
        'none' => array('不显示', ''),
        'all-rights-reserved' => array('保留所有权利', ''),
        'cc-by-4.0' => array('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/deed.zh-hans'),
        'cc-by-sa-4.0' => array('CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/deed.zh-hans'),
        'cc-by-nc-sa-4.0' => array('CC BY-NC-SA 4.0', 'https://creativecommons.org/licenses/by-nc-sa/4.0/deed.zh-hans'),
    );
}

function zen_sanitize_copyright_license($value) {
    return zen_sanitize_choice($value, 'zen_copyright_license', array_keys(zen_copyright_licenses()));
}

function zen_sanitize_site_start_date($value) {
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '' || (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $date)
            && checkdate((int) $date[2], (int) $date[3], (int) $date[1]))) {
            return $value;
        }
    }
    add_settings_error('zen_site_start_date', 'zen_site_start_date_invalid', __('请输入有效日期（YYYY-MM-DD）；已保留上次保存值。', 'zen'));
    return get_option('zen_site_start_date');
}

/** Hand off one parsed root between Zen's readers, never to Core. */
function zen_parse_block_root($content, $reader) {
    static $previous = null;

    // A plugin can observe parser calls or choose a different parser on each call.
    if (!is_string($content) || has_filter('block_parser_class') || has_filter('all')) {
        $previous = null;
        return parse_blocks($content);
    }
    if ($previous && $previous['content'] === $content && $previous['reader'] !== $reader) {
        $blocks = $previous['blocks'];
        // Release the shared tree before Core renders the article separately.
        $previous = null;
        return $blocks;
    }
    $previous = null;
    $blocks = parse_blocks($content);
    $previous = array('content' => $content, 'reader' => $reader, 'blocks' => $blocks);
    return $blocks;
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
        $content = zen_reading_block_text(zen_parse_block_root($content, 'reading'));
    }
    $content = strip_shortcodes($content);
    $content = preg_replace('/<!--\s*wp:.*?-->/s', ' ', $content);
    $content = trim(html_entity_decode(wp_strip_all_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    if (function_exists('mb_strlen')) {
        $length = mb_strlen($content, 'UTF-8');
    } else {
        $length = preg_match_all('/./us', $content);
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

function zen_option_labels() {
    $labels = array_map(static fn($field) => __($field[2], 'zen'), zen_option_schema());
    if (current_user_can('unfiltered_html')) {
        $labels += array_map(static fn($field) => __($field[1], 'zen'), zen_code_fields());
    }
    return $labels;
}

/** Collect linked errors only for controls present on the current settings page. */
function zen_settings_page_errors($labels) {
    global $wp_settings_errors;
    $notices = get_settings_errors();
    $fields = $remaining = array();
    foreach ($notices as $notice) {
        $key = $notice['setting'];
        if ('error' === $notice['type'] && isset($labels[$key])) {
            $fields[$key][$notice['message']] = $notice['message'];
        } else {
            $remaining[] = $notice;
        }
    }
    // Let Core render unrelated notices (including extensions) in its usual format.
    $wp_settings_errors = $remaining;
    try {
        settings_errors();
    } finally {
        $wp_settings_errors = $notices;
    }
    return $fields;
}

function zen_option_description($key, $errors, $help_id = '') {
    if (isset($errors[$key])) {
        $help_id = trim($help_id . ' ' . $key . '_save_error');
        echo ' aria-invalid="true"';
    }
    if ($help_id !== '') echo ' aria-describedby="' . esc_attr($help_id) . '"';
}

function zen_option_error($key, $errors) {
    if (isset($errors[$key])) {
        printf('<p class="zen-field-error" id="%s">%s</p>', esc_attr($key . '_save_error'), esc_html(implode(' ', $errors[$key])));
    }
}

function zen_checkbox_field($key, $label, $desc, $errors) {
    $help_id = $key . '_help';
    ?>
    <tr>
        <th scope="row" id="<?php echo esc_attr($key . '_label'); ?>"><?php echo esc_html($label); ?></th>
        <td>
            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="0">
            <label for="<?php echo esc_attr($key); ?>">
                <input type="checkbox" name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" value="1" <?php checked(1, zen_get_option($key)); ?> aria-labelledby="<?php echo esc_attr($key . '_label'); ?>"<?php zen_option_description($key, $errors, $help_id); ?>>
                <span id="<?php echo esc_attr($help_id); ?>"><?php echo esc_html($desc); ?></span>
            </label>
            <?php zen_option_error($key, $errors); ?>
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
    $editor = current_user_can('unfiltered_html')
        ? wp_enqueue_code_editor(array('type' => 'text/html', 'codemirror' => array('lint' => false)))
        : false;
    wp_enqueue_style('zen-admin', $uri . '/assets/css/admin.css', array(), filemtime($dir . '/assets/css/admin.css'));
    wp_enqueue_script('zen-admin', $uri . '/assets/js/admin.js', $editor ? array('code-editor') : array(), filemtime($dir . '/assets/js/admin.js'), true);
    wp_localize_script('zen-admin', 'zenAdmin', array('codeEditor' => (bool) $editor));
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
    $labels = zen_option_labels();
    // The Settings API redirect preserves the previous request's query string.
    $reset = isset($_GET['settings-updated']) ? '' : ($_GET['zen_reset'] ?? '');
    ?>
    <div class="wrap zen-options">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php $errors = zen_settings_page_errors($labels); ?>
        <?php if ($errors) : ?>
            <div class="notice notice-error zen-settings-errors">
                <p><strong><?php esc_html_e('以下设置未能保存；其他有效设置可能已保存。', 'zen'); ?></strong></p>
                <ul>
                    <?php foreach (array_keys($errors) as $key) : ?>
                        <li><a href="#<?php echo esc_attr($key); ?>"><?php echo esc_html($labels[$key]); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="post" action="options.php" id="zen-options-form">
            <?php settings_fields('zen_options'); ?>
            <?php if ('1' === $reset) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Zen 设置已恢复默认值。', 'zen'); ?></p></div>
            <?php elseif ('partial' === $reset) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('可管理的 Zen 设置已恢复默认值；受权限限制，自定义代码已保留。', 'zen'); ?></p></div>
            <?php elseif ('error' === $reset) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e('部分设置未能恢复默认值，请检查当前设置后重试。', 'zen'); ?><?php if (!current_user_can('unfiltered_html')) : ?> <?php esc_html_e('受权限限制，自定义代码已保留。', 'zen'); ?><?php endif; ?></p></div>
            <?php endif; ?>
            <div class="zen-save-bar">
                <?php submit_button(__('保存设置', 'zen'), 'primary', 'zen_save_top', false); ?>
                <span id="zen-save-state" role="status" hidden data-clean="<?php echo esc_attr(__('没有未保存的修改', 'zen')); ?>" data-dirty="<?php echo esc_attr(__('有未保存的修改', 'zen')); ?>"></span>
                <button type="submit" form="zen-reset-form" class="button" data-zen-confirm="<?php echo esc_attr(zen_reset_confirmation_message()); ?>" data-zen-confirm-nonce="<?php echo esc_attr(wp_create_nonce('zen_reset_confirm')); ?>"><?php esc_html_e('恢复默认设置', 'zen'); ?></button>
            </div>
            <details class="zen-options-group" id="zen-reading" open>
                <summary><?php esc_html_e('排版与阅读', 'zen'); ?></summary>
                <div class="zen-group-content">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="zen_theme_mode_default"><?php echo esc_html($labels['zen_theme_mode_default']); ?></label></th>
                            <td><select name="zen_theme_mode_default" id="zen_theme_mode_default"<?php zen_option_description('zen_theme_mode_default', $errors, 'zen-theme-mode-help'); ?>>
                                <?php foreach (array('auto' => '跟随系统', 'light' => '浅色', 'dark' => '深色') as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $theme_mode); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select><p class="description" id="zen-theme-mode-help"><?php esc_html_e('首次访问时使用的外观', 'zen'); ?></p><?php zen_option_error('zen_theme_mode_default', $errors); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="zen_reading_width"><?php echo esc_html($labels['zen_reading_width']); ?></label></th>
                            <td>
                                <span id="zen-width-presets" hidden>
                                    <label for="zen_reading_width_preset"><?php esc_html_e('预设', 'zen'); ?></label>
                                    <select id="zen_reading_width_preset" aria-label="<?php echo esc_attr(__('正文宽度预设', 'zen')); ?>" aria-describedby="zen-reading-width-help">
                                        <?php foreach (array(600, 720, 768, 800, 960) as $width) : ?>
                                            <option value="<?php echo esc_attr($width); ?>"><?php echo esc_html($width . ' px'); ?></option>
                                        <?php endforeach; ?>
                                        <option value="custom"><?php esc_html_e('自定义', 'zen'); ?></option>
                                    </select>
                                </span>
                                <input type="number" name="zen_reading_width" id="zen_reading_width" value="<?php echo esc_attr($reading_width); ?>" min="600" max="960" step="1" required class="small-text"<?php zen_option_description('zen_reading_width', $errors, 'zen-reading-width-help'); ?>> px
                                <p class="description" id="zen-reading-width-help"><?php printf(esc_html__('可设为 600–960px，默认 %dpx', 'zen'), zen_get_option_defaults()['zen_reading_width']); ?><span hidden id="zen-width-preset-help"><?php esc_html_e('；预设会填入数值，也可直接修改', 'zen'); ?></span></p>
                                <?php zen_option_error('zen_reading_width', $errors); ?>
                            </td>
                        </tr>
                        <?php zen_checkbox_field('zen_show_reading_progress', $labels['zen_show_reading_progress'], __('在页面顶部显示阅读进度条', 'zen'), $errors); ?>
                        <?php zen_checkbox_field('zen_show_back_to_top', $labels['zen_show_back_to_top'], __('显示返回顶部按钮', 'zen'), $errors); ?>
                        <?php zen_checkbox_field('zen_show_search_shortcut', $labels['zen_show_search_shortcut'], __('启用 Ctrl/⌘ + K 搜索', 'zen'), $errors); ?>
                    </table>
                </div>
            </details>
            <details class="zen-options-group" id="zen-list" open>
                <summary><?php esc_html_e('首页与文章列表', 'zen'); ?></summary>
                <div class="zen-group-content"><table class="form-table" role="presentation">
                    <?php zen_checkbox_field('zen_show_site_intro', $labels['zen_show_site_intro'], __('在文章索引首页显示 WordPress 站点副标题', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_latest_card', $labels['zen_show_latest_card'], __('在文章索引首页以卡片展示最新公开文章', 'zen'), $errors); ?>
                    <tr><th scope="row"><label for="zen_list_style"><?php echo esc_html($labels['zen_list_style']); ?></label></th>
                        <td><select name="zen_list_style" id="zen_list_style"<?php zen_option_description('zen_list_style', $errors, 'zen-list-style-help'); ?>>
                            <option value="standard" <?php selected('standard', $list_style); ?>><?php esc_html_e('标准', 'zen'); ?></option>
                            <option value="compact" <?php selected('compact', $list_style); ?>><?php esc_html_e('紧凑', 'zen'); ?></option>
                        </select><p class="description" id="zen-list-style-help"><?php esc_html_e('紧凑模式隐藏列表摘要', 'zen'); ?></p><?php zen_option_error('zen_list_style', $errors); ?></td></tr>
                    <tr><th scope="row"><label for="zen_excerpt_length"><?php echo esc_html($labels['zen_excerpt_length']); ?></label></th>
                        <td><input type="number" name="zen_excerpt_length" id="zen_excerpt_length" value="<?php echo esc_attr(zen_get_option('zen_excerpt_length')); ?>" min="1" max="300" step="1" required class="small-text"<?php zen_option_description('zen_excerpt_length', $errors, 'zen-excerpt-length-help'); ?>> 字
                        <p class="description" id="zen-excerpt-length-help"><?php printf(esc_html__('默认 %d 字（1–300 字）', 'zen'), zen_get_option_defaults()['zen_excerpt_length']); ?></p><?php zen_option_error('zen_excerpt_length', $errors); ?></td></tr>
                </table></div>
            </details>
            <details class="zen-options-group" id="zen-post" open>
                <summary><?php esc_html_e('文章详情', 'zen'); ?></summary>
                <div class="zen-group-content"><table class="form-table" role="presentation">
                    <?php zen_checkbox_field('zen_show_featured_image', $labels['zen_show_featured_image'], __('在文章页展示原生特色图', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_toc', $labels['zen_show_toc'], __('显示目录（桌面侧栏、移动端抽屉）', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_reading_time', $labels['zen_show_reading_time'], __('显示预计阅读时间', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_updated_date', $labels['zen_show_updated_date'], __('显示最后更新时间', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_tags', $labels['zen_show_tags'], __('显示文章底部标签', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_post_navigation', $labels['zen_show_post_navigation'], __('显示相邻文章导航', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_lightbox', $labels['zen_show_lightbox'], __('放大查看正文中的图片', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_highlight', $labels['zen_show_highlight'], __('启用代码块语法高亮', 'zen'), $errors); ?>
                    <?php zen_checkbox_field('zen_show_reading_count', $labels['zen_show_reading_count'], __('显示文章浏览次数', 'zen'), $errors); ?>
                    <tr><th scope="row"><label for="zen_copyright_license"><?php echo esc_html($labels['zen_copyright_license']); ?></label></th>
                        <td><select name="zen_copyright_license" id="zen_copyright_license"<?php zen_option_description('zen_copyright_license', $errors, 'zen-license-help'); ?>>
                            <?php foreach (zen_copyright_licenses() as $value => list($label)) : ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($value, $license); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select><p class="description" id="zen-license-help"><?php esc_html_e('显示在文章底部，仅选择你明确授予的许可', 'zen'); ?></p>
                        <?php zen_option_error('zen_copyright_license', $errors); ?>
                        <?php $legacy_copyright = get_option('zen_show_copyright', null); ?>
                        <?php if (null !== $legacy_copyright && !$legacy_copyright && 'none' !== $license) : ?>
                            <p><?php esc_html_e('旧版曾关闭版权声明，如不公开请选「不显示」并保存', 'zen'); ?></p>
                        <?php endif; ?></td></tr>
                </table></div>
            </details>
            <details class="zen-options-group" id="zen-footer" open>
                <summary><?php esc_html_e('页脚', 'zen'); ?></summary>
                <div class="zen-group-content"><table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="zen_footer_text"><?php echo esc_html($labels['zen_footer_text']); ?></label></th>
                        <td><textarea name="zen_footer_text" id="zen_footer_text" rows="4" class="large-text code" placeholder="例如：&lt;a href=&quot;https://example.com&quot;&gt;Hosted by Example&lt;/a&gt;"<?php zen_option_description('zen_footer_text', $errors, 'zen-footer-text-help'); ?>><?php echo esc_textarea(zen_get_option('zen_footer_text')); ?></textarea>
                        <p class="description" id="zen-footer-text-help"><?php esc_html_e('支持安全 HTML，留空不显示', 'zen'); ?></p><?php zen_option_error('zen_footer_text', $errors); ?></td></tr>
                    <tr><th scope="row"><label for="zen_site_start_date"><?php echo esc_html($labels['zen_site_start_date']); ?></label></th>
                        <td><input type="text" name="zen_site_start_date" id="zen_site_start_date" value="<?php echo esc_attr(zen_get_option('zen_site_start_date')); ?>" placeholder="<?php echo esc_attr(__('例如：2022-07-01', 'zen')); ?>" class="regular-text" pattern="\d{4}-\d{2}-\d{2}" autocomplete="off"<?php zen_option_description('zen_site_start_date', $errors, 'zen-start-date-help'); ?>>
                        <p class="description" id="zen-start-date-help"><?php esc_html_e('填写 YYYY-MM-DD，留空按首篇公开文章计算', 'zen'); ?></p><?php zen_option_error('zen_site_start_date', $errors); ?></td></tr>
                    <?php zen_checkbox_field('zen_show_footer_rss', $labels['zen_show_footer_rss'], __('在页脚菜单中补充 RSS 订阅链接', 'zen'), $errors); ?>
                </table></div>
            </details>
            <details class="zen-options-group" id="zen-code"<?php if (array_intersect_key($errors, zen_code_fields())) echo ' open'; ?>>
                <summary><?php esc_html_e('高级代码', 'zen'); ?></summary>
                <div class="zen-group-content">
                    <?php if (current_user_can('unfiltered_html')) : ?>
                        <p><?php esc_html_e('仅粘贴可信代码。保存后，脚本会在站点前台运行；切换主题后不再输出。', 'zen'); ?></p>
                        <table class="form-table" role="presentation">
                            <?php foreach (zen_code_fields() as $key => list(,, $description)) :
                                $help_id = $key . '_help';
                                $code = zen_get_option($key);
                                // HTML parsers discard one newline just after the opening tag.
                                if (is_string($code) && (str_starts_with($code, "\n") || str_starts_with($code, "\r"))) $code = "\n" . $code;
                            ?>
                            <tr><th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($labels[$key]); ?></label></th>
                                <td><textarea name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>" rows="6" class="large-text code" spellcheck="false" placeholder="<?php echo esc_attr(__('完整 HTML 或 script 代码', 'zen')); ?>"<?php zen_option_description($key, $errors, $help_id); ?>><?php echo esc_textarea($code); ?></textarea>
                                <p class="description" id="<?php echo esc_attr($help_id); ?>"><?php echo esc_html($description); ?></p><?php zen_option_error($key, $errors); ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e('当前账号无权编辑自定义代码。保存其他设置或恢复默认设置时，已保存的代码会保留。', 'zen'); ?></p>
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
