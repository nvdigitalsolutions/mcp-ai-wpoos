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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
						<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos' ); ?></th>
						<?php if ( 0 === $user_id ) : ?>
							<th><?php esc_html_e( 'User', 'mcp-ai-wpoos' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Tokens', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Expected', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Z-Score', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Severity', 'mcp-ai-wpoos' ); ?></th>
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
			<canvas id="wp-mcp-ai-anomaly-scatter-chart"></canvas>
		</div>

		<?php
		wp_enqueue_script(
			'wp-mcp-ai-analytics-anomalies',
			WP_MCP_AI_URL . 'assets/js/admin/widgets/analytics-anomalies.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-analytics-anomalies',
			'wpMcpAiAnomalyData',
			array(
				'anomalies'       => $anomalies,
				'threshold'       => $threshold,
				'severityColors'  => $severity_colors,
				'labels'          => array(
					'anomalies'          => __( 'Anomalies', 'mcp-ai-wpoos' ),
					'thresholdPositive'  => __( 'Threshold (±3σ)', 'mcp-ai-wpoos' ),
					'thresholdNegative'  => __( 'Threshold (-3σ)', 'mcp-ai-wpoos' ),
					'chartTitle'         => __( 'Anomaly Detection (Z-Score Analysis)', 'mcp-ai-wpoos' ),
					'yAxisTitle'         => __( 'Z-Score (Standard Deviations)', 'mcp-ai-wpoos' ),
					'xAxisTitle'         => __( 'Anomaly Index', 'mcp-ai-wpoos' ),
				),
			)
		);
		?>
	<?php else : ?>
		<!-- No Anomalies -->
		<div class="wp-mcp-ai-widget-notice" style="padding: 20px; background: #f7f7f7; border-left: 4px solid #00a32a; text-align: center;">
			<div style="margin-bottom: 10px;">
				<span class="dashicons dashicons-yes-alt" style="font-size: 48px; color: #00a32a; opacity: 0.5;"></span>
			</div>
			<p style="margin: 0; font-weight: 600; color: #00a32a;">
				<?php esc_html_e( 'No Anomalies Detected', 'mcp-ai-wpoos' ); ?>
			</p>
			<p style="margin: 8px 0 0 0; color: #666;">
				<?php
				printf(
					/* translators: %s: Z-score threshold */
					esc_html__( 'All usage patterns are within normal range (Z-score < ±%s)', 'mcp-ai-wpoos' ),
					esc_html( number_format_i18n( $threshold, 1 ) )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<div class="wp-mcp-ai-widget-actions" style="margin-top: 15px; text-align: right;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=token_manager' ) ); ?>" class="button">
			<?php esc_html_e( 'View All Analytics', 'mcp-ai-wpoos' ); ?>
		</a>
	</div>
</div>
