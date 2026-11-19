<?php
if (!defined('ABSPATH')) exit;

/** Trim visible characters, keeping combining marks and emoji together. */
function zen_trim_excerpt($text, $length, $more = '…') {
    $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
    return !empty($pages) ? get_permalink($pages[0]->ID) : '';
}

function zen_get_posts_url() {
    $posts_page = (int) get_option('page_for_posts');
    return get_option('show_on_front') === 'page' && $posts_page ? get_permalink($posts_page) : home_url('/');
}

function zen_category_navigation() {
    $ids = zen_get_option('zen_category_ids');
    $args = array('hide_empty' => true);
    if (is_array($ids) && !empty($ids)) {
        $args['include'] = $ids;
        $args['orderby'] = 'include';
        $args['hide_empty'] = false;
    }
    $categories = get_categories($args);
    $current = is_category() ? get_queried_object_id() : 0;
    ?>
    <nav class="zen-category-nav" aria-label="文章分类">
        <button class="zen-category-arrow zen-category-prev" type="button" aria-label="向左滚动分类" hidden><span aria-hidden="true">‹</span></button>
        <div class="zen-category-track">
        <a class="zen-ui-link" href="<?php echo esc_url(zen_get_posts_url()); ?>"<?php if (is_home()) echo ' aria-current="page"'; ?>>全部</a>
        <?php foreach ($categories as $category) : ?>
        <a class="zen-ui-link" href="<?php echo esc_url(get_category_link($category->term_id)); ?>"<?php if ((int) $category->term_id === $current) echo ' aria-current="page"'; ?>><?php echo esc_html($category->name); ?></a>
        <?php endforeach; ?>
        </div>
        <button class="zen-category-arrow zen-category-next" type="button" aria-label="向右滚动分类" hidden><span aria-hidden="true">›</span></button>
    </nav>
    <?php
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

/** Fold current-year dates into a narrow column on the posts index. */
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
    if (zen_get_option('zen_list_style') === 'compact') return;
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
    if ($excerpt !== '') printf('<div class="zen-post-excerpt line-clamp-3">%s</div>', $excerpt);
}

function zen_highlight_search_terms($text, $query = '') {
    $query = trim($query);

    if ('' === $query) {
        return esc_html($text);
    }

    $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
    if (false === $terms) {
        return esc_html($text);
    }
    $terms = array_unique($terms);

    if (empty($terms)) {
        return esc_html($text);
    }

    $terms = array_filter($terms, function ($term) {
        return function_exists('mb_strlen') ? mb_strlen($term) <= 100 : strlen($term) <= 300;
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
        $part = preg_replace('/&(?=(?:[A-Za-z][A-Za-z0-9]+|#\d+|#x[0-9A-Fa-f]+);)/', '&amp;', $part);
        $escaped_part = esc_html($part);
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
    $pages = paginate_links(array(
        'prev_text' => '<span aria-hidden="true">&larr;</span><span class="screen-reader-text">上一页</span>',
        'next_text' => '<span class="screen-reader-text">下一页</span><span aria-hidden="true">&rarr;</span>',
        'type' => 'array',
    ));

    if (is_array($pages)) {
        echo '<nav class="zen-pagination" aria-label="分页导航">';
        foreach ($pages as $page) {
            echo zen_kses_link($page);
        }
        echo '</nav>';
    }
}

function zen_clear_archives_cache() {
    delete_transient('zen_archives_public_posts_v3');
}
function zen_clear_archives_cache_on_public_save($post_id, $post) {
    if ($post instanceof WP_Post && 'publish' === $post->post_status) {
        zen_clear_archives_cache();
        zen_clear_uptime_cache();
    }
}
add_action('save_post_post', 'zen_clear_archives_cache_on_public_save', 10, 2);
function zen_clear_archives_cache_on_status_change($new_status, $old_status, $post) {
    if ($post instanceof WP_Post && 'post' === $post->post_type && $new_status !== $old_status
        && ('publish' === $new_status || 'publish' === $old_status)) {
        zen_clear_archives_cache();
        zen_clear_uptime_cache();
    }
}
add_action('transition_post_status', 'zen_clear_archives_cache_on_status_change', 10, 3);
function zen_clear_archives_cache_on_delete($post_id, $post) {
    if ('post' === $post->post_type) {
        zen_clear_archives_cache_on_public_save($post_id, $post);
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
        delete_transient('zen_archives_public_posts_v3');
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
        delete_transient('zen_archives_public_posts_v3');
    }
}
add_action('profile_update', 'zen_clear_archives_cache_on_author_profile', 10, 2);
function zen_clear_archives_cache_on_author_reassignment($user_id, $reassign) {
    // Core reassigns posts directly, without emitting individual post-save hooks.
    if ($reassign && str_contains((string) get_option('permalink_structure'), '%author%')) {
        delete_transient('zen_archives_public_posts_v3');
    }
}
add_action('deleted_user', 'zen_clear_archives_cache_on_author_reassignment', 10, 2);
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
        $start_timestamp = 0;

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
                $start_timestamp = (int) get_post_time('U', true, $first_posts[0]);
            }
        }

        $now = (new DateTimeImmutable('now', $timezone))->getTimestamp();
        if ($start_timestamp > 0 && $start_timestamp <= $now) {
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
