<?php get_header(); ?>
<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

<div class="zen-reading-shell">
    <header class="zen-article-header">
        <h1 class="zen-article-title font-bold text-gray-900 dark:text-white leading-tight serif"><?php the_title(); ?></h1>
        <div class="zen-post-meta text-sm text-gray-600 dark:text-gray-400">
            <?php $cat = zen_get_primary_category(); ?>
            <?php if ($cat) : ?>
            <a href="<?php echo esc_url(get_category_link($cat->term_id)); ?>" class="zen-meta-field zen-ui-link"><?php echo esc_html($cat->name); ?></a>
            <?php endif; ?>
            <?php zen_posted_on_link(); ?>
            <?php if (zen_get_option('zen_show_reading_count')) : ?>
            <span class="zen-meta-field">浏览次数 <?php echo esc_html(number_format_i18n(zen_get_reading_count())); ?></span>
            <?php endif; ?>
            <?php if (zen_get_option('zen_show_reading_time')) : ?>
            <span class="zen-meta-field">阅读时间 <?php echo esc_html(zen_get_reading_time()); ?> 分钟</span>
            <?php endif; ?>
        </div>
        <?php if (zen_get_option('zen_show_toc')) : ?>
        <button id="floating-toc-btn" type="button" class="zen-inline-toc-btn zen-ui-link hidden" aria-label="打开目录" aria-haspopup="dialog" aria-controls="drawer-toc" aria-expanded="false" hidden><i class="ph ph-list-bullets" aria-hidden="true"></i><span>目录</span><span aria-hidden="true">⌄</span></button>
        <?php endif; ?>
    </header>

    <?php if (zen_get_option('zen_show_toc')) : ?>
    <aside id="toc-container" class="zen-desktop-toc hidden xl:block opacity-0 transition-opacity duration-500" aria-label="文章目录">
        <h2 class="zen-toc-title">目录</h2>
        <nav id="toc-nav" class="custom-scrollbar" aria-label="桌面端目录导航"></nav>
    </aside>
    <div id="toc-overlay" class="fixed inset-0 bg-black/20 dark:bg-black/50 backdrop-blur-sm z-[60] hidden transition-opacity opacity-0" aria-hidden="true"></div>
    <aside id="drawer-toc" class="zen-toc-drawer fixed top-0 right-0 w-80 h-screen max-w-full z-[70] transform translate-x-full transition-transform duration-300 shadow-2xl flex flex-col" role="dialog" aria-modal="true" aria-labelledby="drawer-toc-title" inert>
        <div class="zen-toc-drawer-header flex items-center justify-between p-6">
            <h2 id="drawer-toc-title" class="font-bold">目录</h2>
            <button id="drawer-toc-close" type="button" class="zen-icon-btn" aria-label="关闭目录"><i class="ph ph-x text-xl" aria-hidden="true"></i></button>
        </div>
        <div class="flex-1 overflow-y-auto p-6 custom-scrollbar">
            <nav id="drawer-toc-nav" aria-label="移动端目录导航"></nav>
        </div>
    </aside>
    <?php endif; ?>

    <?php if (zen_get_option('zen_show_featured_image') && has_post_thumbnail()) : ?>
    <div class="zen-featured-media">
        <?php the_post_thumbnail('large', array('class' => 'zen-featured-image', 'sizes' => '(max-width: 767px) calc(100vw - 32px), 720px')); ?>
    </div>
    <?php endif; ?>

    <article id="post-content" class="prose prose-lg prose-zinc dark:prose-invert focus:outline-none entry-content">
        <?php the_content(); ?>
    </article>
    <?php wp_link_pages(array(
        'before' => '<nav class="zen-page-links" aria-label="' . esc_attr__('正文分页', 'zen') . '">',
        'after' => '</nav>',
    )); ?>

    <?php
    $zen_author = get_the_author();
    $zen_license = zen_get_option('zen_copyright_license');
    $zen_licenses = array(
        'all-rights-reserved' => array('保留所有权利', ''),
        'cc-by-4.0' => array('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/deed.zh-hans'),
        'cc-by-sa-4.0' => array('CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/deed.zh-hans'),
        'cc-by-nc-sa-4.0' => array('CC BY-NC-SA 4.0', 'https://creativecommons.org/licenses/by-nc-sa/4.0/deed.zh-hans'),
    );
    $zen_license_info = isset($zen_licenses[$zen_license]) ? $zen_licenses[$zen_license] : null;
    ?>
    <?php if (zen_get_option('zen_show_updated_date') || $zen_author !== '' || $zen_license_info) : ?>
    <aside class="zen-article-notes text-sm text-gray-600 dark:text-gray-400" aria-label="文章说明">
        <?php if (zen_get_option('zen_show_updated_date')) : ?>
        <div>最后更新：<?php echo esc_html(get_the_modified_date('Y年n月j日')); ?></div>
        <?php endif; ?>
        <?php if ($zen_author !== '') : ?><div>本文作者：<?php echo esc_html($zen_author); ?></div><?php endif; ?>
        <?php if ($zen_license_info) : ?>
        <div>本文链接：<a href="<?php echo esc_url(get_permalink()); ?>" class="zen-ui-link zen-permalink"><?php echo esc_html(get_permalink()); ?></a></div>
        <div>版权声明：除特别声明外，本站文章<?php if ($zen_license_info[1]) : ?>采用 <a href="<?php echo esc_url($zen_license_info[1]); ?>" target="_blank" rel="license noopener noreferrer" class="zen-ui-link" aria-label="<?php echo esc_attr($zen_license_info[0] . ' (在新窗口打开)'); ?>"><?php echo esc_html($zen_license_info[0]); ?></a> 许可协议。<?php else : ?><?php echo esc_html($zen_license_info[0]); ?>。<?php endif; ?></div>
        <?php endif; ?>
    </aside>
    <?php endif; ?>

    <?php $tags = zen_get_option('zen_show_tags') ? get_the_tags() : false; ?>
    <?php if ($tags) : ?>
    <div class="zen-post-taxonomy">
        <div class="zen-taxonomy-row">
            <span class="zen-meta-label">标签</span>
            <div class="zen-tag-list">
                <?php foreach ($tags as $tag) : ?>
                <a href="<?php echo esc_url(get_tag_link($tag->term_id)); ?>" class="zen-tag-pill">#<?php echo esc_html($tag->name); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (zen_get_option('zen_show_post_navigation')) : ?>
    <?php $previous_post = get_previous_post(); $next_post = get_next_post(); ?>
    <?php if ($previous_post || $next_post) : ?>
    <nav class="zen-post-navigation" aria-label="文章导航">
        <div class="zen-post-nav-previous">
            <?php if ($previous_post) : ?>
            <a href="<?php echo esc_url(get_permalink($previous_post)); ?>" class="zen-ui-link" title="<?php echo esc_attr(get_the_title($previous_post)); ?>">
                <span class="zen-post-nav-label">上一篇</span>
                <span class="zen-post-nav-title"><?php echo esc_html(get_the_title($previous_post)); ?></span>
            </a>
            <?php endif; ?>
        </div>
        <div class="zen-post-nav-next">
            <?php if ($next_post) : ?>
            <a href="<?php echo esc_url(get_permalink($next_post)); ?>" class="zen-ui-link" title="<?php echo esc_attr(get_the_title($next_post)); ?>">
                <span class="zen-post-nav-label">下一篇</span>
                <span class="zen-post-nav-title"><?php echo esc_html(get_the_title($next_post)); ?></span>
            </a>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; endif; ?>
    <?php if (comments_open() || get_comments_number()) comments_template(); ?>
</div>
<?php endwhile; endif; ?>
<?php get_footer(); ?>
