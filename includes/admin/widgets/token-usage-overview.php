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

	<!-- Usage Trend Chart -->
	<div class="wp-mcp-ai-chart-container" style="margin-top: 20px;">
		<canvas id="wp-mcp-ai-dashboard-usage-trend" width="400" height="200"></canvas>
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
	// Initialize usage trend chart.
	if (typeof Chart !== 'undefined') {
		var ctx = document.getElementById('wp-mcp-ai-dashboard-usage-trend');
		if (ctx) {
			var chartData = <?php echo wp_json_encode( $data['trend'] ?? array() ); ?>;
			
			new Chart(ctx.getContext('2d'), {
				type: 'line',
				data: chartData,
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: {
							display: false
						},
						title: {
							display: true,
							text: '<?php esc_attr_e( '7-Day Token Usage Trend', 'wp-mcp-ai' ); ?>'
						}
					},
					scales: {
						y: {
							beginAtZero: true,
							title: {
								display: true,
								text: '<?php esc_attr_e( 'Tokens', 'wp-mcp-ai' ); ?>'
							}
						}
					}
				}
			});
		}
	}
});
</script>
