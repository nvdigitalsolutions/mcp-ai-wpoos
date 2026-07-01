<?php
/**
 * EZuite Dashboard Widget.
 *
 * WordPress dashboard widget showing EZuite inventory health,
 * sync status, and quick links to the toolkit settings.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EZuite Dashboard Widget.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_EZuite_Dashboard_Widget {

	/**
	 * Initialize dashboard widget hooks.
	 *
	 * @since 1.9.0
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * @since 1.9.0
	 */
	public static function register_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}

		wp_add_dashboard_widget(
			'wp_mcp_ai_ezuite_dashboard_widget',
			__( 'EZuite Inventory Sync', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	/**
	 * Render the dashboard widget.
	 *
	 * @since 1.9.0
	 */
	public static function render_widget() {
		if ( ! class_exists( 'WP_MCP_AI_EZuite_CCT_Manager' ) ) {
			echo '<p>' . esc_html__( 'EZuite CCT Manager is not available.', 'mcp-ai-wpoos-pro' ) . '</p>';
			return;
		}

		$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager();
		$items       = $cct_manager->get_cached_items( array( 'per_page' => 100 ) );
		$last_sync   = $cct_manager->get_last_sync_time();
		$is_fresh    = $cct_manager->is_fresh();
		$row_count   = $cct_manager->get_row_count();

		if ( empty( $items ) ) {
			echo '<p>' . esc_html__( 'No inventory data yet. Run a sync to populate the cache.', 'mcp-ai-wpoos-pro' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-ezuite-toolkit-settings' ) ) . '" class="button">' . esc_html__( 'Go to EZuite Toolkit', 'mcp-ai-wpoos-pro' ) . '</a></p>';
			return;
		}

		$settings      = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
		$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

		$in_stock     = 0;
		$low_stock    = 0;
		$out_of_stock = 0;
		$total_value  = 0.0;
		$warehouses   = array();

		foreach ( $items as $item ) {
			$qty          = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			$price        = floatval( isset( $item['cost_price'] ) ? $item['cost_price'] : 0.0 );
			$total_value += $qty * $price;

			if ( $qty >= $low_threshold ) {
				++$in_stock;
			} elseif ( $qty > 0 ) {
				++$low_stock;
			} else {
				++$out_of_stock;
			}

			$wh = isset( $item['warehouse'] ) ? $item['warehouse'] : __( 'Unknown', 'mcp-ai-wpoos-pro' );
			if ( ! isset( $warehouses[ $wh ] ) ) {
				$warehouses[ $wh ] = 0;
			}
			++$warehouses[ $wh ];
		}

		// Sort warehouses by item count.
		arsort( $warehouses );
		?>
		<div class="ezuite-dashboard-widget">
			<div class="ezuite-health-gauges" style="display:flex; gap:12px; margin-bottom:12px;">
				<div class="ezuite-gauge" style="flex:1; text-align:center; padding:8px; background:#f0f6e8; border-radius:4px;">
					<div style="font-size:24px; font-weight:bold; color:#46b450;"><?php echo esc_html( (string) $in_stock ); ?></div>
					<div style="font-size:12px;"><?php esc_html_e( 'In Stock', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div class="ezuite-gauge" style="flex:1; text-align:center; padding:8px; background:#fef8ee; border-radius:4px;">
					<div style="font-size:24px; font-weight:bold; color:#f0ad4e;"><?php echo esc_html( (string) $low_stock ); ?></div>
					<div style="font-size:12px;"><?php esc_html_e( 'Low Stock', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div class="ezuite-gauge" style="flex:1; text-align:center; padding:8px; background:#fbeaea; border-radius:4px;">
					<div style="font-size:24px; font-weight:bold; color:#d63638;"><?php echo esc_html( (string) $out_of_stock ); ?></div>
					<div style="font-size:12px;"><?php esc_html_e( 'Out of Stock', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
			</div>

			<table class="widefat fixed striped" style="margin-bottom:8px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Total Items', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php echo esc_html( (string) $row_count ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Total Value', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>$<?php echo esc_html( number_format( $total_value, 2 ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Last Sync', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php echo esc_html( ! empty( $last_sync ) ? $last_sync : __( 'Never', 'mcp-ai-wpoos-pro' ) ); ?>
							<?php if ( $is_fresh ) : ?>
								<span style="color:green; margin-left:6px;">&#10004;</span>
							<?php else : ?>
								<span style="color:orange; margin-left:6px;">&#9888;</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Items Below Threshold', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php echo esc_html( (string) $low_stock ); ?>
							<?php
							if ( $low_threshold > 0 ) {
								printf(
									/* translators: %d: threshold value */
									esc_html__( ' (threshold: %d)', 'mcp-ai-wpoos-pro' ),
									absint( $low_threshold )
								);
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $warehouses ) ) : ?>
				<p><strong><?php esc_html_e( 'Top Warehouses', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<ul style="margin:0; padding-left:16px;">
					<?php
					$top_warehouses = array_slice( $warehouses, 0, 5, true );
					foreach ( $top_warehouses as $name => $count ) :
						?>
						<li><?php echo esc_html( $name ); ?> — <?php echo esc_html( (string) $count ); ?> <?php esc_html_e( 'items', 'mcp-ai-wpoos-pro' ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p style="margin-top:8px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-ezuite-toolkit-settings' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'View Full Report', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
