<?php
if (post_password_required()) return;

$zen_guestbook = !empty($args['guestbook']);
$zen_render_comment_list = static function () use ($zen_guestbook) {
    $list_args = array('style' => 'ol', 'avatar_size' => 48, 'callback' => 'zen_comment_callback');
    if ($zen_guestbook) {
        $reply_depth = get_option('thread_comments') ? (int) get_option('thread_comments_depth') : 0;
        $list_args['reverse_top_level'] = true;
        // Keep saved replies inside each thread without enabling new replies past Core's limit.
        $list_args['max_depth'] = max(2, $reply_depth);
        if ($reply_depth < 2) {
            $list_args['callback'] = static function ($comment, $args, $depth) use ($reply_depth) {
                $args['max_depth'] = $reply_depth;
                zen_comment_callback($comment, $args, $depth);
            };
        }
    }
    ?>
    <ol class="comment-list"<?php if ($zen_guestbook) echo ' id="zen-notes-entries"'; ?>>
        <?php wp_list_comments($list_args); ?>
    </ol>
    <?php if ($zen_guestbook) zen_directory_more('zen-notes-entries', 20, __('查看更早留言', 'zen')); ?>
    <?php if (get_comment_pages_count() > 1 && get_option('page_comments')) : ?>
        <nav class="comment-pagination flex justify-between gap-4" aria-label="评论分页">
            <div class="nav-previous"><?php previous_comments_link('&larr; 较早的评论'); ?></div>
            <div class="nav-next"><?php next_comments_link('较新的评论 &rarr;'); ?></div>
        </nav>
    <?php endif;
};
?>

<section id="comments" class="zen-comments zen-reading-shell scroll-mt-24<?php echo $zen_guestbook ? ' zen-notes-board' : ''; ?>">
    <div class="zen-comments-inner">
        <?php if (!$zen_guestbook) : ?>
        <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="zen-comments-title">
                    <?php
                    $comments_number = get_comments_number();
                    if (0 === (int) $comments_number) {
                        echo '评论';
                    } else {
                        echo esc_html(sprintf('%d 条评论', $comments_number));
                    }
                    ?>
                </h2>
            </div>
            <?php if (comments_open()) : ?>
                <a href="#respond" class="zen-action-link text-sm">写评论 <i class="ph ph-arrow-down" aria-hidden="true"></i></a>
            <?php endif; ?>
        </header>

        <?php if (have_comments()) $zen_render_comment_list(); ?>
        <?php endif; ?>

        <?php if (comments_open()) : ?>
            <?php
            $commenter = wp_get_current_commenter();
            $req = get_option('require_name_email');
            $aria_req = ($req ? " required aria-required='true'" : '');
            $field_required = $req ? '<span class="text-red-500" aria-hidden="true">*</span>' : '';
            $comment_required = '<span class="text-red-500" aria-hidden="true">*</span>';

            $fields = array(
                'author' => '<p class="comment-form-author">' .
                    '<label for="author">称呼 ' . $field_required . '</label>' .
                    '<input id="author" name="author" type="text" value="' . esc_attr($commenter['comment_author']) . '" ' . $aria_req . ' class="zen-comment-input px-4 py-3" autocomplete="name" maxlength="245" placeholder="怎么称呼你" />' .
                    '</p>',

                'email' => '<p class="comment-form-email">' .
                    '<label for="email">邮箱 ' . $field_required . '</label>' .
                    '<input id="email" name="email" type="email" value="' . esc_attr($commenter['comment_author_email']) . '" ' . $aria_req . ' class="zen-comment-input px-4 py-3" autocomplete="email" maxlength="100" aria-describedby="zen-comment-email-help" />' .
                    '<small id="zen-comment-email-help">邮箱不会公开。</small>' .
                    '</p>',
            );

            $comment_label = $zen_guestbook ? __('留言内容', 'zen') : '你的想法';
            $form_args = array(
                'comment_field' => '<p class="comment-form-comment"><label for="comment">' . esc_html($comment_label) . ' ' . $comment_required . '</label><textarea id="comment" name="comment" rows="' . ($zen_guestbook ? '3' : '2') . '" class="zen-comment-input resize-y px-4 py-3" required aria-required="true" maxlength="65525" placeholder="写点什么..."></textarea></p>',
                'class_container' => 'comment-respond zen-comment-panel p-5 sm:p-7',
                'class_submit' => 'zen-submit',
                'label_submit' => $zen_guestbook ? __('提交留言', 'zen') : '发布评论',
                'submit_button' => '<button name="%1$s" type="submit" id="%2$s" class="%3$s"><span>%4$s</span><i class="ph ph-paper-plane-tilt" aria-hidden="true"></i></button>',
                'title_reply' => $zen_guestbook ? __('写留言', 'zen') : '写下评论',
                'title_reply_before' => '<' . ($zen_guestbook ? 'h2' : 'h3') . ' id="reply-title" class="comment-reply-title text-xl">',
                'title_reply_after' => $zen_guestbook ? '</h2>' : '</h3>',
                'title_reply_to' => '回复给 %s',
                'cancel_reply_link' => '取消回复',
                'comment_notes_before' => '',
                'comment_notes_after' => '',
                'logged_in_as' => '<p class="logged-in-as text-sm text-gray-600 dark:text-gray-400">已登录为 <a href="' . esc_url(admin_url('profile.php')) . '" class="zen-ui-link font-medium text-gray-900 dark:text-white underline underline-offset-4">' . esc_html(wp_get_current_user()->display_name) . '</a>。 <a href="' . esc_url(wp_logout_url(get_permalink())) . '" class="zen-ui-link text-gray-500 hover:text-red-600 dark:hover:text-red-400">注销</a></p>',
                'class_form' => 'comment-form mt-5',
                'submit_field' => '<p class="form-submit">%1$s %2$s</p>',
            );

            // Alter only Zen's own fields, leaving Core consent and plugin additions intact.
            $zen_default_fields = static function ($defaults) use ($fields) {
                unset($defaults['url']);
                return array_replace($defaults, $fields);
            };
            $zen_consent_label = static function ($field) {
                return str_replace(
                    __('Save my name, email, and website in this browser for the next time I comment.'),
                    esc_html__('在此浏览器中保存我的称呼和邮箱，以便下次使用。', 'zen'),
                    $field
                );
            };
            add_filter('comment_form_default_fields', $zen_default_fields, PHP_INT_MAX);
            add_filter('comment_form_field_cookies', $zen_consent_label);
            try {
                comment_form($form_args);
            } finally {
                remove_filter('comment_form_default_fields', $zen_default_fields, PHP_INT_MAX);
                remove_filter('comment_form_field_cookies', $zen_consent_label);
            }
            ?>
        <?php else : ?>
            <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo $zen_guestbook ? esc_html(__('目前不接受留言。', 'zen')) : '评论已关闭。'; ?></p>
        <?php endif; ?>

        <?php if ($zen_guestbook) : ?>
            <div class="zen-notes-stream">
                <h2 class="zen-comments-title"><?php esc_html_e('留言', 'zen'); ?></h2>
                <?php if (have_comments()) : ?>
                    <?php $zen_render_comment_list(); ?>
                <?php else : ?>
                    <p class="zen-notes-empty"><?php esc_html_e('还没有留言。', 'zen'); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
