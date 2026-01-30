<?php
/**
 * Tool for getting a single product in the Regulatory Registration system.
 *
 * Allows AI assistants to retrieve detailed product information.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets a regulatory product by ID.
 */
class WP_MCP_AI_Tool_Get_Reg_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_reg_product';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Regulatory Product', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets detailed information about a specific product in the regulatory registration system, including all metadata, ingredients, and associated registrations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Product ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'include_registrations' => array(
					'type'        => 'boolean',
					'description' => __( 'Include list of registrations for this product (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'product_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view products.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['product_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Product ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$product_id            = absint( $arguments['product_id'] );
		$include_registrations = ! empty( $arguments['include_registrations'] );

		// Get the product.
		$product = get_post( $product_id );

		if ( ! $product || 'mcp_ai_reg_product' !== $product->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Product not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build product data.
		$product_data = array(
			'id'                 => $product->ID,
			'name'               => $product->post_title,
			'description'        => $product->post_content,
			'brand'              => get_post_meta( $product->ID, 'brand', true ),
			'supplier_reference' => get_post_meta( $product->ID, 'supplier_reference', true ),
			'item_group'         => get_post_meta( $product->ID, 'item_group', true ),
			'origin_country'     => get_post_meta( $product->ID, 'origin_country', true ),
			'manufacturer'       => get_post_meta( $product->ID, 'manufacturer', true ),
			'inci_ingredients'   => get_post_meta( $product->ID, 'inci_ingredients', true ),
			'allergens'          => get_post_meta( $product->ID, 'allergens', true ),
			'hs_code'            => get_post_meta( $product->ID, 'hs_code', true ),
			'barcode'            => get_post_meta( $product->ID, 'barcode', true ),
			'pack_size'          => get_post_meta( $product->ID, 'pack_size', true ),
			'variant'            => get_post_meta( $product->ID, 'variant', true ),
			'created_date'       => $product->post_date,
			'modified_date'      => $product->post_modified,
		);

		// Get category.
		$categories = wp_get_post_terms( $product->ID, 'mcp_ai_reg_category' );
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			$product_data['category'] = $categories[0]->name;
			$product_data['categories'] = wp_list_pluck( $categories, 'name' );
		}

		// Get brand from taxonomy.
		$brands = wp_get_post_terms( $product->ID, 'mcp_ai_reg_brand' );
		if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
			$product_data['brand'] = $brands[0]->name;
		}

		// Get registrations if requested.
		if ( $include_registrations ) {
			$registrations_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_registration',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_query'     => array(
						array(
							'key'   => 'product_id',
							'value' => $product_id,
						),
					),
				)
			);

			$registrations = array();

			if ( $registrations_query->have_posts() ) {
				foreach ( $registrations_query->posts as $reg_post ) {
					$reg_data = array(
						'id'              => $reg_post->ID,
						'title'           => $reg_post->post_title,
						'country'         => get_post_meta( $reg_post->ID, 'country', true ),
						'authority'       => get_post_meta( $reg_post->ID, 'authority', true ),
						'registration_type' => get_post_meta( $reg_post->ID, 'registration_type', true ),
						'cos_number'      => get_post_meta( $reg_post->ID, 'cos_number', true ),
						'submission_date' => get_post_meta( $reg_post->ID, 'submission_date', true ),
						'approval_date'   => get_post_meta( $reg_post->ID, 'approval_date', true ),
						'expiry_date'     => get_post_meta( $reg_post->ID, 'expiry_date', true ),
					);

					// Get status.
					$statuses = wp_get_post_terms( $reg_post->ID, 'mcp_ai_reg_status' );
					if ( ! empty( $statuses ) && ! is_wp_error( $statuses ) ) {
						$reg_data['status'] = $statuses[0]->name;
					}

					$registrations[] = $reg_data;
				}
			}

			$product_data['registrations'] = $registrations;
			$product_data['registration_count'] = count( $registrations );
		}

		return array(
			'success' => true,
			'product' => $product_data,
		);
	}
}
