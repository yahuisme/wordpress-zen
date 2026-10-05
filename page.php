<?php get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

<div class="zen-reading-shell">
    <header class="zen-article-header">
        <h1 class="zen-article-title font-bold text-gray-900 dark:text-white leading-tight serif">
            <?php the_title(); ?>
        </h1>
    </header>

    <article class="prose prose-lg prose-zinc dark:prose-invert focus:outline-none entry-content">
        <?php the_content(); ?>
    </article>

    <?php wp_link_pages(array(
        'before' => '<nav class="zen-page-links" aria-label="' . esc_attr__('正文分页', 'zen') . '">',
        'after' => '</nav>',
    )); ?>

    <?php
    if (comments_open() || get_comments_number()) :
        comments_template();
    endif;
    ?>
</div>

<?php endwhile; endif; ?>

<?php get_footer(); ?>
