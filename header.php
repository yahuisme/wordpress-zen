<!DOCTYPE html>
<html <?php language_attributes(); ?> class="scroll-smooth">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script data-rocket-exclude="true">
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
</head>
<body <?php body_class('transition-colors duration-300 min-h-screen flex flex-col relative'); ?>>
<?php wp_body_open(); ?>

<a href="#main-content" class="skip-link">跳至主要内容</a>

<?php if (zen_get_option('zen_show_reading_progress')) : ?>
<div id="reading-progress" aria-hidden="true" class="fixed top-0 left-0 h-1 bg-gray-900 dark:bg-white z-50 transition-all duration-100 ease-out w-0"></div>
<?php endif; ?>

<header role="banner" class="zen-site-header w-full transition-colors backdrop-blur-md sticky top-0 z-40">
    <div class="max-w-zen mx-auto px-4 sm:px-6 h-20 flex items-center justify-between">

        <a href="<?php echo esc_url(home_url('/')); ?>" class="zen-site-brand zen-ui-link" aria-label="<?php echo esc_attr(get_bloginfo('name') . ' - 首页'); ?>">
            <?php if (has_custom_logo()) : ?>
                <?php echo wp_get_attachment_image((int) get_theme_mod('custom_logo'), 'full', false, array('class' => 'zen-site-logo', 'alt' => get_bloginfo('name'))); ?>
            <?php endif; ?>
            <span class="zen-site-title font-serif font-bold"><?php echo esc_html(get_bloginfo('name')); ?></span>
        </a>

        <div class="zen-header-actions flex items-center">

            <nav role="navigation" aria-label="主菜单" class="hidden md:flex items-center font-medium text-gray-600 dark:text-gray-400">
                <?php
                wp_nav_menu(array(
                    'theme_location' => 'primary',
                    'container'      => false,
                    'menu_class'     => 'flex items-center gap-5 list-none m-0 p-0 zen-primary-menu',
                    'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
                    'fallback_cb'    => false,
                    'depth'          => 1,
                ));
                ?>
            </nav>

            <div class="zen-header-tools">
                <?php if (is_singular('post') && zen_get_option('zen_show_toc')) : ?>
                <button id="header-toc-btn" type="button" class="zen-header-tool zen-header-toc zen-ui-link hidden" aria-label="打开目录" aria-haspopup="dialog" aria-controls="drawer-toc" aria-expanded="false" hidden>目录</button>
                <?php endif; ?>
                <button id="theme-toggle" type="button"
                        class="zen-theme-toggle zen-header-tool zen-icon-btn"
                        aria-label="切换主题"
                        title="跟随系统">
                    <i class="ph ph-circle-half text-xl md:text-lg" aria-hidden="true"></i>
                    <span class="screen-reader-text" data-theme-toggle-label>跟随系统</span>
                </button>

                <button id="search-toggle" type="button"
                        class="zen-header-tool zen-icon-btn"
                        aria-label="搜索"
                        aria-expanded="false"
                        aria-controls="search-modal">
                    <i class="ph ph-magnifying-glass text-xl md:text-lg" aria-hidden="true"></i>
                </button>

                <?php if (has_nav_menu('primary')) : ?>
                <button id="mobile-menu-btn" type="button"
                        class="zen-header-tool zen-icon-btn md:hidden"
                        aria-expanded="false"
                        aria-controls="mobile-menu">
                    <span class="screen-reader-text">打开/关闭菜单</span>
                    <i class="ph ph-list text-2xl" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (has_nav_menu('primary')) : ?>
    <div id="mobile-menu" class="zen-mobile-menu hidden md:hidden absolute top-20 left-0 w-full p-4 shadow-lg animate-fade-in z-40" aria-hidden="true">
        <nav aria-label="移动端菜单" class="flex flex-col gap-4 text-center text-sm font-medium text-gray-600 dark:text-gray-400">
            <?php
            wp_nav_menu(array(
                'theme_location' => 'primary',
                'container'      => false,
                'menu_class'     => 'flex flex-col gap-4 list-none m-0 p-0',
                'items_wrap'     => '<ul class="%2$s">%3$s</ul>',
                'fallback_cb'    => false,
                'depth'          => 1,
            ));
            ?>
        </nav>
    </div>
    <noscript>
        <nav class="zen-noscript-nav" aria-label="主菜单（无脚本）">
            <?php wp_nav_menu(array('theme_location' => 'primary', 'container' => false, 'menu_class' => 'zen-noscript-menu', 'fallback_cb' => false, 'depth' => 1)); ?>
        </nav>
    </noscript>
    <?php endif; ?>
</header>

<div id="search-modal"
     role="dialog"
     aria-modal="true"
     aria-label="全站搜索"
     class="zen-search-modal fixed inset-0 z-50 hidden opacity-0 p-4">
    <div class="zen-search-backdrop absolute inset-0" data-search-dismiss="true" aria-hidden="true"></div>

    <div class="zen-search-shell relative mx-auto flex min-h-full w-full max-w-3xl items-center justify-center py-12">
        <div class="zen-search-panel w-full">
            <button id="search-close"
                    class="zen-search-close zen-icon-btn"
                    type="button"
                    aria-label="关闭搜索">
                <i class="ph ph-x" aria-hidden="true"></i>
            </button>

            <div class="zen-search-eyebrow">全站搜索</div>

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

<main id="main-content" class="flex-grow w-full max-w-zen mx-auto px-4 sm:px-6 py-10 transition-colors relative">
