<?php
/*
Template Name: Tags Template
*/
get_header();

while (have_posts()) : the_post();
    $tags_locked = post_password_required();
    $tag_directory = array();
    $latest_tagged_post = null;
    if (!$tags_locked) {
        $tags = get_tags(array('hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC'));
        if (!is_wp_error($tags)) {
            foreach ($tags as $tag) {
                $tag_url = get_tag_link($tag->term_id);
                if (!is_wp_error($tag_url)) {
                    $tag_directory[$tag->term_id] = array('term' => $tag, 'url' => $tag_url, 'recent' => null);
                }
            }
        }

        // Visit tagged posts newest first in batches, using Core's primed term cache.
        // Each topic needs just one preview; do not issue a post query per topic.
        $remaining = count($tag_directory);
        $batch_size = 100;
        for ($batch = 1; $remaining > 0; $batch++) {
            $tag_posts = new WP_Query(array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $batch_size,
                'paged' => $batch,
                'ignore_sticky_posts' => true,
                'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'tax_query' => array(array('taxonomy' => 'post_tag', 'terms' => array_keys($tag_directory))),
            ));
            foreach ($tag_posts->posts as $tagged_post) {
                $post_tags = get_the_terms($tagged_post, 'post_tag');
                if (!$post_tags || is_wp_error($post_tags)) continue;
                foreach ($post_tags as $tag) {
                    $tag_id = $tag->term_id;
                    if (isset($tag_directory[$tag_id]) && !$tag_directory[$tag_id]['recent']) {
                        $tag_directory[$tag_id]['recent'] = $tagged_post;
                        $latest_tagged_post = $latest_tagged_post ?? $tagged_post;
                        $remaining--;
                    }
                }
                if ($remaining === 0) break;
            }
            if (count($tag_posts->posts) < $batch_size) break;
        }
    }
    ?>
    <header class="zen-directory-heading">
        <p class="zen-directory-kicker" lang="en" aria-hidden="true">Tags</p>
        <h1 class="zen-directory-title"><?php echo esc_html(zen_post_title()); ?></h1>
        <div class="zen-directory-intro entry-content"><?php the_content(); ?></div>
        <?php wp_link_pages(array(
            'before' => '<nav class="zen-page-links" aria-label="' . esc_attr__('正文分页', 'zen') . '">',
            'after' => '</nav>',
        )); ?>
        <?php if ($tag_directory) : ?>
            <p class="zen-directory-meta">
                <?php echo esc_html(sprintf(__('共 %d 个主题', 'zen'), count($tag_directory))); ?>
                <?php if ($latest_tagged_post) : ?>
                    <span aria-hidden="true">·</span>
                    <?php esc_html_e('最近发布', 'zen'); ?>
                    <time datetime="<?php echo esc_attr(get_the_date('Y-m-d', $latest_tagged_post)); ?>"><?php echo esc_html(get_the_date('Y.m.d', $latest_tagged_post)); ?></time>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </header>

    <?php if ($tags_locked) continue; ?>
    <?php if ($tag_directory) : ?>
        <ul class="zen-tags-directory" role="list">
            <?php foreach ($tag_directory as $topic) : ?>
                <li class="zen-tag-item">
                    <div class="zen-tag-head">
                        <h2><a href="<?php echo esc_url($topic['url']); ?>"><?php echo esc_html($topic['term']->name); ?></a></h2>
                        <span class="zen-directory-meta"><?php echo esc_html(sprintf(__('%d 篇', 'zen'), $topic['term']->count)); ?></span>
                    </div>
                    <?php if ($topic['recent']) : ?>
                        <div class="zen-tag-recent">
                            <span><?php esc_html_e('最近', 'zen'); ?></span>
                            <a href="<?php echo esc_url(get_permalink($topic['recent'])); ?>"><?php echo esc_html(zen_post_title($topic['recent'])); ?></a>
                            <time datetime="<?php echo esc_attr(get_the_date('Y-m-d', $topic['recent'])); ?>"><?php echo esc_html(get_the_date('Y.m.d', $topic['recent'])); ?></time>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?>
        <p class="zen-empty-state"><?php esc_html_e('暂无标签。', 'zen'); ?></p>
    <?php endif; ?>
    <?php if (comments_open() || get_comments_number()) comments_template(); ?>
<?php endwhile; ?>

<?php get_footer(); ?>
