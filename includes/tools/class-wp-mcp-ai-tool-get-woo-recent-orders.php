<?php
/**
 * Tool returning recent WooCommerce orders.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Provides a summary of recent WooCommerce orders.
 */
class WP_MCP_AI_Tool_Get_Woo_Orders implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
	/**
	 * Determine whether WooCommerce is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_orders' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WooCommerce Orders tool is disabled because WooCommerce is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_woo_recent_orders';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Recent WooCommerce Orders', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns recent WooCommerce orders with totals and statuses. Requires WooCommerce.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of orders to retrieve.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
				),
				'status' => array(
					'type'        => 'string',
					'description' => __( 'Optional order status to filter by (e.g. completed).', 'wp-mcp-ai' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_woo_missing', __( 'WooCommerce is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view WooCommerce orders.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'view_woocommerce_reports' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view WooCommerce orders.', 'wp-mcp-ai' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
		$limit = $limit > 0 ? min( $limit, 20 ) : 5;
		$args  = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		if ( ! empty( $arguments['status'] ) ) {
			$args['status'] = sanitize_key( $arguments['status'] );
		}

		$orders  = wc_get_orders( $args );
		$results = array();

		foreach ( $orders as $order ) {
			/** @var WC_Order $order */
			$results[] = array(
				'id'            => $order->get_id(),
				'order_number'  => $order->get_order_number(),
				'status'        => $order->get_status(),
				'total'         => $order->get_total(),
				'currency'      => $order->get_currency(),
				'created_at'    => gmdate( DATE_W3C, $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time() ),
				'billing_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'billing_email' => $order->get_billing_email(),
			);
		}

		return $results;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',      // Requires WooCommerce plugin.
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'cacheable',            // Results can be cached.
			'requires-capability',  // Requires 'manage_woocommerce' or 'view_woocommerce_reports' capability.
			'pii-data',             // Returns personally identifiable information.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip customer PII from order data before sending to LLM.
		// The LLM needs order status and totals for analysis, but doesn't
		// need customer names or email addresses which should remain private.
		$sanitized = array();

		foreach ( $result as $order ) {
			if ( ! is_array( $order ) ) {
				$sanitized[] = $order;
				continue;
			}

			$sanitized_order = $order;

			// Remove customer email - this is PII.
			unset( $sanitized_order['billing_email'] );

			// Remove customer name - this is PII.
			unset( $sanitized_order['billing_name'] );

			// Keep essential fields for LLM analysis:
			// - id, order_number: Order identifiers
			// - status: Order state for workflow decisions
			// - total, currency: Financial data for reporting
			// - created_at: Temporal analysis

			$sanitized[] = $sanitized_order;
		}

		return $sanitized;
	}
}
