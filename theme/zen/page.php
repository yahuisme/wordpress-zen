<?php get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

<div class="zen-reading-shell">
    <header class="zen-article-header">
        <h1 class="zen-article-title">
            <?php echo esc_html(zen_post_title()); ?>
        </h1>
    </header>

    <article class="focus:outline-none entry-content">
        <?php the_content(); ?>
    </article>

    <?php zen_content_page_links(); ?>

    <?php
    if (comments_open() || get_comments_number()) :
        comments_template();
    endif;
    ?>
</div>

<?php endwhile; endif; ?>

<?php get_footer(); ?>
