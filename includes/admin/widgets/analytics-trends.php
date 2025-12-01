<?php
/**
 * Analytics Trends Widget Template
 *
 * Displays trend analysis with linear regression for token usage.
 *
 *
 * @package WP_MCP_AI
 * @var array $data Widget data containing trend information.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : get_current_user_id();
$days    = isset( $data['days'] ) ? absint( $data['days'] ) : 30;

// Get trend data from Analytics Engine if available.
$has_analytics = class_exists( 'WP_MCP_AI_Analytics_Engine' );
$trend_data    = array();

if ( $has_analytics ) {
	$trend_data = WP_MCP_AI_Analytics_Engine::get_user_trends( $user_id, $days );
}
?>

<div class="wp-mcp-ai-widget-analytics-trends">
	<?php if ( $has_analytics && ! empty( $trend_data['daily_usage'] ) ) : ?>
		<!-- Trend Chart -->
		<div class="wp-mcp-ai-chart-container" style="margin-bottom: 20px;">
			<canvas id="wp-mcp-ai-analytics-trend-chart" width="400" height="250"></canvas>
		</div>

		<!-- Trend Statistics -->
		<div class="wp-mcp-ai-trend-stats" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 20px;">
			<!-- Direction -->
			<div class="wp-mcp-ai-stat-box" style="padding: 15px; background: #f7f7f7; border-radius: 4px;">
				<div style="font-size: 12px; color: #666; margin-bottom: 5px;">
					<?php esc_html_e( 'Trend Direction', 'wp-mcp-ai' ); ?>
				</div>
				<div style="font-size: 16px; font-weight: 600;">
					<?php
					$direction = isset( $trend_data['trend']['direction'] ) ? $trend_data['trend']['direction'] : 'stable';
					$icon_map  = array(
						'increasing' => 'arrow-up-alt',
						'decreasing' => 'arrow-down-alt',
						'stable'     => 'minus',
					);
					$color_map = array(
						'increasing' => '#d63638',
						'decreasing' => '#00a32a',
						'stable'     => '#2271b1',
					);
					?>
					<span class="dashicons dashicons-<?php echo esc_attr( $icon_map[ $direction ] ); ?>" style="color: <?php echo esc_attr( $color_map[ $direction ] ); ?>;"></span>
					<?php echo esc_html( ucfirst( $direction ) ); ?>
				</div>
			</div>

			<!-- Confidence -->
			<div class="wp-mcp-ai-stat-box" style="padding: 15px; background: #f7f7f7; border-radius: 4px;">
				<div style="font-size: 12px; color: #666; margin-bottom: 5px;">
					<?php esc_html_e( 'Confidence', 'wp-mcp-ai' ); ?>
				</div>
				<div style="font-size: 16px; font-weight: 600;">
					<?php echo esc_html( isset( $trend_data['trend']['confidence'] ) ? absint( $trend_data['trend']['confidence'] ) : 0 ); ?>%
				</div>
			</div>

			<!-- Average Usage -->
			<div class="wp-mcp-ai-stat-box" style="padding: 15px; background: #f7f7f7; border-radius: 4px;">
				<div style="font-size: 12px; color: #666; margin-bottom: 5px;">
					<?php esc_html_e( 'Average Daily', 'wp-mcp-ai' ); ?>
				</div>
				<div style="font-size: 16px; font-weight: 600;">
					<?php echo esc_html( number_format_i18n( isset( $trend_data['statistics']['mean'] ) ? absint( $trend_data['statistics']['mean'] ) : 0 ) ); ?>
				</div>
			</div>

			<!-- Projected 7d -->
			<div class="wp-mcp-ai-stat-box" style="padding: 15px; background: #f7f7f7; border-radius: 4px;">
				<div style="font-size: 12px; color: #666; margin-bottom: 5px;">
					<?php esc_html_e( 'Projected (7d)', 'wp-mcp-ai' ); ?>
				</div>
				<div style="font-size: 16px; font-weight: 600;">
					<?php echo esc_html( number_format_i18n( isset( $trend_data['projected_7d'] ) ? absint( $trend_data['projected_7d'] ) : 0 ) ); ?>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			if (typeof Chart !== 'undefined') {
				var ctx = document.getElementById('wp-mcp-ai-analytics-trend-chart');
				if (ctx) {
					var dailyUsage = <?php echo wp_json_encode( $trend_data['daily_usage'] ); ?>;
					var trendInfo = <?php echo wp_json_encode( $trend_data['trend'] ); ?>;
					
					// Prepare data points for chart.
					var labels = [];
					var dataPoints = [];
					var trendLine = [];
					var dayIndex = 0;
					
					for (var date in dailyUsage) {
						labels.push(date);
						dataPoints.push(dailyUsage[date]);
						
						// Calculate trend line point: y = slope * x + intercept.
						var trendValue = trendInfo.slope * dayIndex + trendInfo.intercept;
						trendLine.push(Math.max(0, trendValue));
						dayIndex++;
					}
					
					new Chart(ctx.getContext('2d'), {
						type: 'line',
						data: {
							labels: labels,
							datasets: [
								{
									label: '<?php esc_attr_e( 'Actual Usage', 'wp-mcp-ai' ); ?>',
									data: dataPoints,
									borderColor: 'rgba(54, 162, 235, 1)',
									backgroundColor: 'rgba(54, 162, 235, 0.1)',
									fill: true,
									tension: 0.4,
									pointRadius: 3,
									pointHoverRadius: 5
								},
								{
									label: '<?php esc_attr_e( 'Trend Line', 'wp-mcp-ai' ); ?>',
									data: trendLine,
									borderColor: 'rgba(255, 99, 132, 1)',
									borderDash: [5, 5],
									borderWidth: 2,
									fill: false,
									pointRadius: 0
								}
							]
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: true,
									position: 'top'
								},
								title: {
									display: true,
									text: '<?php esc_attr_e( 'Usage Trend Analysis', 'wp-mcp-ai' ); ?>'
								},
								tooltip: {
									callbacks: {
										label: function(context) {
											var label = context.dataset.label || '';
											if (label) {
												label += ': ';
											}
											label += context.parsed.y.toLocaleString() + ' tokens';
											return label;
										}
									}
								}
							},
							scales: {
								y: {
									beginAtZero: true,
									ticks: {
										callback: function(value) {
											if (value >= 1000000) {
												return (value / 1000000).toFixed(1) + 'M';
											} else if (value >= 1000) {
												return (value / 1000).toFixed(1) + 'K';
											}
											return value;
										}
									}
								},
								x: {
									ticks: {
										maxRotation: 45,
										minRotation: 45
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
		<!-- No Data / Implementation Notice -->
		<div class="wp-mcp-ai-widget-notice" style="padding: 20px; background: #f7f7f7; border-left: 4px solid #2271b1; text-align: center;">
			<div style="margin-bottom: 10px;">
				<span class="dashicons dashicons-chart-line" style="font-size: 48px; color: #2271b1; opacity: 0.5;"></span>
			</div>
			<p style="margin: 0; font-weight: 600; margin-bottom: 8px;">
				<?php esc_html_e( 'Usage Trend', 'wp-mcp-ai' ); ?>
			</p>
			<p style="margin: 0; color: #666;">
				<?php esc_html_e( 'Usage is stable. No action required.', 'wp-mcp-ai' ); ?>
			</p>
			<p style="margin: 8px 0 0 0; font-size: 12px; color: #999;">
				<?php esc_html_e( 'Advanced forecasting is currently being implemented. Basic trend analysis will be available soon!', 'wp-mcp-ai' ); ?>
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
