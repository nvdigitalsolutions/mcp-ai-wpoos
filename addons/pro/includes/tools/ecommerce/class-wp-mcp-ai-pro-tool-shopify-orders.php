<?php
/**
 * Shopify Orders Tool — manage orders on a connected Shopify store via the Admin GraphQL API.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides order management operations for Shopify stores.
 *
 * Supports listing, searching, and retrieving individual orders via the
 * Shopify Admin GraphQL API (2025-01+).
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Orders implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_orders';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Orders', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Access and manage orders on a connected Shopify store via the Admin GraphQL API. Supports listing, filtering, and retrieving detailed order information including line items, fulfillments, and transactions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for the Shopify store. If omitted, automatically uses the Shopify connection configured for this assistant.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list, get, search.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'get', 'search' ),
					'default'     => 'list',
				),
				'order_id'      => array(
					'type'        => 'string',
					'description' => __( 'Shopify order GID (e.g. gid://shopify/Order/123456789) for the get action.', 'mcp-ai-wpoos-pro' ),
				),
				'first'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders to return (1–250). Default: 10.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 250,
				),
				'after'         => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor (endCursor from a previous response).', 'mcp-ai-wpoos-pro' ),
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Shopify order search/filter query. Supports Shopify filter syntax, e.g. "financial_status:paid fulfillment_status:unfulfilled created_at:>2024-01-01".', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls to Shopify.
			'requires-credentials', // Requires Shopify API credentials.
			'requires-capability',  // Requires WordPress user capabilities.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id  = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );
		$action   = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		// Telegram Mini App storefront contexts create users with the
		// subscriber role which lacks edit_posts.  Allow read-only order
		// operations with just the "read" capability so the storefront
		// works for all TMA visitors.
		$is_tma                 = isset( $context['source'] ) && 'telegram_mini_app' === $context['source'];
		$tma_storefront_actions = array( 'list', 'get', 'search' );
		$default_cap            = ( $is_tma && in_array( $action, $tma_storefront_actions, true ) )
			? 'read'
			: 'edit_posts';

		$required_capability = apply_filters( 'wp_mcp_ai_shopify_orders_required_capability', $default_cap, $context );

		// Allow guest users when the assistant is configured for public access.
		if ( ! $is_guest && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_forbidden', __( 'You do not have permission to access Shopify orders.', 'mcp-ai-wpoos-pro' ) );
		}

		// Resolve the Shopify connection — auto-resolves from assistant context when not provided.
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_no_manager', __( 'Remote Sites Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			$available = $this->get_available_shopify_connections( $context );
			$conn_list = $this->format_available_connections_message( $available );
			return new WP_Error( 'wp_mcp_ai_shopify_connection_not_found', __( 'The specified connection was not found.', 'mcp-ai-wpoos-pro' ) . $conn_list );
		}
		if ( empty( $connection['connection_type'] ) || 'shopify' !== $connection['connection_type'] ) {
			return new WP_Error( 'wp_mcp_ai_shopify_wrong_type', __( 'The specified connection is not a Shopify connection.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_disabled', __( 'This Shopify connection is disabled.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! $this->is_shopify_connection_enabled_for_assistant( $connection_id, $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_not_enabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Shopify connection "%s" is not enabled for this assistant. Enable it in the assistant editor under Remote Site Connections.', 'mcp-ai-wpoos-pro' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php';
		}

		$client = new WP_MCP_AI_Shopify_Client( $connection_id );
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'list':
			case 'search':
				return $this->handle_list( $client, $arguments );

			case 'get':
				return $this->handle_get( $client, $arguments );

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle list/search action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_list( $client, array $arguments ) {
		$first = isset( $arguments['first'] ) ? max( 1, min( 250, absint( $arguments['first'] ) ) ) : 10;
		$after = isset( $arguments['after'] ) ? sanitize_text_field( $arguments['after'] ) : '';
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		$response = $client->get_orders( $first, $after, $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$orders = array();
		$edges  = isset( $response['data']['orders']['edges'] ) ? $response['data']['orders']['edges'] : array();

		foreach ( $edges as $edge ) {
			$node     = isset( $edge['node'] ) ? $edge['node'] : array();
			$orders[] = $this->normalize_order( $node );
		}

		return array(
			'success'   => true,
			'orders'    => $orders,
			'count'     => count( $orders ),
			'page_info' => isset( $response['data']['orders']['pageInfo'] ) ? $response['data']['orders']['pageInfo'] : array(),
		);
	}

	/**
	 * Handle get action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_get( $client, array $arguments ) {
		$order_id = isset( $arguments['order_id'] ) ? sanitize_text_field( $arguments['order_id'] ) : '';
		if ( empty( $order_id ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_order_id', __( 'order_id is required for the get action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_numeric( $order_id ) ) {
			$order_id = 'gid://shopify/Order/' . $order_id;
		}

		$response = $client->get_order( $order_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$node = isset( $response['data']['order'] ) ? $response['data']['order'] : null;
		if ( ! $node ) {
			return new WP_Error( 'wp_mcp_ai_shopify_not_found', __( 'Order not found.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'order'   => $this->normalize_order( $node ),
		);
	}

	/**
	 * Normalize an order node from the GraphQL response.
	 *
	 * @param array $node Raw GraphQL order node.
	 * @return array Normalized order array.
	 */
	protected function normalize_order( array $node ) {
		$line_items = array();
		if ( isset( $node['lineItems']['edges'] ) ) {
			foreach ( $node['lineItems']['edges'] as $edge ) {
				$line_items[] = isset( $edge['node'] ) ? $edge['node'] : array();
			}
		}

		return array(
			'id'                 => isset( $node['id'] ) ? $node['id'] : '',
			'name'               => isset( $node['name'] ) ? $node['name'] : '',
			'created_at'         => isset( $node['createdAt'] ) ? $node['createdAt'] : '',
			'updated_at'         => isset( $node['updatedAt'] ) ? $node['updatedAt'] : '',
			'financial_status'   => isset( $node['displayFinancialStatus'] ) ? $node['displayFinancialStatus'] : '',
			'fulfillment_status' => isset( $node['displayFulfillmentStatus'] ) ? $node['displayFulfillmentStatus'] : '',
			'total_price'        => isset( $node['totalPriceSet']['shopMoney'] ) ? $node['totalPriceSet']['shopMoney'] : array(),
			'subtotal_price'     => isset( $node['subtotalPriceSet']['shopMoney'] ) ? $node['subtotalPriceSet']['shopMoney'] : array(),
			'total_shipping'     => isset( $node['totalShippingPriceSet']['shopMoney'] ) ? $node['totalShippingPriceSet']['shopMoney'] : array(),
			'total_tax'          => isset( $node['totalTaxSet']['shopMoney'] ) ? $node['totalTaxSet']['shopMoney'] : array(),
			'customer'           => isset( $node['customer'] ) ? $node['customer'] : null,
			'shipping_address'   => isset( $node['shippingAddress'] ) ? $node['shippingAddress'] : null,
			'line_items'         => $line_items,
			'fulfillments'       => isset( $node['fulfillments'] ) ? $node['fulfillments'] : array(),
			'tags'               => isset( $node['tags'] ) ? $node['tags'] : array(),
			'note'               => isset( $node['note'] ) ? $node['note'] : '',
		);
	}
}
