<?php
/**
 * Tool that creates a Facebook Messenger broadcast message.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending broadcast messages via the Facebook Messenger Platform API.
 */
class WP_MCP_AI_Pro_Tool_Create_Messenger_Broadcast implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Facebook Messenger API requests.
	 */
	const DEFAULT_TIMEOUT = 15;

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
		return 'create_messenger_broadcast';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Messenger Broadcast', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a broadcast message to multiple Facebook Messenger users using the Messenger Platform API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token' => array(
					'type'        => 'string',
					'description' => __( 'Facebook Page access token used for authentication. Can be provided via parameter or Authorization Bearer header.', 'mcp-ai-wpoos-pro' ),
				),
				'message'      => array(
					'type'        => 'string',
					'description' => __( 'Text content of the broadcast message to be sent.', 'mcp-ai-wpoos-pro' ),
				),
				'custom_label' => array(
					'type'        => 'string',
					'description' => __( 'Optional custom label ID to target specific users (targeting parameter).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'message' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_create_messenger_broadcast_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create Messenger broadcasts.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$access_token = isset( $arguments['access_token'] ) ? $this->sanitize_token( $arguments['access_token'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error( 'wp_mcp_ai_missing_messenger_token', __( 'A valid Facebook Page access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$message = isset( $arguments['message'] ) ? $this->sanitize_message_text( $arguments['message'] ) : '';

		if ( '' === $message ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_content', __( 'Message content must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$custom_label = isset( $arguments['custom_label'] ) ? sanitize_text_field( $arguments['custom_label'] ) : '';

		$endpoint = 'https://graph.facebook.com/v19.0/me/broadcast_messages';

		$payload = array(
			'message' => array(
				'text' => $message,
			),
		);

		if ( '' !== $custom_label ) {
			$payload['targeting'] = array(
				'custom_label_id' => $custom_label,
			);
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Messenger broadcast request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'messenger_create_broadcast_request',
			'Creating Messenger broadcast message.',
			array(
				'endpoint'     => $endpoint,
				'custom_label' => $custom_label,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_create_messenger_broadcast_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Messenger broadcast request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_messenger_http_error',
				__( 'The Facebook Messenger API request failed to send.', 'mcp-ai-wpoos-pro' ),
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
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Facebook Messenger API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Messenger broadcast request was not successful.',
				array(
					'http_code' => $code,
					'error'     => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_messenger_api_error',
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
	 * Sanitize a Facebook access token.
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
	 * Sanitize Messenger message text.
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
			'write',                // Sends Messenger broadcast messages.
			'external-api',         // Calls Facebook Messenger API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
