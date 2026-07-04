<?php
/**
 * Shopify Sync Inventory Tool.
 *
 * Enables AI assistants to query Shopify inventory levels from the
 * local CCT cache. Provides actions for searching inventory, fetching
 * individual items, getting stock levels across locations, listing
 * low-stock items, and triggering a refresh from the Shopify API.
 *
 * All read actions query the CCT cache directly — zero GraphQL cost.
 * The refresh action triggers a full sync via Shopify Bulk Operations (10 pts).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/trait-wp-mcp-ai-shopify-sync-connection-resolver.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php';

/**
 * Shopify Sync Inventory Tool.
 *
 * Search, filter, and retrieve Shopify inventory items from the local CCT cache.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Sync_Inventory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Shopify_Sync_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_sync_inventory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Sync Inventory', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search and retrieve Shopify inventory levels from the local sync cache. Supports filtering by vendor, product type, location, stock status, and full-text search. All read operations have zero GraphQL API cost. Use refresh action to pull fresh data from Shopify.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for the Shopify store. Auto-resolved if omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'search', 'get_item', 'get_levels', 'list_locations', 'list_low_stock', 'refresh' ),
					'default'     => 'search',
				),
				'sku'           => array(
					'type'        => 'string',
					'description' => __( 'Product SKU for get_item action.', 'mcp-ai-wpoos-pro' ),
				),
				'variant_id'    => array(
					'type'        => 'string',
					'description' => __( 'Shopify Variant GID (e.g. gid://shopify/ProductVariant/123).', 'mcp-ai-wpoos-pro' ),
				),
				'vendor'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by product vendor/brand.', 'mcp-ai-wpoos-pro' ),
				),
				'product_type'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by product type.', 'mcp-ai-wpoos-pro' ),
				),
				'location_id'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by Shopify Location GID.', 'mcp-ai-wpoos-pro' ),
				),
				'location_name' => array(
					'type'        => 'string',
					'description' => __( 'Filter by location name (partial match).', 'mcp-ai-wpoos-pro' ),
				),
				'stock_status'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by stock status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'in_stock', 'low_stock', 'out_of_stock' ),
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Full-text search across product titles and SKUs.', 'mcp-ai-wpoos-pro' ),
				),
				'orderby'       => array(
					'type'        => 'string',
					'description' => __( 'Sort order.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'product_title', 'available_qty', 'price', 'last_synced_at', 'sku', 'vendor' ),
					'default'     => 'product_title',
				),
				'order'         => array(
					'type'        => 'string',
					'description' => __( 'Sort direction.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'asc',
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Page number.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'per_page'      => array(
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
			'cache-first',
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
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1: Sanitize.
		$action        = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'search';
		$sku           = isset( $arguments['sku'] ) ? sanitize_text_field( $arguments['sku'] ) : '';
		$variant_id    = isset( $arguments['variant_id'] ) ? sanitize_text_field( $arguments['variant_id'] ) : '';
		$vendor        = isset( $arguments['vendor'] ) ? sanitize_text_field( $arguments['vendor'] ) : '';
		$product_type  = isset( $arguments['product_type'] ) ? sanitize_text_field( $arguments['product_type'] ) : '';
		$location_id   = isset( $arguments['location_id'] ) ? sanitize_text_field( $arguments['location_id'] ) : '';
		$location_name = isset( $arguments['location_name'] ) ? sanitize_text_field( $arguments['location_name'] ) : '';
		$stock_status  = isset( $arguments['stock_status'] ) ? sanitize_key( $arguments['stock_status'] ) : '';
		$search        = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$orderby       = isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'product_title';
		$order         = isset( $arguments['order'] ) ? sanitize_key( $arguments['order'] ) : 'asc';
		$page          = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page      = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 25;

		// Capability check.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_sync_forbidden',
				__( 'You do not have permission to access Shopify Sync inventory.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Resolve connection ID.
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		// Dependency check.
		$deps = $this->check_shopify_sync_dependencies( $connection_id );
		if ( is_wp_error( $deps ) ) {
			return $deps;
		}

		$cct_manager = $this->get_shopify_sync_cct_manager( $connection_id );

		// Build filter args for read actions.
		$filters = compact( 'vendor', 'product_type', 'location_id', 'location_name', 'stock_status', 'search', 'orderby', 'order', 'page', 'per_page' );

		switch ( $action ) {
			case 'search':
				return $this->handle_search( $cct_manager, $filters );

			case 'get_item':
				return $this->handle_get_item( $cct_manager, $sku, $variant_id );

			case 'get_levels':
				return $this->handle_get_levels( $cct_manager, $sku, $variant_id );

			case 'list_locations':
				return $this->handle_list_locations( $cct_manager );

			case 'list_low_stock':
				$filters['stock_status'] = 'low_stock';
				return $this->handle_search( $cct_manager, $filters );

			case 'refresh':
				return $this->handle_refresh( $cct_manager, $filters );

			default:
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_invalid_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Invalid action "%s".', 'mcp-ai-wpoos-pro' ),
						$action
					)
				);
		}
	}

	/**
	 * Handle search action.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @param array                              $filters     Filter parameters.
	 * @return array Canonical envelope.
	 */
	protected function handle_search( $cct_manager, $filters ) {
		$items = $cct_manager->get_cached_items( $filters );

		$escaped_items = array();
		foreach ( $items as $item ) {
			$escaped_items[] = $this->escape_sync_item( $item );
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
	 * Handle get_item action.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @param string                             $sku         SKU.
	 * @param string                             $variant_id  Shopify variant GID.
	 * @return array|WP_Error
	 */
	protected function handle_get_item( $cct_manager, $sku, $variant_id ) {
		$item = null;

		if ( ! empty( $sku ) ) {
			$item = $cct_manager->get_cached_item( $sku, 'sku' );
		} elseif ( ! empty( $variant_id ) ) {
			$item = $cct_manager->get_cached_item_by_variant_id( $variant_id );
		} else {
			return new WP_Error(
				'wp_mcp_ai_shopify_sync_missing_identifier',
				__( 'Provide either sku or variant_id to look up an inventory item.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $item ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_sync_item_not_found',
				__( 'Inventory item not found in cache. Try refresh to update the cache first.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Inventory item found.', 'mcp-ai-wpoos-pro' ),
			'data'    => $this->escape_sync_item( $item ),
		);
	}

	/**
	 * Handle get_levels action — stock levels across all locations.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @param string                             $sku         SKU.
	 * @param string                             $variant_id  Shopify variant GID.
	 * @return array|WP_Error
	 */
	protected function handle_get_levels( $cct_manager, $sku, $variant_id ) {
		if ( empty( $sku ) && empty( $variant_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_sync_missing_identifier',
				__( 'Provide sku or variant_id to look up stock levels.', 'mcp-ai-wpoos-pro' )
			);
		}

		$filters = array( 'per_page' => 100 );

		if ( ! empty( $sku ) ) {
			$filters['sku'] = $sku;
		} else {
			$filters['variant_id'] = $variant_id;
		}

		$items = $cct_manager->get_cached_items( $filters );

		if ( empty( $items ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_sync_item_not_found',
				__( 'No stock levels found for this product.', 'mcp-ai-wpoos-pro' )
			);
		}

		$levels = array();
		foreach ( $items as $item ) {
			$levels[] = array(
				'location_name' => esc_html( isset( $item['location_name'] ) ? $item['location_name'] : '' ),
				'location_id'   => esc_html( isset( $item['location_id'] ) ? $item['location_id'] : '' ),
				'available_qty' => absint( isset( $item['available_qty'] ) ? $item['available_qty'] : 0 ),
				'on_hand_qty'   => absint( isset( $item['on_hand_qty'] ) ? $item['on_hand_qty'] : 0 ),
				'incoming_qty'  => absint( isset( $item['incoming_qty'] ) ? $item['incoming_qty'] : 0 ),
				'reserved_qty'  => absint( isset( $item['reserved_qty'] ) ? $item['reserved_qty'] : 0 ),
				'product_title' => esc_html( isset( $item['product_title'] ) ? $item['product_title'] : '' ),
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
	 * Handle list_locations action.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @return array
	 */
	protected function handle_list_locations( $cct_manager ) {
		$items     = $cct_manager->get_cached_items( array( 'per_page' => 100 ) );
		$locations = array();
		$seen      = array();

		foreach ( $items as $item ) {
			$lid = isset( $item['location_id'] ) ? $item['location_id'] : '';
			if ( empty( $lid ) || isset( $seen[ $lid ] ) ) {
				continue;
			}

			$seen[ $lid ] = array(
				'location_id'   => esc_html( $lid ),
				'location_name' => esc_html( isset( $item['location_name'] ) ? $item['location_name'] : '' ),
				'item_count'    => 0,
			);
		}

		// Count items per location.
		foreach ( $items as $item ) {
			$lid = isset( $item['location_id'] ) ? $item['location_id'] : '';
			if ( isset( $seen[ $lid ] ) ) {
				++$seen[ $lid ]['item_count'];
			}
		}

		return array(
			'success' => true,
			'message' => sprintf(
			/* translators: %d: number of locations */
				__( 'Found %d locations.', 'mcp-ai-wpoos-pro' ),
				count( $seen )
			),
			'data'    => array_values( $seen ),
		);
	}

	/**
	 * Handle refresh action — sync from Shopify then return results.
	 *
	 * @param WP_MCP_AI_Shopify_Sync_CCT_Manager $cct_manager CCT manager.
	 * @param array                              $filters     Filter for post-refresh query.
	 * @return array|WP_Error
	 */
	protected function handle_refresh( $cct_manager, $filters ) {
		$sync_result = $cct_manager->sync_from_bulk_operation();

		if ( is_wp_error( $sync_result ) ) {
			return $sync_result;
		}

		return $this->handle_search( $cct_manager, $filters );
	}
}
