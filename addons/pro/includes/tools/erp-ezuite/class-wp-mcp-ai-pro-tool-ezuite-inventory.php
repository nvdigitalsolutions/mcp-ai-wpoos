<?php
/**
 * EZuite Inventory Tool.
 *
 * Enables AI assistants to search and filter cached EZuite inventory items
 * from the local JetEngine CCT cache — zero API cost. Uses the existing
 * CCT manager for all reads so tools never hit the EZuite ERP API directly.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-ezuite-cct-manager.php';

/**
 * EZuite Inventory Tool.
 *
 * Search, filter, and retrieve EZuite inventory items from the local CCT cache.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Pro_Tool_EZuite_Inventory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Product_Card;

	/**
	 * Rate limit: max requests per minute per user.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const RATE_LIMIT_PER_MINUTE = 30;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'ezuite_inventory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'EZuite Inventory', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search and filter cached EZuite inventory items from the local CCT cache (zero API cost).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'search'       => array(
					'type'        => 'string',
					'description' => __( 'Full-text search across item names and SKUs.', 'mcp-ai-wpoos-pro' ),
				),
				'sku'          => array(
					'type'        => 'string',
					'description' => __( 'Exact SKU match.', 'mcp-ai-wpoos-pro' ),
				),
				'warehouse'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by warehouse/location name (exact match).', 'mcp-ai-wpoos-pro' ),
				),
				'supplier'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by supplier name (exact match).', 'mcp-ai-wpoos-pro' ),
				),
				'stock_status' => array(
					'type'        => 'string',
					'description' => __( 'Filter by stock status. Thresholds use the low_stock_threshold setting.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'in_stock', 'low_stock', 'out_of_stock' ),
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum items to return (1-50).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'page'         => array(
					'type'        => 'integer',
					'description' => __( 'Page number for paginated results.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'rate-limited',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$search       = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$sku          = isset( $arguments['sku'] ) ? sanitize_text_field( $arguments['sku'] ) : '';
		$warehouse    = isset( $arguments['warehouse'] ) ? sanitize_text_field( $arguments['warehouse'] ) : '';
		$supplier     = isset( $arguments['supplier'] ) ? sanitize_text_field( $arguments['supplier'] ) : '';
		$stock_status = isset( $arguments['stock_status'] ) ? sanitize_key( $arguments['stock_status'] ) : '';
		$limit        = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 50 ) : 10;
		$page         = isset( $arguments['page'] ) ? max( 1, absint( $arguments['page'] ) ) : 1;

		// Capability check.
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_ezuite_forbidden',
				__( 'You do not have permission to access EZuite inventory.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Rate limit.
		$rate_limit_check = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Build filters for CCT query.
		$filters = array(
			'per_page' => $limit,
			'page'     => $page,
		);

		if ( ! empty( $search ) ) {
			$filters['search'] = $search;
		}

		if ( ! empty( $sku ) ) {
			$filters['sku'] = $sku;
		}

		if ( ! empty( $warehouse ) ) {
			$filters['warehouse'] = $warehouse;
		}

		if ( ! empty( $supplier ) ) {
			$filters['supplier'] = $supplier;
		}

		if ( ! empty( $stock_status ) ) {
			$filters['stock_status'] = $stock_status;
		}

		// Query the CCT cache.
		$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager();
		$items       = $cct_manager->get_cached_items( $filters );

		// Gate 2: Escape output.
		$escaped_items = array();
		foreach ( $items as $item ) {
			$escaped_items[] = $this->escape_item( $item );
		}

		// Format product cards for chat display.
		$cards_message = $this->format_product_cards(
			$escaped_items,
			'ezuite',
			array( 'source_label' => __( 'EZuite Inventory', 'mcp-ai-wpoos-pro' ) )
		);

		$message = sprintf(
			/* translators: %d: number of items */
			_n(
				'Found %d EZuite inventory item.',
				'Found %d EZuite inventory items.',
				count( $escaped_items ),
				'mcp-ai-wpoos-pro'
			),
			count( $escaped_items )
		);

		$response = array(
			'success'  => true,
			'message'  => $message,
			'products' => $escaped_items,
			'count'    => count( $escaped_items ),
			'page'     => $page,
			'limit'    => $limit,
		);

		if ( ! empty( $cards_message ) ) {
			$response['message'] .= "\n\n" . $cards_message;
		}

		return $response;
	}

	/**
	 * Escape a single CCT item for output (Gate 2).
	 *
	 * @since 1.9.0
	 *
	 * @param array $item Raw CCT item.
	 * @return array Escaped item.
	 */
	protected function escape_item( $item ) {
		$escaped     = array();
		$text_fields = array(
			'sku',
			'name',
			'warehouse',
			'supplier',
			'connection_id',
		);

		foreach ( $text_fields as $field ) {
			$escaped[ $field ] = isset( $item[ $field ] ) ? esc_html( $item[ $field ] ) : '';
		}

		$escaped['quantity']       = isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 0;
		$escaped['reorder_point']  = isset( $item['reorder_point'] ) ? absint( $item['reorder_point'] ) : 0;
		$escaped['cost_price']     = isset( $item['cost_price'] ) ? floatval( $item['cost_price'] ) : 0.0;
		$escaped['woo_product_id'] = isset( $item['woo_product_id'] ) ? absint( $item['woo_product_id'] ) : 0;
		$escaped['last_updated']   = isset( $item['last_updated'] ) ? esc_html( $item['last_updated'] ) : '';

		// Compute stock status label.
		$settings      = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
		$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;
		$quantity      = $escaped['quantity'];

		if ( $quantity <= 0 ) {
			$escaped['stock_status'] = 'out_of_stock';
		} elseif ( $quantity < $low_threshold ) {
			$escaped['stock_status'] = 'low_stock';
		} else {
			$escaped['stock_status'] = 'in_stock';
		}

		return $escaped;
	}

	/**
	 * Check rate limit for EZuite inventory queries.
	 *
	 * @since 1.9.0
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$user_id        = absint( $user_id );
		$transient_key  = 'wp_mcp_ai_pro_ezuite_inventory_' . $user_id;
		$current_count  = get_transient( $transient_key );
		$max_per_minute = self::RATE_LIMIT_PER_MINUTE;

		/**
		 * Filter the maximum EZuite inventory requests allowed per minute per user.
		 *
		 * @since 1.9.0
		 *
		 * @param int $max_per_minute Maximum requests per minute (default: 30).
		 * @param int $user_id        User ID.
		 */
		$max_per_minute = apply_filters( 'wp_mcp_ai_pro_ezuite_inventory_rate_limit', $max_per_minute, $user_id );

		if ( false === $current_count ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_minute ) {
			return new WP_Error(
				'wp_mcp_ai_pro_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum requests allowed per minute */
					__( 'EZuite inventory rate limit exceeded. Maximum %d requests per minute allowed.', 'mcp-ai-wpoos-pro' ),
					$max_per_minute
				)
			);
		}

		set_transient( $transient_key, $current_count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
