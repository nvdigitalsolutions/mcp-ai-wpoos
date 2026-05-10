<?php
/**
 * Memory hygiene helper for long-running operations.
 *
 * Implements the "stop_the_insanity" cleanup pattern (clearing $wpdb->queries
 * and the in-memory object cache) plus throttling against WP_MAX_MEMORY_LIMIT.
 * Used by WP_MCP_AI_Batch_Iterator and any tool / async job that processes
 * large datasets.
 *
 * @link    https://deliciousbrains.com/wordpress-memory-leak/
 * @link    https://deliciousbrains.com/building-custom-wp-cli-commands-massive-data-migrations/
 * @credit  Pattern derived from WP-CLI / WordPress.com VIP large-data guides
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memory hygiene helper for batch processing.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Memory_Manager {

	/**
	 * Default memory threshold percentage (0-100).
	 *
	 * Operations should pause / abort when memory_get_usage() crosses this
	 * percentage of the configured WP_MAX_MEMORY_LIMIT.
	 */
	const DEFAULT_THRESHOLD_PCT = 75;

	/**
	 * Default sleep duration (microseconds) when throttling.
	 */
	const DEFAULT_THROTTLE_SLEEP_US = 250000; // 0.25s.

	/**
	 * Reset in-memory state that grows during long-running PHP processes.
	 *
	 * Mirrors the "stop_the_insanity" recipe from the WP CLI / VIP playbooks:
	 *   - Empty $wpdb->queries (only populated when SAVEQUERIES is true,
	 *     but reset defensively).
	 *   - Reset version-aware properties on the in-memory object cache.
	 *   - Optionally suspend further cache additions during read-heavy loops.
	 *   - Run gc_collect_cycles() to reclaim circular references.
	 *
	 * @since 1.2.0
	 *
	 * @param array $args {
	 *     Optional. Cleanup options.
	 *
	 *     @type bool $suspend_cache_addition Whether to call wp_suspend_cache_addition( true )
	 *                                        after the reset. Default false.
	 *     @type bool $run_gc                 Whether to run gc_collect_cycles(). Default true.
	 * }
	 * @return void
	 */
	public static function stop_the_insanity( $args = array() ) {
		global $wpdb, $wp_object_cache;

		$args = wp_parse_args(
			$args,
			array(
				'suspend_cache_addition' => false,
				'run_gc'                 => true,
			)
		);

		// 1. Clear stored queries.
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->queries = array();
		}

		// 2. Reset in-memory object cache properties (version-aware / defensive).
		if ( is_object( $wp_object_cache ) ) {
			$cache_props = array(
				'cache',
				'group_ops',
				'memcache_debug',
				'stats',
			);

			foreach ( $cache_props as $prop ) {
				if ( property_exists( $wp_object_cache, $prop ) ) {
					$wp_object_cache->$prop = array();
				}
			}

			if ( property_exists( $wp_object_cache, 'cache_hits' ) ) {
				$wp_object_cache->cache_hits = 0;
			}
			if ( property_exists( $wp_object_cache, 'cache_misses' ) ) {
				$wp_object_cache->cache_misses = 0;
			}

			// Some object cache drop-ins (e.g. WP Super Cache, Memcached Object Cache)
			// expose a __remoteset() method to flush local request-scoped state.
			if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
				call_user_func( array( $wp_object_cache, '__remoteset' ) );
			}
		}

		/**
		 * Filters whether to suspend cache additions after each cleanup.
		 *
		 * Useful for read-heavy loops where every fetched post / meta would
		 * otherwise inflate the in-memory cache again before the next reset.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $suspend Default false.
		 */
		$suspend = apply_filters(
			'wp_mcp_ai_memory_suspend_cache_addition',
			(bool) $args['suspend_cache_addition']
		);

		if ( $suspend && function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( true );
		}

		// 3. Reclaim circular references.
		if ( $args['run_gc'] && function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		/**
		 * Fires after stop_the_insanity() finishes its core cleanup.
		 *
		 * Site owners can hook in custom flushes (WP Rocket, Yoast indexable
		 * cache, Elasticsearch bulk indexer, etc.).
		 *
		 * @since 1.2.0
		 */
		do_action( 'wp_mcp_ai_memory_after_cleanup' );

		/**
		 * Legacy / per-batch hook so callers can run their own cleanup logic
		 * (e.g. flushing a custom in-memory registry between batches).
		 *
		 * @since 1.2.0
		 */
		do_action( 'wp_mcp_ai_post_batch_hook' );
	}

	/**
	 * Convert a PHP shorthand byte string ("128M", "1G") to bytes.
	 *
	 * @since 1.2.0
	 *
	 * @param string|int $value Shorthand byte value.
	 * @return int Bytes (0 if unparseable).
	 */
	public static function parse_byte_size( $value ) {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 0;
		}

		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;

		switch ( $unit ) {
			case 'g':
				$number *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$number *= 1024 * 1024;
				break;
			case 'k':
				$number *= 1024;
				break;
		}

		return (int) $number;
	}

	/**
	 * Resolve the effective memory budget for the current process in bytes.
	 *
	 * Prefers WP_MAX_MEMORY_LIMIT (used for admin / cron) and falls back to
	 * the PHP memory_limit ini setting. A return value of 0 means "unlimited".
	 *
	 * @since 1.2.0
	 *
	 * @return int Bytes, or 0 for unlimited.
	 */
	public static function get_memory_limit_bytes() {
		$limit = '';

		if ( defined( 'WP_MAX_MEMORY_LIMIT' ) && WP_MAX_MEMORY_LIMIT ) {
			$limit = WP_MAX_MEMORY_LIMIT;
		} elseif ( function_exists( 'ini_get' ) ) {
			$limit = ini_get( 'memory_limit' );
		}

		if ( '' === $limit || '-1' === (string) $limit ) {
			return 0;
		}

		return self::parse_byte_size( $limit );
	}

	/**
	 * Decide whether the caller should pause/abort due to memory pressure.
	 *
	 * @since 1.2.0
	 *
	 * @param int $threshold_pct Percentage (0-100) of the limit at which to throttle.
	 *                           Defaults to DEFAULT_THRESHOLD_PCT.
	 * @return bool True if memory usage is at or above threshold.
	 */
	public static function should_throttle( $threshold_pct = self::DEFAULT_THRESHOLD_PCT ) {
		/**
		 * Filters the memory threshold percentage.
		 *
		 * @since 1.2.0
		 *
		 * @param int $threshold_pct Percentage value (0-100).
		 */
		$threshold_pct = (int) apply_filters( 'wp_mcp_ai_memory_threshold', $threshold_pct );
		$threshold_pct = max( 1, min( 100, $threshold_pct ) );

		$limit = self::get_memory_limit_bytes();
		if ( 0 === $limit ) {
			// Unlimited: never throttle.
			return false;
		}

		$used = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;
		if ( $used <= 0 ) {
			return false;
		}

		$ratio = ( $used / $limit ) * 100;

		return $ratio >= $threshold_pct;
	}

	/**
	 * Sleep briefly when over threshold; abort if still over after retry.
	 *
	 * Returns true if the caller should continue, false if they should abort.
	 *
	 * @since 1.2.0
	 *
	 * @param int $threshold_pct Percentage (0-100). Default 75.
	 * @param int $sleep_us      Microseconds to sleep before re-checking. Default 250000.
	 * @return bool True if safe to continue, false if caller should abort.
	 */
	public static function throttle_or_abort( $threshold_pct = self::DEFAULT_THRESHOLD_PCT, $sleep_us = self::DEFAULT_THROTTLE_SLEEP_US ) {
		if ( ! self::should_throttle( $threshold_pct ) ) {
			return true;
		}

		// Try to recover before aborting.
		self::stop_the_insanity( array( 'run_gc' => true ) );

		if ( $sleep_us > 0 ) {
			usleep( max( 0, (int) $sleep_us ) );
		}

		// Re-check.
		return ! self::should_throttle( $threshold_pct );
	}
}
