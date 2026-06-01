<?php
/**
 * Tool that sends a Slack message.
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
 * Provides a tool for sending Slack messages via the Web API.
 */
class WP_MCP_AI_Pro_Tool_Send_Slack_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Slack requests.
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
		return 'send_slack_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Slack Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message to a Slack channel or direct message using the Slack Web API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'     => array(
					'type'        => 'string',
					'description' => __( 'Slack bot token (xoxb-) or user token (xoxp-) used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'channel'   => array(
					'type'        => 'string',
					'description' => __( 'Channel ID, direct message ID, or channel name (e.g., #general).', 'mcp-ai-wpoos-pro' ),
				),
				'text'      => array(
					'type'        => 'string',
					'description' => __( 'Text content of the message to be sent.', 'mcp-ai-wpoos-pro' ),
				),
				'blocks'    => array(
					'type'        => 'array',
					'description' => __( 'Optional array of Block Kit blocks for rich formatting.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'thread_ts' => array(
					'type'        => 'string',
					'description' => __( 'Optional thread timestamp to reply in a thread.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'token', 'channel', 'text' ),
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
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_send_slack_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Slack messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_slack_token', __( 'A valid Slack token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$channel = isset( $arguments['channel'] ) ? sanitize_text_field( $arguments['channel'] ) : '';

		if ( '' === $channel ) {
			return new WP_Error( 'wp_mcp_ai_missing_channel', __( 'A target channel identifier is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$text = isset( $arguments['text'] ) ? $this->sanitize_message_text( $arguments['text'] ) : '';

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_text', __( 'Message text must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$endpoint = 'https://slack.com/api/chat.postMessage';

		$payload = array(
			'channel' => $channel,
			'text'    => $text,
		);

		// Add optional blocks for rich formatting.
		if ( isset( $arguments['blocks'] ) && is_array( $arguments['blocks'] ) ) {
			$payload['blocks'] = $arguments['blocks'];
		}

		// Add optional thread_ts for threaded replies.
		if ( isset( $arguments['thread_ts'] ) && is_string( $arguments['thread_ts'] ) ) {
			$payload['thread_ts'] = sanitize_text_field( $arguments['thread_ts'] );
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Slack request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'slack_send_message_request',
			'Sending Slack postMessage request.',
			array(
				'endpoint' => 'https://slack.com/api/chat.postMessage',
				'channel'  => $channel,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_slack_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack postMessage request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_slack_http_error',
				__( 'The Slack API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['ok'] ) ) {
			$message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Slack API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Slack postMessage request was not successful.',
				array(
					'http_code' => $code,
					'channel'   => $channel,
					'error'     => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_slack_api_error',
				esc_html( $this->get_friendly_slack_error( $message ) ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Sanitize a Slack token.
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
	 * Sanitize Slack message text.
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
	 * Map a Slack API error code to a human-readable, actionable error message.
	 *
	 * @param string $error_code Slack API error code (e.g. 'account_inactive').
	 * @return string Translated error message.
	 */
	protected function get_friendly_slack_error( $error_code ) {
		$known = array(
			'account_inactive' => __( 'Slack API error: account_inactive — The bot account associated with this token has been deactivated. Please check that your Slack app is still installed in the workspace and that the bot user has not been removed. Generate a new Bot Token from your Slack app configuration (api.slack.com/apps) and update it in the connection settings.', 'mcp-ai-wpoos-pro' ),
			'invalid_auth'     => __( 'Slack API error: invalid_auth — The bot token is invalid or has been revoked. Please generate a new token from your Slack app.', 'mcp-ai-wpoos-pro' ),
			'token_revoked'    => __( 'Slack API error: token_revoked — This token has been revoked. Please reinstall your Slack app to the workspace.', 'mcp-ai-wpoos-pro' ),
			'not_authed'       => __( 'Slack API error: not_authed — No bot token was provided. Please configure a valid Bot Token (xoxb-) in the connection settings.', 'mcp-ai-wpoos-pro' ),
			'missing_scope'    => __( 'Slack API error: missing_scope — The bot token does not have the required OAuth scopes. Please update your Slack app permissions and reinstall it.', 'mcp-ai-wpoos-pro' ),
		);

		return isset( $known[ $error_code ] ) ? $known[ $error_code ] : $error_code;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends Slack messages.
			'external-api',         // Calls Slack Web API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
