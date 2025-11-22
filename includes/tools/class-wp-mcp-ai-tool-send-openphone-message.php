<?php
/**
 * Tool that sends a message via the OpenPhone (Quo) API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending OpenPhone messages via the OpenPhone API.
 */
class WP_MCP_AI_Tool_Send_OpenPhone_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for OpenPhone API requests.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * OpenPhone API base URL.
	 */
	const API_BASE_URL = 'https://api.openphone.com/v1';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_openphone_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send OpenPhone Message', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message to a phone number via the OpenPhone (Quo) API.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'api_key'   => array(
					'type'        => 'string',
					'description' => __( 'OpenPhone API key used for authentication.', 'wp-mcp-ai' ),
				),
				'from'      => array(
					'type'        => 'string',
					'description' => __( 'OpenPhone phone number to send from (E.164 format, e.g., +15555555555).', 'wp-mcp-ai' ),
				),
				'to'        => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => __( 'Array of recipient phone numbers in E.164 format (e.g., ["+15555555555"]).', 'wp-mcp-ai' ),
				),
				'content'   => array(
					'type'        => 'string',
					'description' => __( 'Message content to send.', 'wp-mcp-ai' ),
				),
				'user_id'   => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenPhone user ID to send the message as.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'api_key', 'from', 'to', 'content' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_openphone_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send OpenPhone messages.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize API key.
		$api_key = isset( $arguments['api_key'] ) ? $this->sanitize_api_key( $arguments['api_key'] ) : '';
		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_openphone_api_key', __( 'A valid OpenPhone API key is required.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize from number.
		$from = isset( $arguments['from'] ) ? $this->sanitize_phone_number( $arguments['from'] ) : '';
		if ( '' === $from ) {
			return new WP_Error( 'wp_mcp_ai_missing_openphone_from', __( 'A valid sender phone number is required.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize recipient numbers.
		$to = isset( $arguments['to'] ) && is_array( $arguments['to'] ) ? $arguments['to'] : array();
		if ( empty( $to ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_openphone_to', __( 'At least one recipient phone number is required.', 'wp-mcp-ai' ) );
		}

		$sanitized_to = array();
		foreach ( $to as $recipient ) {
			$sanitized = $this->sanitize_phone_number( $recipient );
			if ( '' !== $sanitized ) {
				$sanitized_to[] = $sanitized;
			}
		}

		if ( empty( $sanitized_to ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_openphone_recipients', __( 'No valid recipient phone numbers provided.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize message content.
		$content = isset( $arguments['content'] ) ? $this->sanitize_message( $arguments['content'] ) : '';
		if ( '' === $content ) {
			return new WP_Error( 'wp_mcp_ai_missing_openphone_content', __( 'Message content must be provided.', 'wp-mcp-ai' ) );
		}

		// Optional user ID.
		$openphone_user_id = isset( $arguments['user_id'] ) ? sanitize_text_field( $arguments['user_id'] ) : '';

		// Build the API endpoint.
		$endpoint = self::API_BASE_URL . '/messages';

		// Build the request payload.
		$payload = array(
			'from'    => $from,
			'to'      => $sanitized_to,
			'content' => $content,
		);

		if ( '' !== $openphone_user_id ) {
			$payload['userId'] = $openphone_user_id;
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the OpenPhone request payload.', 'wp-mcp-ai' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'openphone_send_message_request',
			'Sending OpenPhone message request.',
			array(
				'endpoint'   => $endpoint,
				'from'       => $this->mask_sensitive_value( $from ),
				'to_count'   => count( $sanitized_to ),
				'has_userId' => ! empty( $openphone_user_id ),
			)
		);

		// Make the API request.
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => $api_key,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_openphone_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'OpenPhone message request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_openphone_http_error',
				__( 'The OpenPhone API request failed to send.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		// OpenPhone API returns 200/201 for success.
		if ( ! in_array( $code, array( 200, 201 ), true ) ) {
			$message_text = __( 'The OpenPhone API returned an error.', 'wp-mcp-ai' );

			// Extract error message if available.
			if ( isset( $decoded['error'] ) ) {
				if ( is_string( $decoded['error'] ) ) {
					$message_text = $decoded['error'];
				} elseif ( is_array( $decoded['error'] ) && isset( $decoded['error']['message'] ) ) {
					$message_text = $decoded['error']['message'];
				}
			} elseif ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				$message_text = $decoded['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'OpenPhone message request was not successful.',
				array(
					'http_code' => $code,
					'response'  => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_openphone_api_error',
				esc_html( $message_text ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Sanitize the OpenPhone API key.
	 *
	 * @param mixed $api_key Raw API key value.
	 * @return string
	 */
	protected function sanitize_api_key( $api_key ) {
		if ( ! is_string( $api_key ) && ! is_numeric( $api_key ) ) {
			return '';
		}

		$api_key = trim( (string) $api_key );
		if ( '' === $api_key ) {
			return '';
		}

		return $api_key;
	}

	/**
	 * Sanitize a phone number in E.164 format.
	 *
	 * @param mixed $phone Raw phone value.
	 * @return string
	 */
	protected function sanitize_phone_number( $phone ) {
		if ( ! is_string( $phone ) && ! is_numeric( $phone ) ) {
			return '';
		}

		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}

		// E.164 format requires a leading plus sign.
		$has_plus = strpos( $phone, '+' ) === 0;
		$digits   = preg_replace( '/[^0-9]/', '', $phone );

		if ( '' === $digits ) {
			return '';
		}

		return $has_plus ? '+' . $digits : '+' . $digits;
	}

	/**
	 * Sanitize message content.
	 *
	 * @param mixed $text Raw text input.
	 * @return string
	 */
	protected function sanitize_message( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = sanitize_textarea_field( $text );
		return trim( $text );
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
			'external-api',         // Makes external API calls.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
			'requires-credentials', // Requires API credentials.
			'rate-limited',         // Subject to API rate limiting.
		);
	}
}
