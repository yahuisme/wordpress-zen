<?php
/*
Template Name: Archives Template
*/
get_header();

$archive_locked = post_password_required();
$archives_posts = array();
if (!$archive_locked) {
    $archive_cache_key = zen_archive_cache_key();
    $archives_posts = get_transient($archive_cache_key);

    if (!is_array($archives_posts)) {
        $archives_posts = array();
        $archive_page = 1;
        $archive_batch_size = 500;

        do {
            $archives_query = new WP_Query(array(
                'post_status' => 'publish',
                'posts_per_page' => $archive_batch_size,
                'paged' => $archive_page,
                'ignore_sticky_posts' => true,
                'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => str_contains((string) get_option('permalink_structure'), '%category%'),
            ));

            foreach ($archives_query->posts as $archive_post) {
                $archives_posts[] = array(
                    'id' => $archive_post->ID,
                    'title' => get_the_title($archive_post),
                    'url' => get_permalink($archive_post),
                    'year' => get_the_date('Y', $archive_post),
                    'date' => get_the_date('Y-m-d', $archive_post),
                );
            }

            // Iterating the returned posts without the_post() leaves the main Loop untouched.
            $archive_has_more = count($archives_query->posts) === $archive_batch_size;
            $archive_page++;
            unset($archives_query);
        } while ($archive_has_more);

        set_transient($archive_cache_key, $archives_posts, HOUR_IN_SECONDS);
    }
}
?>
<header class="zen-directory-heading zen-archive-heading">
    <p class="zen-directory-kicker" lang="en" aria-hidden="true">Archive</p>
    <h1 class="zen-directory-title"><?php echo esc_html(zen_post_title()); ?></h1>
    <?php if ($archives_posts) : ?>
        <p class="zen-directory-meta">
            <?php echo esc_html(sprintf(__('共 %d 篇文章', 'zen'), count($archives_posts))); ?>
            <span aria-hidden="true">·</span>
            <?php echo esc_html(__('最近发布', 'zen')); ?>
            <time datetime="<?php echo esc_attr($archives_posts[0]['date']); ?>"><?php echo esc_html(str_replace('-', '.', $archives_posts[0]['date'])); ?></time>
        </p>
    <?php endif; ?>
</header>

<?php if ($archive_locked) : ?>
    <div class="entry-content zen-reading-shell"><?php echo get_the_password_form(); ?></div>
    <?php get_footer(); return; ?>
<?php endif; ?>

<div class="zen-archives-directory">
    <?php
    // Group the cached civil dates for display without changing their stored format.
    $archive_years = array();
    foreach ($archives_posts as $archive_post) {
        $archive_years[$archive_post['year']][substr($archive_post['date'], 5, 2)][] = $archive_post;
    }
    $date_format = zen_post_date_format('directory');
    ?>
    <div id="zen-archive-years">
    <?php foreach ($archive_years as $year => $months) : ?>
        <details class="zen-archive-group" open>
            <summary class="zen-archive-year-head">
                <h2 class="zen-archive-year"><?php echo esc_html($year); ?></h2>
                <span class="zen-archive-year-meta zen-directory-meta">
                    <span><?php echo esc_html(sprintf(__('%d 篇', 'zen'), array_sum(array_map('count', $months)))); ?></span>
                    <span aria-hidden="true">/</span>
                    <span class="zen-archive-toggle">
                        <span class="zen-archive-expand"><?php echo esc_html(__('展开', 'zen')); ?></span>
                        <span class="zen-archive-collapse"><?php echo esc_html(__('收起', 'zen')); ?></span>
                    </span>
                </span>
            </summary>
            <?php foreach ($months as $month => $rows) : ?>
                <section class="zen-archive-month">
                    <header class="zen-archive-month-head">
                        <h3 class="zen-archive-month-label"><?php echo esc_html(sprintf(__('%d 月', 'zen'), (int) $month)); ?></h3>
                        <span class="zen-directory-meta"><?php echo esc_html(sprintf(__('%d 篇', 'zen'), count($rows))); ?></span>
                    </header>
                    <ul class="zen-post-list zen-archive-list">
                        <?php foreach ($rows as $archive_post) : ?>
                            <li class="zen-archive-row zen-post-item">
                                <?php $post_date = DateTimeImmutable::createFromFormat('!Y-m-d', $archive_post['date']); ?>
                                <?php if ($post_date) : ?>
                                    <time class="zen-post-date zen-meta-field" datetime="<?php echo esc_attr($archive_post['date']); ?>"><?php echo esc_html($post_date->format($date_format)); ?></time>
                                <?php endif; ?>
                                <a href="<?php echo esc_url($archive_post['url']); ?>" class="zen-archive-link"><?php echo esc_html(trim($archive_post['title']) === '' ? __('无标题', 'zen') : $archive_post['title']); ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </details>
    <?php endforeach; ?>
    <?php if (!$archive_years) : ?>
        <p class="zen-empty-state"><?php echo esc_html(__('暂无文章。', 'zen')); ?></p>
    <?php endif; ?>
    </div>
    <?php if (count($archive_years) > 2) : ?>
        <?php zen_directory_more('zen-archive-years', 2, __('查看更早年份', 'zen'), 'zen-archive-more'); ?>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
