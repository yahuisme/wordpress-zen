<?php
if (!defined('ABSPATH')) exit;

/** Basic metadata shared by the head description and article JSON-LD. */
function zen_description_substr($text, $length) {
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $length, 'UTF-8');
    }
    // Keep just the prefix, not a PHP array entry for every character of the post.
    return preg_match('/\A.{0,' . $length . '}/us', $text, $match) ? $match[0] : substr($text, 0, $length);
}

function zen_description_text($text) {
    $text = strip_shortcodes($text);
    // Separate blocks even when HTML permits omitted closing tags; keep inline text intact.
    $text = preg_replace('~</?(?:br|p|div|li|h[1-6]|blockquote|pre|tr|td|th|dt|dd)(?=[\s/>])~i', ' $0', $text);
    return html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function zen_limit_description($text) {
    $text = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? '');
    // The string is already plain text. wp_html_excerpt can drop a literal trailing ampersand.
    $excerpt = zen_description_substr($text, 160);
    return $excerpt === $text ? $text : rtrim($excerpt) . '…';
}

function zen_clean_description($text) {
    return zen_limit_description(zen_description_text($text));
}

/** Keep the saved markup order without rendering shortcodes or dynamic blocks. */
function zen_visible_block_markup($block) {
    if (false === ($block['attrs']['metadata']['blockVisibility'] ?? null)) return '';

    $html = '';
    $index = 0;
    foreach ($block['innerContent'] as $part) {
        $html .= is_string($part) ? $part : zen_visible_block_markup($block['innerBlocks'][$index++]);
    }
    return $html;
}

function zen_meta_description() {
    if (is_singular()) {
        static $last_post, $description;
        $post = get_queried_object();
        if (post_password_required($post)) {
            return '';
        }
        if ($last_post !== $post) {
            $description = zen_clean_description($post->post_excerpt);
            if ('' === $description) {
                $content = $post->post_content;
                if (function_exists('wp_render_block_visibility_support') && str_contains($content, '<!--')) {
                    $content = implode('', array_map('zen_visible_block_markup', parse_blocks($content)));
                }
                $description = zen_clean_description($content);
            }
            $last_post = $post;
        }
        return $description;
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
        // The query is plain text, not post markup. The output hook escapes it as an attribute.
        return zen_limit_description('关于“' . get_search_query(false) . '”的搜索结果 - ' . zen_description_text(get_bloginfo('name')));
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
