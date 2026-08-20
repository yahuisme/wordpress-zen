<?php get_header(); ?>

<div class="text-center py-20">
    <div class="text-9xl font-bold text-gray-100 dark:text-gray-800 mb-4 font-sans">404</div>
    <h2 class="text-2xl font-bold mb-4 serif">迷路了？</h2>
    <p class="text-gray-500 mb-8">这一页的内容似乎已经消失在数字的海洋中。</p>
    <a href="<?php echo esc_url(home_url('/')); ?>" class="inline-block px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-full hover:bg-gray-900 hover:text-white dark:hover:bg-white dark:hover:text-black transition-colors">
        返回首页
    </a>
</div>

<?php get_footer(); ?>
