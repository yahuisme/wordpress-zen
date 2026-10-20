<?php
if (!defined('ABSPATH')) exit;

/** Basic metadata shared by the head description and article JSON-LD. */
function zen_clean_description($text) {
    $text = wp_strip_all_tags(strip_shortcodes($text));
    $text = wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    return wp_html_excerpt($text, 160, '…');
}

function zen_meta_description() {
    if (is_singular()) {
        $post = get_queried_object();
        if (post_password_required($post)) {
            return '';
        }
        $description = zen_clean_description($post->post_excerpt);
        return '' !== $description ? $description : zen_clean_description($post->post_content);
    }

    if (is_category() || is_tag() || is_tax()) {
        return zen_clean_description(term_description());
    }
    if (is_author()) {
        return zen_clean_description(get_the_author_meta('description', get_queried_object_id()));
    }
    if (is_date()) {
        return zen_clean_description(get_the_archive_title());
    }
    if (is_search()) {
        return zen_clean_description('关于“' . get_search_query(false) . '”的搜索结果 - ' . get_bloginfo('name'));
    }

    if (!is_home() && !is_front_page()) {
        return '';
    }
    $name = get_bloginfo('name');
    $description = get_bloginfo('description');
    return zen_clean_description($name . ('' !== $description ? ' - ' . $description : ''));
}

/**
 * Let common SEO plugins own both outputs; integrations can override ownership.
 * Individual output filters can restore one feature if disabled in the plugin.
 */
function zen_seo_plugin_active() {
    $active = defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION')
        || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION');
    return (bool) apply_filters('zen_seo_plugin_active', $active);
}

function zen_output_meta_description() {
    if (!apply_filters('zen_meta_description_enabled', !zen_seo_plugin_active())) {
        return;
    }
    $description = zen_meta_description();
    if ('' !== $description) {
        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
    }
}
add_action('wp_head', 'zen_output_meta_description');

/** Article JSON-LD. */
function zen_json_ld() {
    if (!apply_filters('zen_json_ld_enabled', !zen_seo_plugin_active())) {
        return;
    }
    if (is_singular('post') && !post_password_required(get_queried_object())) {
        $post = get_queried_object();

        $author_id = $post->post_author;
        $payload = array(
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => get_the_title(),
            'datePublished' => get_the_date('c'),
            'dateModified' => get_the_modified_date('c'),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id' => get_permalink(),
            ),
            'author' => array(
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', $author_id),
                'url' => get_author_posts_url($author_id),
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
            ),
        );

        $description = zen_meta_description();
        if ('' !== $description) {
            $payload['description'] = $description;
        }

        if (has_post_thumbnail()) {
            $payload['image'] = get_the_post_thumbnail_url($post, 'full');
        }

        $site_icon = get_site_icon_url(512);
        if ($site_icon) {
            $payload['publisher']['logo'] = array(
                '@type' => 'ImageObject',
                'url' => $site_icon,
            );
        }

        $json_options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        echo '<script type="application/ld+json">' . wp_json_encode($payload, $json_options) . '</script>';
    }
}
add_action('wp_head', 'zen_json_ld');
