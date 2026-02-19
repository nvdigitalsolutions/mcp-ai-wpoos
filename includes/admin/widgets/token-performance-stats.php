<?php
/**
 * Token Performance Stats Widget Template
 *
 * Displays database optimization and caching performance metrics.
 *
 * @package WP_MCP_AI
 * @var array $data Widget data containing performance statistics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Enqueue widget styles.
wp_enqueue_style(
	'wp-mcp-ai-token-performance-stats',
	WP_MCP_AI_URL . 'assets/css/admin/widgets/token-performance-stats.css',
	array(),
	WP_MCP_AI_VERSION
);

$stats    = isset( $data['stats'] ) ? $data['stats'] : array();
$analysis = isset( $data['analysis'] ) ? $data['analysis'] : array();
?>

<div class="wp-mcp-ai-widget-performance-stats">
	<h3><?php esc_html_e( 'Performance Optimization', 'mcp-ai-wpoos' ); ?></h3>

	<!-- Optimization Status -->
	<div class="wp-mcp-ai-status-section">
		<h4><?php esc_html_e( 'Database Optimizations', 'mcp-ai-wpoos' ); ?></h4>
		<div class="wp-mcp-ai-status-grid">
			<div class="wp-mcp-ai-status-item">
				<span class="wp-mcp-ai-status-label"><?php esc_html_e( 'Status:', 'mcp-ai-wpoos' ); ?></span>
				<?php if ( ! empty( $stats['optimizations_active'] ) ) : ?>
					<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-active">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'Active', 'mcp-ai-wpoos' ); ?>
					</span>
				<?php else : ?>
					<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-inactive">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'Inactive', 'mcp-ai-wpoos' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="wp-mcp-ai-status-item">
				<span class="wp-mcp-ai-status-label"><?php esc_html_e( 'Schema Version:', 'mcp-ai-wpoos' ); ?></span>
				<span class="wp-mcp-ai-status-value"><?php echo esc_html( $stats['schema_version'] ?? 0 ); ?></span>
			</div>

			<div class="wp-mcp-ai-status-item">
				<span class="wp-mcp-ai-status-label"><?php esc_html_e( 'Token Indexes:', 'mcp-ai-wpoos' ); ?></span>
				<span class="wp-mcp-ai-status-value"><?php echo esc_html( $stats['wp_mcp_ai_indexes'] ?? 0 ); ?></span>
			</div>
		</div>
	</div>

	<!-- Index Status -->
	<div class="wp-mcp-ai-index-section">
		<h4><?php esc_html_e( 'Index Status', 'mcp-ai-wpoos' ); ?></h4>
		<table class="wp-mcp-ai-stats-table">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Tier Index:', 'mcp-ai-wpoos' ); ?></td>
					<td>
						<?php if ( ! empty( $stats['tier_index_exists'] ) ) : ?>
							<span class="wp-mcp-ai-badge-success">
								<span class="dashicons dashicons-yes"></span>
								<?php esc_html_e( 'Exists', 'mcp-ai-wpoos' ); ?>
							</span>
						<?php else : ?>
							<span class="wp-mcp-ai-badge-warning">
								<span class="dashicons dashicons-no"></span>
								<?php esc_html_e( 'Missing', 'mcp-ai-wpoos' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Usage Index:', 'mcp-ai-wpoos' ); ?></td>
					<td>
						<?php if ( ! empty( $stats['usage_index_exists'] ) ) : ?>
							<span class="wp-mcp-ai-badge-success">
								<span class="dashicons dashicons-yes"></span>
								<?php esc_html_e( 'Exists', 'mcp-ai-wpoos' ); ?>
							</span>
						<?php else : ?>
							<span class="wp-mcp-ai-badge-warning">
								<span class="dashicons dashicons-no"></span>
								<?php esc_html_e( 'Missing', 'mcp-ai-wpoos' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Data Volume -->
	<div class="wp-mcp-ai-volume-section">
		<h4><?php esc_html_e( 'Data Volume', 'mcp-ai-wpoos' ); ?></h4>
		<table class="wp-mcp-ai-stats-table">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Tier Records:', 'mcp-ai-wpoos' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['tier_records'] ?? 0 ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Usage Records:', 'mcp-ai-wpoos' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['usage_records'] ?? 0 ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Total Indexes:', 'mcp-ai-wpoos' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['total_indexes'] ?? 0 ) ); ?></strong></td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Query Performance -->
	<?php if ( ! empty( $analysis['tier_lookup'] ) || ! empty( $analysis['usage_lookup'] ) ) : ?>
		<div class="wp-mcp-ai-performance-section">
			<h4><?php esc_html_e( 'Query Performance', 'mcp-ai-wpoos' ); ?></h4>
			<table class="wp-mcp-ai-stats-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Query Type', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Index Used', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Rows Scanned', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $analysis['tier_lookup'] ) ) : ?>
						<tr>
							<td><?php esc_html_e( 'Tier Lookup', 'mcp-ai-wpoos' ); ?></td>
							<td>
								<?php if ( ! empty( $analysis['tier_lookup']['using_index'] ) ) : ?>
									<span class="wp-mcp-ai-badge-success">
										<?php echo esc_html( $analysis['tier_lookup']['index_name'] ?? 'yes' ); ?>
									</span>
								<?php else : ?>
									<span class="wp-mcp-ai-badge-warning">
										<?php esc_html_e( 'No index', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $analysis['tier_lookup']['rows'] ?? 0 ) ); ?></td>
						</tr>
					<?php endif; ?>

					<?php if ( ! empty( $analysis['usage_lookup'] ) ) : ?>
						<tr>
							<td><?php esc_html_e( 'Usage Lookup', 'mcp-ai-wpoos' ); ?></td>
							<td>
								<?php if ( ! empty( $analysis['usage_lookup']['using_index'] ) ) : ?>
									<span class="wp-mcp-ai-badge-success">
										<?php echo esc_html( $analysis['usage_lookup']['index_name'] ?? 'yes' ); ?>
									</span>
								<?php else : ?>
									<span class="wp-mcp-ai-badge-warning">
										<?php esc_html_e( 'No index', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $analysis['usage_lookup']['rows'] ?? 0 ) ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<!-- Actions -->
	<div class="wp-mcp-ai-widget-actions">
		<?php if ( empty( $stats['optimizations_active'] ) ) : ?>
			<button type="button" id="wp-mcp-ai-run-optimization" class="button button-primary">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Run Optimization', 'mcp-ai-wpoos' ); ?>
			</button>
		<?php else : ?>
			<button type="button" id="wp-mcp-ai-reanalyze-performance" class="button">
				<span class="dashicons dashicons-chart-line"></span>
				<?php esc_html_e( 'Re-analyze Performance', 'mcp-ai-wpoos' ); ?>
			</button>
		<?php endif; ?>
	</div>
</div>

