<?php
/**
 * FlowHub Inventory Tool.
 *
 * Enables AI assistants to query and search FlowHub inventory levels
 * through the local CCT cache. Provides actions for searching inventory,
 * fetching individual items, getting stock levels across locations,
 * and triggering a refresh from the FlowHub API.
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
 * FlowHub Inventory Tool.
 *
 * Search, filter, and retrieve FlowHub inventory items from the local CCT cache.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Pro_Tool_FlowHub_Inventory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_FlowHub_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_inventory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'FlowHub Inventory', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search and retrieve FlowHub inventory levels from the local cache. Supports filtering by category, location, stock status, and full-text search across product names and SKUs. Use refresh action to pull fresh data from the FlowHub API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'search', 'get_item', 'get_levels', 'refresh' ),
					'default'     => 'search',
				),
				'sku'          => array(
					'type'        => 'string',
					'description' => __( 'Product SKU for get_item action.', 'mcp-ai-wpoos-pro' ),
				),
				'product_id'   => array(
					'type'        => 'string',
					'description' => __( 'FlowHub product ID.', 'mcp-ai-wpoos-pro' ),
				),
				'category'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by product category (e.g., Flower, Edible, Concentrate).', 'mcp-ai-wpoos-pro' ),
				),
				'location'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by location name (partial match).', 'mcp-ai-wpoos-pro' ),
				),
				'location_id'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by exact location ID.', 'mcp-ai-wpoos-pro' ),
				),
				'stock_status' => array(
					'type'        => 'string',
					'description' => __( 'Filter by stock status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'in_stock', 'low_stock', 'out_of_stock' ),
				),
				'search'       => array(
					'type'        => 'string',
					'description' => __( 'Full-text search across product names and SKUs.', 'mcp-ai-wpoos-pro' ),
				),
				'orderby'      => array(
					'type'        => 'string',
					'description' => __( 'Sort order.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'product_name', 'quantity', 'last_updated', 'sku', 'category' ),
					'default'     => 'product_name',
				),
				'order'        => array(
					'type'        => 'string',
					'description' => __( 'Sort direction.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'asc',
				),
				'page'         => array(
					'type'        => 'integer',
					'description' => __( 'Page number.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'per_page'     => array(
					'type'        => 'integer',
					'description' => __( 'Items per page (max 100).', 'mcp-ai-wpoos-pro' ),
					'default'     => 25,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'external-api',
			'requires-credentials',
			'requires-capability',
		);
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
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1: Sanitize.
		$action       = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'search';
		$sku          = isset( $arguments['sku'] ) ? sanitize_text_field( $arguments['sku'] ) : '';
		$product_id   = isset( $arguments['product_id'] ) ? sanitize_text_field( $arguments['product_id'] ) : '';
		$category     = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$location     = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$location_id  = isset( $arguments['location_id'] ) ? sanitize_text_field( $arguments['location_id'] ) : '';
		$stock_status = isset( $arguments['stock_status'] ) ? sanitize_key( $arguments['stock_status'] ) : '';
		$search       = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$orderby      = isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'product_name';
		$order        = isset( $arguments['order'] ) ? sanitize_key( $arguments['order'] ) : 'asc';
		$page         = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page     = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 25;

		// Capability check.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_forbidden',
				__( 'You do not have permission to access FlowHub inventory.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Dependency check.
		$deps = $this->check_flowhub_dependencies();
		if ( is_wp_error( $deps ) ) {
			return $deps;
		}

		$cct_manager = $this->get_flowhub_cct_manager();

		switch ( $action ) {
			case 'search':
				return $this->handle_search( $cct_manager, compact( 'category', 'location', 'location_id', 'stock_status', 'search', 'orderby', 'order', 'page', 'per_page' ) );

			case 'get_item':
				return $this->handle_get_item( $cct_manager, $sku, $product_id );

			case 'get_levels':
				return $this->handle_get_levels( $cct_manager, $sku, $product_id );

			case 'refresh':
				return $this->handle_refresh( $cct_manager, compact( 'category', 'location', 'search', 'orderby', 'order', 'page', 'per_page' ) );

			default:
				return new WP_Error(
					'wp_mcp_ai_flowhub_invalid_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Invalid action "%s".', 'mcp-ai-wpoos-pro' ),
						$action
					)
				);
		}
	}

	/**
	 * Handle search action — query CCT with filters.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager instance.
	 * @param array                         $filters     Filter parameters.
	 * @return array Canonical envelope.
	 */
	protected function handle_search( $cct_manager, $filters ) {
		$items = $cct_manager->get_cached_items( $filters );

		// Gate 2: Escape output.
		$escaped_items = array();
		foreach ( $items as $item ) {
			$escaped_items[] = $this->escape_item( $item );
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of items */
				_n( 'Found %d inventory item.', 'Found %d inventory items.', count( $escaped_items ), 'mcp-ai-wpoos-pro' ),
				count( $escaped_items )
			),
			'data'    => $escaped_items,
		);
	}

	/**
	 * Handle get_item action — fetch single item by SKU or product_id.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager.
	 * @param string                        $sku         SKU.
	 * @param string                        $product_id  FlowHub product ID.
	 * @return array|WP_Error
	 */
	protected function handle_get_item( $cct_manager, $sku, $product_id ) {
		$item = null;

		if ( ! empty( $sku ) ) {
			$item = $cct_manager->get_cached_item( $sku, 'sku' );
		} elseif ( ! empty( $product_id ) ) {
			$item = $cct_manager->get_cached_item_by_product_id( $product_id );
		} else {
			return new WP_Error(
				'wp_mcp_ai_flowhub_missing_identifier',
				__( 'Provide either sku or product_id to look up an inventory item.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $item ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_item_not_found',
				__( 'Inventory item not found in cache. Try using the refresh action to update the cache first.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Inventory item found.', 'mcp-ai-wpoos-pro' ),
			'data'    => $this->escape_item( $item ),
		);
	}

	/**
	 * Handle get_levels action — stock levels across all locations.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager.
	 * @param string                        $sku         SKU.
	 * @param string                        $product_id  FlowHub product ID.
	 * @return array|WP_Error
	 */
	protected function handle_get_levels( $cct_manager, $sku, $product_id ) {
		if ( empty( $sku ) && empty( $product_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_missing_identifier',
				__( 'Provide sku or product_id to look up stock levels.', 'mcp-ai-wpoos-pro' )
			);
		}

		$filters = array( 'per_page' => 100 );

		if ( ! empty( $sku ) ) {
			$filters['sku'] = $sku;
		} else {
			$filters['product_id'] = $product_id;
		}

		$items = $cct_manager->get_cached_items( $filters );

		if ( empty( $items ) ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_item_not_found',
				__( 'No stock levels found for this product.', 'mcp-ai-wpoos-pro' )
			);
		}

		$levels = array();
		foreach ( $items as $item ) {
			$levels[] = array(
				'location_name' => esc_html( isset( $item['location_name'] ) ? $item['location_name'] : '' ),
				'quantity'      => absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 ),
				'product_name'  => esc_html( isset( $item['product_name'] ) ? $item['product_name'] : '' ),
				'sku'           => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of locations */
				__( 'Stock levels across %d locations.', 'mcp-ai-wpoos-pro' ),
				count( $levels )
			),
			'data'    => $levels,
		);
	}

	/**
	 * Handle refresh action — sync from API then return results.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_FlowHub_CCT_Manager $cct_manager CCT manager.
	 * @param array                         $filters     Filter for post-refresh query.
	 * @return array|WP_Error
	 */
	protected function handle_refresh( $cct_manager, $filters ) {
		$sync_result = $cct_manager->sync_from_api( true );

		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}

		// Return the freshly synced items.
		return $this->handle_search( $cct_manager, $filters );
	}

	/**
	 * Escape a single CCT item for output (Gate 2).
	 *
	 * @since 1.2.0
	 *
	 * @param array $item Raw CCT item.
	 * @return array Escaped item.
	 */
	protected function escape_item( $item ) {
		$escaped     = array();
		$text_fields = array(
			'product_id',
			'variant_id',
			'parent_product_id',
			'sku',
			'product_name',
			'variant_name',
			'category',
			'custom_category_name',
			'purchase_category',
			'location_id',
			'location_name',
			'unit_of_measure',
			'image_url',
			'sync_status',
		);

		foreach ( $text_fields as $field ) {
			$escaped[ $field ] = isset( $item[ $field ] ) ? esc_html( $item[ $field ] ) : '';
		}

		$escaped['quantity']            = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
		$escaped['price']               = isset( $item['price'] ) ? floatval( $item['price'] ) : 0.0;
		$escaped['woo_product_id']      = isset( $item['woo_product_id'] ) ? absint( $item['woo_product_id'] ) : 0;
		$escaped['last_updated']        = isset( $item['last_updated'] ) ? esc_html( $item['last_updated'] ) : '';
		$escaped['product_description'] = isset( $item['product_description'] ) ? wp_kses_post( $item['product_description'] ) : '';

		return $escaped;
	}
}
