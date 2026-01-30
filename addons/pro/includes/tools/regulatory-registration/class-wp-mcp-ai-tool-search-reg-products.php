<?php
/**
 * Tool for searching products in the Regulatory Registration system.
 *
 * Allows AI assistants to search products by name, brand, ingredients, etc.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches regulatory products.
 */
class WP_MCP_AI_Tool_Search_Reg_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_reg_products';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Regulatory Products', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Advanced search for products by name, brand, manufacturer, ingredients, HS code, or other criteria.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search'         => array(
					'type'        => 'string',
					'description' => __( 'Search term for product name or description (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'brand'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by brand (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'category'       => array(
					'type'        => 'string',
					'description' => __( 'Filter by category (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'manufacturer'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by manufacturer (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'origin_country' => array(
					'type'        => 'string',
					'description' => __( 'Filter by origin country (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'hs_code'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by HS code (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'ingredients'    => array(
					'type'        => 'string',
					'description' => __( 'Search in INCI ingredients (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'page'           => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination (optional, default: 1)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'per_page'       => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (optional, default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
			),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search products.', 'mcp-ai-wpoos-pro' ) );
		}

		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$per_page = min( $per_page, 100 ); // Cap at 100.

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_reg_product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		);

		// Add text search if provided.
		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		// Build meta query for metadata filters.
		$meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $arguments['manufacturer'] ) ) {
			$meta_query[] = array(
				'key'     => 'manufacturer',
				'value'   => sanitize_text_field( $arguments['manufacturer'] ),
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $arguments['origin_country'] ) ) {
			$meta_query[] = array(
				'key'   => 'origin_country',
				'value' => sanitize_text_field( $arguments['origin_country'] ),
			);
		}

		if ( ! empty( $arguments['hs_code'] ) ) {
			$meta_query[] = array(
				'key'   => 'hs_code',
				'value' => sanitize_text_field( $arguments['hs_code'] ),
			);
		}

		if ( ! empty( $arguments['ingredients'] ) ) {
			$meta_query[] = array(
				'key'     => 'inci_ingredients',
				'value'   => sanitize_text_field( $arguments['ingredients'] ),
				'compare' => 'LIKE',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		// Build tax query for taxonomy filters.
		$tax_query = array( 'relation' => 'AND' );

		if ( ! empty( $arguments['category'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'mcp_ai_reg_category',
				'field'    => 'name',
				'terms'    => sanitize_text_field( $arguments['category'] ),
			);
		}

		if ( ! empty( $arguments['brand'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'mcp_ai_reg_brand',
				'field'    => 'name',
				'terms'    => sanitize_text_field( $arguments['brand'] ),
			);
		}

		if ( count( $tax_query ) > 1 ) {
			$query_args['tax_query'] = $tax_query;
		}

		$query = new WP_Query( $query_args );

		$products = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$product_data = array(
					'id'                 => $post->ID,
					'name'               => $post->post_title,
					'description'        => wp_trim_words( $post->post_content, 30 ),
					'brand'              => get_post_meta( $post->ID, 'brand', true ),
					'manufacturer'       => get_post_meta( $post->ID, 'manufacturer', true ),
					'origin_country'     => get_post_meta( $post->ID, 'origin_country', true ),
					'hs_code'            => get_post_meta( $post->ID, 'hs_code', true ),
					'supplier_reference' => get_post_meta( $post->ID, 'supplier_reference', true ),
				);

				// Get category.
				$categories = wp_get_post_terms( $post->ID, 'mcp_ai_reg_category' );
				if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
					$product_data['category'] = $categories[0]->name;
				}

				// Get brand from taxonomy.
				$brands = wp_get_post_terms( $post->ID, 'mcp_ai_reg_brand' );
				if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) {
					$product_data['brand'] = $brands[0]->name;
				}

				$products[] = $product_data;
			}
		}

		return array(
			'success'     => true,
			'products'    => $products,
			'total'       => $query->found_posts,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $query->max_num_pages,
		);
	}
}
