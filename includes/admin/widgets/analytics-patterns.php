<?php
/**
 * Analytics Patterns Widget Template
 *
 * Displays usage pattern analysis (hourly and daily patterns).
 *
 * @package WP_MCP_AI
 * @var array $data Widget data containing pattern information.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : get_current_user_id();

// Get pattern data from Analytics Engine if available.
$has_analytics = class_exists( 'WP_MCP_AI_Analytics_Engine' );
$pattern_data  = array();

if ( $has_analytics ) {
	$pattern_data = WP_MCP_AI_Analytics_Engine::detect_patterns( $user_id );
}
?>

<div class="wp-mcp-ai-widget-analytics-patterns">
	<?php if ( $has_analytics && ! empty( $pattern_data['hourly_pattern'] ) ) : ?>
		<!-- Pattern Charts -->
		<div class="wp-mcp-ai-pattern-charts" style="display: grid; gap: 20px;">
			<!-- Hourly Pattern Chart -->
			<div class="wp-mcp-ai-chart-container">
				<canvas id="wp-mcp-ai-hourly-pattern-chart"></canvas>
			</div>

			<!-- Daily Pattern Chart -->
			<div class="wp-mcp-ai-chart-container">
				<canvas id="wp-mcp-ai-daily-pattern-chart"></canvas>
			</div>
		</div>

		<!-- Pattern Insights -->
		<div class="wp-mcp-ai-pattern-insights" style="margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 4px;">
			<div style="font-weight: 600; margin-bottom: 10px;">
				<?php esc_html_e( 'Key Insights', 'mcp-ai-wpoos' ); ?>
			</div>

			<!-- Peak Hours -->
			<?php if ( ! empty( $pattern_data['peak_hours'] ) ) : ?>
				<div style="margin-bottom: 8px;">
					<span class="dashicons dashicons-clock" style="color: #2271b1;"></span>
					<strong><?php esc_html_e( 'Peak Hours:', 'mcp-ai-wpoos' ); ?></strong>
					<?php
					$peak_hours_formatted = array_map(
						function ( $hour ) {
							return sprintf( '%02d:00', $hour );
						},
						$pattern_data['peak_hours']
					);
					echo esc_html( implode( ', ', $peak_hours_formatted ) );
					?>
				</div>
			<?php endif; ?>

			<!-- Peak Days -->
			<?php if ( ! empty( $pattern_data['peak_days'] ) ) : ?>
				<div style="margin-bottom: 8px;">
					<span class="dashicons dashicons-calendar-alt" style="color: #2271b1;"></span>
					<strong><?php esc_html_e( 'Peak Days:', 'mcp-ai-wpoos' ); ?></strong>
					<?php echo esc_html( implode( ', ', $pattern_data['peak_days'] ) ); ?>
				</div>
			<?php endif; ?>

			<!-- Usage Type -->
			<div>
				<span class="dashicons dashicons-chart-bar" style="color: #2271b1;"></span>
				<strong><?php esc_html_e( 'Usage Pattern:', 'mcp-ai-wpoos' ); ?></strong>
				<?php
				$usage_type        = isset( $pattern_data['usage_type'] ) ? $pattern_data['usage_type'] : 'consistent';
				$usage_type_labels = array(
					'consistent' => __( 'Consistent (predictable usage)', 'mcp-ai-wpoos' ),
					'sporadic'   => __( 'Sporadic (moderate variation)', 'mcp-ai-wpoos' ),
					'bursty'     => __( 'Bursty (high variation)', 'mcp-ai-wpoos' ),
				);
				echo esc_html( isset( $usage_type_labels[ $usage_type ] ) ? $usage_type_labels[ $usage_type ] : ucfirst( $usage_type ) );
				?>
			</div>
		</div>

		<?php
		wp_enqueue_script(
			'wp-mcp-ai-analytics-patterns',
			WP_MCP_AI_URL . 'assets/js/admin/widgets/analytics-patterns.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-analytics-patterns',
			'wpMcpAiPatternData',
			array(
				'hourlyPattern' => array_values( $pattern_data['hourly_pattern'] ),
				'dailyPattern'  => array_values( $pattern_data['daily_pattern'] ),
				'labels'        => array(
					'tokensUsed'  => __( 'Tokens Used', 'mcp-ai-wpoos' ),
					'hourlyTitle' => __( 'Hourly Usage Pattern', 'mcp-ai-wpoos' ),
					'dailyTitle'  => __( 'Daily Usage Pattern (by Day of Week)', 'mcp-ai-wpoos' ),
					'dayLabels'   => array(
						__( 'Sun', 'mcp-ai-wpoos' ),
						__( 'Mon', 'mcp-ai-wpoos' ),
						__( 'Tue', 'mcp-ai-wpoos' ),
						__( 'Wed', 'mcp-ai-wpoos' ),
						__( 'Thu', 'mcp-ai-wpoos' ),
						__( 'Fri', 'mcp-ai-wpoos' ),
						__( 'Sat', 'mcp-ai-wpoos' ),
					),
				),
			)
		);
		?>
	<?php else : ?>
		<!-- No Data Notice -->
		<div class="wp-mcp-ai-widget-notice" style="padding: 20px; background: #f7f7f7; border-left: 4px solid #2271b1; text-align: center;">
			<div style="margin-bottom: 10px;">
				<span class="dashicons dashicons-chart-bar" style="font-size: 48px; color: #2271b1; opacity: 0.5;"></span>
			</div>
			<p style="margin: 0;">
				<?php esc_html_e( 'No usage patterns detected yet. Start using AI tools to see your usage patterns!', 'mcp-ai-wpoos' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<div class="wp-mcp-ai-widget-actions" style="margin-top: 15px; text-align: right;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ); ?>" class="button">
			<?php esc_html_e( 'View Full Analytics', 'mcp-ai-wpoos' ); ?>
		</a>
	</div>
</div>
