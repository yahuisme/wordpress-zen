<?php get_header(); ?>

<!-- 归档页头部 -->
<header class="mb-12 text-center animate-fade-in">
    <div class="text-sm font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">
        <?php 
        if (is_category()) { echo '分类专栏'; }
        elseif (is_tag()) { echo '话题标签'; }
        elseif (is_author()) { echo '作者专栏'; }
        elseif (is_date()) { echo '历史足迹'; }
        else { echo '归档'; }
        ?>
    </div>
    
    <h1 class="text-3xl md:text-4xl font-bold mb-4 serif text-gray-900 dark:text-white">
        <?php 
        if (is_category()) {
            single_cat_title();
        } elseif (is_tag()) {
            single_tag_title();
        } elseif (is_author()) {
            echo esc_html(get_the_author());
        } elseif (is_day()) {
            echo esc_html(get_the_date());
        } elseif (is_month()) {
            echo esc_html(get_the_date('Y年 n月'));
        } elseif (is_year()) {
            echo esc_html(get_the_date('Y年'));
        } else {
            the_archive_title('', false); 
        }
        ?>
    </h1>

    <?php if (get_the_archive_description()) : ?>
        <div class="text-gray-600 dark:text-gray-400 max-w-2xl mx-auto mt-4 prose dark:prose-invert">
            <?php the_archive_description(); ?>
        </div>
    <?php endif; ?>
</header>

<div class="space-y-12 max-w-zen mx-auto animate-fade-in">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php get_template_part('template-parts/content', 'excerpt'); ?>

    <?php endwhile; ?>
        
        <!-- 分页导航 -->
        <?php zen_pagination(); ?>

    <?php else : ?>
        <div class="text-center py-20">
            <h2 class="text-xl font-bold mb-2">此处空空如也</h2>
            <p class="text-gray-500">该专栏或话题下暂时没有文章。</p>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="inline-block mt-4 text-blue-600 hover:underline">返回首页</a>
        </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
