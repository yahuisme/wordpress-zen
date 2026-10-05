<?php get_header(); ?>
<section class="zen-empty-state zen-reading-shell">
    <p class="text-sm text-gray-600 dark:text-gray-400">404</p>
    <h1 class="zen-list-title serif">页面未找到</h1>
    <p>链接可能已失效，也可以搜索其他内容。</p>
    <div class="zen-inline-search"><?php get_search_form(); ?></div>
    <div class="zen-recovery-links">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="zen-ui-link">返回首页</a>
        <?php $archive_url = zen_get_archives_url(); ?>
        <?php if ($archive_url) : ?><a href="<?php echo esc_url($archive_url); ?>" class="zen-ui-link">浏览归档</a><?php endif; ?>
    </div>
</section>
<?php get_footer(); ?>
