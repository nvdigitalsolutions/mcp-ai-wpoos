<?php
/**
 * Tool that retrieves Google Chat message history.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once __DIR__ . '/class-wp-mcp-ai-pro-google-service-account.php';

/**
 * Provides a tool for retrieving Google Chat message history via the Google Chat API.
 */
class WP_MCP_AI_Pro_Tool_Get_Google_Chat_Messages implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'get_google_chat_messages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Google Chat Messages', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Google Chat API scope for bot operations.
	 */
	const CHAT_BOT_SCOPE = 'https://www.googleapis.com/auth/chat.bot';

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves message history from a Google Chat space using the Google Chat API v1.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'service_account_key' => array(
					'type'        => 'string',
					'description' => __( 'Google Service Account JSON key (contents of the downloaded .json key file). Used to generate an OAuth 2.0 access token automatically.', 'mcp-ai-wpoos-pro' ),
				),
				'access_token' => array(
					'type'        => 'string',
					'description' => __( 'OAuth 2.0 access token for authentication. Use service_account_key instead for automatic token management.', 'mcp-ai-wpoos-pro' ),
				),
				'space'        => array(
					'type'        => 'string',
					'description' => __( 'Google Chat space name (e.g., spaces/AAAAxxxxxx).', 'mcp-ai-wpoos-pro' ),
				),
				'page_size'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of messages to retrieve (default: 50).', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page_token'   => array(
					'type'        => 'string',
					'description' => __( 'Page token from a previous response to retrieve the next page of messages.', 'mcp-ai-wpoos-pro' ),
				),
				'order_by'     => array(
					'type'        => 'string',
					'description' => __( 'Sort order for messages. Use "createTime asc" or "createTime desc" (default: createTime asc).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'createTime asc', 'createTime desc' ),
					'default'     => 'createTime asc',
				),
				'filter'       => array(
					'type'        => 'string',
					'description' => __( 'Optional filter for messages (e.g., createTime > "2023-01-01T00:00:00Z" or thread.name = "spaces/SPACE/threads/THREAD").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'space' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_google_chat_messages_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve Google Chat messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$access_token = $this->resolve_access_token( $arguments, $context );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_access_token', __( 'A valid OAuth 2.0 access token or Service Account JSON key is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$space = isset( $arguments['space'] ) ? sanitize_text_field( $arguments['space'] ) : '';

		if ( '' === $space ) {
			return new WP_Error( 'wp_mcp_ai_missing_space', __( 'A space name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $space ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_space', __( 'Invalid space format. Expected format: spaces/SPACE_ID', 'mcp-ai-wpoos-pro' ) );
		}

		$page_size = isset( $arguments['page_size'] ) ? absint( $arguments['page_size'] ) : 50;
		$page_size = max( 1, min( 100, $page_size ) );

		$endpoint = 'https://chat.googleapis.com/v1/' . $space . '/messages';

		$query_args = array( 'pageSize' => $page_size );

		if ( ! empty( $arguments['page_token'] ) ) {
			$query_args['pageToken'] = sanitize_text_field( $arguments['page_token'] );
		}

		$allowed_order = array( 'createTime asc', 'createTime desc' );
		$order_by      = isset( $arguments['order_by'] ) ? sanitize_text_field( $arguments['order_by'] ) : 'createTime asc';
		if ( in_array( $order_by, $allowed_order, true ) ) {
			$query_args['orderBy'] = $order_by;
		}

		if ( ! empty( $arguments['filter'] ) ) {
			$query_args['filter'] = sanitize_text_field( $arguments['filter'] );
		}

		$endpoint = add_query_arg( $query_args, $endpoint );

		WP_MCP_AI_Logger::log_event(
			'google_chat_get_messages_request',
			'Retrieving Google Chat messages.',
			array(
				'endpoint'  => $endpoint,
				'space'     => $space,
				'page_size' => $page_size,
				'order_by'  => isset( $query_args['orderBy'] ) ? $query_args['orderBy'] : 'createTime asc',
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_google_chat_messages_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat messages request failed.', array( 'error' => $response->get_error_message() ) );

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
				'Google Chat messages request was not successful.',
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
	 * Resolve an OAuth 2.0 access token from arguments.
	 *
	 * Prefers service_account_key (automatic token exchange) over a raw access_token.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return string|WP_Error Access token string or error.
	 */
	protected function resolve_access_token( array $arguments, array $context ) {
		$service_account_key = isset( $arguments['service_account_key'] ) ? trim( (string) $arguments['service_account_key'] ) : '';

		if ( '' !== $service_account_key ) {
			$timeout = (int) apply_filters( 'wp_mcp_ai_get_google_chat_messages_token_timeout', 15, $context, $arguments );
			return WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key( $service_account_key, self::CHAT_BOT_SCOPE, $timeout );
		}

		return isset( $arguments['access_token'] ) ? $this->sanitize_token( $arguments['access_token'] ) : '';
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
			'read-only',            // Retrieves Google Chat messages.
			'external-api',         // Calls Google Chat API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
