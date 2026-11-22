<?php
/*
Template Name: Links Template
*/
get_header(); ?>

<?php while (have_posts()) : the_post(); ?>
    <header class="zen-directory-heading zen-links-heading">
        <p class="zen-directory-kicker" lang="en" aria-hidden="true">Links</p>
        <h1 class="zen-directory-title"><?php echo esc_html(zen_post_title()); ?></h1>
        <div class="zen-reading-shell">
            <div class="zen-directory-intro entry-content">
                <?php the_content(); ?>
            </div>
            <?php zen_content_page_links(); ?>
        </div>
    </header>

    <?php if (post_password_required()) continue; ?>

    <?php
    $bookmarks = get_bookmarks(array(
        'orderby' => 'name',
        'order' => 'ASC',
    ));
    if ($bookmarks) usort($bookmarks, 'zen_compare_link_order');
    $links = array();
    foreach ($bookmarks as $bookmark) {
        $link_url = esc_url($bookmark->link_url);
        if ($link_url !== '') $links[] = array($bookmark, $link_url);
    }
    if ($links) :
        ?>
        <ul id="zen-links-entries" class="zen-links-directory" role="list">
            <?php foreach ($links as [$bookmark, $link_url]) :
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
                $link_host = wp_parse_url($bookmark->link_url, PHP_URL_HOST);
                $link_image = esc_url($bookmark->link_image);
                ?>
                <li>
                    <a href="<?php echo $link_url; ?>" target="<?php echo esc_attr($link_target); ?>" rel="<?php echo esc_attr($link_rel); ?>" class="zen-link-card"<?php if ($link_target === '_blank') : ?> aria-label="<?php echo esc_attr(sprintf(__('%s (在新窗口打开)', 'zen'), $bookmark->link_name)); ?>"<?php endif; ?>>
                        <div class="zen-link-copy">
                            <div class="zen-link-label">
                                <?php if ($link_image) : ?>
                                    <img src="<?php echo $link_image; ?>" alt="" width="40" height="40" loading="lazy" decoding="async" class="zen-link-avatar">
                                <?php endif; ?>
                                <div>
                                    <h2><?php echo esc_html($bookmark->link_name); ?></h2>
                                    <?php if ($bookmark->link_description !== '') : ?>
                                        <p><?php echo esc_html($bookmark->link_description); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($link_host) : ?><bdi class="zen-link-host" aria-hidden="true"><?php echo esc_html($link_host); ?></bdi><?php endif; ?>
                        </div>
                        <svg class="zen-link-arrow" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">
                            <path d="M4 12l8-8M6 4h6v6" />
                        </svg>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        $links_batch_size = 20;
        if (count($links) > $links_batch_size) zen_directory_more('zen-links-entries', $links_batch_size, __('查看更多友链', 'zen'));
        ?>
    <?php else : ?>
        <p class="zen-empty-state"><?php esc_html_e('暂未添加友情链接。', 'zen'); ?></p>
    <?php endif; ?>

    <?php if (comments_open() || get_comments_number()) comments_template(); ?>
<?php endwhile; ?>

<?php get_footer(); ?>
