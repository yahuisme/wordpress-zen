<?php
/** Execute the real enqueue/setup callbacks with explicit WordPress boundaries. */
define('ABSPATH', dirname(__DIR__) . '/theme/zen/');
$GLOBALS['hooks'] = $GLOBALS['supports'] = $GLOBALS['styles'] = $GLOBALS['inline'] = array();
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function remove_action(...$args) {}
function zen_get_option($key) { return array('zen_content_width'=>900,'zen_reading_width'=>720)[$key] ?? 1; }
function add_theme_support($feature, ...$args) { $GLOBALS['supports'][$feature] = $args; }
function register_nav_menus(...$args) {}
function add_editor_style($styles) { $GLOBALS['editor_styles'] = (array) $styles; }
function wp_get_theme() { return new class { public function get($key) { return 'test'; } }; }
function get_template_directory_uri() { return 'https://example.test/zen'; }
function get_template_directory() { return ABSPATH; }
function is_singular() { return false; }
function zen_show_reading_count() { return false; }
function wp_enqueue_style($handle, ...$args) { $GLOBALS['styles'][$handle] = $args; }
function wp_enqueue_script(...$args) {}
function wp_script_is(...$args) { return false; }
function wp_localize_script(...$args) {}
function wp_add_inline_style($handle, $css) { $GLOBALS['inline'][$handle][] = $css; }
function __($s, $domain = '') { return $s; }
require ABSPATH . 'inc/setup.php';
require ABSPATH . 'inc/enqueue.php';
$failed = 0;
function verify($yes, $name) { global $failed; echo ($yes ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$yes) $failed++; }
zen_setup();
verify(isset($GLOBALS['supports']['editor-styles']), 'editor opts into theme typography');
verify(isset($GLOBALS['supports']['custom-logo']), 'native site logo supported');
verify(isset($GLOBALS['supports']['align-wide']), 'wide native media supported');
verify(is_file(ABSPATH . 'assets/css/reading.css') && is_file(ABSPATH . 'assets/css/layout.css'), 'maintained readable design layers exist');
verify(substr_count(file_get_contents(ABSPATH . 'assets/css/style.css'), "\n") > 100, 'compatibility stylesheet is readable without missing build source');
zen_scripts();
verify(isset($GLOBALS['styles']['zen-reading-style']), 'shared reading stylesheet enqueued');
$css = implode('', array_merge(...array_values($GLOBALS['inline'])));
verify(str_contains($css, '--zen-reading-width:720px'), 'reading width independent from site width');
verify(str_contains($css, '--zen-content-width:900px'), 'legacy site width preserved');
verify(!isset($GLOBALS['styles']['zen-directories-style']), 'ordinary pages do not enqueue the secondary-page stylesheet');
$template_hook = $GLOBALS['hooks']['template_include'][0] ?? null;
verify(is_callable($template_hook), 'secondary-page stylesheet follows the resolved template');
if (is_callable($template_hook)) {
    $default_hooks = $GLOBALS['hooks']['wp_enqueue_scripts'];
    foreach (array('index.php', 'page.php', 'single.php', 'archive.php', 'search.php', '404.php', 'page-archives.php', 'page-tags.php', 'page-links.php', 'page-notes.php') as $i => $file) {
        $GLOBALS['hooks']['wp_enqueue_scripts'] = $default_hooks;
        unset($GLOBALS['styles']['zen-directories-style']);
        $template = ABSPATH . $file;
        verify($template_hook($template) === $template, "$file keeps the native template selection");
        foreach ($GLOBALS['hooks']['wp_enqueue_scripts'] as $enqueue) $enqueue();
        verify(($GLOBALS['styles']['zen-directories-style'][1] ?? null) === ($i < 3 ? null : array('zen-layout-style')), "$file loads secondary styles only where used");
    }
}
verify(isset($GLOBALS['hooks']['block_editor_settings_all']), 'editor receives saved width settings');
if (isset($GLOBALS['hooks']['block_editor_settings_all'])) {
    $settings = ($GLOBALS['hooks']['block_editor_settings_all'][0])(array('styles'=>array()));
    $editor = implode('', array_column($settings['styles'], 'css'));
    verify(str_contains($editor, '--zen-reading-width:720px'), 'editor receives same reading width');
}
$reading_css = file_get_contents(ABSPATH . 'assets/css/reading.css');
verify(preg_match('/--zen-font-body:\s*"Zen Kami Kai", "TsangerJinKai02"/', $reading_css) && str_contains($reading_css, '--zen-font-heading: var(--zen-font-body)'), 'reader and editor body/title use the same Kai stack');
verify(preg_match('/--zen-font-mono:\s*"TsangerJinKai02 Code CJK", "Google Sans Code"/', $reading_css), 'mixed code uses separate CJK and Latin families');
verify($GLOBALS['editor_styles'] === array('assets/css/zen-bins-google-code.css', 'assets/css/reading.css'), 'editor registers the shared text and code font styles');
verify(in_array('zen-bins-google-code', $GLOBALS['styles']['zen-reading-style'][1], true), 'reader style keeps its code font dependency');
verify(isset($GLOBALS['styles']['zen-bins-google-code']) && !isset($GLOBALS['styles']['zen-google-fonts']), 'reference code font is self-hosted');
verify(in_array('assets/css/zen-bins-google-code.css', $GLOBALS['editor_styles'], true), 'editor loads the same local code font');
verify(!isset($GLOBALS['styles']['zen-bins-kai']), 'public package does not request missing optional Kai fonts');
exit($failed ? 1 : 0);
