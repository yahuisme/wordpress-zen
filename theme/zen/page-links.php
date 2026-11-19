<?php
/*
Template Name: Links Template
*/
get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

    <header class="zen-article-header">
        <h1 class="zen-article-title"><?php the_title(); ?></h1>
    </header>
    <div class="zen-reading-shell">
        <div class="zen-directory-intro entry-content">
            <?php the_content(); ?>
        </div>
        <?php wp_link_pages(array(
            'before' => '<nav class="zen-page-links" aria-label="' . esc_attr__('正文分页', 'zen') . '">',
            'after' => '</nav>',
        )); ?>
    </div>

    <?php if (post_password_required()) continue; ?>

    <div class="zen-links-directory zen-reading-shell">
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
                $allowed_targets = array('', '_blank', '_self', '_parent', '_top');
                $link_target = in_array($bookmark->link_target, $allowed_targets, true) ? $bookmark->link_target : '_blank';
                if ('' === $link_target) {
                    $link_target = '_self';
                }
                $rel_tokens = preg_split('/\s+/', strtolower((string) ($bookmark->link_rel ?? '')), -1, PREG_SPLIT_NO_EMPTY);
                $rel_tokens = array_filter($rel_tokens, function ($token) {
                    return 'opener' !== $token && preg_match('/^[a-z][a-z0-9_-]*$/D', $token);
                });
                $link_rel = implode(' ', array_unique(array_merge($rel_tokens, array('noopener', 'noreferrer'))));
                $fallback_initial = function_exists('mb_substr') ? mb_substr($bookmark->link_name, 0, 1) : substr($bookmark->link_name, 0, 1);
                ?>
                <a href="<?php echo esc_url($bookmark->link_url); ?>" target="<?php echo esc_attr($link_target); ?>" rel="<?php echo esc_attr($link_rel); ?>" class="zen-link-card"<?php if ($link_target === '_blank') : ?> aria-label="<?php echo esc_attr($bookmark->link_name . ' (在新窗口打开)'); ?>"<?php endif; ?>>
                    <?php if ($bookmark->link_image) : ?>
                        <img src="<?php echo esc_url($bookmark->link_image); ?>" alt="" width="40" height="40" loading="lazy" decoding="async" class="zen-link-avatar">
                    <?php else : ?>
                        <div class="zen-link-avatar font-serif" aria-hidden="true">
                            <?php echo esc_html($fallback_initial); ?>
                        </div>
                    <?php endif; ?>

                    <div class="min-w-0">
                        <h2>
                            <?php echo esc_html($bookmark->link_name); ?>
                        </h2>
                        <?php if ($bookmark->link_description !== '') : ?>
                            <p class="text-sm"><?php echo esc_html($bookmark->link_description); ?></p>
                        <?php endif; ?>
                    </div>

                </a>
                <?php
            }
        } else {
            echo '<p class="zen-empty-state">' . esc_html__('暂未添加友情链接。', 'zen') . '</p>';
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
