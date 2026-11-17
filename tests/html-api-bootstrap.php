<?php
/** Load the real Core HTML tokenizer for template boundary tests, without a database. */
$core = getenv('ZEN_WP_CORE') ?: '/opt/test-tools/wordpress/clean-core';
$api = rtrim($core, '/') . '/wp-includes/html-api/';
if (!is_file($api . 'class-wp-html-tag-processor.php')) {
    throw new RuntimeException('Set ZEN_WP_CORE to isolated WordPress Core for HTML tokenizer tests.');
}
foreach (array('class-wp-html-span.php', 'class-wp-html-attribute-token.php', 'class-wp-html-text-replacement.php', 'class-wp-html-tag-processor.php') as $file) {
    require_once $api . $file;
}
