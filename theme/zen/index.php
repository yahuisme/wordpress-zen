<?php get_header(); ?>

<?php $zen_description = get_bloginfo('description'); ?>
<?php if (is_home() && zen_get_option('zen_show_site_intro') && $zen_description !== '') : ?>
<p class="zen-site-intro text-gray-600 dark:text-gray-400"><?php echo esc_html($zen_description); ?></p>
<?php endif; ?>

<?php zen_category_navigation(); ?>

<div class="zen-post-list">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php get_template_part('template-parts/content', 'excerpt'); ?>
    <?php endwhile; else : ?>
        <p>暂无文章。</p>
    <?php endif; ?>
</div>
<?php zen_pagination(); ?>

<?php get_footer(); ?>
