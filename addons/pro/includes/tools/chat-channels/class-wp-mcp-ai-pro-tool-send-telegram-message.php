<?php
/**
 * Tool that sends a Telegram bot message.
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

// Load the trait that blocks execution from the public /chat-client endpoint.
if ( ! trait_exists( 'WP_MCP_AI_Tool_Restrict_From_Chat_Client' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-restrict-from-chat-client.php';
}

/**
 * Provides a tool for sending Telegram bot messages via the Bot API.
 *
 * Restricted from the /chat-client endpoint by default: an assistant invoked
 * from a public chat surface must not be able to push messages into a
 * Telegram chat (which would impersonate the bot owner and double-dispatch
 * agentic runs). Sites that want to allow this can pass
 * `allow_sensitive_tools=true` in the shortcode/widget settings.
 */
class WP_MCP_AI_Pro_Tool_Send_Telegram_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Context_Restrictions_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * Default timeout for Telegram requests.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Telegram Bot API limit for a single message: 1-4096 characters.
	 */
	const MAX_MESSAGE_LENGTH = 4096;

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
		return 'send_telegram_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Telegram Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends a text message to a Telegram chat using the Bot API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Sending a text message to a Telegram chat or channel using a bot token.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Reading incoming updates; use get_telegram_updates. Registering commands; use manage_telegram_commands.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'get_telegram_updates', 'manage_telegram_commands', 'manage_telegram_webhook' ),
			'notes'           => __( 'Restricted from the public chat client by default; delivers real messages to Telegram users.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'                    => array(
					'type'        => 'string',
					'description' => __( 'Telegram bot token used to authenticate the request.', 'mcp-ai-wpoos-pro' ),
				),
				'chat_id'                  => array(
					'type'        => 'string',
					'description' => __( 'Unique identifier for the target chat or username of the target channel.', 'mcp-ai-wpoos-pro' ),
				),
				'text'                     => array(
					'type'        => 'string',
					'description' => __( 'Text of the message to be sent.', 'mcp-ai-wpoos-pro' ),
				),
				'parse_mode'               => array(
					'type'        => 'string',
					'enum'        => array( 'Markdown', 'MarkdownV2', 'HTML' ),
					'description' => __( 'Optional parse mode that controls how Telegram formats entities.', 'mcp-ai-wpoos-pro' ),
				),
				'disable_web_page_preview' => array(
					'type'        => 'boolean',
					'description' => __( 'Disables link previews for links in the sent message.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'chunk'                    => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically split messages longer than 4,096 characters into multiple Telegram messages. Default true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'token', 'chat_id', 'text' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_send_telegram_message_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to send Telegram messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_telegram_token', __( 'A valid Telegram bot token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$chat_id = isset( $arguments['chat_id'] ) ? sanitize_text_field( $arguments['chat_id'] ) : '';

		if ( '' === $chat_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_chat_id', __( 'A target chat identifier is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$text = isset( $arguments['text'] ) ? $this->sanitize_message_text( $arguments['text'] ) : '';

		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_missing_message_text', __( 'Message text must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		$parse_mode = '';
		if ( isset( $arguments['parse_mode'] ) && is_string( $arguments['parse_mode'] ) ) {
			$candidate = sanitize_text_field( $arguments['parse_mode'] );

			if ( in_array( $candidate, array( 'Markdown', 'MarkdownV2', 'HTML' ), true ) ) {
				$parse_mode = $candidate;
			}
		}

		$disable_preview = ! empty( $arguments['disable_web_page_preview'] );
		$chunk           = isset( $arguments['chunk'] ) ? (bool) $arguments['chunk'] : true;

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $token ) );
		$timeout  = apply_filters( 'wp_mcp_ai_send_telegram_message_timeout', self::DEFAULT_TIMEOUT, $context, $arguments );

		// Auto-split messages that exceed Telegram's 4,096-character limit so
		// long reports (e.g. scheduled assistant-run summaries) are delivered
		// instead of failing with a 400 "message is too long".
		$chunks = array(
			array(
				'text'       => $text,
				'hard_split' => false,
			),
		);
		if ( $chunk && mb_strlen( $text ) > self::MAX_MESSAGE_LENGTH ) {
			$chunks = $this->split_message_text( $text, self::MAX_MESSAGE_LENGTH );
		}

		if ( 1 === count( $chunks ) && ! $chunks[0]['hard_split'] ) {
			return $this->send_single_message( $endpoint, $chat_id, $chunks[0]['text'], $parse_mode, $disable_preview, $timeout, false );
		}

		$sent = array();
		foreach ( $chunks as $index => $chunk_data ) {
			$result = $this->send_single_message( $endpoint, $chat_id, $chunk_data['text'], $parse_mode, $disable_preview, $timeout, $chunk_data['hard_split'] );
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'wp_mcp_ai_telegram_chunk_error',
					sprintf(
						/* translators: 1: failed chunk index, 2: total chunk count, 3: underlying error */
						__( 'Failed to send Telegram message chunk %1$d of %2$d: %3$s', 'mcp-ai-wpoos-pro' ),
						$index + 1,
						count( $chunks ),
						$result->get_error_message()
					),
					array(
						'chunk'        => $index + 1,
						'total_chunks' => count( $chunks ),
					)
				);
			}
			$sent[] = $result;
		}

		return array(
			'ok'            => true,
			'messages_sent' => count( $sent ),
			'results'       => $sent,
		);
	}

	/**
	 * Send a single Telegram message via the Bot API.
	 *
	 * @param string $endpoint         Fully-qualified sendMessage endpoint.
	 * @param string $chat_id          Target chat identifier.
	 * @param string $text             Message text (already within the length limit).
	 * @param string $parse_mode       Validated parse mode or empty string.
	 * @param bool   $disable_preview  Whether link previews are disabled.
	 * @param int    $timeout          HTTP request timeout in seconds.
	 * @param bool   $strip_parse_mode When true, send without parse_mode because a
	 *                                 hard split may have broken markup entities mid-tag,
	 *                                 which Telegram would reject with a 400.
	 * @return array|WP_Error Decoded Telegram response or error.
	 */
	protected function send_single_message( $endpoint, $chat_id, $text, $parse_mode, $disable_preview, $timeout, $strip_parse_mode = false ) {
		$effective_mode = $strip_parse_mode ? '' : $parse_mode;

		$payload = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);

		if ( '' !== $effective_mode ) {
			$payload['parse_mode'] = $effective_mode;
		}

		if ( $disable_preview ) {
			$payload['disable_web_page_preview'] = true;
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Telegram request payload.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'telegram_send_message_request',
			'Sending Telegram sendMessage request.',
			array(
				'endpoint' => 'https://api.telegram.org/bot***/sendMessage',
				'chat_id'  => $chat_id,
				'options'  => array(
					'parse_mode'               => $effective_mode,
					'disable_web_page_preview' => $disable_preview,
				),
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'timeout' => $timeout,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram sendMessage request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_telegram_http_error',
				__( 'The Telegram API request failed to send.', 'mcp-ai-wpoos-pro' ),
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
			$message = isset( $decoded['description'] ) ? $decoded['description'] : __( 'Telegram API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'Telegram sendMessage request was not successful.',
				array(
					'http_code'   => $code,
					'chat_id'     => $chat_id,
					'parse_mode'  => $effective_mode,
					'api_message' => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_telegram_api_error',
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
	 * Split message text into Telegram-sized chunks.
	 *
	 * Prefers paragraph boundaries (double newline), then line boundaries, and
	 * finally hard character splits for pathological single blocks. Hard-split
	 * chunks may break markup entities mid-tag, so they are flagged for the
	 * send loop to deliver without parse_mode.
	 *
	 * @param string $text  Full message text.
	 * @param int    $limit Maximum chunk length in characters.
	 * @return array<int,array{text:string,hard_split:bool}> Ordered chunks.
	 */
	protected function split_message_text( $text, $limit ) {
		$chunks = array();

		// Prefer whole-block splits: they keep markup entities intact.
		foreach ( array( "\n\n", "\n" ) as $separator ) {
			$blocks = explode( $separator, $text );

			$longest = 0;
			foreach ( $blocks as $block ) {
				$block_len = mb_strlen( $block );
				if ( $block_len > $longest ) {
					$longest = $block_len;
				}
			}

			if ( $longest <= $limit ) {
				$current = '';
				foreach ( $blocks as $block ) {
					if ( '' === $current ) {
						$current = $block;
					} elseif ( mb_strlen( $current . $separator . $block ) <= $limit ) {
						$current .= $separator . $block;
					} else {
						$chunks[] = array(
							'text'       => trim( $current ),
							'hard_split' => false,
						);
						$current  = $block;
					}
				}
				if ( '' !== $current ) {
					$chunks[] = array(
						'text'       => trim( $current ),
						'hard_split' => false,
					);
				}
				return $chunks;
			}
		}

		// A single block exceeds the limit: hard-split at the last whitespace
		// before the boundary (or exactly at the limit when no whitespace is
		// available). These chunks may break markup, hence the hard_split flag.
		$remaining = $text;
		while ( mb_strlen( $remaining ) > $limit ) {
			$window = mb_substr( $remaining, 0, $limit );
			$cut    = mb_strrpos( $window, ' ' );

			if ( false === $cut || $cut < (int) floor( $limit / 2 ) ) {
				$cut = $limit;
			}

			$chunks[]  = array(
				'text'       => trim( mb_substr( $remaining, 0, $cut ) ),
				'hard_split' => true,
			);
			$remaining = ltrim( mb_substr( $remaining, $cut ) );
		}

		if ( '' !== trim( $remaining ) ) {
			$chunks[] = array(
				'text'       => trim( $remaining ),
				'hard_split' => true,
			);
		}

		return $chunks;
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
	 * Sanitize Telegram message text while preserving supported markup.
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

		$allowed_html = array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'class'  => true,
				'target' => true,
			),
			'b'      => array(),
			'i'      => array(),
			'em'     => array(),
			'strong' => array(),
			'u'      => array(),
			's'      => array(),
			'code'   => array(),
			'pre'    => array(),
			'span'   => array( 'class' => true ),
		);

		$sanitized = wp_kses( $text, $allowed_html );

		return trim( $sanitized );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Sends Telegram messages.
			'external-api',         // Calls Telegram Bot API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
