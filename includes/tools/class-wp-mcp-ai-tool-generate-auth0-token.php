<?php
/**
 * Tool for generating Auth0 bearer tokens using client credentials flow.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates an Auth0 bearer token using OAuth 2.0 client credentials flow.
 */
class WP_MCP_AI_Tool_Generate_Auth0_Token implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether the tool can be registered.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * Describe why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_auth0_token';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Auth0 Token', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates an Auth0 bearer token using OAuth 2.0 client credentials flow. Requires Auth0 Management API client ID and client secret.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'auth0_domain'  => array(
					'type'        => 'string',
					'description' => __( 'Auth0 domain (e.g., example.us.auth0.com)', 'wp-mcp-ai' ),
				),
				'client_id'     => array(
					'type'        => 'string',
					'description' => __( 'Auth0 Management API Client ID', 'wp-mcp-ai' ),
				),
				'client_secret' => array(
					'type'        => 'string',
					'description' => __( 'Auth0 Management API Client Secret', 'wp-mcp-ai' ),
				),
				'audience'      => array(
					'type'        => 'string',
					'description' => __( 'Auth0 audience (optional, defaults to Management API audience)', 'wp-mcp-ai' ),
				),
			),
			'required'   => array( 'auth0_domain', 'client_id', 'client_secret' ),
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
		// Verify user has manage_options capability.
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id || ! user_can( $acting_user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_auth0_token_forbidden',
				__( 'You do not have permission to generate Auth0 tokens.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate required parameters.
		$auth0_domain  = isset( $arguments['auth0_domain'] ) ? trim( sanitize_text_field( $arguments['auth0_domain'] ) ) : '';
		$client_id     = isset( $arguments['client_id'] ) ? trim( sanitize_text_field( $arguments['client_id'] ) ) : '';
		$client_secret = isset( $arguments['client_secret'] ) ? trim( $arguments['client_secret'] ) : '';

		if ( empty( $auth0_domain ) || empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error(
				'wp_mcp_ai_auth0_token_missing_params',
				__( 'Auth0 domain, client ID, and client secret are required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Determine audience.
		$audience = isset( $arguments['audience'] ) ? trim( sanitize_text_field( $arguments['audience'] ) ) : '';
		if ( empty( $audience ) ) {
			// Default to Management API audience.
			$audience = 'https://' . $auth0_domain . '/api/v2/';
		}

		// Request token from Auth0.
		$token_response = $this->request_auth0_token( $auth0_domain, $client_id, $client_secret, $audience );

		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}

		/**
		 * Fires after an Auth0 token has been generated.
		 *
		 * @param array $token_response Token response from Auth0.
		 * @param array $arguments      Tool arguments.
		 * @param array $context        Tool execution context.
		 */
		do_action( 'wp_mcp_ai_auth0_token_generated', $token_response, $arguments, $context );

		return $token_response;
	}

	/**
	 * Request a bearer token from Auth0 using client credentials flow.
	 *
	 * @param string $domain        Auth0 domain.
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @param string $audience      API audience.
	 * @return array|WP_Error Token response or error.
	 */
	protected function request_auth0_token( $domain, $client_id, $client_secret, $audience ) {
		$url  = 'https://' . $domain . '/oauth/token';
		$body = wp_json_encode(
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'audience'      => $audience,
			)
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_auth0_token_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to request Auth0 token: %s', 'wp-mcp-ai' ),
					$response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$body_content = wp_remote_retrieve_body( $response );
		$data         = json_decode( $body_content, true );

		if ( 200 !== $code ) {
			$error_message = __( 'Auth0 rejected the token request.', 'wp-mcp-ai' );
			if ( is_array( $data ) && ! empty( $data['error_description'] ) ) {
				$error_message = sanitize_text_field( $data['error_description'] );
			} elseif ( is_array( $data ) && ! empty( $data['error'] ) ) {
				$error_message = sanitize_text_field( $data['error'] );
			}

			return new WP_Error(
				'wp_mcp_ai_auth0_token_rejected',
				$error_message,
				array(
					'status'  => $code,
					'details' => array(
						'http_code' => $code,
						'response'  => $data,
					),
				)
			);
		}

		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_auth0_token_invalid_response',
				__( 'Auth0 returned an unexpected response.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'details' => array(
						'response' => $data,
					),
				)
			);
		}

		$token      = (string) $data['access_token'];
		$token_type = isset( $data['token_type'] ) ? sanitize_text_field( $data['token_type'] ) : 'Bearer';
		$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 86400;
		$scope      = isset( $data['scope'] ) ? sanitize_text_field( $data['scope'] ) : '';

		$expires_at = null;
		if ( $expires_in > 0 ) {
			$expires_at = gmdate( 'c', time() + $expires_in );
		}

		return array(
			'summary'      => __( 'Auth0 token generated successfully', 'wp-mcp-ai' ),
			'access_token' => $token,
			'token_type'   => $token_type,
			'expires_in'   => $expires_in,
			'expires_at'   => $expires_at,
			'scope'        => $scope,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
