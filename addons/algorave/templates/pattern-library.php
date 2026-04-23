<?php
/**
 * Template: Pattern Library
 *
 * Renders a browsable library of saved algorave patterns.
 * Used by the [algorave_pattern_library] shortcode.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 *
 * @var array $atts Shortcode attributes (per_page, genre).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$algorave_per_page = absint( $atts['per_page'] ?? 12 );
$algorave_genre    = sanitize_text_field( $atts['genre'] ?? '' );
$algorave_paged    = max( 1, absint( get_query_var( 'paged', 1 ) ) );

$query_args = array(
	'post_type'      => NV_oOS_Algorave_Pattern_CPT::POST_TYPE,
	'post_status'    => 'publish',
	'posts_per_page' => $algorave_per_page,
	'paged'          => $algorave_paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
);

if ( ! empty( $algorave_genre ) ) {
	$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		array(
			'taxonomy' => 'algorave_genre',
			'field'    => 'slug',
			'terms'    => $algorave_genre,
		),
	);
}

$patterns = new WP_Query( $query_args );
?>
<div class="algorave-pattern-library">
	<h2><?php esc_html_e( 'Pattern Library', 'nvoos-algorave' ); ?></h2>

	<?php if ( $patterns->have_posts() ) : ?>
		<div class="algorave-pattern-grid">
			<?php
			while ( $patterns->have_posts() ) :
				$patterns->the_post();
				$pattern = NV_oOS_Algorave_Pattern_CPT::get_pattern( get_the_ID() );
				if ( ! $pattern ) {
					continue;
				}
				?>
				<div class="algorave-pattern-card">
					<h3 class="algorave-pattern-title"><?php echo esc_html( $pattern['name'] ); ?></h3>
					<div class="algorave-pattern-meta">
						<span class="algorave-pattern-engine"><?php echo esc_html( ucfirst( $pattern['engine'] ) ); ?></span>
						<span class="algorave-pattern-bpm"><?php echo esc_html( $pattern['bpm'] ); ?> BPM</span>
						<span class="algorave-pattern-scale"><?php echo esc_html( $pattern['scale'] ); ?></span>
						<?php if ( ! empty( $pattern['genre'] ) ) : ?>
							<span class="algorave-pattern-genre"><?php echo esc_html( $pattern['genre'] ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $pattern['description'] ) ) : ?>
						<p class="algorave-pattern-desc"><?php echo esc_html( wp_trim_words( $pattern['description'], 20 ) ); ?></p>
					<?php endif; ?>
					<div class="algorave-pattern-actions">
						<button type="button"
							class="algorave-btn algorave-btn-load"
							data-pattern-id="<?php echo esc_attr( $pattern['id'] ); ?>"
							data-code="<?php echo esc_attr( $pattern['code'] ); ?>"
							data-engine="<?php echo esc_attr( $pattern['engine'] ); ?>">
							<?php esc_html_e( 'Load', 'nvoos-algorave' ); ?>
						</button>
					</div>
				</div>
			<?php endwhile; ?>
		</div>

		<?php
		// Pagination.
		$total_pages = $patterns->max_num_pages;
		if ( $total_pages > 1 ) :
			?>
			<div class="algorave-pagination">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'total'   => $total_pages,
							'current' => $algorave_paged,
						)
					)
				);
				?>
			</div>
		<?php endif; ?>

	<?php else : ?>
		<p class="algorave-no-patterns">
			<?php esc_html_e( 'No patterns found. Create one using the live coder or ask the AI assistant to generate a pattern!', 'nvoos-algorave' ); ?>
		</p>
	<?php endif; ?>

	<?php wp_reset_postdata(); ?>
</div>
