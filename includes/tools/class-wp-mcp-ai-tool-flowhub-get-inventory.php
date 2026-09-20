<?php
/**
 * Tool that retrieves inventory data from Flowhub API.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving inventory data from Flowhub cannabis dispensary system.
 */
class WP_MCP_AI_Tool_Flowhub_Get_Inventory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'flowhub_get_inventory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Flowhub Get Inventory', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieve cannabis inventory data from Flowhub dispensary system including packages, quantities, locations, and product details. Supports filtering by room and pagination.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking Flowhub package quantities, locations, and current stock levels.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Product catalog details such as pricing or strain info; use flowhub_get_products.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'flowhub_get_products', 'flowhub_create_order' ),
			'notes'           => __( 'Filter by room_id; paginate with limit and offset.', 'mcp-ai-wpoos' ),
		);
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
					'description' => __( 'Optional Remote Sites connection ID for Flowhub (conn_...). Omit to auto-resolve: toolkit settings credentials, then the configured sync connections, then the first enabled FlowHub connection.', 'mcp-ai-wpoos' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of inventory items to retrieve (1-100).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'offset'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to skip for pagination.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'room_id'       => array(
					'type'        => 'string',
					'description' => __( 'Filter inventory by specific room/location ID.', 'mcp-ai-wpoos' ),
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check whether the tool can be registered.
	 *
	 * @since 1.1.22
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_Flowhub_Client' );
	}

	/**
	 * Provide a message explaining why the tool is unavailable.
	 *
	 * @since 1.1.22
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Flowhub client is not available. The Flowhub integration requires API credentials to be configured.', 'mcp-ai-wpoos' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to retrieve Flowhub inventory.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require manage_woocommerce or manage_options capability.
			if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve inventory data.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		// Resolve the target FlowHub connection: explicit argument, settings
		// credentials, configured sync connections, or the first enabled
		// FlowHub connection in Remote Sites.
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Connection_Helper' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-connection-helper.php';
		}
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;
		$resolved      = WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( $connection_id );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$client_id   = $resolved['credentials']['client_id'];
		$api_key     = $resolved['credentials']['api_key'];
		$location_id = $resolved['credentials']['location_id'];

		// Build the inventory endpoint. When location_id is available, scope to that
		// location. Otherwise use the root inventoryNonZero endpoint (no location required).
		$options = array();

		if ( isset( $arguments['limit'] ) ) {
			$options['limit'] = max( 1, min( 100, absint( $arguments['limit'] ) ) );
		}
		if ( isset( $arguments['offset'] ) ) {
			$options['offset'] = max( 0, absint( $arguments['offset'] ) );
		}
		if ( isset( $arguments['room_id'] ) ) {
			$options['room_id'] = sanitize_text_field( $arguments['room_id'] );
		}
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		// Respect the connection's sandbox mode, matching the Remote Sites
		// connection test and the base client's endpoint resolution.
		$base_url = 'https://api.flowhub.co';
		if ( ! empty( $resolved['connection'] ) && ! empty( $resolved['connection']['sandbox_mode'] ) ) {
			$base_url = 'https://api.sandbox.flowhub.co';
		}

		if ( ! empty( $location_id ) ) {
			$endpoint = $base_url . '/v0/locations/' . rawurlencode( $location_id ) . '/inventoryNonZero';
		} else {
			$endpoint = $base_url . '/v0/inventoryNonZero';
		}
		$endpoint = add_query_arg( $options, $endpoint );

		// Attach the resolved proxy. Proxied Remote Sites connections route
		// FlowHub traffic through a forward proxy (e.g. a whitelisted egress
		// IP); without it the request egresses directly and FlowHub rejects
		// the server's IP with an auth error.
		$curl_proxy = null;
		if ( ! empty( $resolved['proxy']['url'] ) ) {
			$proxy_url  = $resolved['proxy']['url'];
			$proxy_auth = $resolved['proxy']['auth'];
			$curl_proxy = function ( $handle ) use ( $proxy_url, $proxy_auth ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Proxy support requires cURL-level configuration.
				curl_setopt( $handle, CURLOPT_PROXY, $proxy_url );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
				if ( ! empty( $proxy_auth ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
					curl_setopt( $handle, CURLOPT_PROXYUSERPWD, $proxy_auth );
				}
			};
			add_action( 'http_api_curl', $curl_proxy, 10, 1 );
		}

		try {
			$response = wp_remote_get(
				$endpoint,
				array(
					'timeout'     => isset( $options['timeout'] ) ? $options['timeout'] : 30,
					'redirection' => 3,
					'httpversion' => '1.1',
					'headers'     => array(
						'clientId' => $client_id,
						'key'      => $api_key,
						'Accept'   => 'application/json',
					),
				)
			);
		} finally {
			if ( null !== $curl_proxy ) {
				remove_action( 'http_api_curl', $curl_proxy, 10 );
			}
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'FlowHub API request failed: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_auth_failed',
				__( 'FlowHub API authentication failed. Check your client ID and API key.', 'mcp-ai-wpoos' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'FlowHub API returned unexpected status (HTTP %d).', 'mcp-ai-wpoos' ),
					$code
				)
			);
		}

		$result = json_decode( $body, true );

		if ( null === $result && json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_json_error',
				sprintf(
					/* translators: %s: JSON parse error */
					__( 'Failed to parse FlowHub API response: %s', 'mcp-ai-wpoos' ),
					json_last_error_msg()
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add summary for frontend display.
		$summary = __( 'Retrieved inventory data from Flowhub', 'mcp-ai-wpoos' );
		if ( isset( $result['total'] ) ) {
			$summary = sprintf(
				/* translators: %d: number of inventory items */
				__( 'Retrieved %d inventory items from Flowhub', 'mcp-ai-wpoos' ),
				absint( $result['total'] )
			);
		}

		$result = array_merge(
			array(
				'message' => $summary,
				'summary' => $summary,
			),
			$result
		);

		/**
		 * Allow third parties to filter the Flowhub inventory result.
		 *
		 * @param array $result    Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$result = apply_filters( 'wp_mcp_ai_flowhub_get_inventory_result', $result, $arguments, $context );

		return $result;
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ecommerce_business',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'inventory_specialist', 'ecommerce_manager' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls.
			'requires-credentials', // Requires Flowhub API credentials.
			'requires-capability',  // Requires user capabilities.
			'read-only',            // Only reads data, does not modify state.
			'pii-data',             // May return customer-related information.
			'rate-limited',         // Subject to Flowhub API rate limits.
			'paginated',            // Supports pagination.
		);
	}
}
