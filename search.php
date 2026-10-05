<?php get_header(); ?>
<header class="zen-list-header">
    <h1 class="zen-list-title serif">搜索结果</h1>
    <div class="zen-inline-search"><?php get_search_form(); ?></div>
    <?php global $wp_query; ?>
    <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo esc_html(sprintf('找到 %d 篇文章', $wp_query->found_posts)); ?></p>
</header>
<div class="zen-post-list">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php get_template_part('template-parts/content', 'excerpt', array('highlight_title' => true)); ?>
    <?php endwhile; else : ?>
        <div class="zen-empty-state">
            <h2 class="text-xl font-bold">未找到相关内容</h2>
            <p>换个关键词再试试。</p>
            <?php $archive_url = zen_get_archives_url(); ?>
            <?php if ($archive_url) : ?><a href="<?php echo esc_url($archive_url); ?>" class="zen-ui-link">浏览归档</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php zen_pagination(); ?>
<?php get_footer(); ?>
