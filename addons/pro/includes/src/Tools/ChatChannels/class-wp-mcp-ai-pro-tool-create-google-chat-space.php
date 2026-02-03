<?php
/**
 * Tool that creates a Google Chat space.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for creating Google Chat spaces via the Google Chat API.
 */
class WP_MCP_AI_Pro_Tool_Create_Google_Chat_Space implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Google Chat requests.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_google_chat_space';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Google Chat Space', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new Google Chat space using the Google Chat API v1.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token' => array(
					'type'        => 'string',
					'description' => __( 'OAuth 2.0 access token for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'display_name' => array(
					'type'        => 'string',
					'description' => __( 'Display name for the new space.', 'mcp-ai-wpoos-pro' ),
				),
				'space_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type of space to create (default: SPACE).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'SPACE', 'GROUP_CHAT', 'DIRECT_MESSAGE' ),
					'default'     => 'SPACE',
				),
			),
			'required'             => array( 'access_token', 'display_name' ),
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

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_create_google_chat_space_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create Google Chat spaces.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_token( $arguments['access_token'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_access_token', __( 'A valid OAuth 2.0 access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$display_name = isset( $arguments['display_name'] ) ? sanitize_text_field( $arguments['display_name'] ) : '';

		if ( '' === $display_name ) {
			return new WP_Error( 'wp_mcp_ai_missing_display_name', __( 'A display name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$space_type = isset( $arguments['space_type'] ) ? sanitize_text_field( $arguments['space_type'] ) : 'SPACE';
		$allowed_types = array( 'SPACE', 'GROUP_CHAT', 'DIRECT_MESSAGE' );

		if ( ! in_array( $space_type, $allowed_types, true ) ) {
			$space_type = 'SPACE';
		}

		$endpoint = 'https://chat.googleapis.com/v1/spaces';

		$payload = array(
			'spaceType'   => $space_type,
			'displayName' => $display_name,
		);

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Google Chat request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'google_chat_create_space_request',
			'Creating Google Chat space.',
			array(
				'endpoint'     => $endpoint,
				'display_name' => $display_name,
				'space_type'   => $space_type,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_create_google_chat_space_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat create space request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_google_chat_http_error',
				__( 'The Google Chat API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code && 201 !== $code ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Google Chat API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Google Chat create space request was not successful.',
				array(
					'http_code'    => $code,
					'display_name' => $display_name,
					'error'        => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_google_chat_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Sanitize an OAuth access token.
	 *
	 * @param string $token Raw token value.
	 * @return string
	 */
	protected function sanitize_token( $token ) {
		if ( ! is_string( $token ) && ! is_numeric( $token ) ) {
			return '';
		}

		$token = trim( (string) $token );

		if ( '' === $token ) {
			return '';
		}

		return $token;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Creates Google Chat spaces.
			'external-api',         // Calls Google Chat API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
