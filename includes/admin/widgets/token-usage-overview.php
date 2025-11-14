<?php
/**
 * Token Usage Overview Widget Template
 *
 * @package WP_MCP_AI
 * @var array $data Widget data containing usage statistics and chart data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_stats = isset( $data['current_stats'] ) ? $data['current_stats'] : array();
?>

<div class="wp-mcp-ai-widget-usage-overview">
	<!-- Quick Stats Grid -->
	<div class="wp-mcp-ai-stats-grid">
		<div class="wp-mcp-ai-stat-card">
			<div class="wp-mcp-ai-stat-value"><?php echo esc_html( number_format_i18n( $current_stats['today_tokens'] ?? 0 ) ); ?></div>
			<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Today', 'wp-mcp-ai' ); ?></div>
		</div>
		<div class="wp-mcp-ai-stat-card">
			<div class="wp-mcp-ai-stat-value"><?php echo esc_html( number_format_i18n( $current_stats['week_tokens'] ?? 0 ) ); ?></div>
			<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'This Week', 'wp-mcp-ai' ); ?></div>
		</div>
		<div class="wp-mcp-ai-stat-card">
			<div class="wp-mcp-ai-stat-value"><?php echo esc_html( number_format_i18n( $current_stats['month_tokens'] ?? 0 ) ); ?></div>
			<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'This Month', 'wp-mcp-ai' ); ?></div>
		</div>
		<div class="wp-mcp-ai-stat-card">
			<div class="wp-mcp-ai-stat-value"><?php echo esc_html( number_format_i18n( $current_stats['active_users'] ?? 0 ) ); ?></div>
			<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Active Users', 'wp-mcp-ai' ); ?></div>
		</div>
	</div>

	<!-- Chart Controls - Following SoC: Presentation separated from logic -->
	<div class="wp-mcp-ai-chart-controls" style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
		<div class="wp-mcp-ai-period-selector">
			<label for="wp-mcp-ai-usage-period" style="margin-right: 8px;"><?php esc_html_e( 'Period:', 'wp-mcp-ai' ); ?></label>
			<select id="wp-mcp-ai-usage-period" class="wp-mcp-ai-chart-period" data-chart-id="wp-mcp-ai-dashboard-usage-trend">
				<option value="1"><?php esc_html_e( 'Today', 'wp-mcp-ai' ); ?></option>
				<option value="7" selected><?php esc_html_e( '7 Days', 'wp-mcp-ai' ); ?></option>
				<option value="30"><?php esc_html_e( '30 Days', 'wp-mcp-ai' ); ?></option>
				<option value="90"><?php esc_html_e( '90 Days', 'wp-mcp-ai' ); ?></option>
			</select>
		</div>
		<div class="wp-mcp-ai-chart-actions">
			<button type="button" class="button wp-mcp-ai-export-chart" data-chart-id="wp-mcp-ai-dashboard-usage-trend" title="<?php esc_attr_e( 'Export as PNG', 'wp-mcp-ai' ); ?>">
				<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
				<?php esc_html_e( 'Export', 'wp-mcp-ai' ); ?>
			</button>
		</div>
	</div>

	<!-- Usage Trend Chart -->
	<div class="wp-mcp-ai-chart-container" style="margin-top: 15px; position: relative;">
		<canvas id="wp-mcp-ai-dashboard-usage-trend" width="400" height="200"></canvas>
		<div class="wp-mcp-ai-chart-loading" style="display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
			<span class="spinner is-active"></span>
		</div>
	</div>

	<!-- Quick Actions -->
	<div class="wp-mcp-ai-widget-actions" style="margin-top: 15px; text-align: right;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'View Full Report', 'wp-mcp-ai' ); ?>
		</a>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	// Initialize usage trend chart with enhanced features (following SoC principles).
	if (typeof Chart !== 'undefined') {
		var ctx = document.getElementById('wp-mcp-ai-dashboard-usage-trend');
		if (ctx) {
			var chartData = <?php echo wp_json_encode( $data['trend'] ?? array() ); ?>;
			
			// Create chart instance with zoom/pan plugins and enhanced tooltips.
			var chart = new Chart(ctx.getContext('2d'), {
				type: 'line',
				data: chartData,
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: {
						mode: 'index',
						intersect: false
					},
					plugins: {
						legend: {
							display: false
						},
						title: {
							display: true,
							text: '<?php esc_attr_e( '7-Day Token Usage Trend', 'wp-mcp-ai' ); ?>'
						},
						tooltip: {
							enabled: true,
							backgroundColor: 'rgba(0, 0, 0, 0.8)',
							titleColor: '#fff',
							bodyColor: '#fff',
							borderColor: '#2271b1',
							borderWidth: 1,
							padding: 12,
							displayColors: false,
							callbacks: {
								title: function(tooltipItems) {
									return tooltipItems[0].label;
								},
								label: function(context) {
									var label = context.dataset.label || '';
									if (label) {
										label += ': ';
									}
									label += new Intl.NumberFormat().format(context.parsed.y) + ' tokens';
									return label;
								},
								afterLabel: function(context) {
									// Add percentage of max if available.
									var dataset = context.dataset.data;
									var maxValue = Math.max.apply(null, dataset);
									var percentage = ((context.parsed.y / maxValue) * 100).toFixed(1);
									return 'Peak: ' + percentage + '%';
								}
							}
						},
						// Note: Zoom plugin would be loaded here if Chart.js zoom plugin is available.
						// For now, users can use browser zoom or we can add it in future enhancement.
					},
					scales: {
						y: {
							beginAtZero: true,
							title: {
								display: true,
								text: '<?php esc_attr_e( 'Tokens', 'wp-mcp-ai' ); ?>'
							},
							ticks: {
								callback: function(value) {
									// Format large numbers with K/M suffixes.
									if (value >= 1000000) {
										return (value / 1000000).toFixed(1) + 'M';
									}
									if (value >= 1000) {
										return (value / 1000).toFixed(1) + 'K';
									}
									return value;
								}
							}
						},
						x: {
							grid: {
								display: false
							}
						}
					}
				}
			});
			
			// Register chart with Analytics Dashboard for export/period change functionality.
			if (typeof window.WpMcpAiAnalyticsDashboard !== 'undefined') {
				window.WpMcpAiAnalyticsDashboard.registerChart('wp-mcp-ai-dashboard-usage-trend', chart);
			}
		}
	}
});
</script>
