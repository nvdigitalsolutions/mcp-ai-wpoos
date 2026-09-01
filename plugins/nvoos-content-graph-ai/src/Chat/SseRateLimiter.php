<?php
/**
 * SSE Connection Rate Limiter for the Content Graph — AI addon.
 *
 * Prevents resource exhaustion by limiting concurrent Server-Sent Events
 * connections per user and globally across all users.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-sse-rate-limiter.php` (behaviour-preserving;
 * base copy is retained permanently — ecosystem port plan D-NOBASE).
 * Transient keys (`wp_mcp_ai_sse_*`), error codes, and the
 * `wp_mcp_ai_sse_per_user_limit` / `wp_mcp_ai_sse_global_limit` filters are
 * byte-identical so connection accounting stays portable between the two
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
 * SSE Rate Limiter Class.
 *
 * Tracks and limits concurrent SSE connections using WordPress transients.
 *
 * Limits:
 * - Per-user: 5 concurrent connections (override via `wp_mcp_ai_sse_per_user_limit` filter).
 * - Global: 100 concurrent connections (override via `wp_mcp_ai_sse_global_limit` filter).
 * - Users with `manage_options` capability bypass all limits.
 *
 * @since 1.1.0
 */
class SseRateLimiter {

	/**
	 * Transient prefix for per-user SSE connection counts.
	 *
	 * @var string
	 */
	const USER_TRANSIENT_PREFIX = 'wp_mcp_ai_sse_user_';

	/**
	 * Transient key for global SSE connection count.
	 *
	 * @var string
	 */
	const GLOBAL_TRANSIENT_KEY = 'wp_mcp_ai_sse_global';

	/**
	 * Default per-user connection limit.
	 *
	 * @var int
	 */
	const DEFAULT_PER_USER_LIMIT = 5;

	/**
	 * Default global connection limit.
	 *
	 * @var int
	 */
	const DEFAULT_GLOBAL_LIMIT = 100;

	/**
	 * How long (in seconds) connection counts are tracked.
	 * This acts as the maximum expected SSE session duration.
	 *
	 * @var int
	 */
	const TRACKING_WINDOW = 3600; // 1 hour.

	/**
	 * Check whether a new SSE connection should be permitted.
	 *
	 * Admins (`manage_options`) bypass all limits. Returns true when the
	 * connection is allowed, WP_Error with HTTP status 429 when denied.
	 *
	 * @return true|\WP_Error True when allowed, WP_Error on rate limit.
	 */
	public function check_connection_allowed() {
		// Admins bypass rate limiting.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$user_id = get_current_user_id();

		$user_limit   = (int) apply_filters( 'wp_mcp_ai_sse_per_user_limit', self::DEFAULT_PER_USER_LIMIT );
		$global_limit = (int) apply_filters( 'wp_mcp_ai_sse_global_limit', self::DEFAULT_GLOBAL_LIMIT );

		$user_connections   = $this->get_user_connection_count( $user_id );
		$global_connections = $this->get_global_connection_count();

		if ( $user_connections >= $user_limit ) {
			return new \WP_Error(
				'wp_mcp_ai_sse_rate_limit',
				sprintf(
					/* translators: %d: per-user SSE connection limit */
					__( 'SSE connection limit reached. You may have at most %d concurrent streaming connections. Please close an existing connection and try again.', 'nvoos-content-graph-ai' ),
					$user_limit
				),
				array(
					'status'      => 429,
					'retry_after' => 30,
					'limit'       => $user_limit,
					'type'        => 'per_user',
				)
			);
		}

		if ( $global_connections >= $global_limit ) {
			return new \WP_Error(
				'wp_mcp_ai_sse_global_rate_limit',
				sprintf(
					/* translators: %d: global SSE connection limit */
					__( 'Server SSE connection capacity (%d) has been reached. Please try again shortly.', 'nvoos-content-graph-ai' ),
					$global_limit
				),
				array(
					'status'      => 429,
					'retry_after' => 60,
					'limit'       => $global_limit,
					'type'        => 'global',
				)
			);
		}

		return true;
	}

	/**
	 * Register an active SSE connection and return a connection token.
	 *
	 * Should be called immediately before the SSE stream begins. Returns
	 * a unique token that must be passed to `release_connection()` when done.
	 *
	 * Note: The counter increment is not atomic under WordPress transients.
	 * In high-concurrency scenarios two simultaneous requests may both read
	 * the same counter value, causing a brief undercount. This is acceptable
	 * for best-effort rate limiting; for strict enforcement a persistent
	 * object cache with atomic increment support (e.g. Redis INCR) is recommended.
	 *
	 * @param int $user_id WordPress user ID (0 for guests).
	 * @return string Unique connection token.
	 */
	public function register_connection( $user_id = 0 ): string {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		// Increment counters atomically (best-effort with transients).
		$user_key   = self::USER_TRANSIENT_PREFIX . $user_id;
		$user_count = (int) get_transient( $user_key );
		set_transient( $user_key, $user_count + 1, self::TRACKING_WINDOW );

		$global_count = (int) get_transient( self::GLOBAL_TRANSIENT_KEY );
		set_transient( self::GLOBAL_TRANSIENT_KEY, $global_count + 1, self::TRACKING_WINDOW );

		// Return a token so the caller can release the right slot later.
		$token = wp_generate_uuid4();
		set_transient( 'wp_mcp_ai_sse_token_' . $token, $user_id, self::TRACKING_WINDOW );

		return $token;
	}

	/**
	 * Release a previously registered SSE connection.
	 *
	 * Should be called when an SSE stream ends (normally or on error).
	 *
	 * @param string $token Connection token returned by `register_connection()`.
	 * @return void
	 */
	public function release_connection( $token ): void {
		$transient_key = 'wp_mcp_ai_sse_token_' . $token;
		$user_id       = (int) get_transient( $transient_key );

		if ( ! $user_id ) {
			// Token already expired or invalid — nothing to do.
			delete_transient( $transient_key );
			return;
		}

		// Decrement per-user counter (floor at 0).
		$user_key   = self::USER_TRANSIENT_PREFIX . $user_id;
		$user_count = (int) get_transient( $user_key );
		if ( $user_count > 0 ) {
			set_transient( $user_key, $user_count - 1, self::TRACKING_WINDOW );
		}

		// Decrement global counter (floor at 0).
		$global_count = (int) get_transient( self::GLOBAL_TRANSIENT_KEY );
		if ( $global_count > 0 ) {
			set_transient( self::GLOBAL_TRANSIENT_KEY, $global_count - 1, self::TRACKING_WINDOW );
		}

		delete_transient( $transient_key );
	}

	/**
	 * Get current SSE connection count for a specific user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Number of active SSE connections for the user.
	 */
	public function get_user_connection_count( $user_id ): int {
		return (int) get_transient( self::USER_TRANSIENT_PREFIX . (int) $user_id );
	}

	/**
	 * Get current global SSE connection count.
	 *
	 * @return int Total number of active SSE connections across all users.
	 */
	public function get_global_connection_count(): int {
		return (int) get_transient( self::GLOBAL_TRANSIENT_KEY );
	}

	/**
	 * Reset all SSE connection counters (for use in tests or admin reset).
	 *
	 * Clears per-user and global counters but does not affect active streams.
	 *
	 * @param int|null $user_id If provided, only reset this user's counter.
	 * @return void
	 */
	public function reset_counters( $user_id = null ): void {
		if ( null !== $user_id ) {
			delete_transient( self::USER_TRANSIENT_PREFIX . (int) $user_id );
			return;
		}

		delete_transient( self::GLOBAL_TRANSIENT_KEY );
	}
}
