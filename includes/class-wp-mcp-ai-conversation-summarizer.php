<?php
/**
 * Conversation summarizer for BME context strategy.
 *
 * Compresses older conversation turns into a compact summary that preserves
 * key decisions, facts, and constraints for long-running chat sessions.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Conversation_Summarizer' ) ) {
	/**
	 * Generates conversation summaries for the BME (Beginning-Middle-End)
	 * context strategy.
	 *
	 * Uses the configured language model to compress older messages into
	 * a compact summary that preserves semantic content while reducing
	 * token usage.
	 */
	class WP_MCP_AI_Conversation_Summarizer {

		/**
		 * Default maximum tokens for generated summaries.
		 */
		const DEFAULT_MAX_SUMMARY_TOKENS = 500;

		/**
		 * Default trigger count — when non-system messages exceed this,
		 * summarization is triggered.
		 */
		const DEFAULT_TRIGGER_COUNT = 30;

		/**
		 * Language model router instance.
		 *
		 * @var WP_MCP_AI_Language_Model_Router
		 */
		protected $router;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_Language_Model_Router $router Language model router.
		 */
		public function __construct( WP_MCP_AI_Language_Model_Router $router ) {
			$this->router = $router;
		}

		/**
		 * Check whether summarization should be triggered for a given message list.
		 *
		 * @param array $messages      Non-system messages.
		 * @param int   $trigger_count Threshold message count.
		 * @return bool True if summarization should run.
		 */
		public function should_summarize( array $messages, $trigger_count = 0 ) {
			if ( $trigger_count <= 0 ) {
				$trigger_count = self::DEFAULT_TRIGGER_COUNT;
			}

			return count( $messages ) > $trigger_count;
		}

		/**
		 * Check whether a tool result should be summarized based on character length.
		 *
		 * @param string $content   Tool result content.
		 * @param int    $threshold Character threshold.
		 * @return bool True if the tool result exceeds the threshold.
		 */
		public function should_summarize_tool_result( $content, $threshold = 0 ) {
			if ( $threshold <= 0 ) {
				return false;
			}

			return strlen( (string) $content ) > $threshold;
		}

		/**
		 * Summarize a list of messages into a compact context block.
		 *
		 * @param array $messages    Messages to summarize (should be non-system).
		 * @param array $options     Optional overrides:
		 *                           - 'max_tokens' (int): Max tokens for summary output.
		 *                           - 'provider' (string): Provider slug.
		 *                           - 'model' (string): Model slug.
		 * @return string|WP_Error  Summary text or error.
		 */
		public function summarize( array $messages, array $options = array() ) {
			if ( empty( $messages ) ) {
				return '';
			}

			$max_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : self::DEFAULT_MAX_SUMMARY_TOKENS;
			$max_tokens = max( 50, min( $max_tokens, 4000 ) );

			// Build the conversation text to summarize.
			$conversation_text = $this->build_conversation_text( $messages );

			if ( '' === $conversation_text ) {
				return '';
			}

			$summary_prompt = array(
				array(
					'role'    => 'system',
					'content' => 'You are a conversation summarizer. Your task is to compress chat history into a concise summary that preserves key context. Include: (1) key decisions made, (2) important facts or constraints established, (3) the user\'s stated preferences or goals, (4) any unresolved questions or pending tasks. Be factual — do not add information not present in the conversation.',
				),
				array(
					'role'    => 'user',
					'content' => sprintf(
						"Summarize the following conversation history concisely. Preserve key decisions, facts established, and any important context that might be needed later.\n\n---\n\n%s\n\n---\n\nProvide a compact summary (under %d words):",
						$conversation_text,
						$max_tokens
					),
				),
			);

			WP_MCP_AI_Logger::log_event(
				'conversation_summarization_start',
				'Starting conversation summarization.',
				array(
					'message_count' => count( $messages ),
					'text_length'   => strlen( $conversation_text ),
					'max_tokens'    => $max_tokens,
				)
			);

			$result = $this->router->create_chat_completion( $summary_prompt, $options );

			if ( is_wp_error( $result ) ) {
				WP_MCP_AI_Logger::log_event(
					'conversation_summarization_error',
					'Conversation summarization failed.',
					array( 'error' => $result->get_error_message() )
				);
				return $result;
			}

			// Providers can return null (or another non-array shape) when a
			// model returns no usable response; treat it as a failed summary
			// instead of fatalling on the array type hint below.
			if ( ! is_array( $result ) ) {
				return new WP_Error(
					'wp_mcp_ai_summarization_empty',
					__( 'Conversation summarization returned an empty response.', 'mcp-ai-wpoos' )
				);
			}

			$summary = $this->extract_summary_from_result( $result );

			WP_MCP_AI_Logger::log_event(
				'conversation_summarization_complete',
				'Conversation summarization completed.',
				array(
					'summary_length' => strlen( $summary ),
					'original_count' => count( $messages ),
				)
			);

			return $summary;
		}

		/**
		 * Build a text representation of conversation messages for summarization.
		 *
		 * @param array $messages Array of message arrays with 'role' and 'content'.
		 * @return string Formatted conversation text.
		 */
		protected function build_conversation_text( array $messages ) {
			$lines = array();

			foreach ( $messages as $message ) {
				if ( ! isset( $message['role'], $message['content'] ) ) {
					continue;
				}

				$role = sanitize_key( $message['role'] );

				// Skip system messages — they're preserved separately.
				if ( 'system' === $role ) {
					continue;
				}

				$content = $message['content'];

				// Handle array content (multi-modal messages).
				if ( is_array( $content ) ) {
					$text_parts = array();
					foreach ( $content as $segment ) {
						if ( is_string( $segment ) ) {
							$text_parts[] = $segment;
						} elseif ( isset( $segment['text'] ) ) {
							$text_parts[] = $segment['text'];
						}
					}
					$content = implode( ' ', $text_parts );
				}

				$content = (string) $content;

				// Truncate very long individual messages in the summary text.
				if ( strlen( $content ) > 2000 ) {
					$content = substr( $content, 0, 2000 ) . '… [truncated]';
				}

				// Use a compact label for readability.
				$label   = strtoupper( $role );
				$lines[] = "{$label}: {$content}";
			}

			return implode( "\n\n", $lines );
		}

		/**
		 * Extract the summary text from a chat completion result.
		 *
		 * @param array $result Chat completion result array.
		 * @return string Summary text.
		 */
		protected function extract_summary_from_result( array $result ) {
			if ( isset( $result['choices'][0]['message']['content'] ) ) {
				return trim( (string) $result['choices'][0]['message']['content'] );
			}

			if ( isset( $result['content'] ) ) {
				return trim( (string) $result['content'] );
			}

			return '';
		}
	}
}
