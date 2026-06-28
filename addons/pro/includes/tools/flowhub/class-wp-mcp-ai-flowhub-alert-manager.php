<?php
/**
 * FlowHub Alert Manager.
 *
 * Proactive low-stock alert system for dispensary operators.
 * Checks inventory after each sync and fires hooks when items
 * fall below threshold. Supports email notifications and
 * admin bar indicators.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_FlowHub_Alert_Manager' ) ) {

	/**
	 * FlowHub Alert Manager.
	 *
	 * @since 1.4.0
	 */
	class WP_MCP_AI_FlowHub_Alert_Manager {

		/**
		 * Initialize alert hooks.
		 *
		 * @since 1.4.0
		 */
		public static function init() {
			add_action( 'wp_mcp_ai_flowhub_after_sync', array( __CLASS__, 'check_low_stock_after_sync' ), 20, 1 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_indicator' ), 100 );
		}

		/**
		 * Check for low-stock items after a sync completes.
		 *
		 * @since 1.4.0
		 *
		 * @param array $result Sync result from CCT manager.
		 */
		public static function check_low_stock_after_sync( $result ) {
			unset( $result ); // Used by hook signature.
			if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
				return;
			}

			$settings      = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
			$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

			$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
			$items       = $cct_manager->get_cached_items( array( 'per_page' => 100 ) );

			$low_stock_items = array();
			$out_of_stock    = array();
			$recovered       = array();

			foreach ( $items as $item ) {
				$qty = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );

				if ( $qty <= 0 ) {
					$out_of_stock[] = $item;
				} elseif ( $qty < $low_threshold ) {
					$low_stock_items[] = $item;
				}
			}

			// Fire hooks for integrations.
			if ( ! empty( $low_stock_items ) ) {
				/**
				 * Fires when low-stock items are detected after a sync.
				 *
				 * @since 1.4.0
				 *
				 * @param array $low_stock_items Array of CCT items below threshold.
				 * @param int   $low_threshold   The configured threshold.
				 */
				do_action( 'wp_mcp_ai_flowhub_low_stock_detected', $low_stock_items, $low_threshold );
			}

			if ( ! empty( $out_of_stock ) ) {
				/**
				 * Fires when out-of-stock items are detected after a sync.
				 *
				 * @since 1.4.0
				 *
				 * @param array $out_of_stock Array of zero-quantity CCT items.
				 */
				do_action( 'wp_mcp_ai_flowhub_out_of_stock_detected', $out_of_stock );
			}

			// Store for admin indicator.
			update_option( 'wp_mcp_ai_flowhub_low_stock_count', count( $low_stock_items ) );
			update_option( 'wp_mcp_ai_flowhub_out_of_stock_count', count( $out_of_stock ) );

			// Send email notification if significant changes.
			$total_alerts = count( $low_stock_items ) + count( $out_of_stock );
			if ( $total_alerts > 0 ) {
				self::maybe_send_alert_email( $low_stock_items, $out_of_stock, $low_threshold );
			}
		}

		/**
		 * Send email notification for low-stock alerts.
		 *
		 * @since 1.4.0
		 *
		 * @param array $low_stock_items Low-stock items.
		 * @param array $out_of_stock    Out-of-stock items.
		 * @param int   $threshold       The threshold used.
		 */
		protected static function maybe_send_alert_email( $low_stock_items, $out_of_stock, $threshold ) {
			$admin_email = get_option( 'admin_email' );
			if ( ! $admin_email ) {
				return;
			}

			// Throttle: only send once per hour.
			$last_alert = get_option( 'wp_mcp_ai_flowhub_last_alert_time', 0 );
			if ( time() - $last_alert < HOUR_IN_SECONDS ) {
				return;
			}

			$subject = sprintf(
				'[%s] %s',
				get_bloginfo( 'name' ),
				__( 'FlowHub Inventory Alert', 'mcp-ai-wpoos-pro' )
			);

			$body = sprintf(
				/* translators: 1: low stock count, 2: out of stock count, 3: threshold */
				__( "FlowHub inventory sync detected alerts:\n\n%1\$d items below threshold (%2\$d)\n%3\$d items out of stock\n\nLow Stock Items:\n", 'mcp-ai-wpoos-pro' ),
				count( $low_stock_items ),
				$threshold,
				count( $out_of_stock )
			);

			foreach ( array_slice( $low_stock_items, 0, 10 ) as $item ) {
				$body .= sprintf(
					"- %s (SKU: %s) — Qty: %d at %s\n",
					isset( $item['product_name'] ) ? $item['product_name'] : 'Unknown',
					isset( $item['sku'] ) ? $item['sku'] : 'N/A',
					absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 ),
					isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown'
				);
			}

			if ( count( $low_stock_items ) > 10 ) {
				$body .= sprintf(
					/* translators: %d: additional count */
					__( "... and %d more low-stock items.\n", 'mcp-ai-wpoos-pro' ),
					count( $low_stock_items ) - 10
				);
			}

			if ( ! empty( $out_of_stock ) ) {
				$body .= "\n" . __( "Out of Stock Items:\n", 'mcp-ai-wpoos-pro' );
				foreach ( array_slice( $out_of_stock, 0, 5 ) as $item ) {
					$body .= sprintf(
						"- %s (SKU: %s) at %s\n",
						isset( $item['product_name'] ) ? $item['product_name'] : 'Unknown',
						isset( $item['sku'] ) ? $item['sku'] : 'N/A',
						isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown'
					);
				}
				if ( count( $out_of_stock ) > 5 ) {
					$body .= sprintf(
						/* translators: %d: additional count */
						__( "... and %d more out-of-stock items.\n", 'mcp-ai-wpoos-pro' ),
						count( $out_of_stock ) - 5
					);
				}
			}

			$body .= "\n" . sprintf(
				/* translators: %s: admin URL */
				__( "View full report: %s\n", 'mcp-ai-wpoos-pro' ),
				admin_url( 'admin.php?page=wp-mcp-ai-flowhub-toolkit-settings' )
			);

			wp_mail( $admin_email, $subject, $body );
			update_option( 'wp_mcp_ai_flowhub_last_alert_time', time() );
		}

		/**
		 * Add admin bar indicator showing low-stock count.
		 *
		 * @since 1.4.0
		 *
		 * @param WP_Admin_Bar $admin_bar The admin bar object.
		 */
		public static function add_admin_bar_indicator( $admin_bar ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
				return;
			}

			$low_count = absint( get_option( 'wp_mcp_ai_flowhub_low_stock_count', 0 ) );
			$out_count = absint( get_option( 'wp_mcp_ai_flowhub_out_of_stock_count', 0 ) );
			$total     = $low_count + $out_count;

			if ( 0 === $total ) {
				return;
			}

			$title = sprintf(
				'<span class="ab-icon dashicons-store" style="top:2px;"></span> %s',
				/* translators: %d: alert count */
				sprintf( _n( '%d alert', '%d alerts', $total, 'mcp-ai-wpoos-pro' ), $total )
			);

			$admin_bar->add_node(
				array(
					'id'    => 'wp-mcp-ai-flowhub-alerts',
					'title' => $title,
					'href'  => admin_url( 'admin.php?page=wp-mcp-ai-flowhub-toolkit-settings' ),
					'meta'  => array(
						'class' => $out_count > 0 ? 'wp-mcp-ai-alert-critical' : 'wp-mcp-ai-alert-warning',
					),
				)
			);
		}
	}
}
