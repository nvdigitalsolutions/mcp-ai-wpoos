<?php
/**
 * Usage Forecast Widget Template
 *
 * @package WP_MCP_AI
 * @var array $data Widget data containing forecast information.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$projected_usage = isset( $data['projected_usage'] ) ? $data['projected_usage'] : 0;
$projected_date  = isset( $data['projected_date'] ) ? $data['projected_date'] : gmdate( 'Y-m-d', strtotime( '+7 days' ) );
$confidence      = isset( $data['confidence'] ) ? $data['confidence'] : 0;
$trend           = isset( $data['trend'] ) ? $data['trend'] : 'stable';

// Determine trend icon and color.
$trend_icon  = 'minus';
$trend_color = '#666';

if ( 'increasing' === $trend ) {
	$trend_icon  = 'arrow-up';
	$trend_color = '#d63638';
} elseif ( 'decreasing' === $trend ) {
	$trend_icon  = 'arrow-down';
	$trend_color = '#00a32a';
}
?>

<div class="wp-mcp-ai-widget-usage-forecast">
	<?php if ( $projected_usage > 0 || $confidence > 0 ) : ?>
		<!-- Forecast Summary -->
		<div class="wp-mcp-ai-forecast-summary">
			<div class="wp-mcp-ai-forecast-value">
				<span class="wp-mcp-ai-forecast-amount"><?php echo esc_html( number_format_i18n( $projected_usage ) ); ?></span>
				<span class="dashicons dashicons-<?php echo esc_attr( $trend_icon ); ?>" style="color: <?php echo esc_attr( $trend_color ); ?>; font-size: 20px; vertical-align: middle;"></span>
			</div>
			<div class="wp-mcp-ai-forecast-label">
				<?php
				printf(
					/* translators: %s: forecast date */
					esc_html__( 'Projected for %s', 'wp-mcp-ai' ),
					esc_html( gmdate( 'M j, Y', strtotime( $projected_date ) ) )
				);
				?>
			</div>
			<?php if ( $confidence > 0 ) : ?>
				<div class="wp-mcp-ai-forecast-confidence">
					<?php
					printf(
						/* translators: %d: confidence percentage */
						esc_html__( 'Confidence: %d%%', 'wp-mcp-ai' ),
						absint( $confidence )
					);
					?>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- Trend Indicator -->
	<div class="wp-mcp-ai-forecast-trend" style="margin-top: 20px; padding: 15px; background: #f7f7f7; border-radius: 4px;">
		<div class="wp-mcp-ai-trend-label" style="font-weight: 600; margin-bottom: 8px;">
			<?php esc_html_e( 'Usage Trend', 'wp-mcp-ai' ); ?>
		</div>
		<div class="wp-mcp-ai-trend-status">
			<?php
			switch ( $trend ) {
				case 'increasing':
					?>
					<span class="dashicons dashicons-arrow-up-alt" style="color: <?php echo esc_attr( $trend_color ); ?>;"></span>
					<?php esc_html_e( 'Usage is trending upward. Consider reviewing tier limits.', 'wp-mcp-ai' ); ?>
					<?php
					break;
				case 'decreasing':
					?>
					<span class="dashicons dashicons-arrow-down-alt" style="color: <?php echo esc_attr( $trend_color ); ?>;"></span>
					<?php esc_html_e( 'Usage is trending downward. Current capacity is sufficient.', 'wp-mcp-ai' ); ?>
					<?php
					break;
				default:
					?>
					<span class="dashicons dashicons-minus" style="color: <?php echo esc_attr( $trend_color ); ?>;"></span>
					<?php esc_html_e( 'Usage is stable. No action required.', 'wp-mcp-ai' ); ?>
					<?php
					break;
			}
			?>
		</div>
	</div>

	<!-- Forecast Implementation Notice -->
	<?php if ( 0 === $projected_usage && 0 === $confidence ) : ?>
		<div class="wp-mcp-ai-widget-notice" style="margin-top: 20px; padding: 15px; background: #f7f7f7; border-left: 4px solid #2271b1;">
			<p style="margin: 0;">
				<?php esc_html_e( 'Advanced forecasting is currently being implemented. Basic trend analysis will be available soon!', 'wp-mcp-ai' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<div class="wp-mcp-ai-widget-actions" style="margin-top: 15px; text-align: right;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ); ?>" class="button">
			<?php esc_html_e( 'View Analytics', 'wp-mcp-ai' ); ?>
		</a>
	</div>
</div>
