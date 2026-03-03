<?php
/**
 * Tool for retrieving Apple Messages for Business (iMessage) conversation history.
 *
 * Retrieves message history from your Messaging Service Provider (MSP) for active
 * Apple Messages for Business conversations. All access requires an approved MSP
 * such as Infobip, Zendesk, Sunshine Conversations, LivePerson, or CM.com.
 *
 * Industry references:
 * - https://developers.apple.com/documentation/businesschatapi/messages_received
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
 * Provides a tool for retrieving Apple Messages for Business conversation history via an MSP.
 */
class WP_MCP_AI_Pro_Tool_Get_Apple_Messages implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for MSP API requests (seconds).
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Maximum messages per request.
	 */
	const MAX_LIMIT = 100;

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
		return 'get_apple_messages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Apple Messages (iMessage)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves Apple Messages for Business conversation history from your Messaging Service Provider (MSP). Supports filtering by conversation ID, date range, and pagination.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Base URL of your MSP REST API endpoint for retrieving Apple Messages conversations (e.g. https://api.example-msp.com/v1/apple/conversations).', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'         => array(
					'type'        => 'string',
					'description' => __( 'API key or bearer token issued by your MSP.', 'mcp-ai-wpoos-pro' ),
				),
				'business_id'     => array(
					'type'        => 'string',
					'description' => __( 'Your Apple Messages for Business identifier.', 'mcp-ai-wpoos-pro' ),
				),
				'conversation_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional conversation ID to retrieve messages for a specific conversation. Omit to list recent conversations.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of messages to retrieve (1-100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 25,
				),
				'after'           => array(
					'type'        => 'string',
					'description' => __( 'Optional pagination cursor to fetch messages after this point (returned by a previous response).', 'mcp-ai-wpoos-pro' ),
				),
				'before'          => array(
					'type'        => 'string',
					'description' => __( 'Optional pagination cursor to fetch messages before this point.', 'mcp-ai-wpoos-pro' ),
				),
				'status'          => array(
					'type'        => 'string',
					'description' => __( 'Optional conversation status filter: open, closed, or all.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'open', 'closed', 'all' ),
					'default'     => 'open',
				),
			),
			'required'             => array( 'msp_api_url', 'api_key', 'business_id' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_apple_messages_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve Apple Messages.', 'mcp-ai-wpoos-pro' ) );
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

		// Build query parameters.
		$query_params = array(
			'businessId' => $business_id,
			'limit'      => $this->resolve_limit( $arguments ),
		);

		if ( ! empty( $arguments['conversation_id'] ) && is_string( $arguments['conversation_id'] ) ) {
			$query_params['conversationId'] = sanitize_text_field( $arguments['conversation_id'] );
		}

		if ( ! empty( $arguments['after'] ) && is_string( $arguments['after'] ) ) {
			$query_params['after'] = sanitize_text_field( $arguments['after'] );
		}

		if ( ! empty( $arguments['before'] ) && is_string( $arguments['before'] ) ) {
			$query_params['before'] = sanitize_text_field( $arguments['before'] );
		}

		if ( ! empty( $arguments['status'] ) && in_array( $arguments['status'], array( 'open', 'closed', 'all' ), true ) ) {
			$query_params['status'] = $arguments['status'];
		}

		$endpoint = add_query_arg( $query_params, $msp_api_url );

		WP_MCP_AI_Logger::log_event(
			'apple_get_messages_request',
			'Retrieving Apple Messages for Business conversation history.',
			array(
				'msp_api_url' => $msp_api_url,
				'business_id' => $this->mask_sensitive_value( $business_id ),
				'limit'       => $query_params['limit'],
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_apple_messages_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Apple Messages get conversation history request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_apple_http_error',
				__( 'The Apple Messages for Business API request failed.', 'mcp-ai-wpoos-pro' ),
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
				'Apple Messages get conversation history request was not successful.',
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
	 * Resolve the limit parameter with bounds checking.
	 *
	 * @param array $arguments Tool arguments.
	 * @return int
	 */
	protected function resolve_limit( $arguments ) {
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 25;

		if ( $limit < 1 ) {
			$limit = 1;
		} elseif ( $limit > self::MAX_LIMIT ) {
			$limit = self::MAX_LIMIT;
		}

		return $limit;
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
			'read-only',            // Only reads data.
			'external-api',         // Calls MSP REST API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
