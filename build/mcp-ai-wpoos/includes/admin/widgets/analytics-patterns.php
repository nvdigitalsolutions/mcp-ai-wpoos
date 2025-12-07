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
				<canvas id="wp-mcp-ai-hourly-pattern-chart" width="400" height="200"></canvas>
			</div>

			<!-- Daily Pattern Chart -->
			<div class="wp-mcp-ai-chart-container">
				<canvas id="wp-mcp-ai-daily-pattern-chart" width="400" height="200"></canvas>
			</div>
		</div>

		<!-- Pattern Insights -->
		<div class="wp-mcp-ai-pattern-insights" style="margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 4px;">
			<div style="font-weight: 600; margin-bottom: 10px;">
				<?php esc_html_e( 'Key Insights', 'wp-mcp-ai' ); ?>
			</div>
			
			<!-- Peak Hours -->
			<?php if ( ! empty( $pattern_data['peak_hours'] ) ) : ?>
				<div style="margin-bottom: 8px;">
					<span class="dashicons dashicons-clock" style="color: #2271b1;"></span>
					<strong><?php esc_html_e( 'Peak Hours:', 'wp-mcp-ai' ); ?></strong>
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
					<strong><?php esc_html_e( 'Peak Days:', 'wp-mcp-ai' ); ?></strong>
					<?php echo esc_html( implode( ', ', $pattern_data['peak_days'] ) ); ?>
				</div>
			<?php endif; ?>

			<!-- Usage Type -->
			<div>
				<span class="dashicons dashicons-chart-bar" style="color: #2271b1;"></span>
				<strong><?php esc_html_e( 'Usage Pattern:', 'wp-mcp-ai' ); ?></strong>
				<?php
				$usage_type        = isset( $pattern_data['usage_type'] ) ? $pattern_data['usage_type'] : 'consistent';
				$usage_type_labels = array(
					'consistent' => __( 'Consistent (predictable usage)', 'wp-mcp-ai' ),
					'sporadic'   => __( 'Sporadic (moderate variation)', 'wp-mcp-ai' ),
					'bursty'     => __( 'Bursty (high variation)', 'wp-mcp-ai' ),
				);
				echo esc_html( isset( $usage_type_labels[ $usage_type ] ) ? $usage_type_labels[ $usage_type ] : ucfirst( $usage_type ) );
				?>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			if (typeof Chart !== 'undefined') {
				// Hourly Pattern Chart
				var hourlyCtx = document.getElementById('wp-mcp-ai-hourly-pattern-chart');
				if (hourlyCtx) {
					var hourlyPattern = <?php echo wp_json_encode( array_values( $pattern_data['hourly_pattern'] ) ); ?>;
					var hourLabels = [];
					for (var i = 0; i < 24; i++) {
						hourLabels.push(i.toString().padStart(2, '0') + ':00');
					}
					
					new Chart(hourlyCtx.getContext('2d'), {
						type: 'bar',
						data: {
							labels: hourLabels,
							datasets: [{
								label: '<?php esc_attr_e( 'Tokens Used', 'wp-mcp-ai' ); ?>',
								data: hourlyPattern,
								backgroundColor: 'rgba(54, 162, 235, 0.6)',
								borderColor: 'rgba(54, 162, 235, 1)',
								borderWidth: 1
							}]
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: false
								},
								title: {
									display: true,
									text: '<?php esc_attr_e( 'Hourly Usage Pattern', 'wp-mcp-ai' ); ?>'
								},
								tooltip: {
									callbacks: {
										label: function(context) {
											return context.parsed.y.toLocaleString() + ' tokens';
										}
									}
								}
							},
							scales: {
								y: {
									beginAtZero: true,
									ticks: {
										callback: function(value) {
											if (value >= 1000) {
												return (value / 1000).toFixed(1) + 'K';
											}
											return value;
										}
									}
								}
							}
						}
					});
				}

				// Daily Pattern Chart
				var dailyCtx = document.getElementById('wp-mcp-ai-daily-pattern-chart');
				if (dailyCtx) {
					var dailyPattern = <?php echo wp_json_encode( array_values( $pattern_data['daily_pattern'] ) ); ?>;
					var dayLabels = ['<?php echo esc_js( __( 'Sun', 'wp-mcp-ai' ) ); ?>', '<?php echo esc_js( __( 'Mon', 'wp-mcp-ai' ) ); ?>', '<?php echo esc_js( __( 'Tue', 'wp-mcp-ai' ) ); ?>', '<?php echo esc_js( __( 'Wed', 'wp-mcp-ai' ) ); ?>', '<?php echo esc_js( __( 'Thu', 'wp-mcp-ai' ) ); ?>', '<?php echo esc_js( __( 'Fri', 'wp-mcp-ai' ) ); ?>', '<?php echo esc_js( __( 'Sat', 'wp-mcp-ai' ) ); ?>'];
					
					new Chart(dailyCtx.getContext('2d'), {
						type: 'bar',
						data: {
							labels: dayLabels,
							datasets: [{
								label: '<?php esc_attr_e( 'Tokens Used', 'wp-mcp-ai' ); ?>',
								data: dailyPattern,
								backgroundColor: 'rgba(75, 192, 192, 0.6)',
								borderColor: 'rgba(75, 192, 192, 1)',
								borderWidth: 1
							}]
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: false
								},
								title: {
									display: true,
									text: '<?php esc_attr_e( 'Daily Usage Pattern (by Day of Week)', 'wp-mcp-ai' ); ?>'
								},
								tooltip: {
									callbacks: {
										label: function(context) {
											return context.parsed.y.toLocaleString() + ' tokens';
										}
									}
								}
							},
							scales: {
								y: {
									beginAtZero: true,
									ticks: {
										callback: function(value) {
											if (value >= 1000) {
												return (value / 1000).toFixed(1) + 'K';
											}
											return value;
										}
									}
								}
							}
						}
					});
				}
			}
		});
		</script>
	<?php else : ?>
		<!-- No Data Notice -->
		<div class="wp-mcp-ai-widget-notice" style="padding: 20px; background: #f7f7f7; border-left: 4px solid #2271b1; text-align: center;">
			<div style="margin-bottom: 10px;">
				<span class="dashicons dashicons-chart-bar" style="font-size: 48px; color: #2271b1; opacity: 0.5;"></span>
			</div>
			<p style="margin: 0;">
				<?php esc_html_e( 'No usage patterns detected yet. Start using AI tools to see your usage patterns!', 'wp-mcp-ai' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<div class="wp-mcp-ai-widget-actions" style="margin-top: 15px; text-align: right;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ); ?>" class="button">
			<?php esc_html_e( 'View Full Analytics', 'wp-mcp-ai' ); ?>
		</a>
	</div>
</div>
