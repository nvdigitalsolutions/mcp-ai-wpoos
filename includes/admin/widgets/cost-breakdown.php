<?php
/**
 * Cost Breakdown Widget Template
 *
 * @package WP_MCP_AI
 * @var array $data Widget data containing cost information.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$total_cost   = isset( $data['total_cost'] ) ? $data['total_cost'] : 0.0;
$by_provider  = isset( $data['by_provider'] ) ? $data['by_provider'] : array();
$period_start = isset( $data['period_start'] ) ? $data['period_start'] : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
$period_end   = isset( $data['period_end'] ) ? $data['period_end'] : gmdate( 'Y-m-d' );
?>

<div class="wp-mcp-ai-widget-cost-breakdown">
	<!-- Cost Summary -->
	<div class="wp-mcp-ai-cost-summary">
		<div class="wp-mcp-ai-cost-total">
			<span class="wp-mcp-ai-cost-amount">$<?php echo esc_html( number_format( $total_cost, 2 ) ); ?></span>
			<span class="wp-mcp-ai-cost-period">
				<?php
				printf(
					/* translators: %1$s: start date, %2$s: end date */
					esc_html__( '%1$s to %2$s', 'wp-mcp-ai' ),
					esc_html( gmdate( 'M j', strtotime( $period_start ) ) ),
					esc_html( gmdate( 'M j', strtotime( $period_end ) ) )
				);
				?>
			</span>
		</div>
	</div>

	<!-- Cost by Provider Chart -->
	<?php if ( ! empty( $by_provider ) ) : ?>
		<div class="wp-mcp-ai-chart-container" style="margin-top: 20px;">
			<canvas id="wp-mcp-ai-dashboard-cost-breakdown" width="400" height="200"></canvas>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			if (typeof Chart !== 'undefined') {
				var ctx = document.getElementById('wp-mcp-ai-dashboard-cost-breakdown');
				if (ctx) {
					var providers = <?php echo wp_json_encode( array_keys( $by_provider ) ); ?>;
					var costs = <?php echo wp_json_encode( array_values( $by_provider ) ); ?>;
					
					new Chart(ctx.getContext('2d'), {
						type: 'doughnut',
						data: {
							labels: providers,
							datasets: [{
								data: costs,
								backgroundColor: [
									'rgba(54, 162, 235, 0.8)',
									'rgba(75, 192, 192, 0.8)',
									'rgba(153, 102, 255, 0.8)',
									'rgba(255, 159, 64, 0.8)'
								],
								borderColor: [
									'rgba(54, 162, 235, 1)',
									'rgba(75, 192, 192, 1)',
									'rgba(153, 102, 255, 1)',
									'rgba(255, 159, 64, 1)'
								],
								borderWidth: 1
							}]
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: true,
									position: 'bottom'
								},
								title: {
									display: true,
									text: '<?php esc_attr_e( 'Cost by Provider', 'wp-mcp-ai' ); ?>'
								}
							}
						}
					});
				}
			}
		});
		</script>
	<?php else : ?>
		<div class="wp-mcp-ai-widget-notice" style="margin-top: 20px; padding: 15px; background: #f7f7f7; border-left: 4px solid #2271b1;">
			<p style="margin: 0;">
				<?php esc_html_e( 'Cost tracking is currently being implemented. Check back soon for detailed cost breakdowns!', 'wp-mcp-ai' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<div class="wp-mcp-ai-widget-actions" style="margin-top: 15px; text-align: right;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager&view=per_site' ) ); ?>" class="button">
			<?php esc_html_e( 'View Details', 'wp-mcp-ai' ); ?>
		</a>
	</div>
</div>
