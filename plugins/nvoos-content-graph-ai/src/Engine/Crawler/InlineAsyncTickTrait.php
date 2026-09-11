<?php
/**
 * Inline-async-tick trait (Wave E6, sub-cluster 5 — crawler collaborator).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Inline_Async_Tick_Trait`
 * (`includes/traits/trait-wp-mcp-ai-inline-async-tick.php`): the
 * byte-identical inline-async-tick primitives — the guarded
 * client-detach helper, the two-layer cooperative tick lock
 * (transient cross-process gate + `wp_cache_add` in-process atomic
 * gate), the idempotent release, the `DISABLE_WP_CRON`-aware loop
 * decision with the `wp_mcp_ai_inline_tick_loop_enabled` filter, the
 * `wp_mcp_ai_inline_kick_enabled` escape hatch, the
 * `wp_mcp_ai_inline_kick_completed` observability action, and the
 * `inline_async_run_kick()` boilerplate wrapper with the Throwable
 * containment.
 *
 * Documented deviations:
 *  - Trait name/namespace — the AI addon's PSR-4 tree (decision D4);
 *    ported as the Crawler's required collaborator (the crawler is the
 *    only engine-piece consumer; the base trait's other consumers are
 *    services outside the E6 scope).
 *  - The failure log resolves per install mode via
 *    `defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' )`
 *    — monolith installs use the base logger exactly as the base trait
 *    does; standalone installs skip the log (the monorepo classmap
 *    would otherwise resolve the base class in standalone test runs).
 *  - `static::class` in the `wp_mcp_ai_inline_tick_loop_enabled` filter
 *    resolves to the ported host class name (documented — the filter
 *    arg is class-name-dependent by design).
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Crawler
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Crawler;

/**
 * Trait providing inline-async-tick fallback primitives.
 *
 * @since 1.1.0
 */
trait InlineAsyncTickTrait {

