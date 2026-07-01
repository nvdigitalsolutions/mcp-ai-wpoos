<?php
/**
 * EZuite Alert Manager.
 *
 * Proactive low-stock alert system for inventory operators.
 * Checks inventory after each sync and sends email notifications
 * when items fall below threshold. Supports cooldown-based
 * throttling to prevent alert floods.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_EZuite_Alert_Manager' ) ) {

	/**
	 * EZuite Alert Manager.
	 *
	 * @since 1.9.0
	 */
	class WP_MCP_AI_EZuite_Alert_Manager {

		/**
		 * Cooldown period between alert emails in seconds.
		 *
		 * Prevents flooding the admin inbox when successive syncs
		 * detect the same low-stock items.
		 *
		 * @since 1.9.0
		 * @var int
		 */
		const ALERT_COOLDOWN = 3600;

		/**
		 * Option key for last alert timestamp.
		 *
		 * @since 1.9.0
		 * @var string
		 */
		const LAST_ALERT_OPTION = 'wp_mcp_ai_ezuite_last_alert_time';

		/**
		 * Option key for previously alerted SKUs.
		 *
		 * @since 1.9.0
		 * @var string
		 */
		const ALERTED_SKUS_OPTION = 'wp_mcp_ai_ezuite_alerted_skus';

		/**
		 * Initialize alert hooks.
		 *
		 * @since 1.9.0
		 */
		public static function init() {
			add_action( 'wp_mcp_ai_ezuite_after_sync', array( __CLASS__, 'check_low_stock' ), 20, 1 );
		}

		/**
		 * Check for low-stock items after a sync completes.
		 *
		 * Fired by the sync engine after a full sync completes.
		 * Retrieves CCT items below the configured threshold, filters
		 * to those not already alerted, and sends an HTML email to
		 * the admin if the cooldown has elapsed.
		 *
		 * @since 1.9.0
		 *
		 * @param array $sync_result Sync result from CCT manager.
		 */
		public static function check_low_stock( $sync_result ) {
			unset( $sync_result ); // Used by hook signature.

			if ( ! self::can_send_alert() ) {
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_EZuite_CCT_Manager' ) ) {
				return;
			}

			$settings      = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
			$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

			$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager();
			$items       = $cct_manager->get_cached_items( array( 'per_page' => 500 ) );

			$alerted = self::get_alerted_skus();
			$to_send = array();

			foreach ( $items as $item ) {
				$qty = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );

				// Only consider items truly below threshold.
				if ( $qty >= $low_threshold ) {
					continue;
				}

				$sku = isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '';
				if ( empty( $sku ) ) {
					continue;
				}

				// Skip if already alerted.
				if ( in_array( $sku, $alerted, true ) ) {
					continue;
				}

				$to_send[] = $item;
			}

			if ( empty( $to_send ) ) {
				return;
			}

			// Send the email.
			self::send_alert_email( $to_send, $low_threshold );

			// Mark items as alerted and update cooldown.
			foreach ( $to_send as $item ) {
				$sku = isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '';
				if ( ! empty( $sku ) ) {
					self::mark_alerted( $sku );
				}
			}

			update_option( self::LAST_ALERT_OPTION, time() );
		}

		/**
		 * Send alert email with HTML table of low-stock items.
		 *
		 * Composes and sends a wp_mail notification to the site admin
		 * with a formatted HTML table showing all low-stock items
		 * including their SKU, name, quantity, warehouse, and reorder point.
		 *
		 * @since 1.9.0
		 *
		 * @param array $items     Array of CCT items below threshold.
		 * @param int   $threshold The configured low-stock threshold.
		 */
		public static function send_alert_email( $items, $threshold ) {
			$admin_email = get_option( 'admin_email' );
			if ( ! $admin_email ) {
				return;
			}

			$subject = sprintf(
				'[%s] %s',
				get_bloginfo( 'name' ),
				__( 'EZuite Low Stock Alert', 'mcp-ai-wpoos-pro' )
			);

			// Build HTML body.
			$body  = '<html><body>';
			$body .= '<h2>' . esc_html__( 'EZuite Low Stock Alert', 'mcp-ai-wpoos-pro' ) . '</h2>';
			$body .= '<p>' . sprintf(
				/* translators: 1: item count, 2: threshold */
				esc_html__( 'The following %1$d items have fallen below the low-stock threshold of %2$d.', 'mcp-ai-wpoos-pro' ),
				count( $items ),
				$threshold
			) . '</p>';

			$body .= '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse; width:100%;">';
			$body .= '<thead><tr style="background:#f1f1f1;">';
			$body .= '<th style="text-align:left;">' . esc_html__( 'SKU', 'mcp-ai-wpoos-pro' ) . '</th>';
			$body .= '<th style="text-align:left;">' . esc_html__( 'Product Name', 'mcp-ai-wpoos-pro' ) . '</th>';
			$body .= '<th style="text-align:center;">' . esc_html__( 'Quantity', 'mcp-ai-wpoos-pro' ) . '</th>';
			$body .= '<th style="text-align:left;">' . esc_html__( 'Warehouse', 'mcp-ai-wpoos-pro' ) . '</th>';
			$body .= '<th style="text-align:center;">' . esc_html__( 'Reorder Point', 'mcp-ai-wpoos-pro' ) . '</th>';
			$body .= '</tr></thead><tbody>';

			foreach ( $items as $item ) {
				$sku        = isset( $item['sku'] ) ? esc_html( $item['sku'] ) : '';
				$name       = isset( $item['product_name'] ) ? esc_html( $item['product_name'] ) : '';
				$quantity   = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
				$warehouse  = isset( $item['location_name'] ) ? esc_html( $item['location_name'] ) : '';
				$reorder_pt = isset( $item['reorder_point'] ) ? esc_html( (string) $item['reorder_point'] ) : '-';

				$row_style = ( 0 === $quantity ) ? ' style="background:#fdd;"' : '';
				$body     .= '<tr' . $row_style . '>';
				$body     .= '<td>' . $sku . '</td>';
				$body     .= '<td>' . $name . '</td>';
				$body     .= '<td style="text-align:center;">' . (string) $quantity . '</td>';
				$body     .= '<td>' . $warehouse . '</td>';
				$body     .= '<td style="text-align:center;">' . $reorder_pt . '</td>';
				$body     .= '</tr>';
			}

			$body .= '</tbody></table>';

			$body .= '<p style="margin-top:20px;">';
			$body .= sprintf(
				/* translators: %s: admin URL */
				esc_html__( 'View full inventory report: %s', 'mcp-ai-wpoos-pro' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-ezuite-toolkit-settings' ) ) . '">' . esc_html__( 'EZuite Toolkit Settings', 'mcp-ai-wpoos-pro' ) . '</a>'
			);
			$body .= '</p>';
			$body .= '</body></html>';

			$headers = array( 'Content-Type: text/html; charset=UTF-8' );

			wp_mail( $admin_email, $subject, $body, $headers );

			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					sprintf(
						/* translators: 1: item count, 2: threshold */
						__( 'EZuite low-stock alert sent for %1$d items below threshold %2$d.', 'mcp-ai-wpoos-pro' ),
						count( $items ),
						$threshold
					),
					'info'
				);
			}
		}

		/**
		 * Get the list of SKUs that have already been alerted.
		 *
		 * @since 1.9.0
		 *
		 * @return array Array of alerted SKU strings.
		 */
		public static function get_alerted_skus() {
			$skus = get_option( self::ALERTED_SKUS_OPTION, array() );
			return is_array( $skus ) ? $skus : array();
		}

		/**
		 * Mark a SKU as having been alerted.
		 *
		 * Adds the SKU to the persistent list so it is not re-alerted
		 * on the next sync cycle.
		 *
		 * @since 1.9.0
		 *
		 * @param string $sku The SKU to mark as alerted.
		 */
		public static function mark_alerted( $sku ) {
			$skus = self::get_alerted_skus();

			if ( ! in_array( $sku, $skus, true ) ) {
				$skus[] = $sku;
				update_option( self::ALERTED_SKUS_OPTION, $skus );
			}
		}

		/**
		 * Check if enough time has passed since the last alert.
		 *
		 * Enforces the ALERT_COOLDOWN period to prevent sending
		 * duplicate alerts in rapid succession.
		 *
		 * @since 1.9.0
		 *
		 * @return bool True if an alert can be sent now.
		 */
		public static function can_send_alert() {
			$last_alert = absint( get_option( self::LAST_ALERT_OPTION, 0 ) );

			if ( 0 === $last_alert ) {
				return true;
			}

			return ( time() - $last_alert ) >= self::ALERT_COOLDOWN;
		}
	}
}
