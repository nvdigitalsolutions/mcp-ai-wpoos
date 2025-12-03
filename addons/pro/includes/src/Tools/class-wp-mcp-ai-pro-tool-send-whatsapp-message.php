<?php
/**
 * Tool that sends a WhatsApp message via the WhatsApp Cloud API.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Provides a tool for sending WhatsApp Cloud API messages.
 */
class WP_MCP_AI_Pro_Tool_Send_WhatsApp_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
	 * Default timeout for WhatsApp API requests.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Graph API version used for WhatsApp requests.
	 */
	const GRAPH_API_VERSION = 'v19.0';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_whatsapp_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send WhatsApp Message', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message to a WhatsApp user via the Meta Cloud API.', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token'    => array(
					'type'        => 'string',
					'description' => __( 'WhatsApp Cloud API access token used for authentication.', 'wp-mcp-ai-pro' ),
				),
				'phone_number_id' => array(
					'type'        => 'string',
					'description' => __( 'Phone number ID assigned to the WhatsApp Business account.', 'wp-mcp-ai-pro' ),
				),
				'to'              => array(
					'type'        => 'string',
					'description' => __( 'E.164 formatted phone number of the message recipient.', 'wp-mcp-ai-pro' ),
				),
				'text'            => array(
					'type'        => 'string',
					'description' => __( 'Body text to send to the recipient.', 'wp-mcp-ai-pro' ),
				),
				'preview_url'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether WhatsApp should render link previews inside the message.', 'wp-mcp-ai-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'access_token', 'phone_number_id', 'to', 'text' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_whatsapp_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send WhatsApp messages.', 'wp-mcp-ai-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai-pro' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';
		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_whatsapp_token', __( 'A valid WhatsApp access token is required.', 'wp-mcp-ai-pro' ) );
		}

		$phone_number_id = isset( $arguments['phone_number_id'] ) ? $this->sanitize_phone_number_id( $arguments['phone_number_id'] ) : '';
		if ( '' === $phone_number_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_whatsapp_phone_number_id', __( 'A valid WhatsApp phone number ID must be provided.', 'wp-mcp-ai-pro' ) );
		}

		$recipient = isset( $arguments['to'] ) ? $this->sanitize_phone_number( $arguments['to'] ) : '';
		if ( '' === $recipient ) {
			return new WP_Error( 'wp_mcp_ai_missing_whatsapp_recipient', __( 'A valid WhatsApp recipient phone number must be provided.', 'wp-mcp-ai-pro' ) );
		}

		$message = isset( $arguments['text'] ) ? $this->sanitize_message( $arguments['text'] ) : '';
		if ( '' === $message ) {
			return new WP_Error( 'wp_mcp_ai_missing_whatsapp_message', __( 'Message text must be provided.', 'wp-mcp-ai-pro' ) );
		}

		$preview_url = true;
		if ( array_key_exists( 'preview_url', $arguments ) ) {
			$preview_url = (bool) $arguments['preview_url'];
		}

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s/messages', self::GRAPH_API_VERSION, rawurlencode( $phone_number_id ) );

		$payload = array(
			'messaging_product' => 'whatsapp',
			'to'                => $recipient,
			'type'              => 'text',
			'text'              => array(
				'body'        => $message,
				'preview_url' => $preview_url,
			),
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the WhatsApp request payload.', 'wp-mcp-ai-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'whatsapp_send_message_request',
			'Sending WhatsApp Cloud API message request.',
			array(
				'endpoint'        => sprintf( 'https://graph.facebook.com/%s/***/messages', self::GRAPH_API_VERSION ),
				'phone_number_id' => $this->mask_sensitive_value( $phone_number_id ),
				'recipient'       => $this->mask_sensitive_value( $recipient ),
				'options'         => array(
					'preview_url' => (bool) $preview_url,
				),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_whatsapp_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'WhatsApp message request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_whatsapp_http_error',
				__( 'The WhatsApp API request failed to send.', 'wp-mcp-ai-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		$api_error = isset( $decoded['error'] ) ? $decoded['error'] : array();
		if ( 200 !== $code || ! empty( $api_error ) ) {
			$message_text = __( 'The WhatsApp API returned an error.', 'wp-mcp-ai-pro' );

			if ( is_array( $api_error ) && isset( $api_error['message'] ) && is_string( $api_error['message'] ) ) {
				$message_text = $api_error['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'WhatsApp message request was not successful.',
				array(
					'http_code' => $code,
					'response'  => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_whatsapp_api_error',
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
	 * Sanitise the WhatsApp access token.
	 *
	 * @param mixed $token Raw token value.
	 * @return string
	 */
	protected function sanitize_access_token( $token ) {
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
	 * Sanitise a WhatsApp phone number ID.
	 *
	 * @param mixed $phone_number_id Raw phone number ID value.
	 * @return string
	 */
	protected function sanitize_phone_number_id( $phone_number_id ) {
		if ( ! is_string( $phone_number_id ) && ! is_numeric( $phone_number_id ) ) {
			return '';
		}

		$phone_number_id = trim( (string) $phone_number_id );
		if ( '' === $phone_number_id ) {
			return '';
		}

		return preg_replace( '/[^0-9]/', '', $phone_number_id );
	}

	/**
	 * Sanitise a phone number while preserving the leading plus.
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

		$has_plus = strpos( $phone, '+' ) === 0;
		$digits   = preg_replace( '/[^0-9]/', '', $phone );

		if ( '' === $digits ) {
			return '';
		}

		return $has_plus ? '+' . $digits : $digits;
	}

	/**
	 * Sanitise message text for WhatsApp.
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
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
