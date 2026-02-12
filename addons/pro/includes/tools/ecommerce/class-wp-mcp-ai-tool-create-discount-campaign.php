<?php
/**
 * Create Discount Campaign Tool
 *
 * Creates WooCommerce coupon codes for discount campaigns with
 * comprehensive settings including usage limits, expiration, and restrictions.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for creating WooCommerce discount campaigns.
 *
 * Supports:
 * - Percentage and fixed discounts
 * - Product/category restrictions
 * - Usage limits (per coupon and per user)
 * - Expiration dates
 * - Minimum/maximum spend requirements
 * - Email restrictions
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Create_Discount_Campaign implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Discount campaign creation requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Discount campaign creation tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'create_discount_campaign';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Create Discount Campaign', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create WooCommerce discount campaigns with coupon codes. Supports percentage and fixed discounts, product/category restrictions, usage limits, expiration dates, and minimum/maximum spend requirements.', 'mcp-ai-wpoos-pro' );
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
				'code'                        => array(
					'type'        => 'string',
					'description' => __( 'Coupon code (required, will be uppercase)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 3,
					'maxLength'   => 50,
				),
				'description'                 => array(
					'type'        => 'string',
					'description' => __( 'Internal description of the campaign', 'mcp-ai-wpoos-pro' ),
				),
				'discount_type'               => array(
					'type'        => 'string',
					'description' => __( 'Type of discount', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'percent', 'fixed_cart', 'fixed_product' ),
					'default'     => 'percent',
				),
				'amount'                      => array(
					'type'        => 'number',
					'description' => __( 'Discount amount (percentage or fixed)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'expiry_date'                 => array(
					'type'        => 'string',
					'description' => __( 'Expiration date (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
				),
				'minimum_amount'              => array(
					'type'        => 'number',
					'description' => __( 'Minimum spend requirement', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'maximum_amount'              => array(
					'type'        => 'number',
					'description' => __( 'Maximum spend limit', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'usage_limit'                 => array(
					'type'        => 'integer',
					'description' => __( 'Total usage limit for this coupon', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'usage_limit_per_user'        => array(
					'type'        => 'integer',
					'description' => __( 'Usage limit per user', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'individual_use'              => array(
					'type'        => 'boolean',
					'description' => __( 'Cannot be used with other coupons', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'free_shipping'               => array(
					'type'        => 'boolean',
					'description' => __( 'Grant free shipping', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'product_ids'                 => array(
					'type'        => 'array',
					'description' => __( 'Product IDs this coupon applies to', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				'excluded_product_ids'        => array(
					'type'        => 'array',
					'description' => __( 'Product IDs this coupon does not apply to', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
				),
				'product_categories'          => array(
					'type'        => 'array',
					'description' => __( 'Category IDs or slugs this coupon applies to', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'oneOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'integer' ),
						),
					),
				),
				'excluded_product_categories' => array(
					'type'        => 'array',
					'description' => __( 'Category IDs or slugs this coupon does not apply to', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'oneOf' => array(
							array( 'type' => 'string' ),
							array( 'type' => 'integer' ),
						),
					),
				),
				'email_restrictions'          => array(
					'type'        => 'array',
					'description' => __( 'Email addresses this coupon is restricted to', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
			),
			'required'   => array( 'code', 'amount' ),
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
				__( 'You do not have permission to create discount campaigns.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Validate required fields.
		if ( empty( $arguments['code'] ) ) {
			return new WP_Error(
				'missing_code',
				__( 'Coupon code is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! isset( $arguments['amount'] ) || $arguments['amount'] < 0 ) {
			return new WP_Error(
				'invalid_amount',
				__( 'Discount amount is required and must be non-negative.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize coupon code.
		$code = strtoupper( sanitize_title( $arguments['code'] ) );

		// Check if coupon already exists.
		$existing_coupon_id = wc_get_coupon_id_by_code( $code );
		if ( $existing_coupon_id ) {
			return new WP_Error(
				'coupon_exists',
				sprintf(
					/* translators: %s: Coupon code */
					__( 'Coupon code "%s" already exists.', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
		}

		// Create the coupon.
		$coupon_data = $this->sanitize_coupon_data( $arguments );
		$coupon_id   = $this->create_woocommerce_coupon( $code, $coupon_data );

		if ( is_wp_error( $coupon_id ) ) {
			return $coupon_id;
		}

		// Get the created coupon for response.
		$coupon = new WC_Coupon( $coupon_id );

		return array(
			'success'   => true,
			'coupon_id' => $coupon_id,
			'coupon'    => array(
				'id'            => $coupon->get_id(),
				'code'          => $coupon->get_code(),
				'amount'        => $coupon->get_amount(),
				'discount_type' => $coupon->get_discount_type(),
				'description'   => $coupon->get_description(),
				'expiry_date'   => $coupon->get_date_expires() ? $coupon->get_date_expires()->date( 'Y-m-d' ) : null,
				'usage_count'   => $coupon->get_usage_count(),
				'usage_limit'   => $coupon->get_usage_limit(),
				'edit_url'      => admin_url( 'post.php?post=' . $coupon_id . '&action=edit' ),
			),
			'message'   => sprintf(
				/* translators: %s: Coupon code */
				__( 'Discount campaign "%s" created successfully.', 'mcp-ai-wpoos-pro' ),
				$coupon->get_code()
			),
		);
	}

	/**
	 * Sanitize coupon data.
	 *
	 * @param array $arguments Raw arguments.
	 * @return array Sanitized data.
	 */
	protected function sanitize_coupon_data( $arguments ) {
		$data = array();

		$data['description']          = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$data['discount_type']        = isset( $arguments['discount_type'] ) ? sanitize_text_field( $arguments['discount_type'] ) : 'percent';
		$data['amount']               = floatval( $arguments['amount'] );
		$data['expiry_date']          = isset( $arguments['expiry_date'] ) ? sanitize_text_field( $arguments['expiry_date'] ) : '';
		$data['minimum_amount']       = isset( $arguments['minimum_amount'] ) ? floatval( $arguments['minimum_amount'] ) : 0;
		$data['maximum_amount']       = isset( $arguments['maximum_amount'] ) ? floatval( $arguments['maximum_amount'] ) : 0;
		$data['usage_limit']          = isset( $arguments['usage_limit'] ) ? absint( $arguments['usage_limit'] ) : 0;
		$data['usage_limit_per_user'] = isset( $arguments['usage_limit_per_user'] ) ? absint( $arguments['usage_limit_per_user'] ) : 0;
		$data['individual_use']       = isset( $arguments['individual_use'] ) ? (bool) $arguments['individual_use'] : false;
		$data['free_shipping']        = isset( $arguments['free_shipping'] ) ? (bool) $arguments['free_shipping'] : false;

		// Product restrictions.
		$data['product_ids']          = isset( $arguments['product_ids'] ) ? array_map( 'absint', (array) $arguments['product_ids'] ) : array();
		$data['excluded_product_ids'] = isset( $arguments['excluded_product_ids'] ) ? array_map( 'absint', (array) $arguments['excluded_product_ids'] ) : array();

		// Category restrictions.
		$data['product_categories']          = isset( $arguments['product_categories'] ) ? $this->process_category_ids( $arguments['product_categories'] ) : array();
		$data['excluded_product_categories'] = isset( $arguments['excluded_product_categories'] ) ? $this->process_category_ids( $arguments['excluded_product_categories'] ) : array();

		// Email restrictions.
		$data['email_restrictions'] = isset( $arguments['email_restrictions'] ) ? array_map( 'sanitize_email', (array) $arguments['email_restrictions'] ) : array();

		return $data;
	}

	/**
	 * Process category IDs or slugs.
	 *
	 * @param array $categories Category IDs or slugs.
	 * @return array Category IDs.
	 */
	protected function process_category_ids( $categories ) {
		$category_ids = array();

		foreach ( $categories as $category ) {
			if ( is_numeric( $category ) ) {
				$category_ids[] = absint( $category );
			} else {
				$term = get_term_by( 'slug', sanitize_title( $category ), 'product_cat' );
				if ( $term ) {
					$category_ids[] = $term->term_id;
				}
			}
		}

		return $category_ids;
	}

	/**
	 * Create WooCommerce coupon.
	 *
	 * @param string $code        Coupon code.
	 * @param array  $coupon_data Coupon data.
	 * @return int|WP_Error Coupon ID or error.
	 */
	protected function create_woocommerce_coupon( $code, $coupon_data ) {
		// Create coupon object.
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );

		// Set basic properties.
		$coupon->set_description( $coupon_data['description'] );
		$coupon->set_discount_type( $coupon_data['discount_type'] );
		$coupon->set_amount( $coupon_data['amount'] );

		// Set expiry date if provided.
		if ( ! empty( $coupon_data['expiry_date'] ) ) {
			$coupon->set_date_expires( $coupon_data['expiry_date'] );
		}

		// Set minimum/maximum amounts.
		if ( $coupon_data['minimum_amount'] > 0 ) {
			$coupon->set_minimum_amount( $coupon_data['minimum_amount'] );
		}

		if ( $coupon_data['maximum_amount'] > 0 ) {
			$coupon->set_maximum_amount( $coupon_data['maximum_amount'] );
		}

		// Set usage limits.
		if ( $coupon_data['usage_limit'] > 0 ) {
			$coupon->set_usage_limit( $coupon_data['usage_limit'] );
		}

		if ( $coupon_data['usage_limit_per_user'] > 0 ) {
			$coupon->set_usage_limit_per_user( $coupon_data['usage_limit_per_user'] );
		}

		// Set flags.
		$coupon->set_individual_use( $coupon_data['individual_use'] );
		$coupon->set_free_shipping( $coupon_data['free_shipping'] );

		// Set product restrictions.
		if ( ! empty( $coupon_data['product_ids'] ) ) {
			$coupon->set_product_ids( $coupon_data['product_ids'] );
		}

		if ( ! empty( $coupon_data['excluded_product_ids'] ) ) {
			$coupon->set_excluded_product_ids( $coupon_data['excluded_product_ids'] );
		}

		// Set category restrictions.
		if ( ! empty( $coupon_data['product_categories'] ) ) {
			$coupon->set_product_categories( $coupon_data['product_categories'] );
		}

		if ( ! empty( $coupon_data['excluded_product_categories'] ) ) {
			$coupon->set_excluded_product_categories( $coupon_data['excluded_product_categories'] );
		}

		// Set email restrictions.
		if ( ! empty( $coupon_data['email_restrictions'] ) ) {
			$coupon->set_email_restrictions( $coupon_data['email_restrictions'] );
		}

		// Save the coupon.
		$coupon_id = $coupon->save();

		if ( ! $coupon_id ) {
			return new WP_Error(
				'coupon_save_failed',
				__( 'Failed to save coupon.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $coupon_id;
	}
}
