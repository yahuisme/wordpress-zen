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
    <h1 class="zen-site-intro"><?php echo esc_html($zen_description); ?></h1>
    <div class="zen-masthead-meta">
        <?php $zen_uptime = zen_get_site_uptime(); ?>
        <span><?php echo esc_html(sprintf(__('建站 %s 天', 'zen'), number_format_i18n($zen_uptime['days']))); ?></span>
        <span class="zen-masthead-stats">
            <span><?php echo esc_html(sprintf(__('共 %s 篇文章', 'zen'), number_format_i18n((int) ($wp_query->found_posts ?? 0)))); ?></span>
            <?php if ($zen_latest) : ?>
                <span><?php echo esc_html(sprintf(__('最近发布 %s', 'zen'), get_the_date('Y.m.d', $zen_latest))); ?></span>
            <?php endif; ?>
        </span>
    </div>
</header>
<?php elseif ($zen_latest) : ?>
<h1 class="screen-reader-text"><?php esc_html_e('文章索引', 'zen'); ?></h1>
<?php endif; ?>

<?php if ($zen_latest) : ?>
    <div class="zen-featured-section">
    <article id="post-<?php echo (int) $zen_latest->ID; ?>" <?php post_class('zen-latest-card', $zen_latest); ?> aria-labelledby="post-title-<?php echo (int) $zen_latest->ID; ?>">
        <p class="zen-latest-label"><?php esc_html_e('卷首 · 近作', 'zen'); ?></p>
        <h2 id="post-title-<?php echo (int) $zen_latest->ID; ?>" class="zen-post-title">
            <a href="<?php the_permalink($zen_latest); ?>" class="zen-post-title-link"><?php echo esc_html(zen_post_title($zen_latest)); ?></a>
        </h2>
        <?php zen_index_post_excerpt($zen_latest); ?>
        <div class="zen-post-meta"><?php zen_posted_on_link('list', $zen_latest); ?></div>
    </article>
    </div>
<?php endif; ?>

<?php if (is_home() && (!$zen_latest || $wp_query->post_count > 1)) : ?>
<div class="zen-index-heading">
    <?php if ($zen_latest || $zen_masthead) : ?>
    <h2><?php esc_html_e('近期辑录', 'zen'); ?></h2>
    <?php else : ?>
    <h1><?php esc_html_e('文章索引', 'zen'); ?></h1>
    <?php endif; ?>
    <?php $zen_archive_url = zen_get_archives_url(); ?>
    <?php if ($zen_archive_url) : ?>
    <a href="<?php echo esc_url($zen_archive_url); ?>" class="zen-ui-link"><?php esc_html_e('全部篇目', 'zen'); ?> <span aria-hidden="true">→</span></a>
    <?php endif; ?>
</div>
<?php endif; ?>
<div class="zen-post-list">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php if (!$zen_latest || (int) get_the_ID() !== (int) $zen_latest->ID) : ?>
            <?php get_template_part('template-parts/content', 'excerpt', array('editorial_index' => is_home())); ?>
        <?php endif; ?>
    <?php endwhile; else : ?>
        <p class="zen-empty-state"><?php esc_html_e('暂无文章。', 'zen'); ?></p>
    <?php endif; ?>
</div>
<?php zen_pagination(); ?>

<?php get_footer(); ?>
