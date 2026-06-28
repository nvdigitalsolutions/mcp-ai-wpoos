<?php
/**
 * Tool that sends messages to Google Chat spaces via webhook URLs.
 *
 * Google Chat supports incoming webhooks where the URL contains the
 * authentication key. Each Google Chat space generates a unique webhook URL.
 * Messages are sent as plain text; the Google Chat API automatically renders
 * basic markdown.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Sends a plain-text message to a Google Chat space via its incoming webhook URL.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Send_Google_Chat_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Google Chat API requests.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true — no external library dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_google_chat_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Google Chat Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message to a Google Chat space using an incoming webhook URL.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'webhook_url' => array(
					'type'        => 'string',
					'description' => __( 'Google Chat incoming webhook URL (https://chat.googleapis.com/v1/spaces/...).', 'mcp-ai-wpoos-pro' ),
				),
				'text'        => array(
					'type'        => 'string',
					'description' => __( 'Plain text message to send. Basic markdown is rendered automatically by Google Chat.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'webhook_url', 'text' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * Posts a message payload to the Google Chat webhook URL. The webhook URL
	 * itself carries the authentication key, so no separate Bearer token is
	 * required.
	 *
	 * @param array $arguments Tool arguments (webhook_url, text).
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_send_google_chat_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Google Chat messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$webhook_url = isset( $arguments['webhook_url'] ) ? esc_url_raw( $arguments['webhook_url'] ) : '';
		if ( '' === $webhook_url || ! filter_var( $webhook_url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url', __( 'A valid Google Chat webhook URL is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$text = isset( $arguments['text'] ) ? sanitize_textarea_field( $arguments['text'] ) : '';
		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_text', __( 'A message text is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Google Chat webhook: POST JSON with a "text" field.
		// The webhook URL itself contains the space and key, so no additional
		// Authorization header is needed.
		$body = wp_json_encode(
			array(
				'text' => $text,
			)
		);

		WP_MCP_AI_Logger::log_event(
			'send_google_chat_message_request',
			'Sending message to Google Chat space.',
			array(
				'message_length' => strlen( $text ),
			)
		);

		$response = wp_remote_post(
			$webhook_url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json; charset=UTF-8',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_google_chat_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_event(
				'send_google_chat_message_error',
				'Google Chat webhook request failed.',
				array( 'error' => $response->get_error_message() )
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$resp_body   = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			WP_MCP_AI_Logger::log_event(
				'send_google_chat_message_error',
				'Google Chat webhook returned non-2xx status.',
				array(
					'status' => $status_code,
					'body'   => $resp_body,
				)
			);
			return new WP_Error(
				'google_chat_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Google Chat API returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Message sent to Google Chat space.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends messages.
			'external-api',         // Calls Google Chat API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
