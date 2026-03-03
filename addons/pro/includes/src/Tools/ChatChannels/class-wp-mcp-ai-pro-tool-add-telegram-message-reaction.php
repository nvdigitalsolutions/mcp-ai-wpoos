<?php
/**
 * Tool that adds an emoji reaction to a Telegram message.
 *
 * Telegram's setMessageReaction Bot API method (available since Bot API 7.0,
 * supported in OpenClaw's 2026 feature set) allows bots to place up to one
 * active emoji reaction per message – matching OpenClaw's lifecycle-feedback
 * pattern used for processing-phase transparency.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

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
		return __( 'Add Telegram Message Reaction', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Adds an emoji reaction to a Telegram message using the Bot API setMessageReaction method (Bot API 7.0+). Supports regular emoji (e.g. "👍") and custom emoji IDs for processing-phase lifecycle feedback.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'          => array(
					'type'        => 'string',
					'description' => __( 'Telegram bot token used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'chat_id'        => array(
					'type'        => array( 'string', 'integer' ),
					'description' => __( 'Unique identifier or username of the target chat (e.g. @channelusername or a numeric chat ID).', 'mcp-ai-wpoos-pro' ),
				),
				'message_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Identifier of the target message within the chat.', 'mcp-ai-wpoos-pro' ),
				),
				'emoji'          => array(
					'type'        => 'string',
					'description' => __( 'Unicode emoji to use as the reaction (e.g. "👍", "🔥", "⚡"). Must be one of the Telegram-supported reaction emoji.', 'mcp-ai-wpoos-pro' ),
				),
				'is_big'         => array(
					'type'        => 'boolean',
					'description' => __( 'Pass true to set the reaction as a big reaction animation (default: false).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'token', 'chat_id', 'message_id', 'emoji' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_add_telegram_message_reaction_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to add Telegram message reactions.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_telegram_token', __( 'A valid Telegram bot token is required.', 'mcp-ai-wpoos-pro' ) );
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
			return new WP_Error( 'wp_mcp_ai_missing_chat_id', __( 'A chat ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$message_id = isset( $arguments['message_id'] ) ? absint( $arguments['message_id'] ) : 0;

		if ( 0 === $message_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_id', __( 'A valid message ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$emoji = isset( $arguments['emoji'] ) ? sanitize_text_field( (string) $arguments['emoji'] ) : '';

		if ( '' === $emoji ) {
			return new WP_Error( 'wp_mcp_ai_missing_emoji', __( 'An emoji is required.', 'mcp-ai-wpoos-pro' ) );
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
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Telegram request payload.', 'mcp-ai-wpoos-pro' ) );
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
				__( 'The Telegram API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$body_decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( null === $body_decoded ) {
			$body_decoded = array();
		}

		if ( 200 !== $code || empty( $body_decoded['ok'] ) ) {
			$api_message = isset( $body_decoded['description'] ) ? $body_decoded['description'] : __( 'Telegram API returned an error.', 'mcp-ai-wpoos-pro' );

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
