<?php
if (!defined('ABSPATH')) exit;

function zen_get_primary_category() {
    $categories = get_the_category();
    return ! empty($categories) ? $categories[0] : null;
}

function zen_posted_on_link() {
    $year = get_the_date('Y');
    $month = get_the_date('n');
    $label = sprintf(__('查看 %s 的所有文章', 'zen'), get_the_date('Y年n月'));

    printf(
        '<a href="%1$s" class="zen-ui-link hover:text-gray-900 dark:hover:text-white" aria-label="%2$s"><time datetime="%3$s">%4$s</time></a>',
        esc_url(get_month_link($year, $month)),
        esc_attr($label),
        esc_attr(get_the_date('c')),
        esc_html(get_the_date('Y年 n月 j日'))
    );
}

function zen_highlight_search_terms($text, $query = '') {
    $query = trim($query);

    if ('' === $query) {
        return esc_html($text);
    }

    $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
    $terms = array_unique(array_filter($terms));

    if (empty($terms)) {
        return esc_html($text);
    }

    usort($terms, function ($a, $b) {
        return strlen($b) <=> strlen($a);
    });

    $terms = array_map(function ($term) {
        return preg_quote($term, '/');
    }, $terms);

    $pattern = '/(' . implode('|', $terms) . ')/iu';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    if (false === $parts) {
        return esc_html($text);
    }

    $output = '';
    foreach ($parts as $part) {
        if (preg_match($pattern, $part)) {
            $output .= '<mark class="bg-yellow-200 dark:bg-yellow-800 dark:text-white rounded-sm px-0.5">' . esc_html($part) . '</mark>';
        } else {
            $output .= esc_html($part);
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

    $big = 999999999;
    $pages = paginate_links(array(
        'base' => str_replace($big, '%#%', esc_url(get_pagenum_link($big))),
        'format' => '?paged=%#%',
        'current' => max(1, get_query_var('paged')),
        'total' => $wp_query->max_num_pages,
        'prev_text' => '<span aria-hidden="true">&larr;</span><span class="screen-reader-text">上一页</span>',
        'next_text' => '<span class="screen-reader-text">下一页</span><span aria-hidden="true">&rarr;</span>',
        'type' => 'array',
    ));

    if (is_array($pages)) {
        echo '<nav class="pt-8 flex justify-center items-center gap-2" aria-label="分页导航">';

        foreach ($pages as $page) {
            if (strpos($page, 'current') !== false) {
                echo "<span class='page-numbers px-3 py-1 border border-transparent bg-gray-900 text-white dark:bg-white dark:text-gray-900 rounded-sm text-sm' aria-current='page'>" . esc_html(strip_tags($page)) . "</span>";
            } else {
                echo zen_kses_link(str_replace('page-numbers', 'page-numbers px-3 py-1 border border-gray-200 dark:border-gray-700 text-sm hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors rounded-sm text-gray-600 dark:text-gray-400', $page));
            }
        }

        echo '</nav>';
    }
}

function zen_clear_archives_cache() {
    delete_transient('zen_archives_posts');
}
add_action('save_post_post', 'zen_clear_archives_cache');
add_action('deleted_post', 'zen_clear_archives_cache');
add_action('trashed_post', 'zen_clear_archives_cache');
add_action('untrashed_post', 'zen_clear_archives_cache');
