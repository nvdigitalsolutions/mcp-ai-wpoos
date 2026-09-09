<?php
/**
 * WP_MCP_AI_Tool_List_Reg_Products (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'List Regulatory Products', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists products in the regulatory registration system with optional filtering by category, brand, country of origin, or search term.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Filter by category (skincare, haircare, makeup, perfumes, cosmetics) (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'skincare', 'haircare', 'makeup', 'perfumes', 'cosmetics' ),
				),
				'brand'          => array(
					'type'        => 'string',
					'description' => __( 'Filter by brand name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'origin_country' => array(
					'type'        => 'string',
					'description' => __( 'Filter by country of origin (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'search'         => array(
					'type'        => 'string',
					'description' => __( 'Search term for product name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'limit'          => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of products to return (default: 20, max: 100) (optional)', 'nvoos-content-graph-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'offset'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of products to skip for pagination (optional)', 'nvoos-content-graph-pro' ),
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list products.', 'nvoos-content-graph-pro' ) );
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
