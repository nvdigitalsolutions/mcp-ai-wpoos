<?php
/**
 * Remote Shopify Connection Tool.
 *
 * Provides a unified entry point for discovering and testing Shopify connections,
 * similar to the Remote WordPress Connection tool. This tool enables the AI
 * assistant to list available Shopify connections and test connectivity without
 * requiring a connection_id up front.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

if ( ! trait_exists( 'WP_MCP_AI_Shopify_Connection_Resolver' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php';
}

/**
 * Remote Shopify Connection Tool.
 *
 * Allows the AI assistant to discover, test, and interact with configured
 * Shopify connections. Acts as the unified entry point for Shopify store
 * operations, providing connection discovery via list_connections and
 * connectivity testing via test_connection.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Remote_Shopify_Connection implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'remote_shopify_connection';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Remote Shopify Connection', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Discover and manage Shopify store connections. Use list_connections to see available Shopify stores configured for this assistant. Use test_connection to verify connectivity. For product, order, customer, and inventory operations, use the dedicated shopify_products, shopify_orders, shopify_customers, and shopify_inventory tools — they will automatically use the correct connection when only one Shopify connection is configured.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform. list_connections returns all available Shopify connections for this assistant. test_connection verifies connectivity and authentication.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list_connections', 'test_connection' ),
					'default'     => 'list_connections',
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Connection ID for the test_connection action. If omitted and only one Shopify connection is configured, it will be used automatically.', 'mcp-ai-wpoos-pro' ),
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

		// Allow guest users when the assistant is configured for public access.
		if ( ! $is_guest && ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_forbidden',
				__( 'You do not have permission to access Shopify connections.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_connections';

		switch ( $action ) {
			case 'list_connections':
				return $this->handle_list_connections( $context );

			case 'test_connection':
				return $this->handle_test_connection( $arguments, $context );

			default:
				return new WP_Error(
					'wp_mcp_ai_shopify_invalid_action',
					__( 'Invalid action. Use list_connections or test_connection.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * List all available Shopify connections for the current assistant.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Execution context.
	 * @return array Connection list response.
	 */
	protected function handle_list_connections( $context ) {
		$shopify_connections = $this->get_available_shopify_connections( $context );

		$result = array(
			'summary'     => sprintf(
				/* translators: %d: number of connections */
				__( 'Found %d Shopify connection(s) available for this assistant.', 'mcp-ai-wpoos-pro' ),
				count( $shopify_connections )
			),
			'connections' => $shopify_connections,
			'count'       => count( $shopify_connections ),
			'hint'        => __( 'Use shopify_products, shopify_orders, shopify_customers, or shopify_inventory tools to interact with these stores. When only one connection is configured, you do not need to provide connection_id — it will be resolved automatically.', 'mcp-ai-wpoos-pro' ),
		);

		/**
		 * Filter the Shopify list_connections response.
		 *
		 * @since 1.0.0
		 *
		 * @param array $result  Connection list response.
		 * @param array $context Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_pro_shopify_connections_list', $result, $context );
	}

	/**
	 * Test a Shopify connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Test results.
	 */
	protected function handle_test_connection( $arguments, $context ) {
		// Resolve the connection — auto-resolves when only one is available.
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_no_manager', __( 'Remote Sites Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			return new WP_Error( 'wp_mcp_ai_shopify_connection_not_found', __( 'The specified connection was not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $connection['connection_type'] ) || 'shopify' !== $connection['connection_type'] ) {
			return new WP_Error( 'wp_mcp_ai_shopify_wrong_type', __( 'The specified connection is not a Shopify connection.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $this->is_shopify_connection_enabled_for_assistant( $connection_id, $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_not_enabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Shopify connection "%s" is not enabled for this assistant.', 'mcp-ai-wpoos-pro' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		// Determine API mode before creating the client.
		$api_mode = isset( $connection['shopify_api_mode'] ) ? $connection['shopify_api_mode'] : 'admin_api';

		// Attempt to load the Shopify client and run a basic shop info query.
		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php';
		}

		$client = new WP_MCP_AI_Shopify_Client( $connection_id );

		if ( 'admin_api' === $api_mode ) {
			// Test Admin GraphQL API via a simple shop query.
			$query  = '{ shop { name myshopifyDomain plan { displayName } } }';
			$result = $client->graphql_query( $query );

			if ( is_wp_error( $result ) ) {
				return array(
					'success'       => false,
					'connection_id' => $connection_id,
					'name'          => isset( $connection['name'] ) ? $connection['name'] : '',
					'api_mode'      => $api_mode,
					'error'         => $result->get_error_message(),
				);
			}

			$shop_data = isset( $result['data']['shop'] ) ? $result['data']['shop'] : array();

			return array(
				'success'          => true,
				'connection_id'    => $connection_id,
				'name'             => isset( $connection['name'] ) ? $connection['name'] : '',
				'api_mode'         => $api_mode,
				'shop_name'        => isset( $shop_data['name'] ) ? $shop_data['name'] : '',
				'myshopify_domain' => isset( $shop_data['myshopifyDomain'] ) ? $shop_data['myshopifyDomain'] : '',
				'plan'             => isset( $shop_data['plan']['displayName'] ) ? $shop_data['plan']['displayName'] : '',
				'message'          => __( 'Shopify Admin API connection successful.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Catalog API mode — test by verifying credentials exist.
		return array(
			'success'       => true,
			'connection_id' => $connection_id,
			'name'          => isset( $connection['name'] ) ? $connection['name'] : '',
			'api_mode'      => $api_mode,
			'message'       => __( 'Shopify Catalog API credentials are configured. Connection will be tested when a search is performed.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
