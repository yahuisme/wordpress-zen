<?php
get_header();
$zen_search_blank = trim(get_search_query(false)) === '';
?>
<header class="zen-search-heading<?php if ($zen_search_blank) echo ' zen-search-heading--blank'; ?>">
    <h1 class="screen-reader-text"><?php esc_html_e('搜索', 'zen'); ?></h1>
    <div class="zen-inline-search zen-native-search">
        <?php get_search_form(); ?>
        <i class="ph ph-magnifying-glass zen-native-search-icon" aria-hidden="true"></i>
    </div>
    <?php if (!$zen_search_blank) : ?>
        <?php global $wp_query; ?>
        <p class="zen-search-count"><?php echo esc_html(sprintf(__('找到 %d 个结果', 'zen'), $wp_query->found_posts)); ?></p>
    <?php endif; ?>
</header>
<?php if (!$zen_search_blank) : ?>
    <div class="zen-post-list">
        <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
            <?php get_template_part('template-parts/content', 'excerpt', array('highlight_title' => true)); ?>
        <?php endwhile; else : ?>
            <div class="zen-empty-state">
                <h2><?php esc_html_e('未找到相关内容', 'zen'); ?></h2>
                <p><?php esc_html_e('换个关键词再试试。', 'zen'); ?></p>
                <?php $archive_url = zen_get_archives_url(); ?>
                <?php if ($archive_url) : ?><a href="<?php echo esc_url($archive_url); ?>" class="zen-ui-link"><?php esc_html_e('浏览归档', 'zen'); ?></a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php zen_pagination(); ?>
<?php endif; ?>
<?php get_footer(); ?>
