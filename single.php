<?php get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

    <?php if (zen_get_option('zen_show_toc')) : ?>

    <!-- 
        1. PC端侧边栏目录 (Large Screen Sidebar TOC)
        显示条件: 屏幕宽度 >= xl (1280px)
        布局: 固定定位，位于内容右侧
    -->
    <aside id="toc-container" class="zen-desktop-toc hidden xl:block fixed top-32 w-56 opacity-0 transition-opacity duration-500" style="left: calc(50% + min(var(--zen-content-half), 50vw - 1rem) + 2rem);" aria-label="文章目录">
        <div class="relative">
            <h4 class="text-[10px] font-bold uppercase tracking-widest text-gray-500 dark:text-gray-400 mb-4 pl-3">
                目录
            </h4>
            <!-- 
                FIX: 调整最大高度，防止与评论区重叠
                max-h-[calc(100vh-24rem)]: 增加底部留白，从 12rem 增加到 24rem
            -->
            <nav id="toc-nav" class="relative border-l-2 border-gray-100 dark:border-gray-800 space-y-1 max-h-[calc(100vh-24rem)] overflow-y-auto pr-2 custom-scrollbar" aria-label="桌面端目录导航">
                <!-- JS 填充内容 -->
            </nav>
        </div>
    </aside>

    <!-- 
        2. 悬浮折叠目录按钮 (Floating Toggle Button)
        显示条件: 屏幕宽度 < xl
        位置: 屏幕右侧边缘，垂直居中
        动画: 使用 Tailwind 实现弹性过渡 (Bezier Curve)
    -->
    <button id="floating-toc-btn" type="button"
            class="zen-mobile-toc xl:hidden fixed right-0 top-1/2 -translate-y-1/2 z-50
                   zen-floating-toc-btn p-3 rounded-l-lg 
                   shadow-lg hover:shadow-xl dark:shadow-none dark:hover:shadow-[0_0_15px_rgba(255,255,255,0.15)]
                   border-y border-l border-gray-200 dark:border-gray-700 
                   text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white 
                   transition-all duration-500 ease-[cubic-bezier(0.175,0.885,0.32,1.275)]
                   hover:scale-110 hover:-translate-x-2
                   hidden" 
            aria-label="打开目录" 
            aria-haspopup="dialog" 
            aria-controls="drawer-toc" 
            aria-expanded="false">
        <i class="ph ph-list-bullets text-2xl" aria-hidden="true"></i>
    </button>

    <!-- 
        3. 滑动型目录抽屉 (Sliding Drawer TOC)
    -->
    <div id="toc-overlay" class="fixed inset-0 bg-black/20 dark:bg-black/50 backdrop-blur-sm z-[60] hidden transition-opacity opacity-0" aria-hidden="true"></div>
    
    <aside id="drawer-toc" 
           class="zen-toc-drawer fixed top-0 right-0 w-80 h-full z-[70] transform translate-x-full transition-transform duration-300 shadow-2xl flex flex-col" 
           style="width: min(20rem, 100vw); height: 100vh;"
           role="dialog" 
           aria-modal="true" 
           aria-labelledby="drawer-toc-title"
           inert>
        
        <div class="zen-toc-drawer-header flex items-center justify-between p-6">
            <h3 id="drawer-toc-title" class="text-sm font-bold uppercase tracking-widest text-gray-900 dark:text-white">目录</h3>
            <button id="drawer-toc-close" type="button" class="zen-icon-btn text-gray-500 hover:text-gray-900 dark:hover:text-white p-2 rounded-md hover:bg-gray-200 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500" aria-label="关闭目录">
                <i class="ph ph-x text-xl" aria-hidden="true"></i>
            </button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 custom-scrollbar">
            <nav id="drawer-toc-nav" class="relative border-l-2 border-gray-100 dark:border-gray-800 space-y-0" aria-label="移动端目录导航">
                <!-- JS 填充内容 -->
            </nav>
        </div>
    </aside>

    <?php endif; ?>

    <header class="mb-10 text-center">
        <div class="text-xs font-medium uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-4 flex items-center justify-center gap-2">
            <?php 
            $cat = zen_get_primary_category();
            if ($cat) {
                echo '<a href="' . esc_url(get_category_link($cat->term_id)) . '" class="zen-ui-link bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700">' . esc_html($cat->name) . '</a>';
                echo '<span class="text-gray-300 dark:text-gray-600">/</span>';
            }
            ?>
            <?php zen_posted_on_link(); ?>

            <?php if (zen_get_option('zen_show_reading_count')) : ?>
                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">/</span>
                <span class="flex items-center gap-1">
                    <i class="ph ph-eye text-sm" aria-hidden="true"></i>
                    <?php echo esc_html(number_format_i18n(zen_get_reading_count())); ?> 阅读
                </span>
            <?php endif; ?>

            <?php if (zen_get_option('zen_show_reading_time')) : ?>
                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">/</span>
                <span class="flex items-center gap-1">
                    <i class="ph ph-clock text-sm" aria-hidden="true"></i>
                    阅读时间 <?php echo esc_html(zen_get_reading_time()); ?> 分钟
                </span>
            <?php endif; ?>

        </div>
        <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-gray-900 dark:text-white mb-6 leading-tight serif">
            <?php the_title(); ?>
        </h1>
    </header>

    <article id="post-content" class="prose prose-lg prose-zinc dark:prose-invert mx-auto focus:outline-none entry-content">
        <?php the_content(); ?>
    </article>

    <?php if (zen_get_option('zen_show_updated_date')) : ?>
    <div class="mt-12 pt-7 border-t border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-4 text-sm text-gray-600 dark:text-gray-400" aria-label="文章信息">
        <span class="flex items-center gap-1">
            <i class="ph ph-clock-counter-clockwise text-sm" aria-hidden="true"></i>
            最后更新：<?php echo esc_html(get_the_modified_date()); ?>
        </span>
    </div>
    <?php endif; ?>

        <?php if (zen_get_option('zen_show_copyright') && zen_get_option('zen_copyright_license') !== 'none') : ?>
        <?php
        $zen_license = zen_get_option('zen_copyright_license');
        $zen_licenses = array(
            'all-rights-reserved' => array('保留所有权利', ''),
            'cc-by-4.0'          => array('CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/deed.zh-hans'),
            'cc-by-sa-4.0'       => array('CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0/deed.zh-hans'),
            'cc-by-nc-sa-4.0'    => array('CC BY-NC-SA 4.0', 'https://creativecommons.org/licenses/by-nc-sa/4.0/deed.zh-hans'),
        );
        $zen_license_info = isset($zen_licenses[$zen_license]) ? $zen_licenses[$zen_license] : $zen_licenses['cc-by-nc-sa-4.0'];
        ?>
        <aside class="mt-12 pt-7 border-t border-gray-100 dark:border-gray-800 text-sm text-gray-600 dark:text-gray-400 leading-relaxed" aria-label="文章版权信息">
            <div>本文作者：<?php echo esc_html(get_the_author()); ?></div>
            <div>本文链接：<a href="<?php echo esc_url(get_permalink()); ?>" class="zen-ui-link break-words hover:text-gray-900 dark:hover:text-white" style="overflow-wrap: anywhere;"><?php echo esc_html(get_permalink()); ?></a></div>
            <div>版权声明：除特别声明外，本站文章<?php if ($zen_license_info[1]) : ?>采用 <a href="<?php echo esc_url($zen_license_info[1]); ?>" target="_blank" rel="license noopener noreferrer" class="zen-ui-link hover:text-gray-900 dark:hover:text-white"><?php echo esc_html($zen_license_info[0]); ?></a> 许可协议。<?php else : ?><?php echo esc_html($zen_license_info[0]); ?>。<?php endif; ?></div>
        </aside>
        <?php endif; ?>

        <?php if (zen_get_option('zen_show_tags')) : ?>
        <div class="zen-post-taxonomy mt-12 pt-7 border-t border-gray-100 dark:border-gray-800">
            <div class="zen-taxonomy-row">
                <span class="zen-meta-label">
                    <i class="ph ph-tag" aria-hidden="true"></i>
                    标签
                </span>
                <div class="zen-tag-list">
                    <?php
                    $tags = get_the_tags();
                    if ($tags) {
                        foreach ($tags as $tag) {
                            echo '<a href="' . esc_url(get_tag_link($tag->term_id)) . '" class="zen-tag-pill">#' . esc_html($tag->name) . '</a>';
                        }
                    } else {
                        echo '<span class="zen-tag-empty">暂无标签</span>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (zen_get_option('zen_show_post_navigation')) : ?>
        <nav class="mt-12 pt-7 border-t border-gray-100 dark:border-gray-800 flex flex-col gap-3 sm:flex-row sm:justify-between text-sm" aria-label="文章导航">
            <div class="min-w-0 flex-1">
                <?php
                $previous_post = get_previous_post();
                if ($previous_post) {
                    echo '<a href="' . esc_url(get_permalink($previous_post)) . '" class="zen-ui-link block break-words hover:text-gray-900 dark:hover:text-white" style="overflow-wrap: anywhere;">上一篇：' . esc_html(get_the_title($previous_post)) . '</a>';
                }
                ?>
            </div>
            <div class="min-w-0 flex-1" style="text-align: right;">
                <?php
                $next_post = get_next_post();
                if ($next_post) {
                    echo '<a href="' . esc_url(get_permalink($next_post)) . '" class="zen-ui-link block break-words hover:text-gray-900 dark:hover:text-white" style="overflow-wrap: anywhere;">下一篇：' . esc_html(get_the_title($next_post)) . '</a>';
                }
                ?>
            </div>
        </nav>
        <?php endif; ?>

    <?php 
    if (comments_open() || get_comments_number()) :
        comments_template();
    endif;
    ?>

<?php endwhile; endif; ?>

<?php get_footer(); ?>
