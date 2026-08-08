<?php
/**
 * Shopify Sync Products Tool.
 *
 * Enables AI assistants to browse and search the Shopify product catalog
 * from the local CCT cache. Provides category listing, product lookup
 * by SKU or variant GID, and product detail retrieval — all with zero
 * GraphQL API cost.
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
 * Shopify Sync Products Tool.
 *
 * Browse Shopify product catalog from the CCT cache.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Sync_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Shopify_Sync_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_sync_products';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Sync Products', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Browse and search the Shopify product catalog from the local sync cache. List products by type, vendor, or status. Find products by SKU or variant GID. All read operations have zero GraphQL API cost.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Remote Sites connection ID. Auto-resolved if omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'search', 'get_product', 'get_by_sku', 'list_by_type', 'list_by_vendor', 'list_by_status' ),
					'default'     => 'search',
				),
				'sku'           => array(
					'type'        => 'string',
					'description' => __( 'Product SKU for get_by_sku action.', 'mcp-ai-wpoos-pro' ),
				),
				'variant_id'    => array(
					'type'        => 'string',
					'description' => __( 'Shopify Variant GID.', 'mcp-ai-wpoos-pro' ),
				),
				'product_id'    => array(
					'type'        => 'string',
					'description' => __( 'Shopify Product GID.', 'mcp-ai-wpoos-pro' ),
				),
				'vendor'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by vendor.', 'mcp-ai-wpoos-pro' ),
				),
				'product_type'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by product type.', 'mcp-ai-wpoos-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by product status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ACTIVE', 'DRAFT', 'ARCHIVED' ),
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search products by title or SKU.', 'mcp-ai-wpoos-pro' ),
				),
				'page'          => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'per_page'      => array(
					'type'    => 'integer',
					'default' => 25,
					'minimum' => 1,
					'maximum' => 100,
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
		$action       = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'search';
		$sku          = isset( $arguments['sku'] ) ? sanitize_text_field( $arguments['sku'] ) : '';
		$variant_id   = isset( $arguments['variant_id'] ) ? sanitize_text_field( $arguments['variant_id'] ) : '';
		$product_id   = isset( $arguments['product_id'] ) ? sanitize_text_field( $arguments['product_id'] ) : '';
		$vendor       = isset( $arguments['vendor'] ) ? sanitize_text_field( $arguments['vendor'] ) : '';
		$product_type = isset( $arguments['product_type'] ) ? sanitize_text_field( $arguments['product_type'] ) : '';
		$status       = isset( $arguments['status'] ) ? sanitize_text_field( $arguments['status'] ) : '';
		$search       = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$page         = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$per_page     = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 25;
		$orderby      = isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'product_title';
		$order        = isset( $arguments['order'] ) ? sanitize_key( $arguments['order'] ) : 'asc';

		// Capability.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_sync_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		// Resolve connection ID.
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		// Dependencies.
		$deps = $this->check_shopify_sync_dependencies( $connection_id );
		if ( is_wp_error( $deps ) ) {
			return $deps;
		}

		$cct_manager = $this->get_shopify_sync_cct_manager( $connection_id );

		switch ( $action ) {
			case 'search':
				$items = $cct_manager->get_cached_items( compact( 'vendor', 'product_type', 'status', 'search', 'page', 'per_page', 'orderby', 'order' ) );
				return array(
					'success' => true,
					'message' => sprintf(
					/* translators: %d: number of products found */
						_n( 'Found %d product.', 'Found %d products.', count( $items ), 'mcp-ai-wpoos-pro' ),
						count( $items )
					),
					'data'    => $this->format_products( $items ),
				);

			case 'get_product':
				if ( empty( $product_id ) && empty( $variant_id ) ) {
					return new WP_Error( 'wp_mcp_ai_shopify_sync_missing_id', __( 'Provide product_id or variant_id.', 'mcp-ai-wpoos-pro' ) );
				}
				$by   = ! empty( $variant_id ) ? 'variant_id' : 'product_id';
				$id   = ! empty( $variant_id ) ? $variant_id : $product_id;
				$item = $cct_manager->get_cached_item( $id, $by );
				if ( ! $item ) {
					return new WP_Error( 'wp_mcp_ai_shopify_sync_not_found', __( 'Product not found in cache.', 'mcp-ai-wpoos-pro' ) );
				}
				return array(
					'success' => true,
					'message' => __( 'Product found.', 'mcp-ai-wpoos-pro' ),
					'data'    => $this->format_product_detail( $item ),
				);

			case 'get_by_sku':
				if ( empty( $sku ) ) {
					return new WP_Error( 'wp_mcp_ai_shopify_sync_missing_sku', __( 'sku is required.', 'mcp-ai-wpoos-pro' ) );
				}
				$item = $cct_manager->get_cached_item( $sku, 'sku' );
				if ( ! $item ) {
					return new WP_Error( 'wp_mcp_ai_shopify_sync_not_found', __( 'Product not found.', 'mcp-ai-wpoos-pro' ) );
				}
				return array(
					'success' => true,
					'message' => __( 'Product found.', 'mcp-ai-wpoos-pro' ),
					'data'    => $this->format_product_detail( $item ),
				);

			case 'list_by_type':
				$values = $cct_manager->get_distinct_values( 'product_type' );
				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: %d: number of product types found */
						__( 'Found %d product types.', 'mcp-ai-wpoos-pro' ),
						count( $values )
					),
					'data'    => array_map( 'esc_html', $values ),
				);

			case 'list_by_vendor':
				$values = $cct_manager->get_distinct_values( 'vendor' );
				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: %d: number of vendors found */
						__( 'Found %d vendors.', 'mcp-ai-wpoos-pro' ),
						count( $values )
					),
					'data'    => array_map( 'esc_html', $values ),
				);

			case 'list_by_status':
				$statuses = array( 'ACTIVE', 'DRAFT', 'ARCHIVED' );
				$counts   = array();
				foreach ( $statuses as $s ) {
					$counts[ $s ] = $cct_manager->get_row_count_by_status( $s );
				}
				return array(
					'success' => true,
					'message' => __( 'Product status counts.', 'mcp-ai-wpoos-pro' ),
					'data'    => $counts,
				);

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_sync_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Format products for list output.
	 *
	 * @param array $items Raw CCT items.
	 * @return array
	 */
	protected function format_products( $items ) {
		$formatted = array();
		foreach ( $items as $item ) {
			$formatted[] = array(
				'sku'           => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
				'product_title' => esc_html( isset( $item['product_title'] ) ? $item['product_title'] : '' ),
				'variant_title' => esc_html( isset( $item['variant_title'] ) ? $item['variant_title'] : '' ),
				'product_type'  => esc_html( isset( $item['product_type'] ) ? $item['product_type'] : '' ),
				'vendor'        => esc_html( isset( $item['vendor'] ) ? $item['vendor'] : '' ),
				'status'        => esc_html( isset( $item['status'] ) ? $item['status'] : '' ),
				'available_qty' => absint( isset( $item['available_qty'] ) ? $item['available_qty'] : 0 ),
				'price'         => floatval( isset( $item['price'] ) ? $item['price'] : 0.0 ),
				'location_name' => esc_html( isset( $item['location_name'] ) ? $item['location_name'] : '' ),
				'image_url'     => esc_url( isset( $item['image_url'] ) ? $item['image_url'] : '' ),
			);
		}
		return $formatted;
	}

	/**
	 * Format a single product with full detail.
	 *
	 * @param array $item Raw CCT item.
	 * @return array
	 */
	protected function format_product_detail( $item ) {
		return array(
			'shopify_product_id' => esc_html( isset( $item['shopify_product_id'] ) ? $item['shopify_product_id'] : '' ),
			'shopify_variant_id' => esc_html( isset( $item['shopify_variant_id'] ) ? $item['shopify_variant_id'] : '' ),
			'sku'                => esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ),
			'product_title'      => esc_html( isset( $item['product_title'] ) ? $item['product_title'] : '' ),
			'variant_title'      => esc_html( isset( $item['variant_title'] ) ? $item['variant_title'] : '' ),
			'product_type'       => esc_html( isset( $item['product_type'] ) ? $item['product_type'] : '' ),
			'vendor'             => esc_html( isset( $item['vendor'] ) ? $item['vendor'] : '' ),
			'tags'               => esc_html( isset( $item['tags'] ) ? $item['tags'] : '' ),
			'status'             => esc_html( isset( $item['status'] ) ? $item['status'] : '' ),
			'handle'             => esc_html( isset( $item['handle'] ) ? $item['handle'] : '' ),
			'location_name'      => esc_html( isset( $item['location_name'] ) ? $item['location_name'] : '' ),
			'location_id'        => esc_html( isset( $item['location_id'] ) ? $item['location_id'] : '' ),
			'available_qty'      => absint( isset( $item['available_qty'] ) ? $item['available_qty'] : 0 ),
			'on_hand_qty'        => absint( isset( $item['on_hand_qty'] ) ? $item['on_hand_qty'] : 0 ),
			'incoming_qty'       => absint( isset( $item['incoming_qty'] ) ? $item['incoming_qty'] : 0 ),
			'reserved_qty'       => absint( isset( $item['reserved_qty'] ) ? $item['reserved_qty'] : 0 ),
			'price'              => floatval( isset( $item['price'] ) ? $item['price'] : 0.0 ),
			'compare_at_price'   => floatval( isset( $item['compare_at_price'] ) ? $item['compare_at_price'] : 0.0 ),
			'image_url'          => esc_url( isset( $item['image_url'] ) ? $item['image_url'] : '' ),
			'woo_product_id'     => absint( isset( $item['woo_product_id'] ) ? $item['woo_product_id'] : 0 ),
			'shopify_updated_at' => esc_html( isset( $item['shopify_updated_at'] ) ? $item['shopify_updated_at'] : '' ),
			'last_synced_at'     => esc_html( isset( $item['last_synced_at'] ) ? $item['last_synced_at'] : '' ),
		);
	}
}
