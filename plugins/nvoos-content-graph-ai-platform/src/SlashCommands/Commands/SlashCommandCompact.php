<?php
/**
 * Compact Slash Command
 *
 * Proactive context management — summarizes conversation history
 * to free up token budget while preserving key information.
 *
 * Inspired by Claude Code's /compact command concept: let users
 * explicitly manage context pressure instead of relying solely
 * on automatic truncation.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact Command Class
 *
 * Provides proactive context compaction for chat sessions.
 * Supports multiple strategies: summarize (default), trim-tools,
 * keep-recent, and full.
 *
 * @since 1.5.0
 */
class SlashCommandCompact {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Default number of recent messages to keep.
	 *
	 * @var int
	 */
	const DEFAULT_KEEP_RECENT = 6;

	/**
	 * Approximate characters per token for estimation.
	 *
	 * @var int
	 */
	const CHARS_PER_TOKEN = 4;

	/**
	 * Execute compact command
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return array|WP_Error Compaction result or error.
	 */
	public function execute( $args, $flags, $context ) {
		$strategy    = isset( $flags['strategy'] ) ? sanitize_key( $flags['strategy'] ) : 'summarize';
		$keep_recent = isset( $flags['keep'] ) ? absint( $flags['keep'] ) : self::DEFAULT_KEEP_RECENT;

		$valid_strategies = array( 'summarize', 'trim-tools', 'keep-recent', 'full', 'caveman' );
		if ( ! in_array( $strategy, $valid_strategies, true ) ) {
			return new \WP_Error(
				'invalid_strategy',
				sprintf(
					/* translators: %s: list of valid strategies */
					__( 'Invalid strategy. Valid options: %s', 'nvoos-content-graph-ai-platform' ),
					implode( ', ', $valid_strategies )
				)
			);
		}

		$messages      = isset( $context['messages'] ) ? $context['messages'] : array();
		$assistant_id  = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		$message_count = count( $messages );

		if ( $message_count < 3 ) {
			return array(
				'success' => true,
				'message' => __( 'Context is already compact. No action needed.', 'nvoos-content-graph-ai-platform' ),
				'data'    => array(
					'messages_before' => $message_count,
					'messages_after'  => $message_count,
					'strategy'        => 'none',
					'tokens_saved'    => 0,
				),
			);
		}

		switch ( $strategy ) {
			case 'trim-tools':
				return $this->strategy_trim_tools( $messages, $keep_recent, $assistant_id );

			case 'keep-recent':
				return $this->strategy_keep_recent( $messages, $keep_recent, $assistant_id );

			case 'full':
				return $this->strategy_full_compact( $messages, $assistant_id );

			case 'caveman':
				return $this->strategy_caveman( $messages, $keep_recent, $assistant_id );

			case 'summarize':
			default:
				return $this->strategy_summarize( $messages, $keep_recent, $assistant_id );
		}
	}

