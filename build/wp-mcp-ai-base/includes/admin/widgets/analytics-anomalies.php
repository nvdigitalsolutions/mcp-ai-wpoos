<?php
/**
 * Analytics Anomalies Widget Template
 *
 * Displays anomaly detection with Z-score analysis.
 *
 * @package WP_MCP_AI
 * @var array $data Widget data containing anomaly information.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id   = isset( $data['user_id'] ) ? absint( $data['user_id'] ) : 0;
$threshold = isset( $data['threshold'] ) ? floatval( $data['threshold'] ) : 3.0;

// Get anomaly data from Analytics Engine if available.
$has_analytics = class_exists( 'WP_MCP_AI_Analytics_Engine' );
$anomalies     = array();

if ( $has_analytics ) {
	if ( $user_id > 0 ) {
		// Single user anomalies.
		$anomalies = WP_MCP_AI_Analytics_Engine::detect_anomalies( $user_id, $threshold );
	} else {
		// Site-wide anomalies (limited to 10 most recent).
		global $wpdb;
		$meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 20",
				$meta_key
			)
		);

		$all_anomalies = array();
		foreach ( $user_ids as $uid ) {
			$user_anomalies = WP_MCP_AI_Analytics_Engine::detect_anomalies( $uid, $threshold );
			if ( ! empty( $user_anomalies ) ) {
				foreach ( $user_anomalies as $anomaly ) {
					$anomaly['user_id'] = $uid;
					$all_anomalies[]    = $anomaly;
				}
			}
		}

		// Sort by date (most recent first) and limit to 10.
		usort(
			$all_anomalies,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		$anomalies = array_slice( $all_anomalies, 0, 10 );
	}
}

// Severity colors.
$severity_colors = array(
	'low'      => '#00a32a',
	'medium'   => '#dba617',
	'high'     => '#d63638',
	'critical' => '#8b0000',
);
?>

<div class="wp-mcp-ai-widget-analytics-anomalies">
	<?php if ( $has_analytics && ! empty( $anomalies ) ) : ?>
		<!-- Anomalies List -->
		<div class="wp-mcp-ai-anomalies-list">
			<table class="widefat" style="margin-top: 10px; background: white;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wp-mcp-ai' ); ?></th>
						<?php if ( 0 === $user_id ) : ?>
							<th><?php esc_html_e( 'User', 'wp-mcp-ai' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Tokens', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Expected', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Z-Score', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Severity', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $anomalies as $anomaly ) : ?>
						<tr>
							<td><?php echo esc_html( gmdate( 'M j, Y', strtotime( $anomaly['date'] ) ) ); ?></td>
							<?php if ( 0 === $user_id && isset( $anomaly['user_id'] ) ) : ?>
								<td>
									<?php
									$user = get_userdata( $anomaly['user_id'] );
									echo esc_html( $user ? $user->display_name : 'User #' . $anomaly['user_id'] );
									?>
								</td>
							<?php endif; ?>
							<td><?php echo esc_html( number_format_i18n( $anomaly['tokens'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $anomaly['expected_value'] ) ); ?></td>
							<td>
								<strong><?php echo esc_html( $anomaly['z_score'] ); ?></strong>
							</td>
							<td>
								<span class="wp-mcp-ai-severity-badge" style="padding: 4px 8px; border-radius: 3px; background: <?php echo esc_attr( $severity_colors[ $anomaly['severity'] ] ); ?>; color: white; font-size: 11px; text-transform: uppercase;">
									<?php echo esc_html( $anomaly['severity'] ); ?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<!-- Anomaly Chart -->
		<div class="wp-mcp-ai-chart-container" style="margin-top: 20px;">
			<canvas id="wp-mcp-ai-anomaly-scatter-chart" width="400" height="250"></canvas>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			if (typeof Chart !== 'undefined') {
				var ctx = document.getElementById('wp-mcp-ai-anomaly-scatter-chart');
				if (ctx) {
					var anomalies = <?php echo wp_json_encode( $anomalies ); ?>;
					
					// Prepare scatter plot data
					var scatterData = anomalies.map(function(anomaly, index) {
						return {
							x: index,
							y: parseFloat(anomaly.z_score),
							date: anomaly.date,
							tokens: anomaly.tokens,
							severity: anomaly.severity
						};
					});
					
					// Color points by severity
					var pointColors = scatterData.map(function(point) {
						var severityMap = <?php echo wp_json_encode( $severity_colors ); ?>;
						return severityMap[point.severity] || '#666';
					});
					
					new Chart(ctx.getContext('2d'), {
						type: 'scatter',
						data: {
							datasets: [{
								label: '<?php esc_attr_e( 'Anomalies', 'wp-mcp-ai' ); ?>',
								data: scatterData,
								backgroundColor: pointColors,
								borderColor: pointColors,
								pointRadius: 6,
								pointHoverRadius: 8
							}, {
								label: '<?php esc_attr_e( 'Threshold (±3σ)', 'wp-mcp-ai' ); ?>',
								data: [
									{x: 0, y: <?php echo esc_js( $threshold ); ?>},
									{x: scatterData.length - 1, y: <?php echo esc_js( $threshold ); ?>}
								],
								borderColor: 'rgba(255, 99, 132, 0.5)',
								borderDash: [5, 5],
								borderWidth: 2,
								pointRadius: 0,
								showLine: true,
								fill: false
							}, {
								label: '<?php esc_attr_e( 'Threshold (-3σ)', 'wp-mcp-ai' ); ?>',
								data: [
									{x: 0, y: <?php echo esc_js( -$threshold ); ?>},
									{x: scatterData.length - 1, y: <?php echo esc_js( -$threshold ); ?>}
								],
								borderColor: 'rgba(255, 99, 132, 0.5)',
								borderDash: [5, 5],
								borderWidth: 2,
								pointRadius: 0,
								showLine: true,
								fill: false
							}]
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
									text: '<?php esc_attr_e( 'Anomaly Detection (Z-Score Analysis)', 'wp-mcp-ai' ); ?>'
								},
								tooltip: {
									callbacks: {
										label: function(context) {
											if (context.datasetIndex === 0) {
												var point = context.raw;
												return [
													'Date: ' + point.date,
													'Z-Score: ' + point.y.toFixed(2),
													'Tokens: ' + point.tokens.toLocaleString(),
													'Severity: ' + point.severity
												];
											}
											return '';
										}
									}
								}
							},
							scales: {
								y: {
									title: {
										display: true,
										text: '<?php esc_attr_e( 'Z-Score (Standard Deviations)', 'wp-mcp-ai' ); ?>'
									}
								},
								x: {
									title: {
										display: true,
										text: '<?php esc_attr_e( 'Anomaly Index', 'wp-mcp-ai' ); ?>'
									},
									ticks: {
										display: false
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
		<!-- No Anomalies -->
		<div class="wp-mcp-ai-widget-notice" style="padding: 20px; background: #f7f7f7; border-left: 4px solid #00a32a; text-align: center;">
			<div style="margin-bottom: 10px;">
				<span class="dashicons dashicons-yes-alt" style="font-size: 48px; color: #00a32a; opacity: 0.5;"></span>
			</div>
			<p style="margin: 0; font-weight: 600; color: #00a32a;">
				<?php esc_html_e( 'No Anomalies Detected', 'wp-mcp-ai' ); ?>
			</p>
			<p style="margin: 8px 0 0 0; color: #666;">
				<?php
				printf(
					/* translators: %s: Z-score threshold */
					esc_html__( 'All usage patterns are within normal range (Z-score < ±%s)', 'wp-mcp-ai' ),
					esc_html( number_format_i18n( $threshold, 1 ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<div class="wp-mcp-ai-widget-actions" style="margin-top: 15px; text-align: right;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ); ?>" class="button">
			<?php esc_html_e( 'View All Analytics', 'wp-mcp-ai' ); ?>
		</a>
	</div>
</div>
