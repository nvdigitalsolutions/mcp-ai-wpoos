<?php
/**
 * WooCommerce Coupons Tool - Pro add-on tool for WooCommerce coupon operations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for WooCommerce coupon operations.
 *
 * Provides CRUD operations for WooCommerce coupons including:
 * - Listing coupons
 * - Getting coupon details
 * - Creating coupons
 * - Updating coupons
 * - Deleting coupons
 *
 * Requires WooCommerce plugin to be active.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Woo_Coupons implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'WooCommerce Coupons tool requires WooCommerce to be installed and activated.', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'woo_coupons';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'WooCommerce Coupons', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Manage WooCommerce coupons. Create, update, list, and delete discount coupons with flexible restrictions.', 'wp-mcp-ai-pro' );
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
				'action'                 => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: get, list, create, update, delete.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'get', 'list', 'create', 'update', 'delete' ),
					'default'     => 'list',
				),
				'coupon_id'              => array(
					'type'        => 'integer',
					'description' => __( 'Coupon ID for get, update, or delete actions.', 'wp-mcp-ai-pro' ),
				),
				'code'                   => array(
					'type'        => 'string',
					'description' => __( 'Coupon code. Required for create action.', 'wp-mcp-ai-pro' ),
				),
				'discount_type'          => array(
					'type'        => 'string',
					'description' => __( 'Discount type.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'fixed_cart', 'percent', 'fixed_product', 'percent_product' ),
					'default'     => 'percent',
				),
				'amount'                 => array(
					'type'        => 'string',
					'description' => __( 'Coupon discount amount.', 'wp-mcp-ai-pro' ),
				),
				'individual_use'         => array(
					'type'        => 'boolean',
					'description' => __( 'If true, coupon cannot be used with other coupons.', 'wp-mcp-ai-pro' ),
					'default'     => false,
				),
				'exclude_sale_items'     => array(
					'type'        => 'boolean',
					'description' => __( 'If true, coupon will not apply to items on sale.', 'wp-mcp-ai-pro' ),
					'default'     => false,
				),
				'minimum_amount'         => array(
					'type'        => 'string',
					'description' => __( 'Minimum order amount that needs to be in the cart before coupon applies.', 'wp-mcp-ai-pro' ),
				),
				'maximum_amount'         => array(
					'type'        => 'string',
					'description' => __( 'Maximum order amount allowed when using the coupon.', 'wp-mcp-ai-pro' ),
				),
				'product_ids'            => array(
					'type'        => 'array',
					'description' => __( 'List of product IDs the coupon can be used with.', 'wp-mcp-ai-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'excluded_product_ids'   => array(
					'type'        => 'array',
					'description' => __( 'List of product IDs the coupon cannot be used with.', 'wp-mcp-ai-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'usage_limit'            => array(
					'type'        => 'integer',
					'description' => __( 'How many times the coupon can be used in total.', 'wp-mcp-ai-pro' ),
				),
				'usage_limit_per_user'   => array(
					'type'        => 'integer',
					'description' => __( 'How many times the coupon can be used per customer.', 'wp-mcp-ai-pro' ),
				),
				'limit_usage_to_x_items' => array(
					'type'        => 'integer',
					'description' => __( 'Max number of items in the cart the coupon can be applied to.', 'wp-mcp-ai-pro' ),
				),
				'free_shipping'          => array(
					'type'        => 'boolean',
					'description' => __( 'If true, this coupon will grant free shipping.', 'wp-mcp-ai-pro' ),
					'default'     => false,
				),
				'expiry_date'            => array(
					'type'        => 'string',
					'description' => __( 'Expiry date for the coupon (ISO 8601 format).', 'wp-mcp-ai-pro' ),
				),
				'per_page'               => array(
					'type'        => 'integer',
					'description' => __( 'Number of coupons to return. Default: 10. Max: 100.', 'wp-mcp-ai-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'                   => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-pro' ),
					'default'     => 1,
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
			'read-only',        // list/get operations.
			'write',            // create/update/delete operations.
			'requires-plugin',  // Requires WooCommerce.
			'local-only',       // No external API calls.
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

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$action  = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		// Check permissions based on action.
		$write_actions = array( 'create', 'update', 'delete' );
		if ( in_array( $action, $write_actions, true ) && ! user_can( $user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to manage coupons.', 'wp-mcp-ai-pro' )
			);
		}

		switch ( $action ) {
			case 'get':
				return $this->get_coupon( $arguments );
			case 'list':
				return $this->list_coupons( $arguments );
			case 'create':
				return $this->create_coupon( $arguments, $context );
			case 'update':
				return $this->update_coupon( $arguments, $context );
			case 'delete':
				return $this->delete_coupon( $arguments, $context );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-pro' )
				);
		}
	}

	/**
	 * Get a single coupon by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_coupon( $arguments ) {
		if ( empty( $arguments['coupon_id'] ) ) {
			return new WP_Error(
				'missing_coupon_id',
				__( 'Coupon ID is required for get action.', 'wp-mcp-ai-pro' )
			);
		}

		$coupon = new WC_Coupon( absint( $arguments['coupon_id'] ) );

		if ( ! $coupon->get_id() ) {
			return new WP_Error(
				'coupon_not_found',
				__( 'Coupon not found.', 'wp-mcp-ai-pro' )
			);
		}

		return $this->format_coupon( $coupon );
	}

	/**
	 * List coupons.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_coupons( $arguments ) {
		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$query   = new WP_Query( $query_args );
		$coupons = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$coupon    = new WC_Coupon( get_the_ID() );
				$coupons[] = $this->format_coupon( $coupon );
			}
			wp_reset_postdata();
		}

		return array(
			'coupons'     => $coupons,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Create a new coupon.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function create_coupon( $arguments, $context ) {
		if ( empty( $arguments['code'] ) ) {
			return new WP_Error(
				'missing_code',
				__( 'Coupon code is required for create action.', 'wp-mcp-ai-pro' )
			);
		}

		$coupon = new WC_Coupon();
		$coupon->set_code( wc_sanitize_coupon_code( $arguments['code'] ) );

		// Apply coupon settings.
		$this->apply_coupon_settings( $coupon, $arguments );

		$coupon_id = $coupon->save();

		if ( ! $coupon_id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create coupon.', 'wp-mcp-ai-pro' )
			);
		}

		return $this->format_coupon( $coupon );
	}

	/**
	 * Update an existing coupon.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function update_coupon( $arguments, $context ) {
		if ( empty( $arguments['coupon_id'] ) ) {
			return new WP_Error(
				'missing_coupon_id',
				__( 'Coupon ID is required for update action.', 'wp-mcp-ai-pro' )
			);
		}

		$coupon = new WC_Coupon( absint( $arguments['coupon_id'] ) );

		if ( ! $coupon->get_id() ) {
			return new WP_Error(
				'coupon_not_found',
				__( 'Coupon not found.', 'wp-mcp-ai-pro' )
			);
		}

		// Apply coupon settings.
		$this->apply_coupon_settings( $coupon, $arguments );

		$coupon->save();

		return $this->format_coupon( $coupon );
	}

	/**
	 * Delete a coupon.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function delete_coupon( $arguments, $context ) {
		if ( empty( $arguments['coupon_id'] ) ) {
			return new WP_Error(
				'missing_coupon_id',
				__( 'Coupon ID is required for delete action.', 'wp-mcp-ai-pro' )
			);
		}

		$coupon = new WC_Coupon( absint( $arguments['coupon_id'] ) );

		if ( ! $coupon->get_id() ) {
			return new WP_Error(
				'coupon_not_found',
				__( 'Coupon not found.', 'wp-mcp-ai-pro' )
			);
		}

		$coupon_code = $coupon->get_code();
		$coupon_id   = $coupon->get_id();

		$coupon->delete( true );

		return array(
			'success'   => true,
			'coupon_id' => $coupon_id,
			'code'      => $coupon_code,
			'message'   => __( 'Coupon deleted successfully.', 'wp-mcp-ai-pro' ),
		);
	}

	/**
	 * Apply coupon settings to a coupon object.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @param array     $arguments Tool arguments.
	 * @return void
	 */
	protected function apply_coupon_settings( $coupon, $arguments ) {
		if ( ! empty( $arguments['discount_type'] ) ) {
			$coupon->set_discount_type( sanitize_key( $arguments['discount_type'] ) );
		}

		if ( isset( $arguments['amount'] ) ) {
			$coupon->set_amount( wc_format_decimal( $arguments['amount'] ) );
		}

		if ( isset( $arguments['individual_use'] ) ) {
			$coupon->set_individual_use( (bool) $arguments['individual_use'] );
		}

		if ( isset( $arguments['exclude_sale_items'] ) ) {
			$coupon->set_exclude_sale_items( (bool) $arguments['exclude_sale_items'] );
		}

		if ( isset( $arguments['minimum_amount'] ) ) {
			$coupon->set_minimum_amount( wc_format_decimal( $arguments['minimum_amount'] ) );
		}

		if ( isset( $arguments['maximum_amount'] ) ) {
			$coupon->set_maximum_amount( wc_format_decimal( $arguments['maximum_amount'] ) );
		}

		if ( ! empty( $arguments['product_ids'] ) && is_array( $arguments['product_ids'] ) ) {
			$coupon->set_product_ids( array_map( 'absint', $arguments['product_ids'] ) );
		}

		if ( ! empty( $arguments['excluded_product_ids'] ) && is_array( $arguments['excluded_product_ids'] ) ) {
			$coupon->set_excluded_product_ids( array_map( 'absint', $arguments['excluded_product_ids'] ) );
		}

		if ( isset( $arguments['usage_limit'] ) ) {
			$coupon->set_usage_limit( absint( $arguments['usage_limit'] ) );
		}

		if ( isset( $arguments['usage_limit_per_user'] ) ) {
			$coupon->set_usage_limit_per_user( absint( $arguments['usage_limit_per_user'] ) );
		}

		if ( isset( $arguments['limit_usage_to_x_items'] ) ) {
			$coupon->set_limit_usage_to_x_items( absint( $arguments['limit_usage_to_x_items'] ) );
		}

		if ( isset( $arguments['free_shipping'] ) ) {
			$coupon->set_free_shipping( (bool) $arguments['free_shipping'] );
		}

		if ( ! empty( $arguments['expiry_date'] ) ) {
			$coupon->set_date_expires( sanitize_text_field( $arguments['expiry_date'] ) );
		}
	}

	/**
	 * Format a coupon for output.
	 *
	 * @param WC_Coupon $coupon Coupon object.
	 * @return array
	 */
	protected function format_coupon( $coupon ) {
		return array(
			'id'                     => $coupon->get_id(),
			'code'                   => $coupon->get_code(),
			'amount'                 => $coupon->get_amount(),
			'discount_type'          => $coupon->get_discount_type(),
			'individual_use'         => $coupon->get_individual_use(),
			'product_ids'            => $coupon->get_product_ids(),
			'excluded_product_ids'   => $coupon->get_excluded_product_ids(),
			'usage_limit'            => $coupon->get_usage_limit(),
			'usage_limit_per_user'   => $coupon->get_usage_limit_per_user(),
			'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items(),
			'usage_count'            => $coupon->get_usage_count(),
			'expiry_date'            => $coupon->get_date_expires() ? $coupon->get_date_expires()->format( 'c' ) : null,
			'free_shipping'          => $coupon->get_free_shipping(),
			'exclude_sale_items'     => $coupon->get_exclude_sale_items(),
			'minimum_amount'         => $coupon->get_minimum_amount(),
			'maximum_amount'         => $coupon->get_maximum_amount(),
			'date_created'           => $coupon->get_date_created() ? $coupon->get_date_created()->format( 'c' ) : null,
			'date_modified'          => $coupon->get_date_modified() ? $coupon->get_date_modified()->format( 'c' ) : null,
		);
	}
}
