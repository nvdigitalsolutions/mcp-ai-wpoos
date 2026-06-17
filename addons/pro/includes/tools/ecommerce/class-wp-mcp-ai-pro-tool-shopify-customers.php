<?php
/**
 * Shopify Customers Tool — manage customers on a connected Shopify store via the Admin GraphQL API.
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
 * Provides customer management operations for Shopify stores.
 *
 * Supports listing, searching, and retrieving individual customers including
 * their order history via the Shopify Admin GraphQL API (2025-01+).
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Customers implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_customers';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Customers', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Access and manage customers on a connected Shopify store via the Admin GraphQL API. Supports listing, filtering by email/name/tags, and retrieving detailed customer profiles including order history and marketing consent.', 'mcp-ai-wpoos-pro' );
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
				'customer_id'   => array(
					'type'        => 'string',
					'description' => __( 'Shopify customer GID (e.g. gid://shopify/Customer/123456789) for the get action.', 'mcp-ai-wpoos-pro' ),
				),
				'first'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of customers to return (1–250). Default: 10.', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Shopify customer search/filter query. Supports Shopify filter syntax, e.g. "email:john@example.com", "tag:vip", "total_spent:>100".', 'mcp-ai-wpoos-pro' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_shopify_customers_required_capability', 'edit_posts', $context );

		// Allow guest users when the assistant is configured for public access.
		if ( ! $is_guest && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_forbidden', __( 'You do not have permission to access Shopify customers.', 'mcp-ai-wpoos-pro' ) );
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

		$response = $client->get_customers( $first, $after, $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$customers = array();
		$edges     = isset( $response['data']['customers']['edges'] ) ? $response['data']['customers']['edges'] : array();

		foreach ( $edges as $edge ) {
			$node        = isset( $edge['node'] ) ? $edge['node'] : array();
			$customers[] = $this->normalize_customer( $node );
		}

		return array(
			'success'   => true,
			'customers' => $customers,
			'count'     => count( $customers ),
			'page_info' => isset( $response['data']['customers']['pageInfo'] ) ? $response['data']['customers']['pageInfo'] : array(),
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
		$customer_id = isset( $arguments['customer_id'] ) ? sanitize_text_field( $arguments['customer_id'] ) : '';
		if ( empty( $customer_id ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_customer_id', __( 'customer_id is required for the get action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_numeric( $customer_id ) ) {
			$customer_id = 'gid://shopify/Customer/' . $customer_id;
		}

		$response = $client->get_customer( $customer_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$node = isset( $response['data']['customer'] ) ? $response['data']['customer'] : null;
		if ( ! $node ) {
			return new WP_Error( 'wp_mcp_ai_shopify_not_found', __( 'Customer not found.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'  => true,
			'customer' => $this->normalize_customer( $node ),
		);
	}

	/**
	 * Normalize a customer node from the GraphQL response.
	 *
	 * @param array $node Raw GraphQL customer node.
	 * @return array Normalized customer array.
	 */
	protected function normalize_customer( array $node ) {
		$recent_orders = array();
		if ( isset( $node['orders']['edges'] ) ) {
			foreach ( $node['orders']['edges'] as $edge ) {
				$recent_orders[] = isset( $edge['node'] ) ? $edge['node'] : array();
			}
		}

		return array(
			'id'               => isset( $node['id'] ) ? $node['id'] : '',
			'first_name'       => isset( $node['firstName'] ) ? $node['firstName'] : '',
			'last_name'        => isset( $node['lastName'] ) ? $node['lastName'] : '',
			'email'            => isset( $node['email'] ) ? $node['email'] : '',
			'phone'            => isset( $node['phone'] ) ? $node['phone'] : '',
			'verified_email'   => isset( $node['verifiedEmail'] ) ? $node['verifiedEmail'] : false,
			'created_at'       => isset( $node['createdAt'] ) ? $node['createdAt'] : '',
			'updated_at'       => isset( $node['updatedAt'] ) ? $node['updatedAt'] : '',
			'number_of_orders' => isset( $node['numberOfOrders'] ) ? $node['numberOfOrders'] : 0,
			'amount_spent'     => isset( $node['amountSpent'] ) ? $node['amountSpent'] : array(),
			'tags'             => isset( $node['tags'] ) ? $node['tags'] : array(),
			'note'             => isset( $node['note'] ) ? $node['note'] : '',
			'default_address'  => isset( $node['defaultAddress'] ) ? $node['defaultAddress'] : null,
			'recent_orders'    => $recent_orders,
			'email_marketing'  => isset( $node['emailMarketingConsent'] ) ? $node['emailMarketingConsent'] : null,
			'sms_marketing'    => isset( $node['smsMarketingConsent'] ) ? $node['smsMarketingConsent'] : null,
		);
	}
}
