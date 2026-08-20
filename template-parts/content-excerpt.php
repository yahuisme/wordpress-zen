<?php
if (!defined('ABSPATH')) exit;

$args = isset($args) && is_array($args) ? $args : array();
$zen_highlight_title = ! empty($args['highlight_title']);
?>

<article id="post-<?php the_ID(); ?>" <?php post_class('group'); ?> aria-labelledby="post-title-<?php the_ID(); ?>">
    <div class="text-xs font-medium uppercase tracking-wider text-gray-600 dark:text-gray-400 mb-3 flex items-center gap-2">
        <?php
        $cat = zen_get_primary_category();
        if ($cat) {
            echo '<a href="' . esc_url(get_category_link($cat->term_id)) . '" class="zen-ui-link hover:text-gray-900 dark:hover:text-white">' . esc_html($cat->name) . '</a>';
            echo '<span class="text-gray-300 dark:text-gray-700" aria-hidden="true">*</span>';
        }

        if (is_search()) {
            printf(
                '<time datetime="%1$s">%2$s</time>',
                esc_attr(get_the_date('c')),
                esc_html(get_the_date('Y年 n月 j日'))
            );
        } else {
            zen_posted_on_link();
        }
        ?>
    </div>

    <h2 id="post-title-<?php the_ID(); ?>" class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white mb-4 leading-tight">
        <a href="<?php the_permalink(); ?>" class="hover:underline decoration-1 underline-offset-4 decoration-gray-400 transition-colors">
            <?php
            if ($zen_highlight_title) {
                echo zen_highlight_search_terms(get_the_title(), get_search_query(false));
            } else {
                the_title();
            }
            ?>
        </a>
    </h2>

    <div class="text-gray-600 dark:text-gray-300 leading-relaxed mb-4 line-clamp-3">
        <?php echo esc_html(wp_trim_words(get_the_excerpt(), 100, '...')); ?>
    </div>

    <a href="<?php the_permalink(); ?>" class="zen-action-link text-sm tracking-wide dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400">
        阅读更多
        <span class="screen-reader-text">关于 <?php the_title_attribute(); ?></span>
        <i class="ph ph-arrow-right ml-1.5 transform transition-transform duration-300 group-hover:translate-x-1" aria-hidden="true"></i>
    </a>
</article>

<div class="post-divider" aria-hidden="true"></div>
