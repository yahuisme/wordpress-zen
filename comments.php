<?php
if (post_password_required()) return;
?>

<section id="comments" class="mt-24 max-w-zen mx-auto scroll-mt-24">
    <div class="pt-12 border-t border-gray-100 dark:border-gray-800">
        <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="mb-3 text-[10px] font-bold uppercase tracking-[0.24em] text-gray-500 dark:text-gray-400">讨论</p>
                <h2 class="serif text-3xl md:text-4xl font-bold leading-tight text-gray-900 dark:text-white">
                    <?php
                    $comments_number = get_comments_number();
                    if ($comments_number === 0) {
                        echo '评论';
                    } else {
                        echo esc_html(sprintf('%d 条评论', $comments_number));
                    }
                    ?>
                </h2>
            </div>
            <?php if (comments_open()) : ?>
                <a href="#respond" class="zen-action-link text-sm dark:text-white">写评论 <i class="ph ph-arrow-down" aria-hidden="true"></i></a>
            <?php endif; ?>
        </header>

        <?php if (comments_open()) : ?>
            <?php
            $commenter = wp_get_current_commenter();
            $req = get_option('require_name_email');
            $aria_req = ($req ? " aria-required='true'" : '');
            $field_required = $req ? '<span class="text-red-500" aria-hidden="true">*</span>' : '';
            $comment_required = '<span class="text-red-500" aria-hidden="true">*</span>';

            $fields = array(
                'author' => '<p class="comment-form-author">' .
                    '<label for="author">称呼 ' . $field_required . '</label>' .
                    '<input id="author" name="author" type="text" value="' . esc_attr($commenter['comment_author']) . '" ' . $aria_req . ' class="zen-comment-input px-4 py-3" autocomplete="name" placeholder="怎么称呼你" />' .
                    '</p>',

                'email' => '<p class="comment-form-email">' .
                    '<label for="email">邮箱 ' . $field_required . '</label>' .
                    '<input id="email" name="email" type="email" value="' . esc_attr($commenter['comment_author_email']) . '" ' . $aria_req . ' class="zen-comment-input px-4 py-3" autocomplete="email" placeholder="邮箱不会公开" />' .
                    '</p>',

                'cookies' => '',
            );

            $args = array(
                'fields' => $fields,
                'comment_field' => '<p class="comment-form-comment"><label for="comment">你的想法 ' . $comment_required . '</label><textarea id="comment" name="comment" rows="2" class="zen-comment-input resize-y px-4 py-3" aria-required="true" placeholder="写点什么..."></textarea></p>',
                'class_container' => 'comment-respond zen-comment-panel p-5 sm:p-7 mb-12',
                'class_submit' => 'zen-submit',
                'label_submit' => '发布评论',
                'submit_button' => '<button name="%1$s" type="submit" id="%2$s" class="%3$s"><span>%4$s</span><i class="ph ph-paper-plane-tilt" aria-hidden="true"></i></button>',
                'title_reply' => '写下评论',
                'title_reply_before' => '<h3 id="reply-title" class="comment-reply-title serif text-xl font-bold text-gray-900 dark:text-white">',
                'title_reply_after' => '</h3>',
                'title_reply_to' => '回复给 %s',
                'cancel_reply_link' => '取消回复',
                'comment_notes_before' => '',
                'comment_notes_after' => '',
                'logged_in_as' => '<p class="logged-in-as text-sm text-gray-600 dark:text-gray-400">已登录为 <a href="' . esc_url(admin_url('profile.php')) . '" class="zen-ui-link font-medium text-gray-900 dark:text-white underline underline-offset-4">' . esc_html(wp_get_current_user()->display_name) . '</a>。 <a href="' . esc_url(wp_logout_url(get_permalink())) . '" class="zen-ui-link text-gray-500 hover:text-red-600 dark:hover:text-red-400">注销</a></p>',
                'class_form' => 'comment-form mt-5',
                'submit_field' => '<p class="form-submit">%1$s %2$s</p>',
            );

            comment_form($args);
            ?>
        <?php endif; ?>

        <div class="comment-list-wrapper">
            <?php if (have_comments()) : ?>
                <ol class="comment-list">
                    <?php
                    wp_list_comments(array(
                        'style'       => 'ol',
                        'short_ping'  => true,
                        'avatar_size' => 48,
                        'callback'    => 'zen_comment_callback',
                    ));
                    ?>
                </ol>

                <?php if (get_comment_pages_count() > 1 && get_option('page_comments')) : ?>
                    <nav class="comment-pagination mt-10 flex justify-between gap-4 border-t border-gray-100 dark:border-gray-800 pt-6" aria-label="评论分页">
                        <div class="nav-previous"><?php previous_comments_link('&larr; 较早的评论'); ?></div>
                        <div class="nav-next"><?php next_comments_link('较新的评论 &rarr;'); ?></div>
                    </nav>
                <?php endif; ?>
            <?php elseif (!comments_open()) : ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 py-6">评论已关闭。</p>
            <?php endif; ?>
        </div>
    </div>
</section>
