<?php
/**
 * Shopify Orders Tool — manage orders on a connected Shopify store via the Admin GraphQL … (ecosystem port — Wave F2, e-commerce shopify batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-shopify-orders.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 *  * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the Shopify client require resolves from
 * `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/'`; version refs resolve from
 * `NVOOS_CONTENT_GRAPH_PRO_VERSION`.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 */

declare(strict_types=1);

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
		return __( 'Shopify Orders', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Access and manage orders on a connected Shopify store via the Admin GraphQL API. Supports listing, filtering, and retrieving detailed order information including line items, fulfillments, and transactions.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Remote Sites connection ID for the Shopify store. If omitted, automatically uses the Shopify connection configured for this assistant.', 'nvoos-content-graph-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list, get, search.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'list', 'get', 'search' ),
					'default'     => 'list',
				),
				'order_id'      => array(
					'type'        => 'string',
					'description' => __( 'Shopify order GID (e.g. gid://shopify/Order/123456789) for the get action.', 'nvoos-content-graph-pro' ),
				),
				'first'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of orders to return (1–250). Default: 10.', 'nvoos-content-graph-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 250,
				),
				'after'         => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor (endCursor from a previous response).', 'nvoos-content-graph-pro' ),
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Shopify order search/filter query. Supports Shopify filter syntax, e.g. "financial_status:paid fulfillment_status:unfulfilled created_at:>2024-01-01".', 'nvoos-content-graph-pro' ),
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
		$user_id  = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
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
			return new WP_Error( 'wp_mcp_ai_shopify_forbidden', __( 'You do not have permission to access Shopify orders.', 'nvoos-content-graph-pro' ) );
		}

		// Resolve the Shopify connection — auto-resolves from assistant context when not provided.
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_no_manager', __( 'Remote Sites Manager is not available.', 'nvoos-content-graph-pro' ) );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			$available = $this->get_available_shopify_connections( $context );
			$conn_list = $this->format_available_connections_message( $available );
			return new WP_Error( 'wp_mcp_ai_shopify_connection_not_found', __( 'The specified connection was not found.', 'nvoos-content-graph-pro' ) . $conn_list );
		}
		if ( empty( $connection['connection_type'] ) || 'shopify' !== $connection['connection_type'] ) {
			return new WP_Error( 'wp_mcp_ai_shopify_wrong_type', __( 'The specified connection is not a Shopify connection.', 'nvoos-content-graph-pro' ) );
		}
		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_disabled', __( 'This Shopify connection is disabled.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! $this->is_shopify_connection_enabled_for_assistant( $connection_id, $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_not_enabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Shopify connection "%s" is not enabled for this assistant. Enable it in the assistant editor under Remote Site Connections.', 'nvoos-content-graph-pro' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-shopify-client.php';
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
				return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified.', 'nvoos-content-graph-pro' ) );
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
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'nvoos-content-graph-pro' ) );
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
			return new WP_Error( 'wp_mcp_ai_shopify_missing_order_id', __( 'order_id is required for the get action.', 'nvoos-content-graph-pro' ) );
		}

		if ( is_numeric( $order_id ) ) {
			$order_id = 'gid://shopify/Order/' . $order_id;
		}

		$response = $client->get_order( $order_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'nvoos-content-graph-pro' ) );
		}

		$node = isset( $response['data']['order'] ) ? $response['data']['order'] : null;
		if ( ! $node ) {
			return new WP_Error( 'wp_mcp_ai_shopify_not_found', __( 'Order not found.', 'nvoos-content-graph-pro' ) );
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
