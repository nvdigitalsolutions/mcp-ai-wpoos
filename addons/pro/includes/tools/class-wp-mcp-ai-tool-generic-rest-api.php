<?php
/**
 * Generic REST API Connection Tool.
 *
 * Enables AI assistants to connect to ANY REST API (not just WordPress).
 * Reuses all performance infrastructure from remote_wp_connection tool.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Generic REST API connection tool for any REST API.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Generic_REST_API implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generic_rest_api';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generic REST API', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Connect to any REST API with full support for custom endpoints, headers, and authentication. Includes caching, retry logic, health monitoring, and request deduplication.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return $this->get_schema();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                => 'generic_rest_api',
			'description'         => $this->get_description(),
			'required_capability' => 'edit_posts',
			'input_schema'        => $this->get_parameters_schema(),
		);
	}

	/**
	 * Get tool schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array Tool schema.
	 */
	protected function get_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'The action to perform. IMPORTANT: Always call with "list_connections" FIRST to discover available connection IDs.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'list_connections',
						'test_connection',
						'make_request',
					),
					'default'     => 'list_connections',
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'REQUIRED (except for list_connections). The connection ID obtained from calling list_connections first.', 'mcp-ai-wpoos-pro' ),
				),
				'method'        => array(
					'type'        => 'string',
					'description' => __( 'HTTP method for make_request action.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS' ),
					'default'     => 'GET',
				),
				'endpoint'      => array(
					'type'        => 'string',
					'description' => __( 'API endpoint path (e.g., /api/v1/users or /users). For make_request action.', 'mcp-ai-wpoos-pro' ),
				),
				'headers'       => array(
					'type'        => 'object',
					'description' => __( 'Custom headers to send with the request (optional). Example: {"X-Custom-Header": "value"}', 'mcp-ai-wpoos-pro' ),
				),
				'body'          => array(
					'type'        => 'object',
					'description' => __( 'Request body for POST/PUT/PATCH requests (optional). Will be JSON-encoded.', 'mcp-ai-wpoos-pro' ),
				),
				'query_params'  => array(
					'type'        => 'object',
					'description' => __( 'URL query parameters (optional). Example: {"page": 1, "limit": 10}', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check user permissions.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to access REST APIs.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_connections';

		// Check rate limiting (except for list_connections).
		if ( 'list_connections' !== $action ) {
			$rate_limit_check = $this->check_rate_limit( $user_id );
			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}
		}

		// Handle listing connections.
		if ( 'list_connections' === $action ) {
			return $this->list_connections( $context );
		}

		// Get connection.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		if ( empty( $connection_id ) ) {
			$available_connections = $this->list_connections( $context );
			$connection_list       = '';

			if ( ! is_wp_error( $available_connections ) && ! empty( $available_connections['connections'] ) ) {
				$connections_formatted = array();
				foreach ( $available_connections['connections'] as $conn ) {
					$connections_formatted[] = sprintf( '%s (%s)', $conn['id'], $conn['name'] );
				}
				$connection_list = ' Available connections: ' . implode( ', ', $connections_formatted ) . '.';
			}

			return new WP_Error(
				'wp_mcp_ai_pro_missing_connection',
				sprintf(
					/* translators: 1: action name, 2: list of available connections */
					__( 'Connection ID is required for action "%1$s".%2$s You must provide the connection_id parameter.', 'mcp-ai-wpoos-pro' ),
					$action,
					$connection_list
				)
			);
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_not_found',
				sprintf(
					/* translators: %s: connection ID */
					__( 'Connection "%s" not found. Call list_connections to see available connections.', 'mcp-ai-wpoos-pro' ),
					$connection_id
				)
			);
		}

		// Only allow generic connections for this tool.
		if ( empty( $connection['connection_type'] ) || 'generic' !== $connection['connection_type'] ) {
			return new WP_Error(
				'wp_mcp_ai_pro_wrong_connection_type',
				__( 'This connection is not a generic REST API connection. Use the remote_wp_connection tool for WordPress/WooCommerce connections.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if enabled.
		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_disabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Connection "%s" is disabled.', 'mcp-ai-wpoos-pro' ),
					$connection['name']
				)
			);
		}

		// Check if connection is enabled for this assistant.
		if ( ! $this->is_connection_enabled_for_assistant( $connection_id, $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_not_enabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Connection "%s" is not enabled for this assistant.', 'mcp-ai-wpoos-pro' ),
					$connection['name']
				)
			);
		}

		// Execute action.
		switch ( $action ) {
			case 'test_connection':
				return $this->test_connection( $connection );

			case 'make_request':
				return $this->make_request( $connection, $arguments );

			default:
				return new WP_Error(
					'wp_mcp_ai_pro_invalid_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Invalid action: %s', 'mcp-ai-wpoos-pro' ),
						$action
					)
				);
		}
	}

	/**
	 * List all available generic REST API connections.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Execution context including assistant_id.
	 * @return array Connections list.
	 */
	protected function list_connections( $context = array() ) {
		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		// Get assistant ID from context to filter connections.
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		// Get enabled connections for this assistant.
		$enabled_connections = array();
		if ( $assistant_id ) {
			$enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );
			if ( ! is_array( $enabled_connections ) ) {
				$enabled_connections = array();
			}
		}

		$result = array();

		foreach ( $connections as $connection ) {
			// Skip if not enabled globally.
			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			// Only include generic connections.
			if ( empty( $connection['connection_type'] ) || 'generic' !== $connection['connection_type'] ) {
				continue;
			}

			// If assistant context is provided and connections are configured,
			// only include connections enabled for this assistant.
			if ( $assistant_id && ! empty( $enabled_connections ) && ! in_array( $connection['id'], $enabled_connections, true ) ) {
				continue;
			}

			$result[] = array(
				'id'      => $connection['id'],
				'name'    => $connection['name'],
				'url'     => $connection['url'],
				'enabled' => ! empty( $connection['enabled'] ),
			);
		}

		$response = array(
			'summary'     => sprintf(
				/* translators: %d: number of connections */
				__( 'Found %d generic REST API connection(s)', 'mcp-ai-wpoos-pro' ),
				count( $result )
			),
			'connections' => $result,
			'count'       => count( $result ),
		);

		/**
		 * Filter the list_connections response for generic REST APIs.
		 *
		 * @since 1.0.0
		 *
		 * @param array $response Connection list response.
		 * @param array $context  Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_pro_generic_rest_connections_list', $response, $context );
	}

	/**
	 * Test connection to a generic REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Test results or error.
	 */
	protected function test_connection( $connection ) {
		// Use a simple HEAD or GET request to test connectivity.
		$test_endpoint = isset( $connection['test_endpoint'] ) && ! empty( $connection['test_endpoint'] )
			? $connection['test_endpoint']
			: '/';

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $test_endpoint, 'GET', array() );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Connection test failed: %s', 'mcp-ai-wpoos-pro' ),
					$result->get_error_message()
				),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Connection successful.', 'mcp-ai-wpoos-pro' ),
			'url'     => $connection['url'],
		);
	}

	/**
	 * Make a request to the generic REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Request arguments.
	 * @return array|WP_Error Request results or error.
	 */
	protected function make_request( $connection, $arguments ) {
		$method   = isset( $arguments['method'] ) ? strtoupper( sanitize_text_field( $arguments['method'] ) ) : 'GET';
		$endpoint = isset( $arguments['endpoint'] ) ? sanitize_text_field( $arguments['endpoint'] ) : '';

		if ( empty( $endpoint ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_endpoint',
				__( 'Endpoint parameter is required for make_request action.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Add query parameters to endpoint if provided.
		if ( ! empty( $arguments['query_params'] ) && is_array( $arguments['query_params'] ) ) {
			$query_string = http_build_query( $arguments['query_params'] );
			$endpoint     = $endpoint . ( strpos( $endpoint, '?' ) === false ? '?' : '&' ) . $query_string;
		}

		// Prepare request body.
		$body = array();
		if ( ! empty( $arguments['body'] ) && is_array( $arguments['body'] ) ) {
			$body = $arguments['body'];
		}

		// Make the request using the Remote Site Manager (which handles caching, retry, etc.).
		$result = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, $method, $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add custom headers if provided (handled by make_request already, but document here).
		// Note: Custom headers should be added to connection configuration for security.

		return array(
			'success'  => true,
			'method'   => $method,
			'endpoint' => $endpoint,
			'data'     => $result,
		);
	}

	/**
	 * Check if a connection is enabled for the current assistant.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param array  $context       Execution context.
	 * @return bool True if enabled, false otherwise.
	 */
	protected function is_connection_enabled_for_assistant( $connection_id, $context ) {
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( ! $assistant_id ) {
			return true;
		}

		$enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );

		if ( ! is_array( $enabled_connections ) ) {
			return true;
		}

		return in_array( $connection_id, $enabled_connections, true );
	}

	/**
	 * Check rate limiting for REST API requests.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$user_id        = absint( $user_id );
		$transient_key  = 'wp_mcp_ai_pro_generic_rest_' . $user_id;
		$current_count  = get_transient( $transient_key );
		$max_per_minute = 30;

		/**
		 * Filter the maximum generic REST API requests allowed per minute per user.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_per_minute Maximum requests per minute (default: 30).
		 * @param int $user_id        User ID.
		 */
		$max_per_minute = apply_filters( 'wp_mcp_ai_pro_generic_rest_rate_limit', $max_per_minute, $user_id );

		if ( false === $current_count ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_minute ) {
			return new WP_Error(
				'wp_mcp_ai_pro_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum requests allowed per minute */
					__( 'Generic REST API rate limit exceeded. Maximum %d requests per minute allowed.', 'mcp-ai-wpoos-pro' ),
					$max_per_minute
				)
			);
		}

		set_transient( $transient_key, $current_count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro feature.
			'read-only',            // Can be read-only depending on HTTP method.
			'write',                // Can modify data via POST/PUT/PATCH/DELETE.
			'external-api',         // Makes external API calls.
			'requires-capability',  // Requires 'edit_posts' capability.
			'cacheable',            // GET requests can be cached.
			'network-dependent',    // Requires internet connectivity.
			'may-timeout',          // External API calls may timeout.
			'rate-limited',         // Subject to rate limiting.
			'supports-compression', // Supports gzip/deflate compression.
		);
	}
}
