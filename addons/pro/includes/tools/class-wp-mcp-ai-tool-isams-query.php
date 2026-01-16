<?php
/**
 * Tool for querying iSAMS School Management System.
 *
 * Provides access to iSAMS data including pupils, employees, departments, and more.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queries iSAMS School Management System via REST API.
 */
class WP_MCP_AI_Tool_ISAMS_Query implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'isams_query';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Query iSAMS', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Query iSAMS School Management System for pupils, employees, departments, terms, and other school data.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Optional Remote Sites connection ID for iSAMS. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos-pro' ),
				),
				'endpoint'   => array(
					'type'        => 'string',
					'description' => __( 'API endpoint to query. Available: pupils, employees, departments, houses, terms, subjects, year_groups', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'pupils',
						'employees',
						'departments',
						'houses',
						'terms',
						'subjects',
						'year_groups',
						'admission_applicants',
					),
				),
				'id'         => array(
					'type'        => 'string',
					'description' => __( 'Optional specific ID to retrieve a single record', 'mcp-ai-wpoos-pro' ),
				),
				'page'       => array(
					'type'        => 'integer',
					'description' => __( 'Page number for paginated results', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'required'             => array( 'endpoint' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api', 'read-only' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Check if in base version mode.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Tool is always available in Pro mode.
		// Credential validation happens in execute() method.
		return true;
	}

	/**
	 * Get unavailable reason message.
	 *
	 * This is only called when is_available() returns false (i.e., in Base version mode).
	 * In Pro mode, the tool is always available and credential validation happens in execute().
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'iSAMS Query tool is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions.
		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to query iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate endpoint.
		$endpoint = isset( $arguments['endpoint'] ) ? sanitize_text_field( $arguments['endpoint'] ) : '';
		if ( empty( $endpoint ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_endpoint',
				__( 'Endpoint parameter is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get connection_id if provided.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

		// Try to get credentials from connection first, then fall back to settings.
		if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || 'isams' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not an iSAMS connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check if connection is enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Get credentials from connection.
			$api_url    = isset( $connection['url'] ) ? trailingslashit( $connection['url'] ) : '';
			$api_key    = ! empty( $connection['api_key'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] ) : '';
			$api_secret = ! empty( $connection['api_secret'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] ) : '';
		} else {
			// Fallback to settings (old approach - for backward compatibility).
			$settings   = get_option( 'wp_mcp_ai_settings', array() );
			$api_url    = isset( $settings['isams_api_url'] ) ? trailingslashit( $settings['isams_api_url'] ) : '';
			$api_key    = isset( $settings['isams_api_key'] ) ? $settings['isams_api_key'] : '';
			$api_secret = isset( $settings['isams_api_secret'] ) ? $settings['isams_api_secret'] : '';

			// Show deprecation notice in logs if using settings.
			if ( ! empty( $api_url ) && ! empty( $api_key ) ) {
				error_log( 'WP MCP AI: Settings-based iSAMS configuration is deprecated. Please migrate to Remote Sites connections.' );
			}
		}

		// Validate credentials.
		if ( empty( $api_url ) || empty( $api_key ) || empty( $api_secret ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_not_configured',
				__( 'iSAMS API credentials are not configured. Please configure them in Settings or use a Remote Sites connection.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get authentication token.
		$token = $this->get_access_token( $api_url, $api_key, $api_secret );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Build API endpoint URL.
		$api_endpoint = $this->get_api_endpoint( $endpoint );
		if ( is_wp_error( $api_endpoint ) ) {
			return $api_endpoint;
		}

		$url = $api_url . $api_endpoint;

		// Handle specific ID request.
		$id = isset( $arguments['id'] ) ? sanitize_text_field( $arguments['id'] ) : '';
		if ( ! empty( $id ) ) {
			$url .= '/' . $id;
			return $this->make_api_request( $url, $token );
		}

		// Handle paginated request.
		$page  = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 20;
		$limit = min( $limit, 100 ); // Cap at 100.

		// Add pagination parameters.
		$url = add_query_arg(
			array(
				'page'     => $page,
				'pageSize' => $limit,
			),
			$url
		);

		return $this->make_api_request( $url, $token );
	}

	/**
	 * Get iSAMS access token.
	 *
	 * @param string $api_url    API base URL.
	 * @param string $api_key    API key.
	 * @param string $api_secret API secret.
	 * @return string|WP_Error Access token or error.
	 */
	private function get_access_token( $api_url, $api_key, $api_secret ) {
		// Check for cached token.
		$cache_key = 'wp_mcp_ai_isams_token_' . md5( $api_url . $api_key );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// Request new token.
		$auth_url = $api_url . 'api/authentication/token';

		$response = wp_remote_post(
			$auth_url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'apiKey' => $api_key,
						'apiSecret' => $api_secret,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_auth_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to authenticate with iSAMS: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_isams_auth_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'iSAMS authentication failed with status code: %d', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data['token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_invalid_token',
				__( 'Invalid token response from iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		$token = $data['token'];

		// Cache token for 55 minutes (tokens typically expire in 1 hour).
		set_transient( $cache_key, $token, 55 * MINUTE_IN_SECONDS );

		return $token;
	}

	/**
	 * Get the API endpoint path for the requested endpoint.
	 *
	 * @param string $endpoint Endpoint name.
	 * @return string|WP_Error API endpoint path or error.
	 */
	private function get_api_endpoint( $endpoint ) {
		$endpoints = array(
			'pupils'               => 'api/students',
			'employees'            => 'api/humanresources/employees',
			'departments'          => 'api/school/departments',
			'houses'               => 'api/school/houses',
			'terms'                => 'api/school/terms',
			'subjects'             => 'api/teaching/subjects',
			'year_groups'          => 'api/school/yeargroups',
			'admission_applicants' => 'api/admissions/applicants',
		);

		if ( ! isset( $endpoints[ $endpoint ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_endpoint',
				sprintf(
					/* translators: %s: endpoint name */
					__( 'Invalid iSAMS endpoint: %s', 'mcp-ai-wpoos-pro' ),
					$endpoint
				)
			);
		}

		return $endpoints[ $endpoint ];
	}

	/**
	 * Make an API request to iSAMS.
	 *
	 * @param string $url   API URL.
	 * @param string $token Access token.
	 * @return array|WP_Error Response data or error.
	 */
	private function make_api_request( $url, $token ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_isams_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'iSAMS API request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error(
				'wp_mcp_ai_isams_api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body */
					__( 'iSAMS API error (status %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$status_code,
					$body
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data ) {
			return new WP_Error(
				'wp_mcp_ai_isams_invalid_json',
				__( 'Invalid JSON response from iSAMS.', 'mcp-ai-wpoos-pro' )
			);
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}
}
