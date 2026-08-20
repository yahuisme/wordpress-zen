<?php get_header(); ?>

<?php if (have_posts()) : while (have_posts()) : the_post(); ?>

	<header class="mb-10 text-center">
		<h1 class="text-3xl md:text-4xl lg:text-5xl font-bold text-gray-900 dark:text-white mb-6 leading-tight serif">
			<?php the_title(); ?>
		</h1>
	</header>

	<article class="prose prose-lg prose-zinc dark:prose-invert mx-auto focus:outline-none entry-content">
		<?php the_content(); ?>
	</article>

<?php endwhile; endif; ?>

<?php get_footer(); ?>