	/**
	 * Summarize strategy: keep recent messages, summarize older ones.
	 *
	 * Creates a summary of older messages and keeps the most recent
	 * ones intact. This is the default and recommended strategy.
	 *
	 * @param array $messages      Conversation messages.
	 * @param int   $keep_recent   Number of recent messages to preserve.
	 * @param int   $assistant_id  Assistant post ID.
	 * @return array Compaction result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function strategy_summarize( $messages, $keep_recent, $assistant_id ) {
		$message_count  = count( $messages );
		$keep_recent    = min( $keep_recent, $message_count );
		$older_messages = array_slice( $messages, 0, $message_count - $keep_recent );
		$kept_messages  = array_slice( $messages, $message_count - $keep_recent );

		if ( empty( $older_messages ) ) {
			return array(
				'success' => true,
				'message' => __( 'No older messages to summarize.', 'nvoos-content-graph-ai-platform' ),
				'data'    => array(
					'messages_before' => $message_count,
					'messages_after'  => $message_count,
					'strategy'        => 'summarize',
					'tokens_saved'    => 0,
				),
			);
		}

		// Build summary of older messages.
		$summary_parts = array();
		$topics        = array();
		$decisions     = array();
		$tool_results  = array();

		foreach ( $older_messages as $msg ) {
			$role    = isset( $msg['role'] ) ? $msg['role'] : '';
			$content = isset( $msg['content'] ) ? $msg['content'] : '';

			if ( 'tool' === $role ) {
				$tool_name      = isset( $msg['name'] ) ? $msg['name'] : 'unknown';
				$tool_results[] = $tool_name;
			} elseif ( 'user' === $role && ! empty( $content ) ) {
				$topics[] = $this->extract_topic( $content );
			} elseif ( 'assistant' === $role && ! empty( $content ) ) {
				$decision = $this->extract_decision( $content );
				if ( $decision ) {
					$decisions[] = $decision;
				}
			}
		}

		// Build compact summary.
		$summary = __( '**[Context Summary]** ', 'nvoos-content-graph-ai-platform' );

		if ( ! empty( $topics ) ) {
			$unique_topics = array_unique( array_filter( $topics ) );
			if ( ! empty( $unique_topics ) ) {
				$summary .= sprintf(
					/* translators: %s: list of topics discussed */
					__( 'Topics discussed: %s. ', 'nvoos-content-graph-ai-platform' ),
					implode( ', ', array_slice( $unique_topics, 0, 10 ) )
				);
			}
		}

		if ( ! empty( $tool_results ) ) {
			$tool_counts = array_count_values( $tool_results );
			$tool_list   = array();
			foreach ( $tool_counts as $tool => $count ) {
				$tool_list[] = sprintf( '%s (%d)', $tool, $count );
			}
			$summary .= sprintf(
				/* translators: %s: list of tools used with counts */
				__( 'Tools used: %s. ', 'nvoos-content-graph-ai-platform' ),
				implode( ', ', array_slice( $tool_list, 0, 10 ) )
			);
		}

		if ( ! empty( $decisions ) ) {
			$summary .= sprintf(
				/* translators: %s: list of key decisions */
				__( 'Key points: %s. ', 'nvoos-content-graph-ai-platform' ),
				implode( '; ', array_slice( $decisions, 0, 5 ) )
			);
		}

		$summary .= sprintf(
			/* translators: %d: number of messages that were summarized */
			__( '(%d messages compacted)', 'nvoos-content-graph-ai-platform' ),
			count( $older_messages )
		);

		$chars_before = $this->estimate_chars( $older_messages );
		$chars_after  = strlen( $summary );
		$tokens_saved = max( 0, intval( ( $chars_before - $chars_after ) / self::CHARS_PER_TOKEN ) );

		// Build compacted message array.
		$compacted = array_merge(
			array(
				array(
					'role'    => 'system',
					'content' => $summary,
				),
			),
			$kept_messages
		);

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: messages before, 2: messages after, 3: tokens saved */
				__( 'Compacted: %1$d → %2$d messages (~%3$s tokens saved). Strategy: summarize.', 'nvoos-content-graph-ai-platform' ),
				$message_count,
				count( $compacted ),
				number_format_i18n( $tokens_saved )
			),
			'data'    => array(
				'messages_before'    => $message_count,
				'messages_after'     => count( $compacted ),
				'strategy'           => 'summarize',
				'tokens_saved'       => $tokens_saved,
				'compacted_messages' => $compacted,
				'summary'            => $summary,
			),
		);
	}

	/**
	 * Trim-tools strategy: remove tool call/result pairs from older messages.
	 *
	 * Keeps user and assistant messages but strips tool invocation details
	 * which are often the largest context consumers.
	 *
	 * @param array $messages      Conversation messages.
	 * @param int   $keep_recent   Number of recent messages to preserve.
	 * @param int   $assistant_id  Assistant post ID.
	 * @return array Compaction result.
		 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function strategy_trim_tools( $messages, $keep_recent, $assistant_id ) {
		$message_count  = count( $messages );
		$keep_recent    = min( $keep_recent, $message_count );
		$older_messages = array_slice( $messages, 0, $message_count - $keep_recent );
		$kept_messages  = array_slice( $messages, $message_count - $keep_recent );

		// Filter out tool messages from older messages.
		$filtered = array();
		foreach ( $older_messages as $msg ) {
			$role = isset( $msg['role'] ) ? $msg['role'] : '';
			if ( 'tool' === $role ) {
				continue;
			}
			// Strip tool_calls from assistant messages.
			if ( 'assistant' === $role && isset( $msg['tool_calls'] ) ) {
				unset( $msg['tool_calls'] );
			}
			$filtered[] = $msg;
		}

		$compacted    = array_merge( $filtered, $kept_messages );
		$removed      = $message_count - count( $compacted );
		$chars_saved  = $this->estimate_chars( $older_messages ) - $this->estimate_chars( $filtered );
		$tokens_saved = max( 0, intval( $chars_saved / self::CHARS_PER_TOKEN ) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: messages removed, 2: tokens saved */
				__( 'Trimmed %1$d tool messages (~%2$s tokens saved). Strategy: trim-tools.', 'nvoos-content-graph-ai-platform' ),
				$removed,
				number_format_i18n( $tokens_saved )
			),
			'data'    => array(
				'messages_before'    => $message_count,
				'messages_after'     => count( $compacted ),
				'strategy'           => 'trim-tools',
				'tokens_saved'       => $tokens_saved,
				'compacted_messages' => $compacted,
			),
		);
	}

	/**
	 * Keep-recent strategy: drop all but the most recent N messages.
	 *
	 * The simplest strategy — just truncates older messages entirely.
	 *
	 * @param array $messages      Conversation messages.
	 * @param int   $keep_recent   Number of recent messages to keep.
	 * @param int   $assistant_id  Assistant post ID.
		 * @return array Compaction result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function strategy_keep_recent( $messages, $keep_recent, $assistant_id ) {
		$message_count = count( $messages );
		$keep_recent   = min( $keep_recent, $message_count );
		$compacted     = array_slice( $messages, $message_count - $keep_recent );
		$removed       = $message_count - count( $compacted );

		$dropped_messages = array_slice( $messages, 0, $removed );
		$tokens_saved     = max( 0, intval( $this->estimate_chars( $dropped_messages ) / self::CHARS_PER_TOKEN ) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: messages kept, 2: messages dropped, 3: tokens saved */
				__( 'Kept %1$d recent messages, dropped %2$d (~%3$s tokens saved). Strategy: keep-recent.', 'nvoos-content-graph-ai-platform' ),
				count( $compacted ),
				$removed,
				number_format_i18n( $tokens_saved )
			),
			'data'    => array(
				'messages_before'    => $message_count,
				'messages_after'     => count( $compacted ),
				'strategy'           => 'keep-recent',
				'tokens_saved'       => $tokens_saved,
				'compacted_messages' => $compacted,
			),
		);
	}

	/**
	 * Full compact strategy: summarize everything into a single context message.
	 *
	 * Most aggressive compaction. Replaces the entire conversation with
	 * a structured summary. Use when context budget is critically low.
	 *
	 * @param array $messages     Conversation messages.
		 * @param int   $assistant_id Assistant post ID.
	 * @return array Compaction result.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	private function strategy_full_compact( $messages, $assistant_id ) {
		$message_count = count( $messages );
		$topics        = array();
		$tools_used    = array();
		$key_facts     = array();
		$last_user_msg = '';

		foreach ( $messages as $msg ) {
			$role    = isset( $msg['role'] ) ? $msg['role'] : '';
			$content = isset( $msg['content'] ) ? $msg['content'] : '';

			if ( 'user' === $role && ! empty( $content ) ) {
				$topics[]      = $this->extract_topic( $content );
				$last_user_msg = $content;
			} elseif ( 'tool' === $role ) {
				$tool_name    = isset( $msg['name'] ) ? $msg['name'] : 'unknown';
				$tools_used[] = $tool_name;
			} elseif ( 'assistant' === $role && ! empty( $content ) ) {
				$fact = $this->extract_decision( $content );
				if ( $fact ) {
					$key_facts[] = $fact;
				}
			}
		}

		$summary = __( '**[Full Session Summary]** ', 'nvoos-content-graph-ai-platform' );

		$unique_topics = array_unique( array_filter( $topics ) );
		if ( ! empty( $unique_topics ) ) {
			$summary .= sprintf(
				/* translators: %s: list of topics */
				__( 'Session covered: %s. ', 'nvoos-content-graph-ai-platform' ),
				implode( ', ', array_slice( $unique_topics, 0, 8 ) )
			);
		}

		if ( ! empty( $tools_used ) ) {
			$tool_counts = array_count_values( $tools_used );
			$tool_list   = array();
			foreach ( $tool_counts as $tool => $count ) {
				$tool_list[] = sprintf( '%s (%d)', $tool, $count );
			}
			$summary .= sprintf(
				/* translators: %s: list of tools */
				__( 'Tools invoked: %s. ', 'nvoos-content-graph-ai-platform' ),
				implode( ', ', array_slice( $tool_list, 0, 8 ) )
			);
		}

		if ( ! empty( $key_facts ) ) {
			$summary .= sprintf(
				/* translators: %s: key facts */
				__( 'Key outcomes: %s. ', 'nvoos-content-graph-ai-platform' ),
				implode( '; ', array_slice( $key_facts, 0, 5 ) )
			);
		}

		$summary .= sprintf(
			/* translators: %d: original message count */
			__( 'Original session: %d messages.', 'nvoos-content-graph-ai-platform' ),
			$message_count
		);

		$compacted = array(
			array(
				'role'    => 'system',
				'content' => $summary,
			),
		);

		// Keep the last user message for continuity.
		if ( ! empty( $last_user_msg ) ) {
			$compacted[] = array(
				'role'    => 'user',
				'content' => $last_user_msg,
			);
		}

		$tokens_saved = max( 0, intval( ( $this->estimate_chars( $messages ) - $this->estimate_chars( $compacted ) ) / self::CHARS_PER_TOKEN ) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: original message count, 2: compacted count, 3: tokens saved */
				__( 'Full compact: %1$d → %2$d messages (~%3$s tokens saved). Strategy: full.', 'nvoos-content-graph-ai-platform' ),
				$message_count,
				count( $compacted ),
				number_format_i18n( $tokens_saved )
			),
			'data'    => array(
				'messages_before'    => $message_count,
				'messages_after'     => count( $compacted ),
				'strategy'           => 'full',
				'tokens_saved'       => $tokens_saved,
				'compacted_messages' => $compacted,
				'summary'            => $summary,
			),
		);
	}

	/**
	 * Caveman strategy: apply semantic compression to the conversation summary.
	 *
	 * Uses the caveman compression technique to strip grammar and filler
	 * from the context summary, making it dramatically more token-efficient
	 * while preserving all facts and decisions.
	 *
	 * @param array $messages     Conversation messages.
	 * @param int   $keep_recent  Number of recent messages to preserve.
	 * @param int   $assistant_id Assistant post ID.
	 * @return array Compaction result.
	 */
	private function strategy_caveman( $messages, $keep_recent, $assistant_id ) {
		$message_count = count( $messages );
		$keep_recent   = min( $keep_recent, $message_count );

		// Build a summary of the full conversation first.
		$full_result = $this->strategy_summarize( $messages, $keep_recent, $assistant_id );

		if ( ! empty( $full_result['data']['summary'] ) ) {
			// Compress the summary text using semantic compressor.
			$compressor = false;
			if ( function_exists( 'wp_mcp_ai_get_semantic_compressor' ) ) {
				$compressor = wp_mcp_ai_get_semantic_compressor();
			}

			if ( $compressor ) {
				$original_summary   = $full_result['data']['summary'];
				$compressed_summary = $compressor->compress(
					$original_summary,
					array( 'aggressiveness' => 2 )
				);

				// Update the compacted messages with the caveman-compressed summary.
				if ( ! empty( $full_result['data']['compacted_messages'] ) ) {
					foreach ( $full_result['data']['compacted_messages'] as &$msg ) {
						if ( 'system' === ( isset( $msg['role'] ) ? $msg['role'] : '' )
							&& isset( $msg['content'] )
							&& $original_summary === $msg['content'] ) {
							$msg['content'] = $compressed_summary;
							break;
						}
					}
					unset( $msg );
				}

				// Recalculate savings.
				$chars_before = strlen( $original_summary );
				$chars_after  = strlen( $compressed_summary );
				$extra_saved  = max( 0, intval( ( $chars_before - $chars_after ) / self::CHARS_PER_TOKEN ) );
				$total_saved  = $full_result['data']['tokens_saved'] + $extra_saved;

				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: original messages, 2: compacted count, 3: tokens saved */
						__( 'Caveman compact: %1$d → %2$d messages (~%3$s tokens saved). Strategy: caveman.', 'nvoos-content-graph-ai-platform' ),
						$message_count,
						count( $full_result['data']['compacted_messages'] ),
						number_format_i18n( $total_saved )
					),
					'data'    => array(
						'messages_before'       => $message_count,
						'messages_after'        => count( $full_result['data']['compacted_messages'] ),
						'strategy'              => 'caveman',
						'tokens_saved'          => $total_saved,
						'compacted_messages'    => $full_result['data']['compacted_messages'],
						'summary'               => $compressed_summary,
						'extra_caveman_savings' => $extra_saved,
					),
				);
			}
		}

		// Fallback: return the summarize result if compressor not available.
		$full_result['data']['strategy'] = 'caveman';
		$full_result['message']          = sprintf(
			/* translators: 1: original message count, 2: compacted count, 3: tokens saved */
			__( 'Caveman compact (fallback): %1$d → %2$d messages (~%3$s tokens saved). Strategy: caveman.', 'nvoos-content-graph-ai-platform' ),
			$message_count,
			count( $full_result['data']['compacted_messages'] ),
			number_format_i18n( $full_result['data']['tokens_saved'] )
		);

		return $full_result;
	}

	/**
	 * Extract a brief topic from a user message.
	 *
	 * @param string $content Message content.
	 * @return string Brief topic description.
	 */
	private function extract_topic( $content ) {
		// Take the first sentence or first 80 characters.
		$content = wp_strip_all_tags( $content );

		// Find first sentence boundary.
		$end = strpos( $content, '.' );
		if ( false !== $end && $end < 120 ) {
			return trim( substr( $content, 0, $end + 1 ) );
		}

		// Fall back to first 80 characters.
		if ( strlen( $content ) > 80 ) {
			return trim( substr( $content, 0, 80 ) ) . '...';
		}

		return trim( $content );
	}

	/**
	 * Extract a key decision or fact from an assistant message.
	 *
	 * Looks for decisive language patterns.
	 *
	 * @param string $content Assistant message content.
	 * @return string|null Extracted decision or null.
	 */
	private function extract_decision( $content ) {
		$content = wp_strip_all_tags( $content );

		// Look for decisive patterns.
		$patterns = array(
			'/(?:I\'ve|I have) (?:created|updated|deleted|configured|set up|enabled|disabled) (.{10,80})/i',
			'/(?:successfully|completed|done|finished)[:\s]+(.{10,80})/i',
			'/(?:the result|outcome|summary)[:\s]+(.{10,80})/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $content, $matches ) ) {
				return trim( $matches[0] );
			}
		}

		return null;
	}

	/**
	 * Estimate total character count for an array of messages.
	 *
	 * @param array $messages Messages to estimate.
	 * @return int Total characters.
	 */
	private function estimate_chars( $messages ) {
		$total = 0;
		foreach ( $messages as $msg ) {
			if ( isset( $msg['content'] ) ) {
				$total += strlen( is_string( $msg['content'] ) ? $msg['content'] : wp_json_encode( $msg['content'] ) );
			}
			if ( isset( $msg['tool_calls'] ) ) {
				$total += strlen( wp_json_encode( $msg['tool_calls'] ) );
			}
		}
		return $total;
	}
}
