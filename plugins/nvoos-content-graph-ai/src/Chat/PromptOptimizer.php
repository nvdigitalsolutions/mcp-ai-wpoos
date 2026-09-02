<?php
/**
 * Prompt Optimizer — cache-optimal prompt structuring utility.
 *
 * Ensures prompts sent to AI providers are ordered to maximize
 * prefix cache hit rates across OpenAI, Anthropic, DeepSeek, and Gemini.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-prompt-optimizer.php` (behaviour-preserving;
 * base copy is retained permanently — ecosystem port plan D-NOBASE).
 * The generated cache-key prefix stays `wp_mcp_ai_` so provider-side
 * routing keys are byte-identical between the two implementations.
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
 * Prompt Optimizer class.
 *
 * Implements industry best practices for prompt caching:
 * - Static content at the beginning (cacheable prefix)
 * - Dynamic content at the end (changes per request)
 * - Stable cache key generation for server routing
 *
 * @since 1.1.0
 */
class PromptOptimizer {

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
	 * @param array $messages Chat messages array (role/content pairs).
	 * @param array $options  Request options including system_prompt.
	 * @return array Reordered messages.
	 */
	public static function order_for_cache_hit( array $messages, array $options ): array {
		$system_messages  = array();
		$static_messages  = array();
		$dynamic_messages = array();

		// Extract system prompt from options and place it first.
		if ( ! empty( $options['system_prompt'] ) ) {
			$system_messages[] = array(
				'role'    => 'system',
				'content' => wp_kses_post( $options['system_prompt'] ),
			);
		}

		// Separate existing messages: system first, then others.
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
				continue;
			}

			if ( 'system' === $message['role'] ) {
				// Skip duplicate system messages — use the one from options instead.
				continue;
			}

			$dynamic_messages[] = $message;
		}

		// Insert memory documents as static context after system prompt.
		if ( ! empty( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
			foreach ( $options['memory_documents'] as $doc ) {
				if ( ! empty( $doc['content'] ) ) {
					$static_messages[] = array(
						'role'    => 'system',
						'content' => sprintf(
							/* translators: %s: document title */
							__( '[Reference: %1$s] %2$s', 'nvoos-content-graph-ai' ),
							isset( $doc['title'] ) ? $doc['title'] : __( 'Document', 'nvoos-content-graph-ai' ),
							$doc['content']
						),
					);
				}
			}
		}

		// Assemble: system → static context → conversation history.
		return array_merge( $system_messages, $static_messages, $dynamic_messages );
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
	public static function generate_cache_key( array $options, array $assistant_config ): string {
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
	public static function split_system_prompt( string $system_prompt ): array {
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
	public static function is_caching_beneficial( array $options, string $provider ): bool {
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
}
