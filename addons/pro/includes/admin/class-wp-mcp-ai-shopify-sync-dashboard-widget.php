<?php
/**
 * Shopify Sync Dashboard Widget.
 *
 * WordPress dashboard widget showing Shopify Sync GraphQL cost
 * analytics, sync health, and inventory summary.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shopify Sync Dashboard Widget.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Shopify_Sync_Dashboard_Widget {

	/**
	 * Initialize.
	 *
	 * @since 1.3.0
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * @since 1.3.0
	 */
	public static function register_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}

		wp_add_dashboard_widget(
			'wp_mcp_ai_shopify_sync_dashboard',
			__( 'Shopify Sync — Cost & Health', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	/**
	 * Render the dashboard widget.
	 *
	 * @since 1.3.0
	 */
	public static function render_widget() {
		$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		if ( empty( $sync_connections ) ) {
			echo '<p>' . esc_html__( 'No Shopify connections configured for sync.', 'mcp-ai-wpoos-pro' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-shopify-sync-toolkit-settings' ) ) . '" class="button">' . esc_html__( 'Configure Shopify Sync', 'mcp-ai-wpoos-pro' ) . '</a></p>';
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';
		}

		$total_used  = 0;
		$total_limit = 0;
		$total_rows  = 0;
		$all_fresh   = true;
		$has_errors  = false;

		foreach ( $sync_connections as $conn_id ) {
			$engine       = new WP_MCP_AI_Shopify_Sync_Engine( $conn_id );
			$cost         = $engine->get_cost_report();
			$total_used  += $cost['used'];
			$total_limit += $cost['limit'];
			if ( $cost['is_low'] ) {
				$all_fresh = false;
			}

			if ( class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
				$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $conn_id );
				$total_rows += $cct_manager->get_row_count();
				if ( ! $cct_manager->is_fresh() ) {
					$all_fresh = false;
				}
			}

			$last_error = get_option( 'wp_mcp_ai_shopify_last_sync_error_' . $conn_id, '' );
			if ( ! empty( $last_error ) ) {
				$has_errors = true;
			}
		}

		$pct_remaining = $total_limit > 0 ? round( ( ( $total_limit - $total_used ) / $total_limit ) * 100, 1 ) : 100;
		$status_color  = $pct_remaining < 20 ? '#d63638' : ( $pct_remaining < 50 ? '#f0ad4e' : '#46b450' );
		?>
		<div class="shopify-sync-dashboard-widget">
			<div style="display:flex; gap:12px; margin-bottom:12px;">
				<div style="flex:1; text-align:center; padding:8px; background:#f6f7f7; border-radius:4px;">
					<div style="font-size:24px; font-weight:bold; color:<?php echo esc_attr( $status_color ); ?>;">
						<?php echo esc_html( (string) $pct_remaining ); ?>%
					</div>
					<div style="font-size:12px;"><?php esc_html_e( 'Cost Budget', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div style="flex:1; text-align:center; padding:8px; background:#f6f7f7; border-radius:4px;">
					<div style="font-size:24px; font-weight:bold; color:#2271b1;">
						<?php echo esc_html( number_format( $total_rows ) ); ?>
					</div>
					<div style="font-size:12px;"><?php esc_html_e( 'Cached Items', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div style="flex:1; text-align:center; padding:8px; background:#f6f7f7; border-radius:4px;">
					<div style="font-size:24px; font-weight:bold; color:<?php echo $all_fresh && ! $has_errors ? '#46b450' : '#d63638'; ?>;">
						<?php echo $all_fresh && ! $has_errors ? '&#10004;' : '&#9888;'; ?>
					</div>
					<div style="font-size:12px;"><?php esc_html_e( 'Health', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>

			<table class="widefat fixed striped" style="margin-bottom:8px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Points Used Today', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php echo esc_html( $total_used . ' / ' . $total_limit ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Connections', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php echo esc_html( (string) count( $sync_connections ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php if ( $has_errors ) : ?>
								<span style="color:#d63638;">&#9888; <?php esc_html_e( 'Errors detected', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php elseif ( $all_fresh ) : ?>
								<span style="color:#46b450;">&#10004; <?php esc_html_e( 'Healthy', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span style="color:#f0ad4e;">&#9888; <?php esc_html_e( 'Stale or low budget', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top:8px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-shopify-sync-toolkit-settings' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'View Shopify Sync', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
