<?php
/**
 * Tool for sending WhatsApp interactive messages.
 *
 * Supports reply buttons, list messages, and CTA buttons following
 * WhatsApp Cloud API best practices for interactive messages.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending WhatsApp interactive messages.
 */
class WP_MCP_AI_Pro_Tool_Send_WhatsApp_Interactive implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for WhatsApp API requests.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Graph API version used for WhatsApp requests.
	 */
	const GRAPH_API_VERSION = 'v19.0';

	/**
	 * Maximum buttons allowed in reply button messages.
	 */
	const MAX_REPLY_BUTTONS = 3;

	/**
	 * Maximum button title length.
	 */
	const MAX_BUTTON_TITLE_LENGTH = 20;

	/**
	 * Maximum list sections.
	 */
	const MAX_LIST_SECTIONS = 10;

	/**
	 * Maximum rows per section.
	 */
	const MAX_ROWS_PER_SECTION = 10;

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
		return 'send_whatsapp_interactive';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send WhatsApp Interactive Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends an interactive WhatsApp message with reply buttons or list options via the Meta Cloud API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token'      => array(
					'type'        => 'string',
					'description' => __( 'WhatsApp Cloud API access token used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'phone_number_id'   => array(
					'type'        => 'string',
					'description' => __( 'Phone number ID assigned to the WhatsApp Business account.', 'mcp-ai-wpoos-pro' ),
				),
				'to'                => array(
					'type'        => 'string',
					'description' => __( 'Recipient: E.164 phone number for individual messages (e.g. +1234567890) or a group ID for group messages (e.g. 120363…@g.us).', 'mcp-ai-wpoos-pro' ),
				),
				'recipient_type'    => array(
					'type'        => 'string',
					'description' => __( 'Recipient type: "individual" (default) to send to a phone number, or "group" to send to a WhatsApp group.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'individual', 'group' ),
					'default'     => 'individual',
				),
				'type'              => array(
					'type'        => 'string',
					'description' => __( 'Interactive message type: "button" for reply buttons or "list" for list messages.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'button', 'list' ),
				),
				'header'            => array(
					'type'        => 'object',
					'description' => __( 'Optional header (text or image).', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'type' => array(
							'type' => 'string',
							'enum' => array( 'text', 'image', 'video', 'document' ),
						),
						'text' => array(
							'type'        => 'string',
							'description' => __( 'Header text (max 60 characters).', 'mcp-ai-wpoos-pro' ),
						),
						'image' => array(
							'type'        => 'object',
							'description' => __( 'Image object with link or id.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'body'              => array(
					'type'        => 'string',
					'description' => __( 'Body text (required, max 1024 characters).', 'mcp-ai-wpoos-pro' ),
				),
				'footer'            => array(
					'type'        => 'string',
					'description' => __( 'Optional footer text (max 60 characters).', 'mcp-ai-wpoos-pro' ),
				),
				'buttons'           => array(
					'type'        => 'array',
					'description' => __( 'Reply buttons (max 3 for button type). Each button requires id and title.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type'  => array(
								'type'    => 'string',
								'default' => 'reply',
							),
							'reply' => array(
								'type'       => 'object',
								'properties' => array(
									'id'    => array(
										'type'        => 'string',
										'description' => __( 'Unique button identifier.', 'mcp-ai-wpoos-pro' ),
									),
									'title' => array(
										'type'        => 'string',
										'description' => __( 'Button text (max 20 characters).', 'mcp-ai-wpoos-pro' ),
									),
								),
							),
						),
					),
				),
				'button_text'       => array(
					'type'        => 'string',
					'description' => __( 'Button text for list messages (required for list type).', 'mcp-ai-wpoos-pro' ),
				),
				'sections'          => array(
					'type'        => 'array',
					'description' => __( 'List sections (required for list type, max 10 sections).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title' => array(
								'type'        => 'string',
								'description' => __( 'Section title (optional).', 'mcp-ai-wpoos-pro' ),
							),
							'rows'  => array(
								'type'        => 'array',
								'description' => __( 'List rows (max 10 per section).', 'mcp-ai-wpoos-pro' ),
								'items'       => array(
									'type'       => 'object',
									'properties' => array(
										'id'          => array(
											'type'        => 'string',
											'description' => __( 'Unique row identifier.', 'mcp-ai-wpoos-pro' ),
										),
										'title'       => array(
											'type'        => 'string',
											'description' => __( 'Row title (max 24 characters).', 'mcp-ai-wpoos-pro' ),
										),
										'description' => array(
											'type'        => 'string',
											'description' => __( 'Row description (optional, max 72 characters).', 'mcp-ai-wpoos-pro' ),
										),
									),
								),
							),
						),
					),
				),
			),
			'required'             => array( 'access_token', 'phone_number_id', 'to', 'type', 'body' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_whatsapp_interactive_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send WhatsApp interactive messages.', 'mcp-ai-wpoos-pro' ) );
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
		if ( ! in_array( $type, array( 'button', 'list' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_interactive_type', __( 'Interactive message type must be "button" or "list".', 'mcp-ai-wpoos-pro' ) );
		}

		$body = isset( $arguments['body'] ) ? $this->sanitize_message( $arguments['body'] ) : '';
		if ( '' === $body ) {
			return new WP_Error( 'wp_mcp_ai_missing_body', __( 'Message body is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build interactive message payload.
		$interactive = $this->build_interactive_payload( $type, $arguments );

		if ( is_wp_error( $interactive ) ) {
			return $interactive;
		}

		$endpoint = sprintf( 'https://graph.facebook.com/%s/%s/messages', self::GRAPH_API_VERSION, rawurlencode( $phone_number_id ) );

		$payload = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => $recipient_type,
			'to'                => $recipient,
			'type'              => 'interactive',
			'interactive'       => $interactive,
		);

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the WhatsApp request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'whatsapp_send_interactive_request',
			'Sending WhatsApp interactive message request.',
			array(
				'endpoint'        => sprintf( 'https://graph.facebook.com/%s/***/messages', self::GRAPH_API_VERSION ),
				'phone_number_id' => $this->mask_sensitive_value( $phone_number_id ),
				'recipient'       => $this->mask_sensitive_value( $recipient ),
				'type'            => $type,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_whatsapp_interactive_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'WhatsApp interactive message request failed to send.', array( 'error' => $response->get_error_message() ) );

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
				'WhatsApp interactive message request was not successful.',
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
	 * Build interactive message payload.
	 *
	 * @param string $type Interactive message type.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error Interactive payload or error.
	 */
	protected function build_interactive_payload( $type, $arguments ) {
		$interactive = array(
			'type' => $type,
		);

		// Add header if provided.
		if ( isset( $arguments['header'] ) && is_array( $arguments['header'] ) ) {
			$interactive['header'] = $this->sanitize_header( $arguments['header'] );
		}

		// Add body (required).
		$interactive['body'] = array(
			'text' => isset( $arguments['body'] ) ? $this->sanitize_message( $arguments['body'] ) : '',
		);

		// Add footer if provided.
		if ( isset( $arguments['footer'] ) && ! empty( $arguments['footer'] ) ) {
			$interactive['footer'] = array(
				'text' => sanitize_text_field( substr( $arguments['footer'], 0, 60 ) ),
			);
		}

		// Build action based on type.
		if ( 'button' === $type ) {
			$action = $this->build_button_action( $arguments );
		} else {
			$action = $this->build_list_action( $arguments );
		}

		if ( is_wp_error( $action ) ) {
			return $action;
		}

		$interactive['action'] = $action;

		return $interactive;
	}

	/**
	 * Build button action for reply buttons.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Button action or error.
	 */
	protected function build_button_action( $arguments ) {
		if ( ! isset( $arguments['buttons'] ) || ! is_array( $arguments['buttons'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_buttons', __( 'Buttons array is required for button type.', 'mcp-ai-wpoos-pro' ) );
		}

		$buttons = $arguments['buttons'];

		if ( count( $buttons ) > self::MAX_REPLY_BUTTONS ) {
			return new WP_Error( 'wp_mcp_ai_too_many_buttons', sprintf( __( 'Maximum %d buttons allowed.', 'mcp-ai-wpoos-pro' ), self::MAX_REPLY_BUTTONS ) );
		}

		if ( empty( $buttons ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_buttons', __( 'At least one button is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$sanitized_buttons = array();

		foreach ( $buttons as $button ) {
			if ( ! isset( $button['reply'] ) || ! is_array( $button['reply'] ) ) {
				continue;
			}

			$reply = $button['reply'];

			if ( ! isset( $reply['id'] ) || ! isset( $reply['title'] ) ) {
				continue;
			}

			$title = sanitize_text_field( $reply['title'] );

			// Enforce max length.
			if ( strlen( $title ) > self::MAX_BUTTON_TITLE_LENGTH ) {
				$title = substr( $title, 0, self::MAX_BUTTON_TITLE_LENGTH );
			}

			$sanitized_buttons[] = array(
				'type'  => 'reply',
				'reply' => array(
					'id'    => sanitize_text_field( $reply['id'] ),
					'title' => $title,
				),
			);
		}

		if ( empty( $sanitized_buttons ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_buttons', __( 'No valid buttons provided.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'buttons' => $sanitized_buttons,
		);
	}

	/**
	 * Build list action for list messages.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error List action or error.
	 */
	protected function build_list_action( $arguments ) {
		if ( ! isset( $arguments['button_text'] ) || empty( $arguments['button_text'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_button_text', __( 'Button text is required for list type.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! isset( $arguments['sections'] ) || ! is_array( $arguments['sections'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_sections', __( 'Sections array is required for list type.', 'mcp-ai-wpoos-pro' ) );
		}

		$sections = $arguments['sections'];

		if ( count( $sections ) > self::MAX_LIST_SECTIONS ) {
			return new WP_Error( 'wp_mcp_ai_too_many_sections', sprintf( __( 'Maximum %d sections allowed.', 'mcp-ai-wpoos-pro' ), self::MAX_LIST_SECTIONS ) );
		}

		if ( empty( $sections ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_sections', __( 'At least one section is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$sanitized_sections = array();

		foreach ( $sections as $section ) {
			if ( ! isset( $section['rows'] ) || ! is_array( $section['rows'] ) || empty( $section['rows'] ) ) {
				continue;
			}

			$rows = array_slice( $section['rows'], 0, self::MAX_ROWS_PER_SECTION );
			$sanitized_rows = array();

			foreach ( $rows as $row ) {
				if ( ! isset( $row['id'] ) || ! isset( $row['title'] ) ) {
					continue;
				}

				$sanitized_row = array(
					'id'    => sanitize_text_field( $row['id'] ),
					'title' => sanitize_text_field( substr( $row['title'], 0, 24 ) ),
				);

				if ( isset( $row['description'] ) && ! empty( $row['description'] ) ) {
					$sanitized_row['description'] = sanitize_text_field( substr( $row['description'], 0, 72 ) );
				}

				$sanitized_rows[] = $sanitized_row;
			}

			if ( empty( $sanitized_rows ) ) {
				continue;
			}

			$sanitized_section = array(
				'rows' => $sanitized_rows,
			);

			if ( isset( $section['title'] ) && ! empty( $section['title'] ) ) {
				$sanitized_section['title'] = sanitize_text_field( substr( $section['title'], 0, 24 ) );
			}

			$sanitized_sections[] = $sanitized_section;
		}

		if ( empty( $sanitized_sections ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_sections', __( 'No valid sections provided.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'button'   => sanitize_text_field( substr( $arguments['button_text'], 0, 20 ) ),
			'sections' => $sanitized_sections,
		);
	}

	/**
	 * Sanitize header object.
	 *
	 * @param array $header Header data.
	 * @return array Sanitized header.
	 */
	protected function sanitize_header( $header ) {
		$sanitized = array();

		if ( isset( $header['type'] ) ) {
			$sanitized['type'] = sanitize_text_field( $header['type'] );

			switch ( $sanitized['type'] ) {
				case 'text':
					if ( isset( $header['text'] ) ) {
						$sanitized['text'] = sanitize_text_field( substr( $header['text'], 0, 60 ) );
					}
					break;

				case 'image':
				case 'video':
				case 'document':
					if ( isset( $header[ $sanitized['type'] ] ) ) {
						$sanitized[ $sanitized['type'] ] = $header[ $sanitized['type'] ];
					}
					break;
			}
		}

		return $sanitized;
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
	 * @return string Sanitised recipient or empty string.
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
			'write',                // Sends WhatsApp messages.
			'external-api',         // Calls WhatsApp Cloud API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
