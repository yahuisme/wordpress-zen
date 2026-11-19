<?php
if (!defined('ABSPATH')) exit;
$args = isset($args) && is_array($args) ? $args : array();
$zen_compact = zen_get_option('zen_list_style') === 'compact';
$zen_post_id = (int) get_the_ID();
?>
<article id="post-<?php echo $zen_post_id; ?>" <?php post_class('zen-post-item zen-post-item--' . ($zen_compact ? 'compact' : 'standard')); ?> aria-labelledby="post-title-<?php echo $zen_post_id; ?>">
    <?php if (!empty($args['editorial_index'])) : ?>
        <div class="zen-post-meta"><?php echo zen_index_date_html(); ?></div>
    <?php else : ?>
        <?php zen_index_post_meta('list'); ?>
    <?php endif; ?>
    <h2 id="post-title-<?php echo $zen_post_id; ?>" class="zen-post-title">
        <a href="<?php the_permalink(); ?>" class="zen-post-title-link">
            <?php if (!empty($args['highlight_title'])) : ?>
                <?php echo zen_highlight_search_terms(zen_post_title(), get_search_query(false)); ?>
            <?php else : echo esc_html(zen_post_title()); endif; ?>
        </a>
    </h2>
    <?php zen_index_post_excerpt(); ?>
</article>
