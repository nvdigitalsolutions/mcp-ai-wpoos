<?php
/**
 * tools/chat-channels/class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php (ecosystem port — Wave F5, chat-channels tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-add-discord-message-reaction.php` for the
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
 * Provides a tool for adding emoji reactions to Discord messages via the Discord Bot API.
 *
 * Supports standard Unicode emoji (e.g. "👍") and custom guild emoji in the
 * format "name:id" (e.g. "thinking:1234567890"). Used by agent workflow hooks
 * to signal processing phases to users in real time.
 */
class WP_MCP_AI_Pro_Tool_Add_Discord_Message_Reaction implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for Discord API requests.
	 */
	const DEFAULT_TIMEOUT = 10;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true – no additional dependencies required.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'add_discord_message_reaction';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Add Discord Message Reaction', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds an emoji reaction to a Discord message via the Discord Bot API. Supports Unicode emoji (e.g. "👍") and custom guild emoji ("name:id"). Used for lifecycle feedback during AI processing phases (queued, thinking, tool-use, done, error).', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Discord bot token used for authentication.', 'nvoos-content-graph-pro' ),
				),
				'channel_id' => array(
					'type'        => 'string',
					'description' => __( 'ID of the Discord channel containing the target message.', 'nvoos-content-graph-pro' ),
				),
				'message_id' => array(
					'type'        => 'string',
					'description' => __( 'ID of the Discord message to react to.', 'nvoos-content-graph-pro' ),
				),
				'emoji'      => array(
					'type'        => 'string',
					'description' => __( 'Emoji to add as a reaction. Use a Unicode emoji (e.g. "👍") or a custom guild emoji in "name:id" format (e.g. "thinking:1234567890").', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'token', 'channel_id', 'message_id', 'emoji' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_add_discord_message_reaction_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to add Discord message reactions.', 'nvoos-content-graph-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_discord_token', __( 'A valid Discord bot token is required.', 'nvoos-content-graph-pro' ) );
		}

		$channel_id = isset( $arguments['channel_id'] ) ? sanitize_text_field( $arguments['channel_id'] ) : '';

		if ( '' === $channel_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_channel_id', __( 'A channel ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$message_id = isset( $arguments['message_id'] ) ? sanitize_text_field( $arguments['message_id'] ) : '';

		if ( '' === $message_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_id', __( 'A message ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$emoji_raw = isset( $arguments['emoji'] ) ? trim( (string) $arguments['emoji'] ) : '';

		if ( ! is_string( $emoji_raw ) || '' === $emoji_raw ) {
			return new WP_Error( 'wp_mcp_ai_missing_emoji', __( 'An emoji is required.', 'nvoos-content-graph-pro' ) );
		}

		// URL-encode the emoji for use in the endpoint path.
		$emoji_encoded = rawurlencode( $emoji_raw );

		// PUT /channels/{channel.id}/messages/{message.id}/reactions/{emoji}/@me
		// Discord returns 204 No Content on success.
		$endpoint = sprintf(
			'https://discord.com/api/v10/channels/%s/messages/%s/reactions/%s/@me',
			$channel_id,
			$message_id,
			$emoji_encoded
		);

		WP_MCP_AI_Logger::log_event(
			'discord_add_reaction_request',
			'Adding emoji reaction to Discord message.',
			array(
				'channel_id' => $channel_id,
				'message_id' => $message_id,
				'emoji'      => $emoji_raw,
			)
		);

		$response = wp_remote_request(
			$endpoint,
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Authorization'  => 'Bot ' . $token,
					'Content-Length' => '0',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_add_discord_message_reaction_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Discord add reaction request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_discord_http_error',
				__( 'The Discord API request failed to send.', 'nvoos-content-graph-pro' ),
				array( 'error' => $response )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		// Discord returns 204 No Content on success.
		if ( 204 === $code ) {
			return array(
				'success'    => true,
				'channel_id' => $channel_id,
				'message_id' => $message_id,
				'emoji'      => $emoji_raw,
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		$message = isset( $decoded['message'] ) ? $decoded['message'] : __( 'Discord API returned an error.', 'nvoos-content-graph-pro' );

		WP_MCP_AI_Logger::log_error(
			'Discord add reaction request was not successful.',
			array(
				'http_code'  => $code,
				'channel_id' => $channel_id,
				'message_id' => $message_id,
				'error'      => $message,
			)
		);

		return new WP_Error(
			'wp_mcp_ai_discord_api_error',
			esc_html( $message ),
			array(
				'code'     => $code,
				'response' => $decoded,
			)
		);
	}

	/**
	 * Sanitize a Discord bot token.
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                 // Pro tier tool.
			'write',               // Modifies a Discord message.
			'external-api',        // Calls Discord Bot API.
			'network-dependent',   // Requires internet connectivity.
			'requires-capability', // Requires user capabilities.
		);
	}
}
