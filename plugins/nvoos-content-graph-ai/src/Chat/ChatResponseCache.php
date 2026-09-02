<?php
/**
 * Chat Response Cache — server-side response caching for LLM chat requests.
 *
 * Caches chat completions using WordPress transients to avoid redundant
 * API calls when identical or near-identical prompts are sent within
 * a configurable time window.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-chat-response-cache.php` (behaviour-preserving;
 * base copy is retained permanently — ecosystem port plan D-NOBASE).
 * Transient keys, the version key, and the `wp_mcp_ai_chat_response_cache_ttl`
 * filter are byte-identical so cached entries stay portable between the two
 * implementations.
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
 * Chat Response Cache class.
 *
 * Implements a two-tier caching strategy:
 * 1. Exact match: same messages + same assistant → cached response
 * 2. Prefix match: same system_prompt + tools → reuse provider cache via prompt_cache_key
 *
 * @since 1.1.0
 */
class ChatResponseCache {

	/**
	 * Default cache TTL in seconds (5 minutes).
	 *
	 * Aligned with provider cache windows (OpenAI: 5-10 min, Anthropic: 5 min).
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 300;

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wp_mcp_ai_chat_cache_';

	/**
	 * Maximum number of cached responses to keep.
	 *
	 * @var int
	 */
	const MAX_CACHED_RESPONSES = 100;

	/**
	 * Check if a cached response exists for the given messages and options.
	 *
	 * Only caches when:
	 * - Prompt caching is enabled for the assistant.
	 * - Temperature is 0 or null (deterministic responses).
	 * - No streaming is requested.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Request options.
	 * @return array|false Cached response or false.
	 */
	public function get_cached_response( array $messages, array $options ) {
		if ( ! $this->is_cacheable( $options ) ) {
			return false;
		}

		$cache_key = $this->build_cache_key( $messages, $options );
		$cached    = get_transient( $cache_key );

		if ( false === $cached ) {
			return false;
		}

		// Track cache hit via the base plugin's logger in monolith installs.
		// Gate on the boot discriminator (not bare class_exists) — the monorepo
		// autoloader classmaps base classes to disk even when the base plugin
		// is inactive, so class_exists alone can resolve in standalone mode.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'chat_response_cache_hit',
				'Chat response served from cache',
				array(
					'cache_key'     => $cache_key,
					'message_count' => count( $messages ),
				)
			);
		}

		return $cached;
	}

	/**
	 * Cache a chat response.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Request options.
	 * @param array $response Normalized LLM response.
	 * @return bool True on success.
	 */
	public function set_cached_response( array $messages, array $options, array $response ): bool {
		if ( ! $this->is_cacheable( $options ) ) {
			return false;
		}

		$cache_key = $this->build_cache_key( $messages, $options );

		// Add cache metadata to response.
		$response['cache_metadata'] = array(
			'cached_at'    => gmdate( 'c' ),
			'cache_key'    => $cache_key,
			'cache_source' => 'wp_transient',
		);

		$ttl = $this->get_cache_ttl( $options );

		return set_transient( $cache_key, $response, $ttl );
	}

	/**
	 * Invalidate cached responses for a specific assistant.
	 *
	 * Called when assistant configuration changes (tools, system prompt, etc.).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	public function invalidate_for_assistant( $assistant_id ): void {
		// Transients are keyed by content hash, so we can't enumerate them.
		// Instead, we bump a version counter that gets appended to cache keys.
		$version_key = 'wp_mcp_ai_chat_cache_version_' . absint( $assistant_id );
		$version     = get_transient( $version_key );
		set_transient( $version_key, ( (int) $version ) + 1, WEEK_IN_SECONDS );
	}

	/**
	 * Build a deterministic cache key from messages and options.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Request options.
	 * @return string Cache key.
	 */
	protected function build_cache_key( array $messages, array $options ): string {
		$assistant_id = isset( $options['assistant_id'] ) ? absint( $options['assistant_id'] ) : 0;

		// Normalize messages for consistent hashing.
		$normalized = array();
		foreach ( $messages as $msg ) {
			$normalized[] = array(
				'role'    => isset( $msg['role'] ) ? sanitize_key( $msg['role'] ) : 'user',
				'content' => isset( $msg['content'] ) ? ( is_array( $msg['content'] ) ? wp_json_encode( $msg['content'] ) : (string) $msg['content'] ) : '',
			);
		}

		// Include tool set hash (different tools → different responses).
		$tools_hash = '';
		if ( ! empty( $options['tools'] ) ) {
			$tool_slugs = array();
			foreach ( $options['tools'] as $tool ) {
				if ( isset( $tool['function']['name'] ) ) {
					$tool_slugs[] = $tool['function']['name'];
				}
			}
			sort( $tool_slugs );
			$tools_hash = md5( implode( ',', $tool_slugs ) );
		}

		$payload = wp_json_encode( $normalized ) . '|' . $tools_hash;

		// Include version for invalidation.
		$version_key = 'wp_mcp_ai_chat_cache_version_' . $assistant_id;
		$version     = get_transient( $version_key );

		return self::TRANSIENT_PREFIX . $assistant_id . '_' . md5( $payload . '_v' . (int) $version );
	}

	/**
	 * Determine if the request is eligible for caching.
	 *
	 * @param array $options Request options.
	 * @return bool
	 */
	protected function is_cacheable( array $options ): bool {
		// Don't cache streaming requests.
		if ( ! empty( $options['stream'] ) ) {
			return false;
		}

		// Don't cache when temperature is set (non-deterministic).
		if ( isset( $options['temperature'] ) && $options['temperature'] > 0 ) {
			return false;
		}

		// Only cache when prompt caching is enabled.
		if ( empty( $options['cache_system_prompt'] ) ) {
			return false;
		}

		// Don't cache if explicitly bypassed.
		if ( ! empty( $options['bypass_cache'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the cache TTL, allowing per-request override.
	 *
	 * @param array $options Request options.
	 * @return int TTL in seconds.
	 */
	protected function get_cache_ttl( array $options ): int {
		if ( ! empty( $options['cache_ttl'] ) && is_numeric( $options['cache_ttl'] ) ) {
			return max( 60, min( 3600, absint( $options['cache_ttl'] ) ) );
		}

		/**
		 * Filter the chat response cache TTL.
		 *
		 * @param int   $ttl     Cache TTL in seconds (default 300 = 5 min).
		 * @param array $options Request options.
		 */
		return apply_filters( 'wp_mcp_ai_chat_response_cache_ttl', self::DEFAULT_TTL, $options );
	}
}
