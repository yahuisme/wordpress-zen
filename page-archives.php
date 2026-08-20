<?php
/*
Template Name: Archives Template
*/
get_header(); ?>

<header class="mb-12 text-center">
    <h1 class="text-3xl font-bold mb-2 serif"><?php the_title(); ?></h1>
    <div class="text-gray-600 dark:text-gray-400">文章时光机</div>
</header>

<div class="max-w-2xl mx-auto space-y-8 animate-fade-in">
    <?php
    $archives_posts = get_transient('zen_archives_posts');

    if (false === $archives_posts) {
        $archives_query = new WP_Query(array(
            'posts_per_page' => -1,
            'ignore_sticky_posts' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        $archives_posts = array_map(function ($post_id) {
            return array(
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'url' => get_permalink($post_id),
                'year' => get_the_date('Y', $post_id),
                'date' => get_the_date('m-d', $post_id),
            );
        }, $archives_query->posts);

        wp_reset_postdata();
        set_transient('zen_archives_posts', $archives_posts, HOUR_IN_SECONDS);
    }

    $year_prev = null;
    
    if (!empty($archives_posts)) : 
        // A11y: 修复列表结构。年份作为标题在列表之外。
        foreach ($archives_posts as $archive_post) : 
            $year_current = $archive_post['year'];
            
            // 如果年份改变
            if ($year_current != $year_prev) {
                // 如果不是第一年，先闭合上一个年份的列表容器
                if ($year_prev != null) { ?>
                    </ul>
                </section>
                <?php } ?>
                
                <section>
                    <h3 class="text-xl font-bold mb-4 text-gray-600 dark:text-gray-400 border-b border-gray-100 dark:border-gray-800 pb-2"><?php echo esc_html($year_current); ?></h3>
                    <ul class="space-y-4">
            <?php } ?>
            
            <li class="flex items-baseline justify-between group">
                <a href="<?php echo esc_url($archive_post['url']); ?>" class="text-lg text-gray-800 dark:text-gray-200 hover:underline decoration-1 underline-offset-4">
                    <?php echo esc_html($archive_post['title']); ?>
                </a>
                <span class="text-sm text-gray-600 dark:text-gray-500 shrink-0 font-mono"><?php echo esc_html($archive_post['date']); ?></span>
            </li>

            <?php $year_prev = $year_current; ?>
            
        <?php endforeach; ?>
            </ul> <!-- Close last ul -->
        </section> <!-- Close last section -->
    <?php else : ?>
        <p class="text-center text-gray-600">暂无文章。</p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
