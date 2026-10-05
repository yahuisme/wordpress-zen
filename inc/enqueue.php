<?php
if (!defined('ABSPATH')) exit;

/** One typography contract for the reader and the native block editor. */
function zen_typography() {
    $family = zen_get_option('zen_font_family');
    $system = 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif';
    $serif = '"Songti SC", "SimSun", "Noto Serif CJK SC", ui-serif, Georgia, serif';
    $fonts = '';
    if ('space-grotesk' === $family) {
        $body = '"Space Grotesk", "Noto Sans SC", ' . $system;
        $heading = $body;
        $fonts = 'family=Space+Grotesk:wght@400;500;600;700&family=Noto+Sans+SC:wght@400;500;600;700';
    } elseif ('inter' === $family) {
        $body = 'Inter, "Noto Sans SC", ' . $system;
        $heading = '"Noto Serif SC", ' . $serif;
        $fonts = 'family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&family=Noto+Sans+SC:wght@400;500;600;700&family=Noto+Serif+SC:wght@400;500;600;700';
    } else {
        $body = $system;
        $heading = $serif;
    }
    return array('body' => $body, 'heading' => $heading, 'fonts' => $fonts ? 'https://fonts.googleapis.com/css2?' . $fonts . '&display=swap' : '');
}

function zen_design_variables() {
    $type = zen_typography();
    $site = max(600, min(1920, (int) zen_get_option('zen_content_width')));
    $reading = max(600, min(960, (int) zen_get_option('zen_reading_width')));
    return ':root{--zen-content-width:' . $site . 'px;--zen-content-half:' . round($site / 2) . 'px;--zen-reading-width:' . $reading . 'px;--zen-archives-width:' . min($site, $reading) . 'px;--zen-font-body:' . $type['body'] . ';--zen-font-heading:' . $type['heading'] . '}';
}

function zen_scripts() {
    $ver = wp_get_theme()->get('Version');
    $type = zen_typography();
    if ($type['fonts']) {
        wp_enqueue_style('zen-google-fonts', $type['fonts'], array(), null);
    }
    wp_enqueue_style('phosphor-icons', get_template_directory_uri() . '/assets/css/phosphor-icons.css', array(), $ver);
    if (is_singular() && zen_get_option('zen_show_highlight') && zen_has_code_blocks()) {
        wp_enqueue_style('highlight-css', get_template_directory_uri() . '/assets/css/github-dark.min.css', array(), $ver);
        wp_enqueue_script('highlight-js', get_template_directory_uri() . '/assets/js/highlight.min.js', array(), $ver, true);
    }
    wp_enqueue_script('zen-main', get_template_directory_uri() . '/js/main.js', wp_script_is('highlight-js', 'enqueued') ? array('highlight-js') : array(), $ver, true);
    wp_localize_script('zen-main', 'zenSettings', array(
        'theme_mode_default' => zen_get_option('zen_theme_mode_default'),
        'search_shortcut' => (int) zen_get_option('zen_show_search_shortcut'),
    ));
    wp_enqueue_style('zen-compiled-style', get_template_directory_uri() . '/assets/css/style.css', array(), $ver);
    wp_enqueue_style('zen-reading-style', get_template_directory_uri() . '/assets/css/reading.css', array('zen-compiled-style'), $ver);
    wp_enqueue_style('zen-layout-style', get_template_directory_uri() . '/assets/css/layout.css', array('zen-reading-style'), $ver);
    wp_add_inline_style('zen-reading-style', zen_design_variables());
    $reading = max(600, min(960, (int) zen_get_option('zen_reading_width')));
    $breakpoint = max(1200, $reading + 560);
    wp_add_inline_style('zen-layout-style', '@media(min-width:' . $breakpoint . 'px){#toc-container.zen-toc-ready{display:block!important}#floating-toc-btn.zen-toc-ready,#header-toc-btn.zen-toc-ready{display:none}}');
    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
}
add_action('wp_enqueue_scripts', 'zen_scripts');

function zen_editor_settings($settings) {
    $type = zen_typography();
    $settings['styles'][] = array('css' => zen_design_variables());
    if ($type['fonts']) {
        $settings['styles'][] = array('css' => '@import url("' . $type['fonts'] . '");');
    }
    return $settings;
}
add_filter('block_editor_settings_all', 'zen_editor_settings');

function zen_has_code_blocks() {
    $post = get_post();
    if (!$post) {
        return false;
    }
    $pending = array($post->post_content);
    $seen_refs = array();
    while ($pending) {
        $content = array_pop($pending);
        if (has_block('core/code', $content) || preg_match('/<pre\b[^>]*>/i', $content)) {
            return true;
        }
        if (!has_block('core/block', $content)) {
            continue;
        }
        $blocks = parse_blocks($content);
        while ($blocks) {
            $block = array_pop($blocks);
            foreach ($block['innerBlocks'] as $inner_block) {
                $blocks[] = $inner_block;
            }
            if ('core/block' !== $block['blockName'] || empty($block['attrs']['ref'])) {
                continue;
            }
            $ref = $block['attrs']['ref'];
            if (!is_int($ref) || $ref <= 0 || isset($seen_refs[$ref])) {
                continue;
            }
            $seen_refs[$ref] = true;
            $pattern = get_post($ref);
            if ($pattern && 'wp_block' === $pattern->post_type && 'publish' === $pattern->post_status && '' === $pattern->post_password) {
                $pending[] = $pattern->post_content;
            }
        }
    }
    return false;
}
