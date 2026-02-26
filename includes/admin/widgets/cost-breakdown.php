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

// Get cost data from Cost Tracking Service (SoC - presentation layer gets data from service).
if ( ! isset( $data ) || empty( $data ) ) {
	$cost_summary = WP_MCP_AI_Cost_Tracking_Service::get_dashboard_cost_summary( 7 );
	$data         = $cost_summary;
}

$total_cost   = isset( $data['total_cost'] ) ? $data['total_cost'] : 0.0;
$total_tokens = isset( $data['total_tokens'] ) ? $data['total_tokens'] : 0;
$by_provider  = isset( $data['by_provider'] ) ? $data['by_provider'] : array();
$by_model     = isset( $data['by_model'] ) ? $data['by_model'] : array();
$period_start = isset( $data['period_start'] ) ? $data['period_start'] : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
$period_end   = isset( $data['period_end'] ) ? $data['period_end'] : gmdate( 'Y-m-d' );
?>

<div class="wp-mcp-ai-widget-cost-breakdown">
	<!-- Cost Summary -->
	<div class="wp-mcp-ai-cost-summary">
		<div class="wp-mcp-ai-cost-total">
			<span class="wp-mcp-ai-cost-amount">
				<?php echo esc_html( WP_MCP_AI_Cost_Calculator::format_cost( $total_cost ) ); ?>
			</span>
			<span class="wp-mcp-ai-cost-period">
				<?php
				printf(
					/* translators: %1$s: start date, %2$s: end date */
					esc_html__( '%1$s to %2$s', 'mcp-ai-wpoos' ),
					esc_html( gmdate( 'M j', strtotime( $period_start ) ) ),
					esc_html( gmdate( 'M j', strtotime( $period_end ) ) )
				);
				?>
			</span>
		</div>
		<?php if ( $total_tokens > 0 ) : ?>
			<div class="wp-mcp-ai-cost-tokens" style="margin-top: 5px; font-size: 12px; color: #fff;">
				<?php
				printf(
					/* translators: %s: total tokens */
					esc_html__( '%s total tokens', 'mcp-ai-wpoos' ),
					esc_html( number_format_i18n( $total_tokens ) )
				);
				?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Cost by Provider Chart -->
	<?php if ( ! empty( $by_provider ) ) : ?>
		<div class="wp-mcp-ai-chart-container" style="margin-top: 20px;">
			<canvas id="wp-mcp-ai-dashboard-cost-breakdown"></canvas>
		</div>

		<?php
		wp_enqueue_script(
			'wp-mcp-ai-cost-breakdown',
			WP_MCP_AI_URL . 'assets/js/admin/widgets/cost-breakdown.js',
			array( 'jquery', 'chartjs' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-cost-breakdown',
			'wpMcpAiCostData',
			array(
				'providers' => array_keys( $by_provider ),
				'costs'     => array_values( $by_provider ),
				'labels'    => array(
					'chartTitle' => __( 'Cost by Provider', 'mcp-ai-wpoos' ),
				),
			)
		);
		?>

		<!-- AI Cost Breakdown by Model -->
		<?php if ( ! empty( $by_model ) ) : ?>
			<div class="wp-mcp-ai-model-breakdown" style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
				<h4 style="margin: 0 0 10px 0; font-size: 13px; font-weight: 600;">
					<?php esc_html_e( 'Cost Breakdown by Model', 'mcp-ai-wpoos' ); ?>
				</h4>
				<table class="widefat" style="font-size: 12px;">
					<thead>
						<tr>
							<th style="padding: 6px 8px;"><?php esc_html_e( 'Provider', 'mcp-ai-wpoos' ); ?></th>
							<th style="padding: 6px 8px;"><?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?></th>
							<th style="padding: 6px 8px; text-align: right;"><?php esc_html_e( 'Tokens', 'mcp-ai-wpoos' ); ?></th>
							<th style="padding: 6px 8px; text-align: right;"><?php esc_html_e( 'Cost', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Sort by cost descending.
						uasort(
							$by_model,
							function ( $a, $b ) {
								return $b['total_cost'] <=> $a['total_cost'];
							}
						);

						// Show top 5 models.
						$count = 0;
						foreach ( $by_model as $model_data ) :
							if ( ++$count > 5 ) {
								break;
							}
							?>
							<tr>
								<td style="padding: 6px 8px;">
									<?php echo esc_html( ucfirst( $model_data['provider'] ) ); ?>
								</td>
								<td style="padding: 6px 8px;">
									<?php echo esc_html( $model_data['model'] ); ?>
								</td>
								<td style="padding: 6px 8px; text-align: right;">
									<?php echo esc_html( number_format_i18n( $model_data['total_tokens'] ) ); ?>
								</td>
								<td style="padding: 6px 8px; text-align: right; font-weight: 600;">
									<?php echo esc_html( WP_MCP_AI_Cost_Calculator::format_cost( $model_data['total_cost'] ) ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php if ( count( $by_model ) > 5 ) : ?>
							<tr>
								<td colspan="4" style="padding: 6px 8px; text-align: center; font-style: italic; color: #666;">
									<?php
									printf(
										/* translators: %d: number of additional models */
										esc_html__( '+ %d more models', 'mcp-ai-wpoos' ),
										count( $by_model ) - 5
									);
									?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<div class="wp-mcp-ai-widget-notice" style="margin-top: 20px; padding: 15px; background: #f7f7f7; border-left: 4px solid #2271b1;">
			<p style="margin: 0;">
				<?php
				if ( $total_cost > 0 ) {
					printf(
						/* translators: %s: total cost */
						esc_html__( 'Estimated cost: %s (based on average pricing)', 'mcp-ai-wpoos' ),
						esc_html( WP_MCP_AI_Cost_Calculator::format_cost( $total_cost ) )
					);
				} else {
					esc_html_e( 'No token usage recorded in this period.', 'mcp-ai-wpoos' );
				}
				?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<div class="wp-mcp-ai-widget-actions" style="margin-top: 15px; text-align: right;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager&view=per_site' ) ); ?>" class="button">
			<?php esc_html_e( 'View Full Report', 'mcp-ai-wpoos' ); ?>
		</a>
	</div>
</div>
