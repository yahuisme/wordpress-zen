<!DOCTYPE html>
<html <?php language_attributes(); ?> class="scroll-smooth">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            var storageKey = 'zen-theme-mode';
            var mode = '<?php echo esc_js(zen_get_option('zen_theme_mode_default')); ?>';
            try {
                var storedMode = window.localStorage && window.localStorage.getItem(storageKey);
                if (storedMode === 'light' || storedMode === 'dark' || storedMode === 'auto') {
                    mode = storedMode;
                }
            } catch (error) {}

            var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            var isDark = mode === 'dark' || (mode === 'auto' && prefersDark);
            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.dataset.themeMode = mode;
            document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
        })();
    </script>
    <?php wp_head(); ?>
    <noscript><style>#floating-toc-btn.zen-toc-pending,#header-toc-btn.zen-toc-pending{display:none!important}</style></noscript>
</head>
<body <?php body_class('min-h-screen flex flex-col'); ?>>
<?php wp_body_open(); ?>

<a href="#main-content" class="skip-link">跳至主要内容</a>

<?php if (zen_get_option('zen_show_reading_progress')) : ?>
<div id="reading-progress" aria-hidden="true" class="fixed top-0 left-0 z-50 w-0"></div>
<?php endif; ?>

<header role="banner" class="zen-site-header w-full sticky top-0 z-40">
    <div class="max-w-zen mx-auto px-4 sm:px-6 flex items-center justify-between">

        <a href="<?php echo esc_url(home_url('/')); ?>" class="zen-site-brand zen-ui-link" aria-label="<?php echo esc_attr(get_bloginfo('name') . ' - 首页'); ?>">
            <?php if (has_custom_logo()) : ?>
                <?php echo wp_get_attachment_image((int) get_theme_mod('custom_logo'), 'full', false, array('class' => 'zen-site-logo', 'alt' => get_bloginfo('name'), 'sizes' => '(max-width: 639.98px) 56px, 120px')); ?>
            <?php endif; ?>
            <span class="zen-site-title"><?php echo esc_html(get_bloginfo('name')); ?></span>
        </a>

        <div class="zen-header-actions">
            <div class="zen-header-tools">
                <?php if (is_singular('post') && zen_get_option('zen_show_toc')) : ?>
                <button id="header-toc-btn" type="button" class="zen-header-tool zen-header-toc zen-ui-link hidden" aria-label="打开目录" aria-haspopup="dialog" aria-controls="drawer-toc" aria-expanded="false" hidden>目录</button>
                <?php endif; ?>
                <a id="search-fallback" href="<?php echo esc_url(home_url('/?s=')); ?>" class="zen-header-tool zen-icon-btn" aria-label="搜索">
                    <i class="ph ph-magnifying-glass text-xl" aria-hidden="true"></i>
                </a>
                <button id="search-toggle" type="button" hidden
                        class="zen-header-tool zen-icon-btn"
                        aria-label="搜索"
                        aria-expanded="false"
                        aria-controls="search-modal">
                    <i class="ph ph-magnifying-glass text-xl" aria-hidden="true"></i>
                </button>
                <button id="theme-toggle" type="button" hidden
                        class="zen-theme-toggle zen-header-tool zen-icon-btn"
                        aria-label="切换主题"
                        title="跟随系统">
                    <i class="ph ph-circle-half text-xl" aria-hidden="true"></i>
                    <span class="screen-reader-text" data-theme-toggle-label>跟随系统</span>
                </button>
            </div>
        </div>
    </div>

</header>

<div id="search-modal" hidden>
    <div class="zen-search-backdrop" data-search-dismiss="true" aria-hidden="true"></div>

    <div class="zen-search-shell relative mx-auto flex min-h-full w-full max-w-3xl items-center justify-center py-12">
        <div class="zen-search-panel w-full">
            <button id="search-close"
                    class="zen-search-close zen-icon-btn"
                    type="button" hidden
                    aria-label="关闭搜索">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>

            <h2 class="zen-search-eyebrow">全站搜索</h2>

            <form role="search" method="get" class="zen-search-form" action="<?php echo esc_url(home_url('/')); ?>">
                <label for="search-input" class="screen-reader-text">输入关键词进行搜索</label>
                <i class="ph ph-magnifying-glass zen-search-form-icon" aria-hidden="true"></i>
                <input type="search"
                       id="search-input"
                       name="s"
                       class="zen-search-input"
                       placeholder="输入关键词"
                       autocomplete="off"
                       value="<?php echo esc_attr(get_search_query(false)); ?>">
                <button type="submit" class="zen-search-submit">
                    <span>搜索</span>
                    <i class="ph ph-arrow-right" aria-hidden="true"></i>
                </button>
            </form>
        </div>
    </div>
</div>

<main id="main-content" class="flex-grow w-full max-w-zen mx-auto px-4 sm:px-6 relative">
