</main> <!-- End Main -->

<footer role="contentinfo" class="zen-site-footer w-full mt-auto">
    <div class="max-w-zen mx-auto px-4 sm:px-6 py-6 md:h-20 md:py-0 flex flex-col md:flex-row items-center justify-between text-xs text-gray-600 dark:text-gray-400 gap-y-3">
        <div class="text-center md:text-left">
            <div>&copy; <?php echo esc_html(date_i18n('Y')); ?> <?php echo esc_html(get_bloginfo('name')); ?>. 保留所有权利.</div>
        </div>
        <?php
        $zen_footer_text = zen_get_option('zen_footer_text');
        $zen_show_footer_theme_by = zen_get_option('zen_show_footer_theme_by');
        $zen_show_footer_wordpress = zen_get_option('zen_show_footer_wordpress');
        $zen_show_footer_rss = zen_get_option('zen_show_footer_rss');
        $zen_show_footer_links = $zen_show_footer_theme_by || $zen_show_footer_wordpress || $zen_show_footer_rss;
        if ($zen_footer_text !== '' || $zen_show_footer_links) :
        ?>
        <div class="zen-footer-links flex flex-col items-center gap-y-2">
            <?php if ($zen_footer_text !== '') : ?>
            <div class="zen-footer-row flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
                <?php echo wp_kses_post($zen_footer_text); ?>
            </div>
            <?php endif; ?>
            <?php if ($zen_show_footer_links) : ?>
            <div class="zen-footer-row flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
                <?php if ($zen_show_footer_theme_by) : ?>
                <a href="https://github.com/yahuisme/wordpress-zen" target="_blank" rel="noopener noreferrer" class="zen-ui-link hover:text-gray-900 dark:hover:text-white" aria-label="Theme By RyanZ (在新窗口打开)">Theme By RyanZ</a>
                <?php endif; ?>
                <?php if ($zen_show_footer_wordpress) : ?>
                <a href="https://wordpress.org/" target="_blank" rel="noopener noreferrer" class="zen-ui-link hover:text-gray-900 dark:hover:text-white" aria-label="Powered By WordPress (在新窗口打开)">Powered By WordPress</a>
                <?php endif; ?>
                <?php if ($zen_show_footer_rss) : ?>
                <a href="<?php echo esc_url(get_bloginfo('rss2_url')); ?>" target="_blank" rel="noopener noreferrer" class="zen-ui-link hover:text-gray-900 dark:hover:text-white flex items-center gap-1" title="订阅 RSS">
                    <i class="ph ph-rss text-sm" aria-hidden="true"></i> RSS
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (has_nav_menu('footer')) : ?>
        <nav class="zen-footer-menu" aria-label="页脚菜单">
            <?php wp_nav_menu(array('theme_location' => 'footer', 'container' => false, 'menu_class' => 'flex flex-wrap items-center justify-center gap-x-4 gap-y-2')); ?>
        </nav>
        <?php endif; ?>
    </div>
</footer>

<!-- Lightbox -->
<?php if (zen_get_option('zen_show_lightbox')) : ?>
<div id="lightbox" 
     role="dialog" 
     aria-modal="true" 
     aria-label="图片查看器" 
     class="zen-lightbox fixed inset-0 z-[100] hidden flex items-center justify-center p-4 cursor-zoom-out"
     tabindex="-1">
    <div class="zen-lightbox-panel relative cursor-default">
        <img id="lightbox-img" src="" alt="" class="zen-lightbox-image object-contain">
        <button id="lightbox-close" class="zen-lightbox-close zen-icon-btn flex items-center justify-center" type="button" aria-label="关闭图片">
            <i class="ph ph-x" aria-hidden="true"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Back to Top -->
<?php if (zen_get_option('zen_show_back_to_top')) : ?>
<button id="back-to-top" type="button" class="zen-back-to-top zen-icon-btn fixed bottom-8 right-8 w-10 h-10 rounded-full flex items-center justify-center opacity-0 pointer-events-none translate-y-4 z-50 focus:outline-none" aria-label="返回顶部">
    <i class="ph ph-arrow-up text-xl" aria-hidden="true"></i>
</button>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
