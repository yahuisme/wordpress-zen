</main>

<?php if (zen_get_option('zen_show_back_to_top')) : ?>
<button id="back-to-top" type="button" disabled class="zen-back-to-top zen-icon-btn w-10 h-10 rounded-full flex items-center justify-center opacity-0 pointer-events-none translate-y-4 z-50" aria-label="返回顶部">
    <i class="ph ph-arrow-up text-xl" aria-hidden="true"></i>
</button>
<?php endif; ?>

<footer role="contentinfo" class="zen-site-footer w-full mt-auto">
    <div class="max-w-zen mx-auto flex flex-col sm:flex-row items-center justify-between">
        <div class="text-start">
            <?php $zen_uptime = zen_get_site_uptime(); ?>
            <div>&copy; <?php echo esc_html(date_i18n('Y')); ?> <?php echo esc_html(get_bloginfo('name')); ?> · Since <?php echo esc_html($zen_uptime['year']); ?> · <?php echo esc_html($zen_uptime['days']); ?> <?php echo 1 === (int) $zen_uptime['days'] ? 'Day' : 'Days'; ?></div>
        </div>
        <?php
        $zen_footer_text = zen_get_option('zen_footer_text');
        if ($zen_footer_text !== '') :
        ?>
        <div class="zen-footer-links flex flex-col items-center gap-y-2">
            <div class="zen-footer-row flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
                <?php echo wp_kses_post($zen_footer_text); ?>
            </div>
        </div>
        <?php endif; ?>
        <nav class="zen-footer-navigation" aria-label="<?php esc_attr_e('页脚菜单', 'zen'); ?>">
            <ul class="flex flex-wrap items-center gap-x-2">
                <?php if (has_nav_menu('footer')) : ?>
                    <?php wp_nav_menu(array('theme_location' => 'footer', 'container' => false, 'items_wrap' => '%3$s', 'fallback_cb' => false)); ?>
                <?php else : ?>
                    <li><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'zen'); ?></a></li>
                    <?php foreach (array('page-archives.php' => __('Archive', 'zen'), 'page-tags.php' => __('Tags', 'zen'), 'page-notes.php' => __('Notes', 'zen'), 'page-links.php' => __('Links', 'zen')) as $template => $label) : ?>
                        <?php $directory_url = zen_get_directory_url($template); ?>
                        <?php if ($directory_url) : ?><li><a href="<?php echo esc_url($directory_url); ?>"><?php echo esc_html($label); ?></a></li><?php endif; ?>
                    <?php endforeach; ?>
                    <?php $about_page = get_page_by_path('about', OBJECT, array('page')); ?>
                    <?php if ($about_page && 'publish' === $about_page->post_status) : ?>
                        <li><a href="<?php echo esc_url(get_permalink($about_page)); ?>"><?php esc_html_e('About', 'zen'); ?></a></li>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (zen_get_option('zen_show_footer_rss')) : ?>
                    <li><a href="<?php echo esc_url(get_bloginfo('rss2_url')); ?>" target="_blank" rel="noopener noreferrer" class="gap-1" aria-label="<?php esc_attr_e('订阅 RSS (在新窗口打开)', 'zen'); ?>"><i class="ph ph-rss text-sm" aria-hidden="true"></i> RSS</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</footer>

<?php if (zen_get_option('zen_show_lightbox')) : ?>
<div id="lightbox"
     role="dialog"
     aria-modal="true"
     aria-label="图片查看器"
     class="zen-lightbox fixed inset-0 z-[100] hidden flex items-center justify-center p-4 cursor-zoom-out"
     tabindex="-1">
    <div class="zen-lightbox-panel relative">
        <img id="lightbox-img" src="" alt="" class="zen-lightbox-image object-contain">
        <button id="lightbox-close" class="zen-lightbox-close zen-icon-btn flex items-center justify-center" type="button" aria-label="关闭图片">
            <i class="ph ph-x" aria-hidden="true"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
