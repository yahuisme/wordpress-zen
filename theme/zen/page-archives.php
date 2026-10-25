<?php
/*
Template Name: Archives Template
*/
get_header(); ?>

<header class="zen-article-header zen-reading-shell">
    <h1 class="text-3xl font-bold mb-2 serif"><?php the_title(); ?></h1>

</header>

<div class="zen-archives-directory zen-reading-shell">
    <?php
    $archive_context = get_locale() . ':' . (function_exists('pll_current_language') ? pll_current_language() : '');
    $archives_cache = get_transient('zen_archives_public_posts_v2');
    $archives_cache = is_array($archives_cache) ? $archives_cache : array();
    $archives_posts = $archives_cache[$archive_context] ?? false;

    if (false === $archives_posts) {
        $archives_posts = array();
        $archive_page = 1;
        $archive_batch_size = 500;

        do {
            $archives_query = new WP_Query(array(
                'post_status' => 'publish',
                'posts_per_page' => $archive_batch_size,
                'paged' => $archive_page,
                'ignore_sticky_posts' => true,
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => str_contains((string) get_option('permalink_structure'), '%category%'),
            ));

            foreach ($archives_query->posts as $archive_post) {
                $archives_posts[] = array(
                    'id' => $archive_post->ID,
                    'title' => get_the_title($archive_post),
                    'url' => get_permalink($archive_post),
                    'year' => get_the_date('Y', $archive_post),
                    'date' => get_the_date('Y年n月j日', $archive_post),
                );
            }

            wp_reset_postdata();
            $archive_has_more = count($archives_query->posts) === $archive_batch_size;
            $archive_page++;
            unset($archives_query);
        } while ($archive_has_more);

        // Keep language variants under one invalidation boundary; discard legacy flat rows.
        $archives_cache = array_filter($archives_cache, 'is_string', ARRAY_FILTER_USE_KEY);
        $archives_cache[$archive_context] = $archives_posts;
        set_transient('zen_archives_public_posts_v2', $archives_cache, HOUR_IN_SECONDS);
    }

    $year_prev = null;

    if (!empty($archives_posts)) :
        foreach ($archives_posts as $archive_post) :
            $year_current = $archive_post['year'];

            if ($year_current !== $year_prev) {
                if ($year_prev !== null) { ?>
                    </ul>
                </section>
                <?php } ?>

                <section>
                    <h3 class="text-xl font-bold mb-4 text-gray-600 dark:text-gray-400 border-b border-gray-100 dark:border-gray-800 pb-2"><?php echo esc_html($year_current); ?></h3>
                    <ul class="zen-post-list">
            <?php } ?>

            <li class="zen-archive-row zen-post-item flex items-baseline justify-between group">
                <a href="<?php echo esc_url($archive_post['url']); ?>" class="zen-archive-link text-lg text-gray-800 dark:text-gray-200 hover:opacity-80 transition-opacity">
                    <?php echo esc_html($archive_post['title']); ?>
                </a>
                <span class="zen-meta-field text-sm text-gray-600 dark:text-gray-500"><?php echo esc_html($archive_post['date']); ?></span>
            </li>

            <?php $year_prev = $year_current; ?>

        <?php endforeach; ?>
            </ul>
        </section>
    <?php else : ?>
        <p class="text-center text-gray-600">暂无文章。</p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
