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

	<!-- Gauge Chart for Current Usage Percentage -->
	<div class="wp-mcp-ai-gauge-container" style="margin-top: 20px;">
		<div style="display: flex; align-items: center; gap: 20px;">
			<div style="flex: 0 0 200px; height: 120px; position: relative;">
				<?php
				// Following SoC: Pass data to canvas via data attribute, not inline JS.
				$gauge_data = isset( $data['gauge'] ) ? $data['gauge'] : array();
				?>
				<canvas 
					id="wp-mcp-ai-dashboard-usage-gauge" 
					data-gauge-data="<?php echo esc_attr( wp_json_encode( $gauge_data ) ); ?>">
				</canvas>
			</div>
			<div style="flex: 1;">
				<?php
				// Presentation only - display pre-calculated values from data layer.
				$percentage = isset( $gauge_data['percentage'] ) ? $gauge_data['percentage'] : 0;
				$usage      = isset( $gauge_data['usage'] ) ? $gauge_data['usage'] : 0;
				$limit      = isset( $gauge_data['limit'] ) ? $gauge_data['limit'] : 0;
				?>
				<div style="font-size: 24px; font-weight: bold; margin-bottom: 5px;">
					<?php echo esc_html( number_format( $percentage, 1 ) ); ?>%
				</div>
				<div style="font-size: 14px; color: #666; margin-bottom: 10px;">
					<?php esc_html_e( 'of daily limit used', 'wp-mcp-ai' ); ?>
				</div>
				<div style="font-size: 12px; color: #999;">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: Current usage, 2: Total limit */
							__( '%1$s / %2$s tokens', 'wp-mcp-ai' ),
							number_format_i18n( $usage ),
							number_format_i18n( $limit )
						)
					);
					?>
				</div>
			</div>
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
		<?php
		// Following SoC: Pass data to canvas via data attribute, chart init happens in JS.
		?>
		<canvas 
			id="wp-mcp-ai-dashboard-usage-trend" 
			width="400" 
			height="200"
			data-chart-data="<?php echo esc_attr( wp_json_encode( $data['trend'] ?? array() ) ); ?>">
		</canvas>
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
