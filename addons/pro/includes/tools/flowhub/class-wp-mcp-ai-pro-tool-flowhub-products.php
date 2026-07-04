<?php
/**
 * FlowHub Products Tool.
 *
 * Enables AI assistants to browse and search the FlowHub product catalog
 * through the local CCT cache. Provides category listing, product lookup
 * by SKU, and product detail retrieval.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/flowhub/trait-wp-mcp-ai-flowhub-connection-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';

/**
 * FlowHub Products Tool.
 *
 * Browse FlowHub product catalog, search by SKU, and list categories.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Pro_Tool_FlowHub_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_FlowHub_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_products';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'FlowHub Products', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Browse and search the FlowHub product catalog from the local cache. List all categories, find products by SKU or name, and view product details including descriptions and images.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'     => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'search', 'get_product', 'get_by_sku', 'list_categories' ),
					'default'     => 'search',
				),
				'sku'        => array(
					'type'        => 'string',
					'description' => __( 'Product SKU for get_by_sku action.', 'mcp-ai-wpoos-pro' ),
				),
				'product_id' => array(
					'type'        => 'string',
					'description' => __( 'FlowHub product ID.', 'mcp-ai-wpoos-pro' ),
				),
				'category'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by category.', 'mcp-ai-wpoos-pro' ),
				),
				'search'     => array(
					'type'        => 'string',
					'description' => __( 'Search products by name.', 'mcp-ai-wpoos-pro' ),
				),
				'page'       => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page'   => array(
					'type'    => 'integer',
					'default' => 25,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'requires-credentials', 'requires-capability' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1: Sanitize.
		$action     = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'search';
		$sku        = isset( $arguments['sku'] ) ? sanitize_text_field( $arguments['sku'] ) : '';
		$product_id = isset( $arguments['product_id'] ) ? sanitize_text_field( $arguments['product_id'] ) : '';
		$category   = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$search     = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$page       = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page   = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 25;

		// Capability.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_flowhub_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		// Dependencies.
		$deps = $this->check_flowhub_dependencies();
		if ( is_wp_error( $deps ) ) {
			return $deps;
		}

		$cct_manager = $this->get_flowhub_cct_manager();

		switch ( $action ) {
			case 'search':
				$items = $cct_manager->get_cached_items( compact( 'category', 'search', 'page', 'per_page' ) );
				return array(
					'success' => true,
					'message' => sprintf(
						_n( 'Found %d product.', 'Found %d products.', count( $items ), 'mcp-ai-wpoos-pro' ),
						count( $items )
					),
					'data'    => $this->format_products( $items ),
				);

			case 'get_product':
				if ( empty( $product_id ) ) {
					return new WP_Error( 'wp_mcp_ai_flowhub_missing_id', __( 'product_id is required.', 'mcp-ai-wpoos-pro' ) );
				}
				$item = $cct_manager->get_cached_item_by_product_id( $product_id );
				if ( ! $item ) {
					return new WP_Error( 'wp_mcp_ai_flowhub_not_found', __( 'Product not found.', 'mcp-ai-wpoos-pro' ) );
				}
				return array(
					'success' => true,
					'message' => __( 'Product found.', 'mcp-ai-wpoos-pro' ),
					'data'    => $this->format_product_detail( $item ),
				);

			case 'get_by_sku':
				if ( empty( $sku ) ) {
					return new WP_Error( 'wp_mcp_ai_flowhub_missing_sku', __( 'sku is required.', 'mcp-ai-wpoos-pro' ) );
				}
				$item = $cct_manager->get_cached_item( $sku, 'sku' );
				if ( ! $item ) {
					return new WP_Error( 'wp_mcp_ai_flowhub_not_found', __( 'Product not found.', 'mcp-ai-wpoos-pro' ) );
				}
				return array(
					'success' => true,
					'message' => __( 'Product found.', 'mcp-ai-wpoos-pro' ),
					'data'    => $this->format_product_detail( $item ),
				);

			case 'list_categories':
				$values = $cct_manager->get_distinct_values( 'category' );
				return array(
					'success' => true,
					'message' => sprintf(
						__( 'Found %d categories.', 'mcp-ai-wpoos-pro' ),
						count( $values )
					),
					'data'    => array_map( 'esc_html', $values ),
				);

			default:
				return new WP_Error( 'wp_mcp_ai_flowhub_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Format products for list output.
	 *
	 * @since 1.2.0
	 * @param array $items Raw CCT items.
	 * @return array
	 */
	protected function format_products( $items ) {
		$formatted = array();
		foreach ( $items as $item ) {
			$formatted[] = array(
				'sku'          => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
				'product_name' => esc_html( isset( $item['product_name'] ) ? $item['product_name'] : '' ),
				'variant_name' => esc_html( isset( $item['variant_name'] ) ? $item['variant_name'] : '' ),
				'category'     => esc_html( isset( $item['category'] ) ? $item['category'] : '' ),
				'quantity'     => absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 ),
				'price'        => floatval( isset( $item['price'] ) ? $item['price'] : 0.0 ),
				'image_url'    => esc_url( isset( $item['image_url'] ) ? $item['image_url'] : '' ),
			);
		}
		return $formatted;
	}

	/**
	 * Format a single product with full detail.
	 *
	 * @since 1.2.0
	 * @param array $item Raw CCT item.
	 * @return array
	 */
	protected function format_product_detail( $item ) {
		return array(
			'sku'                  => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
			'product_name'         => esc_html( isset( $item['product_name'] ) ? $item['product_name'] : '' ),
			'variant_name'         => esc_html( isset( $item['variant_name'] ) ? $item['variant_name'] : '' ),
			'category'             => esc_html( isset( $item['category'] ) ? $item['category'] : '' ),
			'custom_category_name' => esc_html( isset( $item['custom_category_name'] ) ? $item['custom_category_name'] : '' ),
			'product_description'  => wp_kses_post( isset( $item['product_description'] ) ? $item['product_description'] : '' ),
			'quantity'             => absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 ),
			'price'                => floatval( isset( $item['price'] ) ? $item['price'] : 0.0 ),
			'image_url'            => esc_url( isset( $item['image_url'] ) ? $item['image_url'] : '' ),
			'location_name'        => esc_html( isset( $item['location_name'] ) ? $item['location_name'] : '' ),
			'unit_of_measure'      => esc_html( isset( $item['unit_of_measure'] ) ? $item['unit_of_measure'] : '' ),
			'last_updated'         => esc_html( isset( $item['last_updated'] ) ? $item['last_updated'] : '' ),
		);
	}
}
