<?php
/**
 * Tool that sends a message to WebChat P2P rooms.
 *
 * WebChat is a decentralized, serverless chat system using WebRTC.
 * This tool integrates with the WebChat browser extension to send
 * messages via the signaling server to active P2P rooms.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for sending messages to WebChat P2P rooms.
 *
 * WebChat uses WebRTC for peer-to-peer communication. This tool
 * broadcasts messages to active rooms on the current site via
 * WebSocket signaling or REST API.
 */
class WP_MCP_AI_Pro_Tool_Send_WebChat_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for WebChat signaling.
	 */
	const DEFAULT_TIMEOUT = 10;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if WebChat is enabled in settings.
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_webchat_integration'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_webchat_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send WebChat Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a message to WebChat P2P rooms on this site. WebChat is a decentralized browser extension for anonymous peer-to-peer chat.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Message text to broadcast to WebChat rooms.', 'mcp-ai-wpoos-pro' ),
				),
				'room_id'     => array(
					'type'        => 'string',
					'description' => __( 'Optional room ID to target. If omitted, broadcasts to all active rooms on this site.', 'mcp-ai-wpoos-pro' ),
				),
				'sender_name' => array(
					'type'        => 'string',
					'description' => __( 'Display name for the sender (default: "WordPress Assistant").', 'mcp-ai-wpoos-pro' ),
					'default'     => 'WordPress Assistant',
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_webchat_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send WebChat messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$message = isset( $arguments['message'] ) ? sanitize_textarea_field( $arguments['message'] ) : '';

		if ( '' === $message ) {
			return new WP_Error( 'wp_mcp_ai_missing_message', __( 'Message text must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$room_id     = isset( $arguments['room_id'] ) ? sanitize_text_field( $arguments['room_id'] ) : '';
		$sender_name = isset( $arguments['sender_name'] ) ? sanitize_text_field( $arguments['sender_name'] ) : 'WordPress Assistant';

		// Get WebChat settings.
		$settings        = get_option( 'wp_mcp_ai_settings', array() );
		$signaling_url   = isset( $settings['webchat_signaling_url'] ) ? $settings['webchat_signaling_url'] : '';
		$api_key         = isset( $settings['webchat_api_key'] ) ? $settings['webchat_api_key'] : '';
		$use_rest_api    = ! empty( $settings['webchat_use_rest_api'] );

		// WebChat message payload.
		$payload = array(
			'type'    => 'message',
			'content' => $message,
			'sender'  => $sender_name,
			'room'    => $room_id ? $room_id : get_option( 'siteurl' ),
			'site'    => get_option( 'siteurl' ),
			'timestamp' => time(),
		);

		WP_MCP_AI_Logger::log_event(
			'webchat_send_message',
			'Sending WebChat P2P message.',
			array(
				'room'        => $room_id ? $room_id : 'all',
				'sender'      => $sender_name,
				'use_api'     => $use_rest_api,
			)
		);

		if ( $use_rest_api && $signaling_url ) {
			// Use REST API endpoint if configured.
			return $this->send_via_rest_api( $payload, $signaling_url, $api_key, $context, $arguments );
		} else {
			// Use WebSocket broadcast (requires WebSocket server).
			return $this->send_via_websocket( $payload, $context, $arguments );
		}
	}

	/**
	 * Send message via REST API to WebChat signaling server.
	 *
	 * @param array  $payload   Message payload.
	 * @param string $url       Signaling server URL.
	 * @param string $api_key   API key for authentication.
	 * @param array  $context   Execution context.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function send_via_rest_api( $payload, $url, $api_key, $context, $arguments ) {
		$endpoint = trailingslashit( $url ) . 'broadcast';

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode WebChat message payload.', 'mcp-ai-wpoos-pro' ) );
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( $api_key ) {
			$headers['X-API-Key'] = $api_key;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => $headers,
				'timeout' => apply_filters( 'wp_mcp_ai_send_webchat_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'WebChat REST API request failed.', array( 'error' => $response->get_error_message() ) );
			return new WP_Error(
				'wp_mcp_ai_webchat_http_error',
				__( 'The WebChat signaling server request failed.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( 200 !== $code && 201 !== $code ) {
			$message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'WebChat signaling server returned an error.', 'mcp-ai-wpoos-pro' );
			WP_MCP_AI_Logger::log_error( 'WebChat message broadcast failed.', array( 'http_code' => $code, 'error' => $message ) );
			return new WP_Error( 'wp_mcp_ai_webchat_api_error', esc_html( $message ), array( 'code' => $code, 'response' => $decoded ) );
		}

		return array(
			'success'    => true,
			'method'     => 'rest_api',
			'recipients' => isset( $decoded['recipients'] ) ? $decoded['recipients'] : 0,
			'room'       => $payload['room'],
		);
	}

	/**
	 * Send message via WebSocket to local WebChat peers.
	 *
	 * @param array $payload   Message payload.
	 * @param array $context   Execution context.
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function send_via_websocket( $payload, $context, $arguments ) {
		// Store message in transient for WebSocket server to pick up.
		$transient_key = 'wp_mcp_ai_webchat_outbound_' . md5( wp_json_encode( $payload ) );
		set_transient( $transient_key, $payload, 60 ); // 60 seconds TTL.

		// Trigger WordPress action for WebSocket handlers.
		do_action( 'wp_mcp_ai_webchat_message_outbound', $payload );

		WP_MCP_AI_Logger::log_event(
			'webchat_message_queued',
			'WebChat message queued for WebSocket broadcast.',
			array( 'transient_key' => $transient_key )
		);

		return array(
			'success' => true,
			'method'  => 'websocket',
			'queued'  => true,
			'room'    => $payload['room'],
			'message' => __( 'Message queued for WebSocket broadcast. Ensure WebSocket server is running.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends messages.
			'external-api',         // May call external signaling server.
			'network-dependent',    // Requires network for signaling.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
