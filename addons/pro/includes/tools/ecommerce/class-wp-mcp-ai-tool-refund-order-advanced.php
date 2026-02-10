<?php
/**
 * Refund Order Advanced Tool
 *
 * Process order refunds with inventory restoration, full/partial refunds,
 * reason tracking, and automatic stock updates.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for advanced order refund processing.
 *
 * Supports:
 * - Full and partial refunds
 * - Inventory restoration
 * - Refund reason tracking
 * - Automatic stock updates
 * - Customer notifications
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Refund_Order_Advanced implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
	 */
	public static function is_available() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if e-commerce toolkit is enabled.
		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Order refund processing requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Order refund tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'refund_order_advanced';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Refund Order Advanced', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Process order refunds with automatic inventory restoration. Supports full and partial refunds, reason tracking, stock level adjustments, and customer notifications. Includes refund history and audit trail.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'order_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Order ID to refund', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'refund_type'       => array(
					'type'        => 'string',
					'description' => __( 'Type of refund', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'full', 'partial' ),
					'default'     => 'full',
				),
				'refund_amount'     => array(
					'type'        => 'number',
					'description' => __( 'Refund amount (required for partial refunds)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'line_items'        => array(
					'type'        => 'array',
					'description' => __( 'Line items to refund with quantities (for partial refunds)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'item_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Order item ID', 'mcp-ai-wpoos-pro' ),
							),
							'qty'          => array(
								'type'        => 'integer',
								'description' => __( 'Quantity to refund', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 1,
							),
							'refund_total' => array(
								'type'        => 'number',
								'description' => __( 'Refund amount for this item', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
				'reason'            => array(
					'type'        => 'string',
					'description' => __( 'Reason for refund', 'mcp-ai-wpoos-pro' ),
					'default'     => '',
				),
				'restock_items'     => array(
					'type'        => 'boolean',
					'description' => __( 'Restore inventory for refunded items', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'send_notification' => array(
					'type'        => 'boolean',
					'description' => __( 'Send refund notification email to customer', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'order_id' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
			'requires-plugin',
			'email',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to process refunds.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Validate order ID.
		$order_id = isset( $arguments['order_id'] ) ? absint( $arguments['order_id'] ) : 0;
		if ( ! $order_id ) {
			return new WP_Error(
				'invalid_order',
				__( 'Invalid order ID provided.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$refund_type       = isset( $arguments['refund_type'] ) ? sanitize_text_field( $arguments['refund_type'] ) : 'full';
		$reason            = isset( $arguments['reason'] ) ? sanitize_text_field( $arguments['reason'] ) : '';
		$restock_items     = isset( $arguments['restock_items'] ) ? (bool) $arguments['restock_items'] : true;
		$send_notification = isset( $arguments['send_notification'] ) ? (bool) $arguments['send_notification'] : true;

		// Process refund based on type.
		if ( 'full' === $refund_type ) {
			$result = $this->process_full_refund( $order, $reason, $restock_items );
		} else {
			$result = $this->process_partial_refund( $order, $arguments, $reason, $restock_items );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Send notification if requested.
		if ( $send_notification && $result['refund_id'] ) {
			$refund = wc_get_order( $result['refund_id'] );
			if ( $refund ) {
				do_action( 'woocommerce_order_refunded', $order_id, $result['refund_id'] );
			}
		}

		return array(
			'success'         => true,
			'order_id'        => $order_id,
			'refund_id'       => $result['refund_id'],
			'refund_amount'   => $result['refund_amount'],
			'refund_type'     => $refund_type,
			'items_restocked' => $result['items_restocked'],
			'reason'          => $reason,
			'message'         => sprintf(
				/* translators: 1: Refund amount, 2: Order ID */
				__( 'Refund of %1$s processed successfully for order #%2$d.', 'mcp-ai-wpoos-pro' ),
				wc_price( $result['refund_amount'] ),
				$order_id
			),
		);
	}

	/**
	 * Process full refund.
	 *
	 * @param WC_Order $order         Order object.
	 * @param string   $reason        Refund reason.
	 * @param bool     $restock_items Restock items flag.
	 * @return array|WP_Error Result.
	 */
	protected function process_full_refund( $order, $reason, $restock_items ) {
		$refund_amount = $order->get_total();

		// Create refund.
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $refund_amount,
				'reason'     => $reason,
				'line_items' => array(),
			)
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		// Restock items if requested.
		$items_restocked = 0;
		if ( $restock_items ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				$product = $item->get_product();
				if ( $product && $product->managing_stock() ) {
					$quantity = $item->get_quantity();
					wc_update_product_stock( $product, $quantity, 'increase' );
					$items_restocked += $quantity;
				}
			}
		}

		return array(
			'refund_id'       => $refund->get_id(),
			'refund_amount'   => $refund_amount,
			'items_restocked' => $items_restocked,
		);
	}

	/**
	 * Process partial refund.
	 *
	 * @param WC_Order $order         Order object.
	 * @param array    $arguments     Tool arguments.
	 * @param string   $reason        Refund reason.
	 * @param bool     $restock_items Restock items flag.
	 * @return array|WP_Error Result.
	 */
	protected function process_partial_refund( $order, $arguments, $reason, $restock_items ) {
		$line_items    = isset( $arguments['line_items'] ) && is_array( $arguments['line_items'] ) ? $arguments['line_items'] : array();
		$refund_amount = isset( $arguments['refund_amount'] ) ? floatval( $arguments['refund_amount'] ) : 0;

		if ( empty( $line_items ) && ! $refund_amount ) {
			return new WP_Error(
				'invalid_refund_data',
				__( 'Partial refund requires either line items or refund amount.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare line items for refund.
		$refund_line_items = array();
		foreach ( $line_items as $line_item ) {
			$item_id      = isset( $line_item['item_id'] ) ? absint( $line_item['item_id'] ) : 0;
			$qty          = isset( $line_item['qty'] ) ? absint( $line_item['qty'] ) : 0;
			$refund_total = isset( $line_item['refund_total'] ) ? floatval( $line_item['refund_total'] ) : 0;

			if ( $item_id && $qty ) {
				$refund_line_items[ $item_id ] = array(
					'qty'          => $qty,
					'refund_total' => $refund_total,
					'refund_tax'   => array(),
				);
			}
		}

		// Create refund.
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $refund_amount,
				'reason'     => $reason,
				'line_items' => $refund_line_items,
			)
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		// Restock items if requested.
		$items_restocked = 0;
		if ( $restock_items ) {
			foreach ( $line_items as $line_item ) {
				$item_id = isset( $line_item['item_id'] ) ? absint( $line_item['item_id'] ) : 0;
				$qty     = isset( $line_item['qty'] ) ? absint( $line_item['qty'] ) : 0;

				if ( $item_id && $qty ) {
					$item    = $order->get_item( $item_id );
					$product = $item ? $item->get_product() : null;

					if ( $product && $product->managing_stock() ) {
						wc_update_product_stock( $product, $qty, 'increase' );
						$items_restocked += $qty;
					}
				}
			}
		}

		return array(
			'refund_id'       => $refund->get_id(),
			'refund_amount'   => $refund_amount,
			'items_restocked' => $items_restocked,
		);
	}
}
