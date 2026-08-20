<?php
if (!defined('ABSPATH')) exit;

/**
 * SEO: JSON-LD.
 */
function zen_json_ld() {
    if (is_singular('post')) {
        global $post;

        $author_id = $post->post_author;
        $payload = array(
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => get_the_title(),
            'datePublished' => get_the_date('c'),
            'dateModified' => get_the_modified_date('c'),
            'author' => array(
                '@type' => 'Person',
                'name' => get_the_author_meta('display_name', $author_id),
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
                'logo' => array(
                    '@type' => 'ImageObject',
                    'url' => get_avatar_url(get_option('admin_email')),
                ),
            ),
            'description' => get_the_excerpt(),
        );

        if (has_post_thumbnail()) {
            $payload['image'] = get_the_post_thumbnail_url($post, 'full');
        }

        echo '<script type="application/ld+json">' . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
}
add_action('wp_head', 'zen_json_ld');
