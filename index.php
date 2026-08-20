<?php get_header(); ?>

<!-- Breadcrumb -->
<nav aria-label="面包屑导航" class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-gray-600 dark:text-gray-400 border-b border-dashed border-gray-200 dark:border-gray-800 pb-4 mb-8">
    <a href="<?php echo esc_url(home_url('/')); ?>" class="zen-ui-link text-gray-900 dark:text-white font-medium border-b-2 border-gray-900 dark:border-white pb-0.5" aria-current="page">全部</a>
    <span class="text-gray-300" aria-hidden="true">/</span>
    <?php
    $categories = get_categories(array('number' => 5));
    foreach($categories as $category) {
        // A11y: text-gray-600 for contrast
        echo '<a href="' . esc_url(get_category_link($category->term_id)) . '" class="zen-ui-link text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">' . esc_html($category->name) . '</a>';
        echo '<span class="text-gray-300 last:hidden" aria-hidden="true">/</span>';
    }
    ?>
</nav>

<div class="space-y-12">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php get_template_part('template-parts/content', 'excerpt'); ?>

    <?php endwhile; ?>
        
        <?php zen_pagination(); ?>

    <?php else : ?>
        <p>暂无文章。</p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
