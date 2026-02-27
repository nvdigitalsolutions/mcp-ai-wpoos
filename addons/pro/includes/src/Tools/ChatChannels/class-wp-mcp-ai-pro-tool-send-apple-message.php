<?php
/**
 * Tool for sending Apple Messages for Business (iMessage) text messages.
 *
 * Apple Messages for Business requires an approved Messaging Service Provider (MSP)
 * such as Infobip, Zendesk, Sunshine Conversations, LivePerson, or CM.com.
 * This tool communicates with your MSP's REST API to send messages.
 *
 * Industry references:
 * - https://register.apple.com/resources/messages/msp-required-capabilities.pdf
 * - https://developers.apple.com/design/human-interface-guidelines/messages-for-business
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending text messages via Apple Messages for Business through an MSP.
 */
class WP_MCP_AI_Pro_Tool_Send_Apple_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for MSP API requests (seconds).
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Maximum allowed message body length (characters).
	 * Apple Messages for Business enforces a 2000-character soft limit per message.
	 */
	const MAX_MESSAGE_LENGTH = 2000;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies required.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_apple_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Apple Message (iMessage)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message via Apple Messages for Business (iMessage) through an approved Messaging Service Provider (MSP). Supports individual and group conversations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'msp_api_url'     => array(
					'type'        => 'string',
					'description' => __( 'Base URL of your Messaging Service Provider (MSP) REST API endpoint (e.g. https://api.example-msp.com/v1/apple/messages).', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'         => array(
					'type'        => 'string',
					'description' => __( 'API key or bearer token issued by your MSP for authenticating Apple Messages for Business API requests.', 'mcp-ai-wpoos-pro' ),
				),
				'business_id'     => array(
					'type'        => 'string',
					'description' => __( 'Your Apple Messages for Business identifier (Business ID) issued during Apple registration.', 'mcp-ai-wpoos-pro' ),
				),
				'conversation_id' => array(
					'type'        => 'string',
					'description' => __( 'The unique conversation or session ID provided by Apple for the active customer conversation. Required to continue existing conversations.', 'mcp-ai-wpoos-pro' ),
				),
				'recipient_id'    => array(
					'type'        => 'string',
					'description' => __( 'The opaque customer identifier supplied by Apple when a conversation is initiated. Used for new outbound messages when no conversation_id exists yet.', 'mcp-ai-wpoos-pro' ),
				),
				'message'         => array(
					'type'        => 'string',
					'description' => __( 'Plain-text body of the message to send (max 2000 characters).', 'mcp-ai-wpoos-pro' ),
				),
				'intent'          => array(
					'type'        => 'string',
					'description' => __( 'Optional intent string passed when the user tapped a "Message Us" entry point. Used to route conversations to the right support queue.', 'mcp-ai-wpoos-pro' ),
				),
				'locale'          => array(
					'type'        => 'string',
					'description' => __( 'BCP 47 locale code for the message (e.g. en-US). Defaults to en-US when omitted.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'en-US',
				),
			),
			'required'             => array( 'msp_api_url', 'api_key', 'business_id', 'message' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_send_apple_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Apple Messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize required parameters.
		$msp_api_url = isset( $arguments['msp_api_url'] ) ? esc_url_raw( trim( $arguments['msp_api_url'] ) ) : '';
		if ( '' === $msp_api_url ) {
			return new WP_Error( 'wp_mcp_ai_missing_apple_msp_url', __( 'A valid MSP API URL is required for Apple Messages for Business.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! filter_var( $msp_api_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $msp_api_url, 'https://' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_apple_msp_url', __( 'The MSP API URL must be a valid HTTPS URL.', 'mcp-ai-wpoos-pro' ) );
		}

		$api_key = isset( $arguments['api_key'] ) ? $this->sanitize_api_key( $arguments['api_key'] ) : '';
		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_apple_api_key', __( 'A valid MSP API key is required for Apple Messages for Business.', 'mcp-ai-wpoos-pro' ) );
		}

		$business_id = isset( $arguments['business_id'] ) ? sanitize_text_field( trim( $arguments['business_id'] ) ) : '';
		if ( '' === $business_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_apple_business_id', __( 'An Apple Messages for Business ID (business_id) is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$message = isset( $arguments['message'] ) ? $this->sanitize_message( $arguments['message'] ) : '';
		if ( '' === $message ) {
			return new WP_Error( 'wp_mcp_ai_missing_apple_message', __( 'Message body is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Enforce max message length.
		if ( mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
			$message = mb_substr( $message, 0, self::MAX_MESSAGE_LENGTH );
		}

		// Build the outbound payload following Apple Messages for Business conventions.
		$payload = array(
			'businessId' => $business_id,
			'body'       => array(
				'text' => $message,
			),
			'type'       => 'text',
			'locale'     => isset( $arguments['locale'] ) && is_string( $arguments['locale'] ) ? sanitize_text_field( $arguments['locale'] ) : 'en-US',
		);

		if ( ! empty( $arguments['conversation_id'] ) && is_string( $arguments['conversation_id'] ) ) {
			$payload['conversationId'] = sanitize_text_field( $arguments['conversation_id'] );
		}

		if ( ! empty( $arguments['recipient_id'] ) && is_string( $arguments['recipient_id'] ) ) {
			$payload['recipientId'] = sanitize_text_field( $arguments['recipient_id'] );
		}

		if ( ! empty( $arguments['intent'] ) && is_string( $arguments['intent'] ) ) {
			$payload['intent'] = sanitize_text_field( $arguments['intent'] );
		}

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Apple Messages request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'apple_message_send_request',
			'Sending Apple Messages for Business text message.',
			array(
				'msp_api_url'     => $msp_api_url,
				'business_id'     => $this->mask_sensitive_value( $business_id ),
				'conversation_id' => isset( $arguments['conversation_id'] ) ? $this->mask_sensitive_value( $arguments['conversation_id'] ) : '',
				'message_length'  => mb_strlen( $message ),
			)
		);

		$response = wp_remote_post(
			$msp_api_url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_apple_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Apple Messages for Business request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_apple_http_error',
				__( 'The Apple Messages for Business API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		// Accept both 200 (OK) and 201 (Created) as success.
		if ( $code < 200 || $code >= 300 ) {
			$message_text = __( 'The Apple Messages for Business API returned an error.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) ) {
				foreach ( array( 'message', 'error', 'errorMessage', 'detail' ) as $key ) {
					if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
						$message_text = $decoded[ $key ];
						break;
					}
				}
			}

			WP_MCP_AI_Logger::log_error(
				'Apple Messages for Business send request was not successful.',
				array(
					'http_code' => $code,
					'response'  => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_apple_api_error',
				esc_html( $message_text ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'apple_message_sent',
			'Apple Messages for Business text message sent successfully.',
			array(
				'http_code' => $code,
			)
		);

		return $decoded;
	}

	/**
	 * Sanitize an API key / bearer token.
	 *
	 * @param mixed $key Raw key value.
	 * @return string
	 */
	protected function sanitize_api_key( $key ) {
		if ( ! is_string( $key ) && ! is_numeric( $key ) ) {
			return '';
		}

		return trim( (string) $key );
	}

	/**
	 * Sanitize message text.
	 *
	 * @param mixed $text Raw text input.
	 * @return string
	 */
	protected function sanitize_message( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		return trim( sanitize_textarea_field( $text ) );
	}

	/**
	 * Mask a sensitive value so it can be safely logged.
	 *
	 * @param string $value Sensitive value.
	 * @return string
	 */
	protected function mask_sensitive_value( $value ) {
		$value  = (string) $value;
		$length = strlen( $value );

		if ( 0 === $length ) {
			return '';
		}

		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return substr( $value, 0, 2 ) . str_repeat( '*', $length - 4 ) . substr( $value, -2 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends messages.
			'external-api',         // Calls MSP REST API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
