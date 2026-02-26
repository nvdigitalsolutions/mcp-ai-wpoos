<?php
/**
 * Analytics Trends Widget Template
 *
 * Displays trend analysis with linear regression for token usage.
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
			<canvas id="wp-mcp-ai-analytics-trend-chart"></canvas>
		</div>

		<!-- Trend Statistics -->
		<div class="wp-mcp-ai-trend-stats" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 20px;">
			<!-- Direction -->
			<div class="wp-mcp-ai-stat-box" style="padding: 15px; background: #f7f7f7; border-radius: 4px;">
				<div style="font-size: 12px; color: #666; margin-bottom: 5px;">
					<?php esc_html_e( 'Trend Direction', 'mcp-ai-wpoos' ); ?>
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
					<?php esc_html_e( 'Confidence', 'mcp-ai-wpoos' ); ?>
				</div>
				<div style="font-size: 16px; font-weight: 600;">
					<?php echo esc_html( isset( $trend_data['trend']['confidence'] ) ? absint( $trend_data['trend']['confidence'] ) : 0 ); ?>%
				</div>
			</div>

			<!-- Average Usage -->
			<div class="wp-mcp-ai-stat-box" style="padding: 15px; background: #f7f7f7; border-radius: 4px;">
				<div style="font-size: 12px; color: #666; margin-bottom: 5px;">
					<?php esc_html_e( 'Average Daily', 'mcp-ai-wpoos' ); ?>
				</div>
				<div style="font-size: 16px; font-weight: 600;">
					<?php echo esc_html( number_format_i18n( isset( $trend_data['statistics']['mean'] ) ? absint( $trend_data['statistics']['mean'] ) : 0 ) ); ?>
				</div>
			</div>

			<!-- Projected 7d -->
			<div class="wp-mcp-ai-stat-box" style="padding: 15px; background: #f7f7f7; border-radius: 4px;">
				<div style="font-size: 12px; color: #666; margin-bottom: 5px;">
					<?php esc_html_e( 'Projected (7d)', 'mcp-ai-wpoos' ); ?>
				</div>
				<div style="font-size: 16px; font-weight: 600;">
					<?php echo esc_html( number_format_i18n( isset( $trend_data['projected_7d'] ) ? absint( $trend_data['projected_7d'] ) : 0 ) ); ?>
				</div>
			</div>
		</div>

		<?php
		wp_enqueue_script(
			'wp-mcp-ai-analytics-trends',
			WP_MCP_AI_URL . 'assets/js/admin/widgets/analytics-trends.js',
			array( 'jquery', 'chartjs' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-analytics-trends',
			'wpMcpAiTrendData',
			array(
				'dailyUsage' => $trend_data['daily_usage'],
				'trend'      => $trend_data['trend'],
				'labels'     => array(
					'actualUsage' => __( 'Actual Usage', 'mcp-ai-wpoos' ),
					'trendLine'   => __( 'Trend Line', 'mcp-ai-wpoos' ),
					'chartTitle'  => __( 'Usage Trend Analysis', 'mcp-ai-wpoos' ),
				),
			)
		);
		?>
	<?php else : ?>
		<!-- No Data / Implementation Notice -->
		<div class="wp-mcp-ai-widget-notice" style="padding: 20px; background: #f7f7f7; border-left: 4px solid #2271b1; text-align: center;">
			<div style="margin-bottom: 10px;">
				<span class="dashicons dashicons-chart-line" style="font-size: 48px; color: #2271b1; opacity: 0.5;"></span>
			</div>
			<p style="margin: 0; font-weight: 600; margin-bottom: 8px;">
				<?php esc_html_e( 'Usage Trend', 'mcp-ai-wpoos' ); ?>
			</p>
			<p style="margin: 0; color: #666;">
				<?php esc_html_e( 'Usage is stable. No action required.', 'mcp-ai-wpoos' ); ?>
			</p>
			<p style="margin: 8px 0 0 0; font-size: 12px; color: #999;">
				<?php esc_html_e( 'Advanced forecasting is currently being implemented. Basic trend analysis will be available soon!', 'mcp-ai-wpoos' ); ?>
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
