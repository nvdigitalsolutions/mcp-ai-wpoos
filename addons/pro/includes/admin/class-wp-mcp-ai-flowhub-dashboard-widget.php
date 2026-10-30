<?php
/**
 * FlowHub Dashboard Widget.
 *
 * WordPress dashboard widget showing FlowHub inventory health,
 * top locations, and compliance summary.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FlowHub Dashboard Widget.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_FlowHub_Dashboard_Widget {

	/**
	 * Initialize dashboard widget hooks.
	 *
	 * @since 1.4.0
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register the dashboard widget.
	 *
	 * @since 1.4.0
	 */
	public static function register_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}

		/**
		 * Kill-switch for the wp-admin FlowHub dashboard widget (Proposal 089
		 * Phase 4f). The console renders the same metrics through the
		 * dashboards feature; flip this filter off per cohort once console
		 * parity is verified.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_admin_page_enabled_flowhub_widget', true ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wp_mcp_ai_flowhub_dashboard',
			__( 'FlowHub Inventory Health', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	/**
	 * Inventory-health metrics for the console dashboards feature
	 * (Proposal 089 Phase 4f — promoted public seam; the wp-admin widget
	 * renders the same payload). Read-mostly: compliance traceability
	 * stays inside FlowHub.
	 *
	 * @since 2.4.0
	 * @return array|WP_Error Metric payload or unavailable error.
	 */
	public static function get_dashboard_data() {
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			return new WP_Error(
				'flowhub_cct_unavailable',
				__( 'FlowHub CCT Manager is not available.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$items       = $cct_manager->get_cached_items( array( 'per_page' => 100 ) );
		$last_sync   = $cct_manager->get_last_sync_time();
		$is_fresh    = $cct_manager->is_fresh();
		$row_count   = $cct_manager->get_row_count();

		$settings      = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
		$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

		$in_stock     = 0;
		$low_stock    = 0;
		$out_of_stock = 0;
		$total_value  = 0.0;
		$locations    = array();

		foreach ( $items as $item ) {
			$qty          = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			$price        = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );
			$total_value += $qty * $price;

			if ( $qty >= $low_threshold ) {
				++$in_stock;
			} elseif ( $qty > 0 ) {
				++$low_stock;
			} else {
				++$out_of_stock;
			}

			$loc = isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown';
			if ( ! isset( $locations[ $loc ] ) ) {
				$locations[ $loc ] = 0;
			}
			++$locations[ $loc ];
		}

		// Sort locations by item count and shape the list rows.
		arsort( $locations );
		$location_rows = array();
		foreach ( array_slice( $locations, 0, 5, true ) as $name => $count ) {
			$location_rows[] = array(
				'name'  => sanitize_text_field( (string) $name ),
				'count' => absint( $count ),
			);
		}

		return array(
			'in_stock'      => $in_stock,
			'low_stock'     => $low_stock,
			'out_of_stock'  => $out_of_stock,
			'total_items'   => absint( $row_count ),
			'total_value'   => (float) $total_value,
			'last_sync'     => sanitize_text_field( (string) $last_sync ),
			'is_fresh'      => (bool) $is_fresh,
			'low_threshold' => $low_threshold,
			'locations'     => $location_rows,
		);
	}

	/**
	 * Render the dashboard widget.
	 *
	 * @since 1.4.0
	 */
	public static function render_widget() {
		$data = self::get_dashboard_data();
		if ( is_wp_error( $data ) ) {
			echo '<p>' . esc_html( $data->get_error_message() ) . '</p>';
			return;
		}

		$in_stock     = $data['in_stock'];
		$low_stock    = $data['low_stock'];
		$out_of_stock = $data['out_of_stock'];
		$row_count    = $data['total_items'];
		$total_value  = $data['total_value'];
		$last_sync    = $data['last_sync'];
		$is_fresh     = $data['is_fresh'];
		$locations    = array();
		foreach ( $data['locations'] as $row ) {
			$locations[ $row['name'] ] = $row['count'];
		}

		if ( 0 === $row_count && empty( $data['locations'] ) ) {
			echo '<p>' . esc_html__( 'No inventory data yet. Run a sync to populate the cache.', 'mcp-ai-wpoos-pro' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-flowhub-toolkit-settings' ) ) . '" class="button">' . esc_html__( 'Go to FlowHub Toolkit', 'mcp-ai-wpoos-pro' ) . '</a></p>';
			return;
		}
		?>
		<div class="flowhub-dashboard-widget">
			<div class="flowhub-health-gauges" style="display:flex; gap:12px; margin-bottom:12px;">
				<div class="flowhub-gauge" style="flex:1; text-align:center; padding:8px; background:#f0f6e8; border-radius:4px;">
					<div style="font-size:24px; font-weight:bold; color:#46b450;"><?php echo esc_html( (string) $in_stock ); ?></div>
					<div style="font-size:12px;"><?php esc_html_e( 'In Stock', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div class="flowhub-gauge" style="flex:1; text-align:center; padding:8px; background:#fef8ee; border-radius:4px;">
					<div style="font-size:24px; font-weight:bold; color:#f0ad4e;"><?php echo esc_html( (string) $low_stock ); ?></div>
					<div style="font-size:12px;"><?php esc_html_e( 'Low Stock', 'mcp-ai-wpoos-pro' ); ?></div>
				</div>
				<div class="flowhub-gauge" style="flex:1; text-align:center; padding:8px; background:#fbeaea; border-radius:4px;">
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
				</tbody>
			</table>

			<?php if ( ! empty( $locations ) ) : ?>
				<p><strong><?php esc_html_e( 'Top Locations', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<ul style="margin:0; padding-left:16px;">
					<?php
					$top_locations = array_slice( $locations, 0, 5, true );
					foreach ( $top_locations as $name => $count ) :
						?>
						<li><?php echo esc_html( $name ); ?> — <?php echo esc_html( (string) $count ); ?> <?php esc_html_e( 'items', 'mcp-ai-wpoos-pro' ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p style="margin-top:8px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-flowhub-toolkit-settings' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'View Full Report', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
