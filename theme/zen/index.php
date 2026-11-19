<?php get_header(); ?>

<?php
global $wp_query;
$zen_description = get_bloginfo('description');
$zen_first_index = is_home() && !is_paged();
$zen_masthead = $zen_first_index && zen_get_option('zen_show_site_intro') && $zen_description !== '';
$zen_latest = $zen_first_index && zen_get_option('zen_show_latest_card')
    ? zen_get_latest_index_post($wp_query->posts ?? array())
    : null;
?>
<?php if ($zen_masthead) : ?>
<header class="zen-masthead">
    <h1><?php echo esc_html(get_bloginfo('name')); ?></h1>
    <p class="zen-site-intro"><?php echo esc_html($zen_description); ?></p>
</header>
<?php elseif ($zen_latest) : ?>
<h1 class="screen-reader-text"><?php esc_html_e('文章索引', 'zen'); ?></h1>
<?php endif; ?>

<?php zen_category_navigation(); ?>

<?php if ($zen_latest) : ?>
    <?php // The child-overridable list template expects the current Loop post. ?>
    <article id="post-<?php echo (int) $zen_latest->ID; ?>" <?php post_class('zen-latest-card', $zen_latest); ?> aria-labelledby="post-title-<?php echo (int) $zen_latest->ID; ?>">
        <p class="zen-latest-label"><?php esc_html_e('最新文章', 'zen'); ?></p>
        <h2 id="post-title-<?php echo (int) $zen_latest->ID; ?>" class="zen-post-title">
            <a href="<?php the_permalink($zen_latest); ?>" class="zen-post-title-link"><?php echo esc_html(zen_post_title($zen_latest)); ?></a>
        </h2>
        <?php zen_index_post_excerpt($zen_latest); ?>
        <?php zen_index_post_meta('full', $zen_latest); ?>
    </article>
<?php endif; ?>

<?php if (is_home() && (!$zen_latest || $wp_query->post_count > 1)) : ?>
<div class="zen-index-heading">
    <?php if ($zen_latest || $zen_masthead) : ?>
    <h2><?php esc_html_e('近期文章', 'zen'); ?></h2>
    <?php else : ?>
    <h1><?php esc_html_e('文章索引', 'zen'); ?></h1>
    <?php endif; ?>
    <?php $zen_archive_url = zen_get_archives_url(); ?>
    <?php if ($zen_archive_url) : ?>
    <a href="<?php echo esc_url($zen_archive_url); ?>" class="zen-ui-link"><?php esc_html_e('全部归档', 'zen'); ?> <span aria-hidden="true">→</span></a>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="zen-post-list">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php if (!$zen_latest || (int) get_the_ID() !== (int) $zen_latest->ID) : ?>
            <?php get_template_part('template-parts/content', 'excerpt'); ?>
        <?php endif; ?>
    <?php endwhile; else : ?>
        <p class="zen-empty-state"><?php esc_html_e('暂无文章。', 'zen'); ?></p>
    <?php endif; ?>
</div>
<?php zen_pagination(); ?>

<?php get_footer(); ?>
