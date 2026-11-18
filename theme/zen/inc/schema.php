<?php
if (!defined('ABSPATH')) exit;

/** Basic metadata shared by the head description and article JSON-LD. */
function zen_clean_description($text) {
    $text = strip_shortcodes($text);
    // Adjacent blocks need a separator even when the saved markup has no newline.
    $text = preg_replace('~</(?:p|div|li|h[1-6]|blockquote|pre|tr)>|<br\s*/?\s*>~i', '$0 ', $text);
    $text = wp_strip_all_tags($text);
    $text = wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    // The string is already plain text. wp_html_excerpt can drop a literal trailing ampersand.
    $excerpt = mb_substr($text, 0, 160);
    return $excerpt === $text ? $text : rtrim($excerpt) . '…';
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
        // Encode literal ampersands too, so the HTML and JSON-LD decode to the same text.
        echo '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . "\n";
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
            'headline' => html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'datePublished' => get_the_date('c', $post),
            'dateModified' => get_the_modified_date('c', $post),
            'mainEntityOfPage' => array(
                '@type' => 'WebPage',
                '@id' => get_permalink($post),
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

        $image = get_the_post_thumbnail_url($post, 'full');
        if ($image) {
            $payload['image'] = $image;
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
