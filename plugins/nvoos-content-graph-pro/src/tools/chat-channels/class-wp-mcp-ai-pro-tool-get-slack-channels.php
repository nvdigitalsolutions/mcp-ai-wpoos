<?php
/**
 * tools/chat-channels/class-wp-mcp-ai-pro-tool-get-slack-channels.php (ecosystem port — Wave F5, chat-channels tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-get-slack-channels.php` for the
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
 * Provides a tool for listing Slack channels via the Web API.
 */
class WP_MCP_AI_Pro_Tool_Get_Slack_Channels implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'get_slack_channels';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Slack Channels', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists available Slack channels using the Slack Web API. Supports filtering by type and pagination with cursors.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'  => array(
					'type'        => 'string',
					'description' => __( 'Slack bot token (xoxb-) or user token (xoxp-) used for authentication.', 'nvoos-content-graph-pro' ),
				),
				'types'  => array(
					'type'        => 'string',
					'description' => __( 'Comma-separated list of channel types: public_channel, private_channel, mpim, im. Defaults to public_channel.', 'nvoos-content-graph-pro' ),
				),
				'cursor' => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor from a previous request to fetch the next page of results.', 'nvoos-content-graph-pro' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of channels to return (1-1000). Defaults to 100.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'token' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_get_slack_channels_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list Slack channels.', 'nvoos-content-graph-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_slack_token', __( 'A valid Slack token is required.', 'nvoos-content-graph-pro' ) );
		}

		$endpoint = 'https://slack.com/api/conversations.list';

		$query_params = array();

		if ( isset( $arguments['types'] ) && is_string( $arguments['types'] ) ) {
			$query_params['types'] = sanitize_text_field( $arguments['types'] );
		}

		if ( isset( $arguments['cursor'] ) && is_string( $arguments['cursor'] ) ) {
			$query_params['cursor'] = sanitize_text_field( $arguments['cursor'] );
		}

		if ( isset( $arguments['limit'] ) && is_numeric( $arguments['limit'] ) ) {
			$limit = absint( $arguments['limit'] );
			if ( $limit > 0 && $limit <= 1000 ) {
				$query_params['limit'] = $limit;
			}
		}

		if ( ! empty( $query_params ) ) {
			$endpoint .= '?' . http_build_query( $query_params );
		}

		WP_MCP_AI_Logger::log_event(
			'slack_list_channels_request',
			'Sending Slack conversations.list request.',
			array(
				'endpoint' => 'https://slack.com/api/conversations.list',
				'types'    => isset( $query_params['types'] ) ? $query_params['types'] : 'public_channel',
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_slack_channels_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack conversations.list request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_slack_http_error',
				__( 'The Slack API request failed to send.', 'nvoos-content-graph-pro' ),
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
			$message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Slack API returned an error.', 'nvoos-content-graph-pro' );

			WP_MCP_AI_Logger::log_error(
				'Slack conversations.list request was not successful.',
				array(
					'http_code' => $code,
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
	 * Map a Slack API error code to a human-readable, actionable error message.
	 *
	 * @param string $error_code Slack API error code (e.g. 'account_inactive').
	 * @return string Translated error message.
	 */
	protected function get_friendly_slack_error( $error_code ) {
		$known = array(
			'account_inactive' => __( 'Slack API error: account_inactive — The bot account associated with this token has been deactivated. Please check that your Slack app is still installed in the workspace and that the bot user has not been removed. Generate a new Bot Token from your Slack app configuration (api.slack.com/apps) and update it in the connection settings.', 'nvoos-content-graph-pro' ),
			'invalid_auth'     => __( 'Slack API error: invalid_auth — The bot token is invalid or has been revoked. Please generate a new token from your Slack app.', 'nvoos-content-graph-pro' ),
			'token_revoked'    => __( 'Slack API error: token_revoked — This token has been revoked. Please reinstall your Slack app to the workspace.', 'nvoos-content-graph-pro' ),
			'not_authed'       => __( 'Slack API error: not_authed — No bot token was provided. Please configure a valid Bot Token (xoxb-) in the connection settings.', 'nvoos-content-graph-pro' ),
			'missing_scope'    => __( 'Slack API error: missing_scope — The bot token does not have the required OAuth scopes. Please update your Slack app permissions and reinstall it.', 'nvoos-content-graph-pro' ),
		);

		return isset( $known[ $error_code ] ) ? $known[ $error_code ] : $error_code;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Reads Slack channels.
			'external-api',         // Calls Slack Web API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
