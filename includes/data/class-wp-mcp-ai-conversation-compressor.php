<?php
/**
 * Conversation Compressor — Sliding-window attention for multi-turn dialogue.
 *
 * Inspired by the sliding-window attention pattern used in modern Transformer
 * architectures (Mistral, Longformer): keep the most recent N messages at full
 * fidelity while summarizing older messages into compact, multi-aspect summaries.
 *
 * Each "aspect" summary is like an attention head — it compresses the history
 * along a different semantic dimension (decisions, facts, questions), so the
 * LLM can attend to the right dimension based on the current query.
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conversation compression with sliding window + multi-aspect summaries.
 *
 *   ┌─────────────────────────────────────────────┐
 *   │ System Prompt (static, KV-cache friendly)    │
 *   ├─────────────────────────────────────────────┤
 *   │ [Summary: Key Decisions]  [Summary: Facts]   │
 *   │ [Summary: Open Questions]                    │
 *   ├─────────────────────────────────────────────┤
 *   │ [Last N messages — full fidelity window]     │
 *   ├─────────────────────────────────────────────┤
 *   │ Current user query                            │
 *   └─────────────────────────────────────────────┘
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Conversation_Compressor {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Conversation_Compressor|null
	 */
	private static $instance = null;

	/**
	 * Default sliding window size (number of recent messages kept in full).
	 *
	 * @var int
	 */
	const DEFAULT_WINDOW_SIZE = 10;

	/**
	 * Maximum tokens for compressed summaries (total across all aspects).
	 *
	 * @var int
	 */
	const MAX_SUMMARY_TOKENS = 800;

	/**
	 * Aspect labels for multi-head summarization.
	 *
	 * Each aspect produces its own compressed view of the conversation.
	 *
	 * @var array<string, string>
	 */
	const SUMMARY_ASPECTS = array(
		'decisions' => 'Key Decisions Made',
		'facts'     => 'Facts & Data Established',
		'questions' => 'Open Questions & Next Steps',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Conversation_Compressor
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Apply sliding-window compression to a message array.
	 *
	 * Keeps the most recent $window_size messages in full and replaces older
	 * messages with compact multi-aspect summaries.
	 *
	 * @since 1.8.0
	 *
	 * @param array $messages    Chat messages array (role/content pairs).
	 * @param array $options {
	 *     Optional overrides.
	 *
	 *     @type int  $window_size     Number of recent messages to keep (default 10).
	 *     @type int  $max_summary_tokens Token budget for summaries (default 800).
	 *     @type bool $enabled          Whether compression is active (default true).
	 *     @type bool $use_llm          Use LLM for summaries (default true, falls back to extractive).
	 * }
	 * @return array Compressed messages array.
	 */
	public function compress( array $messages, array $options = array() ) {
		$enabled            = isset( $options['enabled'] ) ? (bool) $options['enabled'] : true;
		$window_size        = isset( $options['window_size'] ) ? absint( $options['window_size'] ) : self::DEFAULT_WINDOW_SIZE;
		$max_summary_tokens = isset( $options['max_summary_tokens'] ) ? absint( $options['max_summary_tokens'] ) : self::MAX_SUMMARY_TOKENS;

		if ( ! $enabled || empty( $messages ) ) {
			return $messages;
		}

		// Separate system messages (never compressed — they're the stable prefix).
		$system_messages = array();
		$user_messages   = array();

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) || ! isset( $msg['role'] ) ) {
				continue;
			}
			if ( 'system' === $msg['role'] ) {
				$system_messages[] = $msg;
			} else {
				$user_messages[] = $msg;
			}
		}

		$total_user_messages = count( $user_messages );

		// If messages fit within the window, no compression needed.
		if ( $total_user_messages <= $window_size ) {
			return $messages;
		}

		// Split: older messages get compressed, recent ones stay.
		$split_point     = $total_user_messages - $window_size;
		$older_messages  = array_slice( $user_messages, 0, $split_point );
		$recent_messages = array_slice( $user_messages, $split_point );

		// Generate multi-aspect summaries for older messages.
		$summaries = $this->summarise( $older_messages, $max_summary_tokens, $options );

		// Assemble: system → summaries → recent window.
		$compressed = array_merge( $system_messages, $summaries, $recent_messages );

		return $compressed;
	}

	/**
	 * Estimate the token count of a messages array.
	 *
	 * Uses the same character-based heuristic as the rest of the plugin.
	 *
	 * @since 1.8.0
	 *
	 * @param array $messages Chat messages.
	 * @return int Estimated token count.
	 */
	public function estimate_message_tokens( array $messages ) {
		$total = 0;
		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) || ! isset( $msg['content'] ) ) {
				continue;
			}
			$content = is_string( $msg['content'] ) ? $msg['content'] : wp_json_encode( $msg['content'] );
			$total  += (int) ceil( strlen( $content ) / 4 );
		}
		return $total;
	}

	/**
	 * Check whether compression would be beneficial.
	 *
	 * @since 1.8.0
	 *
	 * @param array $messages Messages array.
	 * @param int   $window_size Window size.
	 * @return bool True if compression would reduce message count.
	 */
	public function is_beneficial( array $messages, $window_size = null ) {
		if ( null === $window_size ) {
			$window_size = self::DEFAULT_WINDOW_SIZE;
		}

		$non_system = 0;
		foreach ( $messages as $msg ) {
			if ( is_array( $msg ) && isset( $msg['role'] ) && 'system' !== $msg['role'] ) {
				++$non_system;
			}
		}

		return $non_system > $window_size;
	}

	// -------------------------------------------------------------------------
	// Summarization
	// -------------------------------------------------------------------------

	/**
	 * Generate multi-aspect summaries for a set of messages.
	 *
	 * Produces one compact summary per aspect (decisions, facts, questions).
	 * Falls back to extractive summarization when the LLM is unavailable.
	 *
	 * @since 1.8.0
	 *
	 * @param array $older_messages    Messages to summarize.
	 * @param int   $max_summary_tokens Token budget for all summaries.
	 * @param array $options           Compression options.
	 * @return array Summary messages as system role.
	 */
	private function summarise( array $older_messages, $max_summary_tokens, array $options ) {
		$use_llm = isset( $options['use_llm'] ) ? (bool) $options['use_llm'] : true;

		// Extract text content from older messages.
		$combined_text = $this->extract_conversation_text( $older_messages );

		if ( empty( $combined_text ) ) {
			return array();
		}

		// Try LLM-based summarization first.
		if ( $use_llm && function_exists( 'wp_mcp_ai_get_language_model_router' ) ) {
			$llm_summaries = $this->llm_summarise( $combined_text, $max_summary_tokens );
			if ( ! empty( $llm_summaries ) ) {
				return $llm_summaries;
			}
		}

		// Fallback: extractive summarization (no LLM needed).
		return $this->extractive_summarise( $combined_text, $older_messages, $max_summary_tokens );
	}

	/**
	 * LLM-based multi-aspect summarization.
	 *
	 * Asks a small, cheap model to produce structured summaries.
	 *
	 * @since 1.8.0
	 *
	 * @param string $combined_text      Combined conversation text.
	 * @param int    $max_summary_tokens Token budget.
	 * @return array Summary messages, or empty on failure.
	 */
	private function llm_summarise( $combined_text, $max_summary_tokens ) {
		try {
			$router = wp_mcp_ai_get_language_model_router();

			$aspect_names = array();
			foreach ( self::SUMMARY_ASPECTS as $key => $label ) {
				$aspect_names[] = '- ' . $label;
			}
			$aspect_list = implode( "\n", $aspect_names );

			$prompt = sprintf(
				"Summarize the following conversation history concisely. Produce exactly these sections:\n%s\n\nKeep each section to 2-3 bullet points maximum. Be factual and brief. Do not include opinions.\n\nConversation:\n%s",
				$aspect_list,
				$combined_text
			);

			$response = $router->create_chat_completion(
				array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				array(
					'max_tokens'  => min( $max_summary_tokens, 500 ),
					'temperature' => 0.2,
					'provider'    => 'openai',
				)
			);

			if ( is_wp_error( $response ) || empty( $response['choices'][0]['message']['content'] ) ) {
				return array();
			}

			$summary_text = $response['choices'][0]['message']['content'];

			// Parse structured summary into aspects.
			return $this->parse_structured_summary( $summary_text );

		} catch ( Exception $e ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'LLM conversation summarization failed.',
					array( 'error' => $e->getMessage() )
				);
			}
			return array();
		}
	}

	/**
	 * Fallback: extractive summarization using keyword extraction.
	 *
	 * Extracts the most information-dense sentences based on term frequency.
	 * No external API calls needed.
	 *
	 * @since 1.8.0
	 *
	 * @param string $combined_text      Combined conversation text.
	 * @param array  $messages           Original messages (for role-aware extraction).
	 * @param int    $max_summary_tokens Token budget.
	 * @return array Summary messages.
	 */
	private function extractive_summarise( $combined_text, array $messages, $max_summary_tokens ) {
		$token_budget_per_aspect = (int) ( $max_summary_tokens / count( self::SUMMARY_ASPECTS ) );
		$char_budget_per_aspect  = $token_budget_per_aspect * 4;

		$summaries = array();

		foreach ( self::SUMMARY_ASPECTS as $key => $label ) {
			// Extract sentences most relevant to this aspect based on keyword matching.
			$aspect_keywords = $this->get_aspect_keywords( $key );
			$extracted       = $this->extract_relevant_sentences( $messages, $aspect_keywords, $char_budget_per_aspect );

			if ( ! empty( $extracted ) ) {
				$summaries[] = array(
					'role'    => 'system',
					'content' => sprintf(
						'[Conversation Summary: %s] %s',
						$label,
						$extracted
					),
				);
			}
		}

		return $summaries;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract plain text from conversation messages.
	 *
	 * @since 1.8.0
	 *
	 * @param array $messages Messages array.
	 * @return string Concatenated text.
	 */
	private function extract_conversation_text( array $messages ) {
		$lines = array();
		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) || empty( $msg['content'] ) ) {
				continue;
			}
			$role    = isset( $msg['role'] ) ? $msg['role'] : 'unknown';
			$content = is_string( $msg['content'] ) ? $msg['content'] : '';

			if ( '' !== $content ) {
				// Truncate very long messages to their first 500 chars for summary.
				$truncated = strlen( $content ) > 500
					? substr( $content, 0, 500 ) . '...'
					: $content;
				$lines[]   = sprintf( '[%s]: %s', $role, $truncated );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Parse a structured LLM summary into per-aspect system messages.
	 *
	 * @since 1.8.0
	 *
	 * @param string $summary_text Raw LLM output.
	 * @return array Per-aspect system messages.
	 */
	private function parse_structured_summary( $summary_text ) {
		$summaries = array();

		foreach ( self::SUMMARY_ASPECTS as $key => $label ) {
			// Try to find the labeled section in the LLM output.
			$pattern = '/\b' . preg_quote( $label, '/' ) . '\b[:\-]?\s*\n?(.*?)(?=\n\s*(?:\b(?:' . implode( '|', array_map( 'preg_quote', array_values( self::SUMMARY_ASPECTS ), array_fill( 0, count( self::SUMMARY_ASPECTS ), '/' ) ) ) . ')\b|$))/is';

			if ( preg_match( $pattern, $summary_text, $matches ) ) {
				$content = trim( $matches[1] );
			} else {
				// If section not found, use first 200 chars of summary.
				$content = substr( $summary_text, 0, 200 );
			}

			if ( ! empty( $content ) ) {
				$summaries[] = array(
					'role'    => 'system',
					'content' => sprintf(
						'[Conversation Summary: %s] %s',
						$label,
						$content
					),
				);
			}
		}

		return $summaries;
	}

	/**
	 * Get aspect-specific keywords for extractive summarization.
	 *
	 * @since 1.8.0
	 *
	 * @param string $aspect Aspect key (decisions, facts, questions).
	 * @return string[] Keywords.
	 */
	private function get_aspect_keywords( $aspect ) {
		switch ( $aspect ) {
			case 'decisions':
				return array(
					'decided',
					'chose',
					'selected',
					'agreed',
					'confirmed',
					'approved',
					'set',
					'picked',
					'determined',
					'will use',
					'recommend',
					'final',
					'conclusion',
					'resolve',
				);
			case 'facts':
				return array(
					'found',
					'discovered',
					'according to',
					'result',
					'data',
					'error',
					'returned',
					'reported',
					'value',
					'count',
					'size',
					'number',
					'total',
					'amount',
					'price',
					'shows',
					'indicates',
					'confirms',
					'status',
				);
			case 'questions':
				return array(
					'need to',
					'should',
					'what',
					'how',
					'when',
					'where',
					'next',
					'pending',
					'waiting',
					'remaining',
					'todo',
					'follow up',
					'check',
					'verify',
					'confirm',
					'review',
					'outstanding',
					'incomplete',
				);
			default:
				return array();
		}
	}

	/**
	 * Extract sentences most relevant to a set of keywords.
	 *
	 * @since 1.8.0
	 *
	 * @param array    $messages           Messages array.
	 * @param string[] $keywords           Aspect keywords.
	 * @param int      $char_budget        Character budget.
	 * @return string Extracted text.
	 */
	private function extract_relevant_sentences( array $messages, array $keywords, $char_budget ) {
		$candidates = array();

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) || empty( $msg['content'] ) ) {
				continue;
			}
			$content = is_string( $msg['content'] ) ? $msg['content'] : '';

			// Split into sentences.
			$sentences = preg_split( '/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY );
			if ( false === $sentences ) {
				$sentences = array( $content );
			}

			foreach ( $sentences as $sentence ) {
				$sentence = trim( $sentence );
				if ( '' === $sentence || strlen( $sentence ) < 20 ) {
					continue;
				}

				// Score by keyword density.
				$sentence_lower = strtolower( $sentence );
				$keyword_count  = 0;
				foreach ( $keywords as $kw ) {
					if ( false !== strpos( $sentence_lower, $kw ) ) {
						++$keyword_count;
					}
				}

				if ( $keyword_count > 0 ) {
					$candidates[] = array(
						'text'  => $sentence,
						'score' => $keyword_count / strlen( $sentence ), // Density.
					);
				}
			}
		}

		// Sort by keyword density (highest first).
		usort(
			$candidates,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Select sentences within budget.
		$selected = array();
		$used     = 0;
		foreach ( $candidates as $candidate ) {
			$len = strlen( $candidate['text'] );
			if ( $used + $len > $char_budget ) {
				continue;
			}
			$selected[] = $candidate['text'];
			$used      += $len;
		}

		return implode( ' ', $selected );
	}
}
