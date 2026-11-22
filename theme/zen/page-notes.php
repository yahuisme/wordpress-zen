<?php
/*
Template Name: Notes Template
*/
get_header();
while (have_posts()) : the_post(); ?>
    <header class="zen-directory-heading zen-notes-heading zen-reading-shell">
        <p class="zen-directory-kicker"><?php esc_html_e('Guestbook', 'zen'); ?></p>
        <h1 class="zen-directory-title"><?php echo esc_html(zen_post_title()); ?></h1>
        <div class="zen-reading-shell">
            <div class="zen-directory-intro entry-content"><?php the_content(); ?></div>
        </div>
        <?php zen_content_page_links(); ?>
    </header>
    <?php if (!post_password_required()) : ?>
        <div class="zen-reading-shell zen-notes-shell">
            <?php if (comments_open() || get_comments_number()) : ?>
                <?php
                // Notes reveals older threads in place, independent of article comment pagination.
                $zen_disable_comment_paging = static function () { return 0; };
                add_filter('pre_option_page_comments', $zen_disable_comment_paging);
                try {
                    comments_template('/comments-notes.php');
                } finally {
                    remove_filter('pre_option_page_comments', $zen_disable_comment_paging);
                }
                ?>
            <?php else : ?>
                <p><?php esc_html_e('目前不接受留言。', 'zen'); ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endwhile;
get_footer();
