<?php
if (!defined('ABSPATH')) exit;

function zen_content_page_links() {
    wp_link_pages(array(
        'before' => '<nav class="zen-page-links" aria-label="' . esc_attr__('正文分页', 'zen') . '">',
        'after' => '</nav>',
    ));
}

function zen_directory_more($list_id, $batch_size, $label) {
    ?>
    <button type="button" class="zen-directory-more" hidden aria-controls="<?php echo esc_attr($list_id); ?>" data-zen-directory-batch="<?php echo esc_attr($batch_size); ?>">
        <span><?php echo esc_html($label); ?></span>
        <span aria-hidden="true">↓</span>
    </button>
    <?php
}

/** Trim visible characters, keeping combining marks and emoji together. */
function zen_trim_excerpt($text, $length, $more = '…') {
    // Keep block boundaries and line breaks when Core removes the markup.
    $text = preg_replace('~</?(?:br|p|div|li|h[1-6]|blockquote|pre|td|th|dt|dd)(?=[\s/>])~i', ' $0', (string) $text);
    $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    $length = max(1, min(300, (int) $length));
    return preg_match('/\A(\X{' . $length . '})\X/u', $text, $match)
        ? rtrim($match[1]) . $more
        : $text;
}

function zen_get_archives_url() {
    return zen_get_directory_url('page-archives.php');
}

/** Use the owner's published page and its native permalink, never guess its slug. */
function zen_get_directory_url($template) {
    $pages = get_pages(array(
        'meta_key' => '_wp_page_template',
        'meta_value' => $template,
        'post_status' => 'publish',
        'hierarchical' => false,
        'number' => 1,
    ));
    if (!empty($pages)) {
        return get_permalink($pages[0]->ID);
    }

    // Core also selects page-{slug}.php for root pages using the default template.
    $slug = substr(basename($template), 5, -4);
    $page = get_page_by_path($slug, 'OBJECT', array('page'));
    if (!$page || $page->post_status !== 'publish') {
        return '';
    }
    $assigned = get_page_template_slug($page);
    return ($assigned === '' || $assigned === false || $assigned === 'default') ? get_permalink($page) : '';
}

function zen_get_posts_url($fallback_to_home = true) {
    if (get_option('show_on_front') !== 'page') {
        return home_url('/');
    }
    $posts_page = (int) get_option('page_for_posts');
    $page = $posts_page && $posts_page !== (int) get_option('page_on_front') ? get_post($posts_page) : null;
    if ($page && $page->post_type === 'page' && $page->post_status === 'publish') {
        return get_permalink($page);
    }
    return $fallback_to_home ? home_url('/') : '';
}

/** Check the final menu links without rewriting the menu or the custom footer text. */
function zen_menu_has_feed_link($menu_html, $feed_url) {
    $feed_url = html_entity_decode($feed_url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $home = wp_parse_url(home_url('/')) ?: array();
    $home_scheme = $home['scheme'] ?? '';
    $home_origin = $home_scheme !== '' && isset($home['host'])
        ? $home_scheme . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '')
        : '';
    $tags = new WP_HTML_Tag_Processor($menu_html);
    while ($tags->next_tag('A')) {
        $href = $tags->get_attribute('href');
        if (!is_string($href)) continue;
        $href = trim($href);
        if ($home_scheme !== '' && str_starts_with($href, '//')) {
            $href = $home_scheme . ':' . $href;
        } elseif ($home_origin !== '' && str_starts_with($href, '/')) {
            $href = $home_origin . $href;
        }
        if ($href === $feed_url) return true;
    }
    return false;
}

function zen_category_navigation() {
    $categories = get_categories(array('hide_empty' => true));
    $current = is_category() ? get_queried_object_id() : 0;
    ?>
    <nav class="zen-category-nav" aria-label="文章分类">
        <button class="zen-category-arrow zen-category-prev" type="button" aria-label="<?php esc_attr_e('上一组分类', 'zen'); ?>" hidden><span aria-hidden="true" dir="ltr">‹</span></button>
        <div class="zen-category-track">
        <a class="zen-ui-link" href="<?php echo esc_url(zen_get_posts_url()); ?>"<?php if (is_home()) echo ' aria-current="page"'; ?>>全部</a>
        <?php foreach ($categories as $category) : ?>
        <a class="zen-ui-link" href="<?php echo esc_url(get_category_link($category->term_id)); ?>"<?php if ((int) $category->term_id === $current) echo ' aria-current="page"'; ?>><?php echo esc_html($category->name); ?></a>
        <?php endforeach; ?>
        </div>
        <button class="zen-category-arrow zen-category-next" type="button" aria-label="<?php esc_attr_e('下一组分类', 'zen'); ?>" hidden><span aria-hidden="true" dir="ltr">›</span></button>
    </nav>
    <?php
}

