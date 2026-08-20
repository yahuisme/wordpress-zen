<?php
/*
Template Name: Links Template
*/
get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

    <header class="mb-12 text-center">
        <h1 class="text-3xl font-bold mb-6 serif"><?php the_title(); ?></h1>
        <!-- 页面简介 (支持后台编辑内容，如申请友链规则) -->
        <div class="text-gray-500 dark:text-gray-400 max-w-lg mx-auto prose dark:prose-invert">
            <?php the_content(); ?>
        </div>
    </header>

    <!-- 友链网格 (增加 mb-20 与评论区拉开距离) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-zen mx-auto mb-20">
        <?php
        // 获取所有链接分类
        $bookmarks = get_bookmarks(array(
            'orderby' => 'name',
            'order'   => 'ASC'
        ));

        if ($bookmarks) {
            foreach ($bookmarks as $bookmark) {
                ?>
                <?php
                $link_target = $bookmark->link_target ? $bookmark->link_target : '_blank';
                $fallback_initial = function_exists('mb_substr') ? mb_substr($bookmark->link_name, 0, 1) : substr($bookmark->link_name, 0, 1);
                ?>
                <a href="<?php echo esc_url($bookmark->link_url); ?>" target="<?php echo esc_attr($link_target); ?>" rel="noopener noreferrer" class="zen-link-card group flex items-center p-4 rounded-lg transition-all">
                    <?php if ($bookmark->link_image) : ?>
                        <img src="<?php echo esc_url($bookmark->link_image); ?>" alt="<?php echo esc_attr($bookmark->link_name); ?>" class="w-14 h-14 rounded-full object-cover mr-4 grayscale group-hover:grayscale-0 transition-all duration-300">
                    <?php else : ?>
                        <div class="zen-link-avatar w-14 h-14 rounded-full flex items-center justify-center text-xl font-serif mr-4">
                            <?php echo esc_html($fallback_initial); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="flex-grow min-w-0">
                        <h3 class="font-bold text-gray-900 dark:text-white truncate group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                            <?php echo esc_html($bookmark->link_name); ?>
                        </h3>
                        <p class="text-sm text-gray-500 truncate"><?php echo esc_html($bookmark->link_description); ?></p>
                    </div>
                    <i class="ph ph-arrow-up-right text-gray-300 group-hover:text-gray-600 dark:text-gray-600 dark:group-hover:text-gray-300 transition-colors"></i>
                </a>
                <?php
            }
        } else {
            echo '<p class="text-center w-full text-gray-500 col-span-2 py-8">暂无友链，请在后台添加。</p>';
        }
        ?>
    </div>

    <!-- 评论区 (新增) -->
    <?php 
    // 只有在后台页面设置中开启了"允许评论"，或者已经有评论时才显示
    if ( comments_open() || get_comments_number() ) :
        comments_template();
    endif;
    ?>

<?php endwhile; endif; ?>

<?php get_footer(); ?>
