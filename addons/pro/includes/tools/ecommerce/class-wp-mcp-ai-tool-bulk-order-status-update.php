<?php
/**
 * Bulk Order Status Update Tool
 *
 * Updates the status of multiple WooCommerce orders at once with
 * optional customer notifications and custom order notes.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for bulk updating WooCommerce order statuses.
 *
 * Supports:
 * - Batch status updates
 * - Customer email notifications
 * - Custom order notes
 * - Filter-based order selection
 * - Dry-run preview mode
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Bulk_Order_Status_Update implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Bulk order status update requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Bulk order status update tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'bulk_order_status_update';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Bulk Update Order Status', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Update the status of multiple WooCommerce orders at once. Supports customer notifications, custom order notes, filter-based selection, and dry-run preview mode.', 'mcp-ai-wpoos-pro' );
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
				'order_ids'         => array(
					'type'        => 'array',
					'description' => __( 'Array of order IDs to update (required if no filter)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				'filter'            => array(
					'type'        => 'object',
					'description' => __( 'Filter criteria to select orders (alternative to order_ids)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'status'      => array(
							'type'        => 'string',
							'description' => 'Current order status to filter by',
						),
						'date_from'   => array(
							'type'        => 'string',
							'description' => 'Start date (Y-m-d format)',
						),
						'date_to'     => array(
							'type'        => 'string',
							'description' => 'End date (Y-m-d format)',
						),
						'customer_id' => array(
							'type'        => 'integer',
							'description' => 'Filter by customer ID',
						),
						'limit'       => array(
							'type'        => 'integer',
							'description' => 'Maximum orders to update',
							'default'     => 100,
						),
					),
				),
				'new_status'        => array(
					'type'        => 'string',
					'description' => __( 'New order status (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
				),
				'send_notification' => array(
					'type'        => 'boolean',
					'description' => __( 'Send customer email notification', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'note'              => array(
					'type'        => 'string',
					'description' => __( 'Custom order note to add', 'mcp-ai-wpoos-pro' ),
				),
				'note_type'         => array(
					'type'        => 'string',
					'description' => __( 'Type of order note', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'customer', 'private' ),
					'default'     => 'private',
				),
				'dry_run'           => array(
					'type'        => 'boolean',
					'description' => __( 'Preview changes without applying them', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'new_status' ),
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
			'database-write',
			'requires-plugin',
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
				__( 'You do not have permission to update order statuses.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Validate new status.
		if ( empty( $arguments['new_status'] ) ) {
			return new WP_Error(
				'missing_status',
				__( 'New order status is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$new_status = sanitize_text_field( $arguments['new_status'] );

		// Get orders to update.
		$order_ids = $this->get_orders_to_update( $arguments );

		if ( is_wp_error( $order_ids ) ) {
			return $order_ids;
		}

		if ( empty( $order_ids ) ) {
			return new WP_Error(
				'no_orders_found',
				__( 'No orders found matching the criteria.', 'mcp-ai-wpoos-pro' )
			);
		}

		$dry_run           = isset( $arguments['dry_run'] ) && $arguments['dry_run'];
		$send_notification = isset( $arguments['send_notification'] ) && $arguments['send_notification'];
		$note              = isset( $arguments['note'] ) ? sanitize_textarea_field( $arguments['note'] ) : '';
		$note_type         = isset( $arguments['note_type'] ) ? sanitize_text_field( $arguments['note_type'] ) : 'private';

		// Apply updates.
		$results = array(
			'success'        => true,
			'dry_run'        => $dry_run,
			'total_found'    => count( $order_ids ),
			'updated'        => 0,
			'failed'         => 0,
			'updated_orders' => array(),
			'errors'         => array(),
		);

		foreach ( $order_ids as $order_id ) {
			$result = $this->update_single_order( $order_id, $new_status, $send_notification, $note, $note_type, $dry_run );

			if ( is_wp_error( $result ) ) {
				++$results['failed'];
				$results['errors'][] = array(
					'order_id' => $order_id,
					'error'    => $result->get_error_message(),
				);
			} else {
				++$results['updated'];
				$results['updated_orders'][] = $result;
			}
		}

		$results['message'] = sprintf(
			/* translators: 1: Number of updated orders, 2: Number of total orders, 3: Dry run indicator */
			__( '%1$d of %2$d orders %3$s.', 'mcp-ai-wpoos-pro' ),
			$results['updated'],
			$results['total_found'],
			$dry_run ? __( 'would be updated (dry run)', 'mcp-ai-wpoos-pro' ) : __( 'updated successfully', 'mcp-ai-wpoos-pro' )
		);

		return $results;
	}

	/**
	 * Get orders to update based on IDs or filter.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Array of order IDs or error.
	 */
	protected function get_orders_to_update( $arguments ) {
		// Use order_ids if provided.
		if ( ! empty( $arguments['order_ids'] ) && is_array( $arguments['order_ids'] ) ) {
			return array_map( 'absint', $arguments['order_ids'] );
		}

		// Use filter if provided.
		if ( ! empty( $arguments['filter'] ) && is_array( $arguments['filter'] ) ) {
			return $this->get_orders_by_filter( $arguments['filter'] );
		}

		return new WP_Error(
			'missing_orders',
			__( 'Either order_ids or filter is required.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Get orders by filter criteria.
	 *
	 * @param array $filter Filter criteria.
	 * @return array Array of order IDs.
	 */
	protected function get_orders_by_filter( $filter ) {
		$args = array(
			'limit'  => isset( $filter['limit'] ) ? absint( $filter['limit'] ) : 100,
			'return' => 'ids',
		);

		// Add status filter.
		if ( ! empty( $filter['status'] ) ) {
			$args['status'] = sanitize_text_field( $filter['status'] );
		}

		// Add date filters.
		if ( ! empty( $filter['date_from'] ) ) {
			$args['date_created'] = '>=' . sanitize_text_field( $filter['date_from'] );
		}

		if ( ! empty( $filter['date_to'] ) ) {
			if ( ! empty( $args['date_created'] ) ) {
				$args['date_created'] .= '...<=' . sanitize_text_field( $filter['date_to'] );
			} else {
				$args['date_created'] = '<=' . sanitize_text_field( $filter['date_to'] );
			}
		}

		// Add customer filter.
		if ( ! empty( $filter['customer_id'] ) ) {
			$args['customer_id'] = absint( $filter['customer_id'] );
		}

		$orders = wc_get_orders( $args );
		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Update a single order.
	 *
	 * @param int    $order_id          Order ID.
	 * @param string $new_status        New status.
	 * @param bool   $send_notification Send notification.
	 * @param string $note              Order note.
	 * @param string $note_type         Note type.
	 * @param bool   $dry_run           Whether this is a dry run.
	 * @return array|WP_Error Order data or error.
	 */
	protected function update_single_order( $order_id, $new_status, $send_notification, $note, $note_type, $dry_run = false ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'invalid_order',
				sprintf(
					/* translators: %d: Order ID */
					__( 'Order %d not found.', 'mcp-ai-wpoos-pro' ),
					$order_id
				)
			);
		}

		$old_status = $order->get_status();

		// Don't update if already at the target status.
		if ( $old_status === $new_status ) {
			return array(
				'order_id'   => $order_id,
				'old_status' => $old_status,
				'new_status' => $new_status,
				'changed'    => false,
			);
		}

		if ( ! $dry_run ) {
			// Update order status.
			$order->update_status( $new_status, '', $send_notification );

			// Add custom note if provided.
			if ( ! empty( $note ) ) {
				$is_customer_note = ( 'customer' === $note_type );
				$order->add_order_note( $note, $is_customer_note );
			}
		}

		return array(
			'order_id'     => $order_id,
			'order_number' => $order->get_order_number(),
			'old_status'   => $old_status,
			'new_status'   => $new_status,
			'changed'      => true,
			'customer'     => array(
				'name'  => $order->get_formatted_billing_full_name(),
				'email' => $order->get_billing_email(),
			),
		);
	}
}
