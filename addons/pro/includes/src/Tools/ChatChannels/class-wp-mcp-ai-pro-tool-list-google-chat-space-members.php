<?php
/**
 * Tool that lists members of a Google Chat space.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for listing members of a Google Chat space via the Google Chat API.
 */
class WP_MCP_AI_Pro_Tool_List_Google_Chat_Space_Members implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'list_google_chat_space_members';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Google Chat Space Members', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists the members of a Google Chat space using the Google Chat API v1.', 'mcp-ai-wpoos-pro' );
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
				'space'        => array(
					'type'        => 'string',
					'description' => __( 'Google Chat space name (e.g., spaces/AAAAxxxxxx).', 'mcp-ai-wpoos-pro' ),
				),
				'page_size'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of members to return per page (1–1000, default 100).', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
				'page_token'   => array(
					'type'        => 'string',
					'description' => __( 'Page token from a previous response to retrieve the next page of members.', 'mcp-ai-wpoos-pro' ),
				),
				'filter'       => array(
					'type'        => 'string',
					'description' => __( 'Optional filter for members (e.g., role = "ROLE_MANAGER" or member.type = "HUMAN").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'access_token', 'space' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_list_google_chat_space_members_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list Google Chat space members.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_token( $arguments['access_token'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_access_token', __( 'A valid OAuth 2.0 access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$space = isset( $arguments['space'] ) ? sanitize_text_field( $arguments['space'] ) : '';

		if ( '' === $space ) {
			return new WP_Error( 'wp_mcp_ai_missing_space', __( 'A space name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $space ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_space', __( 'Invalid space format. Expected format: spaces/SPACE_ID', 'mcp-ai-wpoos-pro' ) );
		}

		$page_size = isset( $arguments['page_size'] ) ? absint( $arguments['page_size'] ) : 100;
		$page_size = max( 1, min( 1000, $page_size ) );

		$endpoint   = 'https://chat.googleapis.com/v1/' . $space . '/members';
		$query_args = array( 'pageSize' => $page_size );

		if ( ! empty( $arguments['page_token'] ) ) {
			$query_args['pageToken'] = sanitize_text_field( $arguments['page_token'] );
		}

		if ( ! empty( $arguments['filter'] ) ) {
			$query_args['filter'] = sanitize_text_field( $arguments['filter'] );
		}

		$endpoint = add_query_arg( $query_args, $endpoint );

		WP_MCP_AI_Logger::log_event(
			'google_chat_list_space_members_request',
			'Listing Google Chat space members.',
			array(
				'endpoint'  => $endpoint,
				'space'     => $space,
				'page_size' => $page_size,
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_list_google_chat_space_members_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat list members request failed.', array( 'error' => $response->get_error_message() ) );

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

		if ( 200 !== $code ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Google Chat API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Google Chat list members request was not successful.',
				array(
					'http_code' => $code,
					'space'     => $space,
					'error'     => $message,
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
			'read-only',            // Lists space members.
			'external-api',         // Calls Google Chat API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
