<?php
/**
 * Token Performance Stats Widget Template
 *
 * Displays database optimization and caching performance metrics.
 *
 *
 * @package WP_MCP_AI
 * @var array $data Widget data containing performance statistics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stats    = isset( $data['stats'] ) ? $data['stats'] : array();
$analysis = isset( $data['analysis'] ) ? $data['analysis'] : array();
?>

<div class="wp-mcp-ai-widget-performance-stats">
	<h3><?php esc_html_e( 'Performance Optimization', 'wp-mcp-ai' ); ?></h3>

	<!-- Optimization Status -->
	<div class="wp-mcp-ai-status-section">
		<h4><?php esc_html_e( 'Database Optimizations', 'wp-mcp-ai' ); ?></h4>
		<div class="wp-mcp-ai-status-grid">
			<div class="wp-mcp-ai-status-item">
				<span class="wp-mcp-ai-status-label"><?php esc_html_e( 'Status:', 'wp-mcp-ai' ); ?></span>
				<?php if ( ! empty( $stats['optimizations_active'] ) ) : ?>
					<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-active">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?>
					</span>
				<?php else : ?>
					<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-inactive">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'Inactive', 'wp-mcp-ai' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div class="wp-mcp-ai-status-item">
				<span class="wp-mcp-ai-status-label"><?php esc_html_e( 'Schema Version:', 'wp-mcp-ai' ); ?></span>
				<span class="wp-mcp-ai-status-value"><?php echo esc_html( $stats['schema_version'] ?? 0 ); ?></span>
			</div>

			<div class="wp-mcp-ai-status-item">
				<span class="wp-mcp-ai-status-label"><?php esc_html_e( 'Token Indexes:', 'wp-mcp-ai' ); ?></span>
				<span class="wp-mcp-ai-status-value"><?php echo esc_html( $stats['wp_mcp_ai_indexes'] ?? 0 ); ?></span>
			</div>
		</div>
	</div>

	<!-- Index Status -->
	<div class="wp-mcp-ai-index-section">
		<h4><?php esc_html_e( 'Index Status', 'wp-mcp-ai' ); ?></h4>
		<table class="wp-mcp-ai-stats-table">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Tier Index:', 'wp-mcp-ai' ); ?></td>
					<td>
						<?php if ( ! empty( $stats['tier_index_exists'] ) ) : ?>
							<span class="wp-mcp-ai-badge-success">
								<span class="dashicons dashicons-yes"></span>
								<?php esc_html_e( 'Exists', 'wp-mcp-ai' ); ?>
							</span>
						<?php else : ?>
							<span class="wp-mcp-ai-badge-warning">
								<span class="dashicons dashicons-no"></span>
								<?php esc_html_e( 'Missing', 'wp-mcp-ai' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Usage Index:', 'wp-mcp-ai' ); ?></td>
					<td>
						<?php if ( ! empty( $stats['usage_index_exists'] ) ) : ?>
							<span class="wp-mcp-ai-badge-success">
								<span class="dashicons dashicons-yes"></span>
								<?php esc_html_e( 'Exists', 'wp-mcp-ai' ); ?>
							</span>
						<?php else : ?>
							<span class="wp-mcp-ai-badge-warning">
								<span class="dashicons dashicons-no"></span>
								<?php esc_html_e( 'Missing', 'wp-mcp-ai' ); ?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Data Volume -->
	<div class="wp-mcp-ai-volume-section">
		<h4><?php esc_html_e( 'Data Volume', 'wp-mcp-ai' ); ?></h4>
		<table class="wp-mcp-ai-stats-table">
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Tier Records:', 'wp-mcp-ai' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['tier_records'] ?? 0 ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Usage Records:', 'wp-mcp-ai' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['usage_records'] ?? 0 ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Total Indexes:', 'wp-mcp-ai' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['total_indexes'] ?? 0 ) ); ?></strong></td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Query Performance -->
	<?php if ( ! empty( $analysis['tier_lookup'] ) || ! empty( $analysis['usage_lookup'] ) ) : ?>
		<div class="wp-mcp-ai-performance-section">
			<h4><?php esc_html_e( 'Query Performance', 'wp-mcp-ai' ); ?></h4>
			<table class="wp-mcp-ai-stats-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Query Type', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Index Used', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Rows Scanned', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! empty( $analysis['tier_lookup'] ) ) : ?>
						<tr>
							<td><?php esc_html_e( 'Tier Lookup', 'wp-mcp-ai' ); ?></td>
							<td>
								<?php if ( ! empty( $analysis['tier_lookup']['using_index'] ) ) : ?>
									<span class="wp-mcp-ai-badge-success">
										<?php echo esc_html( $analysis['tier_lookup']['index_name'] ?? 'yes' ); ?>
									</span>
								<?php else : ?>
									<span class="wp-mcp-ai-badge-warning">
										<?php esc_html_e( 'No index', 'wp-mcp-ai' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $analysis['tier_lookup']['rows'] ?? 0 ) ); ?></td>
						</tr>
					<?php endif; ?>

					<?php if ( ! empty( $analysis['usage_lookup'] ) ) : ?>
						<tr>
							<td><?php esc_html_e( 'Usage Lookup', 'wp-mcp-ai' ); ?></td>
							<td>
								<?php if ( ! empty( $analysis['usage_lookup']['using_index'] ) ) : ?>
									<span class="wp-mcp-ai-badge-success">
										<?php echo esc_html( $analysis['usage_lookup']['index_name'] ?? 'yes' ); ?>
									</span>
								<?php else : ?>
									<span class="wp-mcp-ai-badge-warning">
										<?php esc_html_e( 'No index', 'wp-mcp-ai' ); ?>
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
				<?php esc_html_e( 'Run Optimization', 'wp-mcp-ai' ); ?>
			</button>
		<?php else : ?>
			<button type="button" id="wp-mcp-ai-reanalyze-performance" class="button">
				<span class="dashicons dashicons-chart-line"></span>
				<?php esc_html_e( 'Re-analyze Performance', 'wp-mcp-ai' ); ?>
			</button>
		<?php endif; ?>
	</div>
</div>

<style>
	.wp-mcp-ai-widget-performance-stats {
		padding: 15px;
	}

	.wp-mcp-ai-widget-performance-stats h3 {
		margin-top: 0;
		border-bottom: 1px solid #ddd;
		padding-bottom: 10px;
	}

	.wp-mcp-ai-widget-performance-stats h4 {
		margin: 15px 0 10px;
		color: #23282d;
	}

	.wp-mcp-ai-status-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 10px;
		margin-bottom: 15px;
	}

	.wp-mcp-ai-status-item {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.wp-mcp-ai-status-label {
		font-weight: 600;
		color: #555;
	}

	.wp-mcp-ai-status-value {
		color: #2271b1;
		font-weight: 600;
	}

	.wp-mcp-ai-status-badge {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 3px 8px;
		border-radius: 3px;
		font-size: 12px;
		font-weight: 600;
	}

	.wp-mcp-ai-status-badge.wp-mcp-ai-status-active {
		background: #d1f0d7;
		color: #0a5d1f;
	}

	.wp-mcp-ai-status-badge.wp-mcp-ai-status-inactive {
		background: #fef7e0;
		color: #735c0f;
	}

	.wp-mcp-ai-stats-table {
		width: 100%;
		border-collapse: collapse;
	}

	.wp-mcp-ai-stats-table th,
	.wp-mcp-ai-stats-table td {
		padding: 8px;
		text-align: left;
		border-bottom: 1px solid #f0f0f1;
	}

	.wp-mcp-ai-stats-table th {
		font-weight: 600;
		background: #f9f9f9;
	}

	.wp-mcp-ai-badge-success {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 6px;
		background: #d1f0d7;
		color: #0a5d1f;
		border-radius: 3px;
		font-size: 11px;
		font-weight: 600;
	}

	.wp-mcp-ai-badge-warning {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 6px;
		background: #fef7e0;
		color: #735c0f;
		border-radius: 3px;
		font-size: 11px;
		font-weight: 600;
	}

	.wp-mcp-ai-widget-actions {
		margin-top: 15px;
		padding-top: 15px;
		border-top: 1px solid #ddd;
	}

	.wp-mcp-ai-widget-actions button {
		display: inline-flex;
		align-items: center;
		gap: 5px;
	}

	.wp-mcp-ai-widget-actions .dashicons {
		font-size: 16px;
		width: 16px;
		height: 16px;
	}
</style>
