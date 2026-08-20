<!DOCTYPE html>
<html <?php language_attributes(); ?> class="scroll-smooth">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
        (function() {
            var storageKey = 'zen-theme-mode';
            var mode = 'auto';
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
    
    <!-- SEO: Robots Control -->
    <!-- Google 最佳实践：防止索引内部搜索结果页，避免重复内容和爬虫预算浪费 -->
    <?php if (is_search()) : ?>
        <meta name="robots" content="noindex, follow" />
    <?php endif; ?>

    <!-- SEO: Meta Description -->
    <meta name="description" content="<?php 
        if ( is_single() || is_page() ) {
            $excerpt = get_the_excerpt();
            if (empty($excerpt)) {
                $post = get_post();
                $excerpt = wp_trim_words($post->post_content, 30);
            }
            echo esc_attr(strip_tags($excerpt)); 
        } elseif ( is_category() || is_tag() ) {
            echo esc_attr(strip_tags(term_description()));
        } elseif ( is_search() ) {
            echo esc_attr('关于“' . get_search_query(false) . '”的搜索结果 - ' . get_bloginfo('name'));
        } else {
            $name = get_bloginfo('name');
            $description = get_bloginfo('description');
            if ( ! empty($description) ) {
                echo esc_attr($name . ' - ' . $description);
            } else {
                echo esc_attr($name);
            }
        }
    ?>">

    <?php wp_head(); ?>
</head>
<body <?php body_class('transition-colors duration-300 min-h-screen flex flex-col relative'); ?>>
<?php wp_body_open(); ?>

<!-- A11y: Skip Link -->
<a href="#main-content" class="skip-link">跳至主要内容</a>

<!-- Reading Progress Bar -->
<div id="reading-progress" aria-hidden="true" class="fixed top-0 left-0 h-1 bg-gray-900 dark:bg-white z-50 transition-all duration-100 ease-out w-0"></div>

<!-- Header -->
<header role="banner" class="zen-site-header w-full transition-colors backdrop-blur-md sticky top-0 z-40">
    <div class="max-w-zen mx-auto px-4 sm:px-6 h-20 flex items-center justify-between">
        
        <!-- Logo / Avatar -->
        <div class="flex items-center gap-4">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="block shrink-0 group zen-ui-link rounded-full" aria-label="<?php echo esc_attr(get_bloginfo('name')); ?> - 首页">
                <?php 
                $avatar_url = get_avatar_url(get_option('admin_email'));
                ?>
                <img src="<?php echo esc_url($avatar_url); ?>" 
                     alt="" 
                     class="w-10 h-10 rounded-full object-cover ring-2 ring-gray-100 dark:ring-gray-700 group-hover:ring-gray-300 dark:group-hover:ring-gray-500 transition-all duration-300">
            </a>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="zen-ui-link text-xl font-bold tracking-tight text-gray-900 dark:text-white font-serif hover:opacity-80">
                <?php echo esc_html(get_bloginfo('name')); ?>
            </a>
        </div>

        <!-- Right Actions Container -->
        <div class="zen-header-actions flex items-center">
            
            <!-- Desktop Navigation -->
            <nav role="navigation" aria-label="主菜单" class="hidden md:flex items-center text-sm font-medium text-gray-600 dark:text-gray-400">
                <?php
                wp_nav_menu(array(
                    'theme_location' => 'primary',
                    'container'      => false,
                    'menu_class'     => 'flex items-center gap-5 list-none m-0 p-0',
                    'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
                    'fallback_cb'    => false,
                    'depth'          => 1,
                ));
                ?>
            </nav>

            <div class="zen-header-tools">
                <!-- Theme Toggle Button -->
                <button id="theme-toggle"
                        class="zen-theme-toggle zen-header-tool zen-icon-btn"
                        type="button"
                        aria-label="切换主题"
                        title="跟随系统">
                    <i class="ph ph-circle-half text-xl md:text-lg" aria-hidden="true"></i>
                    <span class="screen-reader-text" data-theme-toggle-label>跟随系统</span>
                </button>

                <!-- Search Toggle Button -->
                <button id="search-toggle"
                        class="zen-header-tool zen-icon-btn"
                        aria-label="搜索"
                        aria-expanded="false"
                        aria-controls="search-modal">
                    <i class="ph ph-magnifying-glass text-xl md:text-lg" aria-hidden="true"></i>
                </button>

                <!-- Mobile Menu Button -->
                <button id="mobile-menu-btn"
                        class="zen-header-tool zen-icon-btn md:hidden"
                        aria-expanded="false"
                        aria-controls="mobile-menu">
                    <span class="screen-reader-text">打开/关闭菜单</span>
                    <i class="ph ph-list text-2xl" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Menu Overlay -->
    <div id="mobile-menu" class="zen-mobile-menu hidden md:hidden absolute top-20 left-0 w-full p-4 shadow-lg animate-fade-in z-40">
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
</header>

<!-- Search Modal (Overlay) -->
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

<main id="main-content" class="flex-grow w-full max-w-zen mx-auto px-4 sm:px-6 py-10 transition-all relative">