/** Numbered links first; within each rating, use natural name order. */
function zen_compare_link_order($a, $b) {
    $rating_a = (int) ($a->link_rating ?? 0);
    $rating_b = (int) ($b->link_rating ?? 0);
    return (($rating_a === 0) <=> ($rating_b === 0))
        ?: ($rating_a <=> $rating_b)
        ?: strnatcasecmp($a->link_name, $b->link_name);
}

function zen_get_primary_category($post = null) {
    $categories = get_the_category($post ? $post->ID : false);
    return ! empty($categories) ? $categories[0] : null;
}

function zen_post_title($post = null) {
    $title = get_the_title($post);
    return trim($title) === '' ? __('无标题', 'zen') : $title;
}

/** Pick from the actual first page without requerying or changing sticky order. */
function zen_get_latest_index_post($posts) {
    $latest = null;
    foreach ($posts as $candidate) {
        if ($candidate->post_type !== 'post' || $candidate->post_status !== 'publish' || $candidate->post_password !== '') {
            continue;
        }
        // Compare site-local publication times, not the sticky display order.
        if (!$latest || array($candidate->post_date, $candidate->ID) > array($latest->post_date, $latest->ID)) {
            $latest = $candidate;
        }
    }
    return $latest;
}

function zen_post_date_format($context = 'full', $year = '') {
    if ($context === 'directory' || ($context === 'list' && $year === wp_date('Y'))) {
        return 'n月 j日';
    }
    return 'Y.m.d';
}

function zen_post_date_html($context = 'full', $post = null) {
    $year = $context === 'list' ? get_the_date('Y', $post) : '';
    return sprintf('<time class="zen-post-date" datetime="%s">%s</time>',
        esc_attr(get_the_date('c', $post)),
        esc_html(get_the_date(zen_post_date_format($context, $year), $post))
    );
}

function zen_posted_on_link($context = 'full', $post = null) {
    $year = get_the_date('Y', $post);
    $month = get_the_date('n', $post);
    $label = sprintf(__('发布于 %1$s，查看 %2$s 的所有文章', 'zen'), get_the_date('Y年n月j日', $post), get_the_date('Y年n月', $post));

    printf(
        '<a href="%1$s" class="zen-meta-field zen-ui-link" aria-label="%2$s">%3$s</a>',
        esc_url(get_month_link($year, $month)),
        esc_attr($label),
        zen_post_date_html($context, $post)
    );
}

/** Index lists split current-year dates into month/day lines. */
function zen_index_date_html() {
    if (get_the_date('Y') !== wp_date('Y')) {
        return zen_post_date_html('list');
    }
    return sprintf(
        '<time class="zen-post-date zen-index-date" datetime="%s"><span>%s</span> <span>%s</span></time>',
        esc_attr(get_the_date('c')),
        esc_html(get_the_date('n月')),
        esc_html(get_the_date('j日'))
    );
}

function zen_index_post_meta($context, $post = null) {
    ?>
    <div class="zen-post-meta">
        <?php if (is_search()) : echo zen_post_date_html($context, $post);
        else : zen_posted_on_link($context, $post); endif; ?>
        <?php $category = zen_get_primary_category($post); ?>
        <?php if ($category) : ?>
            <a href="<?php echo esc_url(get_category_link($category->term_id)); ?>" class="zen-meta-field zen-meta-category zen-ui-link"><?php echo esc_html($category->name); ?></a>
        <?php endif; ?>
    </div>
    <?php
}

function zen_display_excerpt($excerpt) {
    $excerpt = zen_trim_excerpt($excerpt, (int) zen_get_option('zen_excerpt_length'));
    // Keep decoded entity-like text literal at the HTML boundary.
    return esc_html(str_replace('&', '&amp;', $excerpt));
}

function zen_index_post_excerpt($post = null) {
    // Let Core prepare this excerpt without cutting entities. Nested excerpts keep their native limit.
    $excerpt_stack = array_merge($GLOBALS['wp_current_filter'] ?? array(), array('get_the_excerpt', 'excerpt_length'));
    $defer_trimming = static function ($length) use ($excerpt_stack) {
        return $GLOBALS['wp_current_filter'] === $excerpt_stack ? PHP_INT_MAX - 1 : $length;
    };
    add_filter('excerpt_length', $defer_trimming, PHP_INT_MAX);
    try {
        $excerpt = get_the_excerpt($post);
    } finally {
        remove_filter('excerpt_length', $defer_trimming, PHP_INT_MAX);
    }
    $excerpt = zen_display_excerpt($excerpt);
    if ($excerpt !== '') printf('<div class="zen-post-excerpt">%s</div>', $excerpt);
}

/** Legacy helper for callers passing a whitespace-separated string. */
function zen_highlight_search_terms($text, $query = '') {
    return zen_mark_search_terms($text, preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: array());
}

