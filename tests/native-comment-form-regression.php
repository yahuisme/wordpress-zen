<?php
/** ZEN_TEST_ISOLATED=1 php tests/native-comment-form-regression.php /fixture/wp-load.php */
if (getenv('ZEN_TEST_ISOLATED') !== '1' || empty($argv[1])) exit(2);
$complete = false;
register_shutdown_function(function () use (&$complete) { if (!$complete) { fwrite(STDERR, "NATIVE_COMMENT_FORM_INCOMPLETE\n"); exit(1); } });
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1];
if (wp_get_environment_type() !== 'local' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1' || get_template() !== 'zen') throw new RuntimeException('Isolated local Zen required');
if (hash_file('sha256', get_template_directory() . '/comments.php') !== hash_file('sha256', dirname(__DIR__) . '/theme/zen/comments.php')) throw new RuntimeException('Comment source mismatch');
$old = get_option('show_comments_cookies_opt_in', null);
$old_user = get_current_user_id();
$old_cookies = $_COOKIE;
$id = 0; $checks = $failures = 0;
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
    update_option('show_comments_cookies_opt_in', 1);
    $core = comment_form_dom(comment_form_render(true));
    $theme = comment_form_dom(comment_form_render());
    comment_form_check($core->query('//input[@name="wp-comment-cookies-consent"]')->length === 1, 'Core enabled option supplies native consent field');
    comment_form_check($theme->query('//input[@name="wp-comment-cookies-consent"]')->length === 1, 'Zen preserves Core enabled consent field');
    comment_form_check($theme->query('//input[@name="wp-comment-cookies-consent" and @checked]')->length === 0, 'fresh anonymous visitor is not opted in');
    comment_form_check($theme->query('//input[@name="url"]')->length === 0, 'theme retains existing no-website field contract');
    $_COOKIE['comment_author_' . COOKIEHASH] = 'Remembered reader';
    $_COOKIE['comment_author_email_' . COOKIEHASH] = 'reader@example.test';
    $theme = comment_form_dom(comment_form_render());
    comment_form_check($theme->query('//input[@name="wp-comment-cookies-consent" and @checked]')->length === 1, 'Core marks remembered commenter consent');
    comment_form_check($theme->query('//input[@name="author" and @value="Remembered reader"]')->length === 1, 'native commenter cookie prefills author');
    update_option('show_comments_cookies_opt_in', 0);
    comment_form_check(comment_form_dom(comment_form_render())->query('//input[@name="wp-comment-cookies-consent"]')->length === 0, 'disabled discussion option omits native consent field');
    update_option('show_comments_cookies_opt_in', 1);
    $admins = get_users(array('role'=>'administrator','number'=>1));
    wp_set_current_user($admins[0]->ID);
    comment_form_check(comment_form_dom(comment_form_render())->query('//input[@name="wp-comment-cookies-consent"]')->length === 0, 'logged-in Core form does not ask for commenter cookie consent');
} finally {
    wp_set_current_user($old_user); $_COOKIE = $old_cookies;
    if ($old === null) delete_option('show_comments_cookies_opt_in'); else update_option('show_comments_cookies_opt_in', $old);
    if ($id && !is_wp_error($id)) wp_delete_post($id, true);
    wp_reset_postdata();
}
$complete = true;
printf("NATIVE_COMMENT_FORM_COMPLETE %d checks, %d failures\n", $checks, $failures);
exit($failures ? 1 : 0);
