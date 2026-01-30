<?php
/**
 * EZuite ERP Integration Tool.
 *
 * Enables AI assistants to connect to EZuite ERP system for inventory
 * management, item lookups, and other ERP operations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * EZuite ERP integration tool for inventory and item management.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_EZuite_ERP implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'ezuite_erp';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'EZuite ERP', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Connect to EZuite ERP system for inventory management, item lookups, and other ERP operations. Supports multiple API actions including item pull, inventory updates, and more.', 'mcp-ai-wpoos-pro' );
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
			'name'                => 'ezuite_erp',
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
						'invoke_api',
					),
					'default'     => 'list_connections',
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'REQUIRED (except for list_connections). The connection ID obtained from calling list_connections first.', 'mcp-ai-wpoos-pro' ),
				),
				'api_action'    => array(
					'type'        => 'string',
					'description' => __( 'EZuite API action to invoke (e.g., "LX_ItemPull" for pulling items). Case-insensitive. Required for invoke_api action.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'LX_ItemPull',
						'LX_ItemUpdate',
						'LX_ItemCreate',
						'LX_InventoryQuery',
						'LX_OrderCreate',
						'LX_OrderUpdate',
						'LX_CustomerQuery',
					),
				),
				'api_body'      => array(
					'type'        => 'array',
					'description' => __( 'Request body array for the EZuite API action. Example: [{"Location_Code": "ALL"}] for LX_ItemPull.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'object',
					),
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
				__( 'You do not have permission to access EZuite ERP.', 'mcp-ai-wpoos-pro' )
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

		// Only allow ezuite_erp connections for this tool.
		if ( empty( $connection['connection_type'] ) || 'ezuite_erp' !== $connection['connection_type'] ) {
			return new WP_Error(
				'wp_mcp_ai_pro_wrong_connection_type',
				__( 'This connection is not an EZuite ERP connection. Use the ezuite_erp connection type.', 'mcp-ai-wpoos-pro' )
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

			case 'invoke_api':
				return $this->invoke_api( $connection, $arguments );

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
	 * List all available EZuite ERP connections.
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

			// Only include EZuite ERP connections.
			if ( empty( $connection['connection_type'] ) || 'ezuite_erp' !== $connection['connection_type'] ) {
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
				__( 'Found %d EZuite ERP connection(s)', 'mcp-ai-wpoos-pro' ),
				count( $result )
			),
			'connections' => $result,
			'count'       => count( $result ),
		);

		/**
		 * Filter the list_connections response for EZuite ERP.
		 *
		 * @since 1.0.0
		 *
		 * @param array $response Connection list response.
		 * @param array $context  Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_pro_ezuite_erp_connections_list', $response, $context );
	}

	/**
	 * Test connection to EZuite ERP.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Test results or error.
	 */
	protected function test_connection( $connection ) {
		// Test with a simple API action.
		$test_body = array(
			array(
				'Location_Code' => 'ALL',
			),
		);

		$result = $this->make_erp_request( $connection, 'LX_ItemPull', $test_body );

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
	 * Invoke an EZuite ERP API action.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Request arguments.
	 * @return array|WP_Error Request results or error.
	 */
	protected function invoke_api( $connection, $arguments ) {
		$api_action = isset( $arguments['api_action'] ) ? sanitize_text_field( $arguments['api_action'] ) : '';

		if ( empty( $api_action ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_api_action',
				__( 'API action parameter is required for invoke_api action.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate and normalize API action (case-insensitive).
		$allowed_actions = array(
			'LX_ItemPull',
			'LX_ItemUpdate',
			'LX_ItemCreate',
			'LX_InventoryQuery',
			'LX_OrderCreate',
			'LX_OrderUpdate',
			'LX_CustomerQuery',
		);

		// Create case-insensitive mapping.
		$action_map = array();
		foreach ( $allowed_actions as $allowed_action ) {
			$action_map[ strtolower( $allowed_action ) ] = $allowed_action;
		}

		// Normalize the API action to proper casing.
		$api_action_lower      = strtolower( $api_action );
		$normalized_api_action = isset( $action_map[ $api_action_lower ] ) ? $action_map[ $api_action_lower ] : null;

		if ( null === $normalized_api_action ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_api_action',
				sprintf(
					/* translators: 1: API action name, 2: list of allowed actions */
					__( 'Invalid API action: %1$s. Allowed actions: %2$s', 'mcp-ai-wpoos-pro' ),
					$api_action,
					implode( ', ', $allowed_actions )
				)
			);
		}

		// Use the normalized action name for consistency.
		$api_action = $normalized_api_action;

		// Prepare request body.
		$api_body = array();
		if ( ! empty( $arguments['api_body'] ) && is_array( $arguments['api_body'] ) ) {
			$api_body = $arguments['api_body'];
		} else {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_api_body',
				__( 'API body parameter is required. For LX_ItemPull, provide Location_Code. Note: Using "ALL" may return large datasets.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Make the ERP request.
		$result = $this->make_erp_request( $connection, $api_action, $api_body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'api_action' => $api_action,
			'data'       => $result,
		);
	}

	/**
	 * Make an authenticated HTTP request to EZuite ERP API.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $connection Connection data.
	 * @param string $api_action ERP API action.
	 * @param array  $api_body   Request body.
	 * @return array|WP_Error Response data or error.
	 */
	protected function make_erp_request( $connection, $api_action, $api_body ) {
		$url = trailingslashit( $connection['url'] );

		// Get API key from connection.
		$api_key = isset( $connection['api_key'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] ) : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_api_key',
				__( 'API key is not configured for this connection.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare request body according to EZuite API format.
		$request_body = array(
			'API_Key'    => $api_key,
			'API_Action' => $api_action,
			'API_Body'   => $api_body,
		);

		$args = array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $request_body ),
		);

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code >= 400 ) {
			$error_message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP error %d', 'mcp-ai-wpoos-pro' ),
				$status_code
			);

			$decoded = json_decode( $body, true );

			if ( isset( $decoded['Message'] ) ) {
				$error_message .= ': ' . $decoded['Message'];
			}

			return new WP_Error( 'wp_mcp_ai_pro_http_error', $error_message );
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_json',
				__( 'Invalid JSON response from EZuite ERP API.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check EZuite API response status.
		if ( isset( $decoded['Status_Code'] ) && 200 !== absint( $decoded['Status_Code'] ) ) {
			$error_message = isset( $decoded['Message'] ) ? $decoded['Message'] : __( 'Unknown error', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'wp_mcp_ai_pro_erp_error', $error_message );
		}

		return $decoded;
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
	 * Check rate limiting for ERP API requests.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$user_id        = absint( $user_id );
		$transient_key  = 'wp_mcp_ai_pro_ezuite_erp_' . $user_id;
		$current_count  = get_transient( $transient_key );
		$max_per_minute = 30;

		/**
		 * Filter the maximum EZuite ERP API requests allowed per minute per user.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_per_minute Maximum requests per minute (default: 30).
		 * @param int $user_id        User ID.
		 */
		$max_per_minute = apply_filters( 'wp_mcp_ai_pro_ezuite_erp_rate_limit', $max_per_minute, $user_id );

		if ( false === $current_count ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_minute ) {
			return new WP_Error(
				'wp_mcp_ai_pro_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum requests allowed per minute */
					__( 'EZuite ERP API rate limit exceeded. Maximum %d requests per minute allowed.', 'mcp-ai-wpoos-pro' ),
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
			'write',                // Can modify data (includes read operations).
			'external-api',         // Makes external API calls.
			'requires-capability',  // Requires 'edit_posts' capability.
			'requires-credentials', // Requires API key configuration.
			'network-dependent',    // Requires internet connectivity.
			'may-timeout',          // External API calls may timeout.
			'rate-limited',         // Subject to rate limiting.
		);
	}
}
