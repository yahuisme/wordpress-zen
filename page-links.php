<?php
/*
Template Name: Links Template
*/
get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

    <header class="mb-12 text-center">
        <h1 class="text-3xl font-bold mb-6 serif"><?php the_title(); ?></h1>
        <div class="text-gray-500 dark:text-gray-400 max-w-lg mx-auto prose dark:prose-invert">
            <?php the_content(); ?>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-zen mx-auto mb-20">
        <?php
        $bookmarks = get_bookmarks(array(
            'orderby' => 'name',
            'order'   => 'ASC',
        ));

        if ($bookmarks) {
            usort($bookmarks, function ($a, $b) {
                $order_a = isset($a->link_rating) ? (int) $a->link_rating : 0;
                $order_b = isset($b->link_rating) ? (int) $b->link_rating : 0;

                if ($order_a === $order_b) {
                    return strnatcasecmp($a->link_name, $b->link_name);
                }
                if ($order_a === 0) {
                    return 1;
                }
                if ($order_b === 0) {
                    return -1;
                }
                return $order_a <=> $order_b;
            });

            foreach ($bookmarks as $bookmark) {
                ?>
                <?php
                $allowed_targets = array('_blank', '_self', '_parent', '_top');
                $link_target = in_array($bookmark->link_target, $allowed_targets, true) ? $bookmark->link_target : '_blank';
                $fallback_initial = function_exists('mb_substr') ? mb_substr($bookmark->link_name, 0, 1) : substr($bookmark->link_name, 0, 1);
                ?>
                <a href="<?php echo esc_url($bookmark->link_url); ?>" target="<?php echo esc_attr($link_target); ?>" rel="noopener noreferrer" class="zen-link-card group flex items-center p-4 rounded-lg transition-all">
                    <?php if ($bookmark->link_image) : ?>
                        <img src="<?php echo esc_url($bookmark->link_image); ?>" alt="<?php echo esc_attr($bookmark->link_name); ?>" width="56" height="56" loading="lazy" decoding="async" class="w-14 h-14 rounded-full object-cover mr-4 grayscale group-hover:grayscale-0 transition-all duration-300">
                    <?php else : ?>
                        <div class="zen-link-avatar w-14 h-14 rounded-full flex items-center justify-center text-xl font-serif mr-4">
                            <?php echo esc_html($fallback_initial); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="flex-grow min-w-0">
                        <h3 class="font-bold text-gray-900 dark:text-white truncate transition-colors">
                            <?php echo esc_html($bookmark->link_name); ?>
                        </h3>
                        <p class="text-sm text-gray-500 truncate"><?php echo esc_html($bookmark->link_description); ?></p>
                    </div>
                    <i class="ph ph-arrow-up-right text-gray-300 group-hover:text-gray-600 dark:text-gray-600 dark:group-hover:text-gray-300 transition-colors" aria-hidden="true"></i>
                </a>
                <?php
            }
        } else {
            echo '<p class="text-center w-full text-gray-500 col-span-2 py-8">暂无友链，请在后台添加。</p>';
        }
        ?>
    </div>

    <?php
    if ( comments_open() || get_comments_number() ) :
        comments_template();
    endif;
    ?>

<?php endwhile; endif; ?>

<?php get_footer(); ?>
