<?php
/**
 * Conversation summarizer for the BME context strategy.
 *
 * Compresses older conversation turns into a compact summary that preserves
 * key decisions, facts, and constraints for long-running chat sessions.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-conversation-summarizer.php` (behaviour-preserving;
 * base copy is retained permanently — ecosystem port plan D-NOBASE).
 * Decoupling: the constructor accepts any client exposing
 * `create_chat_completion( array $messages, array $options )` instead of the
 * base plugin's `WP_MCP_AI_Language_Model_Router` type, so the port works in
 * standalone mode. Use `OrchestratorCompletionClient` to drive it from the
 * nvoos/core `ChatOrchestrator`.
 *
 * @package NvoosContentGraphAi\Chat
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates conversation summaries for the BME (Beginning-Middle-End)
 * context strategy.
 *
 * Uses the configured language model to compress older messages into
 * a compact summary that preserves semantic content while reducing
 * token usage.
 *
 * @since 1.1.0
 */
class ConversationSummarizer {

	/**
	 * Default maximum tokens for generated summaries.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_SUMMARY_TOKENS = 500;

	/**
	 * Default trigger count — when non-system messages exceed this,
	 * summarization is triggered.
	 *
	 * @var int
	 */
	const DEFAULT_TRIGGER_COUNT = 30;

	/**
	 * Completion client.
	 *
	 * Any object exposing `create_chat_completion( array $messages, array $options )`
	 * (the base plugin's language-model-router contract).
	 *
	 * @var object
	 */
	protected $router;

	/**
	 * Constructor.
	 *
	 * @param object $router Client exposing create_chat_completion( array $messages, array $options ).
	 */
	public function __construct( $router ) {
		$this->router = $router;
	}

	/**
	 * Check whether summarization should be triggered for a given message list.
	 *
	 * @param array $messages      Non-system messages.
	 * @param int   $trigger_count Threshold message count.
	 * @return bool True if summarization should run.
	 */
	public function should_summarize( array $messages, $trigger_count = 0 ): bool {
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
	public function should_summarize_tool_result( $content, $threshold = 0 ): bool {
		if ( $threshold <= 0 ) {
			return false;
		}

		return strlen( (string) $content ) > $threshold;
	}

	/**
	 * Summarize a list of messages into a compact context block.
	 *
	 * @param array $messages Messages to summarize (should be non-system).
	 * @param array $options  Optional overrides:
	 *                        - 'max_tokens' (int): Max tokens for summary output.
	 *                        - 'provider' (string): Provider slug.
	 *                        - 'model' (string): Model slug.
	 * @return string|\WP_Error Summary text or error.
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

		// Log via the base plugin's logger in monolith installs only (the
		// monorepo autoloader classmaps base classes to disk even when the
		// base plugin is inactive — gate on the boot discriminator).
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'conversation_summarization_start',
				'Starting conversation summarization.',
				array(
					'message_count' => count( $messages ),
					'text_length'   => strlen( $conversation_text ),
					'max_tokens'    => $max_tokens,
				)
			);
		}

		$result = $this->router->create_chat_completion( $summary_prompt, $options );

		if ( is_wp_error( $result ) ) {
			if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_event(
					'conversation_summarization_error',
					'Conversation summarization failed.',
					array( 'error' => $result->get_error_message() )
				);
			}
			return $result;
		}

		$summary = $this->extract_summary_from_result( (array) $result );

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'conversation_summarization_complete',
				'Conversation summarization completed.',
				array(
					'summary_length' => strlen( $summary ),
					'original_count' => count( $messages ),
				)
			);
		}

		return $summary;
	}

	/**
	 * Build a text representation of conversation messages for summarization.
	 *
	 * @param array $messages Array of message arrays with 'role' and 'content'.
	 * @return string Formatted conversation text.
	 */
	protected function build_conversation_text( array $messages ): string {
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
	protected function extract_summary_from_result( array $result ): string {
		if ( isset( $result['choices'][0]['message']['content'] ) ) {
			return trim( (string) $result['choices'][0]['message']['content'] );
		}

		if ( isset( $result['content'] ) ) {
			return trim( (string) $result['content'] );
		}

		return '';
	}
}
