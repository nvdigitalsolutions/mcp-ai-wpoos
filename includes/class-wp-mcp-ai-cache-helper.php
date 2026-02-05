<?php
/**
 * Cache Helper
 *
 * Centralized caching utilities for performance optimization.
 * Uses WordPress Transients API for consistent cache management.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache Helper class
 *
 * Provides consistent caching interface for:
 * - Assistant data and configurations
 * - REST API endpoint responses
 * - Database query results
 * - Post meta lookups
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Cache_Helper {

	/**
	 * Cache group prefix
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_';

	/**
	 * Default cache expiration time (1 hour)
	 *
	 * @var int
	 */
	const DEFAULT_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Cache expiration for assistant list (30 minutes)
	 *
	 * @var int
	 */
	const ASSISTANTS_LIST_EXPIRATION = 30 * MINUTE_IN_SECONDS;

	/**
	 * Cache expiration for assistant configuration (1 hour)
	 *
	 * @var int
	 */
	const ASSISTANT_CONFIG_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Cache expiration for Elementor options (1 hour)
	 *
	 * @var int
	 */
	const ELEMENTOR_OPTIONS_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Cache expiration for dashboard analytics (5 minutes)
	 *
	 * @var int
	 */
	const ANALYTICS_EXPIRATION = 5 * MINUTE_IN_SECONDS;

	/**
	 * Cache expiration for tool results (1 hour)
	 *
	 * @var int
	 */
	const TOOL_RESULT_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Default cache group for object cache
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wp_mcp_ai';

	/**
	 * Get cached value
	 *
	 * Tries object cache first (if available), then falls back to transients.
	 *
	 * @param string $key Cache key (without prefix).
	 * @return mixed|false Cached value or false if not found.
	 */
	public static function get( $key ) {
		if ( ! self::is_caching_enabled() ) {
			return false;
		}

		$cache_key = self::build_cache_key( $key );

		// Try object cache first (Redis/Memcached).
		if ( self::has_object_cache() ) {
			$value = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( false !== $value ) {
				return $value;
			}
		}

		// Fallback to transients.
		return get_transient( $cache_key );
	}

	/**
	 * Set cached value
	 *
	 * Stores in both object cache (if available) and transients for redundancy.
	 *
	 * @param string $key        Cache key (without prefix).
	 * @param mixed  $value      Value to cache.
	 * @param int    $expiration Cache expiration in seconds (default: 1 hour).
	 * @return bool True on success, false on failure.
	 */
	public static function set( $key, $value, $expiration = self::DEFAULT_EXPIRATION ) {
		if ( ! self::is_caching_enabled() ) {
			return false;
		}

		$cache_key = self::build_cache_key( $key );
		$success   = true;

		// Store in object cache if available.
		if ( self::has_object_cache() ) {
			$success = wp_cache_set( $cache_key, $value, self::CACHE_GROUP, $expiration );
		}

		// Always store in transients as fallback.
		return set_transient( $cache_key, $value, $expiration ) && $success;
	}

	/**
	 * Delete cached value
	 *
	 * Removes from both object cache and transients.
	 *
	 * @param string $key Cache key (without prefix).
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $key ) {
		$cache_key = self::build_cache_key( $key );
		$success   = true;

		// Delete from object cache if available.
		if ( self::has_object_cache() ) {
			$success = wp_cache_delete( $cache_key, self::CACHE_GROUP );
		}

		// Delete from transients.
		return delete_transient( $cache_key ) || $success;
	}

	/**
	 * Delete all cached values matching a pattern
	 *
	 * @param string $pattern Pattern to match (SQL LIKE pattern).
	 * @return int Number of deleted cache entries.
	 */
	public static function delete_pattern( $pattern ) {
		global $wpdb;

		$cache_pattern  = self::CACHE_PREFIX . $pattern;
		$option_pattern = '_transient_' . $wpdb->esc_like( $cache_pattern ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$option_pattern
			)
		);

		return (int) $deleted;
	}

	/**
	 * Invalidate all assistant-related caches
	 *
	 * Called when assistants are created, updated, or deleted.
	 *
	 * @return void
	 */
	public static function invalidate_assistant_caches() {
		// Clear assistant list cache.
		self::delete( 'assistants_list' );
		self::delete( 'assistants_list_ids' );

		// Clear Elementor options cache.
		self::delete( 'elementor_assistant_options' );

		// Clear individual assistant configuration caches.
		self::delete_pattern( 'assistant_config_%' );
		self::delete_pattern( 'assistant_meta_%' );
	}

	/**
	 * Invalidate cache for a specific assistant
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	public static function invalidate_assistant_cache( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		// Clear specific assistant caches.
		self::delete( "assistant_config_{$assistant_id}" );
		self::delete( "assistant_meta_{$assistant_id}" );

		// Clear list caches as they may include this assistant.
		self::delete( 'assistants_list' );
		self::delete( 'assistants_list_ids' );
		self::delete( 'elementor_assistant_options' );
	}

	/**
	 * Invalidate all analytics dashboard caches.
	 *
	 * Called when usage data changes to ensure fresh statistics on next load.
	 *
	 * @return void
	 */
	public static function invalidate_analytics_caches() {
		self::delete( 'dashboard_usage_overview' );
		self::delete( 'dashboard_usage_forecast' );
		self::delete( 'dashboard_current_stats' );
		self::delete( 'dashboard_user_ids' );
	}

	/**
	 * Invalidate all orchestration-related caches.
	 *
	 * Called when orchestration settings are updated.
	 *
	 * @return void
	 */
	public static function invalidate_orchestration_caches() {
		self::delete( 'health_status' );
		self::delete( 'active_cron_count' );
	}

	/**
	 * Build cache key with prefix
	 *
	 * @param string $key Cache key.
	 * @return string Prefixed cache key.
	 */
	private static function build_cache_key( $key ) {
		return self::CACHE_PREFIX . $key;
	}

	/**
	 * Get cached assistant list
	 *
	 * @param array    $args  Query arguments.
	 * @param callable $callback Callback to generate data if cache miss.
	 * @return array Assistant list.
	 */
	public static function get_assistants_list( $args, $callback ) {
		// Create cache key based on query args.
		$cache_key = 'assistants_list_' . md5( wp_json_encode( $args ) );

		$cached = self::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Cache miss - generate data.
		$data = call_user_func( $callback );

		// Cache for 30 minutes.
		self::set( $cache_key, $data, self::ASSISTANTS_LIST_EXPIRATION );

		return $data;
	}

	/**
	 * Get cached assistant configuration
	 *
	 * @param int      $assistant_id Assistant post ID.
	 * @param callable $callback     Callback to generate config if cache miss.
	 * @return array Assistant configuration.
	 */
	public static function get_assistant_config( $assistant_id, $callback ) {
		$cache_key = "assistant_config_{$assistant_id}";

		$cached = self::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Cache miss - generate configuration.
		$config = call_user_func( $callback, $assistant_id );

		// Cache for 1 hour.
		self::set( $cache_key, $config, self::ASSISTANT_CONFIG_EXPIRATION );

		return $config;
	}

	/**
	 * Get cached Elementor assistant options
	 *
	 * @param callable $callback Callback to generate options if cache miss.
	 * @return array Assistant options for Elementor dropdown.
	 */
	public static function get_elementor_options( $callback ) {
		$cache_key = 'elementor_assistant_options';

		$cached = self::get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Cache miss - generate options.
		$options = call_user_func( $callback );

		// Cache for 1 hour.
		self::set( $cache_key, $options, self::ELEMENTOR_OPTIONS_EXPIRATION );

		return $options;
	}

	/**
	 * Check if caching is enabled
	 *
	 * Allow sites to disable caching via constant or filter.
	 *
	 * @return bool True if caching is enabled.
	 */
	public static function is_caching_enabled() {
		// Allow disabling via constant.
		if ( defined( 'WP_MCP_AI_DISABLE_CACHE' ) && WP_MCP_AI_DISABLE_CACHE ) {
			return false;
		}

		/**
		 * Filter whether caching is enabled.
		 *
		 * @param bool $enabled Whether caching is enabled (default: true).
		 */
		return apply_filters( 'wp_mcp_ai_cache_enabled', true );
	}

	/**
	 * Clear all plugin caches
	 *
	 * Useful for debugging or after plugin updates.
	 *
	 * @return int Number of cache entries cleared.
	 */
	public static function clear_all_caches() {
		// Clear object cache group if available.
		if ( self::has_object_cache() && function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}

		return self::delete_pattern( '%' );
	}

	/**
	 * Check if persistent object cache is available.
	 *
	 * @return bool True if object cache (Redis/Memcached) is available.
	 */
	public static function has_object_cache() {
		return wp_using_ext_object_cache();
	}

	/**
	 * Delete cache group (all keys with same group).
	 *
	 * @param string $group Cache group to delete.
	 * @return bool True on success.
	 */
	public static function delete_group( $group ) {
		// Clear object cache group if available.
		if ( self::has_object_cache() && function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( $group );
		}

		// Clear transients matching the group pattern.
		return self::delete_pattern( $group . '%' ) > 0;
	}

	/**
	 * Warm cache by preloading frequently accessed data.
	 *
	 * @return void
	 */
	public static function warm_cache() {
		// Warm assistant list cache.
		if ( ! self::get( 'assistants_list' ) ) {
			$assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'posts_per_page' => 100,
					'post_status'    => 'publish',
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			self::set( 'assistants_list', $assistants, self::ASSISTANTS_LIST_EXPIRATION );
		}

		/**
		 * Action hook to allow custom cache warming.
		 *
		 * @param self $cache_helper Cache helper instance.
		 */
		do_action( 'wp_mcp_ai_warm_cache', __CLASS__ );
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array Cache statistics including hit rate, size, etc.
	 */
	public static function get_cache_stats() {
		global $wpdb;

		// Count transient entries.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				'_transient_' . $wpdb->esc_like( self::CACHE_PREFIX ) . '%'
			)
		);

		$stats = array(
			'transient_count' => (int) $transient_count,
			'object_cache'    => self::has_object_cache(),
			'caching_enabled' => self::is_caching_enabled(),
			'cache_group'     => self::CACHE_GROUP,
			'cache_prefix'    => self::CACHE_PREFIX,
			'default_ttl'     => self::DEFAULT_EXPIRATION,
		);

		// Add object cache stats if available.
		if ( self::has_object_cache() && function_exists( 'wp_cache_get_stats' ) ) {
			$stats['object_cache_stats'] = wp_cache_get_stats();
		}

		return $stats;
	}

	/**
	 * Remember callback result with automatic cache management.
	 *
	 * Implements cache-aside pattern: check cache, execute callback if miss, store result.
	 * Note: Uses a wrapper to distinguish between cache miss and cached false values.
	 *
	 * @param string   $key        Cache key.
	 * @param callable $callback   Callback to execute on cache miss.
	 * @param int      $expiration Cache expiration in seconds.
	 * @return mixed Cached or freshly generated value.
	 */
	public static function remember( $key, $callback, $expiration = self::DEFAULT_EXPIRATION ) {
		$cached = self::get( $key );

		// Check if cache exists (wrapped in array).
		if ( is_array( $cached ) && array_key_exists( '__cached_value__', $cached ) ) {
			return $cached['__cached_value__'];
		}

		$value = call_user_func( $callback );

		// Wrap value to distinguish from cache miss.
		self::set( $key, array( '__cached_value__' => $value ), $expiration );

		return $value;
	}

	/**
	 * Prevent cache stampede with locking mechanism.
	 *
	 * Multiple concurrent requests for the same uncached data can cause
	 * stampede effect. This method uses a lock to prevent it.
	 * Note: Uses a wrapper to distinguish between cache miss and cached false values.
	 *
	 * @param string   $key        Cache key.
	 * @param callable $callback   Callback to execute on cache miss.
	 * @param int      $expiration Cache expiration in seconds.
	 * @param int      $lock_ttl   Lock timeout in seconds.
	 * @return mixed Cached or freshly generated value.
	 */
	public static function remember_with_lock( $key, $callback, $expiration = self::DEFAULT_EXPIRATION, $lock_ttl = 30 ) {
		// Try to get from cache.
		$cached = self::get( $key );

		// Check if cache exists (wrapped in array).
		if ( is_array( $cached ) && array_key_exists( '__cached_value__', $cached ) ) {
			return $cached['__cached_value__'];
		}

		// Try to acquire lock.
		$lock_key = $key . '_lock';
		$lock     = self::get( $lock_key );

		if ( false !== $lock ) {
			// Lock exists, wait briefly and try to get cached value.
			usleep( 100000 ); // 100ms.
			$cached = self::get( $key );

			// Check if cache was populated while waiting.
			if ( is_array( $cached ) && array_key_exists( '__cached_value__', $cached ) ) {
				return $cached['__cached_value__'];
			}

			// Still not cached, execute anyway to prevent hanging.
			$value = call_user_func( $callback );
			return $value; // Don't cache to avoid overwriting.
		}

		// Acquire lock.
		self::set( $lock_key, true, $lock_ttl );

		// Execute callback.
		$value = call_user_func( $callback );

		// Store result (wrapped).
		self::set( $key, array( '__cached_value__' => $value ), $expiration );

		// Release lock.
		self::delete( $lock_key );

		return $value;
	}
}
