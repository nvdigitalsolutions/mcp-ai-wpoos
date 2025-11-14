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
	 * Get cached value
	 *
	 * @param string $key Cache key (without prefix).
	 * @return mixed|false Cached value or false if not found.
	 */
	public static function get( $key ) {
		$cache_key = self::build_cache_key( $key );
		return get_transient( $cache_key );
	}

	/**
	 * Set cached value
	 *
	 * @param string $key        Cache key (without prefix).
	 * @param mixed  $value      Value to cache.
	 * @param int    $expiration Cache expiration in seconds (default: 1 hour).
	 * @return bool True on success, false on failure.
	 */
	public static function set( $key, $value, $expiration = self::DEFAULT_EXPIRATION ) {
		$cache_key = self::build_cache_key( $key );
		return set_transient( $cache_key, $value, $expiration );
	}

	/**
	 * Delete cached value
	 *
	 * @param string $key Cache key (without prefix).
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $key ) {
		$cache_key = self::build_cache_key( $key );
		return delete_transient( $cache_key );
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
		return self::delete_pattern( '%' );
	}
}
