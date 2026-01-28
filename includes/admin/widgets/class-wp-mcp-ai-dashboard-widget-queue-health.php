<?php
/**
 * Dashboard Widget for DLQ and SLA Health.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a WordPress dashboard widget showing DLQ and SLA health.
 */
class WP_MCP_AI_Dashboard_Widget_Queue_Health {
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	/**
	 * Register the dashboard widget.
	 */
	public function register_widget() {
		// Only show to admins.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show if DLQ or SLA Manager is available.
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) && ! class_exists( 'WP_MCP_AI_SLA_Manager' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wp_mcp_ai_queue_health',
			__( 'NV oOS: Job Queue Health', 'mcp-ai-wpoos' ),
			array( $this, 'render_widget' )
		);
	}

	/**
	 * Render the dashboard widget content.
	 */
	public function render_widget() {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for queue health dashboard widget layout and styling on this admin page only
		?>
		<style>
			#wp_mcp_ai_queue_health .wp-mcp-ai-widget-stat {
				display: flex;
				justify-content: space-between;
				padding: 0.5rem 0;
				border-bottom: 1px solid #f0f0f1;
			}
			#wp_mcp_ai_queue_health .wp-mcp-ai-widget-stat:last-child {
				border-bottom: none;
			}
			#wp_mcp_ai_queue_health .wp-mcp-ai-widget-label {
				font-weight: 500;
			}
			#wp_mcp_ai_queue_health .wp-mcp-ai-widget-value {
				font-weight: 600;
			}
			#wp_mcp_ai_queue_health .wp-mcp-ai-widget-value--success {
				color: #2ea44f;
			}
			#wp_mcp_ai_queue_health .wp-mcp-ai-widget-value--warning {
				color: #fb8c00;
			}
			#wp_mcp_ai_queue_health .wp-mcp-ai-widget-value--error {
				color: #d73a49;
			}
			#wp_mcp_ai_queue_health .wp-mcp-ai-widget-actions {
				margin-top: 1rem;
				padding-top: 1rem;
				border-top: 1px solid #f0f0f1;
			}
		</style>

		<?php if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) : ?>
			<?php
			$dlq_stats = WP_MCP_AI_Dead_Letter_Queue::get_stats();
			$has_items = $dlq_stats['total'] > 0;
			?>
			<div class="wp-mcp-ai-widget-stat">
				<span class="wp-mcp-ai-widget-label"><?php esc_html_e( 'Dead Letter Queue', 'mcp-ai-wpoos' ); ?></span>
				<span class="wp-mcp-ai-widget-value <?php echo esc_attr( $has_items ? 'wp-mcp-ai-widget-value--warning' : 'wp-mcp-ai-widget-value--success' ); ?>">
					<?php
					if ( $has_items ) {
						printf(
							/* translators: %d: number of failed items */
							esc_html__( '%d failed items', 'mcp-ai-wpoos' ),
							(int) $dlq_stats['total']
						);
					} else {
						esc_html_e( '✓ No failures', 'mcp-ai-wpoos' );
					}
					?>
				</span>
			</div>

			<?php if ( $has_items ) : ?>
				<div class="wp-mcp-ai-widget-stat">
					<span class="wp-mcp-ai-widget-label"><?php esc_html_e( 'Active / Dismissed', 'mcp-ai-wpoos' ); ?></span>
					<span class="wp-mcp-ai-widget-value">
						<?php
						printf(
							/* translators: 1: active count, 2: dismissed count */
							esc_html__( '%1$d / %2$d', 'mcp-ai-wpoos' ),
							(int) $dlq_stats['active'],
							(int) $dlq_stats['dismissed']
						);
						?>
					</span>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( class_exists( 'WP_MCP_AI_SLA_Manager' ) && WP_MCP_AI_SLA_Manager::is_enabled() ) : ?>
			<?php
			$recommendations = WP_MCP_AI_SLA_Manager::get_tuning_recommendations();
			$critical_count  = 0;
			$warning_count   = 0;

			foreach ( $recommendations as $rec ) {
				if ( 'critical' === $rec['status'] ) {
					++$critical_count;
				} elseif ( 'warning' === $rec['status'] ) {
					++$warning_count;
				}
			}

			$has_issues = $critical_count > 0 || $warning_count > 0;
			?>

			<div class="wp-mcp-ai-widget-stat">
				<span class="wp-mcp-ai-widget-label"><?php esc_html_e( 'SLA Compliance', 'mcp-ai-wpoos' ); ?></span>
				<span class="wp-mcp-ai-widget-value <?php echo esc_attr( $has_issues ? 'wp-mcp-ai-widget-value--warning' : 'wp-mcp-ai-widget-value--success' ); ?>">
					<?php
					if ( $has_issues ) {
						if ( $critical_count > 0 ) {
							printf(
								/* translators: %d: number of critical issues */
								esc_html__( '⚠️ %d critical', 'mcp-ai-wpoos' ),
								(int) $critical_count
							);
						} else {
							printf(
								/* translators: %d: number of warnings */
								esc_html__( '⚠️ %d warnings', 'mcp-ai-wpoos' ),
								(int) $warning_count
							);
						}
					} else {
						esc_html_e( '✓ All tiers healthy', 'mcp-ai-wpoos' );
					}
					?>
				</span>
			</div>

			<?php if ( $has_issues ) : ?>
				<div class="wp-mcp-ai-widget-stat">
					<span class="wp-mcp-ai-widget-label"><?php esc_html_e( 'Issues', 'mcp-ai-wpoos' ); ?></span>
					<span class="wp-mcp-ai-widget-value" style="font-size:0.875rem;font-weight:normal;">
						<?php
						$issue_parts = array();
						foreach ( $recommendations as $rec ) {
							if ( 'ok' !== $rec['status'] ) {
								$issue_parts[] = ucfirst( $rec['tier'] );
							}
						}
						echo esc_html( implode( ', ', $issue_parts ) );
						?>
					</span>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<div class="wp-mcp-ai-widget-actions">
			<?php if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) && $dlq_stats['total'] > 0 ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dlq-manager' ) ); ?>" class="button button-secondary" style="margin-right:0.5rem;">
					<?php esc_html_e( 'View DLQ', 'mcp-ai-wpoos' ); ?>
				</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-cron-manager' ) ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Cron Manager', 'mcp-ai-wpoos' ); ?>
			</a>
		</div>
		<?php
	}
}
