<?php
/** ZEN_TEST_ISOLATED=1 php tests/native-comment-form-regression.php /fixture/wp-load.php */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$complete = false;
register_shutdown_function(function () use (&$complete) { if (!$complete) { fwrite(STDERR, "NATIVE_COMMENT_FORM_INCOMPLETE\n"); exit(1); } });
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') throw new RuntimeException('Isolated local Zen required');
if (hash_file('sha256', get_template_directory() . '/comments.php') !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/comments.php')) throw new RuntimeException('Comment source mismatch');
$old = array();
foreach (array('show_comments_cookies_opt_in', 'require_name_email', 'comment_registration', 'thread_comments', 'thread_comments_depth') as $option) {
    $old[$option] = get_option($option, null);
}
$old_user = get_current_user_id();
$old_cookies = $_COOKIE;
$old_get = $_GET;
$id = 0; $checks = $failures = 0;
add_filter('pre_wp_mail', '__return_false');
function comment_form_check($ok, $label) {
    global $checks, $failures;
    $checks++; $failures += !$ok;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function comment_form_render($core = false) {
    ob_start();
    if ($core) comment_form(); else require get_template_directory() . '/comments.php';
    return ob_get_clean();
}
function comment_form_dom($html) {
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    return new DOMXPath($doc);
}
try {
    $id = wp_insert_post(array('post_type'=>'post','post_status'=>'publish','post_title'=>'Comment form fixture','comment_status'=>'open'), true);
    if (!$id || is_wp_error($id)) throw new RuntimeException('Fixture insert failed');
    $GLOBALS['wp_query'] = new WP_Query(array('p'=>$id));
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post($id); setup_postdata($GLOBALS['post']);
    wp_set_current_user(0); $_COOKIE = array();
    update_option('comment_registration', 0);
    update_option('require_name_email', 1);
    update_option('show_comments_cookies_opt_in', 1);
    $core = comment_form_dom(comment_form_render(true));
    $theme = comment_form_dom(comment_form_render());
    comment_form_check($core->query('//input[@name="wp-comment-cookies-consent"]')->length === 1, 'Core enabled option supplies native consent field');
    comment_form_check($theme->query('//input[@name="wp-comment-cookies-consent"]')->length === 1, 'Zen preserves Core enabled consent field');
    comment_form_check($theme->query('//input[@name="wp-comment-cookies-consent" and @checked]')->length === 0, 'fresh anonymous visitor is not opted in');
    comment_form_check($theme->query('//input[@name="url"]')->length === 0, 'theme retains existing no-website field contract');
    $email = $theme->query('//input[@id="email"]')->item(0);
    $notice = $email ? $theme->query('//*[@id="' . $email->getAttribute('aria-describedby') . '"]')->item(0) : null;
    comment_form_check($notice && str_contains($notice->textContent, '邮箱不会公开'), 'email privacy notice stays visible after typing and describes the input');
    foreach (array('author' => '245', 'email' => '100', 'comment' => '65525') as $field => $limit) {
        comment_form_check($core->query('//*[@id="' . $field . '"]/@maxlength')->item(0)?->nodeValue === $limit, "Core supplies $field limit");
        comment_form_check($theme->query('//*[@id="' . $field . '" and @maxlength="' . $limit . '"]')->length === 1, "Zen carries Core's $field limit");
    }
    $plugin_field = static function ($fields) {
        $fields['fixture'] = '<p><label>Fixture <input name="fixture"></label></p>';
        return $fields;
    };
    add_filter('comment_form_fields', $plugin_field);
    try {
        comment_form_check(comment_form_dom(comment_form_render())->query('//input[@name="fixture"]')->length === 1, 'native final-field integrations reach the Zen form');
    } finally { remove_filter('comment_form_fields', $plugin_field); }
    $_COOKIE['comment_author_' . COOKIEHASH] = 'Remembered reader';
    $_COOKIE['comment_author_email_' . COOKIEHASH] = 'reader@example.test';
    $theme = comment_form_dom(comment_form_render());
    comment_form_check($theme->query('//input[@name="wp-comment-cookies-consent" and @checked]')->length === 1, 'Core marks remembered commenter consent');
    comment_form_check($theme->query('//input[@name="author" and @value="Remembered reader"]')->length === 1, 'native commenter cookie prefills author');
    update_option('show_comments_cookies_opt_in', 0);
    comment_form_check(comment_form_dom(comment_form_render())->query('//input[@name="wp-comment-cookies-consent"]')->length === 0, 'disabled discussion option omits native consent field');
    update_option('show_comments_cookies_opt_in', 1);
    update_option('require_name_email', 0);
    $theme = comment_form_dom(comment_form_render());
    comment_form_check($theme->query('//*[@id="author" and @required] | //*[@id="email" and @required]')->length === 0, 'optional name and email follow Discussion settings');
    comment_form_check($theme->query('//textarea[@name="comment" and @required]')->length === 1, 'comment body always required');
    update_option('comment_registration', 1);
    $theme = comment_form_dom(comment_form_render());
    comment_form_check($theme->query('//*[@id="commentform"]')->length === 0 && $theme->query('//*[@id="respond"]//a[contains(@href, "wp-login.php")]')->length === 1, 'login-only discussions keep the native login path');
    $admins = get_users(array('role'=>'administrator','number'=>1));
    if (!$admins) throw new RuntimeException('Fixture administrator required');
    wp_set_current_user($admins[0]->ID);
    comment_form_check(comment_form_dom(comment_form_render())->query('//input[@name="wp-comment-cookies-consent"]')->length === 0, 'logged-in Core form does not ask for commenter cookie consent');

    wp_set_current_user(0);
    update_option('comment_registration', 0);
    update_option('thread_comments', 1);
    update_option('thread_comments_depth', 5);
    $comment_id = wp_insert_comment(array('comment_post_ID' => $id, 'comment_approved' => 1, 'comment_author' => 'Fixture reader', 'comment_content' => 'Existing discussion.'));
    if (!$comment_id) throw new RuntimeException('Fixture comment insert failed');
    $GLOBALS['wp_query']->comments = array(get_comment($comment_id));
    $GLOBALS['wp_query']->comment_count = 1;
    $theme = comment_form_dom(comment_form_render());
    $reply = $theme->query('//a[@data-commentid="' . $comment_id . '"]')->item(0);
    comment_form_check($reply && $reply->getAttribute('aria-label') === '回复给 Fixture reader' && $reply->getAttribute('data-replyto') === '回复给 Fixture reader', 'Core reply link and script data both identify the reader in the theme language');
    comment_form_check($reply && str_contains($reply->getAttribute('href'), 'replytocom=' . $comment_id . '#respond'), 'native reply link retains its no-script destination');
    $_GET['replytocom'] = (string) $comment_id;
    $theme = comment_form_dom(comment_form_render());
    comment_form_check($theme->query('//input[@name="comment_parent" and @value="' . $comment_id . '"]')->length === 1, 'server-rendered no-script reply sets the Core parent field');
    comment_form_check($theme->query('//a[@id="cancel-comment-reply-link" and not(contains(@href, "replytocom"))]')->length === 1, 'native cancellation leaves the no-script reply state');
    $reply_comment = wp_handle_comment_submission(array(
        'comment_post_ID' => (int) $theme->query('//input[@name="comment_post_ID"]')->item(0)->getAttribute('value'),
        'comment_parent' => (int) $theme->query('//input[@name="comment_parent"]')->item(0)->getAttribute('value'),
        'author' => 'Visitor fixture',
        'email' => 'visitor@example.test',
        'comment' => 'A native reply from the fixture.',
    ));
    if (is_wp_error($reply_comment)) throw new RuntimeException('Native comment submission failed: ' . $reply_comment->get_error_code());
    $persisted = $reply_comment instanceof WP_Comment ? get_comment($reply_comment->comment_ID) : null;
    comment_form_check($persisted && (int) $persisted->comment_parent === (int) $comment_id && (int) $persisted->user_id === 0 && $persisted->comment_content === 'A native reply from the fixture.', 'Core stores an anonymous reply submitted with the rendered control values');
    unset($_GET['replytocom']);
    wp_update_post(array('ID' => $id, 'comment_status' => 'closed'));
    $GLOBALS['post'] = get_post($id);
    $closed = comment_form_render();
    comment_form_check(str_contains($closed, 'Existing discussion.') && str_contains($closed, '评论已关闭。') && !str_contains($closed, 'id="commentform"'), 'closed discussion explains why the form is absent without hiding existing comments');
} finally {
    remove_filter('pre_wp_mail', '__return_false');
    wp_set_current_user($old_user); $_COOKIE = $old_cookies; $_GET = $old_get;
    foreach ($old as $option => $value) {
        if ($value === null) delete_option($option); else update_option($option, $value);
    }
    if ($id && !is_wp_error($id)) wp_delete_post($id, true);
    wp_reset_postdata();
}
$complete = true;
printf("NATIVE_COMMENT_FORM_COMPLETE %d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
