<?php get_header(); ?>

<!-- 搜索结果头部 -->
<header class="mb-12 text-center animate-fade-in">
    <div class="text-sm font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">
        搜索结果
    </div>
    
    <h1 class="text-3xl md:text-4xl font-bold mb-4 serif text-gray-900 dark:text-white break-words">
        “<?php echo esc_html(get_search_query(false)); ?>”
    </h1>

    <div class="text-gray-600 dark:text-gray-400 max-w-2xl mx-auto mt-4 prose dark:prose-invert">
        <?php 
        global $wp_query;
        $count = $wp_query->found_posts;
        echo esc_html(sprintf('共找到 %d 篇相关文章', $count));
        ?>
    </div>
</header>

<div class="space-y-12 max-w-zen mx-auto animate-fade-in">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php get_template_part('template-parts/content', 'excerpt', array('highlight_title' => true)); ?>

    <?php endwhile; ?>
        
        <!-- 分页导航 -->
        <?php zen_pagination(); ?>

    <?php else : ?>
        <div class="text-center py-20 bg-gray-50 dark:bg-[#1a1a1a] rounded-lg border border-dashed border-gray-200 dark:border-gray-800">
            <div class="mb-4 text-gray-400">
                <i class="ph ph-magnifying-glass text-4xl"></i>
            </div>
            <h2 class="text-xl font-bold mb-2 text-gray-900 dark:text-white">未找到相关内容</h2>
            <p class="text-gray-500 dark:text-gray-400 mb-6">尝试更换关键词，或者查看归档页面。</p>
            
            <?php 
            // 自动查找归档页面链接
            $archive_pages = get_pages(array(
                'meta_key' => '_wp_page_template',
                'meta_value' => 'page-archives.php',
                'number' => 1
            ));
            // 如果找到，使用该页面链接；否则回退到首页
            $archive_url = (!empty($archive_pages)) ? get_permalink($archive_pages[0]->ID) : home_url('/');
            ?>
            
            <a href="<?php echo esc_url($archive_url); ?>" class="inline-block px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-full hover:bg-gray-900 hover:text-white dark:hover:bg-white dark:hover:text-black transition-colors text-sm font-medium">
                浏览归档
            </a>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
