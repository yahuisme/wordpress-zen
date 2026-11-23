<?php
if (!defined('ABSPATH')) exit;

/** Only an explicitly installed, same-theme Kai add-on can supply its webfonts. */
function zen_reference_font_styles() {
    $styles = array();
    $kai = 'assets/zen-kai/assets/css/zen-bins-kai.css';
    if (is_readable(get_template_directory() . '/' . $kai)) {
        $styles['zen-bins-kai'] = $kai;
    }
    return $styles;
}

function zen_reading_width() {
    return max(600, min(960, (int) zen_get_option('zen_reading_width')));
}

function zen_design_variables($reading) {
    return ':root{--zen-content-width:1000px;--zen-reading-width:' . $reading . 'px}';
}

function zen_scripts() {
    $dir = get_template_directory();
    $uri = get_template_directory_uri();
    wp_enqueue_style('phosphor-icons', $uri . '/assets/css/phosphor-icons.css', array(), filemtime($dir . '/assets/css/phosphor-icons.css'));
    // Basic controls bind as soon as footer markup exists, independently of optional scripts.
    wp_enqueue_script('zen-main', $uri . '/js/main.js', array(), filemtime($dir . '/js/main.js'), array('in_footer' => true));
    if (is_singular() && zen_get_option('zen_show_highlight') && zen_has_code_blocks()) {
        wp_enqueue_style('highlight-css', $uri . '/assets/css/github-dark.min.css', array(), filemtime($dir . '/assets/css/github-dark.min.css'));
        wp_enqueue_script('highlight-js', $uri . '/assets/js/highlight.min.js', array(), filemtime($dir . '/assets/js/highlight.min.js'), array('in_footer' => true, 'strategy' => 'async'));
    }
    wp_localize_script('zen-main', 'zenSettings', array(
        'theme_mode_default' => zen_get_option('zen_theme_mode_default'),
        'search_shortcut' => (int) zen_get_option('zen_show_search_shortcut'),
    ));
    if (zen_show_reading_count()) {
        wp_enqueue_script('zen-reading-count', $uri . '/js/reading-count.js', array(), filemtime($dir . '/js/reading-count.js'), array('in_footer' => true, 'strategy' => 'defer'));
        wp_localize_script('zen-reading-count', 'zenReadingCount', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'enabled' => 1,
        ));
    }
    wp_enqueue_style('zen-compiled-style', $uri . '/assets/css/style.css', array(), filemtime($dir . '/assets/css/style.css'));
    $fonts = zen_reference_font_styles();
    foreach ($fonts as $handle => $file) {
        wp_enqueue_style($handle, $uri . '/' . $file, array(), filemtime($dir . '/' . $file));
    }
    wp_enqueue_style('zen-reading-style', $uri . '/assets/css/reading.css', array_merge(array('zen-compiled-style'), array_keys($fonts)), filemtime($dir . '/assets/css/reading.css'));
    wp_enqueue_style('zen-layout-style', $uri . '/assets/css/layout.css', array('zen-reading-style'), filemtime($dir . '/assets/css/layout.css'));
    $reading = zen_reading_width();
    wp_add_inline_style('zen-reading-style', zen_design_variables($reading));
    $breakpoint = max(1200, $reading + 560);
    wp_add_inline_style('zen-layout-style', '@media(min-width:' . $breakpoint . 'px){#toc-container.zen-toc-ready{display:block!important}#header-toc-btn.zen-toc-ready,#header-toc-btn.zen-toc-pending{display:none!important}}');
    if (is_singular() && comments_open() && get_option('thread_comments') && !post_password_required()) {
        wp_enqueue_script('comment-reply');
    }
}
add_action('wp_enqueue_scripts', 'zen_scripts');

function zen_enqueue_directory_styles($template) {
    // Match the resolved template so slug-based pages work without a saved template option.
    if (in_array(basename($template), array('archive.php', 'search.php', '404.php', 'page-archives.php', 'page-tags.php', 'page-links.php', 'page-notes.php'), true)) {
        add_action('wp_enqueue_scripts', static function () {
            $file = '/assets/css/directories.css';
            wp_enqueue_style('zen-directories-style', get_template_directory_uri() . $file, array('zen-layout-style'), filemtime(get_template_directory() . $file));
        }, 20);
    }
    return $template;
}
add_filter('template_include', 'zen_enqueue_directory_styles', 99);

/** A page view must not wait for WP Rocket's first user interaction. */
function zen_reading_count_delay_exclusions($excluded) {
    $excluded[] = 'reading-count.js';
    $excluded[] = 'zenReadingCount';
    return $excluded;
}
add_filter('rocket_delay_js_exclusions', 'zen_reading_count_delay_exclusions');

function zen_editor_settings($settings) {
    $settings['styles'][] = array('css' => zen_design_variables(zen_reading_width()));
    return $settings;
}
add_filter('block_editor_settings_all', 'zen_editor_settings');

function zen_has_preformatted_code($content) {
    if (false === stripos($content, '<pre') || false === stripos($content, '<code')) {
        return false;
    }
    $tags = new WP_HTML_Tag_Processor($content);
    $in_pre = false;
    while ($tags->next_tag(array('tag_closers' => 'visit'))) {
        if ('PRE' === $tags->get_tag()) {
            $in_pre = !$tags->is_tag_closer();
        } elseif ($in_pre && 'CODE' === $tags->get_tag() && !$tags->is_tag_closer()) {
            return true;
        }
    }
    return false;
}

function zen_has_code_blocks() {
    $post = get_post();
    if (!$post || post_password_required($post)) {
        return false;
    }
    $pending = array($post->post_content);
    $seen_refs = array();
    $is_root = true;
    while ($pending) {
        $content = array_pop($pending);
        if (has_block('core/code', $content) || zen_has_preformatted_code($content)) {
            return true;
        }
        if (!has_block('core/block', $content)) {
            continue;
        }
        $blocks = $is_root ? zen_parse_block_root($content, 'highlight') : parse_blocks($content);
        $is_root = false;
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
            $pattern = zen_get_published_block_pattern($ref);
            if ($pattern) {
                $pending[] = $pattern->post_content;
            }
        }
    }
    return false;
}
