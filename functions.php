<?php
if (!defined('ABSPATH')) exit;

$zen_includes = array(
    'inc/setup.php',
    'inc/enqueue.php',
    'inc/template-tags.php',
    'inc/schema.php',
    'inc/comments.php',
);

foreach ($zen_includes as $zen_include) {
    require_once get_template_directory() . '/' . $zen_include;
}
