<?php
/**
 * tools/chat-channels/class-wp-mcp-ai-pro-tool-send-teams-message.php (ecosystem port — Wave F5, chat-channels tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-send-teams-message.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the base-owned interface/logger/restrict/cron-manager
 * requires gain exists-check seams resolving from the addon's D8-compat `src/` copies.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
// Standalone seam (documented deviation): the base-owned logger require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}

/**
 * Provides a tool for sending Microsoft Teams messages via the Microsoft Graph API.
 */
class WP_MCP_AI_Pro_Tool_Send_Teams_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Microsoft Graph API requests.
	 */
	const DEFAULT_TIMEOUT = 20;

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
		return 'send_teams_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Teams Message', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message to a Microsoft Teams channel using the Microsoft Graph API.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'      => array(
					'type'        => 'string',
					'description' => __( 'Microsoft Graph API access token (Bearer token) used for authentication.', 'nvoos-content-graph-pro' ),
				),
				'team_id'    => array(
					'type'        => 'string',
					'description' => __( 'Microsoft Teams team ID where the message will be sent.', 'nvoos-content-graph-pro' ),
				),
				'channel_id' => array(
					'type'        => 'string',
					'description' => __( 'Microsoft Teams channel ID where the message will be sent.', 'nvoos-content-graph-pro' ),
				),
				'content'    => array(
					'type'        => 'string',
					'description' => __( 'Text content of the message to be sent.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'token', 'team_id', 'channel_id', 'content' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_send_teams_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Teams messages.', 'nvoos-content-graph-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_teams_token', __( 'A valid Microsoft Graph API access token is required.', 'nvoos-content-graph-pro' ) );
		}

		$team_id = isset( $arguments['team_id'] ) ? sanitize_text_field( $arguments['team_id'] ) : '';

		if ( '' === $team_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_team_id', __( 'A team ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$channel_id = isset( $arguments['channel_id'] ) ? sanitize_text_field( $arguments['channel_id'] ) : '';

		if ( '' === $channel_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_channel_id', __( 'A channel ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$content = isset( $arguments['content'] ) ? $this->sanitize_message_text( $arguments['content'] ) : '';

		if ( '' === $content ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_content', __( 'Message content must be provided.', 'nvoos-content-graph-pro' ) );
		}

		$endpoint = 'https://graph.microsoft.com/v1.0/teams/' . $team_id . '/channels/' . $channel_id . '/messages';

		$payload = array(
			'body' => array(
				'content' => $content,
			),
		);

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Teams request payload.', 'nvoos-content-graph-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'teams_send_message_request',
			'Sending Teams message request.',
			array(
				'endpoint'   => $endpoint,
				'team_id'    => $team_id,
				'channel_id' => $channel_id,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_send_teams_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams message request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_teams_http_error',
				__( 'The Microsoft Graph API request failed to send.', 'nvoos-content-graph-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 201 !== $code ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Microsoft Graph API returned an error.', 'nvoos-content-graph-pro' );

			WP_MCP_AI_Logger::log_error(
				'Teams message request was not successful.',
				array(
					'http_code'  => $code,
					'team_id'    => $team_id,
					'channel_id' => $channel_id,
					'error'      => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_teams_api_error',
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
	 * Sanitize a Microsoft Graph API access token.
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
	 * Sanitize Teams message text.
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
			'write',                // Sends Teams messages.
			'external-api',         // Calls Microsoft Graph API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
