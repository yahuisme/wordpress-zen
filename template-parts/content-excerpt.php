<?php
if (!defined('ABSPATH')) exit;
$args = isset($args) && is_array($args) ? $args : array();
$zen_compact = zen_get_option('zen_list_style') === 'compact';
?>
<article id="post-<?php the_ID(); ?>" <?php post_class('zen-post-item zen-post-item--' . ($zen_compact ? 'compact' : 'standard')); ?> aria-labelledby="post-title-<?php the_ID(); ?>">
    <h2 id="post-title-<?php the_ID(); ?>" class="zen-post-title font-bold text-gray-900 dark:text-white leading-tight">
        <a href="<?php the_permalink(); ?>" class="zen-post-title-link">
            <?php if (!empty($args['highlight_title'])) : ?>
                <?php echo zen_highlight_search_terms(get_the_title(), get_search_query(false)); ?>
            <?php else : the_title(); endif; ?>
        </a>
    </h2>
    <div class="zen-post-meta text-xs text-gray-600 dark:text-gray-400">
        <?php $cat = zen_get_primary_category(); ?>
        <?php if ($cat) : ?>
            <a href="<?php echo esc_url(get_category_link($cat->term_id)); ?>" class="zen-meta-field zen-ui-link"><?php echo esc_html($cat->name); ?></a>
        <?php endif; ?>
        <?php if (is_search()) : ?>
            <time class="zen-meta-field" datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y年n月j日')); ?></time>
        <?php else : zen_posted_on_link(); endif; ?>
    </div>
    <?php if (!$zen_compact) : ?>
        <div class="zen-post-excerpt text-gray-600 dark:text-gray-300 leading-relaxed line-clamp-3">
            <?php echo esc_html(wp_trim_words(get_the_excerpt(), (int) zen_get_option('zen_excerpt_length'), '…')); ?>
        </div>
    <?php endif; ?>
</article>
