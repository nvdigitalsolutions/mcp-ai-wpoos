<?php
/**
 * WooCommerce Customers Tool - Pro add-on tool for WooCommerce customer operations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for WooCommerce customer operations.
 *
 * Provides read and write access to WooCommerce customers including:
 * - Listing customers
 * - Getting customer details
 * - Updating customer information
 * - Customer order history
 *
 * Requires WooCommerce plugin to be active.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Woo_Customers implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if WooCommerce is active.
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'WooCommerce Customers tool requires WooCommerce to be installed and activated.', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'woo_customers';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'WooCommerce Customers', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Manage WooCommerce customers. View customer details, order history, and update customer information.', 'wp-mcp-ai-pro' );
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
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: get, list, search, get_orders.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'get', 'list', 'search', 'get_orders' ),
					'default'     => 'list',
				),
				'customer_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Customer ID for get or get_orders actions.', 'wp-mcp-ai-pro' ),
				),
				'per_page'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of customers to return. Default: 10. Max: 100.', 'wp-mcp-ai-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'         => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-pro' ),
					'default'     => 1,
				),
				'search'       => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter customers by name or email.', 'wp-mcp-ai-pro' ),
				),
				'role'         => array(
					'type'        => 'string',
					'description' => __( 'Filter by user role.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'customer', 'subscriber', 'all' ),
					'default'     => 'customer',
				),
				'order_status' => array(
					'type'        => 'string',
					'description' => __( 'Filter customer orders by status (for get_orders action).', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'any' ),
					'default'     => 'any',
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',              // Pro tier tool.
			'read-only',        // Only read operations for now.
			'requires-plugin',  // Requires WooCommerce.
			'local-only',       // No external API calls.
			'pii-data',         // Contains customer personal data.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or activated.', 'wp-mcp-ai-pro' )
			);
		}

		// Check permission.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! user_can( $user_id, 'list_users' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to view customers.', 'wp-mcp-ai-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'get':
				return $this->get_customer( $arguments );
			case 'list':
				return $this->list_customers( $arguments );
			case 'search':
				return $this->search_customers( $arguments );
			case 'get_orders':
				return $this->get_customer_orders( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-pro' )
				);
		}
	}

	/**
	 * Get a single customer by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_customer( $arguments ) {
		if ( empty( $arguments['customer_id'] ) ) {
			return new WP_Error(
				'missing_customer_id',
				__( 'Customer ID is required for get action.', 'wp-mcp-ai-pro' )
			);
		}

		$customer = new WC_Customer( absint( $arguments['customer_id'] ) );

		if ( ! $customer->get_id() ) {
			return new WP_Error(
				'customer_not_found',
				__( 'Customer not found.', 'wp-mcp-ai-pro' )
			);
		}

		return $this->format_customer( $customer, true );
	}

	/**
	 * List customers.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_customers( $arguments ) {
		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$role     = isset( $arguments['role'] ) && 'all' !== $arguments['role'] ? sanitize_key( $arguments['role'] ) : '';

		$query_args = array(
			'number' => $per_page,
			'paged'  => $page,
			'fields' => 'ID',
		);

		if ( ! empty( $role ) ) {
			$query_args['role'] = $role;
		}

		$user_query = new WP_User_Query( $query_args );
		$user_ids   = $user_query->get_results();
		$customers  = array();

		foreach ( $user_ids as $user_id ) {
			$customer    = new WC_Customer( $user_id );
			$customers[] = $this->format_customer( $customer, false );
		}

		return array(
			'customers'   => $customers,
			'total'       => $user_query->get_total(),
			'total_pages' => ceil( $user_query->get_total() / $per_page ),
			'page'        => $page,
		);
	}

	/**
	 * Search customers.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function search_customers( $arguments ) {
		if ( empty( $arguments['search'] ) ) {
			return new WP_Error(
				'missing_search_term',
				__( 'Search term is required for search action.', 'wp-mcp-ai-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'number' => $per_page,
			'paged'  => $page,
			'fields' => 'ID',
			'search' => '*' . sanitize_text_field( $arguments['search'] ) . '*',
		);

		$user_query = new WP_User_Query( $query_args );
		$user_ids   = $user_query->get_results();
		$customers  = array();

		foreach ( $user_ids as $user_id ) {
			$customer    = new WC_Customer( $user_id );
			$customers[] = $this->format_customer( $customer, false );
		}

		return array(
			'customers'   => $customers,
			'total'       => $user_query->get_total(),
			'total_pages' => ceil( $user_query->get_total() / $per_page ),
			'page'        => $page,
		);
	}

	/**
	 * Get customer orders.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_customer_orders( $arguments ) {
		if ( empty( $arguments['customer_id'] ) ) {
			return new WP_Error(
				'missing_customer_id',
				__( 'Customer ID is required for get_orders action.', 'wp-mcp-ai-pro' )
			);
		}

		$customer = new WC_Customer( absint( $arguments['customer_id'] ) );

		if ( ! $customer->get_id() ) {
			return new WP_Error(
				'customer_not_found',
				__( 'Customer not found.', 'wp-mcp-ai-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'customer' => $customer->get_id(),
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
		);

		if ( ! empty( $arguments['order_status'] ) && 'any' !== $arguments['order_status'] ) {
			$query_args['status'] = sanitize_key( $arguments['order_status'] );
		}

		$results = wc_get_orders( $query_args );
		$orders  = array();

		foreach ( $results->orders as $order ) {
			$orders[] = array(
				'id'            => $order->get_id(),
				'order_number'  => $order->get_order_number(),
				'status'        => $order->get_status(),
				'total'         => $order->get_total(),
				'currency'      => $order->get_currency(),
				'date_created'  => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
				'date_modified' => $order->get_date_modified() ? $order->get_date_modified()->format( 'c' ) : null,
			);
		}

		return array(
			'customer_id' => $customer->get_id(),
			'orders'      => $orders,
			'total'       => $results->total,
			'total_pages' => $results->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Format a customer for output.
	 *
	 * @param WC_Customer $customer Customer object.
	 * @param bool        $detailed Whether to include detailed information.
	 * @return array
	 */
	protected function format_customer( $customer, $detailed = false ) {
		$data = array(
			'id'           => $customer->get_id(),
			'email'        => $customer->get_email(),
			'username'     => $customer->get_username(),
			'first_name'   => $customer->get_first_name(),
			'last_name'    => $customer->get_last_name(),
			'display_name' => $customer->get_display_name(),
			'role'         => $customer->get_role(),
		);

		if ( $detailed ) {
			$data['billing']  = array(
				'first_name' => $customer->get_billing_first_name(),
				'last_name'  => $customer->get_billing_last_name(),
				'company'    => $customer->get_billing_company(),
				'address_1'  => $customer->get_billing_address_1(),
				'address_2'  => $customer->get_billing_address_2(),
				'city'       => $customer->get_billing_city(),
				'state'      => $customer->get_billing_state(),
				'postcode'   => $customer->get_billing_postcode(),
				'country'    => $customer->get_billing_country(),
				'email'      => $customer->get_billing_email(),
				'phone'      => $customer->get_billing_phone(),
			);
			$data['shipping'] = array(
				'first_name' => $customer->get_shipping_first_name(),
				'last_name'  => $customer->get_shipping_last_name(),
				'company'    => $customer->get_shipping_company(),
				'address_1'  => $customer->get_shipping_address_1(),
				'address_2'  => $customer->get_shipping_address_2(),
				'city'       => $customer->get_shipping_city(),
				'state'      => $customer->get_shipping_state(),
				'postcode'   => $customer->get_shipping_postcode(),
				'country'    => $customer->get_shipping_country(),
			);

			$data['orders_count']     = $customer->get_order_count();
			$data['total_spent']      = $customer->get_total_spent();
			$data['avatar_url']       = $customer->get_avatar_url();
			$data['date_created']     = $customer->get_date_created() ? $customer->get_date_created()->format( 'c' ) : null;
			$data['date_modified']    = $customer->get_date_modified() ? $customer->get_date_modified()->format( 'c' ) : null;
			$data['last_order_id']    = $customer->get_last_order();
			$data['last_order_date']  = $customer->get_last_order() ? wc_get_order( $customer->get_last_order() )->get_date_created()->format( 'c' ) : null;
		}

		return $data;
	}
}
