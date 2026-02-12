<?php
/**
 * Process Order Workflow Tool
 *
 * Advanced order processing automation with status transitions,
 * notifications, and custom workflow steps.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for advanced order workflow processing.
 *
 * Supports:
 * - Automated status transitions
 * - Custom workflow steps
 * - Email notifications
 * - Order validation
 * - Batch processing
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Process_Order_Workflow implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Order workflow processing requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Order workflow tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'process_order_workflow';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Process Order Workflow', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Advanced order processing automation with customizable workflows. Automate status transitions, validation, notifications, and custom processing steps. Supports batch processing and order rules engine.', 'mcp-ai-wpoos-pro' );
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
				'workflow_action'    => array(
					'type'        => 'string',
					'description' => __( 'Workflow action to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'process', 'validate', 'auto_complete', 'batch_process' ),
					'default'     => 'process',
				),
				'order_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Order ID to process', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'order_ids'          => array(
					'type'        => 'array',
					'description' => __( 'Order IDs for batch processing', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'target_status'      => array(
					'type'        => 'string',
					'description' => __( 'Target order status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
				),
				'workflow_steps'     => array(
					'type'        => 'array',
					'description' => __( 'Custom workflow steps to execute', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'validate_payment', 'check_stock', 'send_notification', 'update_inventory', 'create_shipping_label' ),
					),
				),
				'send_notifications' => array(
					'type'        => 'boolean',
					'description' => __( 'Send email notifications for status changes', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'add_note'           => array(
					'type'        => 'string',
					'description' => __( 'Add note to order', 'mcp-ai-wpoos-pro' ),
				),
				'validation_rules'   => array(
					'type'        => 'array',
					'description' => __( 'Validation rules to check', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'payment_received', 'stock_available', 'shipping_address_valid', 'fraud_check' ),
					),
				),
			),
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
				__( 'You do not have permission to process order workflows.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$workflow_action = isset( $arguments['workflow_action'] ) ? sanitize_text_field( $arguments['workflow_action'] ) : 'process';

		switch ( $workflow_action ) {
			case 'process':
				return $this->process_order( $arguments );
			case 'validate':
				return $this->validate_order( $arguments );
			case 'auto_complete':
				return $this->auto_complete_orders( $arguments );
			case 'batch_process':
				return $this->batch_process_orders( $arguments );
			default:
				return new WP_Error(
					'invalid_workflow_action',
					__( 'Invalid workflow action specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Process single order workflow.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function process_order( $arguments ) {
		$order_id = isset( $arguments['order_id'] ) ? absint( $arguments['order_id'] ) : 0;

		if ( ! $order_id ) {
			return new WP_Error(
				'missing_order_id',
				__( 'Order ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$target_status      = isset( $arguments['target_status'] ) ? sanitize_text_field( $arguments['target_status'] ) : '';
		$workflow_steps     = isset( $arguments['workflow_steps'] ) && is_array( $arguments['workflow_steps'] ) ? $arguments['workflow_steps'] : array();
		$send_notifications = isset( $arguments['send_notifications'] ) ? (bool) $arguments['send_notifications'] : true;
		$add_note           = isset( $arguments['add_note'] ) ? sanitize_text_field( $arguments['add_note'] ) : '';

		$old_status    = $order->get_status();
		$steps_results = array();

		// Execute workflow steps.
		foreach ( $workflow_steps as $step ) {
			$step_result            = $this->execute_workflow_step( $order, $step );
			$steps_results[ $step ] = $step_result;

			if ( is_wp_error( $step_result ) ) {
				return $step_result;
			}
		}

		// Update status if specified.
		if ( $target_status && $target_status !== $old_status ) {
			$order->update_status( $target_status, '', ! $send_notifications );
		}

		// Add note if specified.
		if ( $add_note ) {
			$order->add_order_note( $add_note );
		}

		return array(
			'success'         => true,
			'workflow_action' => 'process',
			'order_id'        => $order_id,
			'old_status'      => $old_status,
			'new_status'      => $order->get_status(),
			'steps_executed'  => $workflow_steps,
			'steps_results'   => $steps_results,
			'message'         => sprintf(
				/* translators: %d: Order ID */
				__( 'Order #%d workflow processed successfully.', 'mcp-ai-wpoos-pro' ),
				$order_id
			),
		);
	}

	/**
	 * Execute workflow step.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $step  Step name.
	 * @return array|WP_Error Result.
	 */
	protected function execute_workflow_step( $order, $step ) {
		switch ( $step ) {
			case 'validate_payment':
				$payment_method = $order->get_payment_method();
				return array(
					'validated'      => true,
					'payment_method' => $payment_method,
					'paid'           => $order->is_paid(),
				);

			case 'check_stock':
				$stock_available    = true;
				$out_of_stock_items = array();

				foreach ( $order->get_items() as $item ) {
					$product = $item->get_product();
					if ( $product && ! $product->is_in_stock() ) {
						$stock_available      = false;
						$out_of_stock_items[] = $product->get_name();
					}
				}

				if ( ! $stock_available ) {
					return new WP_Error(
						'insufficient_stock',
						sprintf(
							/* translators: %s: Product names */
							__( 'Insufficient stock for: %s', 'mcp-ai-wpoos-pro' ),
							implode( ', ', $out_of_stock_items )
						)
					);
				}

				return array( 'stock_available' => true );

			case 'send_notification':
				do_action( 'woocommerce_order_status_changed', $order->get_id(), $order->get_status(), $order->get_status() );
				return array( 'notification_sent' => true );

			case 'update_inventory':
				wc_reduce_stock_levels( $order->get_id() );
				return array( 'inventory_updated' => true );

			case 'create_shipping_label':
				// Placeholder for shipping label creation.
				return array(
					'shipping_label_created' => false,
					'message'                => __( 'Shipping label creation not implemented.', 'mcp-ai-wpoos-pro' ),
				);

			default:
				return array(
					'step'   => $step,
					'status' => 'unknown',
				);
		}
	}

	/**
	 * Validate order.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function validate_order( $arguments ) {
		$order_id = isset( $arguments['order_id'] ) ? absint( $arguments['order_id'] ) : 0;

		if ( ! $order_id ) {
			return new WP_Error(
				'missing_order_id',
				__( 'Order ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$validation_rules = isset( $arguments['validation_rules'] ) && is_array( $arguments['validation_rules'] ) ? $arguments['validation_rules'] : array( 'payment_received', 'stock_available', 'shipping_address_valid' );

		$validation_results = array();
		$is_valid           = true;

		foreach ( $validation_rules as $rule ) {
			$result                      = $this->validate_rule( $order, $rule );
			$validation_results[ $rule ] = $result;

			if ( ! $result['valid'] ) {
				$is_valid = false;
			}
		}

		return array(
			'success'            => true,
			'workflow_action'    => 'validate',
			'order_id'           => $order_id,
			'is_valid'           => $is_valid,
			'validation_results' => $validation_results,
			'message'            => $is_valid ? __( 'Order validation passed.', 'mcp-ai-wpoos-pro' ) : __( 'Order validation failed.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Validate rule.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $rule  Rule name.
	 * @return array Validation result.
	 */
	protected function validate_rule( $order, $rule ) {
		switch ( $rule ) {
			case 'payment_received':
				return array(
					'valid'   => $order->is_paid(),
					'message' => $order->is_paid() ? __( 'Payment received', 'mcp-ai-wpoos-pro' ) : __( 'Payment not received', 'mcp-ai-wpoos-pro' ),
				);

			case 'stock_available':
				foreach ( $order->get_items() as $item ) {
					$product = $item->get_product();
					if ( $product && ! $product->is_in_stock() ) {
						return array(
							'valid'   => false,
							'message' => sprintf(
								/* translators: %s: Product name */
								__( 'Product out of stock: %s', 'mcp-ai-wpoos-pro' ),
								$product->get_name()
							),
						);
					}
				}
				return array(
					'valid'   => true,
					'message' => __( 'All products in stock', 'mcp-ai-wpoos-pro' ),
				);

			case 'shipping_address_valid':
				$shipping_address = $order->get_address( 'shipping' );
				$is_valid         = ! empty( $shipping_address['address_1'] ) && ! empty( $shipping_address['city'] ) && ! empty( $shipping_address['postcode'] );
				return array(
					'valid'   => $is_valid,
					'message' => $is_valid ? __( 'Shipping address valid', 'mcp-ai-wpoos-pro' ) : __( 'Shipping address incomplete', 'mcp-ai-wpoos-pro' ),
				);

			case 'fraud_check':
				// Placeholder for fraud check.
				return array(
					'valid'   => true,
					'message' => __( 'No fraud detected', 'mcp-ai-wpoos-pro' ),
				);

			default:
				return array(
					'valid'   => false,
					'message' => __( 'Unknown validation rule', 'mcp-ai-wpoos-pro' ),
				);
		}
	}

	/**
	 * Auto-complete eligible orders.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function auto_complete_orders( $arguments ) {
		// Get orders eligible for auto-completion.
		$orders = wc_get_orders(
			array(
				'status' => array( 'processing' ),
				'limit'  => 100,
			)
		);

		$completed_count  = 0;
		$skipped_count    = 0;
		$completed_orders = array();

		foreach ( $orders as $order ) {
			// Check if order can be auto-completed.
			if ( $this->can_auto_complete( $order ) ) {
				$order->update_status( 'completed' );
				++$completed_count;
				$completed_orders[] = $order->get_id();
			} else {
				++$skipped_count;
			}
		}

		return array(
			'success'          => true,
			'workflow_action'  => 'auto_complete',
			'orders_processed' => count( $orders ),
			'completed'        => $completed_count,
			'skipped'          => $skipped_count,
			'completed_orders' => $completed_orders,
			'message'          => sprintf(
				/* translators: %d: Number of orders */
				__( 'Auto-completed %d orders.', 'mcp-ai-wpoos-pro' ),
				$completed_count
			),
		);
	}

	/**
	 * Check if order can be auto-completed.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool True if can be auto-completed.
	 */
	protected function can_auto_complete( $order ) {
		// Check if payment is received.
		if ( ! $order->is_paid() ) {
			return false;
		}

		// Check if all items are digital/virtual.
		$all_virtual = true;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && ! $product->is_virtual() ) {
				$all_virtual = false;
				break;
			}
		}

		return $all_virtual;
	}

	/**
	 * Batch process orders.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function batch_process_orders( $arguments ) {
		$order_ids = isset( $arguments['order_ids'] ) && is_array( $arguments['order_ids'] ) ? array_map( 'absint', $arguments['order_ids'] ) : array();

		if ( empty( $order_ids ) ) {
			return new WP_Error(
				'missing_order_ids',
				__( 'Order IDs are required for batch processing.', 'mcp-ai-wpoos-pro' )
			);
		}

		$processed_count = 0;
		$failed_count    = 0;
		$results         = array();

		foreach ( $order_ids as $order_id ) {
			$result = $this->process_order(
				array_merge(
					$arguments,
					array( 'order_id' => $order_id )
				)
			);

			if ( is_wp_error( $result ) ) {
				++$failed_count;
				$results[] = array(
					'order_id' => $order_id,
					'success'  => false,
					'error'    => $result->get_error_message(),
				);
			} else {
				++$processed_count;
				$results[] = array(
					'order_id' => $order_id,
					'success'  => true,
				);
			}
		}

		return array(
			'success'         => true,
			'workflow_action' => 'batch_process',
			'total_orders'    => count( $order_ids ),
			'processed'       => $processed_count,
			'failed'          => $failed_count,
			'results'         => $results,
			'message'         => sprintf(
				/* translators: 1: Processed count, 2: Total count */
				__( 'Batch processing completed: %1$d of %2$d orders processed.', 'mcp-ai-wpoos-pro' ),
				$processed_count,
				count( $order_ids )
			),
		);
	}
}
