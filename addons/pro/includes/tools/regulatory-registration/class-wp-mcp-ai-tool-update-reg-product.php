<?php
/**
 * Tool for updating products in the Regulatory Registration system.
 *
 * Allows AI assistants to update product information.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates a regulatory product.
 */
class WP_MCP_AI_Tool_Update_Reg_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_reg_product';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Regulatory Product', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing product in the regulatory registration system. Can update name, description, metadata, and taxonomies.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Product ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'name'               => array(
					'type'        => 'string',
					'description' => __( 'Product name (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => array(
					'type'        => 'string',
					'description' => __( 'Product description (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'category'           => array(
					'type'        => 'string',
					'description' => __( 'Product category (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'brand'              => array(
					'type'        => 'string',
					'description' => __( 'Brand name (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'supplier_reference' => array(
					'type'        => 'string',
					'description' => __( 'Supplier reference code (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'item_group'         => array(
					'type'        => 'string',
					'description' => __( 'Item group (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'origin_country'     => array(
					'type'        => 'string',
					'description' => __( 'Country of origin (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'manufacturer'       => array(
					'type'        => 'string',
					'description' => __( 'Manufacturer name (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'inci_ingredients'   => array(
					'type'        => 'string',
					'description' => __( 'INCI ingredient list (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'allergens'          => array(
					'type'        => 'string',
					'description' => __( 'Known allergens (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'hs_code'            => array(
					'type'        => 'string',
					'description' => __( 'Harmonized System code (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'barcode'            => array(
					'type'        => 'string',
					'description' => __( 'Product barcode (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'pack_size'          => array(
					'type'        => 'string',
					'description' => __( 'Package size (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'variant'            => array(
					'type'        => 'string',
					'description' => __( 'Product variant (optional)', 'mcp-ai-wpoos-pro' ),
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
			'database-write',       // Modifies database.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update products.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['product_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Product ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$product_id = absint( $arguments['product_id'] );

		// Get the product.
		$product = get_post( $product_id );

		if ( ! $product || 'mcp_ai_reg_product' !== $product->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Product not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update post if name or description changed.
		$post_data = array( 'ID' => $product_id );

		if ( isset( $arguments['name'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		// Only call wp_update_post if changes exist.
		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update meta fields conditionally.
		$meta_fields = array(
			'supplier_reference',
			'item_group',
			'origin_country',
			'manufacturer',
			'inci_ingredients',
			'allergens',
			'hs_code',
			'barcode',
			'pack_size',
			'variant',
		);

		foreach ( $meta_fields as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$value = 'inci_ingredients' === $field || 'allergens' === $field
					? sanitize_textarea_field( $arguments[ $field ] )
					: sanitize_text_field( $arguments[ $field ] );
				update_post_meta( $product_id, $field, $value );
			}
		}

		// Update category taxonomy.
		if ( isset( $arguments['category'] ) ) {
			$category = sanitize_text_field( $arguments['category'] );
			$term     = term_exists( $category, 'mcp_ai_reg_category' );
			if ( ! $term ) {
				$term = wp_insert_term( $category, 'mcp_ai_reg_category' );
			}
			if ( ! is_wp_error( $term ) ) {
				wp_set_object_terms( $product_id, absint( $term['term_id'] ), 'mcp_ai_reg_category' );
			}
		}

		// Update brand taxonomy.
		if ( isset( $arguments['brand'] ) ) {
			$brand = sanitize_text_field( $arguments['brand'] );
			$term  = term_exists( $brand, 'mcp_ai_reg_brand' );
			if ( ! $term ) {
				$term = wp_insert_term( $brand, 'mcp_ai_reg_brand' );
			}
			if ( ! is_wp_error( $term ) ) {
				wp_set_object_terms( $product_id, absint( $term['term_id'] ), 'mcp_ai_reg_brand' );
			}
		}

		// Get updated product data.
		$updated_product = get_post( $product_id );
		$product_data    = array(
			'id'                 => $updated_product->ID,
			'name'               => $updated_product->post_title,
			'description'        => $updated_product->post_content,
			'brand'              => get_post_meta( $product_id, 'brand', true ),
			'supplier_reference' => get_post_meta( $product_id, 'supplier_reference', true ),
			'item_group'         => get_post_meta( $product_id, 'item_group', true ),
			'origin_country'     => get_post_meta( $product_id, 'origin_country', true ),
			'manufacturer'       => get_post_meta( $product_id, 'manufacturer', true ),
			'inci_ingredients'   => get_post_meta( $product_id, 'inci_ingredients', true ),
			'allergens'          => get_post_meta( $product_id, 'allergens', true ),
			'hs_code'            => get_post_meta( $product_id, 'hs_code', true ),
			'barcode'            => get_post_meta( $product_id, 'barcode', true ),
			'pack_size'          => get_post_meta( $product_id, 'pack_size', true ),
			'variant'            => get_post_meta( $product_id, 'variant', true ),
			'modified_date'      => $updated_product->post_modified,
		);

		// Get category.
		$categories = wp_get_post_terms( $product_id, 'mcp_ai_reg_category' );
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			$product_data['category'] = $categories[0]->name;
		}

		// Get brand from taxonomy.
		$brands = wp_get_post_terms( $product_id, 'mcp_ai_reg_brand' );
		if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
			$product_data['brand'] = $brands[0]->name;
		}

		return array(
			'success' => true,
			'message' => __( 'Product updated successfully.', 'mcp-ai-wpoos-pro' ),
			'product' => $product_data,
		);
	}
}
