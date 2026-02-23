<?php
/**
 * Tool for sending WhatsApp media messages.
 *
 * Supports images, videos, audio, documents, and stickers with
 * proper validation and WhatsApp Cloud API best practices.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending WhatsApp media messages.
 */
class WP_MCP_AI_Pro_Tool_Send_WhatsApp_Media implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for WhatsApp API requests.
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Graph API version used for WhatsApp requests.
	 */
	const GRAPH_API_VERSION = 'v19.0';

	/**
	 * Supported media types and their limits.
	 */
	const MEDIA_TYPES = array(
		'image'    => array(
			'formats'  => array( 'jpeg', 'jpg', 'png' ),
			'max_size' => 5242880, // 5MB
			'caption'  => true,
		),
		'video'    => array(
			'formats'  => array( 'mp4', '3gp' ),
			'max_size' => 16777216, // 16MB
			'caption'  => true,
		),
		'audio'    => array(
			'formats'  => array( 'aac', 'mp4', 'mpeg', 'amr', 'ogg' ),
			'max_size' => 16777216, // 16MB
			'caption'  => false,
		),
		'document' => array(
			'formats'  => array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip' ),
			'max_size' => 104857600, // 100MB
			'caption'  => true,
		),
		'sticker'  => array(
			'formats'  => array( 'webp' ),
			'max_size' => 524288, // 500KB
			'caption'  => false,
		),
	);

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
		return 'send_whatsapp_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send WhatsApp Media', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a media message (image, video, audio, document, or sticker) via WhatsApp Cloud API.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'WhatsApp Cloud API access token used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'phone_number_id' => array(
					'type'        => 'string',
					'description' => __( 'Phone number ID assigned to the WhatsApp Business account.', 'mcp-ai-wpoos-pro' ),
				),
				'to'              => array(
					'type'        => 'string',
					'description' => __( 'Recipient: E.164 phone number for individual messages (e.g. +1234567890) or a group ID for group messages (e.g. 120363…@g.us).', 'mcp-ai-wpoos-pro' ),
				),
				'recipient_type'  => array(
					'type'        => 'string',
					'description' => __( 'Recipient type: "individual" (default) to send to a phone number, or "group" to send to a WhatsApp group.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'individual', 'group' ),
					'default'     => 'individual',
				),
				'type'            => array(
					'type'        => 'string',
					'description' => __( 'Media type: image, video, audio, document, or sticker.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'image', 'video', 'audio', 'document', 'sticker' ),
				),
				'media_url'       => array(
					'type'        => 'string',
					'description' => __( 'Public URL of the media file (https only).', 'mcp-ai-wpoos-pro' ),
				),
				'media_id'        => array(
					'type'        => 'string',
					'description' => __( 'Alternative: Media ID from previous upload to WhatsApp.', 'mcp-ai-wpoos-pro' ),
				),
				'caption'         => array(
					'type'        => 'string',
					'description' => __( 'Optional caption for image, video, or document (max 1024 chars).', 'mcp-ai-wpoos-pro' ),
				),
				'filename'        => array(
					'type'        => 'string',
					'description' => __( 'Optional filename for document type.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'access_token', 'phone_number_id', 'to', 'type' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_whatsapp_media_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send WhatsApp media.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required parameters.
		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_access_token( $arguments['access_token'] ) : '';
		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_whatsapp_token', __( 'A valid WhatsApp access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$phone_number_id = isset( $arguments['phone_number_id'] ) ? $this->sanitize_phone_number_id( $arguments['phone_number_id'] ) : '';
		if ( '' === $phone_number_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_whatsapp_phone_number_id', __( 'A valid WhatsApp phone number ID must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$recipient_type = isset( $arguments['recipient_type'] ) && 'group' === $arguments['recipient_type'] ? 'group' : 'individual';

		$recipient = isset( $arguments['to'] )
			? ( 'group' === $recipient_type ? $this->sanitize_group_or_phone( $arguments['to'] ) : $this->sanitize_phone_number( $arguments['to'] ) )
			: '';
		if ( '' === $recipient ) {
			return new WP_Error( 'wp_mcp_ai_missing_whatsapp_recipient', __( 'A valid WhatsApp recipient phone number must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$type = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';
		if ( ! array_key_exists( $type, self::MEDIA_TYPES ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_media_type', __( 'Invalid media type. Must be: image, video, audio, document, or sticker.', 'mcp-ai-wpoos-pro' ) );
		}

		// Either media_url or media_id must be provided.
		$media_url = isset( $arguments['media_url'] ) ? esc_url_raw( $arguments['media_url'] ) : '';
		$media_id  = isset( $arguments['media_id'] ) ? sanitize_text_field( $arguments['media_id'] ) : '';

		if ( empty( $media_url ) && empty( $media_id ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_media', __( 'Either media_url or media_id must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build media object.
		$media = array();

		if ( ! empty( $media_id ) ) {
			$media['id'] = $media_id;
		} else {
			$media['link'] = $media_url;
		}

		// Add caption if supported and provided.
		if ( self::MEDIA_TYPES[ $type ]['caption'] && isset( $arguments['caption'] ) && ! empty( $arguments['caption'] ) ) {
			$media['caption'] = sanitize_textarea_field( substr( $arguments['caption'], 0, 1024 ) );
		}

		// Add filename for documents.
		if ( 'document' === $type && isset( $arguments['filename'] ) && ! empty( $arguments['filename'] ) ) {
			$media['filename'] = sanitize_file_name( $arguments['filename'] );
		}

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s/messages', self::GRAPH_API_VERSION, rawurlencode( $phone_number_id ) );

		$payload = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => $recipient_type,
			'to'                => $recipient,
			'type'              => $type,
			$type               => $media,
		);

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the WhatsApp request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'whatsapp_send_media_request',
			'Sending WhatsApp media message request.',
			array(
				'endpoint'        => sprintf( 'https://graph.facebook.com/%s/***/messages', self::GRAPH_API_VERSION ),
				'phone_number_id' => $this->mask_sensitive_value( $phone_number_id ),
				'recipient'       => $this->mask_sensitive_value( $recipient ),
				'type'            => $type,
				'has_caption'     => isset( $media['caption'] ),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_whatsapp_media_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'WhatsApp media message request failed to send.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_whatsapp_http_error',
				__( 'The WhatsApp API request failed to send.', 'mcp-ai-wpoos-pro' ),
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
			$message_text = __( 'The WhatsApp API returned an error.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $api_error ) && isset( $api_error['message'] ) && is_string( $api_error['message'] ) ) {
				$message_text = $api_error['message'];
			}

			WP_MCP_AI_Logger::log_error(
				'WhatsApp media message request was not successful.',
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
	 * Sanitize the WhatsApp access token.
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
	 * Sanitize a WhatsApp phone number ID.
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
	 * Sanitize a phone number while preserving the leading plus.
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
	 * Sanitize a recipient that may be a phone number or a WhatsApp group JID.
	 *
	 * Group JIDs have the form "{numeric_id}@g.us". Phone numbers are E.164.
	 *
	 * @param mixed $value Raw recipient value.
	 * @return string Sanitized recipient or empty string.
	 */
	protected function sanitize_group_or_phone( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		// Allow group JIDs: digits, letters, hyphens, dots, underscores, @, and +.
		return preg_replace( '/[^0-9a-zA-Z@._\-+]/', '', $value );
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
			'write',                // Sends WhatsApp messages.
			'external-api',         // Calls WhatsApp Cloud API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
