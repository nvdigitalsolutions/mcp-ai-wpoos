<?php
/**
 * Tool for listing products in the Regulatory Registration system.
 *
 * Allows AI assistants to list and filter products.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists regulatory products.
 */
class WP_MCP_AI_Tool_List_Reg_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_reg_products';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Regulatory Products', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists products in the regulatory registration system with optional filtering by category, brand, country of origin, or search term.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'category'       => array(
					'type'        => 'string',
					'description' => __( 'Filter by category (skincare, haircare, makeup, perfumes, cosmetics) (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'skincare', 'haircare', 'makeup', 'perfumes', 'cosmetics' ),
				),
				'brand'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by brand name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'origin_country' => array(
					'type'        => 'string',
					'description' => __( 'Filter by country of origin (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'search'         => array(
					'type'        => 'string',
					'description' => __( 'Search term for product name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of products to return (default: 20, max: 100) (optional)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'offset'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of products to skip for pagination (optional)', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'minimum'     => 0,
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
			'paginated',            // Supports pagination.
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list products.', 'mcp-ai-wpoos-pro' ) );
		}

		// Parse arguments.
		$category       = ! empty( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$brand          = ! empty( $arguments['brand'] ) ? sanitize_text_field( $arguments['brand'] ) : '';
		$origin_country = ! empty( $arguments['origin_country'] ) ? sanitize_text_field( $arguments['origin_country'] ) : '';
		$search         = ! empty( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$limit          = ! empty( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$offset         = ! empty( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;

		// Enforce max limit.
		$limit = min( $limit, 100 );

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_reg_product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Add search if provided.
		if ( $search ) {
			$query_args['s'] = $search;
		}

		// Add taxonomy filters.
		$tax_query = array();

		if ( $category ) {
			$tax_query[] = array(
				'taxonomy' => 'mcp_ai_reg_category',
				'field'    => 'slug',
				'terms'    => strtolower( $category ),
			);
		}

		if ( $brand ) {
			$tax_query[] = array(
				'taxonomy' => 'mcp_ai_reg_brand',
				'field'    => 'name',
				'terms'    => $brand,
			);
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query;
		}

		// Add meta query for origin country.
		if ( $origin_country ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => 'origin_country',
					'value'   => $origin_country,
					'compare' => '=',
				),
			);
		}

		// Execute query.
		$query = new WP_Query( $query_args );

		$products = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$product_data = array(
					'id'                 => $post->ID,
					'name'               => $post->post_title,
					'description'        => $post->post_content,
					'brand'              => get_post_meta( $post->ID, 'brand', true ),
					'supplier_reference' => get_post_meta( $post->ID, 'supplier_reference', true ),
					'item_group'         => get_post_meta( $post->ID, 'item_group', true ),
					'origin_country'     => get_post_meta( $post->ID, 'origin_country', true ),
					'manufacturer'       => get_post_meta( $post->ID, 'manufacturer', true ),
					'hs_code'            => get_post_meta( $post->ID, 'hs_code', true ),
					'barcode'            => get_post_meta( $post->ID, 'barcode', true ),
					'pack_size'          => get_post_meta( $post->ID, 'pack_size', true ),
					'variant'            => get_post_meta( $post->ID, 'variant', true ),
				);

				// Get category.
				$categories = wp_get_post_terms( $post->ID, 'mcp_ai_reg_category' );
				if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
					$product_data['category'] = $categories[0]->name;
				}

				// Get brand from taxonomy.
				$brands = wp_get_post_terms( $post->ID, 'mcp_ai_reg_brand' );
				if ( ! empty( $brands ) && ! is_wp_error( $brands ) && empty( $product_data['brand'] ) ) {
					$product_data['brand'] = $brands[0]->name;
				}

				$products[] = $product_data;
			}
		}

		return array(
			'success'  => true,
			'products' => $products,
			'total'    => $query->found_posts,
			'returned' => count( $products ),
			'limit'    => $limit,
			'offset'   => $offset,
			'has_more' => ( $offset + $limit ) < $query->found_posts,
		);
	}
}
