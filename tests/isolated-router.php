<?php
/**
 * Preserve static assets when serving an isolated WordPress fixture with PHP's
 * development server; route only non-file requests through WordPress.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ('/' !== $path && is_file($file)) {
    return false;
}
if (!isset($_SERVER['REQUEST_METHOD'])) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}
if (!defined('WP_USE_THEMES')) {
    define('WP_USE_THEMES', true);
}
require __DIR__ . '/wp-blog-header.php';
