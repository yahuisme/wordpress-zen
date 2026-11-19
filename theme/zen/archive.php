<?php get_header(); ?>
<header class="zen-directory-heading zen-directory-heading--taxonomy">
    <p class="zen-directory-kicker"><?php
        if (is_category()) esc_html_e('分类', 'zen');
        elseif (is_tag()) esc_html_e('标签', 'zen');
        elseif (is_author()) esc_html_e('作者', 'zen');
        else esc_html_e('归档', 'zen');
    ?></p>
    <h1 class="zen-directory-title"><?php
        if (is_category()) echo esc_html(single_cat_title('', false));
        elseif (is_tag()) echo esc_html(single_tag_title('', false));
        elseif (is_author()) echo esc_html(get_the_author());
        elseif (is_day()) echo esc_html(get_the_date('Y年n月j日'));
        elseif (is_month()) echo esc_html(get_the_date('Y年n月'));
        elseif (is_year()) echo esc_html(get_the_date('Y年'));
        else the_archive_title();
    ?></h1>
    <?php $archive_description = get_the_archive_description(); ?>
    <?php if ($archive_description) : ?>
    <div class="zen-directory-description"><?php echo wp_kses_post($archive_description); ?></div>
    <?php endif; ?>
</header>
<?php if (is_category()) zen_category_navigation(); ?>
<div class="zen-post-list">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php get_template_part('template-parts/content', 'excerpt'); ?>
    <?php endwhile; else : ?>
        <div class="zen-empty-state">
            <h2 class="text-xl font-bold">暂无文章</h2>
            <p>此归档下暂时没有文章。</p>
            <a href="<?php echo esc_url(zen_get_posts_url()); ?>" class="zen-ui-link">查看全部文章</a>
        </div>
    <?php endif; ?>
</div>
<?php zen_pagination(); ?>
<?php get_footer(); ?>
