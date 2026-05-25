<?php
/**
 * Prompt Optimizer — Cache-optimal prompt structuring utility.
 *
 * Ensures prompts sent to AI providers are ordered to maximize
 * prefix cache hit rates across OpenAI, Anthropic, DeepSeek, and Gemini.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prompt Optimizer class.
 *
 * Implements industry best practices for prompt caching:
 * - Static content at the beginning (cacheable prefix)
 * - Dynamic content at the end (changes per request)
 * - Stable cache key generation for server routing
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Prompt_Optimizer {

	/**
	 * Separator used between static core and dynamic context in system prompts.
	 *
	 * @var string
	 */
	const CONTEXT_SEPARATOR = "\n\n---\n\n";

	/**
	 * Number of characters from the system prompt used for cache key generation.
	 *
	 * @var int
	 */
	const CACHE_KEY_PREFIX_LENGTH = 256;

	/**
	 * Reorder messages for maximum prompt cache hit probability.
	 *
	 * Industry standard: place static/repeated content at the beginning
	 * and dynamic/user-specific content at the end of the messages array.
	 *
	 * CACHE LAYOUT (v1.5.1):
	 *   [1] static system prompt     ← byte-identical across requests → CACHED
	 *   [2] conversation history     ← changes per turn, but same prefix → PARTIAL CACHE
	 *   [3] memory/RAG documents     ← changes per query, but at tail → doesn't break prefix
	 *   [4] dynamic date context     ← changes daily, at tail → doesn't break prefix
	 *
	 * Previous layout placed memory documents between system prompt and
	 * conversation, which caused any change in retrieved documents to
	 * invalidate the cache for the entire conversation history.
	 *
	 * @param array $messages Chat messages array (role/content pairs).
	 * @param array $options  Request options including system_prompt.
	 * @return array Reordered messages.
	 */
	public static function order_for_cache_hit( array $messages, array $options ) {
		$system_messages    = array();
		$conversation       = array();
		$trailing_messages  = array();

		// --- Layer 1: Static system prompt (MUST be first for prefix caching) ---
		if ( ! empty( $options['system_prompt'] ) ) {
			$system_messages[] = array(
				'role'    => 'system',
				'content' => wp_kses_post( $options['system_prompt'] ),
			);
		}

		// --- Layer 2: Conversation history (the main body, changes per-turn) ---
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
				continue;
			}

			if ( 'system' === $message['role'] ) {
				// Skip duplicate system messages — use the one from options instead.
				continue;
			}

			$conversation[] = $message;
		}

		// --- Layer 3: Memory/RAG documents (placed after conversation to protect prefix) ---
		// When these change between requests (different retrieval results), only the
		// tail of the array changes, leaving the system+conversation prefix cache intact.
		if ( ! empty( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
			foreach ( $options['memory_documents'] as $doc ) {
				if ( ! empty( $doc['content'] ) ) {
					$trailing_messages[] = array(
						'role'    => 'system',
						'content' => sprintf(
							/* translators: %1$s: document title, %2$s: document content */
							__( '[Reference: %1$s] %2$s', 'mcp-ai-wpoos' ),
							isset( $doc['title'] ) ? $doc['title'] : __( 'Document', 'mcp-ai-wpoos' ),
							$doc['content']
						),
					);
				}
			}
		}

		// --- Layer 4: Dynamic date context (changes daily, at very end) ---
		// Placed last so the entire prefix remains stable across same-day requests.
		// The validator stores this in dynamic_date_context when prompt caching is on.
		if ( ! empty( $options['dynamic_date_context'] ) ) {
			$trailing_messages[] = array(
				'role'    => 'system',
				'content' => wp_kses_post( $options['dynamic_date_context'] ),
			);
		}

		// Assemble: static system → conversation → trailing (memory + date).
		return array_merge( $system_messages, $conversation, $trailing_messages );
	}

	/**
	 * Generate a stable prompt cache key for server routing.
	 *
	 * OpenAI and DeepSeek use prompt_cache_key to route similar requests
	 * to the same server, improving KV cache hit rates.
	 *
	 * @param array $options          Request options.
	 * @param array $assistant_config Assistant configuration.
	 * @return string Cache key.
	 */
	public static function generate_cache_key( array $options, array $assistant_config ) {
		$assistant_id = isset( $assistant_config['ID'] ) ? (int) $assistant_config['ID'] : 0;

		// Use the first N characters of the system prompt as the stable prefix.
		$system_prompt = isset( $options['system_prompt'] ) ? (string) $options['system_prompt'] : '';
		$prefix        = substr( $system_prompt, 0, self::CACHE_KEY_PREFIX_LENGTH );

		return 'wp_mcp_ai_' . $assistant_id . '_' . md5( $prefix );
	}

	/**
	 * Split a system prompt into static core and dynamic context parts.
	 *
	 * The static core (role definition, instructions) should remain at the
	 * beginning for cache hits. The dynamic context (dates, user info, etc.)
	 * should be appended after the cache breakpoint.
	 *
	 * @param string $system_prompt Full system prompt.
	 * @return array{static_core: string, dynamic_context: string}
	 */
	public static function split_system_prompt( $system_prompt ) {
		$static_core     = $system_prompt;
		$dynamic_context = '';

		// Known dynamic context markers injected by the plugin.
		$dynamic_markers = array(
			'**Current Context Information:**',
			'Current Date:',
			'Current Year:',
			'Current Time:',
		);

		foreach ( $dynamic_markers as $marker ) {
			$pos = mb_strpos( $system_prompt, $marker );
			if ( false !== $pos ) {
				// Backtrack to the separator before this marker.
				$sep_pos = mb_strrpos( mb_substr( $system_prompt, 0, $pos ), self::CONTEXT_SEPARATOR );
				if ( false !== $sep_pos ) {
					$static_core     = trim( mb_substr( $system_prompt, 0, $sep_pos ) );
					$dynamic_context = trim( mb_substr( $system_prompt, $sep_pos ) );
				} else {
					$static_core     = trim( mb_substr( $system_prompt, 0, $pos ) );
					$dynamic_context = trim( mb_substr( $system_prompt, $pos ) );
				}
				break;
			}
		}

		return array(
			'static_core'     => $static_core,
			'dynamic_context' => $dynamic_context,
		);
	}

	/**
	 * Check whether prompt caching is beneficial for this request.
	 *
	 * Prompt caching is most beneficial when:
	 * - The system prompt is long enough (> 1024 tokens ~ 4000 chars).
	 * - The provider supports caching.
	 * - The request is not a one-off (temperature > 0 for creative tasks).
	 *
	 * @param array  $options  Request options.
	 * @param string $provider AI provider key.
	 * @return bool True if caching is recommended.
	 */
	public static function is_caching_beneficial( array $options, $provider ) {
		// Providers known to support prompt caching.
		$caching_providers = array( 'openai', 'anthropic', 'deepseek', 'gemini', 'openrouter' );

		if ( ! in_array( $provider, $caching_providers, true ) ) {
			return false;
		}

		// System prompt should be substantial enough for caching to matter.
		$system_prompt = isset( $options['system_prompt'] ) ? (string) $options['system_prompt'] : '';
		if ( strlen( $system_prompt ) < 500 ) {
			return false;
		}

		return true;
	}

	/**
	 * Apply provider-specific prompt strategy to optimise cost and cache hit rates.
	 *
	 * Each provider has different cost structures and caching behaviour:
	 *
	 *   DeepSeek  — absurdly cheap cache hits, tolerates large repeated context.
	 *               Strategy: include everything, no trimming, full tool set.
	 *
	 *   Claude    — strict cache matching, expensive output, punishes verbosity.
	 *               Strategy: aggressive tool trimming, minimise memory docs,
	 *               use cache_control, compact output instructions.
	 *
	 *   OpenAI    — balanced, moderate cache support.
	 *               Strategy: standard tool set, moderate memory, prompt_cache_key.
	 *
	 *   Gemini    — gigantic context, excels at retrieval.
	 *               Strategy: lean into semantic search hints, full tools.
	 *
	 *   OpenRouter — passthrough; strategy inherited from upstream provider.
	 *
	 * @since 1.5.1
	 *
	 * @param array  $options Request options (modified in place).
	 * @param string $provider AI provider key.
	 * @return array Modified options.
	 */
	public static function apply_provider_strategy( array $options, $provider ) {
		switch ( $provider ) {
			case 'anthropic':
				// Claude: aggressive trimming to minimise fresh input tokens.
				// Default auto_trim_tools keeps top-5 tools (vs default 10).
				$options['autoTrimTools'] = true;
				if ( ! isset( $options['maxTools'] ) ) {
					$options['maxTools'] = 5;
				}

				// Cap memory documents to top-3 to protect prefix cache.
				if ( ! empty( $options['memory_documents'] ) && count( $options['memory_documents'] ) > 3 ) {
					$options['memory_documents'] = array_slice( $options['memory_documents'], 0, 3 );
				}

				// Prefer ephemeral cache_control (already handled by client).
				$options['cache_system_prompt'] = ! empty( $options['cache_system_prompt'] );
				break;

			case 'deepseek':
				// DeepSeek: include everything — it's cheap and handles large context well.
				// Don't trim tools, keep all memory documents, use disk KV cache.
				$options['autoTrimTools'] = false;
				// Keep cache_system_prompt for prompt_cache_key routing.
				break;

			case 'gemini':
				// Gemini: full tool set, unlimited context, retrieval-oriented.
				$options['autoTrimTools'] = false;
				// Gemini handles large context natively — no need to cap memory.
				break;

			case 'openai':
			default:
				// OpenAI: balanced — auto-trim if many tools, keep moderate memory.
				$options['autoTrimTools'] = ! empty( $options['tools'] ) && count( $options['tools'] ) > 10;
				if ( $options['autoTrimTools'] && ! isset( $options['maxTools'] ) ) {
					$options['maxTools'] = 10;
				}
				break;
		}

		return $options;
	}

	/**
	 * Resolve the best model for agentic loop iterations vs final synthesis.
	 *
	 * Intermediate tool-calling turns don't need the most expensive model —
	 * they're mechanical ("call tool X with args Y"). The final synthesis turn
	 * is where reasoning quality matters most.
	 *
	 * Strategy: if the assistant's primary model is expensive (Claude Opus, GPT-4.1,
	 * Gemini Pro) and a cheaper alternative is available, use the cheap model for
	 * loop iterations 2+ and the primary model for the first and final turns.
	 *
	 * @since 1.5.1
	 *
	 * @param string $primary_model   The assistant's configured model.
	 * @param string $provider         AI provider key.
	 * @param int    $iteration         Current iteration (0-indexed).
	 * @param int    $max_iterations    Total max iterations.
	 * @param array  $options           Request options.
	 * @return string Model to use for this iteration.
	 */
	public static function resolve_loop_model( $primary_model, $provider, $iteration, $max_iterations, array $options = array() ) {
		// Only intervene on iterations 2+ (0 = first call, 1+ = loop iterations).
		if ( $iteration < 1 ) {
			return $primary_model;
		}

		// If this is the final iteration, use the primary model for quality synthesis.
		if ( $iteration >= $max_iterations - 1 ) {
			return $primary_model;
		}

		// Check for explicit agentic_loop_model in options (per-request override).
		if ( ! empty( $options['agentic_loop_model'] ) ) {
			return sanitize_text_field( $options['agentic_loop_model'] );
		}

		// Provider-specific cheap loop models (hardcoded fallbacks).
		$cheap_models = array(
			'openai'    => 'gpt-4.1-mini',
			'anthropic' => 'claude-haiku-4-5',
			'deepseek'  => 'deepseek-chat',  // DeepSeek V4 is already cheap.
			'gemini'    => 'gemini-2.5-flash',
		);

		if ( isset( $cheap_models[ $provider ] ) && $cheap_models[ $provider ] !== $primary_model ) {
			return $cheap_models[ $provider ];
		}

		return $primary_model;
	}
}
