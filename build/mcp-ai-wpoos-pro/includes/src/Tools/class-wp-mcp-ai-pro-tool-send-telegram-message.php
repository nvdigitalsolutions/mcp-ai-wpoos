<?php
/**
 * Tool that sends a Telegram bot message.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending Telegram bot messages via the Bot API.
 */
class WP_MCP_AI_Pro_Tool_Send_Telegram_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Telegram requests.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_telegram_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Telegram Message', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message to a Telegram chat using the Bot API.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'                    => array(
					'type'        => 'string',
					'description' => __( 'Telegram bot token used to authenticate the request.', 'wp-mcp-ai' ),
				),
				'chat_id'                  => array(
					'type'        => 'string',
					'description' => __( 'Unique identifier for the target chat or username of the target channel.', 'wp-mcp-ai' ),
				),
				'text'                     => array(
					'type'        => 'string',
					'description' => __( 'Text of the message to be sent.', 'wp-mcp-ai' ),
				),
				'parse_mode'               => array(
					'type'        => 'string',
					'enum'        => array( 'Markdown', 'HTML' ),
					'description' => __( 'Optional parse mode that controls how Telegram formats entities.', 'wp-mcp-ai' ),
				),
				'disable_web_page_preview' => array(
					'type'        => 'boolean',
					'description' => __( 'Disables link previews for links in the sent message.', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'required'             => array( 'token', 'chat_id', 'text' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_telegram_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Telegram messages.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_telegram_token', __( 'A valid Telegram bot token is required.', 'wp-mcp-ai' ) );
		}

		$chat_id = isset( $arguments['chat_id'] ) ? sanitize_text_field( $arguments['chat_id'] ) : '';

		if ( '' === $chat_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_chat_id', __( 'A target chat identifier is required.', 'wp-mcp-ai' ) );
		}

		$text = isset( $arguments['text'] ) ? $this->sanitize_message_text( $arguments['text'] ) : '';

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_text', __( 'Message text must be provided.', 'wp-mcp-ai' ) );
		}

		$parse_mode = '';
		if ( isset( $arguments['parse_mode'] ) && is_string( $arguments['parse_mode'] ) ) {
			$candidate = sanitize_text_field( $arguments['parse_mode'] );

			if ( in_array( $candidate, array( 'Markdown', 'HTML' ), true ) ) {
				$parse_mode = $candidate;
			}
		}

		$disable_preview = ! empty( $arguments['disable_web_page_preview'] );

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $token ) );

		$payload = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);

		if ( '' !== $parse_mode ) {
			$payload['parse_mode'] = $parse_mode;
		}

		if ( $disable_preview ) {
			$payload['disable_web_page_preview'] = true;
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Telegram request payload.', 'wp-mcp-ai' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'telegram_send_message_request',
			'Sending Telegram sendMessage request.',
			array(
				'endpoint' => 'https://api.telegram.org/bot***/sendMessage',
				'chat_id'  => $chat_id,
				'options'  => array(
					'parse_mode'               => $parse_mode,
					'disable_web_page_preview' => $disable_preview,
				),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_telegram_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram sendMessage request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['ok'] ) ) {
			$message = isset( $decoded['description'] ) ? $decoded['description'] : __( 'Telegram API returned an error.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'Telegram sendMessage request was not successful.',
				array(
					'http_code'   => $code,
					'chat_id'     => $chat_id,
					'parse_mode'  => $parse_mode,
					'api_message' => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_telegram_api_error',
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
	 * Sanitize a Telegram bot token.
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
	 * Sanitize Telegram message text while preserving supported markup.
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

		$allowed_html = array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'class'  => true,
				'target' => true,
			),
			'b'      => array(),
			'i'      => array(),
			'em'     => array(),
			'strong' => array(),
			'u'      => array(),
			's'      => array(),
			'code'   => array(),
			'pre'    => array(),
			'span'   => array( 'class' => true ),
		);

		$sanitized = wp_kses( $text, $allowed_html );

		return trim( $sanitized );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends Telegram messages.
			'external-api',         // Calls Telegram Bot API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
