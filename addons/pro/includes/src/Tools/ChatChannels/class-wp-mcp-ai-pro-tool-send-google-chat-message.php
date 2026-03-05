<?php
/**
 * Tool that sends a Google Chat message.
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
 * Provides a tool for sending Google Chat messages via the Google Chat API.
 */
class WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'send_google_chat_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Google Chat Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Google Chat API scope for bot operations.
	 */
	const CHAT_BOT_SCOPE = 'https://www.googleapis.com/auth/chat.bot';

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message to a Google Chat space using the Google Chat API v1.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Google Chat incoming webhook URL pattern.
	 */
	const WEBHOOK_URL_PATTERN = '#^https://chat\.googleapis\.com/v1/spaces/[a-zA-Z0-9_-]+/messages\?#';

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'webhook_url'         => array(
					'type'        => 'string',
					'description' => __( 'Google Chat incoming webhook URL (from Spaces app settings, e.g. https://chat.googleapis.com/v1/spaces/…/messages?key=…&token=…). When provided, messages are posted directly via the webhook without requiring OAuth credentials or a space name.', 'mcp-ai-wpoos-pro' ),
				),
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
					'description' => __( 'Google Chat space name (e.g., spaces/AAAAxxxxxx). Required when not using webhook_url.', 'mcp-ai-wpoos-pro' ),
				),
				'text'         => array(
					'type'        => 'string',
					'description' => __( 'Text content of the message to be sent.', 'mcp-ai-wpoos-pro' ),
				),
				'thread_key'   => array(
					'type'        => 'string',
					'description' => __( 'Optional thread key to reply in an existing thread or start a new named thread within the space.', 'mcp-ai-wpoos-pro' ),
				),
				'thread_name'  => array(
					'type'        => 'string',
					'description' => __( 'Optional thread resource name (e.g., spaces/SPACE_ID/threads/THREAD_ID) to reply in an existing thread.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'text' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_google_chat_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Google Chat messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Incoming webhook path: post directly to the webhook URL without OAuth.
		$webhook_url = isset( $arguments['webhook_url'] ) ? esc_url_raw( trim( $arguments['webhook_url'] ) ) : '';

		if ( '' !== $webhook_url ) {
			return $this->send_via_webhook( $webhook_url, $arguments, $context );
		}

		$access_token = $this->resolve_access_token( $arguments, $context );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_access_token', __( 'A valid OAuth 2.0 access token, Service Account JSON key, or webhook_url is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$space = isset( $arguments['space'] ) ? sanitize_text_field( $arguments['space'] ) : '';

		if ( '' === $space ) {
			return new WP_Error( 'wp_mcp_ai_missing_space', __( 'A space name is required when not using webhook_url.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! preg_match( '/^spaces\/[a-zA-Z0-9_-]+$/', $space ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_space', __( 'Invalid space format. Expected format: spaces/SPACE_ID', 'mcp-ai-wpoos-pro' ) );
		}

		$text = isset( $arguments['text'] ) ? $this->sanitize_message_text( $arguments['text'] ) : '';

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_text', __( 'Message text must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$endpoint = 'https://chat.googleapis.com/v1/' . $space . '/messages';

		$payload = array(
			'text' => $text,
		);

		// Support threaded messages within spaces.
		$thread_key  = isset( $arguments['thread_key'] ) ? sanitize_text_field( $arguments['thread_key'] ) : '';
		$thread_name = isset( $arguments['thread_name'] ) ? sanitize_text_field( $arguments['thread_name'] ) : '';

		if ( '' !== $thread_name && preg_match( '/^spaces\/[a-zA-Z0-9_-]+\/threads\/[a-zA-Z0-9_-]+$/', $thread_name ) ) {
			$payload['thread'] = array( 'name' => $thread_name );
			$endpoint          = add_query_arg( 'messageReplyOption', 'REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD', $endpoint );
		} elseif ( '' !== $thread_key ) {
			$payload['thread'] = array( 'threadKey' => $thread_key );
			$endpoint          = add_query_arg( 'messageReplyOption', 'REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD', $endpoint );
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Google Chat request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'google_chat_send_message_request',
			'Sending Google Chat message request.',
			array(
				'endpoint' => $endpoint,
				'space'    => $space,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_google_chat_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat message request failed.', array( 'error' => $response->get_error_message() ) );

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
				'Google Chat message request was not successful.',
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
			$timeout = (int) apply_filters( 'wp_mcp_ai_send_google_chat_message_token_timeout', 15, $context, $arguments );
			return WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key( $service_account_key, self::CHAT_BOT_SCOPE, $timeout );
		}

		return isset( $arguments['access_token'] ) ? $this->sanitize_token( $arguments['access_token'] ) : '';
	}

	/**
	 * Send a message via a Google Chat incoming webhook URL.
	 *
	 * Incoming webhooks (created in Spaces app settings) embed the key and token
	 * directly in the URL — no OAuth Bearer token is needed. A plain POST with a
	 * JSON body is all that is required.
	 *
	 * @param string $webhook_url Incoming webhook URL.
	 * @param array  $arguments   Tool arguments.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Decoded response body or error.
	 */
	protected function send_via_webhook( $webhook_url, array $arguments, array $context ) {
		if ( ! preg_match( self::WEBHOOK_URL_PATTERN, $webhook_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_webhook_url',
				__( 'Invalid Google Chat webhook URL. Expected format: https://chat.googleapis.com/v1/spaces/SPACE_ID/messages?key=…&token=…', 'mcp-ai-wpoos-pro' )
			);
		}

		$text = isset( $arguments['text'] ) ? $this->sanitize_message_text( $arguments['text'] ) : '';

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_text', __( 'Message text must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$payload = array( 'text' => $text );
		$body    = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Google Chat request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'google_chat_send_webhook_message_request',
			'Sending Google Chat message via incoming webhook.',
			array( 'webhook_url' => preg_replace( '/([?&])(key|token)=[^&]*/', '$1$2=REDACTED', $webhook_url ) )
		);

		$response = wp_remote_post(
			$webhook_url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => apply_filters( 'wp_mcp_ai_send_google_chat_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook message request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_google_chat_http_error',
				__( 'The Google Chat webhook request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code          = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded       = json_decode( $response_body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Google Chat webhook returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Google Chat webhook message request was not successful.',
				array(
					'http_code' => $code,
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
	 * Sanitize Google Chat message text.
	 *
	 * @param string $text Raw text input.
	 * @return string
	 */
	protected function sanitize_message_text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		return $text;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends Google Chat messages.
			'external-api',         // Calls Google Chat API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