	/**
	 * Detach the worker from the HTTP client so the inline tick can
	 * continue after the response is flushed.
	 *
	 * Both calls are guarded — `fastcgi_finish_request()` exists only
	 * under FPM/FastCGI and `ignore_user_abort()` may be disabled by
	 * `disable_functions`. Errors are silenced because the worker should
	 * still proceed even when these hardening calls cannot fire.
	 *
	 * @return void
	 */
	protected static function inline_async_detach_worker_from_client() {
		if ( \function_exists( 'ignore_user_abort' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Stream context detection may trigger warnings on invalid streams; error suppression is intentional with fallback handling.
			@\ignore_user_abort( true );
		}
		if ( \function_exists( 'fastcgi_finish_request' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Stream context detection may trigger warnings on invalid streams; error suppression is intentional with fallback handling.
			@\fastcgi_finish_request();
		}
	}

	/**
	 * Acquire the per-job cooperative tick lock.
	 *
	 * Two-layer scheme:
	 *
	 * 1. A short-lived transient gives a cross-request, cross-process
	 *    guarantee that survives even when no persistent object cache is
	 *    installed (the typical WordPress.org-distribution case).
	 * 2. A `wp_cache_add()` entry layered on top provides atomic
	 *    in-process protection on installations that ship a persistent
	 *    object cache (Redis, Memcached, etc.), where transient
	 *    operations may be routed through the cache and cannot otherwise
	 *    be guaranteed atomic.
	 *
	 * @param string $lock_key    Fully-qualified key (host class is
	 *                            responsible for prefixing).
	 * @param string $cache_group Object-cache group used by
	 *                            `wp_cache_add()`/`wp_cache_delete()`.
	 * @param int    $ttl_seconds Lock TTL in seconds. Should be greater
	 *                            than the longest expected tick.
	 * @return bool True when the lock was acquired by this caller.
	 */
	protected static function inline_async_acquire_tick_lock( $lock_key, $cache_group, $ttl_seconds = 60 ) {
		// Cross-process gate first.
		if ( false !== \get_transient( $lock_key ) ) {
			return false;
		}

		// In-process atomic gate. wp_cache_add returns false when the key
		// is already present in the cache, which on a persistent object
		// cache means another process beat us to it.
		if ( \function_exists( 'wp_cache_add' ) ) {
			$added = \wp_cache_add( $lock_key, 1, $cache_group, $ttl_seconds );
			if ( false === $added ) {
				return false;
			}
		}

		\set_transient( $lock_key, 1, $ttl_seconds );
		return true;
	}

	/**
	 * Release the per-job cooperative tick lock.
	 *
	 * Idempotent — safe to call from a `finally` even if acquisition
	 * failed (the lock entries simply will not exist).
	 *
	 * @param string $lock_key    Fully-qualified key.
	 * @param string $cache_group Object-cache group.
	 * @return void
	 */
	protected static function inline_async_release_tick_lock( $lock_key, $cache_group ) {
		if ( \function_exists( 'wp_cache_delete' ) ) {
			\wp_cache_delete( $lock_key, $cache_group );
		}
		\delete_transient( $lock_key );
	}

	/**
	 * Decide whether the host class should recurse inline for another
	 * tick. True only when WP-Cron is disabled (the rescheduled event
	 * will never fire on its own), the queue still has work, and we are
	 * inside the inline-loop wall-clock budget.
	 *
	 * @param int  $tick_started_at Unix timestamp recorded immediately
	 *                              before the lock was acquired.
	 * @param bool $queue_has_work  Whether more work remains.
	 * @param int  $budget_seconds  Maximum wall-clock seconds the
	 *                              inline loop may consume on a single
	 *                              PHP request.
	 * @return bool
	 */
	protected static function inline_async_should_loop( $tick_started_at, $queue_has_work, $budget_seconds = 20 ) {
		if ( ! $queue_has_work ) {
			return false;
		}

		/**
		 * Filter whether the inline loop may recurse for another tick.
		 *
		 * Default `true`. Returning `false` restores the one-row-per-tick
		 * contract (each row waits for the next cron loopback), which is
		 * also what the test suite uses to assert per-tick progress.
		 *
		 * @since 1.1.0
		 *
		 * @param bool   $enabled Default `true`.
		 * @param string $class   Host class consuming the trait.
		 */
		if ( ! \apply_filters( 'wp_mcp_ai_inline_tick_loop_enabled', true, static::class ) ) {
			return false;
		}

		if ( ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			return false;
		}
		return ( \time() - (int) $tick_started_at ) < (int) $budget_seconds;
	}

	/**
	 * Resolve the global inline-kick escape hatch.
	 *
	 * Mirrors the `DISABLE_WP_CRON` semantics: when this returns false
	 * the host class should skip the inline shutdown handler entirely
	 * and fall back to whatever the underlying cron loopback would have
	 * done. Useful on hosts where `fastcgi_finish_request()` interacts
	 * badly with another plugin or where the operator wants to debug a
	 * misbehaving job.
	 *
	 * @param string $job_id Job identifier (passed to filter for
	 *                       per-job overrides).
	 * @param string $class  Host class name (passed to filter for
	 *                       per-class overrides).
	 * @return bool
	 */
	protected static function inline_async_kick_enabled( $job_id, $class ) {
		/**
		 * Filter whether the inline-async-tick fallback is active for a
		 * given job. Default `true`. Returning `false` disables the
		 * shutdown-hook kick (and the REST self-heal kick) for that job
		 * without disabling cron itself; the job will still run when
		 * the cron loopback fires.
		 *
		 * @since 1.1.0
		 *
		 * @param bool   $enabled Default `true`.
		 * @param string $job_id  Job identifier.
		 * @param string $class   Host class consuming the trait.
		 */
		return (bool) \apply_filters( 'wp_mcp_ai_inline_kick_enabled', true, $job_id, $class );
	}

	/**
	 * Emit the inline-kick observability action.
	 *
	 * Fires after the inline tick has run (or short-circuited), giving
	 * downstream subscribers a single hook to record duration and
	 * failure metrics.
	 *
	 * @param string $class       Host class name.
	 * @param string $job_id      Job identifier.
	 * @param float  $duration_ms Wall-clock duration of the kick body
	 *                            in milliseconds.
	 * @param bool   $success     Whether the kick reached the tick
	 *                            handler successfully.
	 * @return void
	 */
	protected static function inline_async_emit_kick_completed( $class, $job_id, $duration_ms, $success ) {
		/**
		 * Action fired once per inline-async kick.
		 *
		 * Subscribers can record metrics or audit events. This action is
		 * intentionally lightweight (no return value, no filtering) so
		 * subscribers can subscribe without adding latency to the kick
		 * body.
		 *
		 * @since 1.1.0
		 *
		 * @param string $class       Host class name.
		 * @param string $job_id      Job identifier.
		 * @param float  $duration_ms Wall-clock duration in milliseconds.
		 * @param bool   $success     Whether the kick reached the tick
		 *                            handler successfully.
		 */
		\do_action( 'wp_mcp_ai_inline_kick_completed', $class, $job_id, (float) $duration_ms, (bool) $success );
	}

	/**
	 * Convenience helper: run a callable as the inline-kick body and
	 * emit the completion action automatically.
	 *
	 * Centralises the duration measurement and try/catch so individual
	 * host classes do not have to repeat the boilerplate.
	 *
	 * @param string   $class    Host class name (for the observability
	 *                           action).
	 * @param string   $job_id   Job identifier.
	 * @param callable $callable Tick handler. Receives no arguments.
	 *                           Any thrown `Throwable` (Exception OR
	 *                           Error such as TypeError) is caught,
	 *                           logged, and reported as a failed kick
	 *                           via the observability action so a
	 *                           buggy tick body cannot tear down the
	 *                           shutdown handler chain.
	 * @return void
	 */
	protected static function inline_async_run_kick( $class, $job_id, $callable ) {
		$started_at = \microtime( true );
		$success    = false;
		try {
			\call_user_func( $callable );
			$success = true;
		} catch ( \Throwable $e ) {
			// Per-mode seam (see the class docblock): the base logger is
			// monolith-only.
			if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Logger' ) ) {
				\WP_MCP_AI_Logger::log_error(
					\sprintf(
						/* translators: 1: host class name, 2: error message */
						__( 'Inline-async kick failed in %1$s: %2$s', 'nvoos-content-graph-ai' ),
						$class,
						$e->getMessage()
					),
					array(
						'job_id' => $job_id,
						'class'  => $class,
					)
				);
			}
		}
		$duration_ms = ( \microtime( true ) - $started_at ) * 1000.0;
		self::inline_async_emit_kick_completed( $class, $job_id, $duration_ms, $success );
	}
}
