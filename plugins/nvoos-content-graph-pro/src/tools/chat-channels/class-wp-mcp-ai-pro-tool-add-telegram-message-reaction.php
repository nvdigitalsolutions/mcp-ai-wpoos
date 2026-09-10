<?php
/**
 * tools/chat-channels/class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php (ecosystem port — Wave F5, chat-channels tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-add-telegram-message-reaction.php` for the
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
 * Provides a tool for adding emoji reactions to Telegram messages via the Bot API.
 *
 * Uses the setMessageReaction method (Bot API 7.0+) which accepts a single
 * ReactionTypeEmoji object. Custom emoji require Telegram Premium for the bot.
 * Supports both regular emoji and custom emoji IDs for lifecycle feedback phases.
 */
class WP_MCP_AI_Pro_Tool_Add_Telegram_Message_Reaction implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for Telegram API requests.
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
		return 'add_telegram_message_reaction';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Add Telegram Message Reaction', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds an emoji reaction to a Telegram message using the Bot API setMessageReaction method (Bot API 7.0+). Supports regular emoji (e.g. "👍") and custom emoji IDs for processing-phase lifecycle feedback.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Telegram bot token used for authentication.', 'nvoos-content-graph-pro' ),
				),
				'chat_id'    => array(
					'type'        => array( 'string', 'integer' ),
					'description' => __( 'Unique identifier or username of the target chat (e.g. @channelusername or a numeric chat ID).', 'nvoos-content-graph-pro' ),
				),
				'message_id' => array(
					'type'        => 'integer',
					'description' => __( 'Identifier of the target message within the chat.', 'nvoos-content-graph-pro' ),
				),
				'emoji'      => array(
					'type'        => 'string',
					'description' => __( 'Unicode emoji to use as the reaction (e.g. "👍", "🔥", "⚡"). Must be one of the Telegram-supported reaction emoji.', 'nvoos-content-graph-pro' ),
				),
				'is_big'     => array(
					'type'        => 'boolean',
					'description' => __( 'Pass true to set the reaction as a big reaction animation (default: false).', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'token', 'chat_id', 'message_id', 'emoji' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_add_telegram_message_reaction_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to add Telegram message reactions.', 'nvoos-content-graph-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_telegram_token', __( 'A valid Telegram bot token is required.', 'nvoos-content-graph-pro' ) );
		}

		// chat_id may be a numeric ID or a @username string.
		$chat_id = '';
		if ( isset( $arguments['chat_id'] ) ) {
			if ( is_int( $arguments['chat_id'] ) ) {
				$chat_id = (string) $arguments['chat_id'];
			} else {
				$chat_id = sanitize_text_field( (string) $arguments['chat_id'] );
			}
		}

		if ( '' === $chat_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_chat_id', __( 'A chat ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$message_id = isset( $arguments['message_id'] ) ? absint( $arguments['message_id'] ) : 0;

		if ( 0 === $message_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_id', __( 'A valid message ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$emoji = isset( $arguments['emoji'] ) ? sanitize_text_field( (string) $arguments['emoji'] ) : '';

		if ( '' === $emoji ) {
			return new WP_Error( 'wp_mcp_ai_missing_emoji', __( 'An emoji is required.', 'nvoos-content-graph-pro' ) );
		}

		$is_big = ! empty( $arguments['is_big'] );

		// Build setMessageReaction payload.
		$payload = array(
			'chat_id'    => $chat_id,
			'message_id' => $message_id,
			'reaction'   => array(
				array(
					'type'  => 'emoji',
					'emoji' => $emoji,
				),
			),
			'is_big'     => $is_big,
		);

		$body_json = wp_json_encode( $payload );

		if ( false === $body_json ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Telegram request payload.', 'nvoos-content-graph-pro' ) );
		}

		$endpoint = sprintf(
			'https://api.telegram.org/bot%s/setMessageReaction',
			rawurlencode( $token )
		);

		WP_MCP_AI_Logger::log_event(
			'telegram_add_reaction_request',
			'Adding emoji reaction to Telegram message.',
			array(
				'endpoint'   => 'https://api.telegram.org/bot***/setMessageReaction',
				'chat_id'    => $chat_id,
				'message_id' => $message_id,
				'emoji'      => $emoji,
				'is_big'     => $is_big,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => $body_json,
				'timeout' => apply_filters( 'wp_mcp_ai_add_telegram_message_reaction_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram setMessageReaction request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'nvoos-content-graph-pro' ),
				array( 'error' => $response )
			);
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( null === $body_decoded ) {
			$body_decoded = array();
		}

		if ( 200 !== $code || empty( $body_decoded['ok'] ) ) {
			$api_message = isset( $body_decoded['description'] ) ? $body_decoded['description'] : __( 'Telegram API returned an error.', 'nvoos-content-graph-pro' );

			WP_MCP_AI_Logger::log_error(
				'Telegram setMessageReaction request was not successful.',
				array(
					'http_code'   => $code,
					'chat_id'     => $chat_id,
					'message_id'  => $message_id,
					'api_message' => $api_message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_telegram_api_error',
				esc_html( $api_message ),
				array(
					'code'     => $code,
					'response' => $body_decoded,
				)
			);
		}

		return array(
			'success'    => true,
			'chat_id'    => $chat_id,
			'message_id' => $message_id,
			'emoji'      => $emoji,
		);
	}

	/**
	 * Sanitize a Telegram bot token.
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
			'write',               // Modifies a Telegram message.
			'external-api',        // Calls Telegram Bot API.
			'network-dependent',   // Requires internet connectivity.
			'requires-capability', // Requires user capabilities.
		);
	}
}
