<?php
/**
 * Tool for sending Apple Messages for Business (iMessage) interactive messages.
 *
 * Supports Apple-native interactive message types:
 * - List Picker: product menus, options, support topics
 * - Date/Time Picker: appointment and delivery scheduling
 * - Rich Link: enhanced link previews with image and metadata
 * - Authenticate: biometric-backed customer identity verification
 *
 * All interactive types follow Apple's Business Chat protocol and must be
 * delivered through an approved Messaging Service Provider (MSP).
 *
 * Industry references:
 * - https://developers.apple.com/documentation/businesschatapi/messages_sent
 * - https://register.apple.com/resources/messages/msp-required-capabilities.pdf
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending Apple Messages for Business interactive messages.
 */
class WP_MCP_AI_Pro_Tool_Send_Apple_Message_Interactive implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for MSP API requests (seconds).
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Supported interactive message types.
	 */
	const SUPPORTED_TYPES = array( 'list_picker', 'time_picker', 'rich_link', 'authenticate' );

	/**
	 * Maximum items in a list picker section.
	 */
	const MAX_LIST_PICKER_ITEMS = 10;

	/**
	 * Maximum list picker sections.
	 */
	const MAX_LIST_PICKER_SECTIONS = 10;

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
		return 'send_apple_message_interactive';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Apple Message Interactive (iMessage)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends an Apple Messages for Business interactive message including list pickers, date/time pickers, rich links, and authentication requests through an approved MSP.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'msp_api_url'          => array(
					'type'        => 'string',
					'description' => __( 'Base URL of your Messaging Service Provider REST API endpoint.', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'              => array(
					'type'        => 'string',
					'description' => __( 'API key or bearer token issued by your MSP.', 'mcp-ai-wpoos-pro' ),
				),
				'business_id'          => array(
					'type'        => 'string',
					'description' => __( 'Your Apple Messages for Business ID issued during Apple registration.', 'mcp-ai-wpoos-pro' ),
				),
				'conversation_id'      => array(
					'type'        => 'string',
					'description' => __( 'Active conversation ID. Provide to continue an ongoing conversation.', 'mcp-ai-wpoos-pro' ),
				),
				'recipient_id'         => array(
					'type'        => 'string',
					'description' => __( 'Opaque Apple customer identifier. Provide when no conversation_id exists yet.', 'mcp-ai-wpoos-pro' ),
				),
				'interactive_type'     => array(
					'type'        => 'string',
					'description' => __( 'Interactive message type: list_picker, time_picker, rich_link, or authenticate.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list_picker', 'time_picker', 'rich_link', 'authenticate' ),
				),
				'body_text'            => array(
					'type'        => 'string',
					'description' => __( 'Main body text shown to the customer above the interactive widget.', 'mcp-ai-wpoos-pro' ),
				),
				// List Picker parameters.
				'list_picker_sections' => array(
					'type'        => 'array',
					'description' => __( 'Sections for list_picker type. Each section has a title and items array. Max 10 sections with max 10 items each.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'         => array(
								'type'        => 'string',
								'description' => __( 'Optional section title.', 'mcp-ai-wpoos-pro' ),
							),
							'multipleSelection' => array(
								'type'        => 'boolean',
								'description' => __( 'Allow multiple item selection in this section.', 'mcp-ai-wpoos-pro' ),
								'default'     => false,
							),
							'items'         => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'identifier'  => array(
											'type'        => 'string',
											'description' => __( 'Unique identifier returned when item is selected.', 'mcp-ai-wpoos-pro' ),
										),
										'title'       => array(
											'type'        => 'string',
											'description' => __( 'Display text shown to the customer.', 'mcp-ai-wpoos-pro' ),
										),
										'subtitle'    => array(
											'type'        => 'string',
											'description' => __( 'Optional secondary text (e.g. price, description).', 'mcp-ai-wpoos-pro' ),
										),
										'imageData'   => array(
											'type'        => 'string',
											'description' => __( 'Optional base64-encoded image or URL for item illustration.', 'mcp-ai-wpoos-pro' ),
										),
										'style'       => array(
											'type'        => 'string',
											'description' => __( 'Optional visual style: default or emphasize.', 'mcp-ai-wpoos-pro' ),
											'enum'        => array( 'default', 'emphasize' ),
										),
									),
								),
							),
						),
					),
				),
				// Time Picker parameters.
				'time_picker_event'    => array(
					'type'        => 'object',
					'description' => __( 'Event details for time_picker type.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'identifier'  => array(
							'type'        => 'string',
							'description' => __( 'Unique identifier for this event/appointment.', 'mcp-ai-wpoos-pro' ),
						),
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'Event title shown at the top of the picker.', 'mcp-ai-wpoos-pro' ),
						),
						'location'    => array(
							'type'        => 'object',
							'description' => __( 'Physical or virtual location of the event.', 'mcp-ai-wpoos-pro' ),
							'properties'  => array(
								'title'     => array( 'type' => 'string' ),
								'latitude'  => array( 'type' => 'number' ),
								'longitude' => array( 'type' => 'number' ),
								'radius'    => array( 'type' => 'number' ),
							),
						),
						'timeslots'   => array(
							'type'        => 'array',
							'description' => __( 'Array of available time slots (ISO 8601 start/end pairs).', 'mcp-ai-wpoos-pro' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'identifier' => array( 'type' => 'string' ),
									'startTime'  => array(
										'type'        => 'string',
										'description' => __( 'ISO 8601 start datetime (e.g. 2025-06-01T09:00:00Z).', 'mcp-ai-wpoos-pro' ),
									),
									'duration'   => array(
										'type'        => 'integer',
										'description' => __( 'Duration of the slot in seconds.', 'mcp-ai-wpoos-pro' ),
									),
								),
							),
						),
					),
				),
				// Rich Link parameters.
				'rich_link_url'        => array(
					'type'        => 'string',
					'description' => __( 'For rich_link type: destination URL for the rich link card (must be HTTPS).', 'mcp-ai-wpoos-pro' ),
				),
				'rich_link_title'      => array(
					'type'        => 'string',
					'description' => __( 'Title text displayed on the rich link card.', 'mcp-ai-wpoos-pro' ),
				),
				'rich_link_image_url'  => array(
					'type'        => 'string',
					'description' => __( 'Optional image URL to display in the rich link preview (must be HTTPS).', 'mcp-ai-wpoos-pro' ),
				),
				// Authenticate parameters.
				'authenticate_request_id' => array(
					'type'        => 'string',
					'description' => __( 'For authenticate type: unique request ID to correlate authentication responses.', 'mcp-ai-wpoos-pro' ),
				),
				'locale'               => array(
					'type'        => 'string',
					'description' => __( 'BCP 47 locale code for the message (e.g. en-US).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'en-US',
				),
			),
			'required'             => array( 'msp_api_url', 'api_key', 'business_id', 'interactive_type', 'body_text' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_apple_message_interactive_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Apple Messages interactive messages.', 'mcp-ai-wpoos-pro' ) );
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

		$interactive_type = isset( $arguments['interactive_type'] ) ? sanitize_text_field( $arguments['interactive_type'] ) : '';
		if ( ! in_array( $interactive_type, self::SUPPORTED_TYPES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_apple_interactive_type',
				/* translators: %s: comma-separated list of valid interactive types */
				sprintf( __( 'Interactive type must be one of: %s.', 'mcp-ai-wpoos-pro' ), implode( ', ', self::SUPPORTED_TYPES ) )
			);
		}

		$body_text = isset( $arguments['body_text'] ) ? sanitize_textarea_field( trim( $arguments['body_text'] ) ) : '';
		if ( '' === $body_text ) {
			return new WP_Error( 'wp_mcp_ai_missing_apple_body_text', __( 'Body text is required for interactive messages.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build interactive payload based on type.
		$interactive_data = $this->build_interactive_payload( $interactive_type, $arguments );
		if ( is_wp_error( $interactive_data ) ) {
			return $interactive_data;
		}

		$payload = array(
			'businessId'      => $business_id,
			'type'            => 'interactive',
			'interactiveType' => $interactive_type,
			'body'            => array(
				'text' => $body_text,
			),
			'interactive'     => $interactive_data,
			'locale'          => isset( $arguments['locale'] ) && is_string( $arguments['locale'] ) ? sanitize_text_field( $arguments['locale'] ) : 'en-US',
		);

		if ( ! empty( $arguments['conversation_id'] ) && is_string( $arguments['conversation_id'] ) ) {
			$payload['conversationId'] = sanitize_text_field( $arguments['conversation_id'] );
		}

		if ( ! empty( $arguments['recipient_id'] ) && is_string( $arguments['recipient_id'] ) ) {
			$payload['recipientId'] = sanitize_text_field( $arguments['recipient_id'] );
		}

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Apple Messages interactive request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'apple_interactive_message_send_request',
			'Sending Apple Messages for Business interactive message.',
			array(
				'msp_api_url'      => $msp_api_url,
				'business_id'      => $this->mask_sensitive_value( $business_id ),
				'interactive_type' => $interactive_type,
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
				'timeout' => apply_filters( 'wp_mcp_ai_send_apple_message_interactive_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Apple Messages interactive request failed.', array( 'error' => $response->get_error_message() ) );

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
				'Apple Messages for Business interactive request was not successful.',
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

		return $decoded;
	}

	/**
	 * Build the interactive payload specific to the interactive_type.
	 *
	 * @param string $type      Interactive message type.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error   Interactive data or WP_Error on validation failure.
	 */
	protected function build_interactive_payload( $type, $arguments ) {
		switch ( $type ) {
			case 'list_picker':
				return $this->build_list_picker( $arguments );

			case 'time_picker':
				return $this->build_time_picker( $arguments );

			case 'rich_link':
				return $this->build_rich_link( $arguments );

			case 'authenticate':
				return $this->build_authenticate( $arguments );

			default:
				return new WP_Error( 'wp_mcp_ai_invalid_apple_interactive_type', __( 'Unsupported interactive message type.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Build list picker interactive payload.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function build_list_picker( $arguments ) {
		if ( empty( $arguments['list_picker_sections'] ) || ! is_array( $arguments['list_picker_sections'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_list_picker_sections', __( 'list_picker_sections is required for list_picker type.', 'mcp-ai-wpoos-pro' ) );
		}

		$raw_sections = $arguments['list_picker_sections'];

		if ( count( $raw_sections ) > self::MAX_LIST_PICKER_SECTIONS ) {
			return new WP_Error(
				'wp_mcp_ai_too_many_picker_sections',
				/* translators: %d: maximum section count */
				sprintf( __( 'List picker supports a maximum of %d sections.', 'mcp-ai-wpoos-pro' ), self::MAX_LIST_PICKER_SECTIONS )
			);
		}

		$sanitized_sections = array();

		foreach ( $raw_sections as $section ) {
			if ( ! is_array( $section ) || empty( $section['items'] ) || ! is_array( $section['items'] ) ) {
				continue;
			}

			$sanitized_section = array();

			if ( ! empty( $section['title'] ) && is_string( $section['title'] ) ) {
				$sanitized_section['title'] = sanitize_text_field( $section['title'] );
			}

			if ( isset( $section['multipleSelection'] ) ) {
				$sanitized_section['multipleSelection'] = (bool) $section['multipleSelection'];
			}

			$items = array_slice( $section['items'], 0, self::MAX_LIST_PICKER_ITEMS );
			$sanitized_items = array();

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item['identifier'] ) || empty( $item['title'] ) ) {
					continue;
				}

				$sanitized_item = array(
					'identifier' => sanitize_text_field( $item['identifier'] ),
					'title'      => sanitize_text_field( $item['title'] ),
				);

				if ( ! empty( $item['subtitle'] ) && is_string( $item['subtitle'] ) ) {
					$sanitized_item['subtitle'] = sanitize_text_field( $item['subtitle'] );
				}

				if ( ! empty( $item['imageData'] ) && is_string( $item['imageData'] ) ) {
					// Allow base64 or HTTPS URL.
					if ( 0 === strpos( $item['imageData'], 'https://' ) ) {
						$sanitized_item['imageData'] = esc_url_raw( $item['imageData'] );
					} elseif ( 0 === strpos( $item['imageData'], 'data:image/' ) ) {
						// Basic check for base64 data URI - keep as-is for transmission.
						$sanitized_item['imageData'] = $item['imageData'];
					}
				}

				if ( ! empty( $item['style'] ) && in_array( $item['style'], array( 'default', 'emphasize' ), true ) ) {
					$sanitized_item['style'] = $item['style'];
				}

				$sanitized_items[] = $sanitized_item;
			}

			if ( empty( $sanitized_items ) ) {
				continue;
			}

			$sanitized_section['items'] = $sanitized_items;
			$sanitized_sections[]       = $sanitized_section;
		}

		if ( empty( $sanitized_sections ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_picker_sections', __( 'No valid list picker sections provided.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'sections' => $sanitized_sections,
		);
	}

	/**
	 * Build time picker interactive payload.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function build_time_picker( $arguments ) {
		if ( empty( $arguments['time_picker_event'] ) || ! is_array( $arguments['time_picker_event'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_time_picker_event', __( 'time_picker_event is required for time_picker type.', 'mcp-ai-wpoos-pro' ) );
		}

		$event = $arguments['time_picker_event'];

		if ( empty( $event['identifier'] ) || empty( $event['title'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_time_picker_event', __( 'time_picker_event must include identifier and title.', 'mcp-ai-wpoos-pro' ) );
		}

		$sanitized_event = array(
			'identifier' => sanitize_text_field( $event['identifier'] ),
			'title'      => sanitize_text_field( $event['title'] ),
		);

		// Optional location.
		if ( ! empty( $event['location'] ) && is_array( $event['location'] ) ) {
			$location = array();

			if ( ! empty( $event['location']['title'] ) ) {
				$location['title'] = sanitize_text_field( $event['location']['title'] );
			}

			if ( isset( $event['location']['latitude'] ) && is_numeric( $event['location']['latitude'] ) ) {
				$location['latitude'] = (float) $event['location']['latitude'];
			}

			if ( isset( $event['location']['longitude'] ) && is_numeric( $event['location']['longitude'] ) ) {
				$location['longitude'] = (float) $event['location']['longitude'];
			}

			if ( isset( $event['location']['radius'] ) && is_numeric( $event['location']['radius'] ) ) {
				$location['radius'] = (float) $event['location']['radius'];
			}

			if ( ! empty( $location ) ) {
				$sanitized_event['location'] = $location;
			}
		}

		// Timeslots.
		if ( ! empty( $event['timeslots'] ) && is_array( $event['timeslots'] ) ) {
			$sanitized_slots = array();

			foreach ( $event['timeslots'] as $slot ) {
				if ( ! is_array( $slot ) || empty( $slot['identifier'] ) || empty( $slot['startTime'] ) ) {
					continue;
				}

				$sanitized_slot = array(
					'identifier' => sanitize_text_field( $slot['identifier'] ),
					'startTime'  => sanitize_text_field( $slot['startTime'] ),
				);

				if ( isset( $slot['duration'] ) && is_numeric( $slot['duration'] ) ) {
					$sanitized_slot['duration'] = absint( $slot['duration'] );
				}

				$sanitized_slots[] = $sanitized_slot;
			}

			if ( ! empty( $sanitized_slots ) ) {
				$sanitized_event['timeslots'] = $sanitized_slots;
			}
		}

		return array(
			'event' => $sanitized_event,
		);
	}

	/**
	 * Build rich link interactive payload.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function build_rich_link( $arguments ) {
		if ( empty( $arguments['rich_link_url'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_rich_link_url', __( 'rich_link_url is required for rich_link type.', 'mcp-ai-wpoos-pro' ) );
		}

		$url = esc_url_raw( trim( $arguments['rich_link_url'] ) );
		if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_rich_link_url', __( 'rich_link_url must be a valid HTTPS URL.', 'mcp-ai-wpoos-pro' ) );
		}

		$rich_link = array(
			'url' => $url,
		);

		if ( ! empty( $arguments['rich_link_title'] ) && is_string( $arguments['rich_link_title'] ) ) {
			$rich_link['title'] = sanitize_text_field( $arguments['rich_link_title'] );
		}

		if ( ! empty( $arguments['rich_link_image_url'] ) && is_string( $arguments['rich_link_image_url'] ) ) {
			$image_url = esc_url_raw( trim( $arguments['rich_link_image_url'] ) );
			if ( '' !== $image_url && 0 === strpos( $image_url, 'https://' ) ) {
				$rich_link['imageUrl'] = $image_url;
			}
		}

		return array(
			'richLink' => $rich_link,
		);
	}

	/**
	 * Build authenticate interactive payload.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function build_authenticate( $arguments ) {
		if ( empty( $arguments['authenticate_request_id'] ) || ! is_string( $arguments['authenticate_request_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_authenticate_request_id', __( 'authenticate_request_id is required for authenticate type.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'authenticate' => array(
				'requestIdentifier' => sanitize_text_field( $arguments['authenticate_request_id'] ),
			),
		);
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
