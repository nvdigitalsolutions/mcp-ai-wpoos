<?php
/**
 * Shopify Inventory Tool — manage inventory on a connected Shopify store via the Admin GraphQL API.
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
 * Provides inventory management operations for Shopify stores.
 *
 * Supports listing inventory levels by location, adjusting quantities,
 * and listing store locations via the Shopify Admin GraphQL API (2025-01+).
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Inventory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_inventory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Inventory', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manage inventory on a connected Shopify store via the Admin GraphQL API. Supports listing inventory levels by location, adjusting available quantities, and listing store locations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'     => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for the Shopify store. If omitted, automatically uses the Shopify connection configured for this assistant.', 'mcp-ai-wpoos-pro' ),
				),
				'action'            => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list_levels, adjust, list_locations, get_shop_info.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list_levels', 'adjust', 'list_locations', 'get_shop_info' ),
					'default'     => 'list_levels',
				),
				'location_id'       => array(
					'type'        => 'string',
					'description' => __( 'Shopify Location GID for inventory operations (e.g. gid://shopify/Location/123456789). If omitted for list_levels, uses the primary location.', 'mcp-ai-wpoos-pro' ),
				),
				'inventory_item_id' => array(
					'type'        => 'string',
					'description' => __( 'Shopify InventoryItem GID for the adjust action (e.g. gid://shopify/InventoryItem/123456789).', 'mcp-ai-wpoos-pro' ),
				),
				'delta'             => array(
					'type'        => 'integer',
					'description' => __( 'Quantity change for the adjust action. Positive to add stock, negative to remove.', 'mcp-ai-wpoos-pro' ),
				),
				'reason'            => array(
					'type'        => 'string',
					'description' => __( 'Reason for inventory adjustment (adjust action). Default: correction.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'correction',
					'enum'        => array( 'correction', 'cycle_count_available', 'damaged', 'movement_created', 'movement_updated', 'movement_received', 'movement_canceled', 'other', 'promotion', 'quality_control', 'received', 'reservation_created', 'reservation_deleted', 'reservation_updated', 'retail_pack', 'shrinkage', 'unknown', 'unpack' ),
				),
				'first'             => array(
					'type'        => 'integer',
					'description' => __( 'Number of inventory levels to return (1–250). Default: 50.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 250,
				),
				'after'             => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor for list_levels (endCursor from a previous response).', 'mcp-ai-wpoos-pro' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_shopify_inventory_required_capability', 'edit_posts', $context );

		// Allow guest users when the assistant is configured for public access.
		if ( ! $is_guest && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_forbidden', __( 'You do not have permission to manage Shopify inventory.', 'mcp-ai-wpoos-pro' ) );
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
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_levels';

		switch ( $action ) {
			case 'list_levels':
				return $this->handle_list_levels( $client, $arguments );

			case 'adjust':
				return $this->handle_adjust( $client, $arguments );

			case 'list_locations':
				return $this->handle_list_locations( $client, $arguments );

			case 'get_shop_info':
				return $this->handle_get_shop_info( $client );

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle list_levels action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_list_levels( $client, array $arguments ) {
		$location_id = isset( $arguments['location_id'] ) ? sanitize_text_field( $arguments['location_id'] ) : '';
		$first       = isset( $arguments['first'] ) ? max( 1, min( 250, absint( $arguments['first'] ) ) ) : 50;
		$after       = isset( $arguments['after'] ) ? sanitize_text_field( $arguments['after'] ) : '';

		if ( ! empty( $location_id ) && is_numeric( $location_id ) ) {
			$location_id = 'gid://shopify/Location/' . $location_id;
		}

		$response = $client->get_inventory_levels( $location_id, $first, $after );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		// Normalize output for both query shapes (with/without explicit location_id).
		$levels        = array();
		$page_info     = array();
		$location_name = '';
		$location_gid  = '';

		if ( ! empty( $location_id ) && isset( $response['data']['location'] ) ) {
			$loc           = $response['data']['location'];
			$location_name = isset( $loc['name'] ) ? $loc['name'] : '';
			$location_gid  = isset( $loc['id'] ) ? $loc['id'] : '';
			$page_info     = isset( $loc['inventoryLevels']['pageInfo'] ) ? $loc['inventoryLevels']['pageInfo'] : array();
			$edges         = isset( $loc['inventoryLevels']['edges'] ) ? $loc['inventoryLevels']['edges'] : array();
		} else {
			$loc_edges     = isset( $response['data']['locations']['edges'] ) ? $response['data']['locations']['edges'] : array();
			$loc           = ! empty( $loc_edges ) ? ( isset( $loc_edges[0]['node'] ) ? $loc_edges[0]['node'] : array() ) : array();
			$location_name = isset( $loc['name'] ) ? $loc['name'] : '';
			$location_gid  = isset( $loc['id'] ) ? $loc['id'] : '';
			$page_info     = isset( $loc['inventoryLevels']['pageInfo'] ) ? $loc['inventoryLevels']['pageInfo'] : array();
			$edges         = isset( $loc['inventoryLevels']['edges'] ) ? $loc['inventoryLevels']['edges'] : array();
		}

		foreach ( $edges as $edge ) {
			$node     = isset( $edge['node'] ) ? $edge['node'] : array();
			$levels[] = array(
				'id'         => isset( $node['id'] ) ? $node['id'] : '',
				'quantities' => isset( $node['quantities'] ) ? $node['quantities'] : array(),
				'item'       => isset( $node['item'] ) ? $node['item'] : array(),
			);
		}

		return array(
			'success'       => true,
			'location_id'   => $location_gid,
			'location_name' => $location_name,
			'levels'        => $levels,
			'count'         => count( $levels ),
			'page_info'     => $page_info,
		);
	}

	/**
	 * Handle adjust action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_adjust( $client, array $arguments ) {
		$inventory_item_id = isset( $arguments['inventory_item_id'] ) ? sanitize_text_field( $arguments['inventory_item_id'] ) : '';
		$location_id       = isset( $arguments['location_id'] ) ? sanitize_text_field( $arguments['location_id'] ) : '';

		if ( empty( $inventory_item_id ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_item', __( 'inventory_item_id is required for the adjust action.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $location_id ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_location', __( 'location_id is required for the adjust action.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! isset( $arguments['delta'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_delta', __( 'delta is required for the adjust action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_numeric( $inventory_item_id ) ) {
			$inventory_item_id = 'gid://shopify/InventoryItem/' . $inventory_item_id;
		}
		if ( is_numeric( $location_id ) ) {
			$location_id = 'gid://shopify/Location/' . $location_id;
		}

		$delta  = (int) $arguments['delta'];
		$reason = isset( $arguments['reason'] ) ? sanitize_key( $arguments['reason'] ) : 'correction';

		$response = $client->adjust_inventory( $inventory_item_id, $location_id, $delta, $reason );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_errors = isset( $response['data']['inventoryAdjustQuantities']['userErrors'] ) ? $response['data']['inventoryAdjustQuantities']['userErrors'] : array();
		if ( ! empty( $user_errors ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_user_error', $user_errors[0]['message'] ?? __( 'Shopify validation error.', 'mcp-ai-wpoos-pro' ) );
		}

		$group = isset( $response['data']['inventoryAdjustQuantities']['inventoryAdjustmentGroup'] ) ? $response['data']['inventoryAdjustQuantities']['inventoryAdjustmentGroup'] : null;

		return array(
			'success'          => true,
			'adjustment_group' => $group,
			/* translators: %+d: quantity change (signed integer) */
			'message'          => sprintf( __( 'Inventory adjusted by %+d successfully.', 'mcp-ai-wpoos-pro' ), $delta ),
		);
	}

	/**
	 * Handle list_locations action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_list_locations( $client, array $arguments ) {
		$first    = isset( $arguments['first'] ) ? max( 1, min( 50, absint( $arguments['first'] ) ) ) : 10;
		$response = $client->get_locations( $first );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$locations = array();
		$edges     = isset( $response['data']['locations']['edges'] ) ? $response['data']['locations']['edges'] : array();

		foreach ( $edges as $edge ) {
			$locations[] = isset( $edge['node'] ) ? $edge['node'] : array();
		}

		return array(
			'success'   => true,
			'locations' => $locations,
			'count'     => count( $locations ),
		);
	}

	/**
	 * Handle get_shop_info action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client Shopify client.
	 * @return array|WP_Error
	 */
	protected function handle_get_shop_info( $client ) {
		$response = $client->get_shop_info();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$shop = isset( $response['data']['shop'] ) ? $response['data']['shop'] : array();

		return array(
			'success' => true,
			'shop'    => $shop,
		);
	}
}
