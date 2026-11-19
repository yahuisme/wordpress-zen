<?php get_header(); ?>
<section class="zen-error-page" aria-labelledby="zen-error-title">
    <p class="zen-error-code">404</p>
    <h1 id="zen-error-title"><?php esc_html_e('页面未找到', 'zen'); ?></h1>
    <p class="zen-error-copy"><?php esc_html_e('链接可能已失效，或页面已被移除。', 'zen'); ?></p>
    <nav class="zen-recovery-links" aria-label="<?php echo esc_attr__('页面导航', 'zen'); ?>">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="zen-ui-link"><?php esc_html_e('返回首页', 'zen'); ?></a>
        <?php $archive_url = zen_get_archives_url(); ?>
        <?php if ($archive_url) : ?>
            <span aria-hidden="true">/</span>
            <a href="<?php echo esc_url($archive_url); ?>" class="zen-ui-link"><?php esc_html_e('浏览归档', 'zen'); ?></a>
        <?php endif; ?>
        <span aria-hidden="true">/</span>
        <a href="<?php echo esc_url(home_url('/?s=')); ?>" class="zen-ui-link"><?php esc_html_e('搜索', 'zen'); ?></a>
    </nav>
</section>
<?php get_footer(); ?>
