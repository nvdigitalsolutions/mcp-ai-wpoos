<?php
/**
 * Tool for sending Apple Messages for Business (iMessage) group messages.
 *
 * Apple Messages for Business supports multi-participant (group) conversations.
 * This tool creates or sends messages to a group conversation through an approved
 * Messaging Service Provider (MSP). Group conversations can include multiple
 * business agents and one or more customers.
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
 * Provides a tool for sending Apple Messages for Business group messages through an MSP.
 */
class WP_MCP_AI_Pro_Tool_Send_Apple_Message_Group implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for MSP API requests (seconds).
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Maximum allowed message body length (characters).
	 */
	const MAX_MESSAGE_LENGTH = 2000;

	/**
	 * Maximum number of participants in a group conversation.
	 */
	const MAX_PARTICIPANTS = 32;

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
		return 'send_apple_message_group';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Apple Group Message (iMessage)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a message to an Apple Messages for Business group conversation or creates a new group conversation with specified participants through an approved MSP.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'msp_api_url'      => array(
					'type'        => 'string',
					'description' => __( 'Base URL of your Messaging Service Provider REST API endpoint for group messaging.', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'          => array(
					'type'        => 'string',
					'description' => __( 'API key or bearer token issued by your MSP.', 'mcp-ai-wpoos-pro' ),
				),
				'business_id'      => array(
					'type'        => 'string',
					'description' => __( 'Your Apple Messages for Business ID issued during Apple registration.', 'mcp-ai-wpoos-pro' ),
				),
				'group_id'         => array(
					'type'        => 'string',
					'description' => __( 'Existing group conversation ID. Provide to send a message to an existing group. Omit to create a new group conversation.', 'mcp-ai-wpoos-pro' ),
				),
				'group_name'       => array(
					'type'        => 'string',
					'description' => __( 'Display name for the group conversation (required when creating a new group, optional when updating).', 'mcp-ai-wpoos-pro' ),
				),
				'participants'     => array(
					'type'        => 'array',
					'description' => __( 'Array of participant identifiers to add when creating or updating a group (opaque Apple customer IDs or agent IDs, max 32 total).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
					'maxItems'    => 32,
				),
				'message'          => array(
					'type'        => 'string',
					'description' => __( 'Plain-text message body to send to the group (max 2000 characters).', 'mcp-ai-wpoos-pro' ),
				),
				'sender_name'      => array(
					'type'        => 'string',
					'description' => __( 'Optional display name of the agent or system sending the message (shown in the group conversation).', 'mcp-ai-wpoos-pro' ),
				),
				'locale'           => array(
					'type'        => 'string',
					'description' => __( 'BCP 47 locale code for the message (e.g. en-US).', 'mcp-ai-wpoos-pro' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_apple_message_group_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Apple Group Messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize required parameters.
		$msp_api_url = isset( $arguments['msp_api_url'] ) ? esc_url_raw( trim( $arguments['msp_api_url'] ) ) : '';
		if ( '' === $msp_api_url ) {
			return new WP_Error( 'wp_mcp_ai_missing_apple_msp_url', __( 'A valid MSP API URL is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! filter_var( $msp_api_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $msp_api_url, 'https://' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_apple_msp_url', __( 'The MSP API URL must be a valid HTTPS URL.', 'mcp-ai-wpoos-pro' ) );
		}

		$api_key = isset( $arguments['api_key'] ) ? $this->sanitize_api_key( $arguments['api_key'] ) : '';
		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_apple_api_key', __( 'A valid MSP API key is required.', 'mcp-ai-wpoos-pro' ) );
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

		// Build payload.
		$payload = array(
			'businessId' => $business_id,
			'type'       => 'group',
			'body'       => array(
				'text' => $message,
			),
			'locale'     => isset( $arguments['locale'] ) && is_string( $arguments['locale'] ) ? sanitize_text_field( $arguments['locale'] ) : 'en-US',
		);

		// Add group ID if updating an existing group.
		if ( ! empty( $arguments['group_id'] ) && is_string( $arguments['group_id'] ) ) {
			$payload['groupId'] = sanitize_text_field( $arguments['group_id'] );
		}

		// Add group name if creating or updating.
		if ( ! empty( $arguments['group_name'] ) && is_string( $arguments['group_name'] ) ) {
			$payload['groupName'] = sanitize_text_field( $arguments['group_name'] );
		}

		// Validate and add participants.
		if ( ! empty( $arguments['participants'] ) && is_array( $arguments['participants'] ) ) {
			$sanitized_participants = $this->sanitize_participants( $arguments['participants'] );

			if ( is_wp_error( $sanitized_participants ) ) {
				return $sanitized_participants;
			}

			$payload['participants'] = $sanitized_participants;
		}

		// Add optional sender name for group attribution.
		if ( ! empty( $arguments['sender_name'] ) && is_string( $arguments['sender_name'] ) ) {
			$payload['senderName'] = sanitize_text_field( $arguments['sender_name'] );
		}

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Apple Messages group request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'apple_group_message_send_request',
			'Sending Apple Messages for Business group message.',
			array(
				'msp_api_url'    => $msp_api_url,
				'business_id'    => $this->mask_sensitive_value( $business_id ),
				'group_id'       => isset( $arguments['group_id'] ) ? $this->mask_sensitive_value( $arguments['group_id'] ) : '',
				'message_length' => mb_strlen( $message ),
				'participants'   => isset( $payload['participants'] ) ? count( $payload['participants'] ) : 0,
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
				'timeout' => apply_filters( 'wp_mcp_ai_send_apple_message_group_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Apple Messages group request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_apple_http_error',
				__( 'The Apple Messages for Business group API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

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
				'Apple Messages for Business group request was not successful.',
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
			'apple_group_message_sent',
			'Apple Messages for Business group message sent successfully.',
			array(
				'http_code' => $code,
			)
		);

		return $decoded;
	}

	/**
	 * Sanitize the participants array.
	 *
	 * @param array $participants Raw participants array.
	 * @return array|WP_Error Sanitized participants or error.
	 */
	protected function sanitize_participants( $participants ) {
		if ( count( $participants ) > self::MAX_PARTICIPANTS ) {
			return new WP_Error(
				'wp_mcp_ai_too_many_participants',
				/* translators: %d: maximum participant count */
				sprintf( __( 'Group conversations support a maximum of %d participants.', 'mcp-ai-wpoos-pro' ), self::MAX_PARTICIPANTS )
			);
		}

		$sanitized = array();

		foreach ( $participants as $participant ) {
			if ( ! is_string( $participant ) && ! is_numeric( $participant ) ) {
				continue;
			}

			$clean = sanitize_text_field( trim( (string) $participant ) );
			if ( '' !== $clean ) {
				$sanitized[] = $clean;
			}
		}

		return $sanitized;
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