/** Follow the terms Core actually searched, including phrases and stopword rules. */
function zen_highlight_search_title($text) {
    $terms = get_query_var('search_terms', array());
    if (!is_array($terms)) return esc_html($text);
    $prefix = apply_filters('wp_query_search_exclusion_prefix', '-');
    if ($prefix) {
        $terms = array_filter($terms, static fn($term) => !str_starts_with($term, $prefix));
    }
    return zen_mark_search_terms($text, $terms);
}

function zen_mark_search_terms($text, $terms) {
    $terms = array_unique($terms);
    if (empty($terms)) {
        return esc_html($text);
    }

    $terms = array_filter($terms, function ($term) {
        return trim($term) !== '' && (function_exists('mb_strlen') ? mb_strlen($term) <= 100 : strlen($term) <= 300);
    });
    if (empty($terms)) {
        return esc_html($text);
    }
    $terms = array_slice($terms, 0, 20);

    usort($terms, function ($a, $b) {
        $a_length = function_exists('mb_strlen') ? mb_strlen($a) : strlen($a);
        $b_length = function_exists('mb_strlen') ? mb_strlen($b) : strlen($b);
        return $b_length <=> $a_length;
    });

    $terms = array_map(function ($term) {
        return preg_quote($term, '/');
    }, $terms);

    $pattern = '/(' . implode('|', $terms) . ')/iu';
    $plain_text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = preg_split($pattern, $plain_text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    if (false === $parts) {
        return esc_html($text);
    }

    $output = '';
    foreach ($parts as $part) {
        $is_match = (bool) preg_match($pattern, $part);
        // Keep entity-like text literal after decoding exactly one input layer.
        $escaped_part = esc_html(str_replace('&', '&amp;', $part));
        if ($is_match) {
            $output .= '<mark class="bg-yellow-200 dark:bg-yellow-800 dark:text-white rounded-sm px-0.5">' . $escaped_part . '</mark>';
        } else {
            $output .= $escaped_part;
        }
    }

    return $output;
}

function zen_kses_link($html) {
    return wp_kses($html, array(
        'a' => array(
            'class' => true,
            'href' => true,
            'rel' => true,
            'aria-label' => true,
            'data-belowelement' => true,
            'data-commentid' => true,
            'data-postid' => true,
            'data-replyto' => true,
            'data-respondelement' => true,
        ),
        'span' => array(
            'aria-current' => true,
            'aria-hidden' => true,
            'class' => true,
        ),
        'i' => array(
            'aria-hidden' => true,
            'class' => true,
        ),
    ));
}

function zen_pagination() {
    global $wp_query;
    if ($wp_query->max_num_pages < 2) return;

    // Core owns the current query, page limits and canonical first-page URL.
    $previous = get_previous_posts_link('<span aria-hidden="true">&larr;</span> ' . esc_html__('上一页', 'zen'));
    $next = get_next_posts_link(esc_html__('下一页', 'zen') . ' <span aria-hidden="true">&rarr;</span>');
    if (!$previous && !$next) return;

    echo '<nav class="zen-pagination" aria-label="' . esc_attr__('分页导航', 'zen') . '">';
    if ($previous) echo '<div class="zen-pagination-prev">' . zen_kses_link($previous) . '</div>';
    if ($next) echo '<div class="zen-pagination-next">' . zen_kses_link($next) . '</div>';
    echo '</nav>';
}

function zen_clear_archives_cache() {
    delete_transient('zen_archives_public_posts_v3');
}
function zen_clear_post_directory_caches() {
    zen_clear_archives_cache();
    zen_clear_uptime_cache();
}

function zen_clear_archives_cache_on_post_update($post_id, $after, $before) {
    // Native status transitions (including scheduled publication) have their own hook.
    if ($after->post_type !== $before->post_type) {
        if (('post' === $before->post_type && 'publish' === $before->post_status)
            || ('post' === $after->post_type && 'publish' === $after->post_status && $after->post_status === $before->post_status)) {
            zen_clear_post_directory_caches();
        }
        return;
    }
    if ('post' !== $after->post_type || 'publish' !== $after->post_status || 'publish' !== $before->post_status) {
        return;
    }
    if ($after->post_date !== $before->post_date || $after->post_date_gmt !== $before->post_date_gmt) {
        zen_clear_post_directory_caches();
    } elseif ($after->post_title !== $before->post_title || $after->post_name !== $before->post_name
        || $after->post_password !== $before->post_password || $after->post_author !== $before->post_author) {
        zen_clear_archives_cache();
    }
}
add_action('post_updated', 'zen_clear_archives_cache_on_post_update', 10, 3);
function zen_clear_archives_cache_on_status_change($new_status, $old_status, $post) {
    if ($post instanceof WP_Post && 'post' === $post->post_type && $new_status !== $old_status
        && ('publish' === $new_status || 'publish' === $old_status)) {
        zen_clear_post_directory_caches();
    }
}
add_action('transition_post_status', 'zen_clear_archives_cache_on_status_change', 10, 3);
function zen_clear_archives_cache_on_delete($post_id, $post) {
    if ('post' === $post->post_type && 'publish' === $post->post_status) {
        zen_clear_post_directory_caches();
    }
}
add_action('deleted_post', 'zen_clear_archives_cache_on_delete', 10, 2);
add_action('add_option_zen_site_start_date', 'zen_clear_uptime_cache');
add_action('update_option_zen_site_start_date', 'zen_clear_uptime_cache');
add_action('delete_option_zen_site_start_date', 'zen_clear_uptime_cache');
add_action('edited_category', 'zen_clear_archives_cache');
add_action('delete_category', 'zen_clear_archives_cache');
function zen_clear_archives_cache_on_category_relationship($object_id, $term_ids, $taxonomy) {
    if ('post' === get_post_type($object_id)
        && 'publish' === get_post_status($object_id)
        && ('language' === $taxonomy || ('category' === $taxonomy
            && str_contains((string) get_option('permalink_structure'), '%category%')))) {
        zen_clear_archives_cache();
    }
}
add_action('added_term_relationship', 'zen_clear_archives_cache_on_category_relationship', 10, 3);
add_action('deleted_term_relationships', 'zen_clear_archives_cache_on_category_relationship', 10, 3);
function zen_clear_archives_cache_on_author_profile($user_id, $old_user_data) {
    if (!str_contains((string) get_option('permalink_structure'), '%author%')) {
        return;
    }
    $user = get_userdata($user_id);
    if ($user && $user->user_nicename !== $old_user_data->user_nicename
        && get_posts(array('author' => $user_id, 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'))) {
        zen_clear_archives_cache();
    }
}
add_action('profile_update', 'zen_clear_archives_cache_on_author_profile', 10, 2);
function zen_clear_archives_cache_on_author_reassignment($user_id, $reassign) {
    // Core reassigns posts directly, without emitting individual post-save hooks.
    if ($reassign && str_contains((string) get_option('permalink_structure'), '%author%')) {
        zen_clear_archives_cache();
    }
}
add_action('deleted_user', 'zen_clear_archives_cache_on_author_reassignment', 10, 2);
function zen_clear_archives_cache_on_default_category() {
    // Uncategorized posts use this fallback without a relationship or post-save event.
    if (str_contains((string) get_option('permalink_structure'), '%category%')) {
        zen_clear_archives_cache();
    }
}
add_action('update_option_default_category', 'zen_clear_archives_cache_on_default_category');
add_action('update_option_permalink_structure', 'zen_clear_archives_cache');
add_action('update_option_home', 'zen_clear_archives_cache');
function zen_clear_uptime_cache() {
    delete_transient('zen_site_uptime');
}
add_action('update_option_timezone_string', 'zen_clear_uptime_cache');
add_action('update_option_gmt_offset', 'zen_clear_uptime_cache');

function zen_get_site_uptime() {
    $uptime = get_transient('zen_site_uptime');
    if (false === $uptime) {
        $start_date_str = zen_get_option('zen_site_start_date');
        $start_timestamp = null;

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        if (!empty($start_date_str) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date_str)) {
            $start_date = DateTimeImmutable::createFromFormat('!Y-m-d', $start_date_str, $timezone);
            if ($start_date && $start_date->format('Y-m-d') === $start_date_str) {
                $start_timestamp = $start_date->getTimestamp();
            }
        } else {
            $first_posts = get_posts(array(
                'numberposts' => 1,
                'post_status' => 'publish',
                'lang'        => '', // Site-wide even when a translation plugin filters queries.
                'orderby'     => 'date',
                'order'       => 'ASC',
                'fields'      => 'ids',
            ));
            if (!empty($first_posts)) {
                $post_time = get_post_time('U', true, $first_posts[0]);
                if (false !== $post_time) $start_timestamp = (int) $post_time;
            }
        }

        $now = (new DateTimeImmutable('now', $timezone))->getTimestamp();
        if (null !== $start_timestamp && $start_timestamp <= $now) {
            $year = wp_date('Y', $start_timestamp, $timezone);
            $days = max(1, (int) floor(($now - $start_timestamp) / DAY_IN_SECONDS));
        } else {
            $year = wp_date('Y', $now, $timezone);
            $days = 1;
        }

        $uptime = array(
            'year' => $year,
            'days' => $days,
        );

        // 缓存半天（12 小时），确保跨天自动更新天数
        set_transient('zen_site_uptime', $uptime, 12 * HOUR_IN_SECONDS);
    }

    return $uptime;
}